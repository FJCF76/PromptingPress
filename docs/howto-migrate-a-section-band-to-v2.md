# How to migrate a `section` band to the design contract

You will take a page whose `section` bands were written against the v1 style-slot system
and get it validating and rendering again on v2. The end result: every band writes cleanly,
`wp pp check page` reports nothing at error severity, and the design you had is expressed
as `udc` role maps.

There is **no automatic migration and there will not be one** — v2 is a fresh-build system
by design. What there is instead: every retired name refuses with a message naming the
surface that replaced it, so the page tells you what to write.

## Prerequisites

- PromptingPress 2.0.0-alpha.2 or later.
- A run token and a completed preflight — see
  [the tutorial's step 1](tutorial-style-a-band-on-the-design-contract.md#step-1-get-a-run-token-and-open-the-gate).
  Below, `$RID` is that token and `$PID` the page.
- Familiarity with [section's roles](../components/section/README.md).

## Step 1: Find out what is actually broken

Do not guess from the page. Ask:

```bash
wp pp check page --post_id=$PID
```

Every stale declaration is reported with the band `index` that owns it, at severity
`error`. You are looking for four codes:

| Code | Means |
|---|---|
| `retired_prop` | a prop `section` used to declare. The message names its v2 route. |
| `invalid_style_slot` | a `--section-*` slot. All 47 are gone. |
| `inert_prop` | a prop that is live but paints nothing in this band's layout. |
| `unknown_prop` | a typo, or a name that never existed. Not a migration item. |

Nothing here blocks reading the page, and none of it blocks `restore_composition` — undo
always works, by rule.

## Step 2: Rewrite the props that were retired

Four props are gone. Each has one route:

| Retired prop | Write this instead |
|---|---|
| `theme: "muted"` / `"inverted"` | `_band` `background.fill` for the surface, **plus** `typography.color` on every text role over it (`heading`, `subheading`, `body`, `panel`). There is no single dark/light switch — that is the point, and the contrast is now yours. |
| `title_align: "center"` | the `header` role's `typography.align`. It sets `text-align` on the header block, which the eyebrow, heading and subheading inherit, exactly as the v1 modifier did. |
| `background_image: "<url>"` | `_band` `background.image` — **an attachment id, not a URL** — with `background.overlay` for the scrim and `background.position` / `size` / `repeat` for placement. |
| `panel_cta_variant` | the `panel-cta` role. `primary` and `secondary` map onto the `button` and `button-secondary` presets; `outline` and `ghost` have no preset and are written out on the role (see step 5). |

`background_image` is the one that needs a real conversion rather than a rewrite, because
it narrowed. Import the file first:

```bash
wp pp apply execute import_media --run-id=$RID --params='{"url":"https://example.com/band.jpg"}'
```

(`import_media` is an **apply**, not an action, so it is `wp pp apply execute` — a
different command family from the composition writes above. It takes either a remote `url`
or a server-local absolute `file`, exactly one.)

Use the `attachment_id` it returns:

```json
"_band": {"background": {"image": 20739, "overlay": "rgba(0,0,0,0.45)",
                         "position": "center", "size": "cover"}}
```

**A background hosted outside this install cannot be expressed in v2.** If the image is on
a CDN you do not control, import a copy.

### Clearing a retired key

A key the schema no longer declares cannot be removed by omitting it — the stored value is
still there. Send it as `null`:

```bash
wp pp action execute update_component --run-id=$RID --params='{
  "post_id": '$PID', "component_index": 0,
  "props": {"theme": null, "title_align": null}}'
```

**Send every stale key on a band in the same call.** The validator reports the first
problem per band, so clearing one retired prop on a band carrying three just surfaces the
next one.

`update_component` validates only the band it targets, so a stale name on band 0 does not
block an edit to band 5. Repair the page one band at a time, in any order.

## Step 3: Rewrite the style slots as role maps

All 47 `--section-*` slots are retired. The mapping is mechanical: find the element the
slot named, find the role whose selector is that element, and put the value in the group
that owns the property.

| v1 slot | v2 |
|---|---|
| `--section-bg` | `_band` → `background.fill` |
| `--section-padding-top` / `-bottom` | `_band` → `spacing.padding-top` / `-bottom` |
| `--section-heading-color` / `-size` | `heading` → `typography.color` / `size` |
| `--section-body-color` / `-size` | `body` → `typography.color` / `size` |
| `--section-body-measure` | **four roles, not one** — see below |
| `--section-panel-bg` / `-radius` / `-padding` | `panel` → `background.fill`, `border.radius`, `spacing.padding` |
| `--section-image-radius` / `-aspect-ratio` / `-position` | `media` → `border.radius`, `sizing.aspect-ratio`, `sizing.object-position` |

The full list is in [section's README](../components/section/README.md#retired-style-slots),
and each retired slot carries its own migration note naming the role that replaced it.

Three conversions to do deliberately rather than mechanically:

**`--section-body-measure` fed the WRAPPER, so it set the whole column.** `.section__body`
held the header block, the prose and the trust strip, and capping it capped all three. The
v2 `body` role is `.section__content` alone. To reproduce one slot you set four roles:

```json
{"heading":      {"sizing": {"max-width": "46rem"}},
 "subheading":   {"sizing": {"max-width": "46rem"}},
 "body":         {"sizing": {"max-width": "46rem"}},
 "inline-items": {"sizing": {"max-width": "46rem"}}}
```

Set only `body` and your heading keeps the 40rem default while your prose widens, which
reads as a mistake rather than a design.

**A literal colour becomes a reference.** If the slot held `#0f172a`, write
`"@color-bg-inverted"` instead. A literal will not follow a site retune; a reference will.

**Per-band and site-wide are different questions.** A slot was always per-band. If the
value you are porting is really a brand decision, put it in the design token
(`update_design_token`) and reference it from every band, instead of repeating a literal.

## Step 4: Fix the props that now refuse

Two rules refuse props that paint nothing where they sit. If `wp pp check page` reported
`inert_prop`, one of these fired:

- **Image props on a layout with no image column.** `image_url`, `image_id` and
  `image_alt` are refused on `text-only`, `centered` and `text-panel`.
- **Panel props on a layout with no panel.** The six `panel_*` props are refused on
  `text-only`, `centered`, `image-left` and `image-right`.

Either change the `layout` to one that renders the thing, or drop the props. An empty value
is not a request and is not refused.

## Step 5: Rebuild anything that has no direct replacement

Four things do not port one-to-one. Handle them explicitly rather than looking for the
missing knob:

**`outline` and `ghost` panel CTAs.** No preset ships for these. Write them on the role:

```json
"panel-cta": {"background": {"fill": "transparent"},
              "border": {"width": "1px", "style": "solid", "color": "@color-accent"},
              "typography": {"color": "@color-accent"}}
```

(that is `outline`; `ghost` is the same without the `border`).

**Per-row panel styling.** `panel_items[].style` is gone and the engine addresses roles,
not items, so a single emphasised row is **not expressible today**. Style `panel-row`,
`panel-row-label` and `panel-row-value` and the design lands on every row.

**Glyph colour.** `--section-separator-color`, `--section-body-marker-color` and
`--section-panel-marker-color` have no replacement at all — the marks are pseudo-elements
and no role can reach them. The list markers render the accent; the separator follows its
row's ink. See [#1028](https://github.com/FJCF76/PromptingPress/issues/1028).

**The two recipes.** `accent-panel` and `spacious-editorial` are deleted. A recipe bundled
style slots and there are none. `_preset` plus an explicit role map replaces them and is
per role, per breakpoint and per state — more capable, more verbose.

## Step 6: Verify

```bash
wp pp check page --post_id=$PID
```

Clean means: no `retired_prop`, no `invalid_style_slot`, no `inert_prop`. Then confirm the
design actually paints, which is a different question from validating:

```bash
curl -s "$(wp option get siteurl)/?page_id=$PID" | grep -o '\[data-pp-band[^}]*}'
```

You should see one block per role you styled. **If you see nothing, the most likely cause
is that the composition was written by a path that mints no band id** — a raw
`wp post meta update` of `_pp_composition` stores the bytes and mints nothing, so the `udc`
map scopes to nothing. Rewrite through `update_composition` or `update_component`.

Finally, look at the page at three widths. Validation cannot tell you that your dark band's
links are unreadable.

## Troubleshooting

**"Component has no declared style slots."** Expected. `style_component` does not work on
a v2 component at all — not with slots, not with a recipe. The refusal lists all 19 roles.
Send the design as a `udc` map in the composition instead.

**"This change needs the page's current version as a baseline."** The page has no
composition marker, which happens when the composition was seeded by a raw meta write.
Write once through the action surface to mint one.

**A preset did less than you expected.** Role defaults out-rank presets, so a preset
supplies only the parameters the role does not already default. Set the value directly
beside the preset.

**Your value validates but nothing changes on screen.** Check whether the property is one
of the four no role reaches: glyph colour, prose paragraph rhythm, the list indent, or the
phone-only gap between stacked panel rows. All four are listed in
[section's README](../components/section/README.md#what-no-role-reaches).

## Layout values: `_css` now, a group later

The approved coverage table specifies a **Layout** group — columns, gap, orientation, wrap —
and `Sizing → alignment`, and neither has been built yet. Four places in the shipped
component code route around that gap in writing, and three open issues ask for it (#658
vertical alignment on the panel and grid, #588 media fit and cropping, #905 grid columns).

Until the group ships, **those values are expressible through `_css` today**:

```json
{"columns": {"_css": {"align-items": "start"}},
 "panel":   {"_css": {"align-self": "center"}},
 "media":   {"_css": {"object-fit": "contain"}}}
```

That is exactly what the escape hatch is for, and it is worth being clear about the trade:
these are raw declarations, so they are checked for safety and emitted verbatim, and each
one produces a `udc_css_unchecked_property` finding on the write envelope. When the Layout
group lands they become named, type-checked parameters, and the `_css` versions should be
migrated to them — a raw declaration and a group parameter for the same property is always
a mistake, and the engine will tell you so with a `udc_css_overrides_group_value` finding if
you leave both in place.

## Related

- **[Tutorial — style a band on the design contract](tutorial-style-a-band-on-the-design-contract.md)** — if you have not written a `udc` map before, start there.
- **[`components/section/README.md`](../components/section/README.md)** — the 19 roles, the defaults and every retired slot's migration note.
- **The other two rebuilt bands, each with its own surprise:** [`cta`](howto-migrate-a-cta-band-to-v2.md) (its buttons) and [`faq`](howto-migrate-a-faq-band-to-v2.md) (its open row).
- **[Why the stylesheet is in a cascade layer](explanation-cascade-layers.md)** — what wins when your map and the theme stylesheet disagree.
- **[What a write is allowed to refuse](explanation-validation-scope.md)** — why the retired names refuse instead of being silently dropped.

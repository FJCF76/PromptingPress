# How to migrate a `cta` band to the design contract

You will take a page whose closing `cta` band was written against the v1 style-slot system
and get it validating and rendering again on v2. The end result: the band writes cleanly,
`wp pp check page` reports nothing at error severity, and the design you had is expressed as
`udc` role maps.

> **⚠ CLEAR THE STORED STYLE MAP FIRST, IF THERE IS ONE.** A band still holding its v1
> `style` map cannot be edited at all since #1101 — not restyled, *edited*: a props-only
> change meets `invalid_style_slot` naming a key you never mentioned. That refusal blocks
> every step below. It takes one command to clear:
> **`docs/howto-clear-a-stored-v1-style-map.md`**. Do that, then come back here.

There is **no automatic migration and there will not be one** — v2 is a fresh-build system
by design. What there is instead: every retired name refuses with a message naming the
surface that replaced it, so the page tells you what to write.

**Read [the section how-to](howto-migrate-a-section-band-to-v2.md) first if you have not
migrated a band before.** The mechanics are identical and are not repeated here; this
document covers what is DIFFERENT about cta, which is most of what will surprise you.

## Prerequisites

- PromptingPress 2.0.0-alpha.2 or later.
- A run token and a completed preflight — see
  [the tutorial's step 1](tutorial-style-a-band-on-the-design-contract.md#step-1-get-a-run-token-and-open-the-gate).
  Below, `$RID` is that token and `$PID` the page.
- Familiarity with [cta's roles](../components/cta/README.md).

## Step 1: Find out what is actually broken

```bash
wp pp check page --post_id=$PID
```

Three codes matter, and they mean different things:

| Code | Means |
|---|---|
| `retired_prop` | one of the four props cta used to declare. The message names its v2 route. |
| `invalid_style_slot` | a `--cta-*` slot. All 40 are gone. |
| `unknown_prop` | a typo, or a name that never existed. Not a migration item. |

Nothing here blocks reading the page, and none of it blocks `restore_composition` — undo
always works, by rule.

## Step 2: Rewrite the four retired props

| Retired prop | Write this instead |
|---|---|
| `theme: "inverted"` | `_band` → `background.fill`, **plus** `typography.color` on every text role over it. There is no single dark/light switch — that is the point, and the contrast is now yours. |
| `theme: "muted"` / `"dark"` | **Nothing.** Measured at 375/768/1280 before the prop retired: on a full-width band both rendered byte-identically to `default`. Send the key as `null` and you are done. |
| `background_image: "<url>"` | `_band` → `background.image` — **an attachment id, not a URL** — with `background.overlay` for the scrim. |
| `button_variant` | the `button` role's map, or `"_preset": "button"` / `"button-secondary"`. |
| `button2_variant` | the `button-secondary` role's map, same presets. |

### Clearing a retired key

A key the schema no longer declares cannot be removed by omitting it — the stored value is
still there. Send it as `null`, and send **every** stale key on the band in one call (the
validator reports the first problem per band):

```bash
wp pp action execute update_component --run-id=$RID --params='{
  "post_id": '$PID', "component_index": 4,
  "props": {"theme": null, "background_image": null,
            "button_variant": null, "button2_variant": null}}'
```

`update_component` validates only the band it targets, so a stale name on the cta does not
block an edit to any other band, and vice versa.

## Step 3: The four things that are genuinely NARROWER

These are the ones worth reading before you start, because none of them is a rename.

### 1. An `inline` band now paints the full-width surface and rules

v1's `full-width` painted a `--color-surface` fill and two 1px `--color-border` rules;
`inline` painted neither. v2 has no layout-scoped role defaults, so one value serves both,
and the `_band` defaults are what `full-width` — the prop's own default — rendered. **A band
that specifies nothing is byte-identical to v1.** An `inline` band is not:

```json
"_band": {"background": {"fill": "transparent"},
          "border": {"width-top": "0", "width-bottom": "0"}}
```

### 2. A dark band or a scrim owns its own contrast

The `.cta--inverted` and `.cta--has-bg-image` classes carried seven AA corrections, and it is
worth having all seven named because the list decides what you have to write: **the heading's
own ink**, the accented heading substring, the body's ink, its links' ink, its links' hover,
the `outline`/`ghost` buttons' ink and ring, and a separation ring that kept a FILLED button
visible against a scrim. Both classes derive from the retired props, so all seven are gone.
This is the same ruling #986 made for `.hero--cover`.

Set them yourself, reaching for the role tokens the theme already measures. **Start with the
band's own ink** — that is the line most easily missed, and the one the heading depends on:

```json
"_band":        {"background": {"fill": "@color-bg-inverted"},
                 "typography": {"color": "@color-bg"}},
"body":         {"typography": {"color": "@color-bg"}},
"body-link":    {"typography": {"color": "@color-accent-on-inverted",
                                ":hover": {"color": "@color-accent-on-inverted-hover"}}},
"heading-accent": {"typography": {"color": "@color-accent-on-inverted"}},
"button":       {"border": {"color": "@color-accent-on-inverted"}}
```

**Why `_band` carries a `typography.color` and the heading does not appear in this list.**
The `heading` role ships `typography.color: currentColor`, which in the `color` property means
`inherit` — so the heading follows whatever ink the BAND carries, and setting `_band` once is
what recolours it. That indirection is deliberate and it is also load-bearing: `base.css`
gives every `h1`-`h6` an explicit `color: var(--color-text)`, and a rule that MATCHES an
element beats an inherited value regardless of layer, so a heading with no declaration of its
own would stay `#101828` on your dark band — about 1.01:1, invisible. `currentColor` is the
declaration that restores the inheritance. If you would rather be explicit than rely on it,
set `"heading": {"typography": {"color": "@color-bg"}}` and it wins over both.

Over a scrim, use `@color-accent-on-overlay` (4.59:1 against the worst case) and
`@color-muted-on-overlay` instead.

**The FOCUS ring is the exception and stays automatic.** #986 keyed it to
`data-pp-band-overlay`, which the engine emits when a band paints both an image and an
overlay — so it follows the scrim across every layout and you cannot forget it. There is no
equivalent for a band you merely DARKEN with a fill: the engine composes a scrim and knows
it, but it does not interpret the colour you chose.

### 3. Two background behaviours are now explicit

v1's background-image band got `background-size: cover` and `background-repeat: no-repeat`
for free. Write them:

```bash
wp pp apply execute import_media --run-id=$RID --params='{"url":"https://example.com/band.jpg"}'
```

```json
"_band": {"background": {"image": 20739, "overlay": "@overlay-bg",
                         "size": "cover", "repeat": "no-repeat", "position": "center"}}
```

(`import_media` is an **apply**, not an action, so it is `wp pp apply execute`. A background
hosted outside this install cannot be expressed in v2 — import a copy.)

### 4. `outline` and `ghost` have no preset

`button_variant` offered four treatments; two ship as presets. Write the other two out:

```json
"button": {"background": {"fill": "transparent", ":hover": {"fill": "@color-accent"}},
           "border": {"width": "2px", "style": "solid", "color": "@color-accent"},
           "typography": {"color": "@color-accent", ":hover": {"color": "@color-bg"}}}
```

That is `outline`; `ghost` is the same without the `border` group. **Ship the `:hover` with
the resting value** — a role block is emitted unlayered, so a resting colour outranks the
stylesheet's own hover rules and a button without a state map does not respond to a pointer.

You will usually not need this for the SECOND button: `button-secondary` already carries
v1's outline treatment as its defaults, so an unauthored pair still reads as one filled
action beside one outlined one.

## Step 4: Rewrite the style slots as role maps

The mapping is mechanical: find the element the slot named, find the role whose selector is
that element, put the value in the group that owns the property.

| v1 slot | v2 |
|---|---|
| `--cta-bg` | `_band` → `background.fill` |
| `--cta-padding-top` / `-bottom` | `_band` → `spacing.padding-top` / `-bottom` |
| `--cta-border-color` / `-width` / `--cta-radius` / `--cta-shadow` | `_band` → `border.color` / `border.width` / `border.radius` / `shadow.box` |
| `--cta-inner-gap` | `inner` → `spacing.gap` |
| `--cta-heading-size` / `-color` / `-margin-bottom` | `heading` → `typography.size` / `typography.color` / `spacing.margin-bottom` |
| `--cta-heading-accent-color` | `heading-accent` → `typography.color` |
| `--cta-eyebrow-*` | `eyebrow` → the matching `typography` / `background` / `border` parameter |
| `--cta-body-color` / `-size` / `-measure` | `body` → `typography.color` / `size`, `sizing.max-width` |
| `--cta-button-*` | `button` → `background.fill`, `typography.color`, `border.color`, `shadow.box`, each with a `":hover"` for the `-hover-` twins |
| `--cta-button2-*` | `button-secondary` → the same |
| `--cta-overlay-bg` / `--cta-bg-position` | `_band` → `background.overlay` / `background.position` |

Three conversions to do deliberately rather than mechanically:

**`--cta-heading-measure` fed TWO elements, so it takes two roles.** It capped
`.cta__title` on every layout AND `.cta--full-width .cta__text`. Set only `heading` and your
body runs wider than the title it sits under, which reads as a mistake rather than a design:

```json
{"heading": {"sizing": {"max-width": "48rem"}},
 "text":    {"sizing": {"max-width": "48rem"}}}
```

**`--cta-accent` and `--cta-accent-hover` have no single replacement.** They were band-level
knobs that coloured both buttons' fill and ring at once. In v2 that is two role values per
button. If what you actually wanted was a site-wide accent, that is one
`update_design_token` write on `--color-accent` and no band edit at all.

**A literal colour becomes a reference.** If the slot held `#0f172a`, write
`"@color-bg-inverted"`. A literal will not follow a site retune; a reference will.

## Step 5: Verify

```bash
wp pp check page --post_id=$PID
```

Clean means no `retired_prop` and no `invalid_style_slot`. Then confirm the design actually
paints, which is a different question from validating:

```bash
curl -s "$(wp option get siteurl)/?page_id=$PID" | grep -o '\[data-pp-band[^}]*}'
```

You should see one block per role you styled. **If you see nothing, the most likely cause is
that the band was written by a path that mints no band id** — a raw `wp post meta update` of
`_pp_composition` stores the bytes and mints nothing, so the `udc` map scopes to nothing.
Rewrite through `update_composition` or `update_component`.

Finally, look at the band at three widths, and on a dark band check every text role against
the background you chose. Validation cannot tell you that your closing CTA's body copy is
unreadable.

## Troubleshooting

**"Component has no declared style slots."** Expected. `style_component` does not work on a
v2 component at all — not with slots, not with a recipe. The refusal lists all 11 roles.
Send the design as a `udc` map in the composition instead.

**Your two buttons render identically.** You cleared `button2_variant` but also authored
`button-secondary`, overriding the outline defaults it ships. Role defaults outrank presets,
and your own values outrank both — check what you set on that role.

**A preset did less than you expected.** Role defaults out-rank presets, so a preset supplies
only the parameters the role does not already default. `button` declares nothing precisely so
a preset lands whole; `button-secondary` declares the outline treatment, so a preset applied
there is partly suppressed. Set the value directly beside the preset (tracked: #1018).

**Your dark band's buttons look wrong on hover.** You set a resting colour without its
`":hover"`. An unlayered resting value outranks the stylesheet's hover rule for the same
property in every state, so the button keeps its resting paint under the pointer.

## Related

- **[`components/cta/README.md`](../components/cta/README.md)** — the 11 roles, their
  defaults, and why two of them declare nothing.
- **[How to migrate a `section` band](howto-migrate-a-section-band-to-v2.md)** — the same
  mechanics, on the widest rebuilt band.
- **[How to migrate an `faq` band](howto-migrate-a-faq-band-to-v2.md)** — the same mechanics
  again, where the surprise is the open row rather than the buttons.
- **[Why the stylesheet is in a cascade layer](explanation-cascade-layers.md)** — what wins
  when your map and the theme stylesheet disagree, and §1c on the border baseline cta's
  rebuild had to narrow.
- **[What a write is allowed to refuse](explanation-validation-scope.md)** — why the retired
  names refuse instead of being silently dropped.

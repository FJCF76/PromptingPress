# Style a Component Instance

Styling one band on one page. Two systems exist, they do not overlap, and the first thing to
establish is which one the component you are looking at is on.

**Almost everything is on the Universal Design Contract (v2).** You style it by putting a `udc`
map on the band, beside `props`. Nine composable components and both chrome components work this
way: `cta`, `embed`, `faq`, `hero`, `logos`, `section`, `stats`, `table`, `testimonials`, plus
`nav` and `footer`.

**One component is still on style slots (v1):** `grid`. You style it with the `style_component`
action, setting CSS custom properties. Everything about that path is in the last section of this
file.

How to tell without guessing: run `wp pp schema <component>`. A `roles` block in the report means
the design contract. `style_slots` entries mean slots. A component on the contract declares **no**
style slots, so `style_component` refuses it with `no_style_slots` — and the refusal lists the
roles you should have used instead.

---

## Step 1 — Read the component's roles

**Always do this before writing a `udc` map. Never write one from memory.**

```bash
wp pp schema faq
```

The report gives you, per role: its `role` name, the `selector` it emits at, the `groups` it
permits, a `description` explaining what the part is and how it behaves, and `obligations` when the
role has any. It also carries `udc_groups` (the whole vocabulary, derived from the engine) and
`udc_raw_css` (the escape hatch, described below).

**This file deliberately does not list any component's roles.** A roster copied into a document
goes stale at the next rebuild; the report is generated from the schema every time you ask. If you
have filesystem access you can read `components/<name>/schema.json` directly, but the report is the
surface that also works over SSH and through the chat.

A role whose definition is malformed is reported as `unreportable` with the reason. That is not a
role you can style — fix the schema first.

---

## Step 2 — Write the `udc` map

The shape is `role → group → parameter → value`:

```json
{
  "component": "testimonials",
  "props": { "title": "What clients say" },
  "udc": {
    "quote": { "typography": { "family": "@font-heading", "style": "italic", "size": "19px" } },
    "card":  { "background": { "fill": "#ffffff" },
               "border": { "width": "1px", "style": "solid", "color": "#e6e6e6" } }
  }
}
```

`_band` is the band element itself — the `<section>`. Use it for the band's own background,
padding and border.

### Which action carries it

**`update_composition` and `create_page` are the only two verbs that carry a `udc` map.** Both take
a whole band, which is why they can. `update_component` and `add_component` declare `props` and
`style` only — there is no `udc` parameter on either, so a `udc` key sent to them is never examined,
and `update_component` additionally requires `props`.

So a styling edit is a read-modify-write of the composition:

```bash
wp post meta get 42 _pp_composition          # the resendable bytes, `udc` maps included
wp pp action execute update_composition --run-id=<uuid> --params='{ ... }'
```

**Read the meta, not `inspect`.** This is the one place a raw meta READ is the right tool, and
it is worth being exact about why: `wp pp operate inspect` returns the page map, tokens, chrome
and smells but no composition array; `wp pp operate inspect-composition` returns per-field
targets for patching and carries **no `udc` at all** (measured: zero occurrences in its report).
Neither gives you the map you are about to edit. Reading the meta is safe — it is WRITING it
that skips validation, minting, versioning and history.

**On `expected_version`:** no read command surfaces the composition version today. It comes back
on the `findings` envelope of your own last write (`composition_version`). Carry that forward if
you have it; if you do not, omit `expected_version` and accept last-write-wins, or make a
no-op-free write first to learn the number. Tracked as a gap, not a thing you are missing.

### Groups and parameters

Eight groups. `wp pp schema <component>`'s `udc_groups` field states the whole vocabulary, derived
from the engine, and a role permits only the groups its own `groups` list names:

- **typography** — family, size, weight, style, line-height, letter-spacing, align, transform, decoration, wrap, color
- **spacing** — padding (and per-side), margin (and per-side), gap, row-gap, column-gap
- **border** — width, style, color (each with per-side forms), radius (and per-corner)
- **background** — fill, image, overlay, position, size, repeat
- **sizing** — width, height, min/max width and height, aspect-ratio, object-position, align-self
- **layout** — columns, orientation, wrap, justify, align
- **shadow** — box
- **motion** — transition-duration, timing-function

Two names appear in two groups and mean different things. `layout.align` is the cross-axis
alignment of a container's children; `typography.align` is text alignment. `layout.wrap` is flex
wrapping; `typography.wrap` is line wrapping. Both accept `center`, so writing one under the wrong
group validates and styles the wrong thing.

### Values: the accepted grammar

A length is a number with a CSS unit, unitless `0`, or a `clamp()`/`calc()` expression. The
accepted units are exactly: `px`, `rem`, `em`, `ex`, `ch`, `lh`, `rlh`, `vw`, `vh`, `vmin`, `vmax`,
and `%`. One grammar owns every length-bearing value, so a unit that works in a padding works in a
radius and in a gradient stop. Absolute print units are not accepted.

Two per-property exceptions keep that sentence honest. `shadow` lengths take NO percentage,
because `box-shadow: 0 50%` is not valid CSS. And `clamp()`/`calc()` are accepted on the
`length` and `length-or-none` only — not on a ratio, a duration or a position.

Negative values are allowed only where the property takes them — letter-spacing and margins yes;
padding, sizes, radii and gaps no. `padding`, `margin`, `border.width` and `border.radius` take one
to four space-separated lengths; everything else takes a single value.

Colours are hex, `rgb()`/`rgba()`, `hsl()`/`hsla()`, `transparent` or `currentColor`. Named colours
are refused.

`sizing.aspect-ratio` is the one parameter that is not a length: `auto`, a single positive number,
or two positive numbers separated by a slash (`16/9`). Zero and negatives are refused on both sides.

**Where a value reaches raw CSS text — which is every `udc` parameter — two further limits apply.**
Brackets must come in closed, properly nested pairs, and each quote character must appear an even
number of times across the whole value. A `udc` value breaking either is **REFUSED at write**, so
you find out immediately. The same limits reach a design-token override, but there the value is
accepted at write and then **DROPPED at render**, so the token silently falls back to its default
and the only report is `wp pp readiness status` as a `token_override_validity` finding. A v1 style
slot is the one sink where an unclosed mark is genuinely inert, because it lands in an escaped
`style` attribute.

### References: follow a token instead of freezing a copy

Write `"@token-name"` to make a value track a design token: `"@color-accent"`, `"@space-lg"`,
`"@font-heading"`. No `--` prefix, no `var()`. This works on **every** parameter, lengths included.

A name matching neither the band's own `_tokens` nor a registered design token is refused at write,
never silently ignored. A reference must also be usable for the parameter: a colour token in a
length is refused, and `@transition` is a compound (`150ms ease`) with no single grammar, so write
`motion` values literally.

Reach for a reference whenever the intent is "this follows the brand", and a literal when the
intent is "this band specifically differs".

### Responsive: breakpoint maps

Any value may be a map instead of a single value:

```json
{
  "component": "section",
  "udc": { "heading": { "typography": { "size": { "d": "2.5rem", "t": "2.1rem", "p": "1.75rem" } } } }
}
```

`d` is **the base and carries no media query at all** — it applies at every width unless a narrower tier overrides it, which is why writing only `d` is the normal case. `t` is tablet
(768–1023px), `p` is phone (≤767px). The ranges do not overlap, so a value set at one breakpoint
cannot be cancelled by another. Writing only `d` means that value applies at every width.

### States

A `":hover"`, `":focus-visible"` or `":active"` key inside a group holds the same parameters for
that state, and its values may themselves be breakpoint maps:

```json
{
  "component": "cta",
  "udc": { "button": { "typography": { "color": "#ffffff", ":hover": { "color": "#f0f4ff" } } } }
}
```

Those three are the whole set. `:disabled`, pseudo-elements and states on an ancestor are refused.
States do not nest. They emit in the order hover, focus-visible, active.

Do not restate the theme's keyboard focus ring — every focusable element already has one. Use
`:focus-visible` to add to it, never to replace it.

### Reading `default` in a report

A `default` states the **effective** default: what actually renders with the value unset, in the
component's default configuration, at desktop. It is not a CSS fallback literal and not a guess.
Where the real default varies by variant or breakpoint, the `default` names the desktop value and
the `description` enumerates the alternatives — so read the description before assuming one number
holds everywhere. Setting a value **replaces every branch at once**, at every layout and viewport,
so a value chosen from the desktop number alone can be wrong at 375px.

### Presets

A `"_preset"` key applies a named bundle. At role grain it sits beside the groups; at group grain
beside the parameters:

```json
{
  "component": "cta",
  "udc": { "button": { "_preset": "button", "border": { "radius": "12px" } } }
}
```

Write the name bare — an `@name` always means a design token, never a preset. The shipped presets
are `button`, `button-secondary` and `link`; a name that is not one is refused and the refusal
lists the ones that are. Anything you set beside the preset wins over it.

Two things to expect. Role defaults outrank presets, **per state** — a role that already declares
its own background keeps it at rest and still takes the preset's `:hover` background, so check the
contrast of both. And a role-grain preset applies only the groups that role permits, skipping the
rest; the write envelope names what was skipped in a `udc_preset_groups_skipped` finding.

You can create your own with `save_preset` (and remove one with `delete_preset`). A saved preset is
validated by the same engine that validates a band's `udc`, its `@` references resolve against the
site design tokens, and editing it moves every band that references it with no band write.

### Raw CSS (`_css`) — the escape hatch, and when not to use it

Beside a role's groups you may write `"_css"`, a plain map of CSS property to value:

```json
{
  "component": "testimonials",
  "udc": { "quote": { "typography": { "size": "1.25rem" },
                      "_css": { "opacity": "0.75", "mix-blend-mode": "multiply" } } }
}
```

It takes the same breakpoint maps and the same states as any group, on every role and on `_band`.

**The rule for choosing: structured first, `_css` for what structure cannot say.** If a group and
parameter exist for what you want, use them — those values are type-checked, they appear in the
report, they can be changed by name, and the engine can tell you when one cannot take effect.

Five things to know. A property the vocabulary already owns **keeps its parameter's grammar**, so
`_css` buys you nothing there and costs you the type check. If you set both, `_css` wins and the
envelope says so with `udc_css_overrides_group_value`. A property the vocabulary does not know is
checked for safety only and emitted verbatim, with a `udc_css_unchecked_property` finding — read
those, they are the only signal a value went out unverified. `@token` references work only on
properties the vocabulary knows. And no value may name an external resource: a background image is
an attachment id on `background.image`, and the Media Library is the only source of external
assets. `!important` is refused — this engine keeps specificity flat, and your value already wins
on cascade position.

---

## What the component tells you to pair

Some roles carry `obligations`: a fact that a value you write here does not reach a part you might
expect it to, so you have to write both. Two shapes exist — a partner role whose own default
outranks what you set, and a partner that sits inside this one and takes its styling directly
rather than by inheritance.

**Read them from `wp pp schema <component>`**, per role, at the moment you write. They are not
reproduced here on purpose: an obligation copied into a document is a second copy with nothing
keeping it true, and the report is generated from the schema. The runtime assistant is given them
automatically for the same reason.

The one that bites most often in practice: darkening a surface that carries prose costs more writes
than the surface itself, because the links inside it and the parts with their own colour do not
follow the container.

---

## A dark band

There is no `theme` prop on a component on the design contract. Say it directly — set the band's
background, then every text part's colour:

```json
{
  "component": "testimonials",
  "udc": {
    "_band":       { "background": { "fill": "#101828" } },
    "quote":       { "typography": { "color": "#f7f8fa" } },
    "author":      { "typography": { "color": "#f7f8fa" } },
    "meta":        { "typography": { "color": "#c8ccd4" } },
    "heading":     { "typography": { "color": "#f7f8fa" } },
    "subheading":  { "typography": { "color": "#c8ccd4" } }
  }
}
```

**You own the contrast.** Nothing re-lights text for you. Check every colour against the background
for WCAG AA — 4.5:1 for body text, 3:1 for large text. A dark band with one part left un-recoloured
renders dark ink on dark, which is the single most common way this goes wrong.

Two tokens exist for exactly this and are worth reaching for by name on a dark surface:
`@color-accent-on-inverted` where the brand accent would otherwise be too dark to read, and
`@color-muted-on-overlay` for de-emphasised text over a background image.

A background image is an attachment id, never a URL:

```json
{
  "component": "section",
  "udc": { "_band": { "background": { "image": 42, "overlay": "@overlay-bg",
                                      "size": "cover", "repeat": "no-repeat" } },
           "heading": { "typography": { "color": "#ffffff" } } }
}
```

`import_media` returns the id. Pair an image with an `overlay` whenever text sits on it, or the text
is illegible over whatever the photograph happens to contain. The scrim is not automatic, and
neither are `size` and `repeat`.

---

## Site chrome: the header and the footer

`nav` and `footer` are on the same contract with the same grammar, but they are not bands — the
theme renders them once on every page and they cannot be composed. Their map lives in the
`pp_site_udc` site option, written with `update_site_option`, one entry per chrome component:

```json
{ "nav": { "_band": { "background": { "fill": "#101828" } },
           "link": { "typography": { "color": "#f7f8fa", ":hover": { "color": "@color-accent-on-inverted" } } },
           "link-current": { "typography": { "color": "@color-accent-on-inverted" } },
           "logo": { "typography": { "color": "#ffffff" } },
           "submenu-toggle": { "typography": { "color": "#f7f8fa" } } } }
```

**A write replaces the whole option**, so send every chrome component you want to keep in the same
write. Read the current map back first with `wp pp operate inspect` (it returns it as `chrome`,
with the `version` to pass as `expected_version`), edit it, and send the whole thing. `""` clears
all chrome styling.

`_presets` and `_presets_version` share that row and are engine-owned: they are preserved across
every chrome write, and a write carrying either is refused telling you to drop it.

Chrome styling is site-wide. There is no per-page override, and a key that is not a chrome
component name is refused.

**A dark header or footer is where contrast goes wrong most often**, because many chrome parts
carry their own colour and a background change moves none of them. The accent used above is the
on-inverted token rather than the plain brand accent for exactly that reason: the plain accent
measures 3.21:1 on `#101828`, under the floor, while the on-inverted token measures 8.28:1. Ask
`wp pp schema nav` and `wp pp schema footer` which parts declare a colour, and re-ink every one you
put over a new background.

### Eyebrows: differentiate one deliberately, or leave the default alone

The eyebrow is the small kicker above a heading, and it renders as a pill. Its colour, background,
border, radius and casing are all authorable through the `eyebrow` role — but **the pill geometry
is not**: `padding: 0.35rem 0.85rem` is a stated default, so an eyebrow you restyle keeps the
original pill proportions unless you set `spacing.padding` yourself.

That asymmetry is the thing to plan around. The first instinct on being asked to make one eyebrow
stand out is to change its colour and background, which moves everything except the shape — and a
differently-coloured pill of exactly the same size often reads as a mistake rather than a choice.
Either commit and set the padding too, or leave the eyebrow alone and differentiate the heading.

The eyebrow's TYPE triple — size, weight and letter-spacing — is a separate open question
(#574): the shipped `--text-kicker-*` family carries different values and has no consumers, so
it is not a third thing to reach for here. Authoring the eyebrow's typography through the role
is the supported route today.

Do not restyle every eyebrow on a page to prove the capability exists. A kicker that is uniform
across bands is doing its job; one that differs on every band is noise.

---

## The one component still on style slots: **`grid` (38 slots)**

`grid` is the last component on the v1 styling system. It is styled with `style_component`, setting
CSS custom properties rather than roles:

```bash
wp pp action execute style_component --run-id=<uuid> --params='{
  "post_id": 42,
  "component_id": "pp-a1b2c3d4",
  "style": { "--grid-bg": "#101828", "--grid-item-text-align": "center" }
}'
```

Read the available slots with `wp pp schema grid`. Each carries its `type`, its **effective**
`default`, and a description. The type list that still has a carrier is `color`, `length`,
`gradient`, `shadow`, `align` and `text-transform` — the same unit set and the same colour grammar
as above, because one grammar owns both systems.

Three slot behaviours worth knowing before you write one:

- `--grid-heading-measure` is a **text measure**. A literal there is accepted but opts this band out
  of any later site-wide measure retune, so prefer leaving it unset and tuning the `--measure-*`
  design tokens unless this band must differ.
- `--grid-eyebrow-text-transform` takes one `text-transform` keyword. The pill defaults to
  `uppercase`; set it to `none` when a reference shows the kicker in sentence case.
- Per-item overrides go in the composition, not in `style_component`: a `grid.items[].style` map
  accepts only the card-scoped slots, and container or heading slots are rejected there.

**Slot names you may meet on an aged page, all retired**, because the components that carried them
are on the design contract now: `--stats-max-width`, `--stats-bg-position`, `--stats-number-font`,
`--faq-body-measure`, `--logos-image-size`, `--cta-body-measure`, `--hero-heading-measure` and
`--section-heading-measure` are gone, along with every other `--<component>-*` name on a rebuilt
component. Writing any of them is refused with `no_style_slots`. The replacements are `udc` values:
a width cap is a role's `sizing.max-width`, an image cap its `sizing.max-height`, a band
background's focal point `_band` → `background.position`, and a text colour that role's
`typography.color`.

### Recipes

A recipe is a named bundle of slot values. There is no `apply_recipe` action: a recipe is applied through `style_component`'s optional `recipe` parameter, which expands into slot values before any explicit `style` map you send alongside it is merged on top. Only `grid` ships any:

| component | recipe |
|---|---|
| grid | `dark-showcase` |
| grid | `dense-cards` |
| grid | `uniform-cards` |
| cta | — |
| embed | — |
| faq | — |
| hero | — |
| logos | — |
| section | — |
| stats | — |
| table | — |
| testimonials | — |

A `—` means the component ships no named recipe, so stop looking for one. On the design contract
the equivalent is a saved preset, which you can create yourself.

---

## Step 3 — Verify

A write is not finished when it returns `ok: true`.

1. **Read the envelope's `findings`.** An empty array is the positive confirmation. Anything in it
   is the engine telling you a value did not land the way you wrote it — a skipped preset group, a
   shadowed value, an unchecked raw property, a minted token.
2. **Re-read the composition** with `wp post meta get <id> _pp_composition` and confirm the map is
   what you sent. (`inspect` does not return the composition, and `inspect-composition` does not
   return `udc` — see the read-modify-write note above.)
3. **Check the page** with `wp pp check page --post_id=<id>` for rendered problems.
4. **Look at it.** Contrast, wrapping and cramped text are not things any check catches for you.

Common refusals and what each means:

| Refusal | Meaning |
|---|---|
| `no_style_slots` | The component is on the design contract. Write a `udc` map; the refusal lists its roles. |
| `unknown_udc_role` | That role does not exist on this component. The refusal lists the ones that do. |
| `unknown_udc_group` | The role does not permit that group. The refusal lists the groups it permits. |
| `invalid_prop_value` | A value failed its parameter's grammar, named by band, role, group and parameter. |
| `retired_prop` | A prop the component used to declare. The refusal names the `udc` surface that replaced it. |
| `inert_prop` | The prop exists but does nothing in this configuration (a `cover` hero's inline image props, say). |

---

## What NOT to do

- **Do not write a `udc` map from memory.** Read the roles first. An invented role name is refused,
  and a plausible-looking one that exists on a different component is the most common mistake.
- **Do not reach for `_css` when a parameter exists.** You lose the type check and the report entry,
  and you gain nothing.
- **Do not send `udc` to `update_component` or `add_component`.** They have no such parameter.
- **Do not edit `assets/css/components.css`** to change one band's appearance. That file is
  theme-owned, an upgrade replaces it, and per-instance styling is what the `udc` map is for.
- **Do not use WordPress Additional CSS** for component styling. It is a global stylesheet outside
  the composition: not scoped to a band, not versioned with the page, not covered by undo or
  rollback. A band's `_css` is the opposite on every one of those axes.
- **Do not darken a surface and stop there.** Re-ink the text, the links inside prose, and every
  part that carries its own colour.
- **Do not write a composition with `wp post meta update`.** A raw meta write stores the bytes and
  mints nothing, so the band ids a `udc` map scopes to never exist. Go through the actions.

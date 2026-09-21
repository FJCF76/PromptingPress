# Style a Component Instance

Styling one band on one page. Two systems exist, they do not overlap, and the first thing to
establish is which one the component you are looking at is on.

**Almost everything is on the Universal Design Contract (v2).** You style it by putting a `udc`
map on the band, beside `props`. Ten composable components and both chrome components work this
way: `cta`, `embed`, `faq`, `grid`, `hero`, `logos`, `section`, `stats`, `table`, `testimonials`,
plus `nav` and `footer`.

**NO component is on style slots any more (#1101).** `grid` was the last one; the v1 paragraphs below describe `style_component`
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
wp post meta get 42 _pp_composition_version  # READ THE VERSION FIRST — see below
wp post meta get 42 _pp_composition          # then the resendable bytes, `udc` maps included
wp pp action execute update_composition --run-id=<uuid> --params='{ ... }'
```

**Read the meta, not `inspect`.** This is the one place a raw meta READ is the right tool, and
it is worth being exact about why: `wp pp operate inspect` returns the page map, tokens, chrome
and smells but no composition array; `wp pp operate inspect-composition` returns per-field
targets for patching and carries **no `udc` at all** (measured: zero occurrences in its report).
Neither gives you the map you are about to edit. Reading the meta is safe — it is WRITING it
that skips validation, minting, versioning and history.

**On `expected_version`, with two caveats that matter more than the parameter does.**

Read it from its own meta key — `wp post meta get 42 _pp_composition_version` — and read it
**before** the composition, not after. Read the data first and the version second and you have
built the race you were trying to close: a write landing between the two reads gives you stale
bytes and a version that already covers them, so the check passes and the other edit is gone.
(The version also comes back on every write's envelope as `composition_version`.)

**And on the CLI today, passing it protects you less than it looks.** `wp pp action execute`
runs its own freshness gate and then OVERWRITES whatever `expected_version` you sent with the
baseline that gate computed, so a deliberately stale value is accepted rather than refused —
measured: a write carrying `expected_version: 1` against a composition at version 2 returned
`ok: true`. The engine's compare-and-swap is sound and the chat and dashboard surfaces honour
it; it is the CLI wrapper that discards your value, and only for COMPOSITION writes — the site-option path on the same CLI refuses a stale `expected_version` correctly with `site_option_conflict`, so the chrome promises elsewhere hold. Filed as its own issue.

Until that lands, treat the CLI as last-write-wins and make the window small: read the version,
read the composition, edit, and write **immediately**, in one unbroken sequence. Do not carry a
composition you read earlier in the session. Pass `expected_version` anyway — it costs nothing,
it is honoured on the other surfaces, and it will start being honoured here.

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
(768–1023px), `p` is phone (≤767px). `t` and `p` do not overlap each other, so neither cancels
the other — but `d` sits underneath both and IS overridden by whichever of them you set, which is
the same thing as saying it is the base.

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
    "card":        { "background": { "fill": "#1d2939" }, "border": { "color": "#344054" } },
    "quote":       { "typography": { "color": "#f7f8fa" } },
    "author":      { "typography": { "color": "#f7f8fa" } },
    "meta":        { "typography": { "color": "#c8ccd4" } },
    "heading":     { "typography": { "color": "#f7f8fa" } },
    "subheading":  { "typography": { "color": "#c8ccd4" } }
  }
}
```

**`card` is in that map for a reason, and leaving it out is the trap.** The quote, author and
meta all render INSIDE `.testimonials__item`, and the `card` role ships
`background.fill: "@color-surface"` as its own DEFAULT — a near-white panel. Darken `_band`,
re-ink the text, and skip `card`, and you get near-white ink on a near-white card: measured
**1.01:1** for the quote and 1.50:1 for the meta against THIS example's resolved card fill
(#f4f7fb), on a write that returns `findings: []`,
because the engine warns about a value that cannot take effect and not about a role default
that survives a change you made to a different role.

**You own the contrast, and you own it per ROLE, not per band.** Nothing re-lights text for
you. Check every colour against the surface it actually sits on — which is the nearest
ancestor role carrying a `background.fill`, whether you set that fill or it came as a default.
WCAG AA is 4.5:1 for body text, 3:1 for large text. A dark band with one part left
un-recoloured renders dark ink on dark, or light ink on light, and that is the single most
common way this goes wrong.

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
border, radius, casing **and geometry** are all authorable through the `eyebrow` role: every one
of the five v2 components that has an eyebrow declares `spacing` among its permitted groups, with
`padding: 0.35rem 0.85rem` as the role's DEFAULT. So a restyled eyebrow keeps the original pill
proportions until you set `spacing.padding`, and then it takes yours, breakpoint map and all. On
`grid` the geometry genuinely is fixed, because no slot reaches it.

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

## The v1 style-slot surface: **`grid` (0 slots)** — retired at #1101

**There is no v1 style-slot surface any more.** `grid` was the last component declaring one, and
its rebuild retired all 38 slots and all 3 named recipes. `style_component` now refuses **every**
component with `no_style_slots`, and the refusal names the `udc` route for the one you aimed at.

Everything the slots did is a role parameter on grid's band map, which is the same surface as every
other band in this file. `wp pp schema grid` lists its eighteen roles and the groups each permits.

Three routes worth knowing, because they are the ones people look for by their old names:

- the heading's **text measure**, whose retired slot name ended `-heading-measure`; it is
  `heading` → `sizing.max-width`, defaulting to `@measure-heading`. Prefer leaving it unset and
  tuning the `--measure-*` design tokens, unless this band must differ — a literal here opts the
  band out of a later site-wide retune.
- the eyebrow's **casing**, whose retired slot name ended `-eyebrow-text-transform`; it is
  `eyebrow` → `typography.transform`. The pill defaults to `uppercase`; set `none` when a
  reference shows the kicker in sentence case.
- **per-card overrides** were `items[].style`; they are `items[].udc`, a map on the entry itself
  addressing the same roles the component declares (BUILD-SPEC Addendum B). That is the one thing
  grid can do that no other band can, and it is what the owner's dark-card design is written in.

A stored `style` map on a page built before the rebuild is not migrated and not healed: it is
reported, and the way to remove it is to write the band without it.

---

### Recipes — none ship

A recipe was a named bundle of slot values, applied through `style_component`'s optional
`recipe` parameter. `grid` declared the last three (`dark-showcase`, `dense-cards`,
`uniform-cards`) and they retired with its slot map at #1101; `cta`'s two went at #1026 and
`section`'s at #1023. **No composable component ships one**, so the table below is all dashes
and asking for a recipe by name is refused with `invalid_recipe`.

| component | recipe |
|---|---|
| cta | — |
| embed | — |
| faq | — |
| grid | — |
| hero | — |
| logos | — |
| section | — |
| stats | — |
| table | — |
| testimonials | — |

The v2 equivalent is a **preset**, and it is better in the way that matters: `save_preset`
(#1016) stores a named `udc` fragment for the whole SITE, any band or chrome role can apply it
with `"_preset"`, and editing it moves every reference with no band write. A recipe could only
ever bundle one component's slots.

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

# Style a Component Instance

Use the `style_component` action to change the visual appearance of a specific component instance without editing CSS files. Style overrides are stored in the composition alongside props and survive theme updates.

> ## First: is this component on the v2 contract?
>
> `hero` and `testimonials` are. Run `wp pp schema hero` (or read the component catalog) — if it lists **UDC roles** instead of style slots, `style_component` will refuse it with `no_style_slots`, and everything below about slots does not apply to it.
>
> **Every `--hero-*` slot name later in this file is HISTORY.** The v1 cascade sections are kept because they still govern grid, stats, logos, embed and table (section left at #1023 and cta at #1026), and hero appears in them as the example it used to be. Writing any of those names is refused. The v2 equivalent for each is a role in the band's `udc` map — see "Brand-accent hero CTA buttons" below for the worked translation.
>
> Style a v2 component by putting a `udc` map on the BAND, beside `props`, through `update_composition` / `update_component` / `add_component` / `create_page`:
>
> ```json
> "udc": {
>   "card":  {"background": {"fill": "#ffffff"},
>             "border": {"width": "1px", "style": "solid", "color": "#e6e6e6"}},
>   "quote": {"typography": {"family": "@font-heading", "style": "italic",
>                            "size": {"d": "19px", "p": "17px"}}}
> }
> ```
>
> - **Roles** are the named parts of the component (`quote`, `card`, `attribution`, `heading`, …; `_band` is the band itself). The catalog lists each role with the groups it permits. Do not invent a role name — `unknown_udc_role` names the ones that exist.
> - **`@token-name`** follows a design token instead of freezing a copy of its value (`@color-accent`, `@space-lg`, `@font-heading` — no `--` prefix, no `var()`). This works on **every** parameter including lengths, which the slots below cannot do. An unresolvable name is rejected at write, never ignored.
> - **Breakpoints:** any value can be `{"d": …, "t": …, "p": …}` — desktop (the base), tablet 768-1023px, phone ≤767px. The ranges do not overlap, so a value you set at one breakpoint is never cancelled by another.
> - **States:** a `":hover"`, `":focus-visible"` or `":active"` key inside a group, holding the same parameters (and their values may be breakpoint maps too). Those three are the whole set — `:disabled`, pseudo-elements (`::before`) and states on an ancestor are refused at write, and states never nest. They emit hover → focus-visible → active, so a pressed element shows `:active`. Every focusable element already gets the theme's keyboard focus ring; use `:focus-visible` to add to it, not to replace it.
> - **Motion:** the `motion` group carries `transition-duration` (a time: `"150ms"`, `"0.2s"`) and `timing-function` — one of `linear`, `ease`, `ease-in`, `ease-out`, `ease-in-out`, `step-start`, `step-end`; or `cubic-bezier(x1,y1,x2,y2)` where the 1st and 3rd are between 0 and 1 and the 2nd and 4th may be any number including negative (that is what produces overshoot); or `steps(n[, jump-start|jump-end|jump-none|jump-both|start|end])` with n up to 1000, and `jump-none` needing n of 2 or more. **Values are case-sensitive** — write `ease`, not `EASE`, exactly as for every other value. Both params default to the theme's `150ms` / `ease`. Ignore `prefers-reduced-motion`: the engine emits that guard itself, and there is no parameter for it.
> - **Presets** apply a shared bundle by name through a `"_preset"` key — at role grain beside the groups (`"cta": {"_preset": "button", "border": {"radius": "12px"}}`) or at group grain beside the parameters (`"quote": {"typography": {"_preset": "link", "size": "1.25rem"}}`, taking only that preset's typography). The name is **bare**, never `@`-prefixed: an `@name` always means a design token. The presets that exist are listed in the runtime context you are given (and a wrong name is refused at write with the full list) — read them there rather than assuming, because the set grows. Whatever you set beside the preset overrides it. Role defaults outrank presets, PER STATE: a role that declares its own background keeps it at rest and still takes the preset's `:hover` background. So a preset applied to an already-styled role can end up with its own surface at rest and the preset's accent fill on hover. Set `typography.color` explicitly, at rest and in every state you use, rather than assuming the preset supplied a matching pair. A role-grain preset applies only the groups that role PERMITS and skips the rest, and the write envelope names exactly which were skipped and which applied (if it declares nothing the role permits, the write is refused naming both). A preset carries a button's LOOK, not its behaviour. **You can also create presets of your own** (#1016): `save_preset` stores a named fragment for the whole site, `delete_preset` removes one. A saved preset is validated by this same engine, its `@` references resolve against the SITE tokens only (a preset belongs to the site, not to a band), and editing one moves every band that references it with no band write. A theme preset's name cannot be taken, a preset cannot reference another preset, and a preset that is still referenced anywhere cannot be deleted — that refusal lists every place it is used.
> - **A dark band** has no `theme` prop to set: give `_band` a `background.fill` and then a `typography.color` to EVERY text role on it (`quote`, `author`, `meta`, `heading`, `subheading`, `eyebrow`) plus any link colour. You own the contrast — check each against the background for WCAG AA (4.5:1 body, 3:1 large text). Nothing re-lights text for you.
> - **Why `_band` typography does not reach the text.** `_band` has no selector of its own, so an inherited value you set there (a colour, a family, a size) reaches the parts of the band by INHERITANCE — and a role that declares its own default for that property beats inheritance, always, whatever the order. That is why the rule above says every text role and not just the band. When it happens the write envelope tells you: a `udc_band_value_shadowed_by_role_default` finding names the property and the roles that shadow it. Read it back and set those roles directly rather than assuming the band-level value landed. It is deliberately narrow, so its silence means something: it fires only for INHERITED properties (a band padding and a role padding are different boxes and both paint), only when the `_band` value is itself valid (one that is not painted anywhere is reported by `wp pp readiness status` instead, as a value that cannot take effect), and never for a role you have already set yourself.
>
> The grammar for values is identical to the one stated below; only the addressing differs.

---

## Step 1 -- Inspect available style slots

```bash
wp pp operate inspect-composition --post_id=<id>
```

The output shows each component's available `style_slots` (name, type, default, current value) and `available_recipes` (named shorthand).

**Reading `default`.** It states the **effective** default — what actually renders with
the slot unset, in the component's default configuration, at desktop (>=768px, the theme's
desktop tier; a few slots have a further >=1024px tier, always named in the description).
It is not the CSS fallback literal. Where the real default varies by variant or breakpoint, `default` names
the desktop / default-configuration value and the slot's `description` enumerates the
alternatives, so read the description before assuming one number holds everywhere.
Setting a slot **replaces every branch at once**, at every layout and viewport — a value
picked from the desktop number alone can be wrong at 375px. A parenthesised default such
as `(premium bevel)` means a built-in treatment with no single literal worth quoting.

`styling.tokens` names SOME of the global design tokens that component's own rules consume.
Two things about it are guaranteed, and both are test-enforced: every entry is a **registered
design token** (a property in the first `:root` block of `base.css`, which is the same set
`update_design_token` accepts), and every entry is **reachable by the component that lists it**
(a rule that component can match actually reads it). Completeness is NOT guaranteed. The array
is hand-curated and deliberately partial: a band's own rules read several times more registered
tokens than its array names, and a token can be missing for no reason beyond nobody having added
it. `--overlay-bg` is listed by the one v1 component that still reads it (`stats`), and it is
reached only as a slot fallback (`var(--stats-overlay-bg, var(--overlay-bg))`);
`--measure-heading` is reached in exactly the same way and is not listed by anyone. (`hero`,
`section` and `cta` listed it too until their rebuilds retired the slot chain that reached
it — on a v2 component the token is reached by a ROLE default instead, e.g. cta's `heading`
and `text` roles both default `sizing.max-width` to `@measure-heading`.)
**So never read absence from this array as "this component does not consume that token."** For
what you can actually set on one band, read its `style_slots` — each slot's `default` names the
token it routes — or, on a v2 component, its `roles` and the band's `udc` map, which declare no
slots at all. For what you can retune site-wide, read the design-token list in the catalog.

The shared band rhythm and heading scale are a different thing from a missing entry:
`--pp-band-padding` and `--pp-band-heading-size` are not design tokens at all, so no array could
list them. They are declared in a separate `:root` block that the token registry does not read,
so `update_design_token` rejects them as an `unknown_token`, and no action in the write path
reaches them — retuning them site-wide is a theme-source change, not something you can author.
The per-band `--<comp>-padding-top` / `--<comp>-padding-bottom` / `--<comp>-heading-size` slots
are your only surface for them, and each one moves that band alone.

The template-owned chrome pair (`nav`, `footer`) declares `roles` instead: chrome's
styling surface is its UDC roles, written into the `pp_site_udc` **site option** with
`update_site_option`. That is a different thing from a design token and a different
thing from a style slot — chrome has neither.

---

## Step 2 -- Apply style slots

> `component_id` accepts an authored `id` prop or the auto-generated `pp-<hex8>` id read back from the composition. Auto-generated ids are regenerated by a full `update_composition` re-apply — give components you style an explicit `id` if the page is maintained from source JSON (see website-building.md, "Component IDs").

> **This step is for v1 components only.** `hero`, `section`, `testimonials`, `cta` and `faq` are on
> the Universal Design Contract: they declare no style slots and no recipes, and a
> `style_component` call naming one is refused. Style them through the band's `udc` map —
> see the Universal Design Contract section below. The examples here use `grid`, which is
> still on the slot system.

**Direct slot values:**
```bash
wp pp action execute style_component --run-id=<uuid> --params='{
  "post_id": 19,
  "component_id": "pp-a1b2c3d4",
  "style": {
    "--grid-bg": "#1a1a2e",
    "--grid-heading-color": "#f0f0f0",
    "--grid-padding-top": "8rem"
  }
}'
```

**Using a recipe:**
```bash
wp pp action execute style_component --run-id=<uuid> --params='{
  "post_id": 19,
  "component_id": "pp-a1b2c3d4",
  "recipe": "dark-showcase"
}'
```

**Recipe + overrides (recipe expands first, then explicit values win):**
```bash
wp pp action execute style_component --run-id=<uuid> --params='{
  "post_id": 19,
  "component_id": "pp-a1b2c3d4",
  "recipe": "dense-cards",
  "style": {
    "--grid-heading-size": "clamp(3rem, 6vw, 5rem)"
  }
}'
```

---

## Step 3 -- Verify

```bash
wp pp operate inspect-composition --post_id=<id>
```

Check that `current` values reflect your changes and the `active_recipe` shows correctly.

---

## Semantics

- **PATCH merge:** Style slots merge with existing values. Unspecified slots are unchanged.
- **Remove a slot:** Set its value to `null` to remove the override and revert to the global token default.
- **Clear all style:** Pass `"style": {}` to remove all overrides.
- **Validation:** Only schema-declared slots are accepted. Invalid slot names or values are rejected with descriptive errors.
- **Conditional slots (issue #580):** many slots only do something in a particular configuration, and the schema now says so. The runtime component catalog appends `applies when ...` to those slots (e.g. `--grid-item-bar-color (color, default: ...; applies when layout = "cards")`); `wp pp operate inspect-composition` still lists slot/type/default/current only, so read the condition from the catalog, from `wp pp schema <component>` (#688 — every slot with its raw `applies_when` and `conditionality_note` plus an `applies_when_rendered` phrase carrying both, ANDed, in the catalog's own words; read-only, no run token, no filesystem access needed), or from the component's `schema.json`. **Check the condition against the component's props before you set the slot.** Setting one whose condition is unmet is accepted and stored — the write succeeds — but it renders nothing. **Your own write tells you so (#687):** the accepted envelope carries a `findings` entry of type `inert_slot`, `severity: warning`, naming the slot, the band `index` and the unmet clauses — *"the value is stored and reported as applied, but nothing on the page reads it"*. Read `findings` on every accepted write; that is the whole point of it. `wp pp check page` reports the same advisory, and you no longer have to run it to find out. If you meant the effect, change the prop that gates it (`layout`, `card_emphasis`, `eyebrow`, `image_treatment`, ...) in the same edit; if you did not, drop the slot rather than leaving a stored value that reports as applied and does nothing. Two limits worth knowing: the advisory reads the **component-level** `style` map only, so a per-item override (`items[].style` on grid) is never checked — `panel_items[].style` was the other one and #1023 retired it with section's slot map; and conditions carried as prose in `conditionality_note` (the `main >` composed-page scope, a FAQ's open state, an item-level switch) cannot be machine-checked at all — read those yourself before writing. NONE OF THIS APPLIES TO A v2 COMPONENT (`hero`, `section`, `testimonials`, `cta`): a role's block is emitted only when its element renders, so there is no inert-value class to warn about, and the layout-dependent props that WOULD paint nothing are REFUSED at write (`inert_prop`) rather than stored with an advisory.

---

## Slot types

**The accepted units, stated once for every length-bearing type on this page:**
`rem px em % vw vh vmin vmax ch ex lh rlh`. A length is a number with one of those
attached (no space between them), unitless `0`, or a `clamp()`/`calc()` expression
built from the same units. Negative values are accepted where the property takes
them — letter-spacing and margins yes, padding and sizes no. This list is the whole
set for `length`, `length-or-none`, `position`, `shadow` lengths and gradient stop
positions alike: since v2 they share ONE grammar, so a unit that works in one works
in all of them.

Two per-property exceptions, which are facts of CSS rather than leftovers of the old
divergence: `shadow` lengths take NO percentage (`box-shadow: 0 50%` is not valid
CSS), and `clamp()`/`calc()` are accepted on `length` and `length-or-none` only —
`position`, `shadow` and gradient stops have never taken a function and still do
not.

| Type | Examples | Validator |
|------|----------|-----------|
| `color` | `#1a1a2e`, `rgb(26, 26, 46)`, `transparent`, `currentColor`, `var(--color-accent)` | `_pp_validate_color()` |
| `length` | `8rem`, `50%`, `clamp(3rem, 6vw, 5rem)`, `calc(100% - 2rem)`, `0` | `_pp_validate_length()` |
| `length-or-none` | `none`, `60rem`, `100%` — the `length` grammar plus the keyword `none` ("no cap"). Carried by the width-cap slots whose **declared default IS `none`**, so the built-in uncapped state stays authorable: since #1046 the band-geometry cap `--stats-max-width` is the ONLY slot left that carries it — no text measure ships uncapped any more. (`--faq-body-measure` was the last and left at #1046 with faq's rebuild; faq's `answer` role declares no measure at all, because v1 rendered `none`.) (`--cta-body-measure` left at #1026 with cta's rebuild; cta's `body` role declares no `max-width` at all, which is the same uncapped render stated as silence rather than as `none`.) Every other measure slot has a real length default and stays plain `length`. A plain `length` slot still rejects `none`. On a v2 component there is no `length-or-none` slot to reach: an uncapped measure is the role's `sizing.max-width` set to `none`, which the v2 grammar accepts on that parameter directly. | `_pp_validate_length()` (with the `none` keyword) |
| `number` | `700`, `1.5` | `_pp_validate_number()` |
| `duration` | `250ms`, `0.3s` | `_pp_validate_duration()` |
| `font-family` | `"Inter", sans-serif`, `system-ui, sans-serif`, `-apple-system, BlinkMacSystemFont`, `var(--font-heading)` — a comma-separated list where every name is one of three shapes: an **unquoted** name of letters, digits, spaces, `-` or `_`; a **fully quoted** name (`"Helvetica Neue"`, `'Cascadia Code'`) whose quote character does not recur inside it; or a **single token reference** (`var(--font-mono)`, no fallback, no nesting — unlike `color`, this is not checked against the token registry, so a typo validates and paints nothing). Quote any name carrying other characters, a non-ASCII face name included — quoting is not a licence for anything, since the shared reject set still applies to the whole value on every surface (`{ } ; < >`, backslash, `/*`, `url(`, `@import` are rejected inside quotes too). Empty names (`Inter,, serif`) and trailing commas are rejected. **Two extra limits apply wherever the value reaches raw CSS source text** — every v2 `udc` parameter, and the `:root` block the theme emits for design-token overrides (a v1 style slot is unaffected; its sink is an escaped `style` attribute). First, brackets must be closed, matching pairs: `(` with `)` and `[` with `]`, properly nested (`"Foo (Display)"` ok, `"Foo (Display"` rejected, `[full-start] 1fr [full-end]` ok, `([)]` rejected). Second, each of `'` and `"` must appear an even number of times across the whole value (`"Foo's Font"` rejected however written; `'Foo "Display Font'` rejected; `'Foo "Display" Font'` ok). **Where it bites differs by surface:** a `udc` value breaking either limit is REFUSED at write; a design-token override breaking one is accepted at write but DROPPED at render, and `wp pp readiness status` then reports it. | `_pp_validate_font_family()` (+ the shared delimiter gate on `udc` values and design-token overrides) |
| `shadow` | `var(--shadow-sm)`, `var(--shadow-md)`, `var(--shadow-lg)`, `none`, `0 4px 12px rgba(0,0,0,0.1)` | `_pp_validate_shadow()` |
| `gradient` | `#1a1a2e`, `transparent`, `var(--color-accent)`, `linear-gradient(135deg, #fff, #000)`, `radial-gradient(circle at top left, #fff, #000)` | `_pp_validate_color()` or `_pp_validate_gradient()` |
| `position` | `center`, `top left`, `20% 80%` | `_pp_validate_position()` |
| `ratio` | `auto`, `1`, `16/9` | `_pp_validate_ratio()` |

> **Which types accept `var()`, and which are literal-only.** A `var()` reference is
> accepted only by these types, each in a bounded way: `color` (a single reference
> to a registered **color**-typed token, e.g. `var(--color-accent)`), `gradient`
> (that same single color-token reference, as its plain-color half — **never**
> `var()` *inside* a `linear-gradient()`/`radial-gradient()`, and never a "gradient
> token"), `shadow` (only the fixed presets `var(--shadow-none|sm|md|lg)`, never
> an arbitrary token), and `font-family` (any single bare reference such as
> `var(--font-mono)` — note this is the ONE of the four that is checked for SHAPE
> only: unlike `color`, the token is not required to exist or to be font-typed, so
> a misspelled name validates and then paints nothing).
> Every other type — `length`, `length-or-none`, `number`, `duration`, `position`,
> and `ratio` — is **literal-only**: `var()` is rejected in every form, bare or
> nested. Look up the token's current value (`inspect-composition`, or the
> design-token registry) and pass that literal value. Passing a literal **freezes**
> it: a `length`/`number`/`duration`/`position`/`ratio` slot cannot FOLLOW a token
> the way a `color` slot can, so if the token changes later, the literal you passed
> does not. A bare `var(--space-lg)` in a `length` slot is rejected — not only
> `var()` nested inside `clamp()`/`calc()`.

The `color` type (#230) accepts hex, `rgb()`/`rgba()`, `hsl()`/`hsla()`, the CSS color
keywords `transparent` and `currentColor` (case-insensitive), or a **single bare
reference to a registered color-typed design token** — `var(--color-accent)` exactly.
No fallback (`var(--x, #fff)`), no nesting, no whitespace inside the parentheses, and
the referenced token must exist in the design-token registry and itself be color-typed.
Named colors (`red`) are rejected. Use a `var()` reference when a slot should FOLLOW a
token ("this button follows the brand accent") instead of duplicating literal hex.
For `update_design_token`, a reference chain that loops back to the token being set
(directly or through other tokens) is rejected as a cycle — the browser would resolve
every token in the loop to invalid. Inside `linear-gradient()`/`radial-gradient()`
functions, `var()` is still rejected; the `gradient` type accepts the new color forms
only as its plain-color half.

The `shadow` type is bounded: a preset (`var(--shadow-none\|sm\|md\|lg)` or `none`)
or a single-layer `box-shadow` (2-4 px/rem lengths plus an rgb/rgba/hsl/hsla color).
`inset`, multi-layer shadows, and `url()` are rejected. The grid (card) and stats components each expose namespaced `*-border-color`,
`*-border-width`, `*-radius`, and `*-shadow` slots. hero, section, testimonials, cta and faq
exposed them until their rebuilds (#986, #1023, #958, #1026); on a v2 component that framing
is the role's own `border` and `shadow` groups.

The `stats` band exposes two of these framing slots — `--stats-radius` (length,
default `0`) and `--stats-max-width` (**length-or-none**, default `none`) — for a
**contained, rounded metrics card** (#383). Set both together: `--stats-max-width`
caps the band and centers it with auto side margins, and `--stats-radius` rounds the
band's background. Unset, the band spans full width with square corners exactly as
before. To remove the max-width, set `none` — the `length-or-none` type accepts the
same keyword the slot declares as its default, so the built-in full-bleed is
authorable (#579). `none` is accepted **only** on a `length-or-none` slot; a plain
`length` slot (padding, font-size, radius, and any measure with a real length default
such as `--grid-heading-measure`) still rejects it, and there `100%` remains the way to
widen a cap. The measures that ship uncapped carry `length-or-none` too — see the
type table above and "Text measures" below. Stats does not expose `*-border-*` or `*-shadow` slots.

## Text measures — prefer the token over a per-band literal (#578)

Five band components declare `--<component>-heading-measure` — embed, grid, logos, stats
and table — and of those only `embed` also declares `--<component>-body-measure`. (The
rosters were wider: section's and cta's measures left with their rebuilds at #1023 and #1026,
where a measure is the role's `sizing.max-width`.) They **default to the shared
`--measure-heading` design token** (`40rem`), so the
normal way to change band heading measure across a site is ONE `update_design_token`
write, not ten `style_component` writes.

That is not just a convenience: measure slots are `length` / `length-or-none`, which are
**literal-only**, so you cannot write the token reference into the slot either.
`style_component` with `--grid-heading-measure: var(--measure-heading)` is rejected even
though that string IS the slot's declared default. Retune the token, or write a literal
and accept that this band stops following.

Two components are deliberately exempt and default to `none`:

- **`hero`** — `.hero__content` is a flex item that shrink-wraps to its widest child, so
  a cap on `--hero-heading-measure` narrows the whole content column (title, subheading
  AND buttons), not just the headline. The hero measure you almost always want is
  **`--hero-content-width`**. Reach for `--hero-heading-measure` only to hold a headline
  deliberately narrower than its column.
- **`section`** — the section title has never carried a cap, and section is the most-used
  band, so it stays uncapped unless you say otherwise.

Writing a plain length into any measure slot is **accepted and renders exactly as
written** — a per-band measure is a legitimate typographic choice. Be aware of what it
costs: that band is then pinned, so a later site-wide measure retune moves every other
band and leaves this one behind. Keep the literal when this band must differ; otherwise
leave the slot unset and tune the `--measure-*` tokens.

**Two scope corrections worth knowing before you go looking for a measure that is not
there.** First, `--measure-heading` routes **band headings, not item titles** — the
`main > .grid--steps .grid__item-title { max-width: 17rem }` cap is deliberately outside
the measure surface, so no measure retune reaches a step title. Second, the
`--<component>-body-measure` family covers `embed` **only** (faq's left at #1046; section's and cta's
retired at #1023 and #1026). `testimonials` is **not** among them, so the `layout: "stack"` reading measure
(`42rem`) is a stated default, not something a measure retune moves.

THE BRANCH-FALLBACK SLOTS ARE GONE, and what replaced them is better. `--section-body-measure`
(four branches) and `--hero-content-width` (three) each had defaults that varied by layout
and viewport, and setting either replaced EVERY branch with one value at every layout and
viewport. Both components are v2 now: a measure is the `body` / `content` role's
`sizing.max-width`, which takes a `breakpoints` map, so a per-viewport measure is one value
per tier instead of one value for all of them. Section's role default is `40rem` — the
width a v1 band actually RENDERED, which is not the same as the rule that won among those
targeting the element: the 49rem override never bound, because the element's own wrapper
capped it at 40rem first. What the branches encoded was a LAYOUT difference (a centered
band got a wider cap), and a role default is per component, so one measure now serves all
five layouts; a `centered` band is 32px tighter than it was and sets its own value if that
matters. See components/section/README.md, "Stated defaults" and "What narrowed".

**Stats display numbers follow the heading system only when you ask (#472).** The big
metric values are the largest text in the component, but by default they take the page
**body** font at weight `700` — they are not headings. On a site whose headings use a
distinct display face, set both `--stats-number-font` (font-family, default `inherit`)
and `--stats-number-weight` (number, default `700`) to bring the figures onto the
heading system: `"--stats-number-font": "var(--font-heading)"` plus, say,
`"--stats-number-weight": "600"` when the heading face wants a lighter weight than the
bold body default. `--stats-number-font` takes a font token or any comma-separated
stack; `--stats-number-weight` is literal-only (a unitless number — `bold` is
rejected), so read `--font-weight-heading`'s current value and pass that number if you
want parity. Both are opt-in: leave them unset and the band renders exactly as before.
The `--stats-label-*` text is a sibling element and never follows the number's face.

The grid's **featured first-card treatment** (accent top bar, texture stripe, blue
glow on card 1 of a cards-layout grid) is slot-controllable (#293):
`--grid-item-bar-color`/`--grid-item-bar-height` pin one top bar on EVERY card
(height `0` removes it everywhere); `--grid-featured-texture-color: transparent`
removes the card-1 texture stripe; `--grid-featured-shadow` overrides the shared
`--grid-item-shadow` on card 1 only (`none` removes the glow, or set
`--grid-item-shadow` for one identical shadow on all cards). For a uniform row in
one step, apply the `uniform-cards` recipe instead of setting the slots by hand.

For a fully uniform card row, prefer the grid **`card_emphasis: uniform`** PROP
(set with `update_component` / `create_page`, NOT `style_component` — it is a prop,
not a style slot). It drops the ENTIRE featured first-card treatment — the accent
bar, tinted fill, larger title, the extra first-card top-padding, AND the dark-theme
lift — so card 1 renders identically to its siblings. This is the right tool for a
symmetric/peer card row (specification/comparison cards whose checklists must line
up, an equal-weight feature/plan row). It is more complete than the slot-level
`uniform-cards` recipe, which cannot reach the first-card top-padding or the dark
lift. Keep the default `featured` when one card is genuinely the lead.

The grid's desktop **column count** is likewise a PROP, not a style slot: set the
grid **`columns`** prop (integer 1-4, via `update_component` / `create_page`) to
force a specific number of columns at >=768px instead of the default derivation
from item count. There is no `--grid-columns` slot; do not look for one. Unset
leaves the auto-by-count default unchanged; out-of-range/non-integer values are
rejected. `columns` is a `cards` concept and is ignored on the `steps` layout.

Whether a card image renders as a **16:9 banner or a small icon** is a PROP too, not
a style slot: set the grid **`image_treatment`** prop (`banner` default / `icon`, via
`update_component` / `create_page`) to switch a card's `image_url` from the full-width
16:9 cover banner to a small un-cropped icon above the title (the icon+title+text
feature card). The *size* of that icon IS a style slot: **`--grid-item-icon-size`**
(length, default 48px, item-eligible) — set it via `style_component` grid-wide or in a
per-card `items[].style`. The slot only takes effect under `image_treatment: icon`;
under the default `banner` the 16:9 wrap ignores it. The icon also FOLLOWS the card's
`--grid-item-text-align` (center/right/left) just like the text and the `Read more`
link do — one authored slot aligns all three — so a centered icon+title+text card is
fully centered without a separate icon-alignment slot. `image_treatment` is a `cards`
concept and is ignored on the `steps` layout.

To style **one card differently from its siblings** (a dark CTA panel beside light
checklist cards, or a green-on-dark terminal card), set a per-card `style` object on
that grid item — `props.items[].style` — with the **card-scoped** grid slots (e.g.
`--grid-item-bg`, `--grid-item-border-color`, `--grid-item-title-color`,
`--grid-item-text-color`). It is validated by the same shared engine and overrides
the grid-level value for that card only. Container/heading slots (`--grid-bg`,
`--grid-gap`, `--grid-heading-*`, `--grid-padding-*`) render on the section, not a
card, so they are rejected here — set those on the grid-level style. Set it through the composition
(`update_component` / `update_composition` / `create_page`), NOT `style_component` —
`style_component` targets a whole component instance, not a single item. See
`ai-instructions/composition.md` → "grid items[].style".

The `position` and `ratio` types (#108) control image focal point and aspect ratio,
per-instance. `position` accepts 1-2 keyword/length tokens (no functions, no `var()`);
`ratio` accepts `auto` (natural proportions) or a number/fraction. `--stats-bg-position`
controls the `background_image` CSS background. No v1 component has a content-image
focal-point or crop-ratio slot left. Not exposed on logos (fixed `object-fit: contain`
layout, not a crop model).

**HERO AND SECTION ARE NOT ON THIS LIST (#986, #1006, #1023).** Their `--*-image-*` and
`--*-bg-position` slots were retired with their v2 rebuilds, and both values are role
parameters now: the content image's box shape is the `media` role's
`sizing.aspect-ratio` and its focal point is the same role's `sizing.object-position`.
`object-position` is the parameter #1023 added to the engine — section's rebuild needed
it, an engine gap is fixed in the engine rather than worked around locally, and adding it
also REVERSED hero's #986 narrowing of the same property. The band background's focal
point is `_band`'s `background.position`. A hero band
background is `_band` `background.image` plus `background.position` — never `image_url`,
which on `layout: "cover"` is now REFUSED at write with `inert_prop`.

**The scrim over a `background_image` has its own per-instance slot on the one v1 band
that still carries one: `--stats-overlay-bg`** (stats was the last to get one, #577, and cta's left at #1026).
On a v2 component the scrim is the `_band` role's `background.overlay`, authored beside
`background.image` in the same map — hero left this slot family in #986 and section in
#1023, and writing either retired slot is refused with `no_style_slots`.
It is `gradient`-typed and defaults to the shared `--overlay-bg`. Reach for it when one
particular photo needs a darker or lighter scrim than the site default, instead of
retuning `--overlay-bg` and moving every image band at once. Two things to know before
you lighten one: the band's text defaults are calibrated against the SHIPPED scrim over a
worst-case white image, so lightening it weakens contrast the theme is relying on; and
de-emphasised ink on that band (a stats `label`; a cta `body` was the other until #1026) is already at the edge
of AA, which is why it routes the `--color-muted-on-overlay` role rather than an
`opacity` literal. Do not express de-emphasis on an image band with `opacity` — see
`ai-instructions/retheme.md` for the measurements.

The `align` type (#357) controls text alignment. It accepts exactly one `text-align`
keyword: `left`, `right`, `center`, `start`, `end`, or `justify`. Grid exposes it as
`--grid-item-text-align` — an item-eligible slot, so it can be set grid-wide (all cards)
or per-card via `items[].style`. Default `left` matches historical rendering; set
`center` to center a card's content — the centered emoji/label contact-card pattern.
The slot aligns BOTH the text content (title, text, bullets) AND the `Read more`
link/button: the link follows the same alignment via a derived companion (#361), so
one value fully centers (or right-aligns) the whole card. An unset slot leaves the
card byte-identically left-aligned.

The `text-transform` type (#370) controls letter-casing. It accepts exactly one
`text-transform` keyword: `none`, `uppercase`, `lowercase`, or `capitalize` — a closed
set (the CJK `full-width`/`full-size-kana` values and bare `unset`/`initial` are
rejected, the same tight-vocabulary posture as `align`). The eyebrow/kicker pill
exposes it on the one that still has the slot, as `--grid-eyebrow-text-transform` (hero, section, testimonials, cta and faq take the `eyebrow` role's `typography.transform` instead),
defaulting to `uppercase` (today's baked rendering). Set it to `none` when a reference
shows the kicker in sentence case, or `lowercase`/`capitalize` for those looks. An unset
slot leaves the eyebrow byte-identically uppercase.

### Eyebrows: differentiate one deliberately, or leave the default alone

> **An eyebrow is optional framing, not a default part of a section. Add one only where
> a band genuinely needs the extra orienting line, and when you do style it, restyle the
> ONE band that needs to stand apart — a page whose every band opens with the same
> uppercase pill is the cookie-cutter rhythm the anti-slop rules exist to prevent.**

The eyebrow family is fully authorable — six slots on each of six components (colour,
background, radius, border width, border colour, casing) — so the control surface is not
the problem. Uniformity is. The default pill is deliberately quiet so that a band which
*does* differentiate its eyebrow reads as deliberate; recolour all six bands and you have
spent the contrast and bought nothing.

**Practical shape.** Leave `--<c>-eyebrow-*` unset on every band you are not
deliberately marking. When you do mark one, `--<c>-eyebrow-text-transform: none` (sentence
case against the uppercase default) usually differentiates more cleanly than a colour
change, because it does not compete with the band's accent.

**One thing does not move with the rest — ON THE SLOT SYSTEM.** The pill's **geometry** —
`padding: 0.35rem 0.85rem` — is uniform across all six components by construction, and on
the one still on slots (`grid`) it is a stated default with no slot: those two values are
off the `--space-*` scale, so they are not token-reachable either. Colour, background,
border, radius and casing all move per band there; the pill's shape does not. If
differentiating a `grid` eyebrow leaves you needing a different pill *shape*, that is the
reopening condition for the geometry — record it as an incident rather than working around
it. **On the five v2 components the shape DOES move:** `hero`, `section`, `testimonials`,
`cta` and `faq` each declare `spacing` on the `eyebrow` role, so the same two values are the
role's `spacing.padding` default and an authored map overrides them per breakpoint.

**Out of scope here, deliberately:** the eyebrow's TYPE triple (`0.8125rem` / `600` /
`0.04em`, the same three values on all six) reaches no style SLOT and is not settled by this
guidance — on `grid` there is nothing to set it with, and on the five v2 components it is the
`eyebrow` role's `typography.size` / `weight` / `letter-spacing` default, authorable but
deliberately left alone until #574 resolves. `base.css` ships a documented `--text-kicker-*` family at `0.75rem` / `700` /
`0.08em`, and the eyebrow does **not** route it even though "kicker" is the token's own
documented job. Those tokens are live — the `.text-kicker` utility class consumes all
three, and a grid item with `text_role: "kicker"` renders it — so retuning
`--text-kicker-size` restyles kicker-role card text and leaves every eyebrow pill exactly
where it was. Routing the eyebrow to the semantically correct token would change rendered
type in six components, so the reconciliation is tracked as its own needs-design issue
(#574). Do not pre-empt it by hand-setting eyebrow type here.

> **Text styling is a PROP, not a style slot.** A grid item's typography role
> (`text_role`: mono/meta/label/kicker) is set with `update_component` (props), not
> `style_component`. `style_component` only accepts schema-declared style slots.
> A CTA's button style used to belong in this note as `button_variant`; that prop retired
> at #1026 and a button's look is the `button` / `button-secondary` ROLE now — neither a
> prop nor a slot, but a third address. See the worked example below.

**Links inside `section.body` are the `body-link` ROLE since #1023 (#576 originally).** Its
`typography.color` and its `':hover'` nested inside the same group colour the anchors the
rich-text `body` surface can carry. Set them together — a hover colour with no resting
colour reads as a bug the first time a pointer touches it:

```json
"body-link": { "typography": { "color": "#9ec5ff", ":hover": { "color": "#ffffff" } } }
```

TWO v1 SCOPE LIMITS ARE GONE. The old `--section-body-link-color` /
`--section-body-link-hover-color` pair was consumed on the `inverted` and
`background_image` bands only — on a default or `muted` band the anchors took the global
accent and the two slots did nothing, so they were a dark-band correction rather than the
general way to colour section links. A ROLE HAS NO SUCH SCOPE: `body-link` paints on every
band, whatever its background, so it IS the general way now. And its condition was one of
the three PROSE-ONLY conditions the clause grammar could not express (a disjunction —
"inverted OR a background image"); a role's block is emitted only when its element renders,
so that condition is structural and needs no note.

## The narrow band: `logos` (and the two that left)

`logos` declares far fewer slots than `grid` — the widest surface left now that `hero`,
`section`, `testimonials`, `cta`, `faq`, `table` and `embed` have none at all — and the
gap is a contract, not an omission. Read this before assuming a slot is missing.

`table` and `embed` used to be described here beside it. Both moved to the v2 contract at
#1066 and declare no slots; what remains below for each is the retired surface, kept for
whoever meets a stored `--table-*` or `--embed-*` key on an old page.

**`table` is on the v2 contract since #1066 and has NO style slots.** Its band padding, heading
size/colour/measure/rhythm, and everything v1 had no slot for at all — the table fill, the head
fill, header and cell ink, the rule widths, the caption — are the eleven roles in
`components/table/schema.json`, set through the band's `udc` map. Sending any `--table-*` name to
`style_component` is refused with `retired_prop`, and the refusal names the roles. Two things are
worth knowing before you darken one: the table paints its OWN light surface (`table` fills
`@color-bg`, `head` fills `@color-surface`) so `header` and `cell` ink stay pinned and must NOT be
re-inked for a dark band; while `caption` and `empty` sit on the BAND fill and DO need a write.
The retired v1 surface, for reference only: `--table-padding-top` /
`-bottom`, plus `--table-heading-size` / `-color` / `-measure` / `-margin-bottom`. The
table's own text surface — body type, caption ink, header fill, header ink, rule widths
— has **no slots at all**, and `table` declares no `theme` prop and no variant classes,
so there is no band background to paint either. The one thing to know when authoring:
the horizontal scroll is **viewport-independent** (`overflow-x: auto` with no media
query), so a wide table scrolls at 1440px exactly as it does at 375px. Widen the band or
cut columns; there is no slot that switches it off.

**`embed` is on the v2 contract since #1066 and has NO style slots.** Its band padding,
heading and content column are the four roles in `components/embed/schema.json` — `_band`,
`heading`, `content` and `content-link` — set through the band's `udc` map. Sending any
`--embed-*` name to `style_component` is refused with `retired_prop`, and the refusal names
the roles. The `theme` prop retired with them.

THE ONE THING TO KNOW BEFORE DARKENING AN EMBED BAND, because it reaches less than you
expect: `content` is arbitrary author HTML, and a `_band` -> `typography.color` write is
used only where nothing else declares a colour. A bare paragraph inherits it. An
author-written `<h2>`-`<h6>` does NOT (base.css pins `@color-text`, 1.006:1 on an inverted
band), nor does a `<blockquote>`, nor does a link — links have the `content-link` role,
which ships with no defaults precisely so that an authored value there wins without a
default outranking the premium button rules for an `<a class="btn">`. So a dark embed band
costs the `_band` write plus one per element the embedded content actually uses.

Neither the old slots nor the new roles reach into a plugin's own markup further than
inheritance does: a shortcode that sets its own colours wins, and that is expected —
`embed` is the sanctioned escape hatch for plugin-rendered content, not a styling surface
for it. The retired v1 surface, for reference only: `--embed-padding-top` / `-bottom`,
`--embed-heading-size` / `-color` / `-measure` / `-margin-bottom`, `--embed-body-measure`
and `--embed-body-color`.

**`logos` (8 slots) — band padding, heading, gap, and image size.**
`--logos-image-size` is the one to know: it has **two** effective defaults, `3rem` on a
logo-only strip and `2.5rem` on a labelled tile, and setting it replaces both branches
with your single value. Prefer it on strips that are all-labelled or all-unlabelled.
`logos` is a **fit** model (`object-fit: contain`), so it deliberately exposes no
focal-point or aspect-ratio slots — a client logo must be shown whole. That is the
deliberate contrast with the testimonials avatar, which is a **crop** model.

**`faq` LEFT THIS SECTION AT #1046 — it is a v2 component and declares no slots at all.**
Style it through the `udc` map on the band, on one of its ten roles. Two things the slot
surface used to teach are still true and still worth knowing, because the roles inherited
both:

- **The closed and open states are positional twins.** `question` owns the resting row and
  `question-open` owns the expanded one, and the open role's selector outranks the resting
  one — so a colour set on `question` alone reverts the moment the reader opens the item.
  Set both or neither. The same ranking applies to a `:hover` or `:focus-visible` map: set
  it on `question-open` too when it must survive opening.
- **The disclosure chevron has no role and needs none.** It is drawn with currentColor
  borders, so it follows whatever `question` and `question-open` resolve to. Only its box
  and stroke are stated defaults, and they stay in the stylesheet because a pseudo-element
  has no role address (ruling A3).

The question/answer type pair still distinguishes the two **at identical size**, using
weight (560 vs 430) and leading alone — but it is authorable now, per breakpoint, on the
`question` and `answer` roles.

### `--table-bg`, `--embed-bg` and `--logos-bg` do not exist

Three bands have no background slot. This is one deferred decision, not three
oversights, and it is worth knowing so their absence is legible rather than silent.

- `logos` and `embed` DO have a `theme` prop; their `muted` variant paints
  `--color-surface` directly and frames the band with a `1px var(--color-border)` pair
  top and bottom. `table` has no `theme` prop at all.
- **Entry criterion for the gate that would ship them:** a band background arrives
  together with everything needed to keep the band readable. For `embed` and `logos`
  that means the framing borders must route a slot **in the same change**, or an author
  who paints the band gets a frame that no longer matches it. For `table` it means the
  whole text surface — body type, caption ink (currently `--color-muted`, measured
  3.09:1), header fill and header ink — lands at once, because shipping the typography
  half first leaves an author able to paint a band they cannot make legible.
- Until then: wrap the content in a `section` band and set its `_band` role's
  `background.fill` (section is a v2 component — there is no `--section-bg` slot and no
  `theme` prop any more), or use the narrow component's own `theme: "muted"` /
  `"inverted"` where it has one. Do not write `--table-bg`,
  `--embed-bg` or `--logos-bg` into a `style_component` call — they are not declared
  slots and the write is rejected.

---

## Fusing adjacent components into one colored band

By default every band-level component (`section`, `grid`, `cta`, `stats`, `faq`,
`testimonials`, `table`, `logos`, `embed`) renders **symmetric, non-zero** vertical
padding from the shared `--pp-band-padding` rhythm, and a band placed after another
band takes that same value on its top edge. So two stacked bands that share a
background already read as one color with a comfortable gap between them — you do not
need to touch spacing for that.

To make two adjacent same-background bands read as **one continuous, seamless band**
(no internal gap), collapse the space between them deliberately:

1. Give both components the same background (`--<upper>-bg` and `--<lower>-bg`).
2. Zero the two **facing** paddings: `--<upper>-padding-bottom: 0` on the upper
   component and `--<lower>-padding-top: 0` on the lower one. A band's own
   `--*-padding-top` slot governs its adjacent-top edge too, so this is all it takes;
   the default bottom padding stays non-zero until you override it.
3. Zero the bottom margin on the **last element of the upper component** — for a v1
   `grid` whose title is its last visible element, `--grid-heading-margin-bottom: 0`. On a
   v2 component it is the `heading` role's `spacing.margin-bottom` set to `0`, which takes
   a `breakpoints` map, so you can zero it at one tier and keep it at another — something
   the single slot could not do.

   Since #584 every band component still on the slot system carries this slot —
   `--{grid,stats,table,embed,logos}-heading-margin-bottom` (hero's left in #986, cta's at #1026, faq's at #1046,
   section's and testimonials' with their own rebuilds) —
   so the band's header rhythm is authorable everywhere. Unset, each keeps the spacing it
   always had.

   Read step 3 as "the LAST element", not "the heading". The heading is only that element on a
   band whose heading is the last thing it renders; on `stats`, `table`, `embed`
   and `logos` a required content prop always renders after the heading (the CTA group, the
   number row, the table, the embed, the strip), so on those bands the heading-margin slot is
   the INTERNAL header rhythm and step 2's `--<upper>-padding-bottom: 0` is what closes the
   seam. Zeroing the heading margin there tightens the band's own header and does nothing to
   the seam.

Step 3 is the one that is easy to miss. Once the upper band's bottom padding is zero,
its trailing element's bottom margin is no longer held inside the band: it escapes the
zero-padding edge and opens a gap between the two backgrounds that shows the page
background as a thin seam of the wrong color (a real dogfood hit a ~26px white seam
between two navy bands this exact way). Zeroing that margin closes the seam.

---

## Available recipes (v1)

| Component | Recipe | Description |
|-----------|--------|-------------|
| hero | — | No named recipe. `hero` is a v2 component: it has no style slots for a recipe to expand into. Style it through the `udc` map on the band — see the Universal Design Contract section. |
| section | — | No named recipe. `section` is a v2 component: it has no style slots for a recipe to expand into. Style it through the `udc` map on the band — see the Universal Design Contract section. The two recipes it used to ship, `accent-panel` and `spacious-editorial`, were bundles of slot values; a `udc` map says the same thing directly, and a custom preset (`save_preset`) is the reusable form. |
| grid | `dark-showcase` | Dark background with light cards |
| grid | `dense-cards` | Compact card layout with tight spacing |
| grid | `uniform-cards` | Neutralize the featured first-card treatment (top bar, texture, glow) for a uniform row |
| cta | — | No named recipe. `cta` is a v2 component: it has no style slots for a recipe to expand into. Style it through the `udc` map on the band — see the Universal Design Contract section. The two recipes it used to ship, `dark-bold` and `accent-framed`, were bundles of slot values; a `udc` map says the same thing directly, and a custom preset (`save_preset`) is the reusable form. `dark-bold` becomes `_band` -> `background.fill` plus `typography.color` on the text roles and `heading` -> `typography.size`; `accent-framed` becomes `_band` -> `border` (`width`, `style`, `color`, `radius`). |
| testimonials | — | No named recipe. `testimonials` is a v2 component: it has no style slots for a recipe to expand into. Style it through the `udc` map on the band — see the Universal Design Contract section. |
| stats | — | **No recipes.** Set `--stats-bg` + `--stats-radius` + `--stats-max-width` together for the contained rounded metrics card; there is no named shorthand for it |
| faq | — | No named recipe. `faq` is a v2 component: it has no style slots for a recipe to expand into. Style it through the `udc` map on the band — see the Universal Design Contract section. |
| table | — | **No recipes**, and none is possible today: `table` declares 6 slots, all band padding and heading, so there is nothing for a recipe to bundle. Use the `theme` prop of a surrounding `section` if the band needs a tone |
| embed | — | **No recipes.** Its 8 slots are band padding, heading, and the content column; use the `theme` prop for band tone |
| logos | — | **No recipes.** Use `--logos-image-size` and `--logos-gap` for strip density, and the `theme` prop for band tone |

A `—` row means the component ships **no named recipe**, so `style_component` with a
`recipe` key naming one is rejected. That is a real absence, not a documentation gap —
recipes were authored for the four band components a dogfood kept restyling, and the
other five never got one. It is not a claim that no useful bundle exists for them (the
stats contained-card trio above is an obvious candidate). Set the slots directly.

> **`dark-*` recipes vs. the `theme` prop — do not confuse them.** These `dark-*`
> recipes DO paint a genuinely dark background (via style slots). The band-level
> `theme` prop is different: its tinted value is `muted` (a LIGHT `--color-surface`
> band with borders), and a `theme: "inverted"` band is the dark one. For a dark band,
> set `theme: "inverted"` or use a `dark-*` recipe.

---

## Worked example -- shadows, button variants, and text roles (v0.12.0)

These three controls reach the page through **two different actions**. Slots
(shadow, border, radius, color) go through `style_component`. Props (button
variant, typography role) go through `update_component`. Mixing them up is the
most common mistake -- `style_component` rejects anything that is not a declared
style slot.

**1. Add a drop shadow + rounded corners to a band (style slots).**
```bash
wp pp action execute style_component --run-id=<uuid> --params='{
  "post_id": 19,
  "component_id": "pp-a1b2c3d4",
  "style": {
    "--grid-item-shadow": "var(--shadow-md)",
    "--grid-item-radius": "1rem"
  }
}'
```
Shadow values are bounded: a preset (`var(--shadow-none|sm|md|lg)` or `none`) or a
single-layer `box-shadow` like `0 4px 12px rgba(0,0,0,0.1)`. `inset`, multi-layer
shadows, and `url()` are rejected. The same `*-shadow` / `*-border-color` /
`*-border-width` / `*-radius` family exists on the components still on slots. On grid the
card members are namespaced under `item` (`--grid-item-border-color`, and so on), because
they paint the card rather than the band.

`hero`, `section`, `testimonials`, `cta` and `faq` are no longer in that list: they are v2
components, so a band's border, radius and shadow are the `_band` role's `border` and
`shadow` groups in the band's `udc` map (and a card's are its own role's). The
bounded-shadow grammar above is the same one either way — only the addressing differs.

**2. Style a CTA's buttons — cta IS v2, so this is a `udc` map, not slots (#1026).**

The block that used to sit here taught `button_variant`, `--cta-button-*`,
`--cta-button2-*` and `--cta-accent`. All of them are gone: `style_component` refuses cta
with `no_style_slots`, and a write naming `button_variant` is refused with `retired_prop`.
The replacement is shorter and does more, because a role takes the whole design vocabulary
and its states rather than a fixed list someone had to think of in advance.

**The two buttons are two roles.** `button` is the primary, `button-secondary` the second
one (rendered only when `button2_text` is set). They are independent by construction: v1
needed a dedicated re-pointing rule to stop the primary's slots inheriting onto the second
button, because slots were emitted as custom properties on the band root. Nothing is
emitted there now, so nothing inherits.

**The fastest route is a preset**, and `button` declares NO defaults precisely so one lands
whole:

```bash
wp pp action execute update_component --run-id=<uuid> --params='{
  "post_id": 19,
  "component_id": "pp-a1b2c3d4",
  "udc": {
    "button":           { "_preset": "button" },
    "button-secondary": { "_preset": "button-secondary", "border": { "radius": "0" } }
  }
}'
```

**A brand-coloured filled primary**, with the hover state v1's slots could only reach one
property at a time:

```bash
wp pp action execute update_component --run-id=<uuid> --params='{
  "post_id": 19,
  "component_id": "pp-a1b2c3d4",
  "udc": {
    "button": {
      "background": { "fill": "#7c3aed", ":hover": { "fill": "#6d28d9" } },
      "typography": { "color": "#ffffff", ":hover": { "color": "#ffffff" } },
      "border":     { "width": "2px", "style": "solid", "color": "#7c3aed",
                      ":hover": { "color": "#6d28d9" } },
      "shadow":     { "box": "none" }
    }
  }
}'
```

`shadow.box: "none"` is what makes it FLAT. `background.fill` emits the `background`
shorthand, which clears the premium gradient on its own — but nothing clears the premium
BEVEL, so leave that line out and you get a flat fill wearing a gradient button's shadow.
The same applies to `button-secondary`, whose own defaults already set it.

**`outline` and `ghost` have no preset.** They were `button_variant` values; write them on
the role:

```json
"button": {"background": {"fill": "transparent"},
           "border": {"width": "2px", "style": "solid", "color": "@color-accent"},
           "typography": {"color": "@color-accent"}}
```

That is `outline`; `ghost` is the same without the `border` group. **Ship the `:hover` with
it.** A role block is emitted UNLAYERED, so a resting colour outranks the stylesheet's own
hover rules in every state — a resting value without a state map is a button that does not
respond to a pointer at all.

**YOU own the contrast on a dark cta.** v1 routed the outline/ghost ink of both buttons to
an AA-safe on-dark accent whenever the band carried `theme: "inverted"` or a
`background_image`. Both props are retired, so both routings are gone: set
`typography.color` and `border.color` on the roles, against the background you authored.
`--color-accent-on-inverted` (8.33:1 on the inverted token) and `--color-accent-on-overlay`
(4.59:1 over the worst-case scrim) are still declared at `:root` and are the values to reach
for. The one exception is the FOCUS ring over a scrim, which the engine still routes
automatically — see `ai-instructions/retheme.md`.

> **Per-instance vs site-wide.** The role values above restyle ONE band's buttons. The
> global `--btn-bg` / `--btn-text` / `--btn-border-color` / `--btn-shadow` tokens — plus the
> hover pair `--btn-hover-bg` / `--btn-hover-border-color` (#539), which keep a site-wide
> fill or border retheme from reverting to the theme gradient under the pointer — (base.css,
> set via `update_design_token`) restyle EVERY composed primary button at once: the premium
> `main .btn` primary cascade routes its fill/border/ink/shadow fallbacks through them
> (#458). A role value still wins where set, and wins more decisively than a slot did: a
> band block is unlayered, so it outranks the whole stylesheet rather than sitting at the
> head of a fallback chain. See `ai-instructions/retheme.md` for the full global button
> surface.
>
> The two tiers also differ in WHICH BUTTONS THEY REACH. A `.btn` you hand-write into a
> rich-text prop is not a button any renderer owns: it follows the site-wide `--btn-*` tier
> and whatever ROLE its container declares. On cta that is moot — `cta.body` goes through
> `pp_kses_inline`, whose `a` allowlist is href/title only, so a class cannot survive there
> at all.

**Brand-accent hero CTA buttons — HERO IS v2, so this is a `udc` map, not slots (#986).**
The block that used to sit here taught `--hero-button-*`, `--hero-button2-*` and
`--hero-accent`. Those slots are gone: `style_component` refuses hero with
`no_style_slots`. The replacement is shorter and does more, because a role takes the whole
design vocabulary and three states rather than a fixed list someone had to think of in
advance.

To give a hero a solid brand-coloured primary and a matching secondary:

```bash
wp pp action execute update_component --run-id=<uuid> --params='{
  "post_id": 19,
  "component_id": "pp-a1b2c3d4",
  "udc": {
    "cta": {
      "_preset": "button",
      "background": { "fill": "#7c3aed", ":hover": { "fill": "#6d28d9" } },
      "typography": { "color": "#ffffff" },
      "border": { "color": "#7c3aed" },
      "shadow": { "box": "none" }
    },
    "cta-secondary": {
      "border": { "color": "#7c3aed" },
      "typography": { "color": "#7c3aed" }
    }
  }
}'
```

Three differences worth knowing before translating an old slot recipe:

- **No fill slot is needed to defeat the gradient.** `background.fill` on the role wins
  outright — authored band blocks are unlayered and the v1 stylesheet lives in
  `@layer pp-v1`, so nothing in that sheet can outrank it however many classes it carries.
  The "the premium gradient masks my flat fill" problem does not exist here.
- **Hover is a map, not a parallel slot family.** `":hover": { "fill": "…" }` inside the
  group, instead of a `--*-hover-bg` twin. `:focus-visible` and `:active` work the same
  way, which the slot surface never offered at all.
- **The second button already looks different.** `cta-secondary` ships the v1 outline
  treatment as its role default, so you author only what you want to CHANGE — unlike the
  slot era, where an unstyled second button inherited the primary's look.

Presets carry the LOOK, not the behaviour, and anything you set beside a preset wins over
it.

**The section's `text-panel` CTA is the `panel-cta` ROLE since #1023, and three v1 limits
went with the slots.** It used to carry the same premium gradient and the same masking
problem as the hero's, handled by a family of five slots:

```json
"panel-cta": {
  "_preset": "button",
  "background": { "fill": "#7c3aed", ":hover": { "fill": "#6d28d9" } },
  "typography": { "color": "#ffffff" },
  "shadow": { "box": "none" },
  "border": { "color": "#7c3aed", ":hover": { "color": "#a78bfa" } }
}
```

What that fixes, stated because the v1 restrictions were real and documented:

- **THE MASKING PROBLEM IS GONE.** A plain background colour could not replace the premium
  gradient, because the gradient is a background-IMAGE painted over it, so `#536` needed a
  dedicated fill slot leading a carefully-ordered chain. A role's block is emitted
  UNLAYERED and band-scoped, so it outranks the shared premium rule outright. Set
  `background.fill` and it paints.
- **THERE IS NO primary-ONLY RESTRICTION.** The v1 slots reached a `primary`
  `panel_cta_variant` only, and outline/ghost/secondary panel CTAs kept their transparent
  treatment whatever you set. `panel_cta_variant` is retired; a preset plus your overrides
  is the whole treatment, so there is no variant left to contradict.
- **THERE IS A HOVER FILL.** v1 governed the resting state only: a flat panel button
  reverted to the premium gradient under the pointer, and no per-instance hover fill slot
  existed (only the ring got a twin, in #584). A `':hover'` nested inside `background`
  holds through the hover, same as the ring's.

The CTA still renders only when the panel does (`panel_cta_text` + `panel_cta_url`), and
an unauthored `panel-cta` renders as the bare shared button.

**3. Tag a grid card's text with a typography role (an item field).**
`text_role` lives on each item inside the grid's `items` array, not as a top-level
prop. Patch the whole `items` array via `update_component` (a prop shallow-merge
replaces the array wholesale, so include every item you want to keep):
```bash
wp pp action execute update_component --run-id=<uuid> --params='{
  "post_id": 19,
  "component_id": "pp-grid5678",
  "props": {
    "items": [
      { "title": "v0.12.0", "text": "Shipped 2026-06-26", "text_role": "kicker" }
    ]
  }
}'
```
`text_role` accepts `mono`, `meta`, `label`, `kicker` and nothing else — an unknown
role is rejected at write with `invalid_prop_value` (#600), so omit the key when you
want plain body text rather than inventing a role name. `meta`/`kicker` set a preset text color; an
explicit `--grid-item-text-color` slot overrides it at all breakpoints (the role's
size/weight/spacing still apply).

**Verify all three:** re-run `wp pp operate inspect-composition --post_id=<id>` and load
the page. The grid card carries the `--grid-item-shadow` / `--grid-item-radius` inline
custom properties and its text carries `.text-kicker`. A v2 band shows nothing in
`inspect-composition`'s slot report by design — check its `udc` map and confirm the page
emits a `[data-pp-band="pp-…"]` block for each role you styled:
`curl -s "$(wp option get siteurl)/?page_id=<id>" | grep -o '\[data-pp-band[^}]*}'`.
**If you see nothing there, the most likely cause is a write that minted no band id** — a
raw `wp post meta update` of `_pp_composition` stores the bytes and mints nothing, so the
map scopes to nothing. Rewrite through `update_composition` or `update_component`.

---

## What NOT to do

- Do not edit `assets/css/components.css` to change per-instance appearance -- use style slots
- Do not add inline styles in component PHP files -- the style system handles this
- Do not set style slots that aren't declared in the component's schema.json
- Do not put `var()` in a `length` slot (or `length-or-none`/`number`/`duration`/`position`/`ratio`) at all -- these types are literal-only. This includes every measure slot: `--grid-heading-measure: var(--measure-heading)` is **rejected**, even though that is the slot's own declared default. To move band measures together, retune the token with `update_design_token`; to pin one band, write a literal. That includes a bare `var(--space-lg)` **and** `var()` nested inside `clamp()`/`calc()` (the nested form is additionally blocked for security). Look up the token's value and pass it literally -- see "Which types accept `var()`" above the recipes table

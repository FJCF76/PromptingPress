# Retheme PromptingPress

Change the entire color scheme, fonts, shape language, spacing scale, and shadows
through the design tokens.

**To retheme a SITE, use the apply/DB path — do not edit `base.css`.** Token values
for a specific site are set with `update_design_token` (stored in the
`pp_token_overrides` database option) and **survive theme updates**. `base.css` is a
release artifact: a theme update overwrites it, so editing it on a live install
loses your changes. Jump to **"Programmatic path (use this to retheme a site)"** below.

**Editing `base.css` directly (Steps 1, 2, 4) is product/release development only** —
changing the theme's shipped *defaults* as part of a release, not customizing a site.
If you are customizing a site, use the apply path instead; if a site retheme seems to
require a `base.css` edit, STOP and escalate.

The token names, families, and value rules are the same on both paths, so Steps 1–4
double as the reference for what each token does.

---

## Step 1 (release dev) — The 8 base color tokens in assets/css/base.css

These are the theme's shipped color defaults. For a SITE, set these values via
`update_design_token` instead (see the programmatic path below) — editing `base.css`
here changes the product default and is overwritten on update. When using the
programmatic path, changing `--color-accent` auto-derives **eight** tokens —
`--color-accent-hover`, `--color-accent-strong`, `--color-border-accent`,
`--color-surface-accent`, and the four on-inverted / on-overlay pairs — and changing
`--color-text` auto-derives `--color-text-secondary`. Those are the only two families
(`pp_token_families()`), so every other registered token stands alone. Note that
`--color-accent-hover` appears in the editable list below AND in that derived set: set
`--color-accent` and it moves on its own; pin it by hand and you own it from then on:

```css
--color-bg:           #ffffff;  /* Page background */
--color-surface:      #f9fafb;  /* Card / component backgrounds */
--color-text:         #1a1a1a;  /* Primary text */
--color-muted:        #6b7280;  /* Secondary text, captions */
--color-border:       #e5e7eb;  /* Dividers, outlines */
--color-accent:       #0055cc;  /* Primary action color */
--color-accent-hover: #0044aa;  /* Hover / active state */
--color-bg-inverted:  #1a1a1a;  /* Section theme: inverted bg (semantic opposite of --color-bg) */
```

**WCAG AA requirement:** `--color-accent` on `--color-bg` must have contrast ratio ≥ 4.5:1.
Check at https://webaim.org/resources/contrastchecker/

**Surface-paired accent (the inverted band).** `--color-accent` is tuned for light
surfaces; on the dark `--color-bg-inverted` it drops to ~3.2:1 and fails AA for body
text. Links (and dim accent text like inverted stats numbers) on inverted bands
therefore route through `--color-accent-on-inverted` (default `#9dafee`, 8.33:1 on the
default inverted bg) with `--color-accent-on-inverted-hover` for hover. **NOTHING ROUTES
THIS WAY AUTOMATICALLY ANY MORE.** The inverted **stats** number was the last member and
it left at #1066 with stats' `theme` prop (the inverted **embed** body link left in the
same issue's first half). On a v2 band the author writes the token BY NAME —
`@color-accent-on-inverted` in the role's `typography.color`, on `number` for stats and on
`content-link` for embed — which is the same pixel and a decision you can see in the
composition. A section's `panel_cta` is NOT included: it
sits inside the light panel, not on the band (see the panel exclusion below).

**NO BUTTON ROUTES THIS AUTOMATICALLY ANY MORE.** An `outline` or `ghost` button on an
inverted cta used to take its default ink and ring from this role, and the inverted cta
focus ring routed here for every variant including the filled one (the bare accent measured
3.23:1 there). Both were keyed on `.cta--inverted`, a class derived from the `theme` prop
that #1026 retired, so both are gone — as is the ink routing's resting-only caveat, which
only ever described those buttons. On a dark v2 band you set the button's ink, its
`border.color` and its `":hover"` yourself, and this token is the value to reach for by
name (`@color-accent-on-inverted`). **Pairing
contract:** if you change `--color-accent` OR `--color-bg-inverted`, keep
`--color-accent-on-inverted` at ≥ 4.5:1 against `--color-bg-inverted`. The programmatic
path auto-derives it (a lightened accent tint) when you change `--color-accent` and
leave it unpinned; a pinned on-inverted override that diverges from that derivation is
surfaced by the same `stale_warnings` / `masked_derived_override` machinery as every
other derived token (see below), so you are told when a base change may not reach it.

**Surface-paired accent (the bg-image band).** Any band whose `_band` `udc` map sets
`background.image` plus `background.overlay` (on v2 a `cover` hero carrying `image_url`
or `image_id` is REFUSED at write with `inert_prop`; do not author that pair) lays a
dark `rgba(0,0,0,.55)` scrim over an ARBITRARY image.
**NO INK ROUTES AUTOMATICALLY ON A SCRIM BAND ANY MORE.** The v1 routing worked by band
CLASS, and a v2 band has no class — **stats was the last component that had one**, and its
three corrections (the number, the `title_accent` substring and the label) retired with
`.stats--has-bg-image` at #1066. YOU own the contrast on every band: set a
`typography.color` on each text role over the image, reaching for
`@color-accent-on-overlay` and `@color-muted-on-overlay` by name. Note especially that an
accented heading SUBSTRING does not inherit the heading's ink — it paints its own colour —
so `heading` and `heading-accent` are two writes, not one.

THE ONE EXCEPTION IS THE FOCUS RING, and it is not class-bound: since #986 it routes from
`[data-pp-band-overlay]`, which the ENGINE emits on any v2 band painting both an image and
an overlay, so it reaches such a band without the author switching it on. It
is drawn outside the button, so it lands on the scrim rather than the button's own fill.
(`.hero--cover` is still a term in that selector, so a `cover` hero gets it whether or not
it has an image, because the scrim is painted either way.) The ring and both authored
tokens route through a SEPARATE role,
`--color-accent-on-overlay` (default `#fafbff`), with `--color-accent-on-overlay-hover`
(default `#ffffff`) for hover. A section's `panel_cta` is NOT included here either — it
sits inside the light panel, not on the scrim (see the panel exclusion below). This is NOT the same as `--color-accent-on-inverted`:
on-inverted is tuned to the SOLID `--color-bg-inverted`, but the overlay sits over an
unknown image, so its worst case is the overlay composited over a pure-WHITE image
(effective bg ≈ `rgb(115,115,115)`). **Pairing contract:** the default must be ≥ 4.5:1
against that worst-case composite, not against any single image. Because the overlay over
white has a hard contrast CEILING of 4.74:1 for any foreground, the only values that clear
AA there are near-white — so `--color-accent-on-overlay` is intentionally near-white; the
name describes the ROLE (accent on an overlay surface), not the hue, and the link's
affordance comes from its underline. The programmatic path auto-derives it (a near-white
accent tint) when you change `--color-accent` and leave it unpinned; a pinned override
that diverges is surfaced by the same `stale_warnings` / `masked_derived_override`
machinery as every other derived token.

**De-emphasised ink on the same band uses its own role, `--color-muted-on-overlay`
(default `#fafbff`).** A stats `label` on a scrim band is deliberately quieter than the
heading beside it — and since #1066 you reach this token BY NAME on every component
(`@color-muted-on-overlay` in the `label` role's `typography.color`) instead of getting it
from a class. stats was the last band that got it automatically; cta's went at #1026.
That used to be spelled
`opacity: 0.85`, which composited the ink to `rgb(231,232,234)` and measured **3.87:1**
against the worst-case composite — a WCAG AA failure on normal-size text. The de-emphasis
now lands as this role token instead of a literal, so it is tunable, measurable, and in
one place. Be aware of how little room there is: with no opacity at all, full
`--color-bg` reaches only **4.658:1** on that band, and 4.5:1 needs a luminance of about
`#f9f9f9`. **The entire de-emphasis budget on an overlay band is roughly 0.07:1**, which
is why the shipped value is near-white and why re-introducing an opacity literal on that
band will fail the rendered contrast pins. On the SOLID inverted band there is real
headroom — and THE THEME NO LONGER SPENDS IT WITH AN `opacity` LITERAL. The last two were
the inverted stats and logos labels at `0.75`, and both left at #1066 with the `theme` prop
whose class they were keyed on (a cta body at 12.76:1 was the third until #1026). `opacity`
has no UDC group of its own, and since #1079 a role's `"_css"` map reaches it anyway — so
the ban above is now YOURS to keep, not the engine's. On a dark v2 band the de-emphasis is
still a real colour rather than an alpha, for the reason measured above: write the
PIXEL-MEASURED COMPOSITE of v1's paint, **`rgb(192, 195, 201)`**, on `label` ->
`typography.color`. It measures **10.11:1** on `--color-bg-inverted` — the composite is
192.75 / 195.5 / 201.75 and Chromium floors each channel, which is why it is not the 10.2:1
an idealised calculation gives. The `:not(.stats--has-bg-image)` carve-out that used to
protect the combined inverted-plus-image band went with the classes: a v2 band writes ONE
colour per role, so there is no second rule to carve out of. This role is NOT auto-derived from
`--color-accent`: it is a contrast floor tied to the overlay, not an accent tint, so a
retheme cannot move it below the bar.

On an overlay band the accent role used to do one more job: it was the DEFAULT border of
every FILLED button on the band — a SEPARATION RING, sitting last in the border chain so it
painted only where you had not coloured that edge yourself. It matters because the premium
gradient fill measures only ~1.1:1 against the worst-case composite, so without a ring the
button's shape disappears into the band and only its label carries it.

**THAT RING IS NOW GONE EVERYWHERE, and it is the part to plan around.** Every rule that
drew it was keyed on `.cta--has-bg-image` — hero's half had already gone at #986 — and that
class derived from the `background_image` prop #1026 retired, so no band gets an automatic
filled-button ring any more. This is the same ruling #986 made for the hero's re-coloured
title: v2 has no variant-scoped role defaults and does not guess. **On any v2 band you own
the ring**: set
`border.color` on the `button` / `button-secondary` role, at rest and in `":hover"`, and
check it against the band you actually authored. `--color-accent-on-overlay` (4.59:1 over
the worst-case scrim) and `--color-accent-on-inverted` (8.33:1 on the inverted token) are
still declared at `:root` and are the values to reach for.

The focus ring is a SEPARATE surface from that border and has no per-instance slot:
recolouring a button's border does not recolour its focus ring, and vice versa. Both
dark-band focus routings change the ring's COLOUR only — its width, style and offset are
unchanged, and a light band's focus ring is exactly what it always was.

Focus-ring routing covers any v2 band the engine marks with `data-pp-band-overlay` plus the
`cover` hero — the bands that put a button ON a scrim. A `text-panel` SECTION is deliberately excluded even on those bands:
its `panel_cta` sits inside the panel, which is a self-contained LIGHT surface, so its
ring already contrasts there and the dark-band roles would make it worse. Same reasoning
as the panel's list markers. That exclusion covers the button's INK as well as its ring:
an `outline` / `ghost` / `secondary` `panel_cta` keeps the ordinary light-surface accent
(or `--color-text` for `secondary`) on EVERY band, because it is read against the panel,
never against the band behind it. So a retheme that makes a band darker does not need a
matching panel-CTA adjustment — keep `--color-accent` legible against
`--color-surface`, which is the surface that button actually sits on.

**The FOCUS ring is the one dark-band affordance that is still automatic, and since #986 it
follows the scrim rather than a class.** The engine emits `data-pp-band-overlay` when a band
paints both a `background.image` and a `background.overlay`, and the outline routes to
`--color-accent-on-overlay` from there — on any v2 layout, and without the author switching
it on. Two limits worth knowing: a band you merely DARKEN with a fill carries no such marker
(nothing in CSS can compare your authored colour to a ring), so keep `--color-accent`
legible against any band colour you author; and a scrim you LIGHTEN still carries the marker
and still gets the near-white routing, because the attribute records that a scrim exists,
not how dark it is.

**ON A v2 COMPONENT THIS WHOLE TRAP IS GONE, and the reason is worth knowing because it
is the shape of every future sprint.** All TEN v2 components — `hero`, `section`,
`testimonials`, `cta`, `faq`, `table`, `embed`, `stats`, `logos` and `grid` — have no `theme` prop,
no band class, and no dark-band ROUTING: a band you make dark with `_band`
`background.fill` (or `background.image` + `overlay`) does not silently recolour its text
for you, so there is no class-versus-literal conflict to fall into. The trade is that YOU
own the contrast — set a `typography.color` on every text role over the background. There
is no light-on-light failure mode left, because nothing infers "this band is dark" from
anything.

The retired trap, recorded because a retheme touching a 1.x site still meets it: `#577`
made `--section-bg` win over the `muted` / `inverted` theme paint (before, the theme
literal silently defeated it), which meant an `inverted` section painted light by
`--section-bg` kept its `pp-section--inverted` class and therefore its near-white heading,
body and link routing — light-on-light. Both the slot and the class retired with #1023.
The same trap is still live on `grid` until its own rebuild — it is the last v1 component
in the theme. `cta` left that list at #1026 and `stats` and `logos` at #1066, each the same
way and for the same reason.

Example retheme — warm neutral:
```css
--color-bg:           #fefefe;
--color-surface:      #f5f0eb;
--color-text:         #1c1917;
--color-muted:        #78716c;
--color-border:       #e7e0d8;
--color-accent:       #b45309;
--color-accent-hover: #92400e;
--color-bg-inverted:  #1c1917;
```

---

## Step 2 (release dev) — The font tokens

For a SITE, set fonts via the `enqueue_font` apply (Step 3) and the font-family token
via `update_design_token` — not by editing `base.css`. As a release default, in
`assets/css/base.css`:

```css
--font-body:    system-ui, sans-serif;
--font-heading: system-ui, sans-serif;
```

Replace `system-ui, sans-serif` with your chosen web font name, e.g.:

```css
--font-body:    'Inter', system-ui, sans-serif;
--font-heading: 'Playfair Display', Georgia, serif;
```

**A distinct heading face does not reach the `stats` display numbers.** They are the
largest text on the band but they are not headings: they take `--font-body` at weight 700
unless you say otherwise, and that is DELIBERATE — the `number` role declares the
`typography` group and defaults no `family`, because v1 rendered the inherited body face
and shipping an explicit default would put an unlayered declaration in your way. After
swapping `--font-heading` to a display face, bring the figures with it per instance:
`number` -> `typography.family` (and `weight`, if the display face needs a different one)
in the band's `udc` map, the same way any other v2 role value is set.

---

## Step 3 — Enqueue the font via apply (no file edits needed)

Use the `enqueue_font` apply to add fonts without editing functions.php. Font URLs are stored in the database and survive theme updates.

**Google Fonts example:**
```bash
wp pp apply execute enqueue_font --run-id=<uuid> --params='{"url":"https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=Playfair+Display:wght@700&display=swap"}'
```

**Bunny Fonts (GDPR-friendly) example:**
```bash
wp pp apply execute enqueue_font --run-id=<uuid> --params='{"url":"https://fonts.bunny.net/css?family=inter:400,600,700|playfair-display:700"}'
```

Fonts are enqueued before `pp-base` so they load first. Max 5 font URLs. HTTPS only. To remove: `remove_font`. To clear all: `reset_fonts`.

---

## Step 4 (release dev) — Adjust --radius for shape language

For a SITE, set `--radius` via `update_design_token`. As a release default, in
`assets/css/base.css`:

```css
--radius: 0.375rem;  /* Current: subtle rounding */
```

| Value      | Effect |
|------------|--------|
| `0`        | Sharp, geometric corners |
| `0.375rem` | Subtle rounding (default) |
| `0.75rem`  | Noticeable rounding |
| `1rem`     | Rounded cards and surfaces |

`--radius` is the GLOBAL shape token — it drives cards, panels, surfaces, and
image corners. It does not control button corners: buttons have their own
`--btn-radius` token (default `4px`). To pill a CTA without rounding cards, set
`--btn-radius` (e.g. `100px`) via `update_design_token` and leave `--radius`
alone. Setting `--radius` to a huge value to "pill buttons" is the wrong lever —
it rounds every card and panel too, and no longer reaches the button at all.

### The global button color tokens (the site-wide button surface)

> **NONE OF THE NINE v2 COMPONENTS IS ON THIS SURFACE ANY MORE (#986, #1023, #1026,
> #1046, #1066): hero, section, testimonials, cta, faq, table, embed, stats and logos.**
> Everything in this section describes the v1 per-instance STYLE SLOT cascade, which now
> governs `grid` ALONE — the last v1 component in the theme (`stats` and `logos` left at
> #1066). `hero`, `section`, `testimonials`, `faq`, `table`, `embed`, `stats`, `logos`
> and `cta` — all nine — are v2 components with no style slots: their buttons and text are ROLES, styled
> through the band's `udc` map. Any `--hero-button-*`, `--hero-button2-*`, `--hero-accent*`,
> `--section-*` or `--cta-*` name below is HISTORY — writing one is refused with
> `no_style_slots`. Read
> them as "the cta equivalent"; to restyle a hero button set `background.fill`,
> `typography.color` and `border.color` (plus a `':hover'` map) on its `cta` /
> `cta-secondary` role, and a section's panel button the same way on its `panel-cta` role,
> or apply the `button` / `button-secondary` preset. A role value beats every tier
> described here, because authored band blocks are unlayered and this stylesheet lives in
> `@layer pp-v1`. The list of components this section governs shrinks by one per rebuild
> sprint; when it empties, the section goes with it.

The shared button system carries four registered color tokens, the button analog of
`--btn-radius`, set via `update_design_token`. They are a REAL site-wide restyle knob: the
premium `main .btn` primary cascade routes its fill, border, ink, and shadow fallbacks
through these tokens (#458), so setting one at `:root` restyles EVERY composed primary
button — the section-panel CTA and the CTA-block button alike, both of which are reached by
`main .btn` now that their own component rules are gone. Three of the four register as `initial` (unset), so each
consuming rule resolves its own literal until you set the token; an unset button therefore
renders byte-identically to today.

| Token | Set it to… | Reaches | Effective default when unset |
|-------|-----------|---------|------------------------------|
| `--btn-bg` | recolor every button fill | bare `.btn`, premium `main .btn` primary — which since #1026 is every composed primary there is, including both CTA-block buttons, both hero buttons and the section-panel CTA | `--color-accent` (bare) / accent gradient (composed primary) |
| `--btn-text` | recolor every button label ink | every button ink rule | `var(--color-bg)` (registered, the inversion coupling below) |
| `--btn-border-color` | recolor every button border | bare `.btn` (incl. the `outline` variant), premium `main .btn` primary. **The #564 carve-out is gone**: it excluded filled buttons on `background_image` cta bands, and that separation ring retired with the class at #1026, so there is no exception left | `--color-accent` (bare) / `--color-accent-strong` (premium) |
| `--btn-shadow` | change every button's elevation (a `--shadow-*` preset, or `none` to flatten) | bare `.btn`, premium primary | `none` (bare) / premium bevel (composed primary) |

**THE PER-COMPONENT TIER IS EMPTY FOR BUTTONS AS OF #1026.** `--btn-*` used to sit BETWEEN
the per-component button slots and the literal fallback. Every one of those families has now
retired with its component: `--hero-button-*` and `--hero-accent` at #986,
`--section-panel-cta-*` at #1023, `--cta-button-*` / `--cta-button2-*` / `--cta-accent` at
#1026. No component in the theme declares a button style slot, so each chain is one global
knob and one literal. (`grid` declares `--grid-item-link-color` and
`--grid-item-link-hover-color`, and those are not the exception they look like: they are
INK slots on the card's "Read more" link — no fill, no border — so a `--btn-*` retheme
still owns every button surface on the page.)

**Per-component button styling did not go with them — it moved somewhere stronger.** A v2
component declares button ROLES: hero's `cta` and `cta-secondary`, section's `panel-cta`,
cta's `button` and `button-secondary`. A role value is emitted UNLAYERED while this
stylesheet sits in `@layer pp-v1`, so it outranks every tier here at any specificity,
instead of competing for position inside a fallback chain. **So a `--btn-*` retheme moves
every button EXCEPT one a band has authored**, which is the intended reading of "the
author's value wins" — the same contract as before, reached more decisively.

Three practical consequences of the move, worth planning for:

- **A role reaches surfaces a slot could not.** Hover FILL, hover ink, elevation and the
  ring are all ordinary parameters with `':hover'` maps, per breakpoint. The section-panel
  CTA is the clearest case: it had no per-instance hover fill slot at all, so the global
  knob was the ONLY way to move its hover fill. `panel-cta` ends that.
- **A role cannot arrive by inheritance.** Slots were custom properties on the band root and
  reached every descendant, which is why the theme needed a neutralisation rule (#545) to
  keep a band's button styling off a `.btn` an author hand-wrote into rich text. A role is
  an address the author chose; the rule retired at #1026 with the last slot family.
- **The precedence rulings are not reversed — their subject is gone.** #538, #548, #564 and
  #565 each arbitrated which of two links won when both were set. With one link per chain,
  the question cannot arise.

**The global tier has hover twins for fill and border.** `--btn-hover-bg` and
`--btn-hover-border-color` are the hover counterparts of `--btn-bg` and `--btn-border-color`,
and they sit at the same place in the hover chains that the resting knobs occupy at rest.
Set them alongside the resting pair whenever you recolour buttons site-wide, or the retheme
reverts to the theme's premium accent gradient the moment a pointer lands.

| Token | Set it to… | Reaches | Effective default when unset |
|-------|-----------|---------|------------------------------|
| `--btn-hover-bg` | recolor every button's hover fill | bare `.btn`, every composed primary, premium `main .btn` primary | `--color-accent-hover` (bare) / the premium hover gradient (composed primary) |
| `--btn-hover-border-color` | recolor every button's hover border | the same surfaces, with no carve-out left — see below | `--color-accent-hover` (bare) / `--color-accent` (premium) |

A band that authors the same property on a button role beats both, at rest and on hover.

**THE SCRIM-BAND RING CARVE-OUT IS GONE ENTIRELY, and it is a real narrowing rather than a
simplification.** It worked like this: a band over a scrim rang its filled buttons with
`--color-accent-on-overlay`, a near-white role measured at 4.59:1 against the worst-case
composite so the button's shape survived the photo behind it, and the global knobs were
deliberately kept OUT of that chain so a site-wide retune could not cancel the guarantee
(#564 for the ring knobs, #565 for the fill knobs). Every rule implementing it was keyed on
`.cta--has-bg-image`; hero never had one, its half having gone at #986. That class derived
from the `background_image` prop #1026 retired, so the ring and its carve-out went together
and NO band now gets an automatic filled-button ring. The consequence to plan around: on a
scrim band `--btn-border-color` now reaches the button border through the ordinary premium
chain like anywhere else, and nothing holds a contrast floor for you. Set `border.color` on
the band's button role (on a cta, `button` / `button-secondary`), at rest and in `':hover'`,
reaching for `--color-accent-on-overlay` (4.59:1) or `--color-accent-on-inverted` (8.33:1).
So a ring correction that used to be a single global edit is now per band. The FOCUS ring is
the exception and is still automatic — see above.

The **section-panel CTA** used to be the sharpest case of this: it had no per-instance hover
FILL slot at all, so the global knob was the ONLY way to move its hover fill, and only the
RING got a per-instance hover twin (#584). **#1023 removed that limit rather than documenting
it further.** The panel button is the `panel-cta` role, and a `':hover'` nested inside
`background` is a per-instance hover fill — so a flat panel button holds its colour through
the hover instead of reverting to the premium gradient, and the global knob is no longer the
only route. The remaining v1 button families keep the old behaviour until their own rebuilds.

**The hero's second CTA is no longer an exception (#554).** It used to be the one filled
surface on the theme whose OWN chains never routed the global tier, so a site-wide button
retheme left it behind — visibly, because the global knob still reached it through the shared
premium rule (a flat value there clears the theme gradient) while the second button's own
higher-specificity rule kept painting `--color-accent` / `--color-accent-hover`. A rethemed
hero rendered a FLAT ACCENT second button beside a brand-coloured primary. Its rest AND hover
chains now route `--btn-bg` / `--btn-border-color` / `--btn-hover-bg` /
`--btn-hover-border-color` at the same positions the hero PRIMARY holds them, so a filled hero
pair moves together under a site-wide retheme. On a `cover` hero none of those four is in
either button's BORDER chain any more (ring knobs removed in #564, fill knobs in #565), so a
site-wide retheme does not move that pair's RINGS there — but it does not split them either,
since both buttons lost each knob together. Their FILLS still follow the global tokens as
everywhere else; it is only the separation ring that holds the measured role. The v1
advice here was that you no longer need to set `--hero-button2-bg` /
`--hero-button2-hover-bg` merely to keep the pair consistent — and BOTH NAMES ARE HISTORY
since #986 (hero declares no style slots; writing either is refused with `no_style_slots`).
The surviving instruction is the same one in role vocabulary: set `background.fill` on the
`cta-secondary` role, with its `':hover'`, only when you want that button to DIFFER.

**Watch hover contrast: there is no global hover INK token.** `--btn-text` sets label ink at
rest, but the bare `.btn` and the premium primary hard-code hover ink to `--color-bg`. So if
you pin a custom `--btn-text`, hover ink still reverts to `--color-bg`. When choosing
`--btn-hover-bg`, check it against **`--color-bg`**, not against `--btn-text` (≥ 4.5:1) — or
set `typography.color` with a `':hover'` on the button's ROLE, which is the only surface that
reaches hover ink now that the per-instance ink slots have retired.

**There is no global hover ELEVATION token either.** `--btn-shadow: none` flattens rest and
the premium bevel returns on hover. Set `shadow.box` on the button's role (`cta` /
`cta-secondary` on hero, `panel-cta` on section, `button` / `button-secondary` on cta), with
a `':hover'` nested inside `shadow` if the two should differ — which the v1 slot could not
express. Note that a RESTING role value already covers hover on its own: a role block is
unlayered, so it outranks the stylesheet's hover rule for the same property in every state.

**`--btn-hover-border-color` also rings the `outline` variant.** `.btn--outline:hover` paints
its own fill but declares no border, so the global hover ring reaches it — exactly as
`--btn-border-color` reaches its resting border. Its hover FILL is not routed by any global
knob, so an outline button hovers to the theme accent wearing your ring. Set `border` with a
`':hover'` on the role if that pairing matters on a given button.

**Fill and border are independent knobs.** `--btn-bg` recolors the fill; `--btn-border-color`
recolors the border. The plain `main .btn` primary keeps its own `--color-accent-strong`
border until you set `--btn-border-color` — matching the bare `.btn` primitive, where fill
and border are separate. Set both when recoloring buttons site-wide so every context stays
consistent.

**THE BORDER-FOLLOWS-FILL IDIOM IS GONE FROM EVERY REBUILT COMPONENT.** It existed because a
per-instance FILL slot sat in the border's fallback chain, so recolouring a fill alone kept a
matching ring. A role has no fallback chain: `background.fill` and `border.color` are two
values you write or omit, and omitting the border leaves it on the stylesheet's default
rather than following your fill. **So on a v2 band, set both.** That is more typing and less
guessing, and it is the same trade the rest of the contract makes.

**On `cover` heroes the ring bottoms out at `--color-accent-on-overlay`**, a measured 4.59:1
separation role, and no global token sits above it (#564 removed the ring knobs, #565 the
fill knobs). So a site-wide `--btn-bg` recolors those buttons' FILLS while leaving their
rings on the role — deliberately, because a token aimed at no band in particular must not
cancel a contrast guarantee. Set `border.color` on the role for a specific ring colour.

**The `--btn-text` → `--color-bg` inversion coupling.** Button text defaults to the PAGE
BACKGROUND token, not to `--color-text`. Buttons invert on purpose: the accent fill is
dark relative to a light page, so the label uses the light page-background color to read
on top of it. This coupling is the ink rule's literal fallback
(`color: var(--btn-text, var(--color-bg))`), so changing
`--color-bg` also moves button ink unless you pin `--btn-text`. When you set a custom
`--btn-bg`, check `--btn-text` still contrasts against it (≥ 4.5:1).

---

## Step 5 — Verify no raw hex remains in components.css

Run the repo's own checker:

```bash
php scripts/check-raw-hex.php
```

It prints `OK: no raw hex colors in assets/css/components.css` and exits 0 when clean.
If it names offending lines, replace each value with the corresponding CSS variable from
`base.css`.

**Do not substitute a bare `grep` for this, which earlier versions of this page told you
to do.** A pattern like `#[0-9a-fA-F]{3,6}` cannot tell a colour from an issue reference,
and the stylesheet's v2 rebuild comments are full of the latter: that grep returns 180
matches today — `#1023`, `#1026`, `#1046`, `#994`, `#986` and friends — against a file the
real checker calls clean. An agent that trusts the grep starts rewriting issue numbers
into CSS variables.

---

## What NOT to touch

| File                     | Reason |
|--------------------------|--------|
| components/*.php         | Changing colors/fonts in PHP would bypass the token system |
| lib/*.php                | WP abstraction — not styling |
| templates/*.php          | Page layout — not styling |
| schema.json files        | Machine-readable contracts — not styling |
| functions.php            | Use `enqueue_font` apply instead of editing directly |

The entire visual output of the site flows through the design tokens and the apply/action
model. Editing files directly is unnecessary for a retheme — use `update_design_token` for
global tokens, `enqueue_font` for fonts, and, for per-band visual overrides, **the band's
`udc` map** on any of the nine v2 components (carried by `update_composition` /
`create_page`). `style_component` remains only for `grid`, the last component with style
slots; on anything else it is refused with `no_style_slots`.

One documented exception, so you do not go looking for a token that is not there: the
shared band rhythm and band-heading scale (`--pp-band-padding`, `--pp-band-heading-size`)
are theme-internal properties declared outside the token registry, so
`update_design_token` rejects them as `unknown_token`. They have no site-wide authoring
surface, and changing one is a band-at-a-time job. **How you do it depends on the tier,
and the slot answer is now the rare case:**

- **The ten v2 components** (hero, section, testimonials, cta, faq, table, embed, stats,
  logos, grid) — set it in the band's `udc` map. Vertical rhythm is the `_band` role's
  `spacing.padding-top` / `padding-bottom`; heading size is the `heading` role's
  `typography.size`. Both accept a literal or a REGISTERED design token (`@space-2xl`
  verified accepted). They do **not** accept `@pp-band-padding` or
  `@pp-band-heading-size` — the same registry gap that makes `update_design_token` reject
  them makes an authored reference to them `invalid_prop_value`. Those two names are
  reachable only as shipped role defaults.
- **`grid`** — the one component with style slots left, and the only place the slot
  answer still applies: `--grid-padding-top` / `--grid-padding-bottom` /
  `--grid-heading-size` via `style_component`. On any other component that call is
  refused with `no_style_slots`.

---

## Programmatic path (use this to retheme a site)

This is the path for site retheming. Tokens are changed via the apply layer without editing base.css. Overrides are stored in the database (`pp_token_overrides` option) and survive theme updates:

```bash
# Mutating applies need a run token + a site-scoped preflight first:
#   wp pp operate inspect  →  wp pp apply preflight --run-id=<uuid>  →  execute
wp pp apply execute update_design_token --run-id=<uuid> --params='{"token":"--color-accent","value":"#b45309"}'
wp pp apply preview update_design_token --params='{"token":"--color-accent","value":"#b45309"}'  # diff without writing (no run-id)
wp pp apply restore --run-id=<uuid> --token=--color-accent  # undo this run's change to one token (back to the pre-run snapshot)
wp pp apply restore --run-id=<uuid>                          # undo everything this run touched
wp pp apply reset --run-id=<uuid> --token=--color-accent     # clear one override → product default
wp pp apply reset --run-id=<uuid>                            # clear ALL overrides → product defaults
```

Or from PHP: `pp_execute_apply('update_design_token', ['token' => '--color-accent', 'value' => '#b45309'])`.

The apply layer validates token names, enforces type-specific value constraints, and verifies the write via database read-back. Use this path when rethemeing programmatically (e.g. from an AI interface).

**Token family derivation:** When you change `--color-accent`, eight derived tokens (`--color-accent-hover`, `--color-accent-strong`, `--color-border-accent`, `--color-surface-accent`, `--color-accent-on-inverted`, `--color-accent-on-inverted-hover`, `--color-accent-on-overlay`, `--color-accent-on-overlay-hover`) are auto-filled if they have no existing override. Changing `--color-text` auto-derives `--color-text-secondary`. **A base-token change never touches an existing derived override** — a deliberately pinned derived value survives. That is safe for pins, but it means a base change can succeed (`ok:true`) yet have no visible effect where a stale derived override still wins (e.g. you set `--color-accent` blue but an old orange `--color-accent-strong` keeps the CTA orange). To make that visible, the apply returns a `stale_warnings` entry for any preserved derived override that **diverges** from the value the new base would derive, naming the masking token so you can decide whether to update it. A coherent override (one that already equals the derivable value) is not flagged. The same divergence is reported at INSPECT as a `masked_derived_override` token smell (see `token_smells` in `wp pp operate inspect`), so you catch it before making a change, not only after. This issue makes the state visible; it never recomputes or clobbers your derived overrides.

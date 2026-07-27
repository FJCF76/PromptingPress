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
programmatic path, changing `--color-accent` auto-derives the six accent variants and
changing `--color-text` auto-derives `--color-text-secondary`:

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
default inverted bg) with `--color-accent-on-inverted-hover` for hover. The same applies
to any BUTTON whose variant paints ink straight onto the band: an `outline` or `ghost`
button on an inverted cta takes its default ink and ring from `--color-accent-on-inverted`
too (the bare accent measured 3.23:1 there). Only the RESTING state routes this way —
on hover both variants paint their own contrasting fill, so the ink reverts to the
variant's normal hover colour. The FOCUS RING is routed through the same role on an
inverted cta, for EVERY button variant including the filled one: the ring is drawn
outside the button, so it lands on the band rather than on the button's own fill.
**Pairing
contract:** if you change `--color-accent` OR `--color-bg-inverted`, keep
`--color-accent-on-inverted` at ≥ 4.5:1 against `--color-bg-inverted`. The programmatic
path auto-derives it (a lightened accent tint) when you change `--color-accent` and
leave it unpinned; a pinned on-inverted override that diverges from that derivation is
surfaced by the same `stale_warnings` / `masked_derived_override` machinery as every
other derived token (see below), so you are told when a base change may not reach it.

**Surface-paired accent (the bg-image band).** A section/cta/stats band WITH a
`background_image` — and a hero with `cover` layout + `image_url` — lays a dark
`rgba(0,0,0,.55)` overlay over an ARBITRARY image, so EVERY accent surface on that band
(section/cta links, stats numbers, the `title_accent` substring on all four, section
body list markers, the `outline`/`ghost` buttons on a cta or cover hero — including
the hero's second CTA, whose variant DEFAULTS to `outline` — and the FOCUS RING of
EVERY button variant on a bg-image cta or cover hero — drawn outside the button, so it
lands on the scrim, and applying to any `cover` hero whether or not it has an
`image_url`, because the scrim is painted either way) routes through a SEPARATE role,
`--color-accent-on-overlay` (default `#fafbff`), with `--color-accent-on-overlay-hover`
(default `#ffffff`) for hover. This is NOT the same as `--color-accent-on-inverted`:
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

On an overlay band the role does one more job: it is also the FILLED primary button's
border, so the button keeps a visible edge. The premium gradient fill measures only
~1.1:1 against the worst-case composite, so without that ring the button's shape
disappears into the band and only its label carries it. The solid inverted band does NOT
get this ring — the same fill measures 3.23:1 there, which already clears the 3:1
non-text bar. Per-instance slots (`--cta-button-border`, `--hero-accent`) still win, so
you can recolour the ring; a band you darken yourself with `--cta-bg` gets no automatic
ring, because nothing in CSS can compare your authored band colour to the button fill.

The focus ring is a SEPARATE surface from that border and has no per-instance slot:
recolouring a button's border does not recolour its focus ring, and vice versa. Both
dark-band focus routings change the ring's COLOUR only — its width, style and offset are
unchanged, and a light band's focus ring is exactly what it always was.

Focus-ring routing covers the cta and the cover hero, the two components that put a
button ON the band. A `text-panel` SECTION is deliberately excluded even on those bands:
its `panel_cta` sits inside the panel, which is a self-contained LIGHT surface, so its
ring already contrasts there and the dark-band roles would make it worse. Same reasoning
as the panel's list markers. A dark band you produce yourself with `--cta-bg` /
`--section-bg` rather than `theme: "inverted"` or a `background_image` carries no band
class, so it gets no routing at all; keep `--color-accent` legible against any band
colour you author, and note the reverse also holds — if you LIGHTEN a scrim with
`--cta-overlay-bg` the band keeps its class and keeps the near-white routing.

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
largest text on the band but they are not headings: they take `--font-body` at weight
700 unless you say otherwise. After swapping `--font-heading` to a display face, bring
the figures with it per instance via `--stats-number-font` / `--stats-number-weight`
(`style-component.md`), the same way per-component button slots are set for buttons.

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

The shared button system carries four registered color tokens, the button analog of
`--btn-radius`, set via `update_design_token`. They are a REAL site-wide restyle knob: the
premium `main .btn` primary cascade (and the `.cta`/`.hero` primary rules) route their
fill, border, ink, and shadow fallbacks through these tokens (#458), so setting one at
`:root` restyles EVERY composed primary button — the section-panel CTA, the CTA-block
button, and the hero button alike. Three of the four register as `initial` (unset), so each
consuming rule resolves its own literal until you set the token; an unset button therefore
renders byte-identically to today.

| Token | Set it to… | Reaches | Effective default when unset |
|-------|-----------|---------|------------------------------|
| `--btn-bg` | recolor every button fill | bare `.btn`, `.cta`/`.hero` primary, a filled cta second button, premium `main .btn` primary | `--color-accent` (bare) / accent gradient (composed primary) |
| `--btn-text` | recolor every button label ink | every button ink rule | `var(--color-bg)` (registered, the inversion coupling below) |
| `--btn-border-color` | recolor every button border | bare `.btn`, `.cta`/`.hero`, premium primary | `--color-accent` (bare/`.cta`/`.hero`) / `--color-accent-strong` (premium) |
| `--btn-shadow` | change every button's elevation (a `--shadow-*` preset, or `none` to flatten) | bare `.btn`, premium primary | `none` (bare) / premium bevel (composed primary) |

**Per-component slots still win.** `--btn-*` sits BETWEEN the per-component slots
(`--cta-button-*`, `--cta-button2-*`, `--cta-accent`, `--hero-button-*`, `--hero-cta2-*`,
`--hero-accent`, `--section-panel-cta-*`) and the literal fallback. A component that sets its own slot keeps
overriding the global token, so a site-wide `--btn-bg` recolors every button that has not
been individually restyled.
(The hero's per-instance FILLED-primary slots are `--hero-button-bg` / `--hero-button-hover-bg`
/ `--hero-button-color` / `--hero-button-shadow`; `--hero-accent` recolors only its border. A
cta or hero SECOND button has its own family — `--cta-button2-*` / `--hero-cta2-*` — which the
primary's slots never reach in rest OR hover, so restyling the primary alone leaves the second
button on the global token. The section's `text-panel` CTA has its own family too —
`--section-panel-cta-bg` / `--section-panel-cta-color` / `--section-panel-cta-shadow` — which
is the only way to give one section's panel button a flat brand fill without moving the
site-wide token. See `ai-instructions/style-component.md`.)

**There is no global hover-fill token.** `--btn-bg` is resting-state only: there is no
`--btn-hover-bg`, so a site-wide fill retheme still returns to the theme's premium gradient on
hover. Hover fill is per-instance only — `--hero-button-hover-bg`, `--cta-button-hover-bg`,
`--hero-cta2-hover-bg`, `--cta-button2-hover-bg` — and each pairs with its resting slot.

**Fill and border are independent knobs.** `--btn-bg` recolors the fill; `--btn-border-color`
recolors the border. On `.cta`/`.hero` primaries an unset border follows the fill (so a
recolored `--btn-bg` alone keeps a matching ring), but the plain `main .btn` primary (e.g.
the section-panel CTA) keeps its own `--color-accent-strong` border until you set
`--btn-border-color` — matching the bare `.btn` primitive, where fill and border are separate.
Set both when recoloring buttons site-wide so every context stays consistent. The same
border-follows-fill idiom applies to the hero's per-instance slots: a filled second CTA
recolored with `--hero-cta2-bg` alone keeps a matching ring (`--hero-cta2-border` and
`--hero-accent` still win where set), and since issue 538 the same holds on HOVER —
`--hero-cta2-hover-bg` alone gives a matching hover ring, behind `--hero-cta2-hover-border`
and `--hero-accent-hover`. The cta's own second button works the same way
through `--cta-button2-bg` / `--cta-button2-border`, and its chain routes `--btn-bg` and
`--btn-border-color` in the primary's exact order, so a site-wide recolor moves both
buttons of a pair together.

**The `--btn-text` → `--color-bg` inversion coupling.** Button text defaults to the PAGE
BACKGROUND token, not to `--color-text`. Buttons invert on purpose: the accent fill is
dark relative to a light page, so the label uses the light page-background color to read
on top of it. This coupling is the ink rule's literal fallback
(`color: var(--cta-button-color, var(--btn-text, var(--color-bg)))`), so changing
`--color-bg` also moves button ink unless you pin `--btn-text`. When you set a custom
`--btn-bg`, check `--btn-text` still contrasts against it (≥ 4.5:1).

---

## Step 5 — Verify no raw hex remains in components.css

Run this command to check that no hex colors were accidentally introduced:

```bash
grep -P '#[0-9a-fA-F]{3,6}(?![0-9a-fA-F])' assets/css/components.css
```

The output should be empty. If it returns matches, replace each with the corresponding CSS variable from `base.css`.

---

## What NOT to touch

| File                     | Reason |
|--------------------------|--------|
| components/*.php         | Changing colors/fonts in PHP would bypass the token system |
| lib/*.php                | WP abstraction — not styling |
| templates/*.php          | Page layout — not styling |
| schema.json files        | Machine-readable contracts — not styling |
| functions.php            | Use `enqueue_font` apply instead of editing directly |

The entire visual output of the site flows through the design tokens and the apply/action model. Editing files directly is unnecessary for a retheme — use `update_design_token` for global tokens, `enqueue_font` for fonts, and `style_component` for per-instance visual overrides.

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

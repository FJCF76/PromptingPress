# Why the stylesheet is in a cascade layer

The theme ships a large v1 stylesheet and a v2 styling engine that emits CSS at render
time. Those two things compete for the same elements. This document explains how that
competition is settled, why it is settled that way, and the places where the rule
deliberately does not apply.

Read this before adding a rule to `assets/css/`, and before changing what `lib/udc.php`
emits.

## The problem

A v2 component declares ROLES, and an author styles a role by writing a `udc` map on the
band. The engine turns that into a scoped block:

```css
[data-pp-band="pp-3f9a1c2e"] .hero__cta--primary { background: #7c3aed; }
```

That selector is one attribute plus one class: specificity `[0,2,0]`.

The v1 stylesheet is still on the page, and parts of it are far heavier. The premium
button family reaches `[0,5,1]`:

```css
main .btn:not(.btn--outline):not(.btn--ghost):not(.btn--secondary) { ... }
```

So an author who styled a hero's CTA through the `button` preset got a write that
validated, stored, reported `ok: true` with the value applied, and then painted the
stylesheet's gradient anyway. Printing the authored block later in the document does not
help: source order only breaks ties between rules of EQUAL specificity.

That is invariant I35 — no silently cancelled input — failing on the surface the whole v2
contract exists to provide.

## The approach

Cascade layers, because they rank by LAYER first and specificity only within a layer.
Declared once, at the top of `assets/css/base.css`, which is the first stylesheet the
theme enqueues:

```css
@layer pp-reset, pp-zero, pp-v1;
```

Weakest to strongest:

| Layer | Holds | Why it sits here |
|---|---|---|
| `pp-reset` | `assets/css/base.css` | Tokens, reset, element defaults. Always was the floor. |
| `pp-zero` | the engine's BAND-ROOT defaults | Designed to LOSE to the design system — see below. |
| `pp-v1` | `components.css`, then `utilities.css` | The design system, in its existing source order. |
| *(unlayered)* | the engine's in-band ELEMENT defaults, then every authored band and chrome block | Unlayered beats every layer, at any specificity. |

The last row is the fix. A value an author actually wrote can no longer lose to a v1 rule
that happens to carry five classes, and nobody has to keep an enumeration of those rules
up to date for that to stay true.

The order is not a new design. It is the source order the theme already relied on,
restated as structure so that a reordered enqueue, a plugin, or a late-loading sheet
cannot invert it.

Layering `base.css`, `components.css` and `utilities.css` TOGETHER matters: relative order
inside a layer is unchanged, so nothing in v1 reshuffles against anything else in v1. Only
their relationship to what is outside changes.

## The exceptions, stated rather than implied

**1. `pp-zero` is meant to lose.** The engine emits a band's ROOT-level defaults (the
`_band` role) into `pp-zero`, strictly below the design system. That is deliberate: an
unauthored v2 band must still obey the shared adjacent-band rhythm (#430/#431), which aims
at the same element. Before layers, that ranking came from print order — the defaults ride
an inline block attached to `base.css`'s handle, and `components.css` loads after.

Layering the v1 sheet without this would have INVERTED it, because unlayered beats layered:
the zeroed defaults tier would have started beating the rhythm it exists to yield to.

Verified rather than assumed. With `base.css` sharing a layer with `components.css`, an
unauthored hero computed `padding-top: 0px` instead of its declared `--space-2xl`, because
the reset had started winning. That is why `pp-reset` is a layer of its own.

If a band-root default must beat a shared rule, the answer is not to re-rank the layer. It
is a `:not()` exclusion on the shared rule, which is what hero's `#577` opener rhythm uses.

**1b. Chrome pays for this, and it is the one place the regime bites a user.** Nav and
footer are on the engine but keep their resting appearance in `components.css`, because
retiring that block is a change with its own visual risk on every page of every site
(#994). So chrome ships EMPTY role defaults, and the only thing the engine adds is the
authored tier — which is unlayered.

Unlayered beats a layer in EVERY state, not only at rest. So a colour an author sets at
REST also outranks this stylesheet's `:hover` and current-page rules, which are in
`pp-v1`.

Each role loses its OWN hover: authoring `nav.logo`'s colour erases the logo's accent
hover, authoring `nav.toggle`'s erases the toggle's, and so on. `nav.link` loses two,
because the current-page accent lives on a DIFFERENT role (`link-current`) that the
author did not set. Measured in Chromium with `link`, `logo` and `toggle` authored at
rest and no state maps: link hover `rgb(49,87,244)` → `rgb(10,125,50)`, logo hover the
same, current-page link the same. The logo case is the worst, because its hover rule sets
colour and nothing else — there is no surviving underline, so hovering it does nothing
visible at all.

```
author sets link colour at REST        (unlayered, [0,2,3])   ← wins
components.css  .nav__menu ul li a:hover   (pp-v1, [0,0,4])   ← loses, despite :hover
components.css  li.current-menu-item > a   (pp-v1, [0,1,3])   ← loses
```

That is not the cascade misbehaving; it is the cost of holding chrome half-in. The fix is
#994 (move the resting values into role defaults so the states move with them), and until
then the AI-facing docs tell authors to pair every resting colour with its `:hover` and to
pair `link` with `link-current`. `#992` tracks the defect; a characterization test in
`tests/e2e/style-render.spec.ts` pins today's behaviour so the fix has a red-to-green.

The general lesson for anything else half-migrated: moving ONE tier of a component into
the unlayered engine silently promotes it above every remaining state rule for the same
property. Move a component's states and its resting values together, or not at all.

**2. An unlayered third party still wins.** Anything that is not in a layer outranks
everything that is, whatever its specificity. WordPress core injects:

```css
html :where([style*="border-width"]) { border-style: solid; }
```

That now beats the theme's issue-332 immunity baseline's `border-style: none`. The immunity
survives on the other half: core injects a STYLE and never a width, so `border-width: 0`
still wins, and `solid` at zero width paints nothing.

The baseline was NOT hoisted out of the layer to restore the old mechanism. Unlayered, it
would outrank every layered component rule that legitimately draws a border — 13 of them
today (`.cta--dark`, `.grid--dark`, `.site-footer`, ...) — and erase all of them.

Any future rule whose job is to defeat third-party CSS needs the same check: read the
computed value, do not assume the specificity argument still holds.

## Trade-offs

**What was given up.** Customizer "Additional CSS", a plugin stylesheet, and any late
enqueue are all unlayered, so they now beat the whole theme stylesheet at any specificity
rather than only at equal specificity. For site owners that is mostly a gain — their CSS
wins more reliably. For the theme it means a plugin can override more than it used to,
INCLUDING the ratified on-inverted and on-overlay accent routing: a plugin's
`a { color: red }` at (0,0,1) now defeats `.cta--inverted .cta__title-accent` at (0,2,0),
and those rules exist to hold a contrast ratio. If that becomes a real problem, those
specific rules are the candidates for hoisting out of the layer — not the sheet.

**`!important` reverses the whole thing.** For important declarations the cascade inverts
layer order and treats unlayered as WEAKEST. So `base.css`'s reduced-motion block, which
carries `!important`, went from "beatable by any later important" to the strongest author
declarations in the document. A site owner can no longer override it, and an `!important`
added to `components.css` would lose to one in `base.css`. Two practical rules: do not
reason about importants by source order, and prefer not to add new ones at all.

**One rule had to stay outside.** WordPress core ships `:where(figure){margin:0 0 1em}`
unlayered. The theme's `* { margin: 0 }` used to win that tie on source order; layered, it
lost at any specificity and every `<figure>` in authored body HTML gained 17.04px of
bottom margin. `base.css` now carries one unlayered `figure { margin: 0 }` above the layer
block. That is the general shape: a rule whose job is to beat unlayered third-party CSS
cannot live in a layer, and the way you find out is by reading the computed value.

**The failure mode if `@layer` is unsupported.** An unknown at-rule is dropped WITH ITS
BLOCK, so a browser without `@layer` loses the entire stylesheet, not merely the ranking.
Worse than unstyled: the engine's authored blocks are unlayered and still apply, so such a
browser would paint authored band fills and role typography over raw unstyled HTML.

The exposure is bounded by something the theme already required. `@layer` shipped in
Chrome 99, Firefox 97 and Safari 15.4, all in March 2022. `components.css` uses
`color-mix()` unconditionally, which needs Chrome 111 and Safari 16.2 — a YEAR later. So
every browser that would drop the layer already fails to render the theme's colours
correctly, and layering does not move the real floor. If `color-mix()` ever leaves the
stylesheet, `@layer` becomes the binding constraint and this paragraph has to be redone.

**What `!important` would have cost.** BUILD-SPEC §3.4 forbids it, and the reason holds:
`!important` wins against the site owner too, so every authored value would become
un-overridable by the person whose site it is.

## Alternatives considered

**Per-rule `:where()`.** Tried first, on the four premium button rules hero collides with.
`:where()` contributes zero specificity, so the rule keeps matching and stops competing.

It was measured, and it was withdrawn. Zeroing those rules dropped them below the base
`.btn` rule at `[0,1,0]` as well, which nothing intended. Same button, same page, only
`components.css` swapped:

| property | before | after `:where()` |
|---|---|---|
| `border-width` | 1px | 2px |
| `box-shadow` at rest | inset bevel + drop | `none` |
| `transition-property` | `box-shadow, color, transform` | five-property list |

Every composed primary button on the components not yet rebuilt lost its ring, its bevel
and the #540 motion narrowing. CI could not see it: the CSS lint matches on selector
SHAPE, so a `:where()`-wrapped compound reads identically.

A layer moves the whole sheet at once and cannot single out a rule by accident. The lesson
generalizes: `:where()` is safe on a rule with nothing class-based underneath it (Sprint 0
used it on the adjacent-sibling catch-all, which has none), and unsafe on a rule that sits
above a primitive.

**Raising the band block's specificity.** Rejected: the block's shape is the contract
(§3.4), and every increase is an arms race with the next stylesheet rule.

## Related

- `assets/css/base.css` — the `@layer` statement and the full ordering note.
- `lib/udc.php` — the engine header explains what is emitted into which layer; see
  `pp_udc_component_defaults_css()` and `_pp_udc_render_blocks()`'s `$root_layer`.
- `tests/e2e/style-render.spec.ts` — `#986/I35 an authored hero CTA outranks the v1 premium
  button rules` and `#986 the v1 premium button treatment survives on a legacy cta` are the
  rendered guards in both directions. `#992 CHARACTERIZATION: an authored base colour erases
  the hover and current-page accents` is the chrome half, and is DEBT: it pins a defect on
  purpose and is deleted or inverted when #994 lands.
- Issues #992 (the chrome state-erasure defect) and #994 (retiring the chrome CSS block for
  nav and footer together, which fixes it).
- `docs/v2/BUILD-SPEC-sprint0.md` §3.4 — the cascade contract.
- `docs/explanation-validation-scope.md` — the sibling design note on what a write is
  allowed to refuse on behalf of, and the two schema keys every component rebuild declares.
- Issue #989 — the remaining v1 rules, now a verification list rather than a surgery list.

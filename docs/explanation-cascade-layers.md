# Why the stylesheet is in a cascade layer

The theme ships a large v1 stylesheet and a v2 styling engine that emits CSS at render
time. Those two things compete for the same elements. This document explains how that
competition is settled, why it is settled that way, and the two places where the rule
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

## The two exceptions, stated rather than implied

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
wins more reliably. For the theme it means a plugin can override more than it used to.

**The failure mode if `@layer` is unsupported.** An unknown at-rule is dropped WITH ITS
BLOCK, so a browser without `@layer` loses the entire stylesheet, not merely the ranking.
`@layer` has been in every evergreen browser since 2022, which is the same support floor
the theme already assumes for `color-mix()` and `:where()` — both of which the stylesheet
uses unconditionally and neither of which degrades gracefully either.

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
  rendered guards in both directions.
- `docs/v2/BUILD-SPEC-sprint0.md` §3.4 — the cascade contract.
- Issue #989 — the remaining v1 rules, now a verification list rather than a surgery list.

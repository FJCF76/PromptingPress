# The Layout group — contract (v2 Sprint 2, T9)

**Status:** ruled (7A, 2026-09-20) and implementation-ready. Issue **#1084**.
**Companion documents:** `docs/v2/BUILD-SPEC-sprint0.md` (§2 the structural boundary, §3.3 the value
grammar), `docs/v2/LAYER-2-CONTRACT.md` (the `_css` valve this group takes properties from),
`docs/v2/INVARIANTS.md`.

The approved design doc's coverage table lists **Layout** — *"declared per-component flex/grid
parameters (columns, gap, orientation, wrap) — grammar shared, exposure declared"* — and
**Sizing → alignment** as **1st cut**. Neither was built. #1079's evidence sweep called it
*"the largest recorded gap in the program"*.

---

## §1 — The demand, and the ten sites that route around it

| | |
|---|---|
| #658 | vertical alignment for `section` text-panel layouts (`status:ready`) |
| #905 | `grid`'s `columns` silently ignored on `layout="steps"`; a four-across process band is inexpressible |
| #588 | one canonical mechanism for media sizing/cropping — its **ratio half**: `section`'s hard `1fr 1fr` against `hero`'s enumerated `split_ratio` (`status:needs-design`; only the layout-grammar half is touched here) |

Ten shipped sites assert in writing that *"the UDC taxonomy carries no layout group"*:
`components/hero/hero.php:185`, `components/section/section.php:234`, `components/cta/cta.php:151`,
`components/section/schema.json:3`, `:71`, `:447`, `components/hero/README.md:51`,
`components/section/README.md:72`, `components/cta/README.md:57`, `assets/css/components.css:988`.
Two test comments (`tests/SchemaValidationTest.php:4420`, `tests/SectionInlineItemsTest.php:435`)
repeat it. `CHANGELOG.md:906` records it historically and is **not** rewritten — a changelog is a
record of what was true then.

The live interim path this retires: `docs/howto-migrate-a-section-band-to-v2.md:214`, headed
*"Layout values: `_css` now, a group later"*, promising *"when the Layout group lands they become
named, type-checked parameters."*

---

## §2 — THE BOUNDARY: overlay, not migration (ruled Q1)

### §2.1 What was measured

Layout declarations inside the ten rebuilt components' own CSS blocks, counted from the stylesheet:

| property | declarations | of which variant/attribute-scoped |
|---|---|---|
| `align-items` | 33 | 12 |
| `justify-content` | 20 | 7 |
| `flex-direction` | 17 | 3 |
| `flex-wrap` | 9 | 0 |
| `align-self` | 9 | 6 |
| `grid-template-columns` | 6 | 5 |

The variant-scoped ones are `.hero--split`, `.hero--cover`, `.hero--centered`,
`.hero--cover[data-pp-vertical-align="bottom"]`, `.hero--split[data-pp-split-ratio="60-40"]`,
`.cta--inline`, `.cta--full-width`, `.section--text-panel`, `.section__inline-items--center`,
`.testimonials--stack`, `.logos__item--labeled`.

### §2.2 Why migration is refused

A role's `defaults` carry two dimensions — breakpoint and state — and **no variant dimension**.
An element-level default emits **unlayered** (`lib/udc.php:4613-4640`, the #986 ruling-D5 note), so a
migrated default does not merely replace its stylesheet rule: it **outranks the variant rules that
remain**. Migrating `.cta__inner { flex-direction: column }` into a `cta.inner` default silently
kills `.cta--inline .cta__inner { flex-direction: row }` at ≥768px, because the unlayered default
beats the `pp-v1` rule at any specificity. Deleting the variant rules instead deletes the
capability, which is the #901 class the UDC exists to end.

That is ruling D5's collision arriving from the other side: D5 taught that an emitted value can
outrank machinery it was never meant to touch.

### §2.3 The rule, and the one-home amendment

**The six dual-home properties stay in css-lint's `STRUCTURAL` set. The group emits authored values
only. No role may declare a default for any of them.**

The six are the `layout` five (`grid-template-columns`, `flex-direction`, `flex-wrap`,
`justify-content`, `align-items`) **plus `align-self`**, which `sizing` owns. Which group carries a
property does not change its position: a `sizing.align-self` default would emit unlayered and outrank
`.hero--centered .hero__eyebrow { align-self: center }` exactly as a layout default would, so the
no-default rule is keyed on the property, not on the group.

The one-home rule (*"the property has exactly one home at every commit — never zero, and never
two"*, recorded on `object-position` and `aspect-ratio`) is amended **once, narrowly**, and the
amendment is its own argument:

> The rule exists to guarantee **reachability** — *"a value in CSS that no role owns is a value no
> author can reach and no envelope can report on"*. A structural rule for a property **the registry
> owns** is reachable by definition: an authored band block is unlayered, so an author's value beats
> it at any specificity, in any variant, at any breakpoint. The harm the rule names cannot occur, so
> the property keeps its structural home and gains an authored overlay above it.

The amendment is written into `tests/js/css-lint.test.js` as that argument, beside the properties it
covers, so a later reader meets the reasoning rather than an exception.

**The exception carries its own condition, so it cannot be copied for convenience** (hardened after
the outside-voice pass, which read the first draft as *"one home except when convenient"*). A
property may hold both homes only when **both** hold:

1. the **registry owns it** — so every stylesheet occurrence is overridable by an authored value; and
2. its group declares **no defaults** for it, which makes the stylesheet the only possible home for
   the **unauthored** behaviour. Deleting a rule there would then delete behaviour rather than move
   it.

**Condition 2 is the one that does the work, and an earlier draft of this document got it wrong.**
That draft said condition 2 was "every occurrence is variant- or tier-conditioned". The file
disproves it: `.hero__cta-group { flex-wrap: wrap }` and `.cta__buttons { justify-content:
flex-start }` are unconditioned base values on bare role selectors, and they stay in the stylesheet
precisely **because** no default may hold them. The variant-scoped declarations are why *defaults are
banned* (a default would outrank them); the ban is then why the *stylesheet keeps everything*,
conditioned or not.

Condition 2 is asserted rather than described — by the registry-derived test in
`tests/UdcLayoutGroupTest.php`, which walks every component's role defaults for these five
**properties** (not parameter names: `typography.align` emits `text-align` and legitimately defaults
on `logos.label`, which is how the first version of that guard was found to be asking the wrong
question). The day the Layout group takes a default, its properties stop satisfying the condition and
belong in `ALWAYS_DESIGN` like everything else.

**The cascade claim is narrowed to what this repo can pin.** "Authored beats structural at any
specificity" holds *here* because (a) the six properties carry no `!important` anywhere in
`assets/css/` (verified: the only `!important` in the theme is the `prefers-reduced-motion` block at
base.css:322-325), and (b) a v2 component emits no inline `style` attribute at all — that was the
rebuild. Both facts are pinned by existing tests rather than assumed; the claim is not the general
CSS claim that layers always win.

**What is NOT amended:** a layout property carrying a value on a **role default** is still an
offence, and `gap`/`row-gap`/`column-gap` stay in `ALWAYS_DESIGN` — the `spacing` group owns them
and a stylesheet gap is still split authority.

### §2.4 The three enforcement requirements (ruled with Q1)

1. **The amendment is argued, not applied** — §2.3's paragraph lands in the lint's own comment.
2. **No-layout-defaults is registry-derived.** The schema suite walks `pp_udc_groups()['layout']`
   against every component's `roles[*].defaults`; it fails the moment any component writes a layout
   default, rather than when someone remembers to check. Registry-derived so a seventh layout param
   added tomorrow is covered for free.
3. **The overlay is pinned in both directions.** (a) an authored layout value beats the variant rule
   — the capability; (b) with no authored value the variant rule paints untouched — the
   D5-unauthored-case lesson. Both as computed reads on a variant-carrying component.

---

## §3 — The registry (ruled Q3)

```
'layout' => ['params' => [
    'columns'     => grid-template-columns   (type: track-list, + display:grid companion)
    'orientation' => flex-direction          (type: flex-direction)
    'wrap'        => flex-wrap               (type: flex-wrap)
    'justify'     => justify-content         (type: justify-content)
    'align'       => align-items             (type: box-align)
]]
'sizing' gains:
    'align-self'  => align-self              (type: box-align)
```

Param names follow the DESIGN idea, not the property string — the registry's own rule
(`border.width`, not `border-width`). The property text is looked up from this table and never taken
from author input.

### §3.1 `columns` and its companion (ruled Q2)

`layout.columns` emits `grid-template-columns` **with a `display: grid` companion**, on the shipped
`PP_UDC_BACKGROUND_IMAGE_COMPANIONS` precedent (`lib/udc.php:461`, applied at 4034). The companion
is added per (state, breakpoint) bucket, **only when the author has not set `display` themselves
through `_css`**, and carries `source: 'engine-companion'` exactly as the background trio does.

**Why the companion is forced.** `.section__grid` is `display: flex` at base and `display: grid` only
from 768px (`components.css:923-935`). Without the companion an authored `columns` paints nothing
below 768px — and PHP cannot read the stylesheet, so `_pp_udc_place()`'s drop ledger **cannot report
it**. A value that validates green, stores, and paints nothing on no channel is the I19/I35 class
#1048 is already open against; building one deliberately is not an option. With the companion, "N
columns" is true wherever the author wrote it.

**THE COMPANION RIDES THE PARAMETER, NOT THE PROPERTY** — corrected during the pre-landing review,
which found the roster's visibility-switch clause gated nothing. `_css` reaches every role whatever
the roster says, and the companion keyed on `grid-template-columns` appearing in the bucket, so
`{"menu": {"_css": {"grid-template-columns": "2"}}}` on nav emitted an unlayered `display: grid` on
`.nav__menu` and pinned an open mobile menu open — the exact affordance §5 exists to protect,
reachable through the next door along. The companion now fires only for the `layout.columns`
parameter. A raw track list emits a track list and nothing else, which is the raw valve's own posture
(it checks a value's safety, not its meaning), and the claimed property still keeps its parameter's
GRAMMAR through `_css` — a raw `3` is still the count `repeat(3, minmax(0, 1fr))`, per the Layer-2
typed-property rule. When a band carries both, the raw value wins the property, the envelope
discloses it with `udc_css_overrides_group_value`, and the companion the group value earned survives.

**The suppression check is source-agnostic.** The companion is skipped when `display` is already in
that bucket's declaration map **whatever put it there** — `_css`, a future param, stored data — which
is the same `isset()` shape the background trio uses. No group emits `display` today, so `_css` is
the only live source; the check does not depend on that staying true.

**`columns` switches a mechanism, and the docs say so rather than implying a pure retune.** On a box
the stylesheet makes a flex column, an authored `columns` makes it a grid: `flex-direction`,
`flex-wrap` and flex child sizing stop applying on that box, at the tiers the author wrote. That is
what "give me three columns" means, and it only ever happens on an explicit write — but it is stated
in the param's schema description, in the how-to, and in the AI-facing context, because an author who
also set `orientation` on the same role deserves to know which one the browser will honour.

**Which params bite depends on the box's display mode, which CSS owns and the engine does not.** On a
grid container `orientation`/`wrap` do nothing; on a flex container `columns` brings its own grid
(above). The roster exposes `layout` on containers of either kind rather than splitting the group
into `layout.flex` / `layout.grid`, for a measured reason: `section`'s `columns` role is a **flex
column below 768px and a grid above it** (components.css:923-935), so a mode-split taxonomy would
have to expose both groups on that one role anyway and would still be tier-dependent. The honest
version is one group plus a documented mode table.

**The responsive rule is the engine's existing one, stated rather than invented:** `d` has
`media => null` (`lib/udc.php:200-210`), so a `d` value applies at every width. `{"d": 4, "p": 1}` is
how you stack on phones. The schema description and the AI-facing docs say so; the engine does not
guess a mobile fallback, because guessing would be the coercion I34 forbids.

### §3.2 The Layer-2 interaction rule (first instance, recorded here)

**CLAIMING A PROPERTY TYPES ITS `_css` WRITES.** `pp_udc_css_param()` (udc.php:928) routes a `_css`
property the registry owns to that param's grammar. So every future group addition must check what
`_css` accepts for that property **today** before choosing its grammar, or it silently narrows a
shipped path. This group is the first instance and it changed the design: an integer-only `columns`
would have started refusing `_css: {"grid-template-columns": "repeat(auto-fit, minmax(20rem, 1fr))"}`,
which works today and is exactly what #905's brand specifies.

**The consequence for the five keyword params is bigger than "fails loudly", and the outside-voice
pass was right to push on it.** A stored `_css` value that the new grammar refuses does not fail only
its own edit: `update_composition` validates the **whole** composition, and the post-write envelope,
`wp pp check page` and `restore_composition` all re-validate **stored** compositions — so one
now-invalid declaration in one band makes an unrelated edit to another band fail. That is exactly the
class #1007 is about ("one retired prop locks the whole page for editing").

The answer is therefore not a disclosure, it is a **grammar wide enough that nothing a browser
accepts becomes a lockout**: the keyword sets in §4 take the full CSS Box Alignment vocabulary,
including the `safe` / `unsafe` overflow-alignment prefixes, and `align-self`'s `auto`. This is the
`font-weight` lesson applied before it bites rather than after (#988: a closed ladder refused `650`,
the theme's own value, and the refusal reached through a token reference) — *refusing a value CSS
accepts is this engine inventing a constraint*. Anything still refused (`inherit`, a typo) was
inert-or-invalid CSS before the claim too.

The both-in-place case keeps its shipped disclosure: a `_css` declaration over a group parameter at
the same property still reports `udc_css_overrides_group_value`.

---

## §4 — Grammars

Five new typed keyword sets and one track-list grammar, all in the **one owner** (`lib/apply.php`):
each is a `_pp_validate_*()` beside its siblings at :1344 plus one `case` in the single dispatcher at
:1700 — the same move `font-style`, `text-wrap` and `border-style` made in Sprint 0. No surface adds
a second validator.

| type | accepts |
|---|---|
| `flex-direction` | `row`, `row-reverse`, `column`, `column-reverse` |
| `flex-wrap` | `nowrap`, `wrap`, `wrap-reverse` |
| `justify-content` | `flex-start`, `flex-end`, `start`, `end`, `left`, `right`, `center`, `space-between`, `space-around`, `space-evenly`, `normal`, `stretch`, and `safe`/`unsafe` before any positional keyword. **No baseline values** — not valid on this property |
| `box-align` (align-items / align-self) | `flex-start`, `flex-end`, `start`, `end`, `self-start`, `self-end`, `center`, `baseline`, `first baseline`, `last baseline`, `stretch`, `normal`, `safe`/`unsafe` + positional; `align-self` additionally `auto`. **No `space-*`, no `left`/`right`** — those are justify-content's; `self-start`/`self-end` are valid on BOTH, the container property included |
| `track-list` | a positive integer 1–12, **or** a bounded track list |

**Bounds the grammar enforces, corrected at the pre-landing performance pass.** `minmax()` does
**not** nest — its two sides take breadths (a length/percentage, a sizing keyword, and an `<n>fr` on
the maximum only), which is what CSS Grid says and what stops the validator recursing. A nested one
used to validate in O(len²) with no depth bound, be ACCEPTED and stored by an ordinary write, and be
re-validated on **every front-end request**: measured at 765 ms of page CSS for a 2.2 KB value on a
50-band page, and no result at all in 120 s for 220 KB. A whole track list is also bounded at 400
bytes before anything walks it, and the 12-track bound is enforced on what a list **resolves** to
rather than on how it was spelled (`repeat(12, 1fr 1fr)` is 24 tracks, and is refused).

**`track-list`, precisely.** An integer 1–12 is synthesised to `repeat(N, minmax(0, 1fr))` — the
repo's own grid lesson (a track needs `minmax(0,…)` or its content cannot shrink, the #1043/#1067
class). An explicit list is a sequence of 1–12 tracks, each one of: a length with the shared unit
grammar, a percentage, an `<n>fr`, `auto`, `min-content`, `max-content`, `minmax(<track>, <track>)`,
or one `repeat(<positive integer 1-12 | auto-fit | auto-fill>, <track>+)`. `repeat()` does not nest
(CSS forbids it too), takes exactly one comma (its count from its tracks — the tracks themselves are
space-separated, so `repeat(2, 1fr, 2fr)` is invalid CSS and is refused), and an `auto-fit` /
`auto-fill` repeat requires FIXED track sizes, because a browser cannot count repetitions of a track
whose size depends on how many times it repeated (`repeat(auto-fit, 1fr)` is invalid;
`repeat(auto-fit, minmax(20rem, 1fr))` is the valid shape, and the one #905's brand writes).
Everything else is refused with a message naming the accepted forms.

Red-proofs required: nested `repeat()`, unbalanced `minmax(`, negative `fr`, `0fr` denominators,
`repeat(0, …)`, a 13-track list, `calc()` inside a track (not accepted in this cut — stated), and
the injection classes the shared gate owns (`url(`, `image-set(`, `;`, `}`, `/*`, `@import`).

---

## §5 — Exposure (ruled Q4: S2, by box fact)

A role declares `layout` iff **both** clauses hold:

1. **it is a container** — its shipped structural CSS declares `display: flex|inline-flex|grid|
   inline-grid` on exactly that selector (base tier or inside a media query); and
2. **its `display` is not a visibility switch** — no rule targeting that selector, or an
   attribute-qualified form of it, declares `display: none`, and the selector never appears
   attribute-qualified in a display rule.

**Clause 2 exists because of a verified hazard, not for symmetry.** `nav` uses `display` as
behaviour: `.nav__toggle { display: flex }` with `display: none` from 768px, and
`.nav__menu[hidden] { display: flex }` at 768px against the JS that adds/removes `hidden` on mobile.
The `hidden` attribute is honoured only by the **UA** stylesheet, which any author declaration beats,
and base.css carries no `[hidden]` rule of its own (verified: the theme's only `!important` block is
`prefers-reduced-motion`, base.css:322-325). So an authored `columns` on `nav.menu` would emit an
unlayered `display: grid` and **pin an open mobile menu open** — a styling write breaking a
keyboard/screen-reader affordance. `nav.menu` and `nav.toggle` are therefore out of the roster, by a
derived rule rather than a hand-kept exception list.

**The derivation is fail-closed, because a selector parse that quietly degrades would pass
vacuously** (the outside-voice pass flagged exactly this: comma lists, descendant selectors like
`.nav__menu ul`, media nesting, attribute qualifiers and `:where()` all have to be handled). So the
test asserts the derived set against a **recorded roster constant**, the same anti-vacuity shape
`STRUCTURAL_RULE_COUNT` uses: derived-vs-recorded must match exactly, and schema-vs-derived must
match in **both** directions — a container role missing `layout` fails, a non-container role
declaring it fails, and a parse that stops finding selectors fails loudest of all.

`sizing.align-self` needs no roster: `sizing` is already declared on every role that has geometry,
and `align-self` is meaningful on any child of a flex or grid container.

The expected roster at this commit (derived, then recorded): hero `inner`, `content`, `cta-group`,
`proof`, `surface`; section `columns`, `inline-items`, `panel-row`; cta `inner`, `buttons`; faq
`list`, `question`; testimonials `list`, `card`, `attribution`; stats `list`, `item`; logos `list`,
`item`, `item-labeled`; nav `container`, `logo`, `menu-list`; footer `inner`, `columns`, `brand`,
`social`, `social-link`, `nav-list`, `bottom-row`. **Out by clause 2:** nav `menu`, nav `toggle`.
The exact set is confirmed against the parser during implementation; a role that turns out to differ
moves in the schema and the recorded roster together, in the same commit.

---

## §6 — Exclusions (ruled Q3, verbatim)

- **`gap` / `row-gap` / `column-gap`** — already shipped in `spacing` (udc.php:524). The coverage
  table's "gap" is done; a second home would violate one-home. *This is the rule working, not a gap.*
- **`display`** — structural; it appears only as `columns`' companion, never as a param.
- **`grid-template-rows` / `grid-template-areas` / `grid-auto-flow`** — they *select* the machinery
  rather than retune it; no cited demand.
- **`grid-column` / `grid-row` / `order`** — item grain, excluded pending Addendum B (#1024), the
  same boundary Layer 2 took.
- **`flex` / `flex-grow` / `flex-shrink` / `flex-basis`** — child sizing machinery; the `sizing`
  group owns the width family.
- **`justify-items` / `justify-self` / `align-content`** — no cited demand, and mostly inert on the
  shipped boxes.
- **`position` / `z-index` / `overflow`** — 2nd cut in the approved coverage table.

---

## §7 — The props stay; the comments are rewritten (ruled Q3b)

No prop retires here. `section.body_items_align` genuinely cannot: it switches the `::before` →
`::after` separator mechanism, not just a `justify-content` value (schema.json:71). `hero.layout`,
`hero.split_ratio`, `hero.vertical_align`, `section.layout` and `cta.layout` select **mechanism
bundles** — a variant class carrying several coordinated rules — which a flat role address cannot
express.

Every route-around site is rewritten to the true sentence: *the prop selects a mechanism bundle; the
group retunes the values inside it, and an authored value outranks the bundle's own rule.* The
prop-retirement question (`hero.vertical_align` / `split_ratio` are now expressible as
`inner.sizing.align-self` / `inner.layout.align` and `inner.layout.columns: "3fr 2fr"`) is filed as a
follow-up with the migration-with-its-own-review framing.

---

## §8 — Documentation and the AI surface

- `docs/howto-migrate-a-section-band-to-v2.md:214` — the *"`_css` now, a group later"* section is
  replaced by the group's own how-to, including the `_css` → group migration and the responsive rule.
- `docs/tutorial-style-a-band-on-the-design-contract.md` — the group joins the vocabulary.
- `docs/v2/LAYER-2-CONTRACT.md` — §3.2's interaction rule recorded; the six properties leave the
  "unchecked" examples.
- `lib/ai-context.php` — `GROUPS AND PARAMETERS` is derived from the registry and updates itself; the
  surrounding prose gains the five keyword grammars, the track-list grammar, the companion, and the
  `{"d":4,"p":1}` responsive recipe.
- Component READMEs (hero, section, cta + every component gaining exposure) — the roster, per #1045.

---

## §9 — Test plan

1. **Registry/grammar unit tests** — every accepted keyword, the track-list accepted set, and the
   red-proofs of §4, through the shared validator (never a second one).
2. **Emit tests** on the `RoleDefaultsEmitTest` pattern — the property emitted, the companion
   present, the companion absent when `display` is authored, per-breakpoint placement, and the
   declaration's position after the sort.
3. **The no-defaults schema test** (§2.4.2), registry-derived.
4. **The exposure roster test** (§5), CSS-derived, both directions.
5. **The css-lint amendment** — the six properties still structural; a layout value on a role default
   is still an offence; the existing `text-align` carve-out is untouched.
6. **Authoring-path test** (rule 14.1) — the group written through the real `update_component` /
   `update_composition` path, not raw meta.
7. **`_css` interaction** — a claimed property is typed now; the both-in-place disclosure still
   fires.
8. **Rendered evidence** (rule 14.2) at 375/768/1280 with computed reads, on the demand cases:
   section columns + panel alignment, hero split, cta buttons, including stressed copy.
9. **Vacuity probe on every changed test** — assert the new assertion fails when the mechanism is
   removed.

---

## §9.1 — Prototype evidence (rule 14.3, run before the plan was fixed)

A recorded design that names a CSS mechanism is a hypothesis until a browser answers. Chromium via
Playwright, one page holding a layered `pp-v1` block and unlayered band blocks, read at three
viewports:

```
                         375px              768px                1280px
authored, var() mint     grid               grid                 grid
  repeat(var(--cols))    125 125 125        3 tracks             426.7 426.7 426.7   ✓ mints work
authored, literal        grid               grid 192x4           grid 320x4          ✓
  repeat(4, minmax(0,1fr))
authored flex-direction  column             column               column              ✓ beats
  vs .cta--inline row    (layered rule says row at >=768)                              the variant
UNAUTHORED control       flex / none        grid 384 384         grid 640 640        ✓ machinery
                                                                                       untouched
```

Three things this settles rather than assumes: a **minted** responsive value (`{"d":3,"p":1}` becomes
`var(--pp-…)`) resolves correctly **inside `repeat()`**, so the count→track synthesis must decide
from the LITERAL and emit the CSS — the exact rule `_pp_udc_compose_background_layers()` already
records for the overlay fold; the overlay beats a variant rule; and with nothing authored the variant
rule paints exactly as today. §2.4's requirement 3 is testable because the prototype already did it.

## §9.2 — Coverage map

```
CODE PATHS                                                  USER / AUTHOR FLOWS
[+] lib/udc.php pp_udc_groups()                             [+] Author writes layout.columns
  └── layout group + sizing.align-self                        ├── [GAP] int -> repeat(N,minmax)  unit+emit
      ├── [GAP] property lookup per param      unit           ├── [GAP] track list passthrough   unit+emit
      └── [GAP] rank/order with 6 new props    unit           ├── [GAP] {"d":4,"p":1} per tier   emit
[+] lib/udc.php _pp_udc_grid_columns_companion()              └── [GAP] [->E2E] renders 4-up     browser
  ├── [GAP] adds display:grid when absent     unit+emit     [+] Author writes align/justify on a container
  ├── [GAP] skips when display already set    unit            ├── [GAP] beats the variant rule   [->E2E]
  └── [GAP] per (state,breakpoint) bucket     emit            └── [GAP] #658 panel alignment     [->E2E]
[+] lib/apply.php 5 keyword validators + track-list         [+] Author writes nothing (the control)
  ├── [GAP] accepted sets incl. safe/unsafe   unit            └── [GAP] variants paint untouched [->E2E]
  └── [GAP] red-proofs: nested repeat,                      [+] Author had a stored _css layout value
            unbalanced minmax, -1fr, 0fr,                     ├── [GAP] now typed, still accepted unit
            repeat(0), 13 tracks, url(,                       └── [GAP] both-in-place discloses   unit
            image-set(, ;, }, /*, @import     unit          [+] Operator re-validates a stored page
[+] schemas: layout on N roles, align-self                    └── [GAP] no lockout from §3.2      unit
  ├── [GAP] roster derived == recorded        lint
  ├── [GAP] schema == derived, both ways      lint
  └── [GAP] NO role declares a layout default schema (registry-derived)
[+] tests/js/css-lint.test.js amendment
  ├── [GAP] six props still STRUCTURAL        lint
  ├── [GAP] dual-home condition asserted      lint
  └── [GAP] text-align carve-out untouched    lint (regression)

COVERAGE BEFORE: 0/24 paths.   TARGET: 24/24, with a vacuity probe on every changed test.
```

**REGRESSION RULE — mandatory, no question asked:** the `text-align` layout-modifier carve-out and
the `STRUCTURAL_RULE_COUNT` floor both sit in the file this change amends, and both are existing
guarantees an amendment could silently widen. Regression pins for each.

## §9.3 — Failure modes

| Failure | Test | Error handling | Visible or silent |
|---|---|---|---|
| authored `columns` paints nothing below 768 | emit + browser | the `display:grid` companion | would have been **silent** — the reason the companion exists |
| companion overrides a visibility `display` (nav) | roster lint clause 2 | role excluded from the roster | would have been **silent and an a11y break** |
| a stored `_css` layout value stops validating and locks the page | unit (§3.2) | grammar wide enough that CSS-valid values pass | loud (refusal) but on the **wrong** band — hence the grammar width |
| a minted `var()` reaches `repeat()` and drops the declaration | emit + prototype | decide-from-literal, emit-the-css | silent: the browser drops an invalid track list |
| a layout **default** is added later and beats a variant rule | registry-derived schema test | test fails at write time | would have been silent until a variant page was opened |
| roster parser degrades and asserts on an empty set | derived == recorded | fail-closed | silent (the #1066 lesson: a guard whose assertion count does not move) |

No critical gaps: every row has a test and a handler.

## §9.4 — What already exists (and is reused, not rebuilt)

- `pp_udc_groups()` — the registry shape; the group is **data**, not a new mechanism.
- `_pp_validate_token_value()`'s one dispatcher and the typed-keyword-set pattern at `lib/apply.php:1344`
  — five new validators join their siblings; **no second validator**.
- `PP_UDC_BACKGROUND_IMAGE_COMPANIONS` + `_pp_udc_background_image_companions()` — the companion
  mechanism, already shipped and already ordered correctly against the sort.
- `_pp_udc_place()`'s `css` / `literal` split and drop ledger — the synthesis decides from the
  literal; every drop already reports.
- The role `groups` list — exposure is declared data, the #1006 role-absence pattern.
- `STRUCTURAL_RULE_COUNT` / `INTENTIONALLY_RULE_FREE` — the recorded-roster anti-vacuity shape the
  roster test copies.
- `udc_css_overrides_group_value` — the both-in-place disclosure; already built for this case.
- `RoleDefaultsEmitTest` — the emit-test pattern. **Not copy-pasted a fourth time:** #1052 records
  that the three existing harnesses hardcode the breakpoints the engine owns, so the new tests derive
  tiers from `pp_udc_breakpoints()`.

## §9.5 — NOT in scope (considered, deferred, with the reason)

- **`grid`'s rebuild and #905's own fix** — grid is not a v2 component yet (#1024, item-grain ruling).
  This group is what #905 will be fixed WITH, not the fix.
- **#588's media-fit half** — `status:needs-design`; only its ratio/layout half is reachable here.
- **Prop retirement** (`hero.vertical_align`, `hero.split_ratio`) — a migration with its own review
  (§7); filed as a follow-up.
- **Per-param exposure** (`groups: {"layout": ["align-self"]}`) — the outside voice's deeper ask. It
  changes a schema shape every component and several engine call sites read; a real axis, not a task
  rider. Filed as a follow-up.
- **`layout.flex` / `layout.grid` split** — measured against `section.columns`, which is both at
  different tiers (§3.1); it would not remove mode-dependence.
- **2nd-cut groups** (Filters & Blend, Transform, Position) — the approved table puts them later.
- **Item-grain layout** (`grid-column`, `order`) — Addendum B (#1024).
- **`gap`** — already shipped in `spacing`; this is the one-home rule working.

## §9.6 — Implementation tasks

- [ ] **T1 (P1)** — `lib/apply.php` — five typed keyword sets + the `track-list` grammar, with the
      red-proofs. Verify: `./vendor/bin/phpunit --filter Grammar`.
- [ ] **T2 (P1)** — `lib/udc.php` — the `layout` group, `sizing.align-self`, the
      `display:grid` companion, the count→track synthesis deciding from the literal.
- [ ] **T3 (P1)** — schemas — `layout` on the derived roster; `align-self` reachable per §10 Q1.
- [ ] **T4 (P1)** — `tests/js/css-lint.test.js` — the argued amendment + its condition + the two
      regression pins.
- [ ] **T5 (P1)** — the registry-derived no-defaults test and the fail-closed roster test.
- [ ] **T6 (P1)** — emit tests deriving tiers from the engine (not a fourth copy-paste, #1052).
- [ ] **T7 (P1)** — authoring-path test through `update_composition` (rule 14.1).
- [ ] **T8 (P2)** — the ten route-around rewrites (§7) + component READMEs (#1045).
- [ ] **T9 (P2)** — docs + `lib/ai-context.php` prose (§8), including the mode table and the
      `{"d":4,"p":1}` recipe.
- [ ] **T10 (P2)** — browser evidence at 375/768/1280, stressed, screenshots persisted.
- [ ] **T11 (P3)** — the two approved T8 riders (#1079): dead keys, locator ternary.

**Parallelization:** sequential. Every task after T1 depends on the registry, and T4/T5/T6 all read
the schemas T3 writes. One lane.

## §10 — The two questions review raised, and how they were ruled

Both were put to the coordinator before any schema was written; both were ruled as recommended.

**Q1 — the grain of `align-self`: RULED `sizing`.** The 7A had tabled it inside `layout`. `groups` is
a **group-grain** list, so a role that declares `layout` gets all of its params — and on a role whose
box is a *child* of a container but not a container itself (`section.panel`, `hero.content`), four of
the five would paint nothing at any value: the #1006/#1048 inert class this group must not
manufacture. The approved coverage table's own wording puts alignment under **Sizing** (*"Sizing |
width, max/min-width, height, min-height, alignment"*), so this is fidelity to the design rather than
a deviation from it. Per-param exposure (`groups: {"layout": ["align-self"]}`) stays filed as its own
axis (§9.5).

**Q2 — the visibility-switch clause: RULED IN**, with three requirements, all met:
(1) it derives mechanically from the stylesheet, with no hand-kept list;
(2) the hazard itself is pinned — `tests/js/css-lint.test.js` asserts `nav.menu` and `nav.toggle` do
not expose the group AND asserts the two mechanics the exclusion depends on (`.nav__menu[hidden]`
exists; base.css carries no `[hidden]` rule that would survive an authored value), with the
`hidden`-vs-authored-display reasoning written at the clause;
(3) the roster fails closed — a future role that matches the clause and declares the group anyway
fails, in both directions, against a recorded roster.

## GSTACK REVIEW REPORT

| Review | Trigger | Why | Runs | Status | Findings |
|--------|---------|-----|------|--------|----------|
| CEO Review | `/plan-ceo-review` | Scope & strategy | 0 | — | — |
| Outside Review | `codex exec` (content-inlined, no model override) | Independent 2nd opinion | 1 | completed | 12 raised: 4 folded in, 3 refuted with evidence, 5 answered in place |
| Eng Review | `/plan-eng-review` | Architecture & tests (required) | 1 | issues_open | 7 findings, 0 critical gaps, 2 unresolved |
| Design Review | `/plan-design-review` | UI/UX gaps | 0 | — | — |
| DX Review | `/plan-devex-review` | Developer experience gaps | 0 | — | — |

- **OUTSIDE COVERAGE:** provider codex, phase plan-review, completed; every finding verified against
  the repo per the Codex playbook before acting. **Folded in:** the dual-home exception needed a
  stated condition (§2.3); the roster parse needed a fail-closed recorded roster (§5); the
  stored-`_css` narrowing was a page-lockout risk of the #1007 class and the grammars widened to the
  full CSS vocabulary (§3.2, §4); `columns` switching a mechanism is now documented rather than
  implied (§3.1). **Refuted with evidence:** the companion's suppression check is source-agnostic,
  not `_css`-only; the cascade claim holds here because the six properties carry no `!important`
  anywhere in `assets/css/` and v2 components emit no inline `style` (both verified); "the testing
  plan is too large" loses to this repo's binding method rules (14.1, 14.2, vacuity probes).
  **Deferred with a reason:** per-param exposure and a `layout.flex`/`layout.grid` split, both in
  §9.5, the split argued against by the `section.columns` measurement.
- **CROSS-MODEL:** native review and outside voice reached the same open modelling question from
  opposite ends — group-grain exposure produces inert params (native found it via `section.panel`,
  the outside voice via `testimonials.list`). They differ on remedy scale: the outside voice says
  redesign exposure before building; the native review says ship the group with the honest grain and
  file per-param exposure as its own axis. Presented, not applied — §10 Q1 is the coordinator's.
- **VERDICT:** ENG REVIEW COMPLETE, NOT CLEARED — 2 coordinator decisions open (§10 Q1, Q2).
  Otherwise plan-ready: scope accepted as ruled, no scope reduction proposed, no critical gaps.

**UNRESOLVED DECISIONS:**
- §10 Q1 — `align-self` in `layout` (as the 7A tabled it) or in `sizing` (as §3 proposes, following
  the approved coverage table and avoiding four inert params on child-only roles).
- §10 Q2 — whether roster clause 2 (visibility-switched `display`) may take `nav.menu` and
  `nav.toggle` out of the group's reach.

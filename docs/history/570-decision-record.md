# v1 styling-baseline decision record (issue #570)

> **Historical record.** Consolidated 2026-10-06 from issue
> [#570](https://github.com/FJCF76/PromptingPress/issues/570) and its decision-record
> artifacts. This is the v1 styling-baseline decision record; it is **superseded by
> the v2 UDC role contract** (the v1 style-slot engine was retired at #1101). Nothing
> here re-enters as work.
>
> **Still-binding rulings and their authoritative v2 homes** (those documents, not
> this file, are the binding statement):
>
> - **Ruling 6** (write-accept = render-emit convergence: reject anywhere the
>   write-accept and render-reject sets diverge) → `docs/v2/LAYER-2-CONTRACT.md` and
>   `docs/v2/LAYER-3-CONTRACT.md`.
> - **Ruling 9 as amended by Addendum #5** (names freeze; a rename is a documented
>   breaking change, never an aliased migration) → `ai-instructions/add-component.md`.
>
> **Contents, verbatim from their sources:**
>
> 1. The full 27-ruling record (C-1…C-6, DG-1…DG-9, OQ-1…OQ-11), from the #570
>    decision-gate comment of 2026-08-06.
> 2. The nine Phase-0 rulings, the decision-gate outcome, and Addendum #5, from the
>    #570 issue body; Addenda #1 and #2 in full, from the decision-record artifact
>    (`570-design/decision-record.md`), which the issue body summarizes.
> 3. Addenda #3 (grid-step rename) and #4 (alias machinery retired; DG-9/DG-5
>    superseded), from the decision-record artifact.
>
> File and line citations inside the record refer to the v1 codebase at the time of
> each ruling, and artifact names (`baseline-matrix.md`, `normalization-plan.md`, …)
> refer to the orchestrator design workspace, not to files in this repository.

---

# Part 1 — The 27 rulings (decision-gate comment, 2026-08-06)

# #570 Decision gate — ruling record

**All 27 gate items are ruled.** This comment is the record. The questions as
posed are in `gate-addendum.md`; the repo evidence behind the rulings is in
`validation-note.md`; the four artifacts (`baseline-matrix.md`,
`normalization-plan.md`, `dispositions.md`, `intentional-differences.md`) have
been updated to match and every affected entry is marked `[final]`.

**Core posture, which every ruling below inherits:** *compatibility is not a
constraint — the best baseline wins, and every intentional break is counted and
documented.* Where a ruling nonetheless keeps a legacy surface alive, the reason
is **mechanism trust**, never compatibility.

Rendered evidence: 37 PNGs in `570-design/screenshots/` across two passes. All
contrast figures are measured, not estimated.

---

## The rulings

### Block C — maintainer calls

1. **C-1 — naming freeze: ACCEPTED, names become canonical and derivable.**
   Lands on option (b): all slot renames proceed, **all four class renames
   dropped**. Sub-pick (i): **`heading_align` → `title_align`** — `section/schema.json`
   declares `title` (:15), `title_accent` (:21) and `heading_align` (:39), so a
   derivable rule had a shipped exception on the three components an agent
   touches most. Also proceeding: `--table-section-*` → `--table-*`, and
   `--<c>-*-theme-color` → `--pp-<c>-*-theme-color` (unauthorable plumbing, so the
   freeze does not cover it).

   **Prop renames inherit DG-9's resolution mechanism.** The prop-alias map runs on
   the **write path only** (`lib/admin.php:368,674`; `lib/actions.php:1953,1972,2354-2395`)
   while the render path applies only the `variant`→`layout`/`theme` **key**
   migration (`lib/wp.php:428`) — so **62 declarations across 10 of 12 dev
   compositions would silently default.** Closed by the same mechanism → new plan
   entry **A-37**.

2. **C-2 / F3 — heading rhythm completes NOW; band background is OUT of the
   floor.** **The floor is 5 slots + `id`, final.**
   - **Rank 2, heading rhythm → completes** (6 slots, byte-identical unset,
     each carrying its current literal). It is the one row whose evidence is *a
     shipped, documented procedure six of ten bands cannot execute*
     (`style-component.md:232-256`), not parity. → **A-41**.
   - **Rank 1, F3 → out.** `embed` does **not** ship alone — family completion of
     one is not completion (bar #8). Band background becomes **job-based depth**
     with a five-part safe-treatment prerequisite recorded as the reopening
     condition for a **follow-up gate outside #570**: theme-variant routing, ink
     **and default** safety, theme routing, the missing text slots, rendered
     checks. → **ID-43**.
   - **Ranks 3–6 were not ruled.** Rank 3 (`table` `theme`) is bound to that
     follow-up gate; ranks 4–6 remain open C-2 rows.

3. **C-3a — hero's heading measure: ONE default, `--hero-heading-measure: none`,
   both states.** The `12ch` rule at `components.css:561-564` is **deleted**.
   Measured: `.hero__content` **shrink-wraps** (flex item), so `12ch` strands the
   whole column at **468px = 43%** of the 1088px inner — title, subtitle *and*
   buttons. `ch` is viewport-local while the column is not (`24ch` = 792px at 1152,
   744px at 1024); the smallest inert value is **≈29ch**, i.e. `none` with extra
   steps. On `split` the **553px track binds at ~17ch**, making every candidate
   ≥16ch byte-identical. At 375 both states are column-bound — `12ch` is a
   **desktop-only defect**. **Documented intentional render change:** left/split
   heroes gain full-column titles.

4. **C-3b — the #571 reference column-width/type-scale pair: NOT adopted in #570.**
   A different pair of slots; rendered evidence showed (i) taken alone is *worse*
   than the baseline (7 lines at 461px vs 415px) while `12ch` survives.

5. **C-4 — literals: NO blanket ratification; per-row dispositions stand.** The
   five opacity literals were **measured** (method and inputs in
   `validation-note.md` §7.5; all five are normal-size body text, so the bar is
   4.5:1):
   - **@3255 12.76:1 PASS · @3662 10.22:1 PASS · @3788 10.22:1 PASS** → **stay as
     deliberate de-emphasis, with the measured ratio recorded as the stated
     reason** (which is exactly what P14 asked for and the corpus did not have).
   - **@3303 3.87:1 FAIL · @3698 3.87:1 FAIL** → **move to §A as measured contrast
     corrections** → new entry **A-36**. Break-even alpha on that band is **0.968**
     — any opacity below ~0.97 fails, and `base.css:30` already documents the
     4.74:1 ceiling. Remedy is a **role token**, per P8 ("never as a literal").

   **C-4 group 4 remains open row by row.** It is the largest unfinished surface
   #570 leaves behind, and it is recorded as open rather than closed by silence.

6. **C-5 — logo sizing: disclose the switch, add two slots, and state the
   non-consequence.**
   - **Disclose the label-driven height switch** (`logos.php:39` — a labeled item
     re-declares `max-height: 2.5rem`; the schema describes the *layout* switch and
     never the height). → A-9.
   - **Add `--logos-image-size` and `--logos-gap`** (byte-identical unset,
     mirroring `--grid-item-icon-size`). → **A-40**.
   - **Explicitly NOT dark-bg unblockers.** A dark `logos` band needs heading and
     label **ink safety** (heading default 1.02:1, label 3.09:1 at 13px) **and an
     artwork answer** — dark transparent PNGs on a dark band. **The artwork
     question is an open design question routed to the F3 follow-up gate, not
     solved here**, adjacent to ID-6.

7. **C-6 — decorative boundaries: all rows stay unauthorable** unless the full
   8-point ADD bar is cleared, and **none currently clears it** (the bar requires a
   named incident; no row has one). **NO retraction of
   `--grid-featured-texture-color`** — it is a shipped slot, so retracting it
   would collide with ruling 9's stability freeze. Ruled from the documents; no
   rendered images exist for any C-6 row, and that is a stated choice.

### Block DG — ratified-contract touchpoints

8. **DG-1 — section panel CTA: add `--section-panel-cta-border`** at the head of
   the chain @1707, matching `--cta-button-border`'s position, **plus its
   `-hover-border` twin** (P6). **Narrow slot only — no `--section-button-accent`,
   no band-accent tier, no three-tier extension.** Byte-identical unset by
   construction. → **A-38**.

9. **DG-2 — hero: add `--hero-button-border`** at the head of the rest @833 and
   hover @855 chains, plus its twin. Ruled jointly with DG-1 (bar #8). Also →
   **A-38**. The css-lint chain-order pins and the #545 neutralisation set both
   grow and must be updated in the same change.

10. **DG-3 — all four class renames DROPPED.** The agent-visible premise is dead:
    `styling.variant_classes` has **zero readers** (12 `schema.json` files and
    nowhere else; `pp_ai_system_prompt()` emits no class names at all; no test
    asserts it; already stale on `faq` and `section`), and the Custom-CSS conflict
    detector emits only **12 root block names** through a regex whose trailing
    boundary **excludes `-`** (`lib/guardrails.php:16-31, 76`), so it cannot match
    a modifier class. Docs contain **0** occurrences of `.section__content`, `0`
    of `pp-section--`, `0` of any `<c>--dark` as a class; the test surface is
    **672 / 442 / 169** class-name tokens.

    **What lands instead:** `variant_classes` currently **lies** to any agent that
    reads it. **G1 must make the honest choice explicitly — populate all 12
    truthfully, or remove the field.**

11. **DG-4 — part 1: KEEP `<root>--dark`.** The rename fails the
    materially-improves bar per the same zero-reader evidence. **The eight
    misnomer CSS comments stay** (`components.css:2409-2415` — the only place the
    trap is written down). **No dual emission**, so part 2 (i)/(ii)/(iii) is moot,
    and the unanswered "who closes the window" question disappears. #442's
    "byte-identically **forever**" (`lib/helpers.php:187-189`) stands as shipped.
    → **ID-42**.

12. **DG-5 — ratify the `aliases` field and KEEP the `dark` write-alias.**
    Dropping it is byte-identical *at render* (`pp_theme_class()` coerces `dark`
    forever) but breaks the **write path**: the strict-enum check runs on the
    **whole composition** (`lib/admin.php:842-871` inside
    `pp_validate_composition()`'s item loop; `lib/actions.php:2345` validates the
    full test composition), so an untouched band's `theme:"dark"` blocks an edit to
    a **different band on the same page**. **Dev: 6 of 12 compositions, 10
    declarations — all four live dev site pages.** And one composition
    **manufactures the value at read time**: it stores `"variant":"dark"` with no
    `theme` key, and `pp_migrate_legacy_variant_keys()` carries the value across
    verbatim. **Canonical values stay clean** — `dark` is never advertised in
    `values`.

13. **DG-6 — acknowledged as written.** Hero's #436 exemption stands; any change
    is a #436 amendment, not a #570 entry. **Its shape is reused** for hero's
    exemption from `--measure-heading` → **ID-41**.

14. **DG-7 — acknowledged as written.** Ruling 3's routed inventory was a factual
    snapshot, not a ratification of names. V7 confirmed; A-5's severance proceeds.

15. **DG-8 — chrome.** The cleanup half (A-16, A-23) is confirmed inside the
    chrome guard's "option naming/shape/docs/defaults" allowance; both
    **strengthen** #223.
    - **Sub-q 1 — `pp_logo_alt`: WIRE IT.** **Dev has it SET to `"NeoCompute"` —
      a stored operator intent that has silently never rendered**, the trust class
      live. **Prod unset → byte-identical there.** Render-change scope: sites that
      set it, documented as a break. `lib/wp.php:2886-2898` confirms `alt` was
      never empty, so this is a **P4 fix, not an a11y fix**.
    - **Sub-q 2 — `footer_secondary`: a conditional readiness row, only when a
      menu is assigned.** No registration, no drift-guard change — `templates/base.php:84-88`
      relies on the current behaviour on purpose, and `lib/wp.php:463-475` rules
      out the always-on noise by name.

16. **DG-9 — RENDER-TIME resolution, for slots AND props.** A static
    legacy→canonical map inside `pp_render_style_vars()` **before** the
    declared-slot filter at `lib/wp.php:891`; and, for props,
    `_pp_apply_legacy_prop_aliases()` routed through
    `pp_migrate_stored_composition()` (`lib/wp.php:428`), whose docblock already
    says it is "the permanent counterpart that keeps already-stored legacy content
    readable".

    **Justified as MECHANISM TRUST, not compatibility. The bounded rule, verbatim:**

    > A legacy name resolves at render **iff** a shipped mechanism promises that
    > the already-stored document will render. Today exactly one mechanism makes
    > that promise (`restore_composition`, #233). No other legacy surface
    > qualifies.

    Falsifiable, and narrower than "compatibility". **A-31's byte-identity claim
    survives** — and now states its condition. Under a clean break,
    `restore_composition` would **succeed** and render a page stripped of its
    styling, while `pp_render_style_vars()` drops the declaration with a bare
    `continue` and every action still returns `ok:true`. **Avoided: 105 slot
    declarations across 7 of 12 compositions + 62 prop declarations across 10 of
    12 — ~167 declarations, 83% of the dev corpus.** Cost: one `isset()` on a
    static map. Second-order benefit: #545's neutralisation set needs **canonical
    names only**, which *shrinks* it.

### Block OQ — raised by the Phase 4 reviews

17. **OQ-1 — adjacent hero's unset top edge: option (ii), hero's own opener
    rhythm** (`var(--hero-padding-top, var(--space-2xl))` / `var(--space-xl)`).
    **Visible render change on every adjacent hero** — ~5rem → 7rem desktop,
    3.35rem → 4rem mobile — documented as intentional. **The maintainer chose
    product intent over byte-identity**: ID-1 states the opt-out as deliberate and
    this is the one edge where the catch-all (`[0,2,1]` @4098/@5159 beating `.hero`
    `[0,1,0]`) was silently overriding it. Hero becomes the one band whose
    adjacent-top fallback differs; the css-lint pin must record the exception.

18. **OQ-2 — no collapse.** `--section-body-measure` is declared and documented
    **with its four branch fallbacks intact** (`40rem` @1319, `42rem` @1380,
    `var(--measure-centered)` @4116/@4163, `49rem` @4303). Byte-identical; §6.3's
    old row 11 deleted. `--hero-content-width` is covered by the same answer.

19. **OQ-3 — `.section__title` stays uncapped.** No 40rem cap; §6.3's old row 8
    deleted. Verified: its only declarations @1349-1356 are font-size, color,
    margin-bottom.

20. **OQ-4 — see C-4 (item 5).** Option (c): measure each and route by result.

21. **OQ-5 — option (c): G9 scoped to `table`/`embed`/`logos`; broader rendered QA
    → #571.** All three have **zero tests of any kind**, and G2/G4/G7/G11 all ship
    changes to them. Shipping CSS to a component with no regression net at all is
    the specific risk; the rest is #571's standing capability.

22. **OQ-6 — add `--measure-heading` to the token family, initial value
    `40rem`.** **Byte-identical, validated:** every heading cap that renders today
    renders `40rem` (shared rule @1322-1329, grid @2074, testimonials @3881).
    Routed as F6's default for the **eight** non-hero, non-section surfaces, so the
    site-builder AI can retune band heading measure globally with **one
    `update_design_token` write** (bar-for-absence test #2, satisfied). → **A-39**.

    **HERO IS EXEMPT** — governed by `.hero__content` + the split grid constraints
    + `--hero-heading-measure: none`. **Same exemption shape as DG-6 / #436 /
    ID-2.** → **ID-41**.

    **The eventual `ch`-based retune of the token's value is a FUTURE MAINTAINER
    VISUAL DECISION, not implementation discretion.**

23. **OQ-7 — resolved by C-2/F3.** F3 moves out of the floor into job-based depth;
    the floor is **5 slots + `id`, 5/5**, and "floor" is a word an agent can rely
    on.

24. **OQ-8 — the #568 stacked treatment: the ruled default has FIVE properties.**
    Column layout at ≤767 · value text **LEFT**-aligned · a clear label/value gap ·
    inter-pair rhythm · **plus an explicit stacked label/value visual
    distinction.** The fifth is load-bearing: label and value render at identical
    size, weight and colour, and **position was the only distinguisher** —
    stacked, "Timeline" reads as a short first line of the value, not as its label.
    The concrete treatment is a **bounded implementation choice** whose acceptance
    is *"visually distinguishable in G10's mandatory 375px rendered check"*.

    **The two-column alternative is rejected on rendered evidence:** with a label
    `max-width` the labels shrink-wrap and **the value column's left edge is ragged
    across rows**. At 375 there is not room for two columns. The ruled default
    takes the value box 170 → 247px (+45%) and the longest value 4 → 3 lines.

25. **OQ-9 — ship the eyebrow guidance line.** P12 requires the shipped surface to
    say when to leave a default alone, and `style-component.md` contains no
    anti-uniformity guidance of any kind. **Wording is the maintainer's at review.**

26. **OQ-10 — bounded `applies_when` + `conditionality_note`, with the limits
    stated.** The four ANDed clause forms stay; §5.1 states explicitly which
    classes remain prose (disjunction, composed-page context, interaction state).
    **The grammar does not grow.** Note `lib/ai-context.php:82-92` emits
    `name (type, default: X)` per slot, so **A-17 must extend the emitter** or the
    field reaches no agent at runtime.

27. **OQ-11 — ID-9 confirmed**, with the falsifiable reopening condition: *an
    operator picks `layout: inline` on a cta to get left alignment and loses the
    full-width band, or picks `full-width` and accepts centring it did not want.*
    The P2 "one mechanism per job" argument holds today; the restated condition is
    what would overturn it. (The prop is renamed `title_align` by C-1; the
    difference is unchanged.)

---

## Final break census — 11 render-changing rows across 9 entries

`baseline-matrix.md` §6.3 is the authoritative register. **All eleven are live;
none is conditional.** Everything not listed is byte-identical unset, proven by
test.

| # | Entry | What changes | Who sees it |
|---|---|---|---|
| 1 | A-1 (slot half) | hero's adjacent-top padding obeys `--hero-padding-top` | heroes after a band **that author the slot** — dev: 3 |
| 2 | A-2 | section theme backgrounds/borders route their slots | `--section-bg`/border authored on a `muted`/`inverted` section — dev: 3 |
| 3 | A-3 | cta bg-image band borders route their slots | a border slot authored on a bg-image cta — dev: 0 |
| 4 | A-4 | inverted-band `title_accent` → `--color-accent-on-inverted` | inverted bands using `title_accent` — dev: 6 props / 14 inverted bands |
| 5 | A-14 | testimonials inverted-`stack` meta colour | an inverted `stack` testimonials band — dev: 0 |
| 6 | **A-36** | `.cta--has-bg-image .cta__body` loses `opacity: 0.85` @3303 → role token | every bg-image cta with body text. **3.87 → ≥4.5:1** |
| 7 | **A-36** | `.stats--has-bg-image .stats__label` loses `opacity: 0.85` @3698 → role token | every bg-image stats band. **3.87 → ≥4.5:1** |
| 8 | A-6 (hero) | `12ch` deleted; `--hero-heading-measure` defaults to `none` | **every unset left/split hero at ≥~1150px.** Column 468 → 896px (+92%), long title 7 → 3 lines. **Dev: every stored hero.** No mobile change |
| 9 | A-1 (fallback) | unset adjacent hero top edge, OQ-1 (ii) | **every hero after a band, unset** — ~5rem → 7rem desktop, 3.35 → 4rem mobile |
| 10 | A-35 | paired rows stack at ≤767px, OQ-8's five properties | **every paired row on mobile, unset.** Value box 170 → 247px, 4 → 3 lines, `right` → `left`, inter-pair 4 → 16px. Desktop unchanged. **The only deliberate mobile change on live production content** |
| 11 | A-22 | rendered `alt` on the site-logo image | **sites that set `pp_logo_alt`.** Dev does (`"NeoCompute"`); **prod does not → byte-identical there** |

### Rows the rulings deleted

- **`.section__title` gains a cap** — OQ-3: stays uncapped.
- **`--section-body-width` collapses** — OQ-2: no collapse.
- **~40 renamed slots stop resolving** — DG-9 render-time. *Would have been 105
  declarations, 7 of 12 compositions.*
- **~10 renamed props stop resolving** *(never carried by the register)* — A-37,
  same mechanism. *Would have been 62 declarations, 10 of 12 compositions.*

### Not render changes, recorded so the census is not misread as complete

**A-32 + DG-5** make 28 enum props strict — render unchanged by construction, and
because the `dark` alias is kept, **no currently-accepted `theme` value starts
failing**. A-33/A-30/A-27/A-34 are validation-surface only.

### Additions, all byte-identical unset — 10 new slots + 1 new token

`--section-panel-cta-border` + `--hero-button-border` + 2 hover twins (A-38) ·
six heading-rhythm slots (A-41) · `--logos-image-size` + `--logos-gap` (A-40) ·
the `--measure-heading` token (A-39). Plus the eight new
`--<c>-heading-measure` slots and four `--<c>-body-measure` slots that A-6 was
already declaring.

---

## Gate structure

| # | Gate | Contents |
|---|---|---|
| **G0** | Decision gate | **CLOSED** — this record |
| **G1** | **Definition-surface foundation** | Bounded `applies_when` + `conditionality_note` (**grammar does not grow**) · fill-role marker · prop `aliases` **with `theme: ["dark"]` kept** · slot-alias map with **render-time** resolution (A-31a) · **prop-alias route through `pp_migrate_stored_composition()`** (A-37) · `ai-context.php` emission for every field · `SchemaValidationTest` coverage · **the `variant_classes` honest choice — populate all 12 truthfully or remove the field.** No population, no renames |
| **G2** | Canonical vocabulary applied | A-31b (all slot renames + `heading_align`→`title_align` + `--table-section-*` + `--pp-<c>-*-theme-color`), A-24, A-26. **No class renames** |
| **G3** | Dead and defeated slots | A-1 (**with OQ-1 (ii)'s fallback**), A-2, A-3, A-7, A-8a, A-10, A-13, A-14, A-19, **A-36** |
| **G4** | **Measure surface** + leak severance | A-5 + A-6, A-15, **A-39** (`--measure-heading: 40rem`). **Hero `none`; hero exempt from the token; `.section__title` uncapped; no `--section-body-width` collapse** |
| **G5** | Write/render convergence | A-32 (incl. `theme` strict **with** aliases), A-33, A-30, A-27, A-34 |
| **G6** | `applies_when` population | A-17, A-8b — bounded grammar; `conditionality_note` carries the three classes it cannot express |
| **G7** | Schema truthfulness | A-9 (**incl. the logos label→height switch and the F4/F5 "leave it alone" sentence**), A-11, A-12, A-16, A-18, A-28, A-29 |
| **G8** | Chrome surface | A-21, **A-22 = wire `pp_logo_alt`**, A-23, **A-25 = conditional readiness row** |
| **G9** | **Stressed-state coverage** | **Scoped to `table`, `embed`, `logos`** — the three with zero tests of any kind. Broader rendered QA → **#571** |
| **G10** | `#568` paired-row degradation | A-35 with **OQ-8's five-property default** and a **mandatory 375px rendered check**, which is where property 5 is accepted or sent back. Independent; may land first |
| **G11** | Family completion | **A-41** (heading rhythm) · **A-38** (ring slots + twins) · **A-40** (logos sizing). **No band background** |
| **G12** | Docs reconciliation | A-20, incl. **OQ-9's eyebrow guidance line** and the C-6 stated reasons |
| **G13** | Needs-own-design children | B-1 … B-5 |
| **F3-FUP** | **Band background as job-based depth — OUT of #570 scope** | `--table-bg` / `--embed-bg` / `--logos-bg`, `table`'s `theme`, and `logos`' label colour + size. **Entry criteria (all, together, per component):** theme-variant routing · ink **and default** safety (a `<caption>` slot for table; label colour **and** size for logos) · theme routing (table: 7 selectors) · the missing text slots · rendered checks at 1280 and 375. **Plus the open `logos` artwork/tone question.** Cheapest first step is `embed`'s hazard-2 fix — but **`embed` must not ship alone** |

---

## Still open after this gate

Recorded because P16 forbids treating silence as a decision.

1. **C-4 group 4** — ~30 literals still needing a per-row P14 disposition. The
   largest unfinished surface #570 leaves behind.
2. **C-2 ranks 4, 5, 6** — faq card chrome, responsive `image_id`, item gaps. **No
   ruling was issued.** Rank 3 (`table` `theme`) is bound to F3-FUP.
3. **`--measure-heading`'s eventual `ch` value** — future maintainer visual
   decision; an implementer must not pick it.
4. **The `logos` artwork/tone model** — dark transparent PNGs on a dark band.
   Owned by F3-FUP, adjacent to ID-6.
5. **OQ-9's guidance wording** — the maintainer's, at review.
6. **OQ-8's fifth-property treatment** — bounded implementation choice, approved
   at G10's 375px check.

---

# Part 2 — Phase-0 rulings, gate outcome, Addenda #1, #2 and #5

## Ratified rulings (Phase 0 decision gate, 2026-08-06)
Nine maintainer rulings, binding on the audit and all normalization work. They answer the corresponding baseline questions above; the audit applies them, it does not reopen them.

1. **Audience:** the contract is cut for the supervised operator agent. Agents may propose and apply structured changes under human review; human input is required at decision boundaries (approval, ambiguous tradeoffs, warnings, unsupported fallbacks). #570 does not design for unsupervised in-admin autonomy.
2. **Matrix shape:** universal floor + job-based depth. A small transferable baseline slot set that every band-level component genuinely renders; deeper controls documented only where the job needs them. No declared no-op controls.
3. **Measure:** measure is a declared per-instance slot surface with defaults routed through the `--measure-*` token family (the shipped `var(--hero-content-width, var(--measure-centered))` shape). Free length values remain accepted; writing a non-token length emits a non-blocking warning. Accepted values are NOT restricted to token references in this pass. Repo-validated routed inventory: `--section-body-width`, `--cta-content-width`, `--grid-heading-max-width`, `--hero-content-width` (`--stats-max-width` is band geometry, not text measure). `table`, `embed`, and `testimonials` have no measure surface — they are gaps to complete, not routed precedent.
4. **Mobile:** no responsive slot values in #570. Mobile behavior stays defaulted and is covered by #571 rendered QA. #568 lands as a defaults/docs change, not a mobile knob.
5. **Precedence:** two-tier (per-instance slot → token/role/literal) is the default chain shape; the button family's three-tier chain is the documented exception. A new global tier requires the button model's recorded site-wide-retheme class of need.
6. **Invalid values:** split behavior. Reject malformed or provably dead values — anywhere the write-accept and render-reject sets diverge (#531's backslash and `/*` cases). Warn, non-blocking via the #386 smells channel, on well-formed values that are plausible but ineffective in context (#549's transparent fill, #508's dark-on-dark pairs).
7. **Computed signals:** renderer-emitted signal classes are allowed only where derived from declared authored values with existing repo precedent (`pp_theme_class()` enum mapping, `*--has-bg-image` presence classes). No luminance-computed darkness/signal classes in this baseline; #541's trigger stays a separate design-first decision. Repo-validated precedent record: the only appearance computation in the codebase is write-time token-family derivation (`pp_derive_family_tokens`, disclosed in the apply result, fallback-only) and the #386 warning engine; nothing computes appearance at render time.
8. **Conditionality:** conditional slots get a machine-readable `applies_when`-style schema field; write-time warnings derive from that same field (one source of truth). A slot's conditionality must be legible from `schema.json` alone.
9. **Stability:** slot and prop names freeze at the first stable contract; later renames require the #442 alias-and-keep model (`pp_theme_class()` is the shipped shape). Defaults, value grammars, and rendered behavior stay improvable under the byte-identity and gate discipline.

---

## Decision gate outcome (2026-08-07)

**The decision gate is CLOSED.** All 27 gate items (C-1…C-6, DG-1…DG-9,
OQ-1…OQ-11) are ruled, and **Addendum #1 (2026-08-07)** rules the three C-2 rows
the gate left open. **The 27 rulings and Addendum #1 are binding** on every child
issue filed from here.

**Standing constraint: decision content lives in issue bodies and in the
decision-record artifacts — never in issue comments.** A ruling that exists only
as a comment does not exist.

**Authoritative artifacts**, in the orchestrator design workspace
`~/.claude/pp-orchestrator/570-design/`: `decision-record.md` (the ruling record,
including Addendum #1) · `baseline-matrix.md` (the baseline; §6.3 is the
authoritative render-change register) · `normalization-plan.md` (§A entries
A-1…A-42, §B children, §C maintainer calls, §D gate structure) ·
`dispositions.md` · `intentional-differences.md` (ID-1…ID-45, each with
rationale, citation and reopening condition) · `validation-note.md` (the repo
evidence behind the rulings).

**Outcome.** The baseline lands as a **universal floor of 5 style slots + `id`**
(band background leaves the floor and becomes job-based depth, deferred to a
follow-up gate outside #570); a **canonical, derivable slot and prop vocabulary**
applied under **render-time alias resolution**, so ~167 stored declarations
across the dev corpus keep rendering while the names move; a **census of exactly
11 render-changing rows across 9 entries**, every one deliberate, measured and
documented, with everything else byte-identical unset and proven by test; and an
implementation structure of **G1–G13 plus the out-of-scope F3-FUP gate** —
definition surface, vocabulary, dead slots, the measure surface, write/render
convergence, schema truthfulness, chrome, stressed-state coverage, `#568`'s
mobile degradation, family completion, docs reconciliation, and the
needs-own-design children.

**Still open, recorded rather than closed by silence.** **C-4 group 4 is
RATIFIED as Addendum #2 (2026-08-07)** — all 44 rows stand as recommended (40
keep / 1 route-to-token / 0 route-to-slot / 3 defer); row 40 is filed as **#574**
(needs-design, out of #570 scope). Remaining open: `--measure-heading`'s eventual
`ch` value, the `logos` artwork/tone model (F3-FUP), OQ-9's guidance wording,
and OQ-8's fifth-property treatment (accepted at G10's 375px check). **The #570
decision surface is closed.**

**Child issues.** Implementation children will each carry **their own complete,
subagent-facing body drafted from these artifacts** — scope, inherited rulings,
evidence citations, byte-identity expectations and acceptance criteria stated in
full. No child will depend on this issue's history, and none will carry decision
content in a comment.

---

## `[final]` Addendum #1 (2026-08-07)

**Status: binding, and part of the gate record.** It rules the three C-2 rows
the gate left open and commits the process for the one remaining open surface.
Nothing here re-opens or re-litigates the 27 rulings above.

> **`[final]` STANDING CONSTRAINT, effective now.** **Decision content lives in
> issue bodies and in these decision-record artifacts — never in issue
> comments.** A ruling that exists only as a comment does not exist. Every
> implementation child issue therefore carries its own complete, subagent-facing
> body drafted from these artifacts, and every subsequent addendum is appended
> here and mirrored into the affected artifacts, not posted as a comment.

### A#1-1 — C-2 rank 4, faq card chrome: **INTENTIONALLY ABSENT** → **ID-44**

**With a premise correction.** The gate question implied faq's item chrome was
wholly unslotted. It is not. `.faq__item` @`components.css:1943-1947` already
slots the two **contrast-critical** members:

```
border: 1px solid var(--faq-border-color, var(--color-border));
border-radius: var(--radius);
background: var(--faq-item-bg, var(--color-bg));
```

- **Surface and ink are slotted** — `--faq-item-bg` and `--faq-border-color` are
  live per-instance slots, verified at HEAD.
- **Geometry routes the global token** — `border-radius: var(--radius)`
  (`base.css:152`, `--radius: 0.375rem`). An operator retunes faq's corner
  geometry site-wide with **one `update_design_token` write**, which is
  **bar-for-absence test #2 satisfied**. (The composed-page rule @5118-5121 is
  the `--grid-card-radius` leak, and **A-7 already gives it a faq-side name**,
  `--faq-item-radius` — so radius is covered twice over, once by the token and
  once by the forced severance.)
- **Border-width and shadow are decorative and have no incident.** No named
  authoring incident exists for either; the ADD bar is not cleared.

**Stated reason (P14 / bar test #1), verbatim:** *"surface and ink are slotted;
geometry routes the global token; decoration has no incident."*

**Reopening condition (bar test #6):** an operator needs a faq item's chrome to
**diverge from the site radius/shadow scale** — a different corner or elevation
on the faq band than on the site's cards — and cannot express it.

### A#1-2 — C-2 rank 5, responsive `image_id`: **COMPLETES** → **A-42**, assigned to **G11**

**Verified at HEAD.** `grid.php:161` and `testimonials.php:88` render **real
content images** as raw `<img src=… alt=… class=… loading="lazy">` with **no
`image_id` input and no `srcset` path**, while the rest of the product routes
`pp_render_responsive_image()` (`lib/wp.php:1322`): `hero` and `section` declare
`image_id` in `schema.json` (`hero/schema.json:94`, `section/schema.json:65`) and
`logos.items[]` already takes one (`logos/schema.json:32`, consumed at
`logos.php:35,40`).

**Srcset census — the asymmetry, precisely.** Of the five media surfaces that
render an author-supplied image: **3 responsive** (hero, section,
`logos.items[]`) · **2 not** (`grid.items[]`, `testimonials.items[]`). That is
the whole of the gap; there is no third class.

**The fix:** add `image_id` to `grid.items[]` and `testimonials.items[]`
(same field shape, description and `default: 0` as `logos/schema.json:32`) and
route both render sites through `pp_render_responsive_image()`.

**Byte-identical *visually*; the HTML changes.** Recorded in
`baseline-matrix.md` §6.3's **"not render changes"** block as a **mechanism
change**, explicitly **NOT** a §6.3 render-change row. The census stays at
**eleven rows across nine entries**.

**Stated reason it completes rather than staying absent:** this is not parity.
Two components emit a fixed-size image for a real content object on every page
that uses them, and the shipped mechanism that fixes it already exists and is
already used by three siblings — the absence is an accident of order, not a
choice, and it has a measurable cost on every render.

### A#1-3 — C-2 rank 6, item gaps: **INTENTIONALLY ABSENT** → **ID-45**

**Verified at HEAD.** `assets/css/components.css` carries **41 `gap:`
declarations**: **38 route a custom property** — **35 through `var(--space-*)`
directly, 3 through `var(--grid-gap, …)`** — and only **three are literals**:
`gap: 0` @455 (nav dropdown, a reset), `gap: 0.5rem` @2261 (grid item bullet
list), `gap: 0.45rem` @4464 (grid item link row). All three are small
intra-component nudges, none is a band or list rhythm.

**Site-wide gap retuning therefore already works, through the token scale** —
`update_design_token` on `--space-*` moves every list, band and item gap in the
product at once. **Bar-for-absence test #2 is satisfied without adding a single
slot**, which is the argument `normalization-plan.md` §C already made against
this row itself.

**Stated reason (P14 / bar test #1):** *"every rhythm-bearing gap resolves to a
`--space-*` token, so the retune an operator would want is already one write
away; per-instance gap slots would add five knobs that duplicate a working
mechanism."*

**Reopening condition (bar test #6):** **a recorded incident where one band's gap
must diverge from the token scale per-instance** — a composition that needs a
tighter faq stack or a wider logos strip *than the rest of the site* and cannot
get it without moving every other gap with it.

### A#1-4 — C-4 group 4: the process is committed

The gate refused a blanket ratification and left group 4 open row by row. **The
maintainer now mandates the shape of the close:** a **row-by-row disposition
table**, every literal **verified at HEAD** (file:line, value, selector), each
row carrying one of — *keep as a stated default* (with the P14 reason and a
reopening condition) · *route to a token* · *route to a slot* (which slot, which
gate) · *defer to a named issue* — plus a render-consequence flag on any row
whose disposition would change what is painted.

**The draft is `c4-group4-dispositions.md`** in this workspace. It is a
**recommendation**, not a ruling: **the maintainer ratifies it as Addendum #2,
and that ratification must land before G12 opens** (G12 is docs reconciliation,
which is where every stated reason has to be written down). Rows already ruled
elsewhere — the five opacity literals, heading rhythm, hero `12ch`, embed
`40rem`, the logos heights, faq's `44px` — do not reappear in it.

### `[final]` What Addendum #1 changed in the artifacts

| Artifact | Change |
|---|---|
| `intentional-differences.md` | **+ ID-44** (faq card chrome) and **+ ID-45** (item gaps) in §2, both with reopening conditions; §0 index and the sorted list go **43 → 45**; §7 ledger rows for faq card chrome, `image_id` and item gaps re-pointed; §8 gains an addendum row |
| `normalization-plan.md` | **+ A-42** in §A.6; C-2's ruling block and table rows 4/5/6 carry the rulings; C-4's ruling block carries the addendum-#2 commitment; §D **G11** gains A-42 |
| `baseline-matrix.md` | §4.9, §4.11 and §4.13 family verdicts re-pointed; §6.3's "not render changes" block gains the **srcset mechanism note**; §10 row 4 and the still-open list shrink |
| `dispositions.md` | Standing-reconciliation table gains the **responsive-image contract** row (A-42 extends it; no new mechanism) |
| `validation-note.md` | §9.3's two-item "unfinished work" list drops the C-2 ranks and restates C-4 group 4 as *drafted, awaiting ratification* |
| `decision-record.md` | this section; ruling 2's rank line; the additions census; the G11 row; the still-open list |

---

## Addendum #2 (2026-08-07) — C-4 group 4 ratified

**The maintainer ratified `c4-group4-dispositions.md` as recommended.** All 44
rows (42 group-4 + 2 F-24) stand: **40 keep-as-stated-default · 1 route-to-token
· 0 route-to-slot · 3 defer.** Consequences, now binding:

1. **A-43 (new, → G3):** `.hero__subtitle { line-height: 1.6 }` @576 routes
   `var(--line-height-body)` — a literal duplicating a declared token's exact
   value (`base.css:46`), the A-8 defect class. Byte-identical at the shipped
   value; changes render only on a site that has already retuned the token,
   which is the fix's purpose (the register's conditional row).
2. **Rows 10 and 14** defer to **B-4** (one mechanism for media sizing), which
   already owns them.
3. **Row 40** is filed as **#574** ("Reconcile the `--text-*` typography-role
   tokens with their hand-written consumers"), `status:needs-design`, **out of
   #570's scope** — the token family's size/weight/spacing members have zero
   consumers while six eyebrow blocks hand-write a different triple; which
   values win is a maintainer visual decision (the `--measure-heading` class).
4. **39 of the 40 keeps have no stated reason on any authoring surface today**
   (only row 18, `grid/README.md:133-134`, does). Each ratified keep generates
   its **A-20/G12 docs task**: the stated reason and reopening condition from
   the table land on the authoring surface. G12's size reflects this.
5. Row 39's reopening condition (pill geometry vs the OQ-9 guidance line) and
   row 3's G4 interaction (subtitle `40ch` cap can column-bind a short-title
   left hero after the `12ch` deletion) are carried as implementation notes in
   their gates.

**Still open after Addendum #2** (supersedes prior lists): `--measure-heading`'s
eventual `ch` value (maintainer) · the `logos` artwork/tone model (F3-FUP) ·
OQ-9's guidance wording (maintainer, at G12 review) · OQ-8's fifth-property
treatment (accepted at G10's 375px check) · **#574** (independent,
needs-design). **The #570 decision surface is closed.**

---

## Addendum #5 — Ruling 9 amended: renames are documented breaking changes, not aliased migrations (2026-08-09, landed by #606)

**Ruling 9 as ratified said:**

> **Stability:** slot and prop names freeze at the first stable contract; later renames require the #442 alias-and-keep model (`pp_theme_class()` is the shipped shape). Defaults, value grammars, and rendered behavior stay improvable under the byte-identity and gate discipline.

**The name freeze STANDS. The alias-and-keep requirement is STRUCK.**

Rationale, recorded rather than inferred: alias-and-keep IS the backward-compatibility
posture that the governing removal directive names as an explicit NON-GOAL, alongside
stale demo pages, old compositions, migrations and legacy tolerance. Ruling 9's own
worked example did not survive its siblings either — it cited `pp_theme_class()` as
"the shipped shape" of alias-and-keep, and #605 removed exactly that shape. With every
alias entry retired (#603 slot names, #604 prop keys and the `variant` read migration,
#605 the `theme: "dark"` value), the mechanism stood at zero entries, and a mechanism
kept for a rename that may never come is the speculative benefit the directive files
for removal. #606 retired the `aliases` schema field, its validator branch, its
strict-enum consumer arm and its AI-catalog emitter outright.

**Ruling 9 now reads, in effect:** slot and prop names freeze at the first stable
contract. A later rename is a **documented breaking change, ratified by the maintainer
at review and gated the way every other render-changing entry is gated** — never an
aliased migration. The new name ships, the old name is absent, and documents that still
store it lose that declaration with consequences stated out loud: rejected by name on
the three whole-composition actions (`create_page`, `update_composition`,
`update_component`), unread at render, and **reported rather than blocked** by
`restore_composition` (#233, unchanged). The change must carry a CHANGELOG entry and a
`SCHEMA_RENAME_MIGRATION_NOTES` entry in `tests/SchemaValidationTest.php`, whose
drift-catcher fails CI on any prop removal or rename that does not state what happens
to already-stored documents. Defaults, value grammars and rendered behavior stay
improvable under the byte-identity and gate discipline, exactly as before.

**Enforcement is now structural, not remembered.** `aliases` is no longer a declarable
key on any definition object — prop, slot, or nested `items[]` field — so a schema that
tries to reintroduce one fails CI as an unknown definition key. There is no dormant
mechanism to repopulate.

**Scope of this amendment:** ruling 9 only. Every other #570 ruling, the baseline
matrix, the intentional-differences register and the G1–G13 gate structure are
untouched.

---

# Part 3 — Addenda #3 and #4

## Addendum #3 (2026-08-08) — grid-step rename amendment

The C-1 rename table's `--grid-step-*` two-name swap is unsatisfiable under the
#575 alias mechanism (CHAIN sanitizer drops swap-into-legacy targets; bypassing
is irreducibly ambiguous — stored fill vs authored ink). **Maintainer ruled
option A:** rename the fill only — `--grid-step-color` → `--grid-step-bg`;
`--grid-step-text-color` keeps its name (shipped `-text-color` convention,
mirrors `--grid-item-text-color` and the cta `bg`/`color` pair). One alias
entry, byte-identical for both stored names, no un-authorable slot. The other
50 renames of the table are unaffected. Ruled at iteration 91 after the
implementer proved the collision programmatically and Codex converged on it.

---

## Addendum #4 (2026-08-08) — alias machinery retired; DG-9/DG-5 superseded

Maintainer rulings following the alias/normalization audit (issues #603–#607),
under the gate posture directive (backward compatibility, stale compositions,
migrations, legacy tolerance, and convenience aliases are non-goals):

1. **DG-9 and DG-5 are SUPERSEDED.** Both rested on old-corpus evidence
   ("~167 declarations, 83% of the dev corpus"; "6 of 12 compositions") — a
   category the directive removes from consideration. The render-time/read-path
   alias resolution (DG-9) and the `theme:"dark"` write-alias (DG-5) are
   removed by #603/#604/#605. The mechanism-trust rule is retired with them.
   The #575/#576/#579 changelogs remain historical records and are not
   rewritten.
2. **#606 / ruling 9 amended: the `aliases` field is retired outright.** No
   dormant alias-and-keep machinery is kept as future-rename capability.
   Consequence, stated plainly: ruling 9's name freeze stands, and any
   post-freeze rename that ever becomes necessary is a **documented breaking
   change** ratified by the maintainer — not an aliased migration. The #442
   alias-and-keep model is no longer the reference shape for renames.
   (DG-4's *emitted* `<root>--dark` class for `muted` is unaffected — that is
   output naming, not input aliasing, and its keep evidence stands.)
3. **`type`→`component` input alias: REMOVE confirmed** (inside #604). No
   generation trial unless fresh/current generation evidence later proves a
   real need; the reopening condition is that evidence, not speculation.

The one survivor inside the audit's scope: the schema-rename **drift-catcher
test** (rewritten so the migration-note branch is the sole escape hatch) — it
guards contract truthfulness, not compatibility.

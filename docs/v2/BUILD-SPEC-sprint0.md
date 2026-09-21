<!--
  docs/v2/BUILD-SPEC-sprint0.md

  The owner-approved build spec for the v2 program's Sprint 0, copied into the repo
  as the in-repo source of truth. §3 (the UDC contract) and §4 (the vertical slice)
  are what the code in lib/udc.php, lib/apply.php and components/testimonials/ was
  built against; read them before changing any of it.

  Where the implementation had to make a call the spec delegated or left ambiguous,
  the reasoning lives in the code that made it — lib/udc.php's header for the
  cascade, breakpoint and emission decisions, and lib/apply.php's unified-grammar
  block for the six consolidated unit lists.
-->

# PromptingPress v2 — Build Spec: Sprint 0 (the UDC contract + the vertical slice)

Status: APPROVED by the owner 2026-09-11. Sprint 0 executed under issue #958.
Derived from: the approved UDC design doc (wfroot-n5i9y-main-design-20260910-224118.md, rev 4) under the owner's 2026-09-10 directives (no backward compatibility — zero tokens spent on it; fewest sprints; drop everything non-functional to the new approach), amended by the adversarial Codex review (test repricing, fresh-build policy, vertical slice + contract-boundary reviews).

## 1. Program frame

- **Plan:** Sprint 0 (this spec, ~2–3 days) → Sprint 1 "engine" (`2.0.0-alpha.1`) → Sprint 2 "system" (`alpha.2`) → Sprint 3 "escape + proof" (`2.0.0`). Sprints are scope-defined releasable increments; ~weekly is a target, never a boundary.
- **Source of truth:** this spec lineage. Per-sprint task issues (a handful each, decisions recorded in bodies). #141 + the ~40 pooled issues: FROZEN as the legacy archive; a one-pass sweep during Sprint 0 harvests any issue describing an invariant v2 must keep (harvested → the invariants list in §6; nothing else re-enters until after 2.0.0 except release-blocking fix-firsts).
- **Ceremony:** owner approves each sprint spec before start; a contract-boundary review EARLY in each sprint (before consumers build on new interfaces); one review train + smoke at sprint close; full e2e per PR unchanged; screenshot/red-proof evidence discipline unchanged; mid-sprint genuine forks → owner as 7As.
- **Versioning & deploys:** `2.0.0-alpha.N` at each sprint close on main; five-file sync and zip-deploy discipline unchanged; dev install = the v2 build environment; prod stays 1.20.0 until cutover.

## 2. Policies (owner-directive-derived)

- **Fresh-build, no-migration, forever (for 1.x→2.0):** no importer, no compat shims, no migration machinery. The dev install is reset/rebuilt as needed; the owner's brand site is RECONSTRUCTED on v2 (that reconstruction is Sprint 3's acceptance test); prod cuts over at 2.0.0 by fresh deploy + content reconstruction. Destructive resets on dev are sanctioned.
- **Theme = release artifact (kept — this is upgrade reliability, not compat):** all site expression stays DB-stored; integrity/zip-deploy discipline unchanged.
- **Structural-CSS boundary (what may live in `assets/css/` in v2):** reset/normalize, layout skeleton per component (grid/flex scaffolding, wrapper geometry), accessibility affordances (focus rings, reduced-motion), and NOTHING value-styled: no colors, fonts, sizes, spacing, borders, shadows — every designable value comes from the UDC engine's emitted band blocks resolving tokens. A lint enforces the boundary (the css-lint suite retargets to this rule).
- **Truth spine kept in full:** schema validation, CAS, preview/approve, undo/rollback, honest envelopes cover `udc` writes with the same guarantees as v1 gave `props`.

## 3. The UDC contract (the heart — contract-boundary review target)

**3.1 Storage shape.** Composition item in v2:
```json
{"component":"testimonials","id":"pp-3f9a1c2e","props":{...},
 "udc":{"_tokens":{"quote-size-d":"19px","quote-size-p":"17px"},
   "quote":{"typography":{"family":"@font-serif","style":"italic",
     "size":{"d":"@quote-size-d","p":"@quote-size-p"}}},
   "_band":{"spacing":{"padding":{"top":"18px"}}}}}
```
- `id` is REQUIRED on every band in v2 (auto-minted `pp-<hex8>` at write when absent; carried forward on full-array re-apply by index+component match, minted fresh on ambiguity — carried from the design doc, simplified: v2 has no optional-id legacy).
- `@name` references resolve band-local `_tokens` first, then site tokens. A literal submitted where a reference could exist is legal AND stored as-is; minting is the ENGINE's normalization choice only when a value is used responsively (one value, N breakpoints) — the envelope always reports what the author wrote (no-coercion rule kept).
- Mint names are deterministic: `<role>-<group>-<param>[-<bp>]` *(widened by Addendum A ruling A3 to `<role>-<group>-<param>[-<state>]-<bp>`, where `<state>` may itself contain a hyphen — `focus-visible`)*. Token lifecycle: band tokens live and die with their band (GC by construction); an unused `_tokens` entry is a lint warning, never an error.
- The legacy `style` map (261 slots) DOES NOT EXIST in v2 components. One styling system.

**3.2 Role taxonomy.** Each component's schema declares `roles`: named sub-elements mapped to stable selectors, each listing its permitted UDC groups (declaration is data; the engine is shared). `_band` is the implicit root role. Sprint 0 defines the taxonomy grammar + testimonials' roles (`quote`, `attribution`, `card`); each rebuild sprint declares its components' roles.

**3.3 Value grammar (unified — supersedes the six divergent lists).** One owner validates every dimension-bearing value program-wide: lengths (`rem px em % vw vh vmin vmax ch ex lh rlh`, signed where the property allows), keywords per-property (typed keyword sets — `text-wrap`, `font-style`, etc.), colors/gradients/shadows/durations as typed grammars sharing the same number/unit core, `calc()/clamp()` with the same unit core, multi-value shorthands where the property family allows (padding/margin/inset/radius: 1–4 values). The preserved unit-grammar inventory (notes/t6-unit-grammar-inventory-raw.jsonl) is the consolidation map: the five divergent sibling lists (shadow px/rem-only, position missing vw/vh, gradient-stop no-negatives, radial %-only, the loose `[\d.]+` bodies) are unified INTO the owner — their current quirks die (no compat). The injection gate (`_pp_forbidden_css_construct`) and hardened number-body survive as the shared core. The AI-facing docs state the ACCEPTED grammar explicitly (v1 never told the model its unit set — fix that).

**3.4 Cascade contract.** *(SUPERSEDED IN PART by Addendum A ruling A3, below: the rung gains a PRESET tier — site tokens → presets → component role defaults → band `udc` — and `hover` widens to `hover` / `focus-visible` / `active`. The rest of this section still holds.)* Site tokens → component role defaults (schema data) → band `udc` values (desktop → breakpoint override → hover override). Emission order inside a band block: base declarations, then `@media` blocks narrow-first, then hover rules; later bands' blocks emit after earlier bands'; duplicate ids refuse at write (`duplicate_component_id` exists). No inline style emission anywhere in v2 components — the band block is the only styling source, so specificity is flat by construction (`[data-pp-band="<id>"] <role-selector>`, no `!important` ever).

**3.5 Emission.** One shared engine renders each band's block from `udc` (+ role defaults). Blocks emit inline in the document `<head>` per page (perf budget below); caching to uploads/ is a post-2.0.0 optimization.

**3.6 Truth-spine contract over `udc`.** `pp_validate_composition*` validates `udc` (roles exist, groups permitted, grammar per value) in the same pass as `props`; refusals use `invalid_prop_value`-family codes naming band+role+group+param, plus `unknown_udc_role`/`unknown_udc_group`; CAS/undo/preview operate on the whole item (they already do — `udc` rides the composition value); the approval diff renders `udc` changes per role with the author's literals.

**3.7 Perf budget (Sprint-0 measured, sprint-1 enforced):** emitted block size per band ≤ 2 KB typical; page at 50 bands: no measurable LCP regression vs a static-CSS control; measured in the slice.

## 4. Sprint-0 vertical slice (the deliverable)

Testimonials, end-to-end, on the dev install:
1. Schema: testimonials declares `roles` (quote/attribution/card) + permitted groups (Typography, Spacing, Border, Shadow, Background, Sizing; Addendum A ruling A3 added Motion to every role).
2. Grammar: the unified owner (lengths incl. new units, keyword sets, shorthands) wired for the slice's groups.
3. Engine: `udc` validation + deterministic minting + the band-block emitter with the cascade contract.
4. Component: testimonials rebuilt v2-native (structural skeleton CSS only; every designable value via UDC; the legacy slot map gone from this component).
5. Truth spine: a real chat/CLI write carrying `udc` → validate → preview/approve → CAS conflict staged → undo — all pinned.
6. The #901 acceptance case reproduced exactly: white card, 1px border, serif-italic 19px quote, 1px rule, sans attribution — including the single-card band — screenshotted at 375/768/1280.
7. Perf + block-size measurements published.
8. Contract-boundary review of §3 as implemented (adversarial, before Sprint 1 consumes it).

NOT in Sprint 0: the other 11 components, Layer 2/3, the AI-instruction rewrite, hero/nav, the accordion, any 1.x removal beyond testimonials' own slot map.

## 5. Test repricing (initial marks; finalized as Sprint 0's exit artifact)

| Category (tests/) | Initial mark |
|---|---|
| Truth-spine behavior: CAS, rollback/restore, envelopes, ring, batch exits, chat-card JS suites | **KEEP** (styling-agnostic by design; spot-adjust fixtures) |
| Value-grammar pins (ApplyTest et al.) | **REWRITE** onto the unified grammar (the old quirks' pins die deliberately) |
| Style-slot contract + per-component render-guard suites | **REPLACE** with UDC contract tests, per component as each is rebuilt (the honest PR unit = component + its tests) |
| css-lint suite | **RETARGET** to the structural-CSS boundary |
| Schema validation suites | **EXTEND** (roles/groups grammar) |
| e2e | **PARTIAL REWRITE** as components rebuild; full suite stays the PR gate throughout |
Deleted/rewritten counts per category are enumerated in Sprint 0's exit report and priced into Sprints 1–3.

## 6. Invariants harvested from the legacy backlog (sweep COMPLETE 2026-09-13)

The one-pass sweep classified all 187 open issues exactly once (verified by set-diff): **117 issues → 43 invariants (I1–I43)**, 39 die with v1 styling, 27 orthogonal-deferred, 4 program/meta. The full invariants list with citations lives beside this spec (V2-INVARIANTS-sweep-raw.jsonl → to be committed as docs/v2/INVARIANTS.md with the slice). Headlines binding on current work:
- **I8 (#909 CRITICAL): every mutating step is CAS-covered by a baseline the conversation EARNED for the page it targets.** The only invariant whose violation converts a refusal into an acceptance. ~~PINNED in the Sprint-0 truth-spine tests (the fix stays deferred per §7; the pin records the truth).~~ **CORRECTION (Sprint 1, boundary-review finding E3):** it is NOT pinned. The Sprint-0 test written for it (`tests/UdcTruthSpineTest.php`) asserts only that a write's baseline must EQUAL the stored version — a baseline from another page is refused because its number differs, not because its origin is wrong — and was renamed to say so. I8 remains unpinned and unfixed; both stay deferred per §7. This line is corrected rather than deleted because the claim was acted on: it is why no one looked again.
- **I35 (#908): no declared authoring input silently ignored or cancelled by another mechanism** and **I36 (#681 ruling): no hidden aliasing, cascade included** — the two invariants the §3.4 cascade contract must satisfy; explicit targets of the §4.8 contract-boundary review.
- The eleven category headings (write-path honesty, concurrency/consent, rollback truth, corrupt-state/no-fatal, model-facing truth, diagnostics vocabulary, schema/grammar truth, reflected text, editor round-trip, engineering-system, artifact integrity) are the acceptance frame every sprint's close is checked against.
- Recommend-close: #498 (moot under §2's fresh-build policy). Judgment calls recorded in the raw sweep (notably #900 kept as I31's self-consistency clause; #908 kept as I35 though its v1 mechanism dies).

## 7. Later-sprint pointers (scoped out of Sprint 0, named so they are not lost)

Layer 2 (breakpoint-keyed declaration lists, PP-owned property/function ALLOWLIST) and Layer 3 (sanitizer model: kses delta, no event attrs/iframes/JS URLs; content islands) — Sprint 2/3, each with its own contract review. AI-instruction rewrite with prompt-regression cases (the ai-ready harness extends). The accordion UDC UI: post-2.0.0. #909 CRITICAL re-enters at Sprint 3 if capacity allows, else first post-2.0.0 work.

---

<!--
  Addendum A is copied verbatim from the canonical spec. Sprint 1's tasks are
  ruled here; where an implementation had to choose a shape the ruling
  delegated, the reasoning lives in the code that made it (lib/udc.php's
  pp_udc_states(), pp_udc_presets() and pp_udc_compile_band() for ruling A3).
-->

## Addendum A — Sprint-1 contract rulings (Fernando, confirmed one-by-one, 2026-09-14)

**A1 — Chrome storage (CONFIRMED):** a site-level UDC container — the `pp_site_udc` option holding one entry per chrome component (nav, footer), identical internal shape to a band's `udc` map, validated by the same engine and grammar, emitted under `[data-pp-chrome="<name>"]`; writes ride the existing site-option truth-spine machinery (CAS/snapshot/rollback). Per-page chrome overrides explicitly OUT of scope.

**A2 — Background images (CONFIRMED):** the Background group gains `image: attachment_id` (+ position/size/repeat/overlay companions). The ENGINE resolves the ID via WordPress, verifies referential existence (dangling ID = write refusal naming the ID), and constructs the `url()` from the escaped same-install URL. Author-written `url()` stays banned everywhere. External URLs, video backgrounds, and per-breakpoint art direction OUT of scope (each its own future ruling).

**A3 — Presets, states, motion (CONFIRMED as amended — the Divi-complete contract, staged delivery):**
- PRESETS are first-class: named `udc` fragments at ROLE grain and GROUP grain, site-stored, referenced by name, band-overridable, dangling references refused (the @ref discipline one level up — a reference resolving to a value map instead of a scalar). Cascade rung: site tokens → presets → component role defaults → band `udc`.
- Sprint 1 ships the resolution mechanism + three SYSTEM presets (button, button-secondary, link); Sprint 2 ships author/AI-created custom presets (create/save/apply) with the AI-instruction rewrite. The Sprint-1/2 schemas encode against the preset contract from day one.
- STATES widen to `hover` / `focus-visible` / `active` as value dimensions; `disabled`, pseudo-elements, ancestor-states deferred to their own ruling.
- MOTION group: `transition-duration` + `timing-function`, defaulting to today's theme values; `prefers-reduced-motion` respected structurally (a §2 accessibility affordance, not an authored value).

### A3 sub-ruling — preset/role group mismatch (orchestrator ruling, T2 — pending maintainer review)

Ruling A3 settles the cascade rung and the dangling-reference refusal, but not what happens when a preset declares a group the target role does not permit. Implementing T2 forced the question: refusing the whole reference left the shipped `button` preset writable on 2 of testimonials' 12 roles (9 blocked by a `shadow` group carrying only the inert `box: none`, 1 by `typography`).

Settled as follows. This is a sub-ruling INSIDE A3's confirmed contract, not a new maintainer-confirmed axis, and stays overridable:

1. **INTERSECT.** A role-grain `_preset` applies the groups the target role permits and skips the rest. A group-grain `_preset` names one group and is refused as usual if the role does not permit it.
2. **THE SKIP IS DISCLOSED.** Skipped groups are named in a `udc_preset_groups_skipped` write-envelope finding — the same channel as the minting disclosure, not a log line. A partial apply is acceptable; a silent partial apply is the reported-success-without-effect class I35 forbids.
3. **AN EMPTY INTERSECTION REFUSES**, naming the preset and the role, in the same posture as a dangling reference. Intersect semantics must never degrade into a fully silent no-op.
4. **The write gate and the emitter intersect through one predicate**, so what the envelope reports as skipped is what the page omits.
5. The inert `shadow: {box: none}` is dropped from both button presets (`.btn` sets `box-shadow: var(--btn-shadow, none)`, so it painted nothing, and `shadow` is the narrowest group in the taxonomy — carrying it made the disclosure fire on nine roles to report a no-op).

Sprint-2 custom presets inherit these semantics unchanged.

Recorded context: the 2/12 figure is partly an artifact of testimonials having no button-like role; the hero/nav rebuilds are where the button presets earn their keep. That supports intersect (the wall would otherwise recur on every future component) without softening the honesty requirements above.

---

<!--
  Addendum B is copied from the staged draft that was ratified, with the ruling
  header added. Sprint 2's grid rebuild is built against it; where the
  implementation had to choose a shape the ruling delegated, the reasoning lives
  in the code that made it (lib/udc.php's item-tier block for the emission and
  provenance decisions, and components/grid/schema.json's `item_roles` for the
  declaration surface).
-->

## Addendum B — item-grain UDC (Fernando, confirmed as drafted, 2026-09-21)

**Status: RULED. Confirmed AS DRAFTED — all five clauses and all six exclusions, no amendments.** Ratified by the owner on 2026-09-21, from the draft staged at #1024 on 2026-09-18 for confirmation the way Addendum A was confirmed. Gate issue #1024 is lifted by this ruling; the grid rebuild (#1101) is what implements it.

### B0 — Why this exists: the measured gap

The T4 site-structure inventory (#1023) read promptingpress.com live on 2026-09-18. On **4 of the 5 content pages** (`/how-it-works/`, `/for-agencies/`, `/for-consultants-implementers/`, and the blog post) exactly one card inside an otherwise light uniform grid is dark:

```
li.grid__item  style="--grid-item-bg:#14141F; --grid-item-title-color:#F2EEE5;
                      --grid-item-text-color:#E8E2D4; --grid-item-border-color:#0A0A12"
```

Rendered, that is cards 01 and 03 light, card 02 dark — a deliberate emphasis device, and the owner's grid design language rather than an accident of authoring.

v1 expressed it with `grid.items[].style`, a per-item map rendered as **inline custom properties on that card**. v2 forbids inline style emission outright (§3.4) and roles are BAND-grain, so **the v2 contract had no address for "this one card"**. A `card-featured` role does not cover it: the live need is "the SECOND card is dark", not "the first card is emphasised", and no ordinal-free role can say that.

Deleting the capability is the #901 class — a capability deletion the UDC exists to end. The same reasoning added `sizing.aspect-ratio` to the engine during hero's rebuild (#986) rather than dropping the property when neither system had a home for it.

Sprint 3's acceptance test is reconstructing exactly these pages.

### B1 — Item-grain `udc` maps

A composition item's `items[]` entry MAY carry its own `udc` map, identical in internal shape to a band's `udc` map, validated by the same engine, the same grammar, the same group taxonomy, and the same refusal codes.

```json
{"component":"grid","id":"pp-3f9a1c2e",
 "props":{"items":[
   {"title":"01 Scattered","text":"..."},
   {"title":"02 Bounded","text":"...","id":"it-7b2c91d4",
    "udc":{"card":{"background":{"fill":"#14141F"},
                   "border":{"color":"#0A0A12"}},
           "card-title":{"typography":{"color":"#F2EEE5"}},
           "card-text":{"typography":{"color":"#E8E2D4"}}}},
   {"title":"03 Evidence","text":"..."}]},
 "udc":{"_band":{"spacing":{"padding-top":"6.25rem"}}}}
```

The roles an item map may address are the SAME roles the component declares — no second taxonomy. Which roles are item-addressable is declared per component, see B5.

### B2 — Item ids, minted the way band ids are

`id` on an `items[]` entry is the item's stable styling handle, and it follows `pp_udc_assign_band_ids()`'s rules exactly rather than inventing a second lifecycle:

- shape `it-<hex8>`, distinct prefix from a band's `pp-<hex8>` so the two can never be confused in a selector, a message, or a test;
- **minted on WRITE only** — a read never mutates, matching §3.1 and the existing `pp_udc_compile_band()` "MINT-ON-WRITE ONLY" rule;
- an authored or already-minted valid id is honoured, never overwritten;
- carried forward on full-array re-apply by **index + component match**, minted fresh on ambiguity — the band rule, one level down;
- uniqueness is enforced **within the band**, not globally: two bands may each hold `it-…` ids without collision because the emitted selector is always band-scoped (B3). A duplicate within one band refuses at write, mirroring `duplicate_component_id`;
- minted only for components that declare item-addressable roles (B5), so a legacy component's stored shape is not changed for no reader — the same boundary `pp_udc_assign_band_ids()` draws with `pp_udc_is_v2_component()`.

Items with no `udc` map get **no id and no attribute**. Minting an id for every list entry would change stored shape for nothing and would put a meaningless attribute on every card.

### B3 — Emission shape

The item tier emits into the SAME band block, as an additional, more specific selector:

```css
[data-pp-band="pp-3f9a1c2e"] .grid__item            { /* band tier, role default + authored */ }
[data-pp-band="pp-3f9a1c2e"] [data-pp-item="it-7b2c91d4"] .grid__title { /* item tier */ }
[data-pp-band="pp-3f9a1c2e"] [data-pp-item="it-7b2c91d4"]              { /* the item root role */ }
```

The component renders `data-pp-item="<id>"` on the element the item's ROOT role maps to (`.grid__item` for grid), exactly as a band renders `data-pp-band`, and emits **no attribute at all** when the id is absent or malformed — the same guard `testimonials.php` already carries, for the same reason: an empty attribute would match every other id-less item on the page.

Ranking is by ordinary specificity within the unlayered tier: the item selector carries one more attribute than the band selector, so it wins its own element without `!important` and without raising the band block's specificity. §3.4's "no `!important` ever" is unchanged.

Emission order inside the band block: band role defaults, band authored, then item tier, each still narrow-first across breakpoints and states. The item tier prints last so that source order and specificity agree rather than compete.

### B4 — Provenance, findings and disclosure

The item tier is a **cascade tier**, so it joins the existing machinery rather than sitting beside it:

- **Provenance.** `pp_udc_compile_band()` already carries `source` per declaration because I35 requires that a value that loses can be reported. The item tier adds one more `source` value (`item`), so a band value cancelled by an item value is reportable in the same shape a role default cancelling a band value already is.
- **`udc_band_value_shadowed_by_role_default`** gains its item counterpart: an item value shadowed by a role default, and a BAND value shadowed by an ITEM value, are both disclosed. Silent cancellation at the item tier would be the exact I35 failure the band tier already guards.
- **`udc_token_minted`** applies unchanged: a responsive item value mints a band token. Mint names extend to `<item>-<role>-<group>-<param>[-<state>]-<bp>` so two items styling the same role at the same breakpoint cannot collide on one token name.
- **`udc_preset_groups_skipped`** (A3's intersect ruling) applies at item grain unchanged, and A3 clause 4 — "the write gate and the emitter intersect through ONE predicate" — is satisfied by using the same predicate, not a second copy.
- **`udc_unused_band_token`** counts item references, so an item's token is not reported unused.
- Findings carry the item locator alongside the band index, so a message names the card rather than only the band.

### B5 — Generality: this is not a grid feature

**Item-grain addressing is declared in the schema, and every repeater component may declare it.** The schema names which of its roles are item-addressable and which prop holds the repeater:

```jsonc
"item_roles": {
  "prop": "items",
  "root": "card",
  "roles": ["card", "card-media", "card-title", "card-text", "card-bullets", "card-link"]
}
```

The engine reads that declaration; it holds no list of component names. Candidates the same declaration serves without further engine work: **grid** (cards/steps), **testimonials** (quote cards — the #901 acceptance case's "one card different" is the same shape), **faq** (items), **logos**, **stats**, **table** (rows), and `section.panel_items` (whose v1 per-row `style` map is the second live instance of this gap).

Declaring it is optional; a component that declares no `item_roles` behaves exactly as today.

### B6 — Explicitly NOT included (the six exclusions, each its own future ruling)

Each refuses rather than being ignored: accepted-stored-ignored is the I35 class this engine exists to close.

1. **No per-item chrome.** Chrome (`nav`, `footer`) is site-grain by ruling A1, which already put per-PAGE chrome overrides out of scope; per-ITEM chrome would be two levels past that.
2. **No per-item `_band`.** `_band` is the band's own root by definition (§3.2). An item map naming `_band` is refused, not silently ignored — an item cannot restyle its container.
3. **No ordinal or structural selectors.** `nth-child`, `first`, `last`, `even/odd` are not addressable. An item is addressed by its minted id and nothing else, so reordering the list carries the styling with the item rather than leaving it on a position. This is the whole reason for an id rather than an index.
4. **No nesting beyond one level.** An item inside an item is not addressable. No component ships a two-level repeater today.
5. **No per-item presets defining new presets.** An item map may REFERENCE a preset with `_preset` (A3 semantics, intersect included); it may not define one. Presets stay site-stored per A3 and #1016.
6. **Pseudo-elements remain deferred** — carried forward from ruling A3 unchanged, and recorded here so the two deferrals sit side by side. The live instance is `section.body_marker` / `panel_items_marker`, whose glyph is painted by `.pp-marker-list--{variant} > li::before`. In T4 the marker enums stayed structural props (the glyph is content) and the two colour slots retired with the narrowing stated in `retired_props`. Measured live exposure at the ruling: **zero**. A pseudo-element ruling would give them a home; until then the deferral is recorded rather than implied.

### B7 — What this costs, stated rather than implied

- **Emitted CSS grows with styled items, not with items.** Only an item carrying a `udc` map emits anything. §3.7's budget (≤2 KB typical per band) is measured per band and needs re-measuring on a band with several styled items.
- **One more tier is one more thing a disclosure can miss.** B4 is the mitigation, and it is the clause most worth scrutinising: every finding that names a band must decide what it says about an item.
- **`items[]` entries gain an `id` key.** Under §2's no-migration policy this costs nothing for 1.x content, which is reconstructed rather than migrated. It does mean the editor's array-sync guard must treat `id` and `udc` as preserved keys, not user content.

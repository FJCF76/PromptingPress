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
- Mint names are deterministic: `<role>-<group>-<param>[-<bp>]`. Token lifecycle: band tokens live and die with their band (GC by construction); an unused `_tokens` entry is a lint warning, never an error.
- The legacy `style` map (261 slots) DOES NOT EXIST in v2 components. One styling system.

**3.2 Role taxonomy.** Each component's schema declares `roles`: named sub-elements mapped to stable selectors, each listing its permitted UDC groups (declaration is data; the engine is shared). `_band` is the implicit root role. Sprint 0 defines the taxonomy grammar + testimonials' roles (`quote`, `attribution`, `card`); each rebuild sprint declares its components' roles.

**3.3 Value grammar (unified — supersedes the six divergent lists).** One owner validates every dimension-bearing value program-wide: lengths (`rem px em % vw vh vmin vmax ch ex lh rlh`, signed where the property allows), keywords per-property (typed keyword sets — `text-wrap`, `font-style`, etc.), colors/gradients/shadows/durations as typed grammars sharing the same number/unit core, `calc()/clamp()` with the same unit core, multi-value shorthands where the property family allows (padding/margin/inset/radius: 1–4 values). The preserved unit-grammar inventory (notes/t6-unit-grammar-inventory-raw.jsonl) is the consolidation map: the five divergent sibling lists (shadow px/rem-only, position missing vw/vh, gradient-stop no-negatives, radial %-only, the loose `[\d.]+` bodies) are unified INTO the owner — their current quirks die (no compat). The injection gate (`_pp_forbidden_css_construct`) and hardened number-body survive as the shared core. The AI-facing docs state the ACCEPTED grammar explicitly (v1 never told the model its unit set — fix that).

**3.4 Cascade contract.** Site tokens → component role defaults (schema data) → band `udc` values (desktop → breakpoint override → hover override). Emission order inside a band block: base declarations, then `@media` blocks narrow-first, then hover rules; later bands' blocks emit after earlier bands'; duplicate ids refuse at write (`duplicate_component_id` exists). No inline style emission anywhere in v2 components — the band block is the only styling source, so specificity is flat by construction (`[data-pp-band="<id>"] <role-selector>`, no `!important` ever).

**3.5 Emission.** One shared engine renders each band's block from `udc` (+ role defaults). Blocks emit inline in the document `<head>` per page (perf budget below); caching to uploads/ is a post-2.0.0 optimization.

**3.6 Truth-spine contract over `udc`.** `pp_validate_composition*` validates `udc` (roles exist, groups permitted, grammar per value) in the same pass as `props`; refusals use `invalid_prop_value`-family codes naming band+role+group+param, plus `unknown_udc_role`/`unknown_udc_group`; CAS/undo/preview operate on the whole item (they already do — `udc` rides the composition value); the approval diff renders `udc` changes per role with the author's literals.

**3.7 Perf budget (Sprint-0 measured, sprint-1 enforced):** emitted block size per band ≤ 2 KB typical; page at 50 bands: no measurable LCP regression vs a static-CSS control; measured in the slice.

## 4. Sprint-0 vertical slice (the deliverable)

Testimonials, end-to-end, on the dev install:
1. Schema: testimonials declares `roles` (quote/attribution/card) + permitted groups (Typography, Spacing, Border, Shadow, Background, Sizing).
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

# The AI-instruction surface — contract (v2 Sprint 2, T10)

**Status:** ruled (7A, three batches, 2026-09-21) and implemented for the MECHANISM half. Issue **#1087**.
**Companion documents:** `docs/v2/BUILD-SPEC-sprint0.md` (§7 names this task), `docs/v2/LAYER-2-CONTRACT.md`
(§7.1, the ruling that `lib/ai-context.php` is the only channel), `docs/v2/INVARIANTS.md`.

Delivered as two PRs against one issue, sequentially:

| | Scope | State |
|---|---|---|
| **PR1 — the mechanism** | the role key guard, `obligations`, the derived prompt rosters, the descendant net, the roster guards, the contrast regression case, the byte budget, the chrome-example fix, the mandate-fetching tripwire | this document |
| **PR2 — the prose** | the ~4,300-line rewrite of `ai-instructions/`, the ~35 queued claim verifications, the end-to-end authoring session | follows PR1's merge |

The guards are what make the prose rewrite safe, so they merge and enforce **before** the prose is
written against them. Every rewritten roster then lands already-pinned.

---

## 1. The measured pipeline

Two model-facing channels, and they are not alike. Everything below was probed at `8e14221`.

**Channel A — the in-admin chat AI.** `ai-stream.php:97` → `pp_ai_system_prompt()`. **Single shot: no
tool calls, no function calling, no way to fetch a schema mid-turn.** `lib/ai-provider.php` routes
through WP's `ProviderRegistry` with **no prompt caching**, so the whole string is re-sent on every
conversation turn, on the operator's own API key.

**Channel B — a filesystem/CLI agent.** `ai-instructions/*.md` (shipped; `.distignore` does not exclude
them) **plus** `wp pp schema <component>`, which already emits every role's full `description`
(`lib/operate.php:2848-2869`). One component's report is 24,715 bytes.

**So #1059's "a role's `description` is never injected" is true of Channel A only.** Channel B has had
them all along. That asymmetry is the whole design.

Prompt composition at the time of the ruling: 90,367 bytes on an empty store, of which ~37,500 was
derived from the registry and ~52,000 was hand-written prose containing **998 bytes** of derived
fragments. **~58% of the prompt was hand-maintained prose with a 1 KB derived core.**

---

## 2. Rulings

### Q1 — role descriptions do NOT inject, at either grain

125 roles carry 92,572 bytes of `description`. Injecting them takes the prompt from 90 KB to ~177 KB,
**+96% every turn, uncached**, for content written for maintainers: faq's `question-open` alone is
1.5 KB of ruling references and selector history wrapping a two-sentence obligation.

Per-component-on-demand is **impossible** on Channel A and **already solved** on Channel B.

### Q2 — the source of truth is a required `obligations` key on the role definition

Four properties, all of them load-bearing:

1. **Required on every role**, `[]` permitted. An optional field is answered by omission on every role
   a future rebuild adds — which is how the stale rosters happened.
2. **Bounded structured records** (`{kind, with, why}`), never free prose.
3. The prompt keeps its hand-written **argument** and derives its **roster**.
4. A registry-derived guard pins **both directions**.

**The honest asymmetry, and the code says so rather than implying otherwise.**
`reached_only_by_inheritance` IS derivable from selectors; `outranked_by_default` is NOT — a sound
string predicate misses faq's `question`/`question-open` and nav's `link`/`link-current`, while a loose
one false-positives on `.faq__heading` against `.faq__heading-accent`, which select different elements.
Declaration is the complete source of truth; the derivation is a one-directional net.

**Prerequisite, landed first:** role definitions had **no key guard at all**, so a typo'd key was
accepted by every surface and ignored forever — the accepted-stored-ignored class.

### Q3 — the budget is enforced in BYTES, ceiling 92,000

A byte count is deterministic offline; a token count depends on which of three provider families the
operator configured. `PP_AI_PROMPT_BUDGET` is pinned on a **seeded empty store**, because that is the
only figure that is a property of the code rather than of someone's content. The pin carries a floor
too — a ceiling is trivially satisfied by a collapsed prompt.

Page-scoped catalog injection was considered and **rejected**: saves ~6 KB, breaks "add a component
this page does not have yet".

### Q4 — the three-way split

- **Runtime-injected** (Channel A has nowhere else): the obligation class — contrast obligations,
  pairing idioms, structured-first/`_css`, grammar, refusal codes, the derived rosters.
- **Instruction files**: the narrative and procedural class — workflow, CLI mechanics, CAS/versioning,
  the playbooks.
- **Neither, by reference**: per-component role detail, **fetched** with `wp pp schema <component>`
  and never duplicated. Enforced by a tripwire.

### Q5 — prompt-regression cases extend `DocumentedUdcSnippetsTest`

It already runs documented `udc` maps through the real write path with fail-closed floors. It now also
walks the runtime prompt and the instruction files, carries the contrast case, and the byte pin lives
beside it.

### Batch 2 — scope

- **C1** `build-landing-page.md` → rewritten as the composition+`udc` recipe it is named for;
  `add-page.md` → relocated under a product-dev prefix with a banner, `AI_CONTEXT.md` repointed.
  Both teach the ACF/PHP-template path the composition model replaced, and `AI_CONTEXT.md:29/:31`
  route plain *site* requests into them.
- **C2** `AI_CONTEXT.md` and `AI_RULES.md` in scope **for routing/summary claims only**.
- **C3** docs match the engine; the `update_component`-`udc` widening is filed separately.
- **C4** the anchored roster guard, both directions.
- **C5** `validate-site.md` is a rewrite — its findings table names no `udc` finding at all.

### Batch 3

- `docs/v2/AI-INSTRUCTION-CONTRACT.md` (this file) — the T8/T9 sibling pattern.
- The 92,000 ceiling stands; the prompt-caching gap is filed separately. The two fixes compose: the
  byte pin makes any future growth a deliberate, argued act.

---

## 3. What implementation changed about the design

Recorded because each was found by probing, and each would have shipped a weaker mechanism.

**The descendant net needed an anchor arm.** Keyed only on "the inner role declares its own
typography", it finds **6 of 13** shipped descendant pairs — all six chrome — and misses **every pair
#1069 was filed about**. The six composable `*-link` roles declare no defaults deliberately; their
obligation comes from `base.css` giving every anchor a direct colour rule. A descendant whose last
compound is `a` therefore counts however little it declares.

**`footer.social -> social-link` is invisible to any selector analysis, and it settles the ruling.**
`components/footer/footer.php:148-152` nests `<a class="site-footer__social-link">` inside
`<ul class="site-footer__social">`, while the two selectors express no containment at all. Found by
reading markup, not selectors. A second blind spot: `.faq__item[open] > .faq__question` does not begin
with `.faq__item` followed by a combinator, so a prefix predicate cannot see that containment either.

**Two claims were dropped after checking specificity rather than assuming it.**
`logos.item`/`item-labeled` are both `(0,1,0)`, so the modifier does not outrank — source order
decides. That is not an obligation.

**The reclamation estimate was wrong, and the ceiling is tighter than planned.** 3.5–4.5 KB was
projected; ~1.5 KB was delivered, because `font-family` and `ratio` describe grammars shared with live
non-slot surfaces (61 design tokens; the v2 `sizing.aspect-ratio` parameter) and deleting them would
have taken a live rule off a live surface. The margin the gate lands on is a few hundred bytes; the budget test prints the live figure rather than pinning a number here that goes stale at the next roster change.

**A count-consistency guard was built and removed.** "A stated count must match the list beside it"
fired four times on the shipped corpus and was wrong all four — prose pairs counts with exclusions,
historical figures, and unrelated clauses — and it would have passed on the very defect that motivated
it. Anchoring is what makes count checking work. The full reasoning lives in
`tests/ModelFacingRosterTest.php` so the next person to propose it finds the result first.

**A latent trap is recorded rather than left to be discovered.** The v1 section is gated on a style
slot existing, and that paragraph also carries **design-token** grammar — colour references,
font-family name shapes, the `token_override_validity` render-drop warning. Design tokens do not depend
on slots. The gate cannot fire while `grid` carries 38 slots, so this is not a live defect, but grid's
rebuild would delete live token grammar as a side effect. A comment sits at the conditional.

---

## 4. The defect this gate found in its own surface

The runtime prompt's worked chrome example set a `#101828` header fill and then put `@color-accent`
(`#3157f4`) on the current-page link at rest and on two hover states.

| value | ratio on `#101828` | |
|---|---:|---|
| `#f7f8fa` — `link` rest | 16.70:1 | pass |
| `#ffffff` — `logo` rest | 17.75:1 | pass |
| **`@color-accent` — `link-current`, `link:hover`, `logo:hover`** | **3.21:1** | **fails AA 4.5:1** |
| `@color-accent-on-inverted` `#9dafee` | 8.28:1 | pass |

The rest states passed, so the example *looked* correct; only the accent states failed — which is why
it survived review, and exactly why the contrast case exists. Verified there is no rescuing mechanism:
`[data-pp-chrome]` appears in `components.css` only as a specificity carve-out.

The same paragraph said **"THE ONE PAIRING THAT IS STILL MANDATORY"** and named one role, while
**eleven** chrome roles declare their own `typography.color`. On that fill the footer's five muted-ink
roles measure **3.08:1**. A count whose roster names one member, in the runtime prompt.

---

## 5. Implementation map

| Task | Subject | Commit |
|---|---|---|
| T1 | closed role key set, `obligations` contract, explicit `$kind` dispatch | `11e3c66` |
| T2 | `obligations` on all 125 roles; 16 verified records | `c8968ec` |
| T3 | derived prompt rosters; fail-safe renderer; chrome-inclusive walk | `01a39ac` |
| T4 | the descendant net with the anchor arm | `85ac6e3` |
| T10 | v1 block derived from live slot types; self-deleting | `b3ef52b` |
| T6, T9 | chrome example fix, derived own-ink roster, byte budget | `f4a5efe` |
| T7, T8 | prompt + instruction-file walks; contrast regression case | `6b62fbe` |
| T5, T12 | anchored roster guard, reverse check, mandate tripwire | `8258fbd` |
| review | testing pass — eight half-blind guards | `2ea8347` |
| review | maintainability pass — seventeen comment and structure defects | `f79f08d` |
| review | security pass — one gate for every composer, the role name bounded | `e16911a` |
| review | performance pass — one registry walk per build | `f40a136` |
| review | simplification pass — six structural findings | `68b6ce2` |

---

## 6. Method

Binding for both PRs. The #1057 lesson applies doubly to prose-heavy work — that issue records three
review cycles at #1046, each fix round introducing new false claims.

- **Every claim about the engine is PROBED, never remembered.**
- **Write-then-verify as separate read-only passes** after every prose stretch.
- **The vacuity probe on every guard**: plant the defect *and* a near-miss, and check the assertion
  count moved. A guard whose count does not change when you break its subject is asserting on an empty
  set. This caught a bug in a *probe* during PR1 — a nested-reference mutation silently did not apply,
  and only the unchanged record count exposed it.
- Both suites before every commit; commit-per-round; no splices.

Baselines at `8e14221`: **PHP 5239 tests / 33884 assertions, 11 warnings, 2 PHPUnit deprecations,
2 skipped; JS 1584 / 36 files.** Warnings and deprecations are unchanged throughout. The final PR1
totals are recorded in the PR body rather than here, because five review passes moved them after
this section was first written and a figure quoted in two places goes stale in one of them.

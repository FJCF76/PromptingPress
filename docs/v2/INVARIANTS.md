<!--
  docs/v2/INVARIANTS.md

  The invariants v2 must keep, harvested from the frozen v1 backlog in a one-pass
  sweep during Sprint 0 (BUILD-SPEC §6). #141 and its pooled issues are the legacy
  archive; anything in them describing a behaviour v2 must preserve was lifted here,
  and anything styling-shaped was NOT — that dies with the v1 styling system.

  This is a CONTRACT, not a backlog. An invariant here is a property every future
  sprint has to keep true; the issue numbers are the evidence trail for why it is
  one, not work to be done.
-->

# §6-READY INVARIANTS — harvested from the frozen legacy backlog

Read of `BUILD-SPEC-v2-sprint0.md` §1/§2/§6, `gh issue view 141` (full body incl. all Part-2 pooled lists and the proposed-v1.21.0 staging section), and all 187 open issues. Working index: `/tmp/claude-1000/-home-wfroot-n5i9y/5580d4e5-577a-4f54-a653-b5ea78890a61/scratchpad/invariant-sweep/classified.txt`.

The four §6 seeds all survive verification and are folded in below (I1, I4, I21, I36, I9/I35).

## A. Write-path & envelope honesty

**I1.** An envelope's `ok`/failure claim is the *verified* outcome of the store it describes — in both directions: no success over a write that was refused, skipped, or never checked, and no failure reported over an ambiguous API return the code did not disambiguate. — #752, #835, #917, #920, #932, #837

**I2.** `ok: true` must state whether it was *authoritatively* verified; a confirmation that could not run is disclosed on the envelope, never silently equated to a confirmed one. — #844

**I3.** Integrity and freshness markers are never computed over a failed encode, and content plus its freshness marker are written atomically or their divergence is detectable. — #948, #944

**I4.** Validation is owned by one gate that *every* ingress path traverses — import, migration, fixture loading, bulk ingestion, seeds included; render-time stays an injection guard and is never widened to compensate for an unvalidated ingestion boundary. — #94

**I5.** A gate keys on the sanitizer's **output**, never its input: a value the sanitizer rejected drives no downstream treatment. — #734

**I6.** A refusal ends the operation on its own terms: no downstream action runs after a refusal, and the short-circuit is a property of the code, not of `wp_die()`. — #939

**I7.** A mutation's success claim is not its render's: a visual/site mutation is not complete until the rendered result is validated in the required contexts. — #27

## B. Concurrency, locks, CAS & consent

**I8.** Every mutating step is CAS-covered by a baseline the conversation actually *earned* for the page it targets, captured from the read the proposal was reasoned against — and a write's returned version always belongs to the page that was written. — #909 (CRITICAL), #911, #794, #718, #787

**I9.** A failed read is never mapped to a valid answer: an unreadable version, composition or history ring never resolves to `0`, empty, or absent, and never authorizes an overwrite of the state it failed to read. — #848, #954

**I10.** Every reader of the same datum shares one reader standard, and every core API call that can return `WP_Error` is guarded — snapshot and restore siblings included. — #849, #953

**I11.** The advisory-lock critical section fails rather than self-heals onto an unlocked connection, and a death inside it leaves a PromptingPress-attributable trace. — #943

**I12.** Reversibility is a property of the *shared mutation boundary*, not of one entry point: every mutation reachable from any surface has a usable rollback baseline, and a mutation whose effect is site-wide requires site-scoped preflight coverage. — #393, #690

## C. Rollback, recovery & change records

**I13.** A rollback restores only what the run actually wrote, is CAS-aware against concurrent external writes, goes through the validating writer, and reports everything it did, failed to do, or left behind — with the findings report constructed so a crash cannot strand the record of a change that already landed. — #405, #746, #918, #919, #772

**I14.** Declared semantics hold or the operation refuses — no silent degradation of a documented semantic — and the change record reports the real before-state, read inside the same lock as the write. — #916, #928, #845

**I15.** Preview promises exactly what execute delivers. — #929

## D. Corrupt state, preserved bytes & no-fatal

**I16.** Corrupt state is classified as corrupt, its bytes preserved, and a repair/undo route named: no corrupt page is described as "empty" or routed to overwrite, no missing target is described as an empty one, and **no action reachable from CLI or chat fatals on a page the classifier calls healthy**. — #817, #946, #774

**I17.** No surface fatals on stored data. Readers, validators, diagnostics, summarizers, sanitize callbacks, editor boot and the public render boundary degrade with a stated refusal instead of a TypeError/500 — and a diagnostic survives the corruption it exists to report. — #693, #733, #740, #768, #800, #802, #810, #813, #913, #931, #935

**I18.** No stored non-scalar ever reaches the page as content: typed render guards on every prop reaching output, on every component. — #721, #736

**I19.** Nothing validates green and renders nothing: every accepted shape is owned by a rule, ID-typed values are checked for referential existence, and structured data emitted to machines matches what is rendered to humans. — #703, #770, #803

## E. Model-facing truth (the chat arc)

**I20.** A write that really happened always has a reachable report: ending a conversation never discards the record, the rollback disclosure or the undo route of an in-flight write, and never injects hidden turns about it into the next conversation's model context. — #910

**I21.** What is narrated to the model equals what was authorised *and verified applied*; no stale cached state reaches the model's page context within a request. — #922, #826

**I22.** Envelope readers are hostile-shape safe — own-property checks, shape guards, missing-key degradation — so no planted or absent field can suppress a refusal card, forge a conflict card, or throw after a turn has already been pushed. — #924, #926

**I23.** Stale is marked stale: post-rollback locators, spent affordances and superseded warnings are distinguishable from live ones and never present as current. — #792, #879, #936

**I24.** Every failure and refusal reaches the operator with a stated reason and a route back — nothing is silently swallowed, left permanently frozen, or spends its only affordance on a failed attempt. — #783, #784, #786, #859, #878

**I25.** One event, one severity: a surface never contradicts its own classifier, and server and client never render contradictory claims about the same step. — #664, #775, #925

## F. Diagnostics & locator vocabulary

**I26.** No fabricated locators, component names or step numbers: every locator a surface prints is one the system actually wrote or can name; unknown is stated as unknown. — #758, #789, #867, #923

**I27.** One canonical locator vocabulary per concept across every surface, and a finding is reported once. — #714, #720

**I28.** One shared refusal-envelope builder: every refusal — preview dry-runs included — carries `error_code` and a locator, and an error message describes the input it refused, never a re-read of current state. — #711, #659

**I29.** Diagnostics are complete and fail-closed: a validator never reports clean when it could not read, parse or reach what it was asked about; the write-path gate and the rendered validator agree on the same question; exit codes reflect the verdict. — #764, #762, #763, #771

## G. Schema, grammar & contract truth

**I30.** Every value-bearing input has a declared grammar: no value may report success and then silently no-op, and no value may escape its emission context (the injection gate is the shared core). — #531

**I31.** What the schema advertises is exactly what the write and render grammars accept; every declared surface sits inside the schema-contract sweep and is reported to the AI; and the system never ships a default its own validator rejects. — #674 (ruled), #694, #672, #673, #900

**I32.** One canonical definition per addressable entity across every surface that discovers, lists, or addresses it — a page the commands can address appears in the map that documents addressing. — #680, #691

**I33.** One canonical value shape per typed field on the write path; the write path never holds two definitions of the same field. — #644 (ruled: `format: "attachment_id"`)

**I34.** Reject, never coerce — symmetrically across types — and an unaddressable or malformed addressing parameter is refused, never coerced into a valid-looking target reported clean. — #769, #760

**I35.** No declared authoring input is silently ignored or silently cancelled by another mechanism; where two mechanisms can reach one outcome, one owns it and the envelope discloses when a submitted value cannot take effect. *(Directly binding on the §3.4 cascade contract.)* — #908

**I36.** No hidden one-way aliasing at any layer, cascade included: a rename is a documented breaking change, not a silent migration, and no lint may sanction a re-point that behaves as an alias for stored values. — #681 (ruled 2026-08-16: the re-point IS the forbidden alias)

## H. Reflected text

**I37.** Reflected text has one owner: every channel reflecting stored or author data is bounded (encoding-explicit) and escaped at its sink through the single shared cleaner — or is recorded as a deliberate exclusion in the inventory. No hand-copied truncation idioms, no raw interpolation into a formatted surface, no stored value reaching output unescaped for its context. — #937, #866, #868, #808, #938, #914

## I. Editor round-trip fidelity

**I38.** Authored ≠ default: a write records only what the author actually set, and an editor round-trip never invents, blanks, or retypes data the author did not touch; form and buffer never disagree about what is stored. — #809, #815, #806, #807, #814

## J. Engineering-system invariants (kept by §5, not by the engine)

**I39.** Test-harness fidelity: a harness divergence from WordPress core never decides a behavior production does not exhibit, and a staged fixture never manufactures a symptom against a correct writer. *(§6 seed — the #831 staged-write discipline.)* — #831, #731, #801, #950

**I40.** Suites are order-independent, and a test's name never promises coverage its assertions do not pin. — #656, #819, #933, #668

**I41.** Site expression stays DB-stored: nothing an author or the AI expresses lives in theme-owned files a release upgrade overwrites. *(Already §2 policy — #72 is the evidence it was violated once.)* — #72

**I42.** Release-artifact integrity: the package validator rejects duplicate entries, symlink entries and unsafe archive names. — #264

**I43.** Shipped and AI-facing documentation states the real contract — refusal sets, action counts, capability claims are derived from or checked against the registry/source, never hand-maintained prose that drifts. — #754, #796

---

# DIES WITH V1 STYLING (39)

60, 166, 384, 500, 505, 541, 555, 556, 570, 571, 574, 586, 587, 588, 590, 591, 592, 609, 610, 618, 639, 658, 670, 678, 698, 727, 776, 863, 892, 893, 894, 895, 896, 899, 901, 903, 905, 906, 907

Notes: #901 is the Sprint-0 acceptance case itself. #900/#906 (the T6 grammar pair) and #610 (measure slots can't take a token reference) are answered outright by §3.3's unified grammar owner and §3.1's `@name` references — #900 is nevertheless kept above as an invariant carrier (see judgment calls). #907/#903 are the archetypes the §2 structural-CSS boundary lint exists to prevent. #570 stays open as the v1 decision record; its 27 rulings are styling-baseline rulings and do not re-enter (the two non-styling ones — the no-alias posture and the site-wide-retheme class of need — are carried by I36 and by §3.4).

# ORTHOGONAL-DEFERRED (27)

49, 54, 59, 81, 498, 508, 569, 572, 573, 589, 613, 646, 675, 684, 689, 778, 779, 780, 781, 785, 791, 798, 860, 891, 902, 904, 949

Nature: i18n (#904, #949), advisory acknowledgment (#902, #684), editor UX (#646), chat-pane presentation CSS (#778/#779/#780/#781/#791/#798 — admin chat styling, *not* the component styling system, so it does not die with v1), a11y delivery (#860), dev/test tooling (#54, #81), dead code (#675, #785), CLI DX (#49, #689), features and content-model demands re-asked at rebuild time (#59, #508, #569, #572, #573, #589, #613, #891), one ops chore (#498).

# PROGRAM / NOT CLASSIFIABLE (4)

141 (the frozen archive itself), 958, 959, 960 (the Sprint-0 issues).

---

# JUDGMENT CALLS

1. **#900 — classified INVARIANT, not DIES.** Its concrete unit gap (`ch` rejected) is already answered by §3.3, but the *shape* of the bug — a validator rejecting the units the system's own shipped defaults are written in — is a self-consistency rule the new unified owner can reproduce on day one. Kept as I31's second clause.
2. **#908 — classified INVARIANT, not DIES**, though hero width is a v1 mechanism. It is the sharpest recorded statement of "six mechanisms, three of which silently cancel the slots", and the §3.4 cascade (site tokens → role defaults → band udc → breakpoint → hover) is exactly where that failure reappears. Its same-axis siblings #609/#905/#610 are listed as DIES because their specific mechanisms are deleted.
3. **#664 / #775 — classified INVARIANT** despite living on CSS swatches and the slot-`alternatives` payload. The harvested truth is "a surface never contradicts its own classifier, and server and client never disagree about whether a step is possible" (I25) — the classifier vocabulary survives the pivot even though `alternatives` does not.
4. **#72 — classified INVARIANT** even though it reads as a migration chore. It is the empirical proof of §2's "theme = release artifact / all site expression DB-stored" policy; recorded so the policy has evidence behind it.
5. **#27 — classified INVARIANT** (I7) although it is an umbrella epic. Its completion rule ("a mutation alone is not success") is the parent of the entire post-apply rendered-validation surface and is unaffected by the styling architecture.
6. **#668 / #656 / #819 / #933 — classified INVARIANT (I40)** rather than orthogonal test hygiene, because §5 keeps the truth-spine suites and #960 reprices them; an untruthful or order-dependent test is the mechanism by which a truth invariant silently stops being enforced. Note #141 records an unresolved maintainer question on whether order-independence is a *wanted* property — I40 asserts it is; that is still ratifiable either way.
7. **#860 — classified ORTHOGONAL**, not INVARIANT: the truth already reaches the visual surface; only AT delivery is missing. #859 (a whole refusal class never rendered at all) is INVARIANT because the truth reaches nobody.
8. **#791 — classified ORTHOGONAL** with reservation: two of its three defects are pure geometry, but "the truncation notice looks like a finding" is a truth defect. If the UDC work re-renders the findings rows, that third clause belongs under I23.
9. **#498 — classified ORTHOGONAL**, but under the §2 fresh-build/no-migration policy it is moot (the install is rebuilt) — recommend closing it outright rather than carrying it.
10. **#589 — classified ORTHOGONAL** (a component content-model design question), though it contains a reject-never-coerce instance (a scalar row silently cast to a one-cell row). That instance is covered generically by I34; the shape question itself is re-asked when `table` is rebuilt.
11. **#570 / #141 asymmetry:** #570 is DIES (it is the v1 styling-baseline decision record); #141 is PROGRAM/META (the gate archive). Both stay open as records; neither re-enters as work.

---

# COUNT CHECK

- Open issues enumerated: **187** (`gh issue list --state open --limit 300`, 2026-09-13).
- INVARIANT-classified: **117** (across 43 invariants, I1–I43)
- DIES WITH V1 STYLING: **39**
- ORTHOGONAL-DEFERRED: **27**
- PROGRAM/META: **4**
- 117 + 39 + 27 + 4 = **187** ✓
- Verified by set-diff against the live open list: **zero duplicates, zero unclassified, zero classified-but-closed.**

Two sharp items for the orchestrator's attention beyond the list: **I8 (#909)** is the only invariant in the sweep whose violation converts a server-side *refusal* into a server-side *acceptance* — it is the consent gate itself, it is persisted to localStorage, and §7 currently defers #909 to Sprint 3 or post-2.0.0; the invariant should be pinned in the Sprint-0 truth-spine slice (spec §4.5 already stages a CAS conflict) even though the fix is deferred. And **I35/I36** are the two invariants that bear directly on §3.4's cascade contract as written — they are the ones the contract-boundary review in §4.8 should be pointed at.

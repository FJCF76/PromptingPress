<!--
  docs/v2/LAYER-2-CONTRACT.md

  The Layer-2 contract that BUILD-SPEC-sprint0.md §7 points at ("breakpoint-keyed
  declaration lists, PP-owned property/function ALLOWLIST ... each with its own
  contract review"). Written under issue #1079, BEFORE any implementation, and
  reviewed by /plan-eng-review in the same pass.

  STATUS: DRAFTED. NOT IMPLEMENTED. Q1, Q2 and Q3 RULED (§8.R); Q0 OPEN.
  §8 carried four questions. Three are answered and recorded in §8.R. Q0 — whether
  Layer 2 should be built at all — is with the owner, because the demand evidence
  in §0.5 says its allowlist has no member that passes the admission POLICY. This file is the record of that contract and that evidence,
  so whoever builds Layer 2 (this sprint or a later one) starts from the answers
  rather than re-deriving them, and so the reason it was deferred (if it is) is
  written down rather than remembered.

  Read BUILD-SPEC-sprint0.md §3 and Addendum A first; this contract sits beside
  them and reuses their vocabulary throughout.
-->

# PromptingPress v2 — the LAYER 2 contract (issue #1079)

`docs/v2/BUILD-SPEC-sprint0.md` §7 names Layer 2 in one line — *"breakpoint-keyed declaration lists, PP-owned property/function ALLOWLIST … each with its own contract review"* — and the approved design doc gives it a paragraph. **Neither is a contract.** This body IS the contract, written before any code, and it is a **7A to the maintainer**.

**Nothing here is implemented yet, and §8 asks whether it should be.** Writing the contract was supposed to settle *how* Layer 2 works. It did — §1-§7 are implementation-ready — but the evidence gathered to size the allowlist says something the task did not anticipate, and it is reported first because it is the more important result: **Layer 2's allowlist has almost no cited subject, and the escape pressure v2 has actually measured is selector pressure, which Layer 2 is selector-free by definition.** §0.5 is that evidence. §8 Q0 is the fork it forces.

---

## 0.5 — What the demand evidence says (and why it makes Q0 the first question)

An evidence sweep ran over both shipped stylesheets, the open backlog (the ten likeliest candidates read in full, ~80 more triaged), all **223** retired v1 style slots across nine rebuilt components (cross-checked against `PINNED_SLOT_BASELINE`, tests/SchemaValidationTest.php:5089-5460, **and** against the nine rebuild commits), and the approved design doc's own coverage table. Seven results, each verified:

**(a) The designable-but-homeless property set in the shipped stylesheets is ONE property, and the repo has already ruled it out in writing — eight times.** Both stylesheets were parsed with a comment-stripping scanner, every declaration attributed to its `COMPONENT:` banner block, and each classified against the exact boundary sets at tests/js/css-lint.test.js:2838-3013 and `designOffencesIn()` at :3342-3404, against the 58 properties `pp_udc_groups()` emits. What survives is `opacity` — plus `text-underline-offset` (base.css:462, site-global, no issue asks for it) — and nothing else that is not a shorthand/longhand duplicate already covered (`background-color`, `border`, `text-decoration`). **Zero spacing-family offences inside any v2 component block.**

And `opacity`'s two occurrences are **mechanism, not design**: the `@keyframes faq-open` body at components.css:1309/1313, already exempted by `isKeyframeBody()`. Its absence is a **documented deliberate non-gap**, not an oversight:

> *"**`opacity` has no group.** Write the composite colour (Step 4). **This is deliberate, not a gap waiting to be filled** — see the token note in `base.css`."* — docs/howto-migrate-a-stats-band-to-v2.md:239-240

The same statement ships at docs/howto-migrate-a-logos-band-to-v2.md:194 (and :117/:138 — *"`opacity` is in none of the seven UDC groups"*), components/stats/README.md:105-109, components/logos/README.md:103 and :158, ai-instructions/style-component.md:403 and :544, and ai-instructions/retheme.md:113-123 — on top of base.css:145's measured contrast-budget argument (*"the previous `opacity: 0.85` … measured 3.87:1 … **do NOT re-introduce an opacity literal**"*). **Allowlisting `opacity` contradicts eight shipped statements, two of them in the AI's own instructions.**

**(b) `opacity`'s designated home in the approved design is a Layer-1 GROUP, not Layer 2.** The design doc's coverage table (verified at lines 71-82) lists *"Filters & Blend | hue/sat/brightness/contrast/**opacity**/blur; mix-blend-mode | 2nd"*. Allowlisting it here would take a property out of the home the approved design gave it — and then §2.3's promotion clause makes moving it back a breaking change, for nothing.

**(b2) The ONE property with genuine, cited Layer-2 demand is `transition-property`** (#1041 instance 2 — the #540 snap, on the role's own element). It cannot be allowlisted either: ruling **A3 fixed the motion group at exactly two params** (lib/udc.php:677-690) and the css-lint boundary claimed it into `STRUCTURAL` with a written reason (:2870-2877). Reaching it is an **A3 widening**, which is your ruling, not an allowlist entry.

**(c) The GROUP-HOME test disqualifies every other candidate.** Asked of each one, *"does this property have a natural home in an existing or 2nd-cut group?"* comes back yes every time — `text-indent`/`word-spacing`/`text-underline-offset` → Typography, `z-index`/`overflow` → Position, `transform` → Transform, `object-fit` → Sizing, `align-*`/`grid-template-columns` → Layout. The per-candidate verdicts are §2.5.

**(d) The gaps against the approved design's own coverage table are group-shaped, and one of them is FIRST CUT.** Comparing the table to the seven groups that shipped:

| design-doc row | cut | shipped? |
|---|---|---|
| **Layout** — *"declared per-component flex/grid parameters (columns, gap, orientation, wrap) — grammar shared, exposure declared"* | **1st** | **NO group exists, and this is the largest recorded gap in the program.** Four shipped code sites route around it in writing — hero.php:183, section.php:231, cta.php:150, and section/schema.json:121-128 where `--section-inline-items-align` was demoted to a PROP rather than given a group. Open demand: #658 (`align-items` on `.section__grid`, `align-self` on `.section__panel` — **both role-selected**), #588 (`object-fit`, `grid-template-columns`), #905 (*grid's `columns` silently ignored on `layout="steps"`, a four-across process band is inexpressible*), and grid's own rebuild |
| **Sizing → "alignment"** | **1st** | shipped without it |
| **Typography → `text-shadow`** | **1st** | **NO param exists** (`shadow` carries `box` only); zero cited demand |
| Filters & Blend, Transform, Animation, Position | 2nd | no groups exist (as scheduled) |

**(e) The set of properties with NO group home is, by the repo's own rulings, the set that must not be authorable.** This is the sharpest result of the sweep. Asked of every flagged property, *"does this belong to no design family?"* comes back yes for exactly nine: `overflow-wrap`, `cursor`, `outline`/`outline-offset`/`outline-color`, `border-collapse`, `caption-side`, `-webkit-overflow-scrolling`, `overscroll-behavior-x`, `transition-property`, and the `list-style` family. **Every one of those nine has already been claimed into `STRUCTURAL`** with a written reason (#994's H1-H3, #1023 for `overflow-wrap`, #1046 for `animation`, #1066 for the five table-markup properties). So §2.4's one-home rule disqualifies precisely the set §2.3's disjointness rule would have admitted. **The property-escape valve's subject set is empty, and it is empty by construction rather than by accident.**

**(f) The escape pressure v2 has measured is SELECTOR pressure, in FOUR distinct dimensions — and Layer 2 reaches none of them.** Stated precisely, because the outside review rightly pushed back on the stronger reading: this shows Layer 2 **should not be sold as solving the biggest current pain**. It is a different problem, not a disproven one. Verdicts on the ten issues most likely to be Layer-2 demand: eight are selector demand or engine-vocabulary work, one is a value-grammar gap, one is a prop-emission bug. The dimensions:

| dimension | evidence | Layer 2 reaches it? |
|---|---|---|
| pseudo-element | all **four** capability deletions across the 223 retired slots are `color` on a `::before`/`::after`; plus #1028, #384. testimonials/README.md:128 records one deletion in terms: *"Sprint 0's taxonomy has no generated-content group, so it was removed rather than reproduced"* | No — §6.3 |
| descendant of authored rich text | #1049, #1068 (`.faq__answer ul`, `.embed__content ul`: base.css:384 resets, components.css:1063-1067 restores only `.section__content`) | No — §6.1/§6.15 |
| per-item ordinal grain | #1024, #727 | No — §1.4 |
| container / sibling | #592, and #1071 needs `a:not(.btn)`, which the role-selector charset (lib/udc.php:3022) admits no `:` or `(` for | No — §6.1 |

**And two of the "selector" items are not layer work at all — they are missing schema lines.** #1069 (*no role can reach a link inside a rich-text surface; the documented dark-panel write ships a 3.21:1 link*) is reachable **today**: the selector charset admits space and dot, and section and cta already ship `.section__content a` / `.cta__body a`, so faq/hero need **two schema lines**. #1032 (`.section__body` measure) is **one** missing role declaration. Both carry measured defects and neither needs an engine change.

**(g) The one genuinely Layer-2-shaped demand is a VALUE grammar, not a property** — and §2.3 excludes it. #1041's focus halo needs a multi-layer or inset `box-shadow`; `box-shadow` is already emitted and the refusal is the grammar's (lib/apply.php:1669: *"single-layer… No inset, multi-layer, or url()"*). #505/#639 want repeating gradients on `background.fill`, same shape. Both properties are **registry-owned**, so disjointness puts them out of Layer 2's reach by the very rule that makes Layer 2 safe. If there is an escape worth building, the evidence says it escapes the **grammar on an existing param**, not the property set (§8 Q0 Option 5).

**So the honest summary is:** Layer 1's property coverage is better than the design doc assumed when Layer 2 was scheduled; the properties still homeless are the ones the repo has ruled must stay unauthorable; the largest recorded gap is an **unbuilt first-cut group** nobody is tracking; the pressure actually escaping the grammar is selectors in four dimensions and value grammars in two; and Layer 2 as specified addresses none of it. A property-escape valve built today would be real machinery — a write-gate seam, an emitter seam, a mint-namespace fix, an AI-surface paragraph, docs, and a new permanent security boundary — pointed at a pressure the evidence does not find.

That is not mine to decide. §8 Q0.

---

## 0 — What Layer 2 is, in one paragraph

Layer 1 (the UDC engine, `lib/udc.php`) lets an author reach a CSS property only through a **group** and a **param**: `quote.typography.size`. The property string is looked up from `pp_udc_groups()` and **never taken from author input** (lib/udc.php:474-478). That closed registry is the whole security posture of the styling system, and it is also its ceiling: a designable property that belongs to no group is unreachable from any surface, which is the #901 class the UDC exists to end.

Layer 2 is the flat residue. An author names a **CSS property directly**, from a PP-owned **allowlist**, at the same role/`_band` grain and with the same breakpoint and state dimensions Layer 1 has. There are no selectors, no at-rules, no pseudo-elements: the role's own selector is the only selector, and the engine still emits every `@media` wrapper itself.

**The allowlist is the security boundary of this layer**, and §2 is therefore the longest section.

---

## 1 — STORAGE

### 1.1 Where it lives: inside `udc`, at role grain, under `_css`

```json
{"component":"section","id":"pp-3f9a1c2e","props":{…},
 "udc":{
   "_tokens":{"body-css-opacity-p":"0.6"},
   "_band":{"_css":{"opacity":"0.9"}},
   "body":{"typography":{"size":"1rem"},
           "_css":{"text-indent":"1.5rem",
                   "opacity":{"d":"1","p":"@body-css-opacity-p"},
                   ":hover":{"opacity":"1"}}}
 }}
```

`_css` sits **beside the groups inside a role map**, and it is a *pseudo-group whose params are CSS property names drawn from the allowlist instead of from the registry*. That single sentence is the whole design: every other property of Layer 2 follows from it.

### 1.2 Why not a top-level item key, and why not a prop

The approved design doc says *"One optional `custom_css` prop on the shared contract"*. Both alternatives are rejected on verified repo facts:

- **A top-level item key (`{"component":…,"udc":…,"css":…}`) is not gated by anything.** `pp_validate_composition*` never iterated an item's own top-level keys — lib/admin.php:4138-4142 records this verbatim: *"nothing in this validator ever iterated an item's own top-level keys, so before v2 an item carrying `udc` was accepted, stored and ignored."* A new sibling key would be **accepted, stored and ignored**, which is the reported-success-without-effect class (I35) arriving at the moment of introduction. A key *inside* `udc` is gated by construction: `pp_udc_validate_map()` iterates every key and refuses an unknown one with `unknown_udc_role` (lib/udc.php:2046-2057).
- **A prop is the wrong grain and the wrong authority.** Props are content, validated per-component by schema; v2 deleted the per-component `style` map precisely to stop styling living in per-component surfaces (BUILD-SPEC §3.1). A shared styling channel declared as a prop would be a per-component declaration of a structural capability, which constraint 1 of the design doc forbids.

### 1.3 What riding inside `udc` buys, itemised

Each of these is inherited, not rebuilt: **CAS/freshness, undo, the history ring, rollback, and the preview/approve diff** (`_css` is inside the composition value, so `pp_composition_content_hash()` and the ring cover it whole); **the write gate** (`pp_udc_validate_map()` already walks every key of every role map); **band-token minting and GC** (`_tokens` lives beside the roles and dies with the band); **emit-time re-validation of stored data** and **the emit drop ledger** (both in `_pp_udc_place()`, bounded at `PP_UDC_MAX_EMIT_DROPS`); and **chrome** — verified by read rather than assumed: `pp_udc_validate_site_map()` calls `pp_udc_validate_map($value, $key)` under the comment *"THE SAME ENGINE. Not a chrome-flavoured copy of it."* (lib/udc.php ~4593), so `_css` reaches nav and footer through the identical path and the §1.5 permission bypass applies there unchanged (pre-existing limit: chrome has no findings channel, #993).

### 1.4 Grain: role and `_band`. **Not** item.

`_css` is valid on every declared role and on `_band`, and **nowhere else**. Item grain (a `_css` on one card of `grid.items[]`) is **excluded pending Addendum B** — the item-grain UDC ruling that #1024 gates grid's rebuild on. Shipping an item-grain escape here would pre-empt a ruling the maintainer has not made.

### 1.5 Every role permits `_css`; there is no schema opt-in

Stated as a decision because the alternative is tempting. A per-role `"custom": true` schema flag would let a component withhold the valve — and the valve's whole purpose is to be reachable *exactly where the grammar fell short*, which is by definition not predictable when the schema is written. A withheld escape recreates the #901 wall one level up.

The cost is named: **a role's `groups` list stops being a complete statement of what can be set on that role.** §2.3's disjointness rule is what keeps that cost bounded — the `groups` list stays authoritative for everything Layer 1 owns, and `_css` can only ever reach properties Layer 1 owns *nothing* of.

`_css` is **not** a member of `roles.<name>.groups`. Putting it there would feed it to the preset intersect predicate (`_pp_udc_split_preset_by_permitted()`), and presets must not carry `_css` (§6.13).

And `_css` must **not** join `pp_udc_reserved_keys()` either, for exactly the reason that list's docblock gives `_preset` (lib/udc.php:346-352): those keys are skipped as *top-level* non-role keys, so adding `_css` there would make a role literally named `_css` **skip validation entirely** — the opposite of what it needs. `_css` is a key inside a role map and is handled at that grain.

---

## 2′ — ADMISSION UNDER R2′ (broad by default) — THIS SUPERSEDES §2.2-§2.5

§2.1 (one owner, one params-table seam) and §2.6 (the function allowlist already exists,
per type) **survive unchanged**. Everything between them was written under the
cited-demand policy and is replaced by this section. §2.2-§2.5 are kept below because the
arguments in them are still the arguments — they simply lost on the freedom question, not
on their own terms.

### 2′.1 THE PROPERTY NAME IS NOW AUTHOR INPUT, AND THAT IS THE WHOLE SECURITY CHANGE

Under Layer 1 the property string is **looked up from `pp_udc_groups()`, never taken from
author input** (lib/udc.php:474-478) — the registry's own docblock says an author *"can no
more influence the property text than they can invent a role"*, and contrasts it with v1,
which emitted the author's slot key as the property name behind nothing but an `isset()`.

**R2′ removes that protection by design.** An open property set means the author writes the
property, and the engine interpolates it into CSS **source text** inside a `<style>` block.
So the property name gets its own hard gate, and it is the single most load-bearing new
line in this layer:

```
^-?[a-z][a-z0-9-]{0,63}\z
```

Read exactly:

- **`\z`, not `$`** — PCRE's `$` matches before a trailing newline, so `$` would admit one
  byte the charset does not name. Every sibling gate in `lib/udc.php` is anchored this way
  and this one is interpolated into a selector-adjacent position, so it has to be exact.
- **lowercase ASCII only.** CSS property names are case-insensitive, so `Color` and `color`
  are one property with two spellings — and two spellings defeat the collision detection in
  §2′.3, which is keyed on the string. **Refused, not lower-cased**: I34 is reject-never-
  coerce, and the refusal names the lowercase form so the fix is obvious.
- **an optional SINGLE leading hyphen**, which admits vendor prefixes (`-webkit-line-clamp`)
  and excludes custom properties: `--x` puts a hyphen where the pattern requires a letter,
  so it cannot match. That is §6.0's first exclusion enforced by the charset itself rather
  than by a list that could be forgotten.
- **bounded at 64**, the same bound the token-name and preset-name gates use.

No byte outside `[a-z0-9-]` reaches the sheet as a property. Not a brace, not a semicolon,
not a colon, not whitespace, not a comment delimiter — the gate is an allowlist of
characters, so nothing has to be enumerated as forbidden.

### 2′.2 ADMISSION: everything the charset accepts, minus §6.0

There is no property list to maintain and no demand test to pass. A property is admitted
when it clears §2′.1's charset and is not one of §6.0's named exclusions. **The freedom
guarantee is the default; the exclusions are the argued exceptions.**

### 2′.3 THE COLLISION §2.3's DISJOINTNESS RULE USED TO PREVENT — ranked, and disclosed

Layer 2 may now name a property a group already emits, so `_css` and a Layer-1 param can
resolve to the same `$resolved[$state][$bp][$property]` coordinate. One of them has to win,
and a silent overwrite is the I35 class this contract was built to avoid.

**`_css` WINS, and the overridden value is DISCLOSED.** The rank follows the frame: Layer 2
is raw-CSS parity with Divi's custom-CSS box, and that box wins over the structured
controls beside it. It is also the only rank under which the valve works — an escape that
loses to the thing it is escaping cannot escape anything.

The disclosure is what keeps it honest: a new `udc_css_overrides_group_value` finding names
the role, the property, the group/param that lost, and the state and breakpoint. The
authoring principle the AI surface teaches (§7′) is the other half — **structured first,
`_css` for what structure cannot say** — so the finding reads as "you wrote both; here is
which one painted", not as a refusal.

**Role `groups` permissions stop bounding what a role can be given.** That is a real
consequence of R2′ and it is stated rather than buried: a role that does not permit
`shadow` can still be given a `box-shadow` through `_css`. The `groups` list remains
authoritative for the STRUCTURED surface — what the schema advertises, what a preset may
apply, what the catalog lists — and no longer for the raw one.

### 2′.4 TYPED WHERE KNOWN, VERBATIM WHERE NOT — and the disclosure that makes it honest

Per R1′, the value path is:

| the property is… | validation | emission | disclosed? |
|---|---|---|---|
| one a `pp_udc_groups()` param emits | the security gates **+ that param's full typed grammar** | as Layer 1 | only if it overrode a group value (§2′.3) |
| any other admitted property | the security gates **only** | **verbatim** | **yes — `udc_css_unchecked_property`** |

`udc_css_unchecked_property` is the design doc's `custom_styling_conventions_only` in
honest form, and the difference is that it is now **true on every band it fires on**. R1
refused to ship that code name because under a typed-everything reading nothing would have
been conventions-only and the finding would have lied. Under R2′ there is a real
unchecked-beyond-security set, so the disclosure has a real subject. It is also the
telemetry the design doc's ladder wanted, arriving as a by-product rather than as a
separate mechanism: a count of these findings is a count of escapes.

**`@references` are the one place this is strict** (R1′.3): an `@name` needs a declared
type for `_pp_udc_reference_check()` to judge it against, so a reference on an untyped
property is **REFUSED**, naming the property and saying a literal is accepted there. This
is the check R1 existed to protect and it survives the reversal intact.

---

## 2 — THE ALLOWLIST as first drafted (§2.1 and §2.6 stand; §2.2-§2.5 superseded by §2′)

### 2.1 One owner, beside the unified grammar

`pp_udc_css_properties()` — a new registry function in `lib/udc.php`, sitting beside `pp_udc_groups()` in the same file, with the same shape per entry:

```php
'opacity' => ['property' => 'opacity', 'type' => 'number', 'signed' => false,
              'max_values' => 1, 'keywords' => []],
```

**The entry shape is identical to a `pp_udc_groups()` param on purpose**, because that is what lets one seam serve both:

```php
_pp_udc_params_for_group(string $group): ?array   // registry group's params, or the allowlist
```

The write gate and the compiler both resolve their params table through that one function. **Two tables, one predicate.** This is the A3 sub-ruling 4 discipline (*"The write gate and the emitter intersect through one predicate"*) applied to the allowlist, and it is what makes §4.3's mismatch class unreachable rather than merely tested-for.

**The seam is four lines, and both halves are already adjacent** — verified by read, so the "one predicate" claim is checkable rather than aspirational:

| side | today | becomes |
|---|---|---|
| write gate | `if (!isset($groups[$group_name])) { … unknown_udc_group … }` (lib/udc.php:2330) and `$params = $groups[$group_name]['params'];` (lib/udc.php:2359) | resolve through `_pp_udc_params_for_group()`; `_css` additionally skips the `$permitted` membership check at lib/udc.php:2339, per §1.5 |
| compiler | `if (!isset($groups[$group_name]) \|\| !is_array($group_map)) { … ledger … }` (lib/udc.php:~3167) and `$params = $groups[$group_name]['params'];` (lib/udc.php:~3182) | the same resolver |

**The resolver must be a static-cached lookup, never a per-call merge (P1).** The compiler's group loop runs per group, per role, per band, per source, on every front-end request, and this file documents that constraint three times over — the `$note` closure is *"BUILT ONLY WHEN SOMEONE IS COLLECTING"*, `_pp_udc_sort_declarations()` fast-paths `count < 2`, `_pp_udc_delimiters_balanced()` fast-paths `strpbrk`. `pp_udc_groups()` is itself `static`-cached. A resolver that built or merged a table per call would add an allocation to the hottest path in the engine to serve a lookup.

Everything downstream of those two assignments — `_pp_udc_validate_param()`, `_pp_udc_validate_scalar()`, `pp_udc_validate_value()`, `_pp_udc_place()`, the drop ledger, the `@reference` resolution, the breakpoint and state walks, the minting — is **untouched**. The compiler's existing drop reason for an unrecognised group (*"there is no such group in the design vocabulary"*) already covers a stored `_css` on a build that does not know the key.

### 2.2 The property allowlist is TYPED — it is not a bare name list

This is §8's first question and the departure from the approved design doc, so it is argued rather than asserted.

The design doc says Layer-2 declarations are *"conventions-checked, not proof-checked"*: parse it, check the property is allowlisted, check the functions, and **do not type-check the value against the property**. That is exactly the shape four of this repo's own invariants forbid:

- **I19** — *"Nothing validates green and renders nothing."*
- **I30** — *"Every value-bearing input has a declared grammar: no value may report success and then silently no-op."*
- **I31** — *"What the schema advertises is exactly what the write and render grammars accept."*
- the **#570 ruling-6 convergence rule** — *"anywhere the write-accept set and the render-reject set diverge, the value is REJECTED at write"* (quoted at lib/apply.php:1502-1506).

An untyped `opacity: "3rem"` is accepted, stored, reported `ok: true`, emitted, and silently dropped by the browser. That is a **new instance of the class #1048 is open against**, manufactured deliberately, on the same channel — which the task frame forbids in terms.

A second, quieter cost, worth naming because it is not obvious: **`@reference` checking is only possible against a declared type.** `_pp_udc_validate_scalar()` resolves an `@name` then calls `_pp_udc_reference_check($resolved, $param)` (lib/udc.php:2600) to refuse a colour token on a length param — the accepted-but-dead class rejected since #230, one predicate by ruling D3 (#972). An untyped `_css` has no `$param` to check against, so every Layer-2 `@reference` would paint or not paint silently. Untyped does not skip a check; it deletes one.

So: **every allowlist entry carries a `type` from the one shared grammar owner in `lib/apply.php`** (`length`, `length-or-none`, `color`, `number`, `duration`, `ratio`, `position`, `gradient`, `shadow`, `timing-function`, and the typed keyword sets). No new validator, no second grammar, no per-property special case in `lib/udc.php` — the repo architecture rule (*"validation rules live in the shared engines … never add a surface-specific second validator"*) holds unchanged.

**What Layer 2 therefore is, and is not.** Not a looser grammar. Its escape value is the **property set** and the **flat grain** — a designable property belonging to no design family, reachable on any role without inventing a group. A novel *value shape* still needs the shared owner to learn it, which is correct: that owner is what keeps `_pp_forbidden_css_construct()` and the hardened number body in front of every value in the program.

A pressure valve exists if the evidence demands it — see §8 question 1, option C.

### 2.3 DISJOINTNESS: the allowlist and the registry share no property, by shorthand family

**The rule.** A property is admissible to `pp_udc_css_properties()` only if **no `pp_udc_groups()` param emits it and no `pp_udc_groups()` param emits a property in the same CSS shorthand family.**

**Why by family and not by exact name.** `background.fill` emits the `background` *shorthand*, which resets every `background-*` longhand — `_pp_udc_property_rank()` exists precisely because that ordering is load-bearing. Allowlisting `background-attachment` would collide by cascade while passing an exact-name check, and since the resolved map is keyed by property string (`$resolved[$state][$bp][$property]`) nothing would notice. Same for `border`, `margin`, `padding`, `border-radius`, `list-style`, `text-decoration`, `transition`, `font`.

**What disjointness buys, and this is the load-bearing claim of the whole contract:**

1. **No collision is possible at any coordinate.** `_css` resolves into the same `$resolved[$state][$bp][$property]` slot space as Layer 1, and under disjointness it can never target an occupied slot. No silent overwrite, no I35 cancellation between the two layers.
2. **The role's `groups` list stays authoritative** for every property Layer 1 owns (§1.5's cost, bounded).
3. **The ladder is enforced structurally, not by instruction.** An author cannot use Layer 2 to reach a property Layer 1 owns, so "use the group when one exists" is a property of the code rather than a sentence in a prompt the model may ignore. That is what makes escape telemetry meaningful at all.
4. **Role permissions cannot be bypassed.** A role that deliberately does not permit `shadow` cannot be given a `box-shadow` through `_css`, because `box-shadow` is a registry property.

**The cost, named.** **Promotion is a breaking change.** When demand promotes an allowlisted property into a group (the design doc's standing promotion path), that property must leave the allowlist in the same commit, and every stored `_css` declaration of it becomes a refusal. Under I36 that is correct — *"a rename is a documented breaking change, not a silent migration"* — and §2 of the build spec removes any migration obligation. It is stated here so the first promotion is not a surprise.

**Enforcement, and the hidden cost the review caught (A1).** "Same shorthand family" requires a **family map, and no such table exists anywhere in this repo** — `_pp_udc_property_rank()` encodes emission order, not kinship, and WP core exposes nothing usable. So §2.3 quietly introduces a **second CSS-knowledge table beside the allowlist**, hand-maintained, where an omission is silent. The `white-space` case proves the omissions are not obvious: nobody guesses `white-space` ↔ `text-wrap` from the names.

So the family map is **fail-closed on an unclassified property**, in the same posture `designOffencesIn()` already takes: a test asserts that every allowlist entry AND every one of the 58 registry properties is classified into a family (or explicitly into "no family"), and an unclassified property fails the suite. Declared explicitly, never derived by subtraction — subtraction makes a promotion *silently* remove a property, where an explicit list forces an editor to see and record the removal.

### 2.4 The one-home rule binds the allowlist too

`tests/js/css-lint.test.js`'s structural-CSS boundary classifies every property as `STRUCTURAL`, `ALWAYS_DESIGN` or spacing-family, **fail-closed on an unlisted one**. Its `object-position` note states the rule: *"the property has exactly one home at every commit — never zero, and never two."*

So **a property classified `STRUCTURAL` may not be allowlisted**, and admitting one moves it out of `STRUCTURAL` into `ALWAYS_DESIGN` **in the same commit**. That disqualifies `cursor`, `transform`, `transition`, `transition-property`, `animation`, `border-collapse`, `caption-side`, `vertical-align`, `overscroll-behavior-x` and `-webkit-overflow-scrolling` — each claimed there by #994, #1023, #1046 or #1066 with its own stated reason. §0.5e is what that turns out to cost.

### 2.5 The candidate property set — and why the evidence empties it

This section was drafted as a six-property table. The sweep then applied the contract's own three admission rules to it, and **every candidate failed one of them.** That result is reported as the section rather than hidden behind a shorter table, because it is the finding that drives §8 Q0.

**The rules come in two kinds, and the outside review was right that this draft had blurred them.** Separating them changes the conclusion, so the separation is stated before the rules:

- **SAFETY rules** — (a) and (d). Break one and the layer is unsound: a `STRUCTURAL` property has two homes, or an inert property reports success. These are intrinsic.
- **COHERENCE rules** — (b). Break it and two mechanisms collide silently.
- **POLICY rules** — (c), and the group-home test of §0.5c. Break one and you have built something nobody asked for yet. **These are prioritisation, not soundness**, and §2.5's closing originally read as though they were structural. They are not.

The rules: **(a)** designable (not `STRUCTURAL`, §2.4); **(b)** emitted by no registry param and in no registry shorthand family (§2.3); **(c)** cited demand (§8 Q2's principle); and **(d)**, added by the review —

**(d) ELEMENT-AGNOSTIC.** A role maps to a **selector, not a tag**: the engine has no idea whether `.faq__answer` is a `<div>`, a `<p>` or a `<span>`. Layer 1 is safe from this because a group's params are chosen for the roles that permit the group, and a role permits a group deliberately. `_css` is universal (§1.5), so a property that is inert on some element types is a **validates-green-paints-nothing class that disjointness does NOT cover** — the I19 exposure, arriving through the one door §2.3 leaves open. `text-indent` on an inline role, `list-style-type` on a non-list role: accepted, stored, reported applied, paints nothing, forever.

Two ways to close it: declare a per-role element type in every component schema so the engine can refuse a mismatch (a large new schema surface across ten components, for one valve), or **admit only properties that are meaningful on any element a role can select**. The contract takes the second. It is also why each entry must carry a non-empty `description` saying why it qualifies (§4.4) — the same posture `SchemaValidationTest` already takes on a slot's `description`.

Rule (d) is independent of (a)-(c) and it disqualifies `text-indent`, `word-spacing`, `list-style-type` and `list-style-position` on a **second, separate ground** from the one §2.5 gives them. `opacity` is the only candidate that passes (d).

| candidate | fails | why |
|---|---|---|
| `opacity` | **(c) only** — it PASSES every safety rule | no cited demand at all, and a written prohibition in **eight** places including the AI's own instructions (§0.5a). The `Filters & Blend` group home (§0.5b) is now a POLICY argument about where it belongs, not a disqualifier. **This is the one candidate a maintainer could reasonably admit over my recommendation** — the objection to it is the eight shipped statements, not soundness |
| `text-underline-offset` | **(c) only** — also passes every safety rule | base.css:462, site-global, no issue asks for it. In **neither** css-lint set, so admitting it also means classifying it (the `aspect-ratio` precedent) |
| `text-indent` | **(c)** | **zero occurrences** in any theme stylesheet; `_pp_udc_inherited_properties()` stocks it as *anticipation*, which its docblock says outright (lib/udc.php:5470-5473: *"deliberately stocked ahead of it … unreachable lookups until one does"*). Natural home: Typography |
| `word-spacing` | **(c)** | same — zero occurrences, anticipation only. Natural home: Typography |
| `white-space` | **(b)** | CSS Text L4 shorthand for `white-space-collapse` + `text-wrap-mode`; `text-wrap` **is** a registry property (`typography.wrap`, lib/udc.php:521). Its every shipped occurrence is `nowrap`/`normal`, all reachable through `typography.wrap` today — including #892's nav case. The unreachable half (`pre`, `pre-wrap`) occurs only on admin surfaces outside the band engine |
| `list-style-type`, `list-style-position` | **(a)** | the `list-style` family is claimed into `STRUCTURAL` (14 role-selected resets), and §6.15 shows they would not fix #1049/#1068 anyway — those need a descendant selector |
| `transition-property` | **(a)** | the one candidate with **real cited demand** (#1041 instance 2), and the one blocked by a *ruling* rather than by thin evidence: A3 fixed motion at two params, css-lint claimed it `STRUCTURAL`. An A3 widening, not an allowlist entry (§0.5b2) |
| `overflow-wrap` | **(a)** | cited demand (#1067, #591, role-selected) and an explicit ruling against authorability — css-lint.test.js:2856-2858: *"There is no UDC group for it and there should not be: an author choosing 'let long words overflow my layout' is not a design decision anyone wants."* |

**The honest conclusion, corrected by the outside review, because the first draft of this line overstated it.**

Under the **safety and coherence rules alone — (a), (b), (d) — the set is NOT empty.** `opacity` passes all three (verified: `ALWAYS_DESIGN` at tests/js/css-lint.test.js:2937, emitted by no registry param, in no registry shorthand family, and meaningful on any element). `text-underline-offset` passes (a) vacuously and (b) and (d) properly — it is in **neither** css-lint set today, so it sits in the fail-closed unlisted arm, exactly where `aspect-ratio` and `object-position` sat before they were classified.

**It is rule (c) — a POLICY rule — that empties the list.** That distinction matters and the first draft lost it: "empty by construction" is true of the *policy*, not of the *safety boundary*. The outside review's word for the original framing was "partly manufactured", and that is fair.

Two further corrections it forced, both internal-consistency failures of this contract against itself:

1. **The group-home test is self-defeating as a disqualifier.** If "it could belong to a future group" excludes a property, then the only admissible properties are those belonging to no design family — and §0.5e proves that set is *exactly* the nine already ruled `STRUCTURAL`. So applied as an admission rule it drives the allowlist toward precisely the properties that must never be on it. It is a legitimate argument about where a property is **best homed**; it is not a soundness test. Demoted to POLICY above.
2. **"It has a future group home" is a weak objection given §2.3's own promotion clause**, which already accepts promotion as a documented breaking change under I36 with no migration obligation. Treating a future home as fatal while simultaneously writing down that promotion is fine is incoherent.

What survives, and it is still the finding that drives Q0: **the demand that exists today is group-shaped and selector-shaped, and none of it is Layer-2-shaped.** That is a statement about priority, and Q2 asks whether priority should gate admission.

**Two exclusions the rules produced that are worth keeping on the record either way**, because they are what a future admission has to get past:

- **`white-space`** is the case that proves the by-family rule earns its keep: it passes an exact-name check, and `white-space: nowrap` in `_css` would still silently reset an authored `typography.wrap` at the same coordinate — in the browser, invisibly to the engine, because the two property strings differ. The honest route for it is a `typography` param beside `wrap`. *(Shorthand relationship read from CSS Text L4, not a browser probe; the exclusion is conservative either way, so a probe is owed only if `white-space` is reconsidered — §14.3.)*
- **`list-style`** (the shorthand) would be excluded even if the family were admissible: its `list-style-image` member takes `url()`, banned program-wide.

**The failure mode this section was written to avoid was an over-stated allowlist. The result is that there is nothing honest to state at all.** A property with no cited demand is not a capability, it is surface area — and on this layer, surface area is the security boundary.

### 2.5a A keyword-valued property needs one line, not a new validator — and it retires a synthetic stand-in

Worth recording because the obvious implementation is a new `_pp_validate_list_style_type()` in `lib/apply.php`, i.e. exactly the forked-grammar the architecture forbids, and there is a better answer already in the tree.

The shared grammar owner **already has a closed-keyword-set type**: `_pp_validate_token_value()`'s `case 'enum'`, which checks strict membership against an `$allowed` list (lib/apply.php:1587-1606).

`pp_udc_validate_value()` currently calls `_pp_validate_token_value($token, $type)` with **two** arguments (lib/udc.php:1928), so the UDC cannot reach that case usefully. Passing `$param['allowed'] ?? null` as the third is the whole change: **one line, zero new validators.**

Three obligations, and the first is a correction to a claim I made before checking it:

1. **This does NOT retire the synthetic slot-enum stand-in.** `apply.php`'s `case 'enum'` docblock says *"NO SHIPPED SLOT DECLARES ONE TODAY"* and points at `SchemaValidationTest::testTheValuesGuardStillReachesTheSlotSurfaceWithNoLiveSlotEnumShipped()`, whose vacuity guard counts enums declared in **component schemas** (`styling.style_slots`) — a different surface from the UDC registry. A `_css` param carrying `type: 'enum'` would not trip it and would not fold back into that sweep. So the honest claim is the narrow one: reusing the case avoids a forked validator. It retires nothing.
2. **The UDC registry needs its own self-consistency pin**, since the schema sweep does not cover it: a param declaring `enum` must carry a non-empty `allowed`, and `allowed` on a non-`enum` param is a declaration error. That is I31's *"the system never ships a default its own validator rejects"* clause applied to the new table.
3. **`_pp_udc_reference_check()` must have an answer for `enum`** (lib/udc.php:1575) — an `@reference` resolving to a keyword has to be judged against the set, or a token reference becomes the way around it. The v1 render boundary deliberately calls `_pp_validate_token_value()` *without* `$allowed` (apply.php:1596-1599), but that is the v1 inline-style channel and is untouched: v2 re-validates through `pp_udc_validate_value()`, which *does* pass it — so no write-accept/emit-drop divergence, provided the reference path is closed too.

### 2.6 The FUNCTION allowlist: it already exists, and it is per type

The design doc asks for a function allowlist (*"calc/clamp/min/max/var + color functions; url() only to same-install uploads; everything else rejected"*). **The repo already has one, it is already an allowlist rather than a denylist, and it is already per type** — so Layer 2 does not get a second one. Stated here so it is checkable rather than assumed:

| where | what it admits | mechanism |
|---|---|---|
| `length` / `length-or-none` | `calc()`, `clamp()` **only** | `_pp_css_length()` (lib/apply.php:413-489): *"any alpha run that is not a unit word is out"* — this is what blocks `var()`, `env()`, `url()` and every other function, plus a proper-nesting depth walk and a top-level-comma guard on `calc()` |
| `color` | `rgb/rgba/hsl/hsla`, `transparent`, `currentColor`, one `var(--registered-token)` | `_pp_validate_color()` |
| `gradient` | the gradient functions + the color set | `_pp_validate_gradient()` |
| every type | **no** `url()`, `expression()`, `@import`, `{ } ; < >`, backslash, control chars, `/*`, `*/` | `_pp_forbidden_css_construct()` (lib/apply.php:1532), which runs **ahead of the type switch** |
| every v2 value | balanced `( )`, `[ ]`, paired quotes, string-aware walk | `_pp_udc_delimiters_balanced()` (lib/udc.php:5721), called from `pp_udc_validate_value()` at write, at emit re-validation, and on referenced band tokens |
| `background.image` only | a same-install attachment id; the engine builds the `url()` | ruling A2 |

**Three consequences, stated rather than left implicit:**

1. **`min()` and `max()` are NOT admitted.** The design doc named them; `_pp_css_length()` admits `calc` and `clamp` only (`preg_match('/^(clamp|calc)\(/')`). Adding them is a **widening of the shared grammar owner for every value in the program**, which is its own decision and not Layer 2's to take. Recorded as a deliberate deviation (§6.10).
2. **`url()` stays banned, no Layer-2 exception.** The design doc floats *"url() restricted to uploads/-hosted assets"*; ruling A2 already settled it — the author writes an attachment id, the **engine** builds the escaped same-install URL. A Layer-2 `url()` would be a second mechanism for one outcome (I35) and would put author bytes inside a CSS function for the first time.
3. **Raw `var()` is not a Layer-2 reference form.** `@name` is v2's one reference sigil (`pp_udc_parse_reference()`); a second syntax on the same namespace is the hidden aliasing I36 forbids. `@name` reaches `_css` free — resolved *before* the grammar check, by `_pp_udc_validate_scalar()` and the reference-type table.

### 2.7 The refusal envelope names the exclusion

Four refusals, each naming *why* rather than only *no*:

| situation | code | message must name |
|---|---|---|
| property not on the allowlist | `unknown_udc_css_property` | the property, the allowlist (derived, bounded by `pp_udc_bounded_list()`) |
| property **is** registry-owned | `unknown_udc_css_property` | the property **and the `group.param` to use instead**, and that the role must permit that group — this is the ladder made operational |
| value fails the property's type | the existing type code (`invalid_length`, `invalid_color`, …) | unchanged from Layer 1 |
| a `:pseudo` / at-rule / selector-shaped key | `invalid_prop_value` | the three states that exist, and that selectors, at-rules and pseudo-elements are not supported here — the wording `_pp_udc_validate_group_map()` already uses at lib/udc.php:2419-2427 |
| a **mistyped `_css`** (`_cs`, `css`) | `unknown_udc_group` | **the existing message is wrong the moment `_css` is valid.** lib/udc.php:2336 renders *"Available groups: %s"* from `implode(', ', array_keys($groups))`, which will not contain `_css` — so an author who typos the key is told the correct key does not exist. The listing must include `_css`, on both the unknown-group and not-permitted arms |

---

## 3 — EMISSION, and where Layer 2 RANKS

### 3.1 Breakpoint keying and states: inherited, not re-implemented

A `_css` map takes exactly the two dimensions a group map takes: a breakpoint-keyed value (`{"d":…,"t":…,"p":…}`) against `pp_udc_breakpoints()`, and a state sub-map (`":hover"`, `":focus-visible"`, `":active"`) against `pp_udc_states()`, whose values may themselves be breakpoint-keyed. Emission is narrow-first in `pp_udc_breakpoints_in_emit_order()` and states print in `pp_udc_states_in_emit_order()`. **The engine emits every `@media` wrapper; an author never writes one.**

A declaration used responsively **mints a band token** exactly as Layer 1 does — see §3.4 for the one thing that must be fixed for that to be true.

### 3.2 Layer / cascade placement: unlayered, in the band's own block

`_css` declarations emit **in the same unlayered band block as the authored Layer-1 values**, at `[data-pp-band="<id>"] <role-selector>`. So, exactly as for Layer 1: `@layer pp-v1` (the v1 stylesheet) cannot outrank them whatever its specificity, `pp-zero` (band-root defaults, designed to lose) sits below both, and `!important` is never emitted.

### 3.3 ⚠️ SUPERSEDED BY R2′ — "no new cascade rung" was true only under disjointness

**This subsection is stale and is kept for its reasoning, not its conclusion.** It argued
that a `_css` declaration has no competitor in any tier, and that argument rested entirely
on §2.3's disjointness rule, which R2′ deleted. Under broad admission `_css` CAN collide
with a Layer-1 param at the same coordinate: **`_css` wins and the loss is disclosed**
(§2′.3). The provenance half below still holds and is now more important, not less — the
source name `css` is what lets the new `udc_css_overrides_group_value` finding say which
mechanism painted. ORIGINAL TEXT FOLLOWS.

#### 3.3 (original) — Layer 2 introduces no new cascade rung

The honest answer to *"where does Layer 2 rank?"* is: **nowhere new, and the rank is unobservable inside the engine.**

The rung today is `site tokens → presets → role defaults → band udc → breakpoint → state`. A `_css` property cannot be emitted by a role default (defaults are `pp_udc_groups()`-shaped schema data) and cannot be carried by a preset (§6.13) — and under §2.3 disjointness it can never collide with a band's own Layer-1 value either. **A `_css` declaration therefore has no competitor in any tier of the engine's own cascade.** Its only cascade competitors are the v1 stylesheet, which it beats by being unlayered, and unlayered third-party CSS (WP core's injected rules), which is the pre-existing bounded exception the engine header already names.

**Is the rank visible in provenance? Yes, and it is named separately.** `pp_udc_compile_band()` carries a provenance `$source` per value; `_css` resolves under its **own source name `css`**, not folded into `authored`. The reason is the one the preset tier records at lib/udc.php:100-104: I35/I36 need a reader to see *which* mechanism a value came from, not merely that some mechanism did. The drop ledger's locator follows: `<role> _css <property> [(:state)]`.

**A claim this contract deliberately does not make:** that `_css` is immune to being outranked by a role default on a *different* role whose selector is a superset. It is not — that is #1059's open rung, and it is orthogonal to Layer 2 (it is a role-selector containment problem, not a layer problem). Layer 2 neither fixes nor worsens it.

### 3.4 Two engine facts that must change, or Layer 2 breaks something that works

Both are consequences of `_css` being a pseudo-group that `pp_udc_groups()` does not contain. Both are cheap; both are silent and severe if missed.

1. **`_pp_udc_name_is_the_engines_own_mint()` must learn the `_css` pseudo-group.** It decides whether a mint-shaped `_tokens` name is the engine's own by looking the group segment up in `pp_udc_groups()` (lib/udc.php:5871-5881). The mint name for a responsive `_css` value is `<role>-_css-<property>[-<state>]-<bp>` — e.g. `body-_css-opacity-p` — whose group segment is the literal key `_css` (chosen verbatim precisely so it cannot collide with a real group name), and `_css` is not in that registry. So the name reads as an *author squatting the mint namespace* and is **refused**. Validation runs over **stored** compositions (`restore_composition`, `wp pp check page`, the post-write envelope), so this turns **every band holding a responsive `_css` value into a permanent false refusal** — the exact regression that function's own docblock was written to record (lib/udc.php:5848-5862). Pinned in both directions: an engine mint accepted, an author squatting it refused.
2. **The CSS-inherited Layer-2 properties must be in `_pp_udc_inherited_properties()`** (lib/udc.php:5480), which drives the `udc_band_value_shadowed_by_role_default` disclosure. Verified: **all four inherited candidates of §2.5 are already in that table** (`text-indent` 5490, `word-spacing` 5493, `list-style-type` 5498, `list-style-position` 5499) — anticipated there while no group emits them, which is itself part of §2.5's demand evidence. So this is a **verification item, not a change**. The forward rule is what matters: **a later allowlist addition that is CSS-inherited joins that table in the same commit**, or the `_band` disclosure silently under-reports — the I35 shape the disclosure exists to close.

### 3.5 Declaration ordering: already safe, and worth making explicit

Verified rather than assumed, because it is where a shorthand-family mistake would surface. `_pp_udc_sort_declarations()` (lib/udc.php:3474) sorts each block into registry order via `_pp_udc_property_rank()`, which is derived from `pp_udc_groups()` — so every Layer-2 property is **unranked**. That is already handled, and handled for exactly the right reason: *"An unranked property … sorts last, keeping its relative order stable rather than jumping ahead of a shorthand it might belong to."* So emission is deterministic with no change at all.

The obligation is therefore small and is about stability rather than correctness: **`_pp_udc_property_rank()` should extend with the allowlist after the registry**, in the same posture the background-overlay carrier is given a stable rank at lib/udc.php:3463 (*"it has to sort somewhere stable or the fold would depend on author key order again"*). Without it, Layer-2 declarations order alphabetically-by-fallback rather than by declaration order, which is stable but arbitrary, and an emit test would be pinning an accident.

### 3.6 Emitted-size posture

Layer 2's contribution is bounded by construction: at most `|allowlist|` declarations × 3 breakpoints × 4 states per role — a small multiple of what a role can already emit through its groups. **#1062 (emitted head CSS is uncapped) is not made worse in kind** and is not addressed here.

**Measured against the recorded baseline, not asserted (P2).** The engine already carries numbers for this loop — lib/udc.php:3124-3135 records 12.0 → 44.0 ms on a 50-band page at twelve preset roles per band, and #973 measures UDC value re-validation at 34-38% of page CSS build. `_css` adds entries to that same loop, so the evidence must be the same 50-band harness, before and after, reported in the handoff. An unmeasured "bounded by construction" is the claim the preset tier's own re-measurement (*"the number above should not be read as still describing a preset-using page"*) exists to warn against.

---

## 4′ — VALIDATION UNDER R1′ (what is checked, and the promise that it says so)

§4.1 (one gate, every ingress) and §4.2 (reflected-text bounds) stand. §4.3 and §4.4 are
replaced by this section; the originals follow.

### 4′.1 The scope of check, stated so the docs can claim exactly it and no more

| | checked | not checked |
|---|---|---|
| property NAME | the `^-?[a-z][a-z0-9-]{0,63}\z` charset; the §6.0 exclusions | — nothing else is possible: the charset is an allowlist |
| value, any property | forbidden constructs, delimiter balance, emptiness, reflected-text bounds | — |
| value, property the vocabulary TYPES | **+ that parameter's full grammar**, and `@reference` type-fitness | — |
| value, any other property | *(the security gates only)* | whether the browser accepts it — **disclosed** as `udc_css_unchecked_property` |

**This table IS the declared grammar I30 asks for.** The breach R1 named was a system
claiming proof it did not have; the honesty is in the fourth row saying so on the envelope
and in the AI surface, not in pretending the row is empty.

### 4′.2 The write-accept / emit-drop prohibition, re-aimed

R1′ makes the #570 convergence rule the sharpest constraint here: **whatever the write gate
accepts, the emitter emits or DISCLOSES.** Three mechanisms, and the third is what makes it
checkable rather than hoped for:

1. **One predicate.** `_pp_udc_css_param_for_property()` decides "typed or not" for the gate
   and for the compiler. Two copies would let a write say typed and an emit say verbatim.
2. **The emitter re-gates stored data**, because a raw meta write, a pre-rule composition
   and `restore_composition` all reach it directly — and ledgers every discard, so data the
   gate never saw is reported rather than silently dropped.
3. **Both-direction pins.** `tests/UdcRawCssTest.php` asserts each refused shape is refused
   at write AND dropped-with-a-ledger-entry at emit, and the hostile-byte sweep is
   red-proofed by planting the `$`-for-`\z` defect (48 assertions → failure at 13).

**One pre-existing instance Layer 2 inherits and does not fix:** `_pp_udc_place()` silently
skips a role whose schema-declared selector fails its charset (#1048). A `_css` value on
such a role is inert for the same reason a Layer-1 one is.

### 4′.3 What the test suite covers

The property-name gate hostile-byte by hostile-byte with a paired accept for each; every
exclusion with its stated reason; typed-where-known in both directions; the `@reference`
rule including inside a breakpoint map; the rank in **both key orders**; both disclosures
including the no-`_tokens` band; the mint round trip in both directions (the CRITICAL
regression); presets; chrome; the real authoring path; and the emit-drop ledger. Rendered
claims — that a declaration computes, that the raw value wins in the browser's own cascade,
and that breakpoints key to the viewport — are `tests/e2e/raw-css.spec.ts`, because an
unknown property is emitted verbatim and only a browser can say whether it was accepted.

---

## 4 — VALIDATION as first drafted (§4.1-§4.2 stand; §4.3-§4.4 superseded by §4′)

### 4.1 One gate, already traversed by every ingress

`_css` is validated inside `pp_udc_validate_map()`, which every ingress reaches: `update_composition`, `create_page`, `update_component`, chrome writes, preset definitions, `restore_composition` (reports without blocking, #233), `wp pp check page`, and the post-write envelope's re-validation. I4's *"one gate every ingress path traverses"* holds with no new gate.

The dispatch is: role map key `_css` → `_pp_udc_validate_group_map()` with the params table resolved through `_pp_udc_params_for_group('_css')` → `_pp_udc_validate_param()` → `_pp_udc_validate_scalar()` → `pp_udc_validate_value()`. **Zero new validation functions.**

### 4.2 Refusal envelopes and reflected-text bounds

Codes and messages per §2.7. Every author-supplied fragment reaching a message — property name, role, token name — goes through the existing sinks: `_pp_render_undeclared_prop_keys()` for keys, `_pp_udc_reflect()` for ledger/findings values (bounded at `PP_UDC_REFLECTED_MAX` = 100), `pp_udc_bounded_list()` for the allowlist listing with a true-total tail. I37's single-owner rule: **no new truncation idiom, no new escaping call site.** A property name that is not on the allowlist is refused before it can be anything else, so no byte the allowlist does not name reaches a sink as a property.

### 4.3 The write-accept / emit-drop mismatch class: made unreachable, then pinned

This is the class #1048 is open against and the class the task frame forbids adding to. Three mechanisms, in order of strength:

1. **One predicate.** Write gate and emitter resolve the params table through the same `_pp_udc_params_for_group()`. A property the gate accepts is a property the emitter can place. (A3 sub-ruling 4.)
2. **Emit-time re-validation already exists.** `_pp_udc_place()` re-validates every value, so stored data that never met the write gate — a raw meta write, a pre-rule composition, a restore — is dropped at emit **and ledgered**.
3. **Both-direction pins.** For each of: a non-allowlisted property, a registry-owned property, a type-invalid value, a `single_valued` violation, an unknown state, an item-grain `_css` — a test asserts *refused at write* **and** a test asserts *dropped-with-a-ledger-entry at emit* from raw stored data. A property accepted at one gate and silently skipped at the other is a test failure, not a discovered defect.

**One pre-existing mismatch Layer 2 inherits and does not fix, named rather than hidden:** `_pp_udc_place()` silently skips a role whose *schema-declared selector* fails its charset gate (lib/udc.php:3022), and the write gate consults nothing equivalent. That is #1048 exactly. `_css` on such a role is silently inert for the same reason a Layer-1 value is. Layer 2 adds no new instance and closing #1048 is not in this task's scope.

### 4.4 Test plan

**Every guard below is subject to one meta-rule, from a 10/10 prior learning in this repo:** after writing it, plant the defect it exists for **and** a near-miss variant, and check the **assertion count moved**. A guard whose assertion count does not change when you break its subject is asserting on an empty set — and four review passes at #1066 found that most defects were introduced by the previous pass's fix.

- `pp_udc_css_properties()` ↔ `pp_udc_groups()` **disjointness by shorthand family**, both directions, with the family map **fail-closed on an unclassified property** on both sides (A1).
- Every allowlist entry carries a **non-empty `description`** naming its demand citation and why it satisfies admission rule (d); pinned non-empty, the posture `SchemaValidationTest` already takes on a slot's `description`.
- The `unknown_udc_group` listing includes `_css` on **both** arms (C1), and a role literally named `_css` is still validated (C2).
- Allowlist ↔ css-lint boundary: **no allowlisted property is `STRUCTURAL`**; every allowlisted property is `ALWAYS_DESIGN` or spacing-family (the one-home rule).
- Every allowlist `type` is a type the shared grammar owner actually dispatches (the I31 self-consistency clause: the system never ships a declaration its own validator rejects).
- A `RoleDefaultsEmitTest`-pattern emit suite for `_css`: base / tablet / phone / each state / a minted responsive value, asserting the emitted text and the `@media` nesting. It **must read the breakpoints from `pp_udc_breakpoints()`** rather than hardcoding them — #1052 is open against the three existing harnesses for exactly that (a moved breakpoint asserts the wrong tier and still passes).
- **Authoring-path mandate (14.1):** at least one test authors `_css` through the real `update_composition` / `create_page` surface, never a raw `_pp_composition` meta write.
- The mint-reservation pin of §3.4.1, both directions.
- Chrome: a `_css` on a nav role validates, emits under `[data-pp-chrome="nav"]`, and round-trips through the `pp_site_udc` CAS.
- Preset definition carrying `_css` is refused at **both** grains (§6.13, §6.13a), and the band path still accepts.
- **Any rendered evidence is measured, never computed or derived.** Two prior learnings bind here, both 10/10: `getComputedStyle` cannot see a clipped or unpainted treatment (a computed `outline: solid 2px` read green over **zero** painted pixels at #1046), and Chromium **floors** each channel when compositing an opacity, so an arithmetic contrast figure is not a measurement (#1066 carried 10.22:1 for months where the browser paints 10.11:1). If `opacity` is ever admitted, its contrast evidence is a **pixel probe**: screenshot a clip, read it back through `getImageData`.

---

## 5 — DISCLOSURE

### 5.1 ⚠️ SUPERSEDED BY R2′ — Layer 2 now adds **TWO** finding types

**Stale conclusion, kept for the channel map below, which is still correct for what it
covers.** "No new finding type" followed from disjointness (no collision to report) and
from typed-everything (nothing unchecked to disclose). R2′ removed both premises, so Layer 2
adds exactly two, each with a real subject:

- **`udc_css_overrides_group_value`** — this `_css` declaration outranked a group value the
  author also set; names role, property, group/param, state and breakpoint (§2′.3).
- **`udc_css_unchecked_property`** — this property is not one the unified grammar types, so
  only the security gates ran and the value emits verbatim (§2′.4). This is the design
  doc's `custom_styling_conventions_only` in honest form, and it is the escape telemetry
  the ladder wanted, arriving as a by-product.

§5.2 below argued that a conventions-only finding would be false on every band it fired on.
That was correct under R1 and is now inverted: under R1′ there is a genuine
unchecked-beyond-security set, so the finding has a true subject. ORIGINAL TEXT FOLLOWS.

#### 5.1 (original) — the four existing channels

| what an author needs told | channel | fires because |
|---|---|---|
| a responsive `_css` value was stored as a minted band token | `udc_token_minted` | minting is the same normalization; §3.4.1 is what makes the name legal |
| a `_band._css` inherited value is cancelled by a role's own default | `udc_band_value_shadowed_by_role_default` | §3.4.2 puts the inherited properties in the table |
| a stored `_css` declaration is not painting | the emit **drop ledger** → `UdcEmitDropAdvisory`, `wp pp check page`, the pre-mutation channel | `_pp_udc_place()` ledgers at the branch that drops |
| a `_css` property is refused | the refusal envelope, §2.7 | one gate |

Inventing a fifth type where four already carry the information would breach I27 (*one canonical vocabulary, a finding reported once*).

### 5.2 Escape telemetry: deliberately not built, and the reason is recorded

The design doc's ladder wants each escape machine-visible so recurring escapes become demand telemetry, and names a `custom_styling_conventions_only` finding for it. **Not built here**, for two reasons: the design doc itself places telemetry *"Post-wedge, explicitly not gating"*; and under §2.2 nothing in Layer 2 is conventions-only, so a finding whose name asserts reduced verifiability would be **false on every band it fired on**. The data is not lost — a stored `_css` key is a grep over compositions, and the option stays open for a telemetry design that has its own home.

### 5.3 One gap this contract does not close, stated because it is real

#1061 (three silent emit-drop paths report nothing on the pre-mutation channel) and #993 (chrome writes have no findings channel) both sit under `_css`'s disclosure story. A `_css` drop on a chrome role has nowhere to be reported, for the same reason a Layer-1 one does not. Layer 2 does not worsen either and fixes neither.

---

## 6.0 — THE EXCLUSION SET (R2′): named, argued, and short

Broad-by-default means the exclusions carry the whole burden of justification. Each is here
because it disables a mechanism, not because it looked risky.

| excluded | why |
|---|---|
| **Custom properties (`--*`)** | `_tokens` owns that namespace with a charset gate, a literal-only rule, a mint reservation and a balance gate. A raw custom property in `_css` bypasses all four — and would let an author overwrite a name the engine mints for itself. Enforced by §2′.1's charset, so it cannot be forgotten. |
| **`all`** | it resets **every** other declaration in the block, including the engine's own role defaults and the band's Layer-1 values. That makes emission order semantically load-bearing in an unbounded way, and no disclosure could describe the blast radius honestly. |
| **`content`** | it puts author bytes into the page **as rendered text**, through a channel that never passes `wp_kses_post()` and never reaches the shared reflected-text cleaner (I37). That is a content mutation wearing a styling channel, and it is the one property here that bypasses the truth machinery rather than merely out-ranking a value. |
| **`behavior`, `-moz-binding`** | historical script-execution vectors (IE's HTC behaviours, Gecko's XBL bindings). Dead in every shipping browser, and named anyway: an exclusion set is only as good as the things it bothers to name. |
| **`-pp-background-overlay`** | not a CSS property at all — it is the engine's internal carrier for the `background.overlay` param, folded into `background-image` by `_pp_udc_compose_background_layers()`. An author writing it would collide with that fold and reach a code path no author input was ever meant to enter. |

**What is NOT on this list, deliberately:** every `url()`-bearing property
(`background-image`, `cursor`, `mask`, `filter`, `border-image`, …). They need no exclusion
because `url(` is banned in every **value** by `_pp_forbidden_css_construct()`, which runs
ahead of everything. Excluding the properties as well would be theatre — it would suggest
the property was the risk when the value always was.

**And `position`, `z-index`, `transform`, `overflow` and the rest of the escape-the-box
family are ADMITTED**, per R2′, with the `udc_css_unchecked_property` disclosure. The author
owns the outcome. §6.8 argued for excluding them under the old policy and is superseded.

---

## 6 — EXCLUSIONS as first drafted (§6.1-§6.5, §6.9-§6.15 stand; §6.6-§6.8 superseded by §6.0)

| # | excluded | reason |
|---|---|---|
| 6.1 | **Selectors of any kind** | BUILD-SPEC §7 (*"no selectors"*). The flat `[data-pp-band] <role-selector>` contract is what makes specificity flat by construction; a selector grammar needs a real CSS parser, which the design doc places at **Layer 3** |
| 6.2 | **At-rules** (`@media`, `@supports`, `@container`, `@font-face`, `@import`) | the engine emits `@media` from `pp_udc_breakpoints()`; a second breakpoint semantics in one page is the hidden divergence I36 forbids (the engine header says so for the breakpoint integers). `@import` is also in the shared reject set |
| 6.3 | **Pseudo-elements** (`::before`, `::after`, `::marker`) | ruling A3 deferral, unchanged: *"a pseudo-element is a new box rather than a new value for an existing one"* |
| 6.4 | **`:disabled`, ancestor states, sibling combinators** | ruling A3 deferral, unchanged. The three states of `pp_udc_states()` are the whole state dimension |
| 6.5 | **Item grain** | pending **Addendum B** (#1024). Shipping it here pre-empts an unmade ruling |
| 6.6 | **Any property in a registry shorthand family** | §2.3 disjointness — the rule the whole no-collision argument rests on |
| 6.7 | **Any property the css-lint boundary classifies `STRUCTURAL`** | §2.4 one-home rule. Disqualifies `cursor`, `transform`, `transition`, `animation`, and the five table-markup properties |
| 6.8 | **Properties that escape the band's own box**: `position`, `inset`/`top`/`right`/`bottom`/`left`, `z-index`, `float`, `mix-blend-mode`, `isolation`, `clip-path` | the design doc names `position: fixed`; the general rule is that a band must not paint or place outside itself — the entire safety story of scoped emission. Several are also `STRUCTURAL` or 2nd-cut group members |
| 6.9 | **`content`** | the design doc names `content: attr()`. Beyond that, `content` on an ordinary element *replaces its content* — a content mutation through a styling channel, which breaks the file-vs-composition authority model |
| 6.10 | **`min()` / `max()`** | named by the design doc; the shared grammar owner admits `calc`/`clamp` only. Widening it changes every value in the program and is its own decision (§2.6) |
| 6.11 | **`url()`** | ruling A2 already owns images: the author writes an attachment id and the engine builds the escaped same-install URL. A second mechanism for one outcome is I35 |
| 6.12 | **Custom properties as the declared property** (`--x: y`) | `_tokens` owns that namespace with a charset gate, a literal-only rule, a mint reservation and a balance gate. A raw custom property in `_css` bypasses all four |
| 6.13 | **Presets may not carry `_css`**, at either grain | a preset is a site-wide named Layer-1 bundle. A preset carrying raw declarations makes the escape invisible at the band that uses it, defeats the ladder's attributability, and would need the intersect predicate (`_pp_udc_split_preset_by_permitted()`) to reason about a pseudo-group. See §6.13a — this is not free, and getting it wrong is a silent hole |
| 6.14 | **`!important`** | §3.4 of the build spec: never emitted, never authorable |

### 6.13a The preset exclusion interacts with §1.5, and the interaction is the whole difficulty

Verified by read, because the naive implementation opens a hole:

- `pp_udc_validate_preset_definition()` derives `$permitted = array_keys(pp_udc_groups())` (lib/udc.php:1118) and checks `grain` against the same registry (lib/udc.php:1097). So **today** a `grain: "_css"` preset is refused, and a role-grain preset carrying a `_css` key is refused by `_pp_udc_validate_group_map()`'s `$permitted` membership check (lib/udc.php:2339).
- **But §1.5 requires that exact check to be bypassed for `_css`** on the band and chrome paths, because `_css` is universal and is not a member of any role's `groups`. A bypass written *inside* `_pp_udc_validate_group_map()` and keyed only on the group name would therefore **silently re-open the preset path**.

So the rule is explicit: **`pp_udc_validate_preset_definition()` refuses a `_css` key before dispatch**, in the same position and posture as its existing nested-preset refusal (lib/udc.php:1113-1115), with a message that says a preset carries design groups and the escape stays attributable to the band that used it. The universal-permission bypass then lives where it belongs and cannot leak. Pinned in both directions: a `_css` preset refused, a `_css` band value accepted on a role whose `groups` list does not mention it.

### 6.15 The exclusion that matters most: Layer 2 does **not** fix the rich-text gaps

`#1049`, `#1068` and `#1069` — an authored list inside a rich-text surface renders with no markers or indent; no role can reach a link inside rich text — are **selector demand, not property demand**, and Layer 2 is explicitly selector-free. Two honest details:

- `list-style-type` and `list-style-position` *are* CSS-inherited, so setting them on a rich-text role does reach descendant list items **by inheritance**. But **inheritance loses to any direct rule on the descendant**: a `list-style: none` reset targeting the `ul` itself beats an inherited value from the parent, and no cascade layer changes that, because inheritance is not cascade. So this reaches *unstyled* descendants only.
- indent needs `padding-left` on the `ul`, which is a **registry** property on an element **no role selects**. Layer 2 cannot express it at any value.

Those three issues remain Layer-3 / role-taxonomy work. Anyone reading this contract as their fix will be wrong.

---

## 7′ — THE AI-AUTHORING SURFACE UNDER R2′ (structured first)

§7.1's premise is unchanged and is now more binding, not less: `lib/ai-context.php` is the
only channel the model reads, so a valve documented anywhere else does not exist for it
(#1059). What changes is that there is much more to say.

**The principle the surface teaches, in the owner's framing:** *structured first, `_css` for
what structure cannot say.* A group parameter is type-checked, appears in the catalog, can
be reviewed and changed by name, and the engine can report when it cannot take effect. A
raw declaration has none of that and is the right answer only when the vocabulary genuinely
has no way to say the thing. The prompt leads with that rule, then the shape, then the four
facts an author cannot infer: `_css` wins a collision (with the finding that says so); an
unknown property is checked for safety only (with the finding that says so); `@references`
need a declared type; and property names are lowercase with one optional leading hyphen.

**Derived, not restated.** The exclusion list in the prompt is built from
`pp_udc_css_excluded_properties()` at runtime, for the reason `pp_udc_group_summary()`'s
docblock gives about v1's four hand-maintained copies of the unit set: an exclusion added
later has to reach the model on the day it lands.

**One thing the surface says that the engine cannot enforce:** *you still own contrast.* A
raw `background` or `opacity` changes what text sits on, and nothing checks that.

---

## 7 — THE AI-AUTHORING SURFACE as first drafted (§7.1's premise stands)

### 7.1 #1059's finding binds this section

`lib/ai-context.php:83-96` injects only `role_name: group/group/group` into the system prompt. **A role's schema `description` is never injected**, and neither is a README or a `docs/` page. Anything the model must know about Layer 2 therefore has to live in `lib/ai-context.php` prose or in `wp pp schema` output. #1059 is the measured proof that the alternative ships a 3.21:1 contrast failure with the warning sitting in a schema field nobody reads.

### 7.2 What ships, and derived from where

1. **One `lib/ai-context.php` paragraph**, assembled at runtime by a `pp_udc_css_property_summary()` sibling of `pp_udc_group_summary()` (lib/udc.php:5121) — never a hand-written list. That function's own docblock is the standard this has to meet: *"DERIVED, never restated. v1 kept four hand-maintained copies of its accepted unit set and pinned none of them to the validator … so a parameter added in a later sprint reaches the authoring model on the day it lands rather than whenever someone remembers."* I43 says the same thing as an invariant. A test pins the prose against the registry, the way `pp_css_grammar_summary()` and `pp_udc_preset_names_for_message()` already are.
2. It must state, in this order: **the ladder first** (*use the group when one exists; `_css` is only for what no group owns — a registry-owned property is refused and the refusal names the group to use*); the `_css` key and its place inside a role map; the exact property list with each one's value grammar; that breakpoints and states work identically to a group; that `_band._css` exists; and the exclusions of §6 in one sentence each.
3. **`wp pp schema <component>`** reports `_css` availability and the allowlist, so the CLI author and the model read the same source.
4. **The refusal messages carry the teaching**, per §2.7 — a registry-owned property names its `group.param`, and the allowlist listing is bounded with a true-total tail.
5. `AI_RULES.md` / `AI_CONTEXT.md` / `docs/` gain the Layer-2 section in the same change. Per `/document-generate`'s scope, the `docs/tutorial-*` and `docs/howto-migrate-*` pages are checked for claims this invalidates (they currently say the styling surface is groups-only).

### 7.3 What the model is *not* told

That Layer 2 is a general CSS escape. It is not: a short allowlist, no selectors, typed values. Overselling it produces exactly the refusal loop #1064 describes one surface over.

---

## 8.R — RULINGS

Q1, Q2 and Q3 were ruled by the orchestrator on 2026-09-20. **Q0 was then ruled by the
owner, and his frame re-ruled two of the three.** Superseded rulings are kept in full
beside their replacements — the same traceability discipline R1 established, and for the
same reason: a ruling that is edited away takes its reasoning with it, and the next reader
re-derives the losing argument from scratch.

### R0 — Q0: **BOTH THIS SPRINT** (owner, 2026-09-20)

Layer 2 builds now, in this task. The `Layout` group follows as its own task (S2-T9) after
this lands. **The governing frame, in the owner's words: Layer 2 is a STANDING FREEDOM
GUARANTEE — raw-CSS parity with Divi's custom-CSS boxes, so that v2 never rebuilds v1's
ceiling.**

That frame is what re-rules R2 and R1 below, and it is worth stating why rather than just
recording that it did. §0.5's evidence answered the question *"what is demanded today"* and
answered it correctly. The frame asks a different question — *"what must never again be
un-expressible"* — and a demand sweep cannot answer that one, by construction. Both my
recommendation and the outside review's `"do not conclude the allowlist has no admissible
member"` were arguing inside the first question. §0.5 therefore **stays in this contract as
CONTEXT — it is why the structured groups are where they are, and it sizes the Layout task
— and stops being the gate.**

### R2′ — Q2 REVERSED: **BROAD-BY-DEFAULT ADMISSION** (supersedes R2)

The allowlist admits **every CSS property** except a small, **named and argued** exclusion
set (§6.0): the security constructs the gates already ban, and anything that could disable
the truth machinery. **`STRUCTURAL` properties are admitted WITH a disclosure** — the
author owns the outcome, which is the contrast posture generalized.

The SAFETY / COHERENCE / POLICY separation **stays as the contract's shape**. What changes
is the POLICY line: it was *cited demand*; it is now **freedom-first, with exclusions named
and argued**. The safety rules are unchanged and are now doing all of the load-bearing
work, which is the right place for them.

Two of this contract's own rules die with this ruling, and they die explicitly rather than
by being quietly dropped:

- **§2.3's DISJOINTNESS rule is GONE.** Layer 2 may now name a property a group already
  emits. That was the rule the whole no-collision argument rested on, so the collision it
  prevented is now real and must be *ranked and disclosed* instead of made impossible —
  see §2.3′.
- **§2.4's ONE-HOME rule no longer disqualifies.** A `STRUCTURAL` property is admitted and
  disclosed rather than refused. The css-lint boundary keeps its own job unchanged (what
  may live in `assets/css/`); it stops being an admission gate for authored values.

> **SUPERSEDED — R2 (orchestrator, 2026-09-20):** *"Q2 is CITED DEMAND for admission, and
> §2.5's rule structure is PERMANENT… Under this ruling the allowlist is empty today —
> which is the Q0 input, not a Q0 answer."* Correct against the demand question it was
> asked; overtaken by the freedom frame. Its rule STRUCTURE survives; its policy LINE does
> not.

### R1′ — Q1 REVISED: **GENERIC VALUES THROUGH THE SECURITY GATES, TYPED WHERE KNOWN** (revises R1)

A per-property typed grammar cannot cover an open property set — that is arithmetic, not
preference. So:

1. **Every value passes the byte-level security gates**, without exception: the forbidden
   constructs (`_pp_forbidden_css_construct()`), delimiter balance
   (`_pp_udc_delimiters_balanced()`), the `\z`-anchored charset gates, and the
   reflected-text bounds at every sink.
2. **A property whose type the unified grammar already knows ALSO gets typed validation.**
   Typed-where-known, so nothing that is checkable today becomes unchecked tomorrow.
3. **An `@reference` REQUIRES a declared type.** This preserves the check R1 was written to
   protect: `_pp_udc_reference_check()` needs a `$param` to judge against, so an `@name` on
   a property the grammar does not type is **refused**, naming why. A literal is accepted
   there; a reference is not.
4. **An unknown property's value emits VERBATIM after the security gates.**

**The invariant reconciliation, recorded because this is the part that looks like a
contradiction and is not.** R1 argued that untyped acceptance breaches I19/I30/I31. Under
R1′ it does not, and the distinguishing fact is *honesty about what was checked*:

- **I30** requires a declared grammar and no silent no-op. The grammar IS declared here —
  it is the security grammar, stated exactly, and the docs and refusals claim **no more
  than it checks**. The breach R1 named was a system claiming proof it did not have.
- **I19/I31** are satisfied by the refusal envelopes covering exactly what is checked and
  the AI surface advertising exactly that. A browser dropping a malformed value an author
  wrote *deliberately, through a channel documented as verbatim* is the author's outcome,
  not a system that validated green and rendered nothing behind their back.
- **The #570 convergence rule still binds**, and is now the sharpest constraint in the
  contract: **whatever the write gate accepts, the emitter emits or DISCLOSES.** The
  write-accept/emit-drop mismatch class — the T6 finding, #1048's class — must not gain a
  new instance. §4.3 is re-aimed at exactly this.

> **SUPERSEDED IN PART — R1 (orchestrator, 2026-09-20):** *"Q1 is A: TYPED… an approved
> design document loses to a recorded invariant of the same program when the two conflict
> on a question the invariant was written about."* **That precedent stands and is not
> disturbed.** What changes is its application here: with an open property set the typed
> reading is not available, and the invariants are satisfied by honest scope-of-check
> instead. R1's clause 3 — `@references` require a declared type — **survives verbatim** as
> R1′.3, which is the half that was actually protecting something.

### R3 — Q3 **CONFIRMED** (unchanged): `_css` as a pseudo-group at role and `_band` grain inside `udc`

Settled by the verified fact rather than by taste: a new top-level composition-item key is
gated by nothing and would be **accepted, stored and ignored** (lib/admin.php:4138-4142
records exactly that about `udc` itself). Layer 2 must not build on a silently-ignored
address. Item grain stays out pending Addendum B (#1024).

### R1 (original) — the design-doc override, kept because its precedent is load-bearing

The approved UDC design doc specifies Layer 2 as *"conventions-checked, not proof-checked"*.
R1 overrode that on this repo's own recorded invariants — I19, I30, I31, the #570
convergence rule — and on the fact that an untyped reading would **delete** an existing
check (`_pp_udc_reference_check()`). R1′ keeps the deletion closed (clause 3) and reaches
the design doc's posture for the open set by a different route: honest scope-of-check
rather than unchecked acceptance. **The precedent R1 set is unchanged: an approved design
loses to a recorded invariant of the same program when the two conflict on a question the
invariant was written about; it does not lose to an implementer's preference, and it does
not lose quietly.**

---

## 8 — THE 7A (as asked; §8.R answers it — kept unrewritten so the rulings read against the real question)

**All four questions are ruled. See §8.R.**

### Q0 — Is Layer 2 the right next build, given §0.5?

The spec schedules Layer 2 for Sprint 2 and this contract makes it buildable. The evidence says its subject is nearly empty and the measured pressure is elsewhere. Four ways forward:

- **Option 1 — Build it as specced, minimal.** Ship §1-§7 with a 1-2 property allowlist (`opacity`, perhaps `text-underline-offset`). *For:* the spec is honoured; the mechanism exists for the next brand; the contract is written. *Against:* a permanent new security boundary, an AI-surface paragraph and a docs section for properties whose own demand evidence argues against them, and it takes `opacity` out of the Filters & Blend group the approved design assigned it to (§0.5b).
- **Option 2 — Build the mechanism, admit nothing yet.** Ship §1-§7 with an **empty allowlist** plus the disjointness/one-home/type tests, so the valve exists and the first real escape is a one-line registry addition rather than a sprint. *For:* cheapest honest version; nothing is mis-homed; readiness without invention; the refusal half of the security boundary ships already tested. *Against, and the review sharpened this into the strongest argument against Option 2:* with no admitted property, **most of the obligations in §1-§7 become guards that assert on an empty set** — the mint-reservation regression pin (§3.4.1) has no value to mint, the rank extension (§3.5) has nothing to rank, the emit suite has nothing to emit, the element-fit rule (d) has no entry to check, and the family map classifies only the registry side. A 10/10-confidence prior learning from this repo names that exact failure mode: *"a guard whose assertion count does not change when you break its subject is asserting on an empty set."* Shipping eight of them, deliberately, is worse than shipping nothing — it manufactures the appearance of coverage. Plus a feature that does nothing on the day it lands, which the release notes would have to say plainly.
- **Option 3 (my recommendation) — Re-aim T8 at the unbuilt FIRST-CUT `Layout` group, and defer Layer 2 to Sprint 3.** §0.5d: `Layout` is a **1st-cut** row of the approved coverage table that was never built, it is the only gap in the program with four shipped code sites routing around it in writing plus three open issues, and its demand is **role-selected** — `align-items` on `.section__grid`, `align-self` on `.section__panel` (#658) need no new selector shape at all. *For:* it is already-approved scope rather than a new axis; it closes the largest recorded gap instead of none; it is Layer-1 work, so it reuses the whole engine with no new security boundary; and the AI-instruction rewrite (#1009) is un-gated and can take a slot either side of it, so alpha.2 still closes. *Against, both sharpened by the outside review:* **Layout is not a substitute for Layer 2 and this option must not be read as one** — it closes known first-cut debt; it does nothing for the future-brand valve Layer 2 exists to be, and calling it a re-aim rather than a re-prioritisation would be sleight of hand. And **Layout is probably harder than one task**: *"exposure declared"* means touching several component schemas, and it reaches responsive behaviour and component semantics. One qualification in its favour, verified: its two sharpest demand issues are **section**-shaped, not grid-shaped (#658 *"vertical alignment controls for section text-panel layouts"*, #588 *"one canonical mechanism for media sizing and cropping"*), so the already-rebuilt components' share of Layout is **independent of Addendum B**; only grid's share is gated.
- **Option 4 — Build it broad, as readiness.** Admit the *designable, disjoint, non-structural* set without requiring cited demand, on the design doc's own argument that cross-brand demand is unfilable in advance (*"it fixes what was filed, never what the next design system will need"*). *For:* the only option that aims at cross-brand reach. *Against:* §0.5e is fatal to it — the disjoint-and-homeless set IS the `STRUCTURAL` set the repo has already ruled must not be authorable, so "broad" has nothing to be broad about without reversing those rulings; and every property admitted without demand is surface area on the layer that is the security boundary.
- **Option 5 — Keep the name, change the dimension: make Layer 2 a VALUE-GRAMMAR escape at param grain, not a property escape.** §0.5g: the one genuinely Layer-2-shaped demand is values the typed grammar refuses on properties that already have groups — multi-layer/inset `box-shadow` (#1041, refused at lib/apply.php:1669) and repeating gradients on `background.fill` (#505/#639). A `raw`-typed escape on an existing param would reach both. *For:* it goes where the escape pressure actually is; it needs no property allowlist, no disjointness rule and no new grain; it is the design doc's *"the valve is never LESS capable than the layer it escapes"* applied to the dimension that is actually binding; and it is **the one place a conventions-only check is honest** — escaping a typed grammar *is* reduced verifiability, so `custom_styling_conventions_only` would be true on every band it fired on instead of false on all of them (which resolves Q1 rather than dodging it). *Against:* it is a different design from the one §1-§7 specify (most of this contract's storage and allowlist sections would be replaced by a per-param `raw` marker); it reopens the write-accept/paint-nothing exposure deliberately, on two named params rather than everywhere; and #505 is an open *design-surface decision* of yours (*"should the bounded gradient grammar admit repeating gradient forms?"*), so it partly presupposes a ruling you have not made.

**Three options the outside review found that I had missed. All three attack the same joint, and it is a joint I introduced without noticing: §1.5's universality is what forces safety rule (d), and rule (d) is what does most of the emptying.**

- **Option 6 — a CANARY Layer 2: build the mechanism, admit one property that is not an authoring feature.** No AI exposure, no docs section; one fixture- or maintainer-only entry so that every guard has a real subject. This answers Option 2's fatal objection directly (guards over an empty set) and **the repo has a proven precedent for exactly this shape**: `SchemaValidationTest::testTheValuesGuardStillReachesTheSlotSurfaceWithNoLiveSlotEnumShipped()` is a synthetic declaration through the real entry point, carrying a vacuity guard that retires it the moment a real one ships (§2.5a). *For:* validates storage, validation, emission, drops, minting and disclosure for real, while admitting nothing to authors and pre-empting no group home; Sprint 3's evidence then sizes the public list. *Against:* a mechanism nobody can use is still maintenance, and a synthetic-only entry has to be impossible to reach from a real write — which is one more thing to pin.
- **Option 7 — per-role schema OPT-IN, reversing §1.5.** §1.5 rejected opt-in on the grounds that an escape must be reachable wherever the grammar fell short. The review's counter is sharper than my argument: **universality is precisely what makes element fit unanswerable**, because the engine knows selectors and the component author knows elements. With opt-in, rule (d) becomes the component author's answered question rather than a blanket exclusion, and `text-indent`, `word-spacing` and the `list-style` longhands become admissible **on the roles where they mean something**. *For:* it restores the role's `groups` list as a complete contract (§1.5's named cost disappears); it closes the one failure mode with no runtime closure (§13); it un-empties the list on safety grounds rather than by relaxing them. *Against:* a component can withhold the valve, which is the #901 wall one level up — the exact objection §1.5 was written to answer. This is a genuine two-sided call and I no longer think §1.5 settled it.
- **Option 8 — GROUP-SCOPED `_css`: admit a direct property only under a group the role already permits** (`quote.typography._css.text-underline-offset`, and later `panel.layout._css.align-self`). *For:* the role's `groups` list stays authoritative and the ladder stays intact; element fit gets a partial answer for free, because a role that permits `typography` has a text-bearing element; it avoids designing a param for every long-tail property while keeping group authority; and it composes with Option 6 or 7. *Against:* it needs a per-group property classification (which group may host which property) — a third CSS-knowledge table beside the allowlist and the family map; and it makes a shorthand collision *more* likely rather than less, since the property now sits beside params that emit neighbours.

**My reading of the three:** Option 8 is the most architecturally attractive and the most work; Option 6 is the cheapest thing that is not dishonest; Option 7 is the one that changes a decision §1 already made and therefore most needs your ruling rather than mine. None of them changes my top-line advice — the evidence still says today's demand is group-shaped and selector-shaped — but all three defeat the claim that Layer 2 *cannot* be built usefully now, and that claim was in my first draft.

**The counter-argument to my own recommendation, stated fairly**, because it is the design doc's central argument and §0.5 does not defeat it: **absence of filed demand is not absence of demand.** Nobody files an issue for a property they never attempted, and the whole thesis of the pivot is that the treadmill *"fixes what was filed, never what the next design system will need."* A demand sweep can only ever measure the past. What I can say is narrower and is what §0.5 actually supports: **the demand that exists today is group-shaped and selector-shaped, and none of it is Layer-2-shaped** — and Sprint 3's brand reconstruction is the exercise designed to produce the cross-brand evidence Layer 2 would be sized from. Deferring to it is deferring by one sprint to the measurement the program already planned, not deferring indefinitely on a thin argument.

**Recommendation, revised after the outside review: Option 3 on priority grounds** — today's measured demand is group-shaped and selector-shaped, and `Layout` is first-cut debt nobody is tracking. **If you want the spec item closed in this sprint regardless, take Option 6 or Option 8, not Option 2** — Option 2 is the one version that ships guards asserting on an empty set, and Option 6 fixes exactly that for the price of one synthetic entry on a pattern the repo already uses.

**Option 7 is the one I am explicitly not recommending either way.** §1.5 decided universality and the review showed that decision is what forces safety rule (d) and does most of the emptying. That is a real two-sided call between two of your own principles (an escape must be reachable where the grammar failed, versus no input may report success and paint nothing), and it is yours.

I am still not recommending **Option 1**: admitting `opacity` contradicts eight shipped statements including two in the AI's own instructions. Note honestly that this is now the *only* objection to it — `opacity` passes every safety rule, so Option 1 is sound-but-contradictory rather than unsound, which is a weaker case against it than my first draft made.

**Two riders, cheap and independent of whichever option you pick.** Both were found by the sweep, both carry measured defects, and neither needs a layer, a ruling or an engine change:

1. **#1069 is two schema lines.** *"No role can reach a link inside a rich-text surface"* — but the role-selector charset already admits space and dot, and **section and cta already ship `.section__content a` and `.cta__body a` as roles today.** faq and hero need the same two declarations. The issue currently ships a documented dark-panel write at **3.21:1**.
2. **#1032 is one schema line** — a missing role on `.section__body`; `max-width` is already emitted.

**BOTH WERE APPROVED AND HAVE SHIPPED** on this branch, independently of Q0 — see the two commits after this contract's own. Neither turned out to be quite the one-liner this section promised, and the difference is recorded because it is the useful part:

- **#1069 was two roles, not two lines**, plus a false sentence to delete, plus the prompt paragraph that obligation needs in order to reach the model at all (#1059's lesson), plus a fixture that made `hero.proof-link` verifiable — it had passed the engine's selector sweep VACUOUSLY, because that sweep looks for a bare `<a` anywhere in the rendered html and hero renders its CTAs as anchors (filed as **#1081**).
- **#1032's one line had a cost the issue did not name.** Restoring the wrapper's `max-width` fixes the strip's centring (measured: 224px adrift at 1280 and 1600, 32px at 768, 0 after) — and, because the children have carried their own caps since #1023, it makes a wrapper cap a CEILING they cannot exceed. The four-role widening recipe this repo's own how-to taught now renders 640px instead of 736px, accepted, with no finding. The how-to teaches five roles now and says so; the missing engine disclosure is filed as **#1080**. That interaction was found by A/B-ing the cap in the browser, not by reading the issue.

A third, smaller thing worth ruling while you are here, because §0.5d found it and it is nobody's task: **`Layout` is a FIRST-CUT group in the approved coverage table and no group exists**, with four in-code workarounds and open demand (#658, #588, #905). It is grid-adjacent, so it may belong with the Addendum-B/grid work — but it is currently unscheduled and unrecorded as a gap. Under Option 3 it becomes T8; under every other option it still needs a home.

### Q1 — Typed or conventions-only? (the departure from the approved design doc)

The design doc says Layer-2 declarations are *conventions-checked, not proof-checked*, with a `custom_styling_conventions_only` finding. §2.2 argues that contradicts I19, I30, I31 and the #570 ruling-6 convergence rule, and would manufacture a new instance of the class #1048 is open against.

- **Option A (recommended) — TYPED.** Every allowlist entry carries a type from the shared grammar owner. Nothing is conventions-only; no new finding type. Cost: a novel *value shape* still needs the shared owner to learn it.
- **Option B — as the design doc is written.** Untyped, conventions-only, maximum escape. Cost: knowingly creates write-accept/paint-nothing on the same channel #1048 is open against, against four invariants.
- **Option C — typed, with a named `raw` escape.** Typed by default; a specific, enumerated property whose grammar the shared owner genuinely lacks may carry `type: 'raw'` (shared guards only) **and that property alone** carries a conventions-only finding. Preserves the design doc's intent exactly where it is load-bearing and nowhere else.

Recommendation: **A**, moving to **C** only if Q2's evidence names a property that needs it.

### Q2 — The admission principle (the contents answered themselves)

§2.5 was drafted as a six-property table and the evidence emptied it: every candidate fails admission rule (a), (b) or (c), and §0.5e shows that is structural — the properties with no design family are the nine already ruled into `STRUCTURAL`. So there is no contents question left; there is only a principle question, and it decides whether the emptiness is respected or overridden.

**Does a property need CITED DEMAND to be admitted, or is "designable, disjoint, not structural" sufficient?**

Recommendation: **cited demand.** It is the *benchmark-detects-never-specifies* discipline, it is Layer 0's demand-scoped precedent, and it is the rule that keeps the security boundary as small as the evidence justifies. Note the consequence honestly: under cited demand the allowlist is empty today, which is Q0 Option 2 or 3. Under "designable, disjoint, not structural" it is *also* empty (§0.5e), which is why Q0 Option 4 cannot be delivered without first reversing nine written `STRUCTURAL` rulings — and that is a bigger ask than Layer 2.

### Q3 — Storage name and grain confirmation

`_css` inside a role map at role/`_band` grain (§1), rather than the design doc's `custom_css` prop — rejected on the verified fact that a top-level item key is gated by nothing (lib/admin.php:4138-4142) and that a prop is the wrong authority. Recommendation: **confirm `_css`**. If a longer key reads better to the maintainer (`_declarations`), that is a free rename now and a breaking one later.

---

## 9 — Acceptance criteria

*Q0 was answered BUILD (R0), so these bind. Re-derived against R1′/R2′ — the original list
assumed a typed, disjoint allowlist and three of its seven items described a layer that is
not the one being built.*

1. Broad admission implemented as the §2′.1 charset plus the §6.0 exclusions, with **no
   property list to maintain**; the charset red-proofed hostile-byte by hostile-byte.
2. Typed-where-known, verbatim-where-not, and `@references` refused on untyped properties.
3. `_css` outranks a group value **in both key orders**, and the collision is disclosed.
4. The unchecked set is disclosed, and the disclosure fires on a band that mints no token.
5. The mint round trip works in both directions (accept the engine's own, refuse a squat).
6. Whatever the write gate accepts, the emitter emits or ledgers — pinned both ways.
7. Presets refused at both grains; chrome reached through the same engine.
8. `lib/ai-context.php` carries the structured-first principle and a derived exclusion list.
9. Rendered evidence at 375/768/1280 including a dark band and a stressed value.
10. Both suites green at every commit.

1. The contract above, as ruled, implemented with **zero new validators** and **zero forked grammar**: one registry function, one params-table seam, the existing write gate, the existing emitter, the existing findings channels.
2. Disjointness (by shorthand family), the one-home rule, and every allowlist type's existence in the shared grammar owner: each pinned by a test.
3. Red-proofs on real surfaces: hostile properties and hostile function values **refused at write** and **dropped-with-a-ledger-entry at emit**; allowed ones round-trip through the real CLI and paint in Chromium.
4. Both-direction pins per §4.3.3, including the §3.4.1 mint-reservation pin — **flagged CRITICAL under the regression rule**: it is not new-code coverage, it is a path that works today and that Layer 2 breaks silently if missed, turning every band holding a responsive `_css` value into a permanent false refusal on `restore_composition` and `wp pp check page`.
5. Evidence at 375 / 768 / 1280 including a dark band, with computed reads and persisted screenshots.
6. `lib/ai-context.php`, `wp pp schema`, and the AI-facing docs updated in the same change, derived from the registry (§7).
7. Both suites green at every commit; baseline at 1596f22 is PHP 5160 tests / 32651 assertions / 11 warnings / 2 deprecations, JS 1567.

## 10 — Out of scope

Layer 3 (the custom band), the AI-instruction rewrite (#1009, the next task), item grain (Addendum B / #1024), escape telemetry, `min()`/`max()`, closing #1048 / #1059 / #1061 / #993 / #1062, and the rich-text selector gaps #1049 / #1068 / #1069.

---

## 11 — NOT in scope (considered, deliberately deferred)

| Deferred | Rationale |
|---|---|
| Layer 3 (the custom band) | gated on Layer-2 escape telemetry by the design doc; and §0.5f says the selector pressure it would serve is real but four-dimensional, needing its own contract |
| Item grain (`_css` on one card) | Addendum B / #1024 is unruled; shipping it here pre-empts the owner |
| `min()` / `max()` | a widening of the shared grammar owner for every value in the program (§2.6) |
| A `filters` / `transform` / `position` / `animation` group | 2nd cut in the approved coverage table; scheduled, not skipped |
| The **`Layout`** group and `Sizing → alignment` | **1st cut and unbuilt** (§0.5d) — flagged to the owner as an unscheduled gap, not claimed by this task |
| `text-shadow` (Typography, 1st cut) | unbuilt, zero cited demand; named so it is not lost |
| Escape telemetry / `custom_styling_conventions_only` | the design doc places telemetry post-wedge and non-gating; and under §2.2 nothing is conventions-only, so the code name would be false (§5.2) |
| Closing #1048, #1059, #1061, #993, #1062 | each is its own open issue; Layer 2 inherits them and worsens none |
| #1049 / #1068 / #1069 / #1071 / #1032 rich-text and descendant gaps | selector demand; Layer 2 cannot express them at any value (§6.15). **#1069 and #1032 are two and one schema lines respectively and are named to the owner as riders** |
| Per-role element-type declarations in ten component schemas | the runtime way to close admission rule (d); rejected as a large schema surface for one valve (§2.5, rule d) |
| An `_css` accordion / editor UI | the design doc defers the whole UDC accordion past 2.0.0 |

## 12 — What already exists (and is reused, not rebuilt)

The contract's central engineering claim is that Layer 2 is **almost entirely existing machinery**. Itemised, with the seam sizes:

| Need | Existing owner | Reused how |
|---|---|---|
| write gate | `pp_udc_validate_map()` → `_pp_udc_validate_group_map()` → `_pp_udc_validate_param()` → `_pp_udc_validate_scalar()` → `pp_udc_validate_value()` | unchanged; **two lines** swap the params-table lookup for a resolver |
| emitter | `pp_udc_compile_band()` → `_pp_udc_place()` → `_pp_udc_render_blocks()` | unchanged; **two lines**, the same resolver |
| value grammars + function allowlist | `lib/apply.php` — `_pp_css_length()`, `_pp_validate_color()`, `_pp_validate_gradient()`, the typed keyword validators, `case 'enum'` | unchanged; **one line** passes `allowed` through (§2.5a) |
| injection gate | `_pp_forbidden_css_construct()` | unchanged, runs ahead of every type |
| CSS-source-sink gate | `_pp_udc_delimiters_balanced()` | unchanged, already called from `pp_udc_validate_value()` |
| breakpoints / states | `pp_udc_breakpoints()`, `pp_udc_states()` + their emit-order siblings | unchanged |
| references | `pp_udc_parse_reference()`, `pp_udc_resolve_reference()`, `_pp_udc_reference_check()` | unchanged; `enum` needs an answer (§2.5a obligation 3) |
| minting | `pp_udc_mint_name()`, `_pp_udc_mint_value()` | unchanged; `_pp_udc_name_is_the_engines_own_mint()` **must learn `_css`** (§3.4.1, CRITICAL) |
| declaration ordering | `_pp_udc_sort_declarations()` / `_pp_udc_property_rank()` | already correct for unranked properties; extend for stable order (§3.5) |
| drop ledger | `_pp_udc_place()`'s `$drops`, `PP_UDC_MAX_EMIT_DROPS` | unchanged |
| disclosure | `udc_token_minted`, `udc_band_value_shadowed_by_role_default` | unchanged; **no new finding type** (§5.1) |
| reflected-text bounds | `_pp_udc_reflect()`, `pp_udc_bounded_list()`, `_pp_render_undeclared_prop_keys()` | unchanged |
| chrome | `pp_udc_validate_site_map()` → `pp_udc_validate_map()` | **verified**: same engine, no chrome copy |
| CAS / undo / ring / preview | the composition value and `pp_composition_content_hash()` | free, by storing inside `udc` (§1.2) |
| derived AI prose | `pp_udc_group_summary()`, `pp_css_grammar_summary()` | pattern reused for `pp_udc_css_property_summary()` (§7.2) |
| structural-CSS boundary | `designOffencesIn()` and its three sets | consulted by the one-home rule; fail-closed posture copied for the family map |

**Nothing here is rebuilt.** Total new surface: one registry function, one static-cached resolver, one family map, one derived prose function, and their tests.

**One thing the design doc says to reuse that must NOT be reused.** It describes the allowlist as *"a PP-owned superset of `safecss_filter_attr()`'s conservative list."* Read against WP core (wp-includes/kses.php), that framing is wrong on both axes: WP's `safe_style_css` list contains properties PP has deliberately ruled `STRUCTURAL` (`border-collapse`, `caption-side`, `border-spacing`) and layout properties PP has no group for (`columns`, `column-count`, `column-gap`, `column-rule`, `column-span`, `column-width`, `display`), so it is not a subset of "designable"; and its value guard is a **denylist regex** (`%[\\\(&=}]|/\*%`) that rejects **every parenthesis**, so it would refuse `calc()`, `clamp()`, `rgb()` and `var()` outright — strictly incompatible with PP's per-type function allowlist. PP's list must be independently derived, and the contract says so rather than inheriting a framing that reads as reuse.

## 13 — Failure modes (one realistic production failure per new codepath)

| Codepath | Realistic failure | Test? | Error handling? | Silent? |
|---|---|---|---|---|
| resolver at the write gate | a near-miss key (`css`, `_cs`) reads as a group, or the refusal lists a set that omits `_css` | C1 pin | the unknown-group refusal | no |
| resolver in the compiler | an edit moves one side; gate and emitter diverge | both-direction pins (§4.3.3) | drop ledger entry | no |
| **mint-name decoding** | `_pp_udc_name_is_the_engines_own_mint()` does not know `_css`, so **every stored band holding a responsive `_css` value becomes a permanent false refusal** on `restore_composition` and `wp pp check page` | **CRITICAL regression pin (§3.4.1)** | none — this IS the error | no, but loudly wrong, which is worse |
| **preset path after the §1.5 bypass** | the universal-permission bypass leaks into `pp_udc_validate_preset_definition()`, admitting a site-wide named escape nobody can attribute to a band | **§6.13a both-direction pin** | none if missed | **yes** |
| **element-fit (admission rule d)** | an allowlisted property is inert on the element a role actually selects; accepted, stored, reported applied, paints nothing forever | **none is mechanically possible** — the engine knows selectors, not tags | none | **yes** |
| declaration ordering | a Layer-2 property sorts by alphabetical fallback; an emit test pins an accident | emit suite reading `pp_udc_breakpoints()` (#1052's lesson) | stable either way | yes, harmless |
| hot-loop resolver | a per-call merge costs an allocation per group/role/band/source/request | the 50-band measurement (§3.6) | none | yes |

**Three critical gaps, each with its named closure:** the mint regression (closed by the CRITICAL pin), the preset-bypass leak (closed by §6.13a's pin), and **element fit — which has no runtime closure at all.** That third one is the honest reason admission rule (d) is an *admission* rule rather than a check: the only way to keep it true is to never admit a property that can be inert on an element. It is also, independently, the reason §2.5's table is empty.

## 14 — Parallelization

**Sequential implementation, no parallelization opportunity.** Every task touches `lib/udc.php` as its primary module; the docs and `lib/ai-context.php` work derives from the registry function and cannot start before it. One note if the work is ever split: the family map (T2) and the registry (T1) are the same file and the same commit — splitting them produces a map with nothing to classify.

## 15 — Implementation Tasks

**SUPERSEDED BY THE BUILD.** This list was derived under the typed/disjoint rulings and
several of its tasks describe work that no longer exists (T2's shorthand-family map died
with disjointness; T5's `enum` pass-through is unnecessary once values are generic). What
was actually built, and why, is in the commit series on this branch and in §2′/§4′ above.
Kept unedited because the delta between a plan and its build is worth being able to read.

**All conditional on §8 Q0 being answered Option 1, 2 or 4.** Under Option 3 this issue closes as the recorded contract and these tasks travel with it. No task is invented: each derives from a section or a review finding above.

- [ ] **T1 (P1, human: ~3h / CC: ~25min)** — `lib/udc.php` — add `pp_udc_css_properties()` and the static-cached `_pp_udc_params_for_group()` resolver; route the write gate and the compiler through it
  - Surfaced by: §2.1 (the seam), P1 (static-cached, not a per-call merge)
  - Files: `lib/udc.php`
  - Verify: `./vendor/bin/phpunit --configuration phpunit.xml`
- [ ] **T2 (P1, human: ~2h / CC: ~15min)** — `lib/udc.php` + tests — the shorthand-family map, fail-closed on an unclassified property on both sides, plus the disjointness and one-home pins
  - Surfaced by: A1 (no family table exists in the repo; `white-space` proves omissions are non-obvious)
  - Files: `lib/udc.php`, `tests/UdcEngineTest.php`, `tests/js/css-lint.test.js`
  - Verify: both suites; plant an unclassified property and confirm the assertion count moves
- [ ] **T3 (P1 CRITICAL, human: ~1h / CC: ~10min)** — `lib/udc.php` — teach `_pp_udc_name_is_the_engines_own_mint()` the `_css` pseudo-group; both-direction regression pin
  - Surfaced by: §3.4.1 — a REGRESSION, not new coverage (regression rule: no approval needed, it lands)
  - Files: `lib/udc.php`, `tests/UdcEngineTest.php`
  - Verify: an engine mint accepted, an author squatting it refused, over a STORED composition
- [ ] **T4 (P1, human: ~1h / CC: ~10min)** — `lib/udc.php` — refuse `_css` in `pp_udc_validate_preset_definition()` at both grains, before dispatch, so the §1.5 bypass cannot leak
  - Surfaced by: §6.13a — a silent critical gap if missed
  - Files: `lib/udc.php`, `tests/UdcPresetStateMotionTest.php`
  - Verify: preset refused at role and group grain; band value still accepted on a role whose `groups` omits `_css`
- [ ] **T5 (P2, human: ~30min / CC: ~5min)** — `lib/udc.php` — pass `$param['allowed']` through `pp_udc_validate_value()`; give `_pp_udc_reference_check()` an `enum` answer
  - Surfaced by: §2.5a — reuse `case 'enum'` instead of forking a validator
  - Files: `lib/udc.php`
  - Verify: an out-of-set keyword refused at write AND at emit; an `@reference` resolving out-of-set refused
- [ ] **T6 (P2, human: ~2h / CC: ~20min)** — tests — a `RoleDefaultsEmitTest`-pattern `_css` emit suite reading breakpoints from `pp_udc_breakpoints()`, plus the §14.1 authoring-path test through the real `update_composition`
  - Surfaced by: §4.4; #1052 (three existing harnesses hardcode the breakpoints)
  - Files: `tests/UdcCssDeclarationsEmitTest.php`
  - Verify: base / tablet / phone / each state / a minted responsive value
- [ ] **T7 (P2, human: ~1h / CC: ~10min)** — `lib/udc.php` + `lib/ai-context.php` — `pp_udc_css_property_summary()` derived from the registry, one prompt paragraph leading with the ladder, `wp pp schema` output, and the prose-vs-registry pin
  - Surfaced by: §7 and #1059 (a schema `description` never reaches the prompt)
  - Files: `lib/udc.php`, `lib/ai-context.php`, `tests/AiContextTest.php`, `tests/CliSchemaCommandTest.php`
  - Verify: the pin fails when a registry entry is added without the prose changing
- [ ] **T8 (P2, human: ~1h / CC: ~10min)** — `lib/udc.php` + docs — extend `_pp_udc_property_rank()` for stable ordering; update `AI_RULES.md`, `AI_CONTEXT.md` and the `docs/` pages whose "what no role reaches" rosters this changes
  - Surfaced by: §3.5, §7.2 item 5
  - Files: `lib/udc.php`, `AI_RULES.md`, `AI_CONTEXT.md`, `docs/`
  - Verify: `npm test` (`DocsCoverageTest`, `DocumentedUdcSnippetsTest`)
- [ ] **T9 (P2, human: ~1h / CC: ~15min)** — evidence — the 50-band before/after measurement on the same harness as the recorded preset numbers, plus screenshots at 375/768/1280 including a dark band
  - Surfaced by: P2, §9.5
  - Files: (evidence only)
  - Verify: numbers reported in the handoff against lib/udc.php:3124-3135

## 16 — Cross-model tension (the outside voice vs this review)

The outside voice (Codex, account default model, high reasoning, content-inlined because its sandbox cannot run shell or git on this container) was pointed at one question: *is the empty-allowlist conclusion wrong, and is the recommended alternative worse?* Its findings are recorded because they changed the contract, not as decoration. Where they were internal-consistency failures of this document against itself, they are folded in above. Where they open a choice, they are Q0 options and yours.

| Topic | This review said | Outside voice said | Disposition |
|---|---|---|---|
| Why the allowlist is empty | "empty by construction rather than by accident" | *"partly manufactured"* — cited demand and the group-home test are prioritisation rules, not safety rules; `opacity` passes every safety rule | **Folded.** §2.5 now separates SAFETY / COHERENCE / POLICY and states that rule (c) is what empties the list |
| The group-home test | an admission rule | self-defeating as a disqualifier: it drives the list toward exactly the `STRUCTURAL` set | **Folded.** Demoted to POLICY |
| A future group home as an objection | disqualifying | incoherent against §2.3's own promotion-is-a-breaking-change clause | **Folded** |
| Evidence reach | the demand sweep shows the set is empty | a sweep can show "not urgent today"; it cannot show "no admissible member exists", least of all for a valve whose purpose is cross-brand unknowns | **Folded** — and it is the same limit §8's own counter-argument paragraph already conceded, so the two agree |
| Option 3 | "re-aim T8" | Layout is first-cut debt, **not a substitute** for the valve; calling it one is sleight of hand; and it is probably harder than one task | **Folded**, with one verified qualification: #658 and #588 are section-shaped, so the rebuilt components' share of Layout is independent of Addendum B |
| Option 2 | dismissed on the vacuous-guard argument | agreed the problem is real, but it has a simpler fix than dropping the option | **Folded as new Option 6** (canary), on the repo's own synthetic-slot-enum precedent |
| §1.5 universality | settled | universality is what forces rule (d) and does most of the emptying; per-role opt-in answers element fit properly | **NOT folded — new Option 7, a genuine fork for you.** I no longer think §1.5 settled it |
| Reaching a property at all | via a top-level `_css` pseudo-group | admit it **under a group the role already permits** | **NOT folded — new Option 8.** Most attractive architecturally, most work, needs a third classification table |
| Selector-pressure evidence | Layer 2 addresses none of the measured pressure | true, but that argues Layer 2 should not be *sold* as solving today's pain — a different problem, not a disproven one | **Folded** as a wording correction in §0.5f |

**Where the two agree, which is the strongest signal in this document:** do not ship a public, documented, author-facing Layer 2 in this sprint. Codex's own closing line is *"Defer public Layer 2, but do not conclude the allowlist has no admissible member."* That is Option 3 or Option 6, and it is not Option 1 or Option 4.

**Nothing here has been applied as a decision.** The folded items are corrections to this contract's own reasoning; the three new options are additions to Q0's menu. Q0 is unanswered.

## GSTACK REVIEW REPORT

| Review | Trigger | Why | Runs | Status | Findings |
|--------|---------|-----|------|--------|----------|
| CEO Review | `/plan-ceo-review` | Scope & strategy | 0 | — | — |
| Outside Review | `codex exec` (account default model, no override; content-inlined per the repo's Codex playbook) | Independent 2nd opinion | 1 | completed | 11 findings: 5 folded as internal-consistency corrections, 3 added as Q0 options 6-8, 2 folded as wording, 1 agreement |
| Eng Review | `/plan-eng-review` | Architecture & tests (required) | 1 | issues_open | 8 issues (3 architecture, 2 code quality, 1 test-meta, 2 performance), all folded; 3 critical failure-mode gaps named; 4 questions open to the owner |
| Design Review | `/plan-design-review` | UI/UX gaps | 0 | — | not applicable — no UI in this change |
| DX Review | `/plan-devex-review` | Developer experience gaps | 0 | — | — |

**OUTSIDE COVERAGE:** provider codex, phase plan-review, **completed**. Ran with **no model override** (account default `gpt-5.5`), deliberately: a 10/10 prior learning records that on a ChatGPT-account Codex login every explicit `-c model=` override returns HTTP 400 and the outside voice silently degrades to unavailable. Probe used a computed value (17 × 23 → 391) rather than an echoable token, per the same learning. Content was inlined because Codex's sandbox cannot run shell or git on this container (pipeline Section 12); sections 3-7 were summarised for length and that truncation is disclosed in the prompt.

**CROSS-MODEL:** §16. Both reviewers independently converge on *do not ship a public author-facing Layer 2 this sprint*; they diverge on whether the allowlist is empty for structural or for policy reasons, and the outside voice is right — that correction is folded.

**VERDICT:** ENG REVIEW COMPLETE, PLAN NOT CLEARED TO IMPLEMENT — Q0 is unanswered and every implementation task is conditional on it. The contract itself is implementation-ready; whether to implement it is not this review's call.

**UNRESOLVED DECISIONS:**
- **Q0** — whether to build Layer 2 at all, and if so as which of the eight options (§8 Q0). Recommended: Option 3; Option 6 or 8 if the spec item must close this sprint.
- **Q1** — typed vs conventions-only, a departure from the approved design doc (§8 Q1). Recommended: A (typed).
- **Q2** — whether admission requires cited demand, now that the safety/policy split shows the rules disagree (§8 Q2). Recommended: cited demand.
- **Q3** — storage key `_css` and role/`_band` grain (§8 Q3). Recommended: confirm.
- **Option 7's fork** — whether §1.5's universality stands, given that it is what forces safety rule (d). Deliberately no recommendation; it is a choice between two of the owner's own principles.

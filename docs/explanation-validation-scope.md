# What a write is allowed to refuse on behalf of

The composition validator is one engine, and every write goes through it. But the
writes do not all touch the same amount of the page, and for a long time they were all
judged as if they did. This document explains what changed, the rule that replaced it,
and the one fatal bug that proves the rule has to be stated in exactly that shape.

Read this before changing `pp_validate_composition_errors()`, before adding a rule to
it, and before rebuilding a component.

## The problem

`update_component` writes one band. It validated the whole page.

That sounds conservative, and it was defended as conservative for two years: a page
carrying a stale value could not be edited anywhere until the stale value was fixed,
which was recorded in the code as "the accepted stale-data cost" of having no
backward-compatibility layer.

It was not payable. The documented cure for a retired prop is to send it as `null`,
which removes the key, and that cure runs through `update_component` too. So on a page
with retired props on TWO bands, every single-band clear was refused by the other band:

```
page = [hero(button_variant), section, testimonials(theme)]

clear hero's key       -> refused, naming testimonials
clear testimonials' key -> refused, naming hero
edit the middle band    -> refused, naming hero
```

Measured, not reasoned. And it is the ordinary shape of a 1.x page, because hero and
testimonials were migrated in the same sprint, so a real site has both. A way out that
cannot be taken is not a way out.

The narrowing was also overdue on its own terms. `add_component` has always validated
only the item it adds. So have `style_component`, `remove_component` and
`reorder_components`. The accepted-write envelope already carries the whole page's
errors precisely BECAUSE those actions accept writes onto pages whose other bands are
stale. `update_component` was the outlier, not the guardian.

## The approach

`pp_validate_composition_errors()` takes an `$only_index`. It narrows the PER-ITEM
loop to one band and leaves everything else running.

```
$only_index = null              $only_index = 2
┌────────────────────────┐      ┌────────────────────────┐
│ structural:  0, 1, 2   │      │ structural:  0, 1, 2   │  ← unchanged
│ per-item:    0, 1, 2   │      │ per-item:    2         │  ← narrowed
│ cross-item:  whole page│      │ cross-item:  whole page│  ← unchanged
└────────────────────────┘      └────────────────────────┘
```

One gate, not two. The rules, their order, their codes and their locators are identical
either way; this is a scope parameter on the single engine every ingress traverses, not
a surface-specific second validator.

What the caller loses is only the refusal. The page's other problems move to the
ACCEPTED envelope's `findings`, at severity `error`, each naming its own band by
`index` — the "refuse narrowly, advise page-wide" contract `restore_composition`
established and every accepted write inherited.

## The rule, in the shape the bug forced

The obvious statement of the rule is "per-item rules narrow, cross-item rules do not."
That statement is wrong, and shipping it cost an uncaught fatal.

The two structural checks — a band with no `component` key, and a band whose
`component` is not a scalar — are per-item in FORM. They are page-level in CONSEQUENCE,
because they are the shapes the composition WRITER indexes into, and that writer is
deliberately non-defensive:

```php
foreach ($composition as &$item) {          // pp_update_composition()
    $props = $item['props'] ?? [];          // fatals if $item is a string
```

So a composition like `["a-scalar-band", {hero}]` — a list, therefore a page the
classifier calls HEALTHY — passed a band-scoped gate aimed at the hero and reached that
loop, throwing `Cannot access offset of type string on string`. A 500 or a WP-CLI fatal
on a supported authoring surface, where the old whole-page gate had cleanly refused and
named the band. Invariants I16 and I17, both.

The rule is therefore:

> **Rules the WRITER depends on stay unconditional. Everything else narrows.**

Concretely, three tiers, and the order in the loop is the rule:

| Tier | Scope | Why |
|---|---|---|
| structural identity | every band, always | the writer indexes into these shapes and cannot survive them being wrong |
| per-item rules | the targeted band | they describe one band's contract and speak for no other |
| cross-item rules | the whole page, always | a duplicate id is a property of the page, and the writer re-serializes every band, so accepting the write would store it again |

A new rule added to this engine has to be placed against that table deliberately. The
question is not "is it per-item?" — it is "can the writer survive this being wrong on a
band nobody is looking at?"

## The exceptions, stated rather than implied

**A cross-item defect still refuses every band, and that is honest.** A pre-existing
duplicate `props.id` between two untargeted bands blocks an edit to a third. Both
cross-item messages say so and name `update_composition` as the route out. The reason
is not caution: the writer re-serializes the whole composition, so accepting the write
would persist the collision again.

**The budget interacts with the cross-item passes, benignly.** Those passes are skipped
when a finding already exists and a budget is set. Under a band scope that means: if the
targeted band is dirty, the cross-item passes do not run — but the write is already
being refused by the targeted band's own error, so nothing is stored. If the targeted
band is clean, they run. The cost is that when both are wrong, the message names the
targeted band and stays silent about the collision; the operator repairs one, retries,
and meets the other. Two round trips, never a silent accept.

**The whole-page verbs did not change.** `create_page` and `update_composition` submit a
whole composition and judge a whole composition. They are the two verbs where a
rejection can still name a band the caller never mentioned, which is why the `index`
field on a rejected envelope still matters.

## Two schema keys, and the rebuild pattern

The narrowing is only half the fix. The other half is that a refusal on a retired key
has to name where the value went, which is what `retired_option` established for the
chrome site options: a rejection plus a list of twenty valid names makes the author
guess which of the twenty replaced theirs.

Two schema keys carry that, and **every component rebuild declares its own as part of
the rebuild**:

```jsonc
"retired_props": {
  "_note": "Props this component declared on 1.x that the v2 rebuild retired (#1007) ...",
  "button_variant": "the `cta` role's `udc` map — set `border`, `background` and ..."
},
"refuse_props_when": [
  {
    "when":    [{ "prop": "layout", "equals": "cover" }],
    "props":   ["image_url", "image_id"],
    "message": "paints nothing on the \"cover\" layout: a band background image is ..."
  }
]
```

`retired_props` drives the `retired_prop` refusal: its own error code, so a caller can
tell "this moved, and here is where" from "you typo'd" without string-matching prose.
`refuse_props_when` drives `inert_prop`, for a prop that is declared, well-typed and
stored but paints nothing in the configuration the band is actually in. Its `when`
clauses reuse the `applies_when` grammar rather than inventing a second predicate
language.

**Both are guarded in both directions**, by tests that iterate the registry rather than
naming components, so a new component's block is covered the moment it is added:

- a key declared retired that still exists in `props` fails — otherwise the refusal
  tells an author to rewrite working content;
- a route naming a role the component does not declare fails — a rename during a
  rebuild is exactly when that happens;
- a `refuse_props_when` clause that the shared grammar rejects fails, as does an empty
  `when`, a prop the component does not declare, or a missing `message`.

**Style slots need no such registry and must not grow one.** All 223 retired slots
(hero 49, section 47, cta 40, testimonials 27, faq 21, stats 17, logos 8, embed 8, table 6 —
the count is the arithmetic, so a rebuild that leaves it alone is visible here) belong
to components `pp_udc_is_v2_component()` already identifies, and every one is replaced
by the same thing — the band's `udc` map — so that route is derived at runtime and
cannot drift. Only props need naming, because their replacements differ per prop.

## Trade-offs

**What was given up.** A page can now carry stale bands indefinitely while remaining
fully editable. Before, the lockout forced a repair. That pressure is gone, and the
disclosure on every accepted write is what replaces it — which is a weaker forcing
function and a much better one, because the previous pressure could not be relieved.

**Nothing is healed behind the author.** Narrowing the refusal did not become repairing,
coercing or migrating: a stale band that a write did not touch is re-serialized exactly
as it was stored. The one thing the writer does do to untouched bands is mint band ids
and normalize responsive literals, which it did before this change too.

**Refusal is fail-open on ambiguity, and that is the opposite of the advisory it shares
a grammar with.** `pp_applies_when_clause_met()` resolves every ambiguity to "met",
because its original consumer is a warning and staying silent is the safe direction for
a warning. For a refusal "met" means REFUSE, so the same answer is the unsafe direction:
a `layout` stored as an array would refuse `image_url` with a confident message about
backgrounds while the real defect went unmentioned. The blocking consumer therefore
decides ambiguity for itself and declines to refuse.

## Alternatives considered

**Pure band-scoping** — narrow the whole per-item loop with no exceptions. Rejected on
evidence: it is what produced the fatal above, and it would also have skipped
`duplicate_component_id`, letting one call collide two bands' `props.id` and persist the
wrong-targetable state that rule exists to prevent.

**"Block only new or worsened errors in the targeted band."** Proposed by the outside
voice and rejected as specified: "worsened" needs a before-state to diff against and has
no stable definition, where the tier table above is decidable from the rule alone.

**Making the writer defensive instead** — have `pp_update_composition()`'s `props.id`
loop test `is_array($item)`. That is the durable fix and it removes the residual for
`add_component` and `reorder_components` too, but it belongs to the issue that owns
that loop rather than to a change narrowing a gate. Tracked separately.

## Related

- `lib/admin.php` — `pp_validate_composition_errors()`'s `$only_index`, and
  `pp_validate_composition_band()`, the entry point that owns the scoping rule.
- `lib/actions.php` — `update_component`'s validate arm, and `pp_execute_action()`'s
  findings attachment, which is what makes the narrowing honest.
- `docs/reference-apply-cli.md` — the `retired_prop` and `inert_prop` sections, and the
  schema-key table.
- `docs/v2/INVARIANTS.md` — I4 (one gate every ingress traverses), I16 and I17 (nothing
  fatals on stored data), I24 (a stated reason AND a route back), I35 (no silently
  ignored input).

# Add a New Component

Follow these steps to add a new reusable component to PromptingPress.
The auto-loader picks up any component at `/components/{name}/{name}.php` — no registration needed.

---

## Step 1 — Create the component directory

```bash
mkdir components/mycomponent
```

Replace `mycomponent` with your component name (lowercase, no hyphens — use underscores if needed).

---

## Step 2 — Create the component PHP file

Create `/components/mycomponent/mycomponent.php`:

```php
<?php
/**
 * components/mycomponent/mycomponent.php
 *
 * Brief description of what this component renders.
 * Props: see schema.json
 *
 * @var array $props
 */

// Declare all props at the top with defaults.
$title = $props['title'] ?? 'Default Title';

// GUARD EVERY PROP THAT REACHES esc_url() OR wp_kses_post() (#730). Those two are
// WordPress CORE functions and both are UNTYPED, so nothing in their signature warns
// you — but each reaches a string-only PHP builtin before it sanitizes anything, and a
// stored array or object raises a TypeError there. templates/composition.php renders
// bands with no try/catch, so that is a whole-page 500, not a missing fragment.
//
//   esc_url( ['x'] )        TypeError: ltrim(): ... must be of type string, array given
//   wp_kses_post( ['x'] )   TypeError: str_contains(): ...
//   wp_kses_post( $object ) TypeError: preg_replace(): ...
//
// The write path rejects these shapes, but it gates WRITES, not STORAGE: restore
// reports without blocking (#233), pre-rule compositions still carry old values, and a
// raw _pp_composition meta write is not gated at all.
//
// is_scalar, NOT is_string: PHP runs coercive here, so a stored int/float/bool already
// coerced and rendered, and is_string() would silently drop it. Since #707 the write
// path REJECTS a non-string scalar on a type:"string" prop, but that gates writes, not
// the stored data above — a pre-#707 page still holds 42 and still has to render.
// Guard at the READ so every gate below sees the guarded local.
// NEVER try/catch a wp_kses_post() TypeError to recover — the throw escapes between
// core's remove_filter('pre_kses', …) and the matching re-add, which de-registers
// block-attribute KSES for the rest of the request.
$raw_text = $props['text'] ?? '';
$text     = is_scalar($raw_text) ? (string) $raw_text : '';
$raw_link = $props['link'] ?? '';
$link     = is_scalar($raw_link) ? (string) $raw_link : '';

// Props whose contract is an ARRAY take the is_array form of the same idiom, because
// every scalar fatals at an `array` parameter (#708/#739):
//   $raw_items = $props['items'] ?? [];
//   $items     = is_array($raw_items) ? $raw_items : [];
?>
<section class="mycomponent">
    <div class="container">
        <?php if ($title) : ?>
            <h2 class="mycomponent__title"><?php echo esc_html($title); ?></h2>
        <?php endif; ?>

        <?php if ($text) : ?>
            <div class="mycomponent__body">
                <?php echo wp_kses_post($text); ?>
            </div>
        <?php endif; ?>

        <?php if ($link) : ?>
            <a href="<?php echo esc_url($link); ?>" class="mycomponent__link btn">
                Learn more
            </a>
        <?php endif; ?>
    </div>
</section>
```

**Rules:**
- Declare all `$props` variables at the top with `??` defaults
- Use `esc_html()` for plain-text output (titles, labels, button text)
- Use `esc_url()` for all URLs
- Use `esc_attr()` for all HTML attributes
- Use `wp_kses_post()` for rich HTML content (the main prose surface: body/answer)
- **Guard every prop that reaches `esc_url()` or `wp_kses_post()` at the read** with
  `is_scalar($raw_x) ? (string) $raw_x : ''` (#730), and every array-contract prop with
  `is_array($raw_x) ? $raw_x : []` (#708/#739). Both core escapers are untyped but still
  fatal internally on an array or object, which 500s the whole public page. Read each
  guarded prop exactly ONCE, into `$raw_<name>`, so no gate can read the raw value below
  the guard. `tests/InvariantTest.php` enforces both rules and will fail your component
  otherwise. `esc_html()`/`esc_attr()` do NOT need this — they coerce with a warning
  rather than fataling.
- Use `pp_kses_inline()` for supporting-text props that allow a link + light emphasis (a, strong, em, br) but no block elements (#439)
- Do NOT call WordPress functions directly — use `pp_*` wrappers from `lib/wp.php`
- Do NOT call other components from within a component

---

## Step 3 — Create schema.json

Create `/components/mycomponent/schema.json`:

```json
{
  "component": "mycomponent",
  "description": "One-sentence description of what this component does.",
  "props": {
    "title": {
      "type": "string",
      "required": false,
      "default": "Default Title",
      "description": "Main heading."
    },
    "text": {
      "type": "string",
      "required": false,
      "default": "",
      "description": "Body HTML content."
    },
    "link": {
      "type": "string",
      "required": false,
      "default": "",
      "description": "URL for the link button."
    }
  },
  "roles": {
    "_band": {
      "selector": "",
      "description": "The band itself — the <section>.",
      "groups": ["typography", "spacing", "border", "background", "sizing", "shadow", "motion"],
      "defaults": {
        "spacing": { "padding-top": "@pp-band-padding", "padding-bottom": "@pp-band-padding" }
      },
      "obligations": []
    },
    "heading": {
      "selector": ".mycomponent__title",
      "description": "The band heading.",
      "groups": ["typography", "spacing", "sizing"],
      "defaults": {},
      "obligations": []
    }
  },
  "safe_to_edit": ["mycomponent.php", "../../assets/css/components.css (COMPONENT: mycomponent section)"],
  "do_not_touch": ["schema.json without updating this component's README.md and the repo-root AI_CONTEXT.md"]
}
```

**Required keys:** `component`, `description`, `props`. A v2 component also declares
`roles` — that is Step 3b below, and it is the step that decides whether the component
is styleable at all, so read it before you settle the role names above.

### Step 3b — declare `roles`: put the component on the design contract

**This is the step that makes a new component authorable, and the v1 docs had no
equivalent.** A component with props and no `roles` renders, validates, and is
then unreachable by every styling surface the theme has. A `udc` map aimed at it is
refused outright — the measured refusal is `unknown_udc_role`, *"Component "grid" is
not on the UDC styling system, so it accepts no "udc" map"* — and `wp pp schema`
reports no roles. `grid` is the only shipped component in that state, and it is there
because it predates the contract; it is not the model to copy.

A **role** is one visual job on the component: the band, the inner wrapper, the
heading, a button, a card. Authors style a band by naming roles, never by naming
your CSS classes, so the roles you declare *are* the component's styling API.

```json
"roles": {
  "_band": {
    "selector": "",
    "description": "The band itself — the <section>. Maintainer-facing prose.",
    "groups": ["typography", "spacing", "border", "background", "sizing", "shadow", "motion"],
    "defaults": {
      "spacing": { "padding-top": "@pp-band-padding", "padding-bottom": "@pp-band-padding" }
    },
    "obligations": []
  },
  "heading": {
    "selector": ".mycomponent__title",
    "description": "The band heading.",
    "groups": ["typography", "spacing", "sizing"],
    "defaults": {},
    "obligations": []
  }
}
```

**The five keys are the whole surface** (`pp_role_definition_keys()`, `lib/admin.php`),
and all 125 shipped roles declare all five — write all five. An unknown key fails CI,
exactly as it does on a prop. Requiredness is enforced for `obligations` alone today;
the other four are load-bearing rather than policed, so omitting `groups` or `selector`
buys you a role that passes CI and styles nothing.

- **`selector`** — the CSS selector the role's authored values emit at, relative to
  the band. The empty string means the band element itself, which is why `_band`
  declares `""`. It must match what your PHP actually renders; a selector that
  matches nothing is a role that silently styles nothing.
- **`description`** — **maintainer-facing prose.** It reaches `wp pp schema` and it
  does **not** reach the model's system prompt (see `docs/v2/AI-INSTRUCTION-CONTRACT.md`),
  so write it for the next person reading the schema: what the default measured, why
  it is what it is, and which roles it interacts with. Length is not charged to the
  prompt budget here.
- **`groups`** — the UDC groups this role permits. The eight are `typography`,
  `spacing`, `border`, `background`, `sizing`, `shadow`, `layout` and `motion`
  (`pp_udc_groups()`, `lib/udc.php`). Declare only the ones the role's element can
  honour: a group listed here is a promise that writing it changes the rendering. A group
  the role does not list is refused at write with `unknown_udc_group`, which names the
  permitted set back to the author.
- **`defaults`** — the role's own default values, per group. **Measure them, do not
  read them off the stylesheet.** The shipped roles were measured in Chromium at
  375/768/1280 precisely because the stylesheet and the computed value disagree more
  often than they agree.

  **`defaults` and an authored `udc` map do not accept the same values, and this is the
  trap.** A default may reference the shared band-rhythm props — `@pp-band-padding` is
  what every shipped `_band` uses for its padding, stats included — but the *authored*
  path refuses the same string: `invalid_prop_value`, *"references "@pp-band-padding",
  which is not a registered design token. Only site design tokens resolve here."* The
  band props live in a second `:root` block the token registry does not read, so they
  are reachable as a shipped default and not as an author's value. Copy them into
  `defaults` freely; never put one in a doc example of a `udc` map.

**`obligations` is required on every role, and `[]` is a real answer** (#1087,
`SchemaValidationTest`). It is the one part of the role that IS model-facing, so it
is bounded rather than prose. An obligation is a fact about this role that an author
must act on and that no other channel can tell them — two kinds, and the set does not
grow without changing `pp_udc_obligation_kinds()`:

- **`outranked_by_default`** — writing this role alone loses to something already on
  the page, so the pair must be written together. Not derivable from the selectors.
- **`reached_only_by_inheritance`** — this role takes its value from an ancestor
  rather than declaring its own, so setting the ancestor is what moves it.

Each record declares exactly `kind`, `with` and `why` (`pp_udc_obligation_keys()`).
`with` must name a real sibling role on the same component — CI checks it — and `why`
is one line under 240 characters, because it is composed into the prompt.

```json
"obligations": [
  { "kind": "outranked_by_default",
    "with": "number",
    "why": "`number` pins its own colour as a direct declaration, so a band ink set here does not reach it." }
]
```

Write `[]` when the role has no such fact. That is the common case and it is not a
placeholder — it is the declaration that this role stands alone, and the validator
requires you to say so rather than leaving the question open.

**Two more things the roster gets you.** A role name is bounded to
`[A-Za-z0-9_-]{1,64}`, because it is composed into the newline-delimited prompt
catalog and a name carrying a newline would forge a line there. And the roles you
declare are pinned by `ModelFacingRosterTest`: a component that grows a role without
the docs learning about it fails CI, which is the guard against the vocabulary-blind
roster drift that this rewrite exists to end.

### The definition-object contract (issue #575)

A slot or prop **definition object** is a closed surface: `SchemaValidationTest`
rejects any key not on this list, so a typo or a half-landed field fails CI
instead of being ignored at runtime forever. Every piece of declaration-level
metadata is declared **on the definition object**, never inferred from a name and
never stored anywhere else.

| Key | Surface | What it declares |
|---|---|---|
| `type` / `default` / `description` | slot + prop | required on every definition |
| `values` | slot + prop | the bounded value set for an `enum` — a non-empty list of non-empty, single-line strings, no double quotes (below) |
| `item_eligible` | slot | the slot is item-scoped (a grid card, a section panel row) — enforced at **write and at render**, so a container-scoped slot never reaches the item element even from a non-validating write |
| `applies_when` | slot + prop | machine-readable conditionality (below) |
| `conditionality_note` | slot + prop | the bounded prose escape hatch (below) |
| `role` | slot | `"fill"` — this slot is the component's fill colour; `"measure"` — this slot is a text measure (a heading, prose or content-column `max-width`). **Not a UDC role.** This is a marker ON a style slot, bounded by `pp_slot_roles()`; the UDC roles of Step 3b are a different surface with a different key (`roles`) and a different value set. Only `grid` has style slots, so only `grid` can carry this key. |

`applies_when` is an **array of clauses, ANDed**. **Exactly four clause forms
exist and the grammar does not grow:**

```jsonc
{ "prop": "<name>", "equals": "<value>" }
{ "prop": "<name>", "in": ["<v>", "…"] }
{ "prop": "<name>", "present": true }    // non-empty string, or non-empty array
{ "slot": "--<name>", "present": true }  // sibling-slot case
```

Do **not** add an `any_of` clause, a `context` clause, or any free-form
structure. Three condition classes stay **prose**, in `conditionality_note`,
precisely so the machine-readable grammar never has to grow to swallow them:

- **Composed-page context** — `--grid-item-bar-*` / `--grid-featured-*` apply
  only under a `main >` scope, which is not a prop, not a slot and not a value.
  **This is the only one of the three with a shipped declaration today** — four grid
  slots carry it, and they are the whole population.
- **Disjunction** — a slot applying on dark bands only, i.e. `theme: inverted` **or**
  `background_image` present. *Historical:* the v2 rebuilds retired both props from
  every component that had them, and `grid` — the only component with style slots
  left — never declared `background_image`, so nothing declares this today. The
  grammar rule stands: if a disjunction returns, it returns as prose.
- **Interaction state** — a question's open state. *Historical for the same reason:*
  faq became a v2 component at #1046 and has no style slots at all now.

(Viewport-scoped behaviour is neither: responsive slot values are out of scope by
ruling, and breakpoint families are *defaults*, not authored conditions.) If the
grammar ever needs to grow, that growth lands in this contract **before** anything
populates it.

**Shape contract for `values`:** a non-empty JSON **list** of non-empty strings,
each on one line (no newlines, no tabs), none containing a double quote. The
runtime AI catalog renders the set inside double quotes (`layout?:
"cards"|"steps"`), so a member carrying a quote advertises a value set nothing
accepts, and one carrying a newline forges a whole catalog line.
`SchemaValidationTest` rejects both at authoring time. The quote half is the rule
the `applies_when` subjects and `in` members already follow; the single-line half
matches `conditionality_note`. Unlike `in`, `values` takes strings only — write
`"2"`, not `2`.

**Phrasing contract for `conditionality_note`:** write it as a condition clause
that completes the sentence "applies when ...", on a single line, under 400
characters. The runtime AI catalog emits it verbatim inside that phrase, so
"the band is dark" reads correctly while "This slot has no effect unless the band
is dark." renders as the **opposite** of what you meant. When a definition
declares both `applies_when` and a note, the catalog joins them with `AND` as one
condition.

**One field, two consumers (issue #580).** `applies_when` is populated across the
conditionality census — **29 entries in `CONDITIONALITY_LEDGER` today, 21 of them
declaring clauses and 8 carrying a `conditionality_note` only**, which is what the
schemas state, not a claim that every code-real condition has been found. The census
used to be an order of magnitude larger; it shrank because conditionality is a
STYLE-SLOT concept and the v2 rebuilds retired the slots (testimonials alone took 18
rows with it). A v2 component's roles are unconditional by construction, so a new
component on the design contract will usually add nothing here at all. The *same*
field drives a write-time advisory:
when a composition sets a slot whose condition is unmet, a non-blocking **`inert_slot`**
smell names the slot and every unmet clause. Since #687 that advisory rides the
ACCEPTED WRITE's own envelope (`findings`), as well as `wp pp check page` and the
restore findings — so an author learns the slot is dead from the write that set it,
without opting into a diagnostic. There is deliberately no second condition table — declare the
condition once, on the definition, and both the before-the-write catalog line and
the after-the-write warning follow. Two consequences when you author a condition:

- **A wrong condition is worse than none.** It becomes advice an agent designs
  around. Verify a new condition against the renderer *and* the CSS selector that
  consumes the slot before declaring it, and update the `CONDITIONALITY_LEDGER`
  pin in `tests/SchemaValidationTest.php` in the same change.
- **The advisory only sees `applies_when`.** A `conditionality_note` is prose, so
  the three classes that live there stay silent on the warning channel by
  construction. They reach the author through the catalog, before the write.

`role` is a **declared key, not a name convention**: a naming convention is not
machine-readable without a second source of truth, which is the same defect this
contract fixes one layer down. The bounded value set lives in `pp_slot_roles()`
(`lib/admin.php`) — today `fill` and `measure`, and a value outside that set fails CI.

- **`fill`** — mark a colour slot `fill` when it paints a **button or surface fill**;
  the composition-smell channel reads the marker to warn (never block) when such a
  slot resolves to `transparent` or `currentColor`, which renders an
  invisible-but-clickable button.
- **`measure`** — mark a length slot `measure` when it caps the width of **text**: a
  band heading, a prose column, or a content column. The name is exactly why this
  cannot be a `-measure` suffix rule — hero's real measure was spelled
  `--hero-content-width` (a name now RETIRED with hero's slot map at #986, kept here
  because it is the clearest example there is), so a suffix rule would have missed the
  one slot the hero docs pointed every author at. Its consumer is deferred (issue #610); the runtime AI catalog
  already emits the marker so an agent is told a literal here opts that band out of a
  later site-wide `--measure-*` retune.

**There is no `aliases` key, and no way to declare a legacy value (#606).** A prop
once could: `aliases` listed values accepted at write and never advertised in
`values`. #605 retired the last such declaration (the `theme` prop's legacy `dark`),
leaving the field with nothing to declare, and #606 retired the field itself — the
end of a sweep that also took the slot-name map (#603) and the prop-key map (#604).
So what the schema advertises is exactly what the write
path accepts — one vocabulary, no legacy tier, nothing an agent must be warned not to
write, and since #600 that is true at nested depth too. A schema that still declares
`aliases` — on a prop, on a slot, or on a nested `items[]` field — now fails CI as an
unknown definition key.

**Every enum must declare `strict: true` — top-level props AND nested `items[]`
fields.** Without it the write path accepts any string and the renderer coerces it to
the default, so the action reports `ok:true` and the page shows something else. A
schema that ships an enum without `strict` at either depth fails CI
(`SchemaValidationTest::testEveryEnumDeclarationDeclaresStrict`).

The nested half of that rule is #600, and it closed the last accept-at-write /
coerce-at-render surface in the grammar. Declaring `strict` on a nested enum used to
be a silent no-op because the gate walked top-level props only; it is now enforced by
the same predicate, over the same one-`items[]`-level traversal the required/scalar
rules already walk. `grid.items[].text_role` is the only nested enum shipped today.
Read the reach precisely: ONE `items[]` level, which is every depth the schemas
declare. An enum nested deeper than that would not be reached, so do not declare one
without extending the traversal in the same change.

**A nested field's scalar `type` also has teeth (#614).** A field declared `type: "string"` or
`type: "number"` inside an `items[]` map is enforced at the write path through the
same predicate as the top-level pass, so `"42"` is a number at both depths and a
non-numeric `image_id` is rejected with `invalid_prop_value`. Since #707 `string` means
`is_string()` at both depths too: a non-string SCALAR (`42`, `3.14`, `true`, `false`) is
rejected exactly like an array is, so declaring `type: "string"` on a new field buys you
the whole contract and not just "not a container". The unset sentinels also
match the top level (`null` for both, plus `""` for `number`), so an omitted value
still preserves the field's default. Since #744 the same holds for the container
types: declaring `type: "array"` or `type: "object"` on a nested field DOES reject a
present **scalar** at the write path, through a predicate shared with the top level and
with the same `null` / `""` sentinels. Since #738 an `array` declaration buys strictly
more than that: it also requires a JSON LIST, so a keyed object is rejected too, at both
depths and through a second shared predicate (`grid.items[].bullets` is the shipped
nested case). Since #883 an `object` declaration buys the mirror of it: a populated JSON
LIST is rejected too, through a third shared predicate, at both depths (`grid.items[]
.style` is the shipped nested case; `section.panel_items[].style` was the other until
#1023 retired it). PHP decodes
both JSON containers to an array, so neither shape rule is free — each is a real
predicate on top of the container check, and each has its own message. (Before #744 a
nested container declaration bought nothing at all — `item_type` checks a nested array's
entries, never the field itself.) What a nested annotation still does NOT buy you is any
constraint on what a container HOLDS: nothing checks an item `style` map's contents.
Note the one shape both rules must accept: `{}` and `[]` decode identically, so the
empty container satisfies `array` and `object` alike.

**Enforcement reach:** the closed key set is a **repo-CI invariant**, not a runtime
gate. `SchemaValidationTest` runs `pp_schema_definition_errors()` over every shipped
schema (including nested `items` sub-definitions). Nothing checks a schema on a live
request. That is sufficient because components are discovered only from the theme's
own `components/` directory — there is no child-theme or plugin registration path.

### Renaming a slot or a prop later

Names freeze at the first stable contract. **A rename gets no alias, on any surface —
the old name simply dies.** There is no alias surface left anywhere in the theme
(#603, #604, #605), and since #606 there is no alias **mechanism** either: nothing to
declare, nothing to populate, nothing to resolve.

| Surface | Resolves at | Consequence |
|---|---|---|
| prop **key** names | nowhere | there is no prop-key alias surface (#604). A retired prop name is rejected at write and unread at render — one answer on both paths, at both depths: top-level props (#147) and nested `items[]` fields (#643). The CODE depends on whether the component declares the name in its `retired_props` block: the nineteen v2-rebuild keys (hero's `button_variant`, `button2_variant`, `spacing`, `width`; section's `theme`, `title_align`, `background_image`, `panel_cta_variant`; cta's `theme`, `background_image`, `button_variant`, `button2_variant`; testimonials' `theme`, `title_align`; faq's `theme`; embed's `theme`; stats' `theme` and `background_image`; logos' `theme` — and NOT `table`, which is v2 as of #1066 but never declared a styling prop, so it retired none) return `retired_prop` with a message naming the `udc` surface that replaced them and the `null` clear; every other undeclared key returns `unknown_prop`. |
| style **slot** names | nowhere | there is no slot alias surface (#603). An undeclared slot name is rejected at write with `invalid_style_slot` and dropped at render. |
| prop **values** | nowhere | there is no value-alias surface (#605 took the last entry, #606 took the field). An unadvertised value is rejected at write with `invalid_prop_value`, at both depths — top-level props (#579) and nested `items[]` enum fields (#600). |
| the `variant` prop | nowhere | retired in #69. Rejected on every write path (#388) and, since #604, not decoded on any read path either. |

**How a rename happens now (#606 amends #570 ruling 9).** Ruling 9 used to require the
alias-and-keep model for any post-freeze rename. It no longer does, because alias-and-keep
IS the backward-compatibility posture the governing ruling names as a NON-GOAL. **A rename
is a documented breaking change, ratified by the maintainer at review, gated the way every
other render-changing entry is gated** — never an aliased migration. Concretely: the new
name ships, the old name is simply absent, documents that still store it lose that
declaration with the consequences spelled out below, and the change says so out loud in
the CHANGELOG.

**Know what CI actually catches here — since #598 the two surfaces are symmetric.** A
renamed or removed **prop** OR **style slot** trips the drift-catcher in
`tests/SchemaValidationTest.php`, which fails the build unless the same change records the
change in the matching migration-notes register (`SCHEMA_RENAME_MIGRATION_NOTES` for props,
`SLOT_RENAME_MIGRATION_NOTES` for slots). The note is the discipline: it forces the author
to state what happens to already-stored documents instead of making the problem disappear.
Both surfaces run one shared algorithm, so neither can drift into a weaker notion of what
"documented" means.

The register is enforced, not merely offered:

- **The note must say something and cite the ruling.** A non-empty string carrying an issue
  reference (`#598`) — an empty, whitespace, or non-string value is rejected, and so is
  prose with no issue number. "Documented" means a human wrote down what happened and which
  issue authorised it.
- **A note may only describe a name that is actually gone.** Writing a note for a name the
  schemas still declare fails. Pre-authorising a future removal in an earlier commit is not
  documentation, and it would let the removal itself ship unremarked later.
- **The pinned baselines are append-only.** Retiring a name MOVES it into the notes
  register; deleting (or quietly editing) its baseline line is not a fix, and both the count
  floor and the baseline fingerprint fail if you try. That closes the count-preserving
  rename — swapping one name for another in the schema and in the baseline — which leaves
  every total identical and used to ship green.

**Adding** a prop or slot touches the same pins: append it to `PINNED_PROP_BASELINE` /
`PINNED_SLOT_BASELINE` and update the matching `*_BASELINE_FLOOR` and
`*_BASELINE_FINGERPRINT` in the SAME change. The failure messages tell you which. This is
what keeps a name added today from being renamed unremarked next month.

The CHANGELOG entry and the maintainer's ratification at review still apply on both
surfaces — CI now makes the trail mandatory rather than remembered.

**Renaming a slot or a prop is a breaking change, and that is the accepted cost.** A composition
stored under the old name loses that declaration at render, and the two actions that validate
the WHOLE composition — `create_page` and `update_composition` — reject it by name. Since #1007
`update_component` validates only the BAND IT TARGETS, so a stale name on one band no longer
refuses an edit to another: it is reported on the accepted envelope's `findings` at severity
`error` instead. `add_component` validates only the item it adds; `remove_component`,
`reorder_components` and `style_component` validate no props, so those all still succeed on a
stale page too. A retired PROP name gets its own code (`retired_prop`) and a message naming the
v2 surface that replaced it, and clears with `{"<prop>": null}` through `update_component`. `restore_composition` still succeeds and
**reports** the dead slots (#233) rather than blocking. Neither an attempted edit nor a
restore is the only way to find out: `wp pp check page --post_id=N` and
`wp pp validate site` report the same error-severity findings on the STORED page, and
`validate site` exits non-zero for them (#622). Do not add a migration, a
tolerance, or a widened schema to soften this: backward compatibility, stale demo pages
and old compositions are explicit NON-GOALS. Author with canonical names — the runtime
catalog and `AI_CONTEXT.md` advertise nothing else.

**Slots and props are now treated identically (#604).** The prop surface used to be the
exception: a write naming a legacy **prop** key was accepted and silently stored under
the canonical key (the #495 heal-on-write model), while the same write naming a legacy
**slot** was rejected. That asymmetry is gone. A retired prop name is rejected at write
with `unknown_prop`, exactly as a retired slot name is rejected with
`invalid_style_slot`, and neither is resolved at render.

Silently repairing a generation error was the argument FOR the prop map, and it is what
killed it: the heal emitted no `changes` entry, so an agent that wrote `cta_text` got
`ok:true` and never learned it had used a retired name. Removing the map returns those
13 names to the strict `unknown_prop` gate, which is a validation **strengthening**.

**The "mechanism trust" rule that used to govern this section is RETIRED (#570 decision
record, Addendum #4).** It said a legacy name resolves at render iff a shipped mechanism
promises the already-stored document will render, and named `restore_composition` (#233)
as that mechanism. `restore_composition`'s actual contract is narrower: it restores the
snapshot verbatim and **reports findings** rather than blocking on it. (Since #818 one
precondition can refuse it outright: a ring slot holding preserved bytes rather than a
composition — and since #841/#842 "a composition" means a LIST OF COMPONENTS, so a slot
holding a JSON object, or a list whose entries are not components (`["a","b"]`, or a list
holding `{"component":"x","props":"str"}`), is a preserved-bytes slot too. "Of components" is shorthand for a structural test, not a schema one: an entry must be an array whose `props`, if set and not `null`, is also an array. A merely INVALID entry still restores and still reports. That is a
statement about the slot, not about the snapshot's contents — every slot that carries a
composition still replays verbatim, however illegal today's rules find it.) It does not promise that
what it restores still paints. Keeping a name alive because an old document might replay
it is exactly the legacy tolerance the governing ruling names as a NON-GOAL, so the slot
map was removed outright (#603), the prop map followed it (#604), the last legacy value
went with them (#605), and #606 retired the `aliases` field itself — mechanism included,
so there is nothing left to repopulate.

Add a new legacy surface only on evidence it improves the **current** AI-authorable
baseline — generation reliability, one canonical contract, or easier inspection — never
on stale-document compatibility.

---

## Step 4 — Create README.md

Create `/components/mycomponent/README.md`:

```markdown
# Component: mycomponent

One-sentence description. When to use it.

## Props

| Prop    | Type   | Required | Default           | Description |
|---------|--------|----------|-------------------|-------------|
| `title` | string | No       | `'Default Title'` | Main heading |
| `text`  | string | No       | `''`              | Body HTML |
| `link`  | string | No       | `''`              | Link URL |

## Usage

...example call...

## CSS

Styles in `assets/css/components.css` under `/* === COMPONENT: mycomponent === */`.
```

---

## Step 5 — Add CSS

Open `/assets/css/components.css` and add a labeled section at the bottom.

**What you may write here is decided by Step 3b.** The moment your schema declares a
non-empty `roles` map, the css-lint enrolls the component in the v2 structural boundary
— the suite discovers v2 components by reading the schemas, "so each later sprint's
component joins the rule on the day it declares roles", which means the day you finish
Step 3b, not the day someone remembers to add you to a list.

Inside that boundary the rule is: **structural CSS only.** No colour, type, size,
spacing, border, shadow or aspect-ratio value, and **no custom properties at all** — a
`--anything: …` in a v2 component's rule is itself an offence. Every one of those values
belongs to a role and is emitted by the UDC engine as a band-scoped block in the head.

The practical consequence surprises people, so it is worth stating plainly: **a v2
component usually has no band rule.** When stats was rebuilt, `.stats` declared padding,
background, max-width, margin-inline and border-radius — and the whole rule was deleted,
because each of those is a `_band` role parameter now. Your band padding comes from the
`_band` role's `spacing` defaults (`@pp-band-padding`, as in the Step 3b template), not
from a CSS declaration here.

Two narrow admissions, both geometry rather than design: a spacing property whose value
is geometry-only (`0`, `auto`, a negative pull) is allowed — `padding: 0` on a `<ul>`
undoes UA chrome rather than expressing rhythm — and `text-align` is allowed when
*every* selector in the rule targets a `--variant` layout modifier, because a layout
called "centered" that leaves its text ragged-left is not the layout it advertises.

```css
/* === COMPONENT: mycomponent === */

/* STRUCTURAL CSS ONLY — this block is the v2 boundary
   (docs/v2/BUILD-SPEC-sprint0.md §2). Note what is NOT here: no .mycomponent band
   rule, because its padding, background and framing are all `_band` role parameters. */

.mycomponent__list {
  display: flex;
  flex-wrap: wrap;
  list-style: none;
  padding: 0;   /* geometry-only: undoes UA chrome, not rhythm */
  margin: 0;
}

/* NO .mycomponent__title rule and NO .mycomponent__body rule here either. A title's
   font-size and margin, and a body's colour, are `heading` and `body` role defaults —
   writing them in this file is exactly what the lint refuses. */
```

**The shared scales did not go away — they moved.** Every band still shares one
vertical rhythm and one responsive heading scale, and a v2 component joins both through
its role defaults rather than through a CSS fallback chain. `stats` is the worked
example: its `heading` role declares `typography.size: "@pp-band-heading-size"`,
`spacing.margin-bottom: "@space-lg"` and `sizing.max-width: "@measure-heading"`, which is
the same shared definition the v1 slot chain reached, declared where an author can now
see and override it. Copy that shape; never paste a rhythm or scale literal, and never
fall back to `inherit` (it silently discards the scale).

Those two shared properties — `@pp-band-padding` and `@pp-band-heading-size` — are
theme-internal rather than design tokens: the registry is the FIRST `:root` block of
`base.css` and they are declared in a later one, so `update_design_token` rejects both
and retuning either is a theme-source change (issue 616). They are reachable as role
DEFAULTS, and, per Step 3b, refused in an authored `udc` map.

**Still true regardless of tier:** no raw hex values and no raw rhythm literals.

---

## Step 6 — Call it from a template

In any template file (e.g. `templates/front-page.php`):

```php
pp_get_component('mycomponent', [
    'title' => pp_field('mycomponent_title') ?: 'My Section',
    'text'  => pp_field('mycomponent_text')  ?: '<p>Default content.</p>',
]);
```

> **If you call it from `templates/base.php`, it is site chrome, and you are not done.**
> `base.php` runs on every page, so a component rendered there is *also* placeable in a page
> composition unless you say otherwise — the page would then render it twice while every
> validator reports success. That was issue #223.
>
> Declare it: add the name to `pp_template_owned_components()` in `lib/admin.php`, and its menu
> location to `pp_template_owned_menu_locations()` in `lib/wp.php` if it reads one — but only
> when the location renders on EVERY page. A location the component paints only when a menu is
> assigned to it belongs in `pp_conditionally_rendered_menu_locations()` instead (#582); putting
> it in the template-owned list would warn every site that never assigned that menu.
> `pp_validate_composition()` will then reject it from `_pp_composition` with
> `template_owned_component` on every write-time path, and it will be dropped from the catalog
> the AI reads. `restore_composition` is the one deliberate exception (#233): it replays stored
> history, so it writes the chrome and reports it as a finding rather than refusing the restore.
> (It can still refuse for two reasons unrelated to validation — a ring slot holding preserved
> bytes rather than a composition (#818), and a concurrent write that moved what your selector
> names, refused with `history_target_shifted` so you re-select rather than replay a snapshot you
> did not choose (#829) — but no validation rule ever vetoes what it replays.)
>
> The drift guards in `tests/NavReadinessTest.php` read `base.php` back and fail if you forget.
> Calling it from any other template (`front-page.php`, `single.php`, …) needs none of this.

---

## Step 7 — Update AI_CONTEXT.md

Add a row to the Component index table in `AI_CONTEXT.md`:

```
| mycomponent | components/mycomponent/mycomponent.php | Description | key_props |
```

---

## Verification checklist

- [ ] `components/mycomponent/mycomponent.php` exists
- [ ] `components/mycomponent/schema.json` exists and is valid JSON
- [ ] **The component declares `roles` (Step 3b).** A new component belongs on the
      Universal Design Contract; shipping one without roles ships a band no author can
      style, and `grid` is a predecessor rather than a precedent.
- [ ] **Every role declares `obligations`** — `[]` for a role that carries none, never
      absent. `SchemaValidationTest` fails until this is done, and every `with` must name
      a real sibling role on the same component.
- [ ] Every role's `selector` matches what the PHP actually renders, and every group in
      its `groups` list is one the role's element can honour. Probe both with
      `wp pp schema mycomponent` and a trial `udc` write before believing either.
- [ ] `components/mycomponent/README.md` exists
- [ ] CSS section added to `assets/css/components.css`
- [ ] No raw hex values in the new CSS section
- [ ] No direct WordPress function calls in the PHP file
- [ ] All text output uses `esc_html()` (plain), `pp_kses_inline()` (inline subset), or `wp_kses_post()` (rich), per the prop's documented contract
- [ ] Every prop reaching `esc_url()` or `wp_kses_post()` is guarded at the read with
      `is_scalar($raw_x) ? (string) $raw_x : ''`, and every array-contract prop with
      `is_array($raw_x) ? $raw_x : []` — each raw prop read exactly once (#730/#708/#739).
      `InvariantTest::testEveryCoreEscaperCallInAComponentTakesAGuardedLocal` and
      `::testEveryItemsTypedCallInAComponentTakesAnArrayGuardedLocal` fail until this is done.
      The escaper checker is keyed on the GUARD, not the variable name: for every argument
      reaching `esc_url()`/`wp_kses_post()` it requires a matching
      `$x = is_scalar($raw_x) ? (string) $raw_x : ''` in the same file, so reusing a name
      another component already guards will not satisfy it. If your call site genuinely
      cannot carry a stored value, exempt it by name (with the reason) in that test's
      `$exempt` list and in `StoredLinkAndRichTextRenderGuardTest::EXEMPT_CALL_SITES`;
      otherwise add the surface to that file's `GUARDED_SURFACES`, which is compared to the
      real call-site set by equality.
- [ ] If the component renders a `.btn`: its owning element class is added to the
      `main .btn:not(...)` neutralisation rule in `assets/css/components.css` (#545), and any
      per-instance BUTTON slot it declares is added to that rule's `initial` list. The rule
      keeps a band's button slots off buttons no renderer owns; a component whose own button
      class is missing from the `:not()` list gets its own slots neutralised. `NestedButtonSlotIsolationTest`
      and the `#545` css-lint pin both fail until this is done.
- [ ] `AI_CONTEXT.md` component index updated
- [ ] Every slot/prop definition object uses only the keys in the definition-object
      contract (Step 3); `SchemaValidationTest` rejects anything else — and the fields
      it renders into the AI catalog also satisfy their shape contracts (`values`,
      `conditionality_note`, `applies_when`, `role`)
- [ ] *(style slots only — `grid` is the sole component that has any, so a new v2
      component skips this item and the three below it.)*
      Every slot's `default` states the **effective** default — what actually renders
      with the slot unset, in the component's default configuration, at desktop (>=768px,
      the theme's desktop tier). Not the CSS fallback literal, and never a value that
      appears nowhere in the stylesheet.
      Where the real default varies by variant or breakpoint, put the desktop value in
      `default` and enumerate the alternatives in `description`. (There is deliberately
      no mechanical `default == CSS-fallback` check — see the note at the top of
      `StyleSlotContractTest` — because one slot is legitimately consumed with different
      fallbacks per theme variant. That is exactly why the value has to be written
      truthfully by hand.)
- [ ] Every RESTING slot that has an interaction state declares its **positional twin**
      (`--x-color` / `--x-hover-color`, rest / open) — the repo's term for the counterpart
      position in a state chain. A slot whose counterpart is a sibling ELEMENT rather than
      a state (e.g. a second button) is a per-button counterpart, not a positional twin. A control shipped without its twin
      is a future flip bug: an author sets the resting value, and the state reverts to
      the product default under the pointer.
- [ ] `styling.tokens` lists only REGISTERED design tokens (properties in the first
      `:root` block of `base.css` — the set `update_design_token` accepts) that THIS
      component's own rules consume. The array is hand-curated and never claims to be
      complete, so adding a token is optional; putting a non-token in it is not allowed.
      In particular the shared band props (`--pp-band-padding`, `--pp-band-heading-size`)
      are declared in a second `:root` block the registry does not read, so they are NOT
      design tokens and must never appear here — they are documented on the per-band
      slots that route them, which are their only authoring surface.
      The css-lint `#438` whole-theme scan proves a listed token is consumed
      somewhere; the css-lint `#581` ownership pin proves *this* component can reach it;
      the `SchemaTruthfulnessTest` `#616` registry pin proves it is a token at all. A
      token consumed only by another component's block is a false advertisement.
      Template-owned chrome declares its styling surface in `roles` (the UDC roles),
      not in `tokens` — chrome styling comes from the `pp_site_udc` site option, not
      from the design system, and conflating them on one array misrepresents both.
- [ ] `styling.variant_classes` lists **exactly** the root-element modifier classes
      the template can emit. It is derived from the template by
      `SchemaValidationTest::testVariantClassesListExactlyWhatTheTemplateCanEmit`,
      so an empty array is a claim the test checks, not a gap nobody noticed. Watch
      the `section` trap: its root class is `section` but `pp_theme_class()` is
      called with the `pp-section` prefix, so its theme classes are `pp-section--*`

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
$text  = $props['text']  ?? '';
$link  = $props['link']  ?? '';
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
  "safe_to_edit": ["mycomponent.php", "../../assets/css/components.css (COMPONENT: mycomponent section)"],
  "do_not_touch": ["schema.json without updating this component's README.md and the repo-root AI_CONTEXT.md"]
}
```

**Required keys:** `component`, `description`, `props`.

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
| `role` | slot | `"fill"` — this slot is the component's fill colour; `"measure"` — this slot is a text measure (a heading, prose or content-column `max-width`) |

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

- **Disjunction** — a slot that applies on dark bands only, i.e. `theme:
  inverted` **or** `background_image` present.
- **Composed-page context** — `--grid-item-bar-*` / `--grid-featured-*` apply
  only under a `main >` scope, which is not a prop, not a slot and not a value.
- **Interaction state** — a question's open state.

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
conditionality census — 160 declarations today, which is what the schemas state, not a
claim that every code-real condition has been found — and the *same* field drives a
write-time advisory:
when a composition sets a slot whose condition is unmet, `wp pp check page` and the
restore findings report a non-blocking **`inert_slot`** smell naming the slot and
every unmet clause. There is deliberately no second condition table — declare the
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
  cannot be a `-measure` suffix rule — hero's real measure is spelled
  `--hero-content-width`, so a suffix rule would miss the one slot the hero docs point
  every author at. Its consumer is deferred (issue #610); the runtime AI catalog
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
non-numeric `image_id` is rejected with `invalid_prop_value`. The unset sentinels also
match the top level (`null` for both, plus `""` for `number`), so an omitted value
still preserves the field's default. What a nested annotation still does NOT buy you:
`object` (nothing constrains an item `style` map's contents), and a
field declaring `type: "array"` handed a **scalar** — `item_type` checks a nested
array's entries, never the field itself.

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
| prop **key** names | nowhere | there is no prop-key alias surface (#604). A retired prop name is rejected at write with `unknown_prop` and unread at render — one answer on both paths. |
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
stored under the old name loses that declaration at render, and the three actions that validate
the WHOLE composition — `create_page`, `update_composition`, `update_component` — reject it by
name. `add_component` validates only the item it adds; `remove_component`, `reorder_components`
and `style_component` validate no props, so those four still succeed on a stale page. `restore_composition` still succeeds and
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
snapshot verbatim and **reports findings**, and it never blocks. It does not promise that
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

Open `/assets/css/components.css` and add a labeled section at the bottom:

```css
/* === COMPONENT: mycomponent === */

.mycomponent {
  /* A band-level (full-width section) component shares ONE rhythm definition
     with the others (section, grid, cta, stats, faq, testimonials). Route its
     vertical padding through the component's own slot, falling back to the
     shared --pp-band-padding, so it agrees with every other band by default and
     a release-level rhythm retune moves it too — never paste a bare rhythm
     literal (issue 431). That retune means editing --pp-band-padding in base.css:
     it is NOT a design token and update_design_token rejects it, so there is no
     site-wide authoring write for it (issue 616). */
  padding-top: var(--mycomponent-padding-top, var(--pp-band-padding));
  padding-bottom: var(--mycomponent-padding-bottom, var(--pp-band-padding));
}

.mycomponent__title {
  /* A band-level title shares ONE responsive heading scale with every other band
     title (issue 436). Route its font-size through the component's own size slot,
     falling back to the shared --pp-band-heading-size, so it never collapses to
     body size on mobile and reads as a peer of adjacent band titles. Never fall
     back to `inherit` (that silently discards the scale) or a bare literal.
     Like --pp-band-padding, this one is theme-internal: not a design token, and
     update_design_token rejects it, so retuning the scale is a theme-source
     change and the per-band slot is the only authoring surface (issue 616). */
  font-size: var(--mycomponent-heading-size, var(--pp-band-heading-size));
  margin-bottom: var(--space-md);
}

.mycomponent__body {
  color: var(--color-muted);
}
```

**Rule:** No raw hex values and no raw rhythm literals — use CSS variables:
each component's own authorable slots (`--mycomponent-padding-*`) falling back to
a `base.css` property. Band-level components take their default vertical rhythm from
`--pp-band-padding` (never a per-component literal), so all sections share one
spacing model. Band titles do the same on the typography axis: their `font-size`
falls back to `--pp-band-heading-size` (never `inherit`, never a per-component
literal), so every band heading shares one responsive scale and never collapses
to body size on mobile. Both of those shared properties are theme-internal, not
design tokens — the registry is the FIRST `:root` block of `base.css`, and they are
declared in a later one — so a slot may fall back to them but nothing may advertise
them as authorable.

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
- [ ] `components/mycomponent/README.md` exists
- [ ] CSS section added to `assets/css/components.css`
- [ ] No raw hex values in the new CSS section
- [ ] No direct WordPress function calls in the PHP file
- [ ] All text output uses `esc_html()` (plain), `pp_kses_inline()` (inline subset), or `wp_kses_post()` (rich), per the prop's documented contract
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
- [ ] Every slot's `default` states the **effective** default — what actually renders
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
      Template-owned chrome keeps its `--header-*` / `--footer-*` inline custom
      properties in `chrome_custom_properties`, not in `tokens` — those come from site
      options, not the design system, and conflating them on one array misrepresents
      both.
- [ ] `styling.variant_classes` lists **exactly** the root-element modifier classes
      the template can emit. It is derived from the template by
      `SchemaValidationTest::testVariantClassesListExactlyWhatTheTemplateCanEmit`,
      so an empty array is a claim the test checks, not a gap nobody noticed. Watch
      the `section` trap: its root class is `section` but `pp_theme_class()` is
      called with the `pp-section` prefix, so its theme classes are `pp-section--*`

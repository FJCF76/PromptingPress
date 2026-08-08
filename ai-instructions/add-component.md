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
  "do_not_touch": ["schema.json without updating README.md"]
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
| `values` | slot + prop | the bounded value set for an `enum` |
| `item_eligible` | slot | the slot is item-scoped (a grid card, a section panel row) — enforced at **write and at render**, so a container-scoped slot never reaches the item element even from a non-validating write |
| `applies_when` | slot + prop | machine-readable conditionality (below) |
| `conditionality_note` | slot + prop | the bounded prose escape hatch (below) |
| `role` | slot | `"fill"` — this slot is the component's fill colour; `"measure"` — this slot is a text measure (a heading, prose or content-column `max-width`) |
| `aliases` | prop | legacy **values** accepted at write, never advertised |

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

`aliases` lists legacy **values** of a bounded set. Canonical values stay clean —
an alias is **accepted, never advertised**, so it must not also appear in `values`,
and it is valid only on an `enum` prop. The `theme` prop advertises
`["default","muted","inverted"]` and declares `"aliases": ["dark"]`. The write path
**consumes** `aliases`, so an alias is part of the strict-enum membership test:
declaring `aliases` beside `strict: true` is the contract, not an error.

**Every TOP-LEVEL enum prop must declare `strict: true`.** Without it the write path
accepts any string and the renderer coerces it to the default, so the action reports
`ok:true` and the page shows something else. A schema that ships a top-level enum
without `strict` fails CI.

Nested `items[]` enums are a **known remaining gap**, stated here rather than left to
be discovered: the strict gate and its CI tripwire both walk top-level props only, so
`grid.items[].text_role` is still accept-at-write / coerce-at-render. Declaring
`strict` on a nested enum today has no effect. Closing that gap is its own change.

**Enforcement reach:** the closed key set is a **repo-CI invariant**, not a runtime
gate. `SchemaValidationTest` runs `pp_schema_definition_errors()` over every shipped
schema (including nested `items` sub-definitions). Nothing checks a schema on a live
request. That is sufficient because components are discovered only from the theme's
own `components/` directory — there is no child-theme or plugin registration path.

### Renaming a slot or a prop later

Names freeze at the first stable contract. A later rename uses the
**alias-and-keep** model — add the old name to the legacy map, never delete it:

| Map | Lives in | Maps |
|---|---|---|
| `pp_legacy_slot_aliases()` | `lib/wp.php` | legacy slot **name** → canonical slot **name** |
| `pp_legacy_prop_aliases()` | `lib/admin.php` | legacy prop **key** → canonical prop **key** |
| `props.<p>.aliases` | `schema.json` | legacy prop **value** (accepted, never advertised) |

The two name maps are **symmetric** as of #576/#594 — both resolve on every composition
**read**, and the slot map resolves again at the render boundary as belt-and-braces:

| Map | Resolves at | Consequence |
|---|---|---|
| `pp_legacy_prop_aliases()` | every composition **read** | a legacy-shaped band heals to canonical keys on any whole-array write-back, including bands you did not touch. Value-preserving except when an item stores **both** names, where canonical-wins drops the legacy value. The heal is **not** reported in the action's `changes`. |
| `pp_legacy_slot_aliases()` | every composition **read**, and again at **render** | same heal semantics, over the component-level `style` map and every per-item style map the schema declares. Before #576 this resolved at render ONLY, so a stored legacy slot name painted but every whole-composition validation rejected it and the page could not be edited or saved — closed by #594 in the same change as the first rename. |

**One asymmetry remains, and it is deliberate.** Resolution covers the ALREADY-STORED
document. A *new* write naming a legacy **slot** is still rejected with
`invalid_style_slot`, whereas a new write naming a legacy **prop** key is accepted and
silently stored under the canonical key (the #495 heal-on-write model). Author with
canonical names: every read path hands you those.

Both resolve under one bounded rule:

> A legacy name resolves at render **iff** a shipped mechanism promises that the
> already-stored document will render. Today exactly one mechanism makes that
> promise (`restore_composition`, #233 — it restores the snapshot verbatim and
> reports findings, and it never blocks). No other legacy surface qualifies.

That is **mechanism trust, not backward compatibility**. Under a clean break
`restore_composition` would *succeed* and render a page stripped of its styling:
`pp_render_style_vars()` drops an undeclared slot with a bare `continue` — no
finding, no warning, no log, no admin notice — and every action still returns
`ok:true`. A durability mechanism that returns success and produces an unstyled
page has not restored anything. Both maps apply **canonical-wins**: if a stored
document carries both names, the canonical value is the author's explicit one and
the stale legacy one is dropped.

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
     a site-wide rhythm retune (--pp-band-padding) moves it too — never paste a
     bare rhythm literal (issue 431). */
  padding-top: var(--mycomponent-padding-top, var(--pp-band-padding));
  padding-bottom: var(--mycomponent-padding-bottom, var(--pp-band-padding));
}

.mycomponent__title {
  /* A band-level title shares ONE responsive heading scale with every other band
     title (issue 436). Route its font-size through the component's own size slot,
     falling back to the shared --pp-band-heading-size, so it never collapses to
     body size on mobile and reads as a peer of adjacent band titles. Never fall
     back to `inherit` (that silently discards the scale) or a bare literal. */
  font-size: var(--mycomponent-heading-size, var(--pp-band-heading-size));
  margin-bottom: var(--space-md);
}

.mycomponent__body {
  color: var(--color-muted);
}
```

**Rule:** No raw hex values and no raw rhythm literals — use CSS variables:
each component's own authorable slots (`--mycomponent-padding-*`) falling back to
`base.css` tokens. Band-level components take their default vertical rhythm from
`--pp-band-padding` (never a per-component literal), so all sections share one
spacing model. Band titles do the same on the typography axis: their `font-size`
falls back to `--pp-band-heading-size` (never `inherit`, never a per-component
literal), so every band heading shares one responsive scale and never collapses
to body size on mobile.

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
> location to `pp_template_owned_menu_locations()` in `lib/wp.php` if it reads one.
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
      contract (Step 3); `SchemaValidationTest` rejects anything else
- [ ] `styling.variant_classes` lists **exactly** the root-element modifier classes
      the template can emit. It is derived from the template by
      `SchemaValidationTest::testVariantClassesListExactlyWhatTheTemplateCanEmit`,
      so an empty array is a claim the test checks, not a gap nobody noticed. Watch
      the `section` trap: its root class is `section` but `pp_theme_class()` is
      called with the `pp-section` prefix, so its theme classes are `pp-section--*`

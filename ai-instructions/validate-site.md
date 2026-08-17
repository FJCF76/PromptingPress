# Validate Site — CLI Checks + Rendered Review

## Mandatory pre-check

Before modifying any component styling or composition, always run conflict detection first:

```bash
wp pp check conflicts
```

If conflicts are found, resolve them before proceeding. The admin edit screen also shows a dismissible warning on composition pages when conflicts exist, and `WP_DEBUG` mode renders an HTML comment in the page source.

## Automated checks (CLI)

Run the full validation battery:

```bash
wp pp validate site
```

This checks:
1. **Custom CSS conflicts** — selectors in WordPress Custom CSS that target PP component classes (also surfaced via admin notice on composition edit screens)
2. **Composition styling (ambiguous targeting)** — duplicate component types without authored IDs (auto-generated `pp-<hex8>` ids do not count as stable). Duplicate authored IDs (two components sharing the same `id`) are reported under item 5 as an error-severity `duplicate_component_id` finding, plus the matching advisory smell for state that predates the rule.
3. **Composition data integrity** — a page whose stored composition is corrupt (undecodable JSON) or not a valid composition list is flagged as a data-integrity error and fails validation, instead of being silently treated as a blank page (issue 144). `wp pp check page` reports the same corruption distinctly from "no composition".
4. **Composition smells** — the advisory findings `wp pp check page` reports, including `empty_section`, `transparent_fill`, and `inert_slot` (a style slot whose declared `applies_when` condition is unmet on that component, so the stored value renders nothing). These are ADVISORIES about the composition, not errors in it: the writes that produced them were accepted and the values are stored as authored. **They still make `wp pp validate site` exit non-zero**, because this command is the "nothing is quietly wrong" gate. Resolve an `inert_slot` by setting the prop the slot needs (`layout`, `eyebrow`, `button2_text`, ...) or by dropping the slot — the message names the slot and every unmet clause.
5. **Composition validity** — findings from the same write-time rules that would reject a normal edit: a missing required prop, an unknown prop key — or, since #643, an unknown field inside an `items[]` entry — an out-of-set enum value, a wrong-typed value, template-owned chrome in the body, duplicate authored ids. These are ERRORS, not advisories, and they also make `wp pp validate site` exit non-zero (#622).

Item 5 is the one to read first when a page misbehaves. Before the vocabulary freeze (#603/#604/#605/#606) the read path canonicalized retired prop and value names, so a page written under the old vocabulary validated clean; it no longer does, and that break is deliberate. What changed in #622 is that the read-only diagnostics REPORT it. A page carrying pre-freeze names now shows up here instead of looking healthy right up until its next edit is refused. Fix it by authoring the canonical names — the error message names the undeclared keys the item is carrying and lists the props the component actually declares — or, for a key inside an `items[]` entry, names the item and lists the fields that component's entries accept (#643). That holds for the missing-required message at both depths too, so a renamed prop OR a renamed item field is named alongside what is missing, in one message. Never re-add a compatibility shim; the shipped starter composition and freshly authored content are clean and keep this command at exit 0.

Expect MORE THAN ONE line per band (#621). A band reports every problem its rules can locate — each missing required prop, each unrecognized key at either depth (including every undeclared field inside an `items[]` entry, one line per field, #643), a dead card link, a dead style slot — rather than the first one, so a band usually takes one repair pass instead of fixing, re-running, and discovering the next. Two limits on that, and they are the reason to re-run rather than assume the list was complete: a band whose identity is unusable (unknown component, site chrome) reports that alone, because nothing else about it can be judged; and a `style` map reports its first dead slot only.

Every composition finding — error or advisory — is printed in one format, `[type] index N: message`, so `index` always tells you which band to fix. It is omitted only for `duplicate_component_id`, which spans two bands and names both indices in its message.

Individual checks:

```bash
wp pp check conflicts              # Custom CSS conflicts only
wp pp check page --post_id=42      # Composition validity + styling + smells for one page (raw composition data)
wp pp validate page --post_id=42   # Rendered-HTML validation for one page (see below)
```

`wp pp check page` reports the same composition errors but never changes its exit code — it is the per-page inspector. `wp pp validate site` is the gate.

## Rendered-HTML validation (per page)

`wp pp check page` inspects raw composition data; `wp pp validate page` inspects
the **actual rendered output** — it runs the exact same `pp_post_apply_validate()`
service that gates the AI chat's success message after an apply (issue 77):

```bash
wp pp validate page --post_id=42                      # whole page
wp pp validate page --post_id=42 --component-index=1  # scope to one component
```

It flags render failures, broken `<img>` sources, background-image and link URLs
pointing at missing local media, empty content, a component render count that
doesn't match the composition, and any template-owned chrome (`nav`/`footer`) found
in the composition. Exits non-zero on failure, so it can gate a deployment workflow
the same way it gates the chat. Use it as the automated half of the rendered review
checklist below.

## What the checks catch

| Check | What it flags | What to do |
|---|---|---|
| Custom CSS conflict | `.hero { ... }` in Additional CSS | Run `clear_custom_css` action, move styling to tokens or components.css |
| Ambiguous targeting | Two `section` components without IDs | Save the composition (IDs auto-assign) or set explicit IDs |
| `template_owned_component` (ERROR) | A `nav` or `footer` in the composition — the template already renders both, so the page shows the chrome twice (#223) | Remove them with `remove_component`, highest index first. Configure the logo via `pp_logo_id` and the menus via `set_menu` / `assign_menu_location` |
| `invalid_composition` (ERROR) | A missing required prop. When the item also carries keys the schema does not declare, the message names them and lists the props the component does declare | Author the canonical prop name. If a value is sitting under a retired name, rename it — never re-add an alias |
| `unknown_prop` (ERROR) | A prop key the component's `schema.json` does not declare, or — at both depths since #643 — a field an `items[]` entry carries that the prop's `items` field map does not declare | Rename it to a declared prop/field, or drop it. The message lists the available props, or for an item the available fields and which item carries the key. Repair an items[] entry with `update_component`/`update_composition` — `wp pp operate patch` exposes `items[].<field>` selectors only for a prop literally named `items`, so `section.panel_items` has none |
| `invalid_prop_value` (ERROR) | An out-of-set enum value, an out-of-range number, a wrong-typed value | Use one of the advertised values; the message names the accepted set |
| `invalid_style_value` / `invalid_style_slot` (ERROR) | A style slot the component does not declare, an unusable value, or a non-scalar value where a scalar belongs (#622) | Set a declared slot with a scalar value, or drop it |
| `duplicate_component_id` (ERROR) | Two components sharing the same authored `id` — id-based targeting silently resolves to the first (#238) | Give each component a unique `id`. This is the one error-severity finding with no `index`: it spans two bands and names both in its message |

Every ERROR row above means the same thing: a normal write of that composition would be REJECTED, so the page will refuse its next edit until it is fixed. They are printed before the advisories and tagged `(would be rejected on write)`.

## Site chrome readiness (v0.12.0, rescoped in #223)

`pp_check_nav_readiness()` diagnoses the site chrome the page template renders on
every page: the `primary` and `footer` menu locations, and the site logo. It runs
automatically -- you do not call it directly. The rows appear:

**Conditionally rendered locations (#582).** The footer also renders an optional
second menu column at `footer_secondary`, which paints only when a menu is
assigned to it. That location is diagnosed under an INVERTED rule, because
leaving it unassigned is the intended default rather than a problem: an
unassigned location reports nothing at all, a healthy assigned menu reports
nothing either, and the single state that warns is a menu assigned to
`footer_secondary` that is **empty** -- the footer then renders an empty column
and nothing else would tell you. Registering it as a template-owned location
instead would emit a row on every site that never opted in, which is the noise
this diagnostic exists to avoid.

- in **preflight** output before any mutation (`wp pp apply preflight`), and
- in the **post-apply validation** report after a composition changes.

Every row is **warning-grade** (`severity: warning`): it surfaces the problem but
never blocks the mutation. A non-passing row is a **configuration-class finding**
(#496): it carries `class: configuration`, a stable `finding_key`,
`acknowledgeable: true`, and a `next_action`. Find the rows by their `check` field:

```
{ "check": "nav_readiness", "pass": false, "severity": "warning",
  "class": "configuration", "finding_key": "nav_readiness:primary:no_menu",
  "acknowledgeable": true,
  "next_action": "Assign a menu to \"primary\" via the set_menu action (or Appearance -> Menus), or acknowledge as intentional.",
  "message": "Site chrome location \"primary\" has no menu assigned. Use the set_menu action (or Appearance -> Menus) to create one and assign it (issue 132)." }
```

**Acknowledging a deliberate gap.** If a location is intentionally menu-less (e.g.
a footer with no menu by design), record it as intentional with
`wp pp readiness acknowledge <finding-key>` (optionally `--note`). It then reports
as acknowledged instead of a warning, and is reversible with
`wp pp readiness unacknowledge <finding-key>`. Only configuration findings are
acknowledgeable. See `operating-loop.md` -> PREFLIGHT for the full class model and
the grouped `findings` block (`wp pp readiness status` gives a read-only view).

It is scoped to the locations the template actually renders, not to anything a
page composition declares -- chrome is not composable (see `composition.md` ->
Site chrome). Because chrome renders on every page, these rows appear on every
preflight, including a site-scoped one with no `--post_id`. A registered location
the template never renders (say, one a plugin adds) stays silent -- no false alarms.

| It flags | Meaning | What to do |
|---|---|---|
| `no menu assigned` | The location has no WP menu attached | Assign one via the `assign_menu_location` action, or build menu + items + location in one call with `set_menu` (rendered locations: `primary`, `footer`) |
| `menu ... is empty` | A menu is attached but has zero items | Add items via the `add_menu_item` action (or replace declaratively with `set_menu`) |
| `not registered` | The template renders a location nothing registered | Register it in `functions.php` |
| `pp_logo_id ... is not an image` | The site logo option points at a non-image attachment, so the chrome silently falls back to a text wordmark | Set `pp_logo_id` to an image attachment ID via `update_site_option`, or clear it to use the wordmark deliberately |

This is the diagnostic for a "broken" or missing mobile menu: if the hamburger
opens to nothing, preflight will already be telling you the menu is empty or
unassigned.

## Rendered review checklist

After automated checks pass, verify rendered output:

### Desktop (1280px+)

- [ ] All pages load without console errors
- [ ] Component IDs visible in DOM inspector (search for `pp-`)
- [ ] `data-pp-component` attributes present on all component root elements
- [ ] Design tokens applied (no raw hex in computed styles)
- [ ] Grid components lay out by item count (3 items = 3 across; 2 and 4 items = 2 across; `steps` = 3 across, but a 4-item `steps` grid = 2 x 2)
- [ ] Hero CTA buttons styled correctly (primary solid, secondary outline)

### Mobile (375px)

- [ ] Hero CTA visible without scrolling
- [ ] Grid cards stack to single column
- [ ] Nav collapses to hamburger menu
- [ ] Tables scroll horizontally (no content clipping)
- [ ] No horizontal overflow on any page
- [ ] Text readable without zooming

## Clean site criteria

A site passes validation when:
1. `wp pp validate site` returns success (exit code 0). Since #622 this also fails on any composition current write rules reject, so a site carrying pre-vocabulary-freeze prop or value names goes red here — deliberately, because those pages would refuse their next edit. Author the canonical names; do not add a compatibility shim and do not weaken the gate.
2. No Custom CSS exists
3. All composition components have IDs in the DOM (authored `id` props for anything you need to target durably — `wp pp check page` warns about components with only auto-generated ids)
4. Desktop and mobile rendered review passes

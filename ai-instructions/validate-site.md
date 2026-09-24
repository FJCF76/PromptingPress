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
4. **Composition smells** — the advisory findings `wp pp check page` reports, including `empty_section` and the hero/layout/wall-of-text advisories. **`transparent_fill` and `inert_slot` no longer exist** — both read a declared field off a style slot, and the style-slot engine was retired at #1101, so the pass that produced them is deleted rather than dormant. The accepted-stored-ignored failure `inert_slot` existed for is reported by the UDC engine now, on the surface that has it (`udc_band_value_shadowed_by_role_default` and its siblings). These are ADVISORIES about the composition, not errors in it: the writes that produced them were accepted and the values are stored as authored. **They still make `wp pp validate site` exit non-zero**, because this command is the "nothing is quietly wrong" gate. Resolve an `inert_slot` by setting the prop the slot needs (`layout`, `eyebrow`, `button2_text`, ...) or by dropping the slot — the message names the slot and every unmet clause. Since #687 the accepted write that set the slot carries the same advisory in its own `findings`, so on the CLI you can catch it in that edit instead of in a later sweep. Chat and the dashboard editor do not surface it yet, so if you author there, this command is still where you find out.

   **The `udc` advisories are the v2 half of this list, and they are the ones worth
   learning, because a `udc` write that lands and paints nothing is otherwise invisible:**

   - `udc_preset_value_shadowed_by_role_default` — you applied a preset, but the role's
     OWN default outranks a preset for that parameter, so the preset's value is not
     applied. Write the value directly in your map for that role, where it outranks both.
     Fires on a card's own map too, and then names the card as `item "<id>"`, and on a
     group-grain `_preset` (inside `typography`, say), naming the group.
   - `udc_overlay_without_image` — a `background.overlay` with no `background.image`
     under it, one finding per dropped value (a responsive overlay names each breakpoint). The scrim is dropped: an overlay only paints over an image, and a
     `background.fill` is a colour, not an image. Set `background.image`, put the tint
     in the fill, or remove the overlay. An overlay inside a state (`:hover`) is always
     dropped, even over a base image, because `background.image` cannot be set inside a
     state. `wp pp check page` reports it as this finding; the readiness report
     (`wp pp apply preflight --run-id=<uuid> --post_id=<id>` for a page's bands, `wp pp readiness status`
     for site chrome) carries the same fact as a `udc_value_cannot_take_effect` row. `<uuid>` is the
     `run_id` that `wp pp operate inspect` returns, not any UUID.
   - `udc_css_overrides_group_value` — a raw `_css` declaration is a SHORTHAND that
     resets the longhand you also set through a group, so the group value does not paint.
     Write the whole treatment in one place. This is the structured-first principle
     arriving as a finding.
   - `udc_value_cannot_take_effect` — the value is stored as authored and cannot reach
     the element.
   - `udc_token_minted` — informational: you wrote a literal and it was stored as a
     band token (`--pp-<name>`) because you set it per breakpoint. Nothing to fix; it
     explains a name you will see in the emitted CSS.
   - `udc_unused_band_token` — the band declares a token nothing references, so it has
     no effect. Reference it or drop it.

   Like every advisory here these are accepted writes, and like every advisory here they
   still make `wp pp validate site` exit non-zero.
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

**Addressing (#726).** `check page` and `validate page` each take `--post_id=<id>` and nothing else (`validate site` is site-scoped and takes no page address) — a numeric post ID in canonical decimal form. `00019`, `19abc`, `1.5` and a bare `--post_id` are refused by name rather than silently read as some other page, and a slug or URL is never resolved. A refusal always names the flag, shows the corrected shape, and never tells you a flag you just typed is missing. Full contract: `docs/reference-apply-cli.md`.

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

**A generated image size counts as present (#686).** When `image_id` resolves, the
image renders through `wp_get_attachment_image()`, so the `src` is a WordPress-generated
size (`care-t-860x1024.png`) rather than the upload the composition stores
(`care-t.png`). Only the original is a Media Library row — the sizes are metadata on
it — so `missing_local_media` used to fire on every page that used `image_id`
correctly. It now resolves the size back to the attachment that owns it, including for
an upload WordPress kept as `-scaled` (over the big-image threshold) or `-rotated`
(EXIF orientation). **So treat the finding as real:** it names a file the library does
not have, and the fix is to correct the image, not the filename in the message. A name
that merely looks like a size does not pass — a size the attachment never generated,
and a size of an upload that does not exist, both still fail.

What it does not prove: it checks Media Library membership, not the filesystem, and it
reads each `<img>`'s `src`, not the whole `srcset`. A file deleted from disk while its
attachment row survives still validates clean.

## What the checks catch

| Check | What it flags | What to do |
|---|---|---|
| Custom CSS conflict | `.hero { ... }` in Additional CSS | Run `clear_custom_css` action, move styling to tokens or components.css |
| Ambiguous targeting | Two `section` components without IDs | Save the composition (IDs auto-assign) or set explicit IDs |
| `template_owned_component` (ERROR) | A `nav` or `footer` in the composition — the template already renders both, so the page shows the chrome twice (#223) | Remove them with `remove_component`, highest index first. Configure the logo via `pp_logo_id` and the menus via `set_menu` / `assign_menu_location` |
| `invalid_composition` (ERROR) | A missing required prop. When the item also carries keys the schema does not declare, the message names them and lists the props the component does declare | Author the canonical prop name. If a value is sitting under a retired name, rename it — never re-add an alias |
| `unknown_prop` (ERROR) | A prop key the component's `schema.json` does not declare, or — at both depths since #643 — a field an `items[]` entry carries that the prop's `items` field map does not declare | Rename it to a declared prop/field, or drop it. The message lists the available props, or for an item the available fields and which item carries the key. Repair an items[] entry with `update_component`/`update_composition` — `wp pp operate patch` exposes `items[].<field>` selectors only for a prop literally named `items`, so `section.panel_items` has none |
| `retired_prop` (ERROR) | A prop key the component USED TO declare and no longer does, because the component moved to the v2 styling system. Its own code rather than `unknown_prop` (#1007), because the answer differs: `unknown_prop` means you typo'd, `retired_prop` means the capability moved and the message names where. **The whole set is twenty-five keys across nine components**, and a repair sweep that works from a shorter list leaves pages red: `testimonials`' `theme` and `title_align` (#958); `hero`'s `button_variant`, `button2_variant`, `spacing` and `width` (#986); `section`'s `theme`, `title_align`, `background_image` and `panel_cta_variant` (#1023); `cta`'s `theme`, `background_image`, `button_variant` and `button2_variant` (#1026); `faq`'s `theme` (#1046); `embed`'s `theme`, `stats`' `theme` and `background_image`, and `logos`' `theme` (#1066); and `grid`'s `theme`, `title_align`, `card_emphasis`, `image_treatment` and the two ITEM-level keys `items[].text_role` and `items[].style` (#1101). `table` is v2 and retired NOTHING — it never declared a styling prop. GRID IS THE ONLY COMPONENT WHOSE RETIREMENTS REACH INSIDE AN `items[]` ENTRY: a stale `style` or `text_role` on ONE card refuses the whole band, and clearing it means sending the corrected `items` array. You will meet these on any page built before the rebuild | **Send the key as `null`** — `update_component` with `{"<prop>": null}` removes it, and that is the ONLY route that clears a key the schema no longer declares. Then write the design value the message names into the band's `udc` map. Repair one band per call, but send EVERY stale key on that band in the same call: the validator reports only the first problem per band |
| `invalid_prop_value` (ERROR) | An out-of-set enum value, an out-of-range number, or a value whose shape does not match the prop's declared `type` — including a non-string scalar (`42`, `3.14`, `true`, `false`) on a text prop, at both depths since #707, a scalar where a list or a per-item `style` object belongs, at both depths since #744, since #738 a JSON OBJECT where a declared list belongs, and since #883 a JSON LIST where a declared object belongs | For an enum or a bounded number, use one of the advertised values; the message names the accepted set. For a type mismatch, the message names the prop (and, one level down, the item and field) and the shape it wanted — **read that shape, because the repair differs by type.** A TEXT prop wants the value **quoted**: `"number": "99%"`, `"image_url": "/wp-content/uploads/logo.png"`. A prop the message wanted as an **array or an object** is the opposite — quoting is the shape being rejected, so rewrite it as a real container: `"bullets": ["Fast", "Honest"]`, `"style": {"--grid-item-bg": "#111111"}`. If the message says **must be a list**, the value is ALREADY a container and rewriting it as one fixes nothing — it is a keyed object where an array belongs, so re-send it as `[...]` with the keys dropped (`"items": {"first": {...}}` becomes `"items": [{...}]`); order is the array order. If it says **must be an object**, it is the same mistake mirrored: an array where a keyed map belongs, so re-send it as `{...}` with real keys (`"style": ["#fff"]` becomes `"style": {"--grid-item-bg": "#fff"}`). Either way, dropping the key leaves it unset. **Repair them band by band, or all at once.** `check page` lists every offending band. Since #1007 `update_component` (and `wp pp operate patch`, which routes through it) validates only the band it targets, so each band is repairable on its own in any order and a bad band no longer refuses a repair to its neighbour — the ones still outstanding come back on the accepted envelope's `findings`. One `update_composition` carrying every fix is still the fastest route when you have them all in hand |
| `invalid_style_slot` (ERROR) | A stored `style` map on a band written before its component was rebuilt. No component declares a style slot, so EVERY key in such a map is undeclared and the refusal names the first one. It blocks every `update_component` edit to that band — a props-only edit included — until the map is gone. `invalid_style_value` is no longer reachable: it needed a DECLARED slot carrying a bad value | Clear the whole map in ONE call: `update_component` with `style` set to every stored slot name → `null`, and `props` as `{}` if you are changing no props. A PARTIAL clear is refused, naming whichever slot you left behind. Then write the design value into the band's `udc` map — or, for ONE card of a repeater, into that entry's own `udc` (Addendum B) |
| `unknown_udc_role` (ERROR) | The band's `udc` map names a role the component does not declare — a typo, or a role borrowed from a different component. The same code also answers an ITEM-grain map (`props.items[].udc`) naming a role the component does not list in `item_roles` — and it says which of the two mistakes you made: a role that exists but is band-only is reported as settable on the band instead, not as a typo | Use a role the component declares; the message lists them. Read them with `wp pp schema <component>` |
| `unknown_udc_group` (ERROR) | The role exists but does not permit that group — e.g. `layout` on a role that declares only `typography`, `spacing` and `sizing` | Use a permitted group; the message names the permitted set for that role |
| `invalid_udc_value` (ERROR) | A parameter value the group's grammar refuses — a length where a colour belongs, a malformed breakpoint map, an unknown state key | Use a value the group's grammar accepts; `wp pp schema <component>` prints the groups and their parameters |
| `invalid_prop_value` (ERROR, for `@token` references) | A `@token` reference that does not resolve. **`@pp-band-padding` and `@pp-band-heading-size` are the ones that catch people**, because they are legal as shipped role DEFAULTS and illegal in an authored map: *"references "@pp-band-padding", which is not a registered design token. Only site design tokens resolve here."* Note the code — this refusal is `invalid_prop_value`, NOT `invalid_udc_value`, so key on the right one | Use a literal or a registered token. `update_design_token` lists what is registered |
| `unknown_udc_css_property` (ERROR) | A `_css` escape hatch declares a property the engine does not accept | Prefer the structured group parameter; `_css` is the last resort, not the first |
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
- [ ] Design tokens applied — inspect the **authored** rules in DevTools' Styles pane, where a value should read `var(--color-accent)`. Do NOT check the Computed pane for this: it resolves every custom property to a literal `rgb(...)`, so "no raw hex in computed styles" is unsatisfiable by construction and proves nothing either way.
- [ ] Grid columns are right for the item count. Unset, `columns` auto-derives from the
      count via a `data-pp-count` attribute: **1 item = one full-width track** (from
      768px), and from 1024px **2 items = 2 across, 3 = 3 across, 4 = 2 x 2**; `steps` is
      3 across except a 4-item `steps` grid, which is also 2 x 2. Two things to hold
      onto when a grid looks wrong: these rules are scoped `main > .grid`, so they apply
      to a composed PAGE and not to a grid rendered anywhere else, and below 1024px
      everything except the count-1 case is the plain two-column default. Setting
      `columns` (integer 1-4, emitted as `data-pp-columns`) overrides the count grain at
      768px and up, and is ignored on `steps`.
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

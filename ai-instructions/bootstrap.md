# PromptingPress Bootstrap State Contract

This document defines what a correctly provisioned PromptingPress install looks like
as verifiable state. Format: required object → required state predicate → WP-CLI
verification command.

This is a state contract, not a setup checklist. Every predicate is verifiable.
An agent setting up a fresh install should enforce each predicate, not follow steps.

## Agent Operating Loop

**Before making any changes to a PromptingPress site, read the operating loop contract.**

The operating loop (`ai-instructions/operating-loop.md`) defines the 8-step process
agents must follow: INSPECT → PLAN → PREFLIGHT → EDIT → APPLY → SCREENSHOT → REVIEW → HANDOFF.

Three playbooks customize the loop for common operations:
- `playbook-create-page.md` — Create a new page from a brief
- `playbook-revise-section.md` — Revise an existing section
- `playbook-inspect-fix.md` — Diagnose and fix a reported issue

The procedural guides in this directory (`composition.md`, `build-landing-page.md`, `retheme.md`, etc.)
describe *what* actions are available. The operating loop governs *how* and *when* to use them.

---

## Theme

**Active theme is `promptingpress`**
- Verify: `wp theme status promptingpress` → `Status: Active`
- Set: `wp theme activate promptingpress`

**Install from repo (no plugin directory — copy the theme directory directly):**
```bash
cp -r /path/to/PromptingPress /var/www/{site}/wp-content/themes/promptingpress
chown -R www-data:www-data /var/www/{site}/wp-content/themes/promptingpress
wp theme activate promptingpress --path=/var/www/{site}
```

---

## WordPress Options

**Static homepage (not latest posts)**
- Required: `show_on_front = page`
- Verify: `wp option get show_on_front` → `page`
- Set: `wp option update show_on_front page`

**Homepage post ID**
- Required: `page_on_front` = numeric post ID of the homepage page object
- Verify: `wp option get page_on_front` → numeric post ID (not 0)
- Set: `wp option update page_on_front <post_id>`

**Pretty permalinks**
- Required: `permalink_structure = /%postname%/`
- Verify: `wp option get permalink_structure` → `/%postname%/`
- Set: `wp option update permalink_structure '/%postname%/'`
- Flush rules (avoid `--hard` flag — proc_open restrictions on some servers):
  `wp eval 'flush_rewrite_rules(true);'`

---

## Homepage Page

**A page exists whose post ID matches `page_on_front`**
- Verify: `wp post get $(wp option get page_on_front) --field=post_status` → `publish`

**Page template is set to composition.php**
- Required: `_wp_page_template = composition.php`
- Verify: `wp post meta get <id> _wp_page_template` → `composition.php`
- Set: `wp post meta update <id> _wp_page_template 'composition.php'`

**Composition data is present and valid**
- Required: `_pp_composition` is a non-null, non-empty, valid JSON array of registered components
- Verify: `wp post meta get <id> _pp_composition` → valid JSON array
- WARNING: an absent `_pp_composition` on a composition page produces a blank render
  with no error message. Absence is a failure state, not an empty state.
- Set: **through the action model, never by writing the meta.**
  `wp pp action execute update_composition --run-id=<uuid> --params='{"post_id":<id>,"composition":[...]}'`
  (a mutating action needs `wp pp operate inspect` → `wp pp apply preflight` first).
  A raw `wp post meta update <id> _pp_composition '<json>'` stores the bytes and skips
  everything that makes them a composition: validation, the version counter, the history
  ring, and band-id minting — and a value that does not parse as JSON is stored as the
  EMPTY STRING, which reads back as a healthy page with no composition. Reading the meta
  to VERIFY, as above, is fine; writing it is not.

---

## Navigation Menus

**Primary navigation menu**
- Required: a menu exists and is assigned to the `primary` location
- WARNING: the `nav` component renders silently empty if no menu is assigned to `primary`.
  WordPress does not error. No menu = blank nav with no indication of failure.
- Verify: `wp menu list --fields=name,locations` → a row where locations includes `primary`
- Create and assign:
  ```bash
  wp menu create "Primary Navigation"
  MENU_ID=$(wp menu list --format=csv --fields=term_id,name | grep "Primary" | cut -d',' -f1)
  wp menu item add-custom $MENU_ID "Home" "/"
  wp menu location assign $MENU_ID primary
  ```

**Footer navigation menu**
- Required: a menu exists and is assigned to the `footer` location
- WARNING: the `footer` component renders silently empty if no menu is assigned to `footer`.
  WordPress does not error. No menu = blank footer nav with no indication of failure.
- Verify: `wp menu list --fields=name,locations` → a row where locations includes `footer`
- Create and assign:
  ```bash
  wp menu create "Footer Navigation"
  MENU_ID=$(wp menu list --format=csv --fields=term_id,name | grep "Footer" | cut -d',' -f1)
  wp menu item add-custom $MENU_ID "Home" "/"
  wp menu location assign $MENU_ID footer
  ```

---

## General Rule for All Composition Pages

Every page with `_wp_page_template = composition.php` must have a valid `_pp_composition`:
- Non-null
- Non-empty (not `[]`)
- Valid JSON array
- Each item has `component` (registered name) and `props` (satisfying schema.json), and
  may carry a `udc` map (the band's styling, for the nine v2 components) and a band `id`

Validation lives in the WRITE PATH — the `update_composition` / `create_page` actions —
not in a save handler that guards the meta. The meta's own `sanitize_callback` asks only
whether the value parses as JSON, so a raw write of a shape the actions would refuse
lands intact. A new page with no `_pp_composition` set produces a blank render with no
error.

**Verify all composition pages:**
```bash
wp post list --post_type=page --meta_key=_wp_page_template --meta_value=composition.php \
  --fields=ID,post_title --path=/var/www/{site}
# For each ID: wp post meta get <id> _pp_composition
```

---

## Known WP-CLI Behavior on This Server

**`wp rewrite structure --hard` fails** with `proc_open(): posix_spawn() failed: Permission denied`.
The `--hard` flag spawns a subprocess, which is blocked by the server's security policy.

**Workaround:** set the option and flush separately:
```bash
wp option update permalink_structure '/%postname%/'
wp eval 'flush_rewrite_rules(true);'
```

**`wp post create --post_meta` JSON format** does not reliably set post meta on creation.
Use a separate `wp post meta update <id> <key> <value>` call after creating the post — for
ordinary meta such as `_wp_page_template`. **Not for `_pp_composition`:** create the page
and its composition in one validated call with the `create_page` action, which is also the
only way the bands get ids.

---

## Full Verification Checklist

Run these after provisioning. All must pass before the install is considered complete.

```bash
WP="wp --path=/var/www/{site}"

# Theme
$WP theme status promptingpress | grep "Status: Active"

# Options
$WP option get show_on_front          # → page
$WP option get page_on_front          # → numeric ID
$WP option get permalink_structure    # → /%postname%/

# Homepage page
HP_ID=$($WP option get page_on_front)
$WP post get $HP_ID --field=post_status           # → publish
$WP post meta get $HP_ID _wp_page_template        # → composition.php
$WP post meta get $HP_ID _pp_composition          # → valid JSON array

# Menus
$WP menu list --fields=name,locations             # primary and footer both assigned
```

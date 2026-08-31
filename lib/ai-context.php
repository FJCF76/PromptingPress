<?php
/**
 * lib/ai-context.php — PromptingPress AI Site Context Layer
 *
 * Packages site state into a system prompt and structured context
 * for the LLM. This is the bridge between PromptingPress's internal
 * data model and the AI's understanding of the site.
 *
 * Loaded unconditionally (not gated behind is_admin()) because
 * ai-stream.php needs it and runs outside admin context.
 */

// ── System Prompt Assembly ─────────────────────────────────────────────────

/**
 * Assembles the complete system prompt describing the site, its capabilities,
 * available mutations, and response format instructions.
 *
 * @return string  The system prompt text.
 */
function pp_ai_system_prompt(): string {
    $site_name = pp_site_title();
    $site_desc = pp_site_description();
    $site_url  = pp_site_url();

    $parts = [];

    // Role
    $parts[] = "You are the PromptingPress site assistant for \"{$site_name}\".";
    $parts[] = "Site: {$site_url}";
    if ($site_desc) {
        $parts[] = "Tagline: {$site_desc}";
    }
    $parts[] = '';

    // Page inventory
    $pages = pp_composition_pages();
    if ($pages) {
        $parts[] = '## Pages';
        foreach ($pages as $page) {
            $parts[] = "- {$page['title']} (ID: {$page['id']}, status: {$page['status']}, URL: {$page['url']})";
        }
        $parts[] = 'To change a page\'s URL, use the update_page_slug action (post_id + slug) — never guess or construct a URL, and never propose a slug change without confirming the current URL above first.';
    } else {
        $parts[] = '## Pages';
        $parts[] = 'No pages exist yet.';
    }
    $parts[] = '';

    // Navigation state (issue 132) — grounds menu proposals against real
    // menus/locations, next to the Pages inventory above (menu items are
    // usually page links).
    $menus = pp_get_menus();
    $registered_locations = array_keys(get_registered_nav_menus());
    $parts[] = '## Navigation';
    $parts[] = 'Registered locations: ' . implode(', ', $registered_locations) . '.';
    if ($menus) {
        foreach ($menus as $menu) {
            $loc_str = $menu['location'] ? "assigned to \"{$menu['location']}\"" : 'not assigned to any location';
            $item_titles = $menu['items'] ? implode(', ', array_column($menu['items'], 'title')) : '(no items)';
            $parts[] = "- {$menu['name']} (ID: {$menu['id']}, {$loc_str}): {$item_titles}";
        }
    } else {
        $parts[] = 'No menus exist yet. Use create_menu or the declarative set_menu action to build one, then assign_menu_location to attach it to a location above.';
    }
    $parts[] = '';

    // Component catalog (condensed: name + required props only).
    // Template-owned chrome (nav/footer) is excluded: it is registered and
    // renderable but not composable, and listing it here is what led an agent
    // to compose duplicate chrome in the first place (issue #223).
    $components = pp_composable_components();
    if ($components) {
        $parts[] = '## Available Components';
        foreach ($components as $name => $schema) {
            $props = pp_ai_condense_schema($schema);
            $parts[] = "- **{$name}**: {$props}";

            $slots = pp_get_style_slots($name);
            if ($slots) {
                $slot_parts = [];
                foreach ($slots as $slot_name => $slot_def) {
                    // Enum slots carry a bounded value set — surface it (mirrors the
                    // prop-enum format) so the AI knows exactly which values are
                    // accepted, not just that the slot is "enum".
                    if (($slot_def['type'] ?? null) === 'enum' && !empty($slot_def['values']) && is_array($slot_def['values'])) {
                        $enum_str = '"' . implode('"|"', $slot_def['values']) . '"';
                        $facts = "enum: {$enum_str}, default: {$slot_def['default']}";
                    } else {
                        $facts = "{$slot_def['type']}, default: {$slot_def['default']}";
                    }
                    // Definition-surface metadata (#575). A field an agent never sees
                    // is not in the baseline, so every declared definition key that
                    // changes what an agent should DO reaches the runtime catalog.
                    $facts .= pp_ai_definition_suffix($slot_def);
                    $slot_parts[] = "{$slot_name} ({$facts})";
                }
                $parts[] = "  Style slots: " . implode(', ', $slot_parts);

                // Per-item style overrides (issue 306): a prop declared as an array
                // whose item sub-schema declares a `style` field (today: grid items)
                // accepts per-element style slots, set in the composition (not
                // style_component) and overriding grid-level by cascade proximity.
                // Only the CARD-SCOPED slots apply per element (issue 323): those
                // flagged item_eligible in style_slots. Container/heading slots render
                // nothing on a single card and are rejected. Derive the eligible list
                // from the same slot metadata so this guidance never drifts.
                $item_eligible = [];
                foreach ($slots as $slot_name => $slot_def) {
                    if (!empty($slot_def['item_eligible'])) {
                        $item_eligible[] = $slot_name;
                    }
                }
                foreach (($schema['props'] ?? []) as $prop_name => $prop_def) {
                    if (($prop_def['type'] ?? null) === 'array' && isset($prop_def['items']['style'])) {
                        $eligible_list = $item_eligible
                            ? implode(', ', $item_eligible)
                            : 'the same style slots';
                        $parts[] = "  Per-item style: {$prop_name}[].style accepts only the item-scoped slots for one entry (a distinct look for a single item in the set): {$eligible_list}. Container/heading slots are rejected — set those on the component-level style. Set via the composition (update_component), not style_component.";
                    }
                }
            }

            $recipes = pp_get_style_recipes($name);
            if ($recipes) {
                $recipe_parts = [];
                $first = true;
                foreach ($recipes as $recipe_name => $recipe_def) {
                    if ($first && !empty($recipe_def['description'])) {
                        $recipe_parts[] = "{$recipe_name} (\"{$recipe_def['description']}\")";
                        $first = false;
                    } else {
                        $recipe_parts[] = $recipe_name;
                    }
                }
                $parts[] = "  Recipes: " . implode(', ', $recipe_parts);
            }
        }
    }
    $parts[] = '';

    // Action signatures
    $actions = pp_get_registered_actions();
    if ($actions) {
        $parts[] = '## Available Actions (database mutations)';
        foreach ($actions as $name => $def) {
            $param_str = pp_ai_format_params($def['params'] ?? []);
            $parts[] = "- **{$name}** ({$def['scope']}): {$def['description']} Params: {$param_str}";
        }
    }
    $parts[] = '';

    // Apply signatures
    $applies = pp_get_registered_applies();
    if ($applies) {
        $parts[] = '## Available Applies (design mutations)';
        foreach ($applies as $name => $def) {
            $param_str = pp_ai_format_params($def['params'] ?? []);
            $parts[] = "- **{$name}** ({$def['domain']}): {$def['description']} Params: {$param_str}";
        }
    }
    $parts[] = '';

    // Design tokens
    $tokens = pp_design_tokens();
    if ($tokens) {
        $parts[] = '## Design Tokens (defaults from base.css, overrides from database)';
        foreach ($tokens as $token_name => $token_data) {
            $type_str = $token_data['type'] ? " ({$token_data['type']})" : '';
            $parts[] = "- `{$token_name}`: `{$token_data['value']}`{$type_str}";
        }
    }
    $parts[] = '';

    // Custom CSS conflict warnings
    if (function_exists('pp_check_custom_css_conflicts')) {
        $conflicts = pp_check_custom_css_conflicts();
        if ($conflicts) {
            $parts[] = '## ⚠ Custom CSS Conflicts';
            $parts[] = 'The following Custom CSS selectors target PP component classes, creating split visual authority. Use `clear_custom_css` action to remove them:';
            foreach ($conflicts as $c) {
                $parts[] = "- `{$c['selector']}` targets **{$c['component']}**";
            }
            $parts[] = '';
        }
    }

    // Media library inventory
    $media = pp_ai_media_inventory();
    $parts[] = '## Media Library';
    if ($media) {
        $parts[] = 'Available images. Copy the exact URL for each image — do not modify filenames, even to fix apparent typos or adjust spacing/hyphenation:';
        foreach ($media as $item) {
            $dims = ($item['width'] && $item['height'])
                ? " ({$item['width']}x{$item['height']})"
                : '';
            $alt_str = $item['alt'] ? " alt=\"{$item['alt']}\"" : '';
            $parts[] = "- `{$item['filename']}`{$dims}{$alt_str}: {$item['url']}";
        }
    } else {
        $parts[] = 'No images available in the media library.';
    }
    $parts[] = '';

    // Response format instructions
    $parts[] = '## How to Respond';
    $parts[] = '';
    $parts[] = 'When the user asks a question, answer conversationally. Use the site state above to give accurate, specific answers.';
    $parts[] = '';
    $parts[] = 'When the user requests a change (add a component, change a color, update a title, etc.), respond with a structured action proposal in this exact JSON format:';
    $parts[] = '';
    $parts[] = '```json';
    $parts[] = '{"proposal": true, "steps": [{"type": "action", "name": "action_name", "params": {"key": "value"}, "description": "Human-readable description of what this step does"}]}';
    $parts[] = '```';
    $parts[] = '';
    $parts[] = 'For design token changes, use type "apply" with name "update_design_token".';
    $parts[] = 'For database mutations (add component, create page, etc.), use type "action" with the appropriate action name.';
    $parts[] = 'You can include multiple steps in a single proposal for complex requests.';
    $parts[] = 'Always explain what the proposal will do before the JSON block.';
    $parts[] = '';
    $parts[] = '### Style slot value rules';
    // Issue 581 — state the `default` convention where the AI actually reads the value.
    // Every slot above is emitted as "<slot> (<type>, default: <value>)", and before this
    // gate a dozen of those values were false (`inherit` where the shared band-heading
    // scale renders, a 1.875rem literal that appears nowhere in the CSS). Correcting the
    // values without stating what `default` MEANS would just invite the next drift.
    $parts[] = 'READING `default`: it states the EFFECTIVE default — what actually renders with the slot unset, in the component\'s default configuration, at desktop (>=768px, the theme\'s desktop tier; a few slots have a further >=1024px tier, always named in the description). It is not the CSS fallback literal and not a guess: if a slot is unset, the stated default is what you will see. Where the real default varies by variant or breakpoint (a card title that shrinks below 768px, a CTA background that changes on the inverted theme), the `default` names the desktop/default-configuration value and the `description` enumerates the alternatives — so read the description before assuming one number holds everywhere. A parenthesised default like "(premium bevel)" means the value is a built-in treatment with no single literal worth quoting. Setting a slot REPLACES every branch at once, at every layout and viewport, so a value chosen from the desktop number alone can be wrong at 375px.';
    $parts[] = 'Style slot values must match the declared type (color, length, length-or-none, number, duration, font-family, shadow, gradient, position, ratio, align, text-transform). Only the `color`, `gradient`, `shadow`, and `font-family` types accept a `var()` reference (color/gradient/shadow bounded as described below; `font-family` takes a font token like `var(--font-mono)`); the `length`, `length-or-none`, `number`, `duration`, `position`, and `ratio` types are literal-only and reject `var()` in EVERY form — bare (`var(--space-lg)`) and nested inside `clamp()`/`calc()` — so look up the token\'s current value and pass that literal value (this freezes it: those types cannot FOLLOW a token the way `color` can). A `color`-typed slot or design token accepts hex, `rgb()`/`rgba()`, `hsl()`/`hsla()`, the keywords `transparent` and `currentColor`, or a single bare reference to a registered color-typed design token — `var(--color-accent)` exactly, with no fallback, no nesting, and no whitespace inside (`var(--x, #fff)` is rejected); named colors are rejected. Use a `var()` reference when a value should FOLLOW another token (e.g. "the kicker follows the brand accent") instead of duplicating a literal hex; a reference chain that loops back to the token being set is rejected as a cycle. A `gradient`-typed slot accepts either a plain color (including the forms above) or a bounded `linear-gradient()`/`radial-gradient()` (2+ color stops; `conic-gradient()`, `repeating-*-gradient()`, and `var()`/`url()`/`env()` INSIDE a gradient function are not accepted). `radial-gradient()` may carry an optional shape and/or `at <position>` clause where `<position>` is 1-2 placement keywords (`center`/`top`/`bottom`/`left`/`right`) or non-negative percentages (e.g. `radial-gradient(circle at top left, ...)`, `radial-gradient(at 20% 30%, ...)`); radial size keywords like `closest-side` and length positions like `at 10px` are not accepted. A `position`-typed slot (image/background focal point) accepts 1-2 keywords (`center`, `top`, `bottom`, `left`, `right`) or lengths/percentages (e.g. `top left`, `20% 80%`) — no functions, no `var()`. A `ratio`-typed slot (aspect ratio) accepts `auto` (natural proportions), a single positive number, or two positive numbers separated by a slash (e.g. `16/9`). An `align`-typed slot (text alignment, e.g. `--grid-item-text-align`) accepts exactly one `text-align` keyword: `left`, `right`, `center`, `start`, `end`, or `justify` — no lengths, no `position` keywords like `top`/`bottom`, and no bare `unset`/`initial`. A `text-transform`-typed slot (letter-casing, e.g. the eyebrow/kicker `--<component>-eyebrow-text-transform`) accepts exactly one `text-transform` keyword: `none` (render the text as authored, e.g. sentence case), `uppercase`, `lowercase`, or `capitalize` — no `align` keywords, no CJK `full-width`/`full-size-kana`, and no bare `unset`/`initial`. The eyebrow pill defaults to `uppercase`; set the slot to `none` when a reference shows the kicker in sentence case. A `length-or-none`-typed slot accepts everything `length` accepts PLUS the keyword `none`, which removes the cap and is that slot\'s built-in default — this is the ONE length family where `none` is a real input. It is carried only by the width caps whose DECLARED DEFAULT is `none`: the band-geometry cap `--stats-max-width`, plus the four text measures that ship uncapped (`--hero-heading-measure`, `--section-heading-measure`, `--cta-body-measure`, `--faq-body-measure`). A plain `length` slot (padding, font-size, radius, and every measure with a real length default, e.g. `--section-body-measure` or `--cta-heading-measure`) still rejects it. Most other types reject bare CSS keywords like `unset`/`initial`/`auto` — they will fail validation — with three named exceptions: `shadow`\'s own preset `none` (or `var(--shadow-*)`), `ratio`\'s own preset `auto`, and `length-or-none`\'s `none` are each explicitly accepted, mirroring their slot\'s documented default. If a user asks to "remove" or "disable" a constraint, use the slot\'s own removal value when its type has one (`none` on a `length-or-none` slot, `none` on a `shadow` slot); otherwise do not propose an unsupported CSS keyword — set the slot to the maximum practical value for the type (e.g. `100%` on a plain `length` max-width slot) and explain what the slot supports. If the requested change is genuinely not possible through the exposed style slots, say so clearly and offer the closest achievable alternative.';
    $parts[] = '';
    $parts[] = '### Before proposing a style_component action';
    $parts[] = 'Before generating a style_component proposal, verify all three checks:';
    $parts[] = '1. **Correct component**: You are targeting the component that owns the style slot. Grid gap is on the grid component, not the section that wraps it. Check the component\'s style slots list above.';
    $parts[] = '2. **Slot exists**: The style slot you want to change actually exists on the target component\'s schema.';
    $parts[] = '3. **Value is representable**: The value you want to set is valid for the slot\'s type and does not violate any constraints the user stated.';
    $parts[] = 'If any check fails, do NOT generate a proposal. Instead, explain in plain language: which component you checked, what slot you looked for, why the request cannot be fulfilled, and what the user could ask for instead.';
    $parts[] = '';
    $parts[] = '### Component prop rules';
    $parts[] = 'Only props declared in a component\'s schema (the props listed for it above) are accepted. `add_component`, `update_component`, `update_composition`, and `create_page` reject a composition whose component carries a prop key not in that component\'s schema with `unknown_prop` — the write does not persist and reports the error, so an unknown key is never silently dropped. This mirrors the style-slot rule: before proposing `add_component`/`update_component`, confirm every prop key you set exists on the target component\'s schema. If a capability the user wants has no corresponding prop, say so plainly instead of inventing a prop name.';
    $parts[] = '**The same rule applies INSIDE an `items[]` entry (#643).** A field a component\'s `items` map does not declare is rejected with `unknown_prop` too, naming the item and the fields that component\'s entries do accept — so `imageId` is refused where `image_id` is declared, instead of persisting behind `ok:true` and rendering nothing. Item field names are `snake_case` like prop names; do not camelCase them and do not invent them. An array prop whose entries are objects carries its accepted set in the catalog above as `[entry fields: ...]`, with `?` marking an optional field; an array prop with no such list takes plain scalar entries (`section.body_items`, `table.headers`, `table.rows`). Where the list is shown it is the whole contract for an OBJECT entry, so compose entries from it and never from a field name you inferred — and note `section.panel_items`, whose entries may be either such an object or a plain string.';
    $parts[] = '**The VALUE has to match the declared type too, and a text prop wants a JSON STRING (#707).** A prop or item field the catalog above shows as text takes a quoted string and nothing else: `42`, `3.14`, `true` and `false` are all rejected with `invalid_prop_value` naming the prop, at both depths. Quote the value — write `"number": "99%"` or `"number": "42"` for a stats figure, `"image_url": "/wp-content/uploads/logo.png"` for an image, never a bare number or a bare boolean. `null` and `""` still satisfy the TYPE rule and leave the prop on its default — but they are not a way around a content requirement: a band that must carry content (`section`) still needs real text in one of its content props, so clearing a value is not the same as writing one. This matters most where a value LOOKS numeric (`stats.items[].number`, `grid.items[].number`) or where you might reach for a boolean to clear a link (`section.panel_cta_url`) — write `""` or omit the key instead.';
    $parts[] = '**The same rule covers LISTS and per-item STYLE MAPS (#744).** A prop or item field declared as a list takes a JSON array, and a per-item `style` takes a JSON object — a scalar in either is rejected with `invalid_prop_value` naming the prop, and one level down the item and the field, at both depths. Write `"bullets": ["Fast", "Honest"]`, never `"bullets": "Fast, honest"`; write `"style": {"--grid-item-bg": "#111111"}`, never `"style": "dark"`. This one used to be silent one level down: a comma-joined string in `grid.items[].bullets` returned `ok:true`, persisted as written, and the card rendered with NO checklist at all, and a string in a card or panel-row `style` did the same to the override. `null` and `""` still leave the field on its default, and an empty list or map is accepted and simply renders nothing — so neither is a way to express a value you actually want.';
    $parts[] = '**A LIST MEANS A JSON ARRAY, NOT AN OBJECT WITH KEYS (#738).** A prop or item field declared as a list takes `[...]`. A JSON OBJECT is now rejected with `invalid_prop_value` naming the component and the prop — `{"first": {...}, "second": {...}}` where `items` belongs is refused, at both depths, with `must be a list, but this one is a JSON object (N entries)`. Write `"items": [{"title": "Card one"}, {"title": "Card two"}]`, never `"items": {"first": {"title": "Card one"}}`; the same holds for `bullets`, `headers`, `rows`, `body_items` and `panel_items`. ORDER IS THE ARRAY ORDER — there are no position keys, and nothing reads a key as an ordinal. This used to be accepted: a keyed object returned `ok:true`, persisted as written, and could then take the whole PUBLIC page down with a 500, so the refusal is the write path declining to store a shape the page cannot render. `{}` and `[]` are indistinguishable once parsed and both count as the empty list. If you are repairing a page that already holds one, re-send the whole prop as an array through `update_composition` — nothing is migrated for you.';
    $parts[] = '';

    // Image selection rules
    $parts[] = '## Image Selection Rules';
    $parts[] = '- When adding or editing components that accept images, select from the Media Library above.';
    $parts[] = '- Match images to the task by filename and alt text. Copy the full URL exactly as listed. Never invent, guess, or modify URLs.';
    $parts[] = '- If the Media Library section shows no images, tell the user no images are available. Do not hallucinate URLs. To bring in an image as a locally-owned asset, use the `import_media` apply — it returns `{attachment_id, url}` with `action` "import" (new) or "reused". Give it EITHER a remote `url` (HTTPS image; re-importing the same source URL reuses the existing attachment, so retrying is safe) OR a `file` (a server-local absolute path to a brand-kit asset — logo, favicon, OG card — copied then sideloaded, the operator\'s source file left untouched). Provide exactly one of url/file.';
    $parts[] = '- Foreground images require `image_alt` (non-empty, descriptive):';
    $parts[] = '  - hero (layout: "split"): `image_url` + `image_alt`, optionally `image_id` (the Media Library attachment ID NUMBER — `import_media` returns `{attachment_id, url, action}`, so pass its `attachment_id`, never the whole object; a non-numeric value is rejected at write) for responsive srcset/sizes output. A "split" hero with neither an image nor `proof` has no second column and degrades to the single-column "left" layout; add an image or proof to get the two-column split.';
    $parts[] = '  - section (layout: "image-left" or "image-right"): `image_url` + `image_alt`, optionally `image_id` (same as hero)';
    $parts[] = '  - grid items (cards layout only): `items[].image_url` + `items[].image_alt`, optionally `items[].image_id` (same as hero). By default each card image renders as a full-width 16:9 cover banner; set the grid-level `image_treatment: "icon"` prop to render it instead at a small fixed icon size (default 48px, un-cropped) above the title — the icon+title+text feature card. Unset keeps the banner; the icon box size is the `--grid-item-icon-size` style slot.';
    $parts[] = '  - logos items: `items[].image_url` + `items[].image_alt`, optionally `items[].image_id` (same as hero)';
    $parts[] = '  - testimonials items (author avatar): `items[].image_url` + `items[].image_alt`, optionally `items[].image_id` (same as hero)';
    $parts[] = '  - nav/footer logos are NOT props (issue 582): the header and footer are template-owned chrome, so a composition naming them is rejected. Use SITE OPTIONS instead — `update_site_option` with `pp_logo_id` (Media Library attachment ID, not a URL), optionally `pp_footer_logo_id` for a footer-specific variant, and `pp_logo_alt` for alt text. `pp_logo_alt` is one site-wide value shared by both logos; leave it unset and each attachment\'s own alt metadata is used, then the site title. The alt is never empty. A value that is empty or whitespace-only counts as unprovided and falls through the chain rather than rendering, so do not write a blank alt to "clear" it — it would announce nothing and suppress the attachment\'s own alt. A real value renders verbatim.';
    $parts[] = '- Background images (no `image_alt` needed):';
    $parts[] = '  - hero (layout: "cover"): `image_url` rendered as CSS background-image';
    $parts[] = '  - section: `background_image`';
    $parts[] = '  - cta: `background_image`';
    $parts[] = '  - stats: `background_image`';
    $parts[] = '- Image focal point / aspect ratio: when an image crops badly (off-center subject) or needs a specific box shape, use `style_component` on the `position`-typed slot (`--hero-image-position`, `--section-image-position`, or the four `--{component}-bg-position` slots for background images) and, for hero/section content images only, the `ratio`-typed `--hero-image-aspect-ratio`/`--section-image-aspect-ratio` slots. Not available for logos or the plain `image_url`/`background_image` fallback path — these are style slots, set via `style_component`, not props.';
    $parts[] = '- Grid component: images only render in the cards layout, not the steps layout.';
    $parts[] = '- When editing a single item in a grid or logos component, pass the complete `items` array with the modification applied at the correct index. `update_component` uses shallow merge, not positional patching.';

    return implode("\n", $parts);
}

/**
 * Renders the DEFINITION-SURFACE metadata of one slot or prop definition object
 * into the runtime AI catalog (issue #575).
 *
 * One emitter for both surfaces, so a field can never reach the slot catalog and
 * silently miss the prop catalog (or vice versa). A field an agent never sees is
 * not in the baseline — it is a comment in a JSON file.
 *
 * Three fields, three jobs:
 *
 *   applies_when         when the declaration does anything at all. Rendered as the
 *                        ANDed clause list so the agent can check the condition
 *                        against the composition it is about to write, instead of
 *                        setting a value that renders nothing.
 *   conditionality_note  the same job for the three condition classes the clause
 *                        grammar deliberately cannot express (disjunction,
 *                        composed-page context, interaction state).
 *   role                 what KIND of slot this is, from the bounded set in
 *                        pp_slot_roles() (lib/admin.php). `fill` marks a component's
 *                        FILL, so "make the button blue" resolves to the fill slot and
 *                        not to some other colour slot that happens to be nearby.
 *                        `measure` marks a TEXT MEASURE (#578), so "tighten the line
 *                        length" resolves to a measure slot — and the emitted line says
 *                        what a literal there costs, since it opts that band out of a
 *                        later site-wide --measure-* retune.
 *
 * There was a fourth, `aliases`, and it is RETIRED (#606). It disclosed legacy VALUES
 * a prop still accepted at write, phrased as "also accepts legacy" so an agent would
 * not read it as a value to choose. Every declaration was retired (#603/#604/#605) and
 * the schema field went with them, so no catalog line carries an accepted-but-
 * unadvertised tier any more: what a prop advertises is what it accepts. A definition
 * that still carries the key emits NOTHING here and fails CI as an unknown key.
 *
 * @param  array $definition  A slot or prop definition object from schema.json.
 * @return string             A leading-space suffix, or '' when nothing is declared.
 */
function pp_ai_definition_suffix(array $definition): string {
    $bits = [];

    if (($definition['role'] ?? null) === 'fill') {
        $bits[] = 'role: fill (this is the component\'s fill colour)';
    }
    if (($definition['role'] ?? null) === 'measure') {
        // Says WHAT the marker means for the agent's next write, not just that it exists:
        // a literal here is accepted but opts this band out of a site-wide measure retune,
        // which is exactly what the literal_measure advisory reports afterwards.
        $bits[] = 'role: measure (a text measure — a literal value here is accepted, but it '
            . 'opts this band out of any later site-wide measure retune, so prefer leaving it '
            . 'unset and tuning the --measure-* design tokens unless THIS band must differ)';
    }

    // ONE condition, however it is expressed. `applies_when` carries the clauses the
    // bounded grammar can express and `conditionality_note` carries the classes it
    // deliberately cannot (disjunction, composed-page context, interaction state).
    // A definition may declare both, and when it does they are a CONJUNCTION — so
    // they must render as one "applies when A AND B" phrase. Emitting two separate
    // "applies when" bits would read as two unrelated, competing conditions.
    $conditions = [];
    if (!empty($definition['applies_when']) && is_array($definition['applies_when'])) {
        foreach ($definition['applies_when'] as $clause) {
            $rendered = pp_ai_format_applies_when_clause($clause);
            if ($rendered !== '') {
                $conditions[] = $rendered;
            }
        }
    }
    if (!empty($definition['conditionality_note']) && is_string($definition['conditionality_note'])) {
        // Emitted VERBATIM (bar a trailing period), never behind a forced "applies
        // when" prefix. The note is free prose: prefixing "This slot has no effect
        // unless the band is dark." yields "applies when this slot has no effect
        // unless the band is dark", which states the OPPOSITE of the author's
        // intent. add-component.md documents the phrasing contract — write the note
        // as a condition clause that completes "applies when ...".
        $conditions[] = rtrim(trim($definition['conditionality_note']), '.');
    }
    if ($conditions) {
        $bits[] = 'applies when ' . implode(' AND ', $conditions);
    }

    return $bits ? '; ' . implode('; ', $bits) : '';
}

/**
 * Formats one `applies_when` clause for the runtime catalog (issue #575).
 *
 * ONE SOURCE OF TRUTH for the grammar: this function does not re-derive what a
 * valid clause looks like, it ASKS pp_applies_when_clause_errors() (lib/admin.php)
 * and renders nothing when that engine reports anything. Re-deriving is how the
 * two drifted in the first draft — the formatter accepted a two-subject clause the
 * validator rejects, accepted a bool/float `equals` the validator rejects, and
 * rendered `equals` when both `equals` and `in` were present. It also emitted a PHP
 * "Array to string conversion" warning into the prompt buffer on a nested `in`
 * member, while its own docblock promised it never guesses.
 *
 * The delegation makes the promise true and keeps it true: a future clause form is
 * added to the validator, and the formatter cannot silently disagree — it can only
 * fail to render, which is the safe direction. The catalog must never invent a
 * condition an agent would then design around.
 *
 * Guarded with function_exists so a partial include (lib/ai-context.php without
 * lib/admin.php) degrades to rendering nothing rather than fataling.
 *
 * @param  mixed  $clause
 * @return string
 */
function pp_ai_format_applies_when_clause($clause): string {
    if (!is_array($clause)) {
        return '';
    }
    if (!function_exists('pp_applies_when_clause_errors')
        || pp_applies_when_clause_errors($clause, 'catalog') !== []) {
        return '';
    }

    // Validated: exactly one subject, exactly one predicate, all scalars.
    $subject = $clause['prop'] ?? $clause['slot'];

    if (array_key_exists('equals', $clause)) {
        return "{$subject} = \"{$clause['equals']}\"";
    }
    if (array_key_exists('in', $clause)) {
        return "{$subject} is one of \"" . implode('", "', $clause['in']) . '"';
    }
    return "{$subject} is set";
}

/**
 * Condenses a component schema to a short string of required + key optional props.
 */
function pp_ai_condense_schema(array $schema): string {
    // Support both OpenAI JSON Schema format ('properties' + top-level 'required' array)
    // and PromptingPress format ('props' with per-prop 'required' boolean)
    $props = $schema['properties'] ?? $schema['props'] ?? null;

    if (empty($props)) {
        return '(no props)';
    }

    $top_required = $schema['required'] ?? [];
    $parts = [];

    foreach ($props as $prop_name => $prop_def) {
        $type = $prop_def['type'] ?? 'mixed';
        // Per-prop 'required' (PromptingPress) or top-level array (JSON Schema)
        $is_required = !empty($prop_def['required']) || in_array($prop_name, $top_required, true);
        $marker = $is_required ? '' : '?';
        // The prop list is joined with ', ' and the definition suffix can itself
        // contain ', ' (a multi-value `in` clause, a conditionality_note). Parenthesize the
        // suffix — as the slot catalog already does — so the prop boundaries stay
        // unambiguous and an agent can still split the line back into props.
        $suffix = pp_ai_definition_suffix($prop_def);
        if ($suffix !== '') {
            $suffix = ' (' . ltrim($suffix, '; ') . ')';
        }
        // Entry field map for an array prop (#643). Until #643 an undeclared field inside
        // an items[] entry was silently accepted, so a model that guessed `imageId` got
        // ok:true and a blank render — bad, but self-limiting. Now that guess is a HARD
        // REJECT on create_page / update_composition / add_component / update_component,
        // and this catalog was the only place the model could have learned the real names:
        // it rendered every array prop as bare `items: array`, the chat runtime has no file
        // or tool surface to go read a schema with, and a rejected step's message (which
        // does name the fields) is shown to the operator without re-entering the model's
        // conversation. A rule the primary consumer cannot see is a rule it cannot follow,
        // so the accepted grammar and the advertised grammar ship together.
        //
        // DERIVED FROM THE VALIDATOR'S OWN PREDICATE (_pp_entry_is_object_shape, the same
        // is-a-field-map test lib/admin.php applies at both levels), so the catalog cannot
        // advertise a field set the gate does not enforce, or omit one it does. A
        // value-grammar `items` yields no field map and prints nothing extra, as before.
        //
        // THE CLOSED-SET CLAUSE IS GATED ON `item_type: "object"`, and that is not a
        // detail. `section.panel_items` is the one shipped field map whose entries may ALSO
        // be a PLAIN STRING — the primary documented form, rendered as a bulleted list item
        // — which is exactly why it declares no `item_type` and why RULE 5 skips a
        // non-array entry there. Printing "no other field is accepted" for it would be
        // false in the direction that costs output: a model believing strings are illegal
        // wraps each line as `{label: "..."}`, which validates, reports ok:true, and
        // renders a two-part paired row with an empty value span instead of a bullet. The
        // gate says nothing about string entries, so neither may the catalog.
        $entry_fields = '';
        if ($type === 'array'
            && isset($prop_def['items'])
            && _pp_entry_is_object_shape($prop_def['items'])
        ) {
            $field_names = [];
            foreach ($prop_def['items'] as $field_name => $field_def) {
                // A field DEFINITION is a non-empty object. The map-level test above admits
                // `{}`; this one must not, or an array `default` (`[]`) reads as a field
                // named `default`. Mirrors lib/admin.php's hoist exactly — see the comment
                // there for why the two levels use different predicates.
                if (!is_array($field_def) || pp_is_list($field_def)) {
                    continue; // a schema keyword (values/applies_when/default), not a field
                }
                $field_names[] = $field_name . (empty($field_def['required']) ? '?' : '');
            }
            if ($field_names !== []) {
                $entry_fields = ($prop_def['item_type'] ?? null) === 'object'
                    ? ' [entry fields: ' . implode(', ', $field_names) . ' — no other field is accepted]'
                    : ' [entry fields, for an OBJECT entry: ' . implode(', ', $field_names)
                        . ' — no other field is accepted; a plain string entry is also allowed]';
            }
        }
        if ($type === 'enum' && !empty($prop_def['values']) && is_array($prop_def['values'])) {
            $enum_str = '"' . implode('"|"', $prop_def['values']) . '"';
            $parts[] = "{$prop_name}{$marker}: {$enum_str}{$suffix}";
        } else {
            $parts[] = "{$prop_name}{$marker}: {$type}{$suffix}{$entry_fields}";
        }
    }

    $condensed = implode(', ', $parts);

    // Content requirement (#488): when a component makes every prop optional but
    // still requires SOME content (section, since body became optional), the
    // per-prop `?` markers alone would tell the AI a fully-empty component is
    // valid. Surface the schema-level content_requirement so the condensed
    // catalog stays coherent with the write-time validator and composition.md.
    if (!empty($schema['content_requirement']['any_of']) && is_array($schema['content_requirement']['any_of'])) {
        $condensed .= ' [needs one of: ' . implode(', ', $schema['content_requirement']['any_of']) . ']';
    }

    return $condensed;
}

/**
 * Formats an action/apply params array into a compact string.
 */
function pp_ai_format_params(array $params): string {
    if (empty($params)) {
        return '(none)';
    }

    $parts = [];
    foreach ($params as $name => $def) {
        $required = ($def['required'] ?? false) ? '' : '?';
        $type = $def['type'] ?? 'mixed';
        $parts[] = "{$name}{$required}: {$type}";
    }

    return implode(', ', $parts);
}

// ── Page-Specific Context ──────────────────────────────────────────────────

/**
 * Returns composition JSON + metadata for a specific page.
 * Used when the user references a specific page in the chat.
 *
 * The `composition_version` is the write-time CAS baseline (#13/#404): the version the
 * model reasoned against, captured at read time so a chat write can be rejected if the page
 * moved before the user applied it. It is app-managed (the chat UI threads it back on
 * write) — the model never sets or increments it.
 *
 * A CORRUPT ROW IS NOT AN EMPTY PAGE, AND THIS IS THE READ THAT USED TO SAY IT WAS (#750).
 * This read went through pp_get_composition(), the legacy accessor that degrades a corrupt
 * or wrong-shaped stored value to `[]` (lib/wp.php). The classification was thrown away
 * here, one line before the only consumer that could have used it, so the system prompt
 * showed the model `[]` and the model authored into a page it had been told was blank —
 * the same wrong conclusion #725 removed from `inspect-composition` and #748 from the
 * `composition_required` refusal, reached through the third door. Measured consequence:
 * the model proposed `add_component` (correctly refused) instead of the repair #756 had
 * just made reachable, which left that carve-out INERT on the first turn.
 *
 * `composition_error` carries the classification — null, `unexpected_shape` or
 * `decode_error` — and `composition` STAYS `[]` when it is set, exactly as
 * pp_get_composition_result() returns it. That pairing is deliberate rather than
 * contradictory: `[]` there is "nothing could be read", never "nothing is stored". THE ONE
 * RULE FOR ANY FUTURE CONSUMER: read `composition_error` FIRST, and never present
 * `composition` as the page's content while it is non-null. The single consumer today is
 * pp_ai_format_messages() below, which renders the corruption instead of the empty list.
 *
 * READS THE CACHED CLASSIFIER, DELIBERATELY. This builds CONTEXT — it is a reader, not a
 * gate — so it takes the same cached read every diagnostic surface takes
 * (pp_get_composition_result), not the uncached pp_get_composition_result_authoritative()
 * that exists for gate-opening decisions only (its docblock states the asymmetry). The
 * OPPOSITE choice, for the opposite reason, from _pp_batch_target_refusal_reason()
 * (lib/actions.php): that one is the #749 refusal's source owner, and since #833 it reads
 * the row and fails closed with no handle, because it decides whether a write may proceed.
 * A cached classification that has gone stale mid-request describes the page one moment out
 * of date, which is the honest cost of a context block; a GATE resting on one is the
 * vulnerability #833 recorded. Nothing here gates anything, so the staleness is disclosed
 * and accepted rather than paid for with a per-request query.
 *
 * @param int $post_id  WordPress post ID.
 * @return array  [] when the post does not exist — the caller's `if ($page_ctx)` guard is
 *                what that shape is for, and `composition_error` is absent from it, not
 *                null. Otherwise ['id' => int, 'title' => string, 'status' => string,
 *                'composition' => array, 'composition_error' => ?string,
 *                'composition_version' => int].
 */
function pp_ai_page_context(int $post_id): array {
    $post = get_post($post_id);
    if (!$post) {
        return [];
    }

    $stored = pp_get_composition_result($post_id);

    return [
        'id'                  => $post_id,
        'title'               => $post->post_title,
        'status'              => $post->post_status,
        'composition'         => $stored['composition'],
        'composition_error'   => $stored['error'],
        'composition_version' => pp_get_composition_marker($post_id)['version'],
    ];
}

// ── Media Inventory ────────────────────────────────────────────────────────

/**
 * Returns recent media attachments for AI context.
 * Capped to prevent system prompt bloat.
 *
 * @param int $limit  Maximum number of items (default 50).
 * @return array  Array of media items with id, filename, url, alt, mime_type, width, height.
 */
function pp_ai_media_inventory(int $limit = 50): array {
    $attachments = get_posts([
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'post_mime_type' => 'image',
        'posts_per_page' => $limit,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ]);

    $items = [];
    foreach ($attachments as $att) {
        // 'post_mime_type' => 'image' matches any image/* mime, but SVGs
        // (image/svg+xml) fail wp_attachment_is_image() in WordPress core —
        // it isn't a "displayable" raster image. Recheck here so the
        // inventory never advertises a URL that _pp_validate_media_urls_in_params()
        // would then reject at execute time (#124).
        if (!wp_attachment_is_image($att->ID)) {
            continue;
        }
        $meta = wp_get_attachment_metadata($att->ID);
        $items[] = [
            'id'        => $att->ID,
            'filename'  => basename(get_attached_file($att->ID) ?: ''),
            'url'       => wp_get_attachment_url($att->ID) ?: '',
            'alt'       => get_post_meta($att->ID, '_wp_attachment_image_alt', true) ?: '',
            'mime_type' => $att->post_mime_type ?? '',
            'width'     => $meta['width'] ?? null,
            'height'    => $meta['height'] ?? null,
        ];
    }

    return $items;
}

// ── Full Site Context Bundle ───────────────────────────────────────────────

/**
 * Bundles all site context into a single array for the system message.
 * This is what gets passed to pp_ai_format_messages().
 *
 * @return array  Site context bundle.
 */
function pp_ai_site_context(): array {
    return [
        'site' => [
            'name'        => pp_site_title(),
            'description' => pp_site_description(),
            'url'         => pp_site_url(),
        ],
        'pages'      => pp_composition_pages(),
        'menus'      => pp_get_menus(),
        'components' => array_keys(pp_composable_components()),
        'actions'    => array_keys(pp_get_registered_actions()),
        'applies'    => array_keys(pp_get_registered_applies()),
        'tokens'     => pp_design_tokens(),
    ];
}

// ── Component Summary ─────────────────────────────────────────────────────

/**
 * Returns a one-line summary of a component for the page context index.
 * Includes component type and key distinguishing props so the AI can
 * unambiguously target components when a page has duplicates.
 */
function _pp_summarize_component(array $item, ?array $inspect_target = null): string {
    $name  = $item['component'] ?? 'unknown';
    $props = $item['props'] ?? [];

    $name_str = $name;
    if ($inspect_target && !empty($inspect_target['component_id'])) {
        $name_str .= " ({$inspect_target['component_id']})";
    }
    $parts = [$name_str];

    // Structural layout (the main structural differentiator) and, separately,
    // the color/tone theme — kept distinct so inspect never conflates the two
    // (issue #69: `variant` was split into `layout` + `theme`).
    if (!empty($props['layout'])) {
        $parts[] = "layout: {$props['layout']}";
    }
    if (!empty($props['theme'])) {
        $parts[] = "theme: {$props['theme']}";
    }

    // Title (short identifier)
    if (!empty($props['title'])) {
        $title = mb_strlen($props['title']) > 40
            ? mb_substr($props['title'], 0, 37) . '...'
            : $props['title'];
        $parts[] = "title: \"{$title}\"";
    }

    // Image filename (key for image-bearing components). logo_id is an
    // attachment ID, not a URL, so it is not a basename source here.
    foreach (['image_url', 'background_image'] as $img_prop) {
        if (!empty($props[$img_prop])) {
            $parts[] = basename($props[$img_prop]);
            break;
        }
    }

    $summary = implode(' | ', $parts);

    if ($inspect_target) {
        // Style line: recipe + overridden slots
        $style_parts = [];
        if (!empty($inspect_target['active_recipe'])) {
            $style_parts[] = "recipe: {$inspect_target['active_recipe']}";
        }
        if (!empty($inspect_target['style_slots'])) {
            foreach ($inspect_target['style_slots'] as $slot) {
                if ($slot['current'] !== null && $slot['current'] !== $slot['default']) {
                    $style_parts[] = "{$slot['slot']}: {$slot['current']}";
                }
            }
        }
        if ($style_parts) {
            $summary .= "\n      Style: " . implode(' | ', $style_parts);
        }

        // Editable line: deduplicated field names by type
        if (!empty($inspect_target['fields'])) {
            $seen = [];
            $editable_parts = [];
            foreach ($inspect_target['fields'] as $f) {
                $key = $f['field'];
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    // Show the schema type, plus the format family (link_url /
                    // image_url) when the prop declares one, so the chat AI knows
                    // the constraint the shared validator will enforce on a patch
                    // (#506/#507/#509) — e.g. "button_url (string, link_url)".
                    $type_label = $f['field_type'];
                    if (!empty($f['field_format'])) {
                        $type_label .= ", {$f['field_format']}";
                    }
                    $editable_parts[] = "{$f['field']} ({$type_label})";
                }
            }
            if ($editable_parts) {
                $summary .= "\n      Editable: " . implode(', ', $editable_parts);
            }
        }
    }

    return $summary;
}

// ── Adjacent Same-Background Hint (#378) ───────────────────────────────────

/**
 * Sanitizes a background value for display INSIDE the chat system prompt.
 *
 * Collapses internal whitespace/newlines (a stored value could carry them via
 * snapshot restore or an out-of-band write, and a raw newline would fabricate a
 * spurious context line) and caps length so one pathological value can't bloat
 * the prompt. This is prompt hygiene, not a security boundary — the value is
 * never emitted into HTML/CSS here, only into the model's own context.
 *
 * @param string $value  Raw style-slot value.
 * @return string  Single-line, length-capped display string.
 */
function _pp_bg_annotation_value(string $value): string {
    $clean = trim((string) preg_replace('/\s+/', ' ', $value));
    if (mb_strlen($clean) > 40) {
        $clean = mb_substr($clean, 0, 37) . '...';
    }
    return $clean;
}

/**
 * Resolves a component's effective background to a comparison identity for the
 * adjacency hint (#378). Uses only the inputs the chat context already serializes
 * — the per-instance `--{component}-bg` style override and the band-level `theme`
 * prop — with no rendering or CSS parsing (#378 is explicitly not full visual
 * modeling, and #377 owns the human-facing fusing heuristic).
 *
 * Returns an identity for a band that paints a definite, non-default flat
 * background, or null when it inherits the page/body background (so a pair is never
 * annotated on a default match). Resolution order (first match wins):
 *
 *   1. Image-backed band (section/cta/stats `background_image`, or a hero `cover`
 *      layout with `image_url`) -> null. The visible band is the image, not a flat
 *      color; "shares background <color>" would be a false fusing hint. Checked
 *      first so an image beats a co-set `--*-bg` slot unambiguously.
 *   2. Per-instance `--{component}-bg` override -> its literal value (what actually
 *      paints among flat backgrounds). A `transparent` or empty override reveals the
 *      inherited background, so it resolves to null like the default.
 *   3. `theme` prop bucket, component-independent so section vs grid compare equal:
 *      `inverted` -> the dark inverted band; `muted` -> the light muted surface
 *      (which paints under the legacy `--dark` class name, #570 DG-4).
 *   4. Otherwise (default/absent/unknown theme, including a `dark` stored before
 *      #605) -> null (inherited body background).
 *
 * @param array $item  One composition item (defensively typed).
 * @return array{id:string,label:string}|null  Identity + display label, or null.
 */
function _pp_resolve_component_bg(array $item): ?array {
    $name  = is_string($item['component'] ?? null) ? $item['component'] : '';
    $props = is_array($item['props'] ?? null) ? $item['props'] : [];
    $style = is_array($item['style'] ?? null) ? $item['style'] : [];

    // 1. Image-backed bands are not flat-color bands.
    $bg_image = $props['background_image'] ?? '';
    $is_hero_cover = $name === 'hero'
        && ($props['layout'] ?? '') === 'cover'
        && is_string($props['image_url'] ?? null)
        && trim($props['image_url']) !== '';
    if ((is_string($bg_image) && trim($bg_image) !== '') || $is_hero_cover) {
        return null;
    }

    // 2. Per-instance background override wins among flat backgrounds.
    $override = $style["--{$name}-bg"] ?? null;
    if (is_string($override)) {
        $val = trim($override);
        if ($val !== '' && strtolower($val) !== 'transparent') {
            // Whitespace-normalize the id so two equal gradients compare equal.
            $norm = strtolower((string) preg_replace('/\s+/', ' ', $val));
            return ['id' => "bg:{$norm}", 'label' => _pp_bg_annotation_value($val)];
        }
    }

    // 3. Theme bucket.
    $theme = is_string($props['theme'] ?? null) ? $props['theme'] : '';
    if ($theme === 'inverted') {
        return ['id' => 'theme:inverted', 'label' => 'the inverted theme (dark band)'];
    }
    // The accepted set here mirrors pp_theme_class() in lib/helpers.php — if a theme
    // value is ever added or removed, update both sites in lockstep. A value outside
    // the set (including a `dark` stored before #605) falls through to the default
    // bucket below, exactly as pp_theme_class() coerces it to the default band.
    if ($theme === 'muted') {
        return ['id' => 'theme:muted', 'label' => 'the muted theme (light surface band)'];
    }

    // 4. Default / inherited body background.
    return null;
}

/**
 * Builds the adjacency-hint lines for a page composition (#378): one line per
 * consecutive component pair whose resolved backgrounds are the same non-default
 * value, so the chat AI gets the "two touching same-color bands" relationship as a
 * structural fact instead of inference. Vocabulary tracks the #377 band-fusing
 * heuristic in ai-instructions/style-component.md ("facing paddings/margins").
 *
 * @param array $composition  Ordered composition items.
 * @return string[]  Annotation lines (no leading indent/newline), empty when none.
 */
function _pp_adjacent_background_annotations(array $composition): array {
    $lines = [];
    $count = count($composition);
    for ($i = 0; $i < $count - 1; $i++) {
        $a = is_array($composition[$i] ?? null) ? $composition[$i] : null;
        $b = is_array($composition[$i + 1] ?? null) ? $composition[$i + 1] : null;
        if ($a === null || $b === null) {
            continue;
        }
        $bg_a = _pp_resolve_component_bg($a);
        $bg_b = _pp_resolve_component_bg($b);
        if ($bg_a === null || $bg_b === null || $bg_a['id'] !== $bg_b['id']) {
            continue;
        }
        $name_a = is_string($a['component'] ?? null) ? $a['component'] : 'component';
        $name_b = is_string($b['component'] ?? null) ? $b['component'] : 'component';
        $lines[] = sprintf(
            '[%d] %s and [%d] %s share background %s (adjacent — facing paddings/margins control the visible seam)',
            $i,
            $name_a,
            $i + 1,
            $name_b,
            $bg_a['label']
        );
    }
    return $lines;
}

// ── Message Formatting ─────────────────────────────────────────────────────

/**
 * What the system prompt says about a page whose stored composition cannot be read (#750).
 *
 * SHARED DIAGNOSIS + SHARED ROUTE + A CALLER-LOCAL LEAD-IN, which is the shape ruling R-C
 * (#748) fixes for every surface and the shape _pp_batch_unreadable_target_error()
 * (lib/actions.php) already ships. The diagnosis sentence is
 * pp_composition_integrity_message()'s, so the model reads the same two nouns
 * (`unexpected_shape` / `decode_error`) the CLI prints, the refusals carry and the docs
 * teach; the route is pp_corrupt_repair_route_message()'s, so "how this page gets repaired"
 * has one spelling. Only the middle paragraph is local, because only it is specific to
 * "you are a model about to propose a change to this page".
 *
 * THE MIDDLE PARAGRAPH IS THE HALF #756 COULD NOT SHIP. Ruling D-1 admits a lone
 * `update_composition` / `restore_composition` step through the #749 batch refusal, but the
 * model could not aim for a route it was never told existed — it was told the page was
 * empty. Three things have to be here or the carve-out stays inert: the classification,
 * that band-level verbs are refused, and that the repair must travel ALONE.
 *
 * THE REFUSAL IS STATED AT THE GATE'S REAL BREADTH, not at the band level alone.
 * _pp_batch_unreadable_targets() (lib/actions.php) collects the post id off EVERY step, so a
 * proposal that merely publishes or renames the corrupt page is refused too. Naming only the
 * band verbs would have been an accurate half-truth that earns the model a refusal the
 * prompt did not predict. `patch` is deliberately NOT named: it is refused as well, but it
 * is a CLI/PHP surface (`wp pp operate patch`), not a verb a chat proposal can carry, and
 * listing it would teach the model a word that is not in its own vocabulary.
 *
 * "Ask the operator first" is the reconciliation between this issue's original framing
 * ("instruct the model not to author over it") and ruling D-1 ("the repair IS the sanctioned
 * write"). Both hold: the admitted write is a deliberate whole-composition replacement, and
 * since the stored content cannot be read back, a replacement the model invents is not a
 * repair — it is authored content over recoverable bytes, which is the failure this whole
 * issue family exists to stop.
 *
 * @param  int    $post_id  The page whose stored composition was classified.
 * @param  string $error    The classification: 'decode_error' or 'unexpected_shape'.
 * @return string           The block, newline-terminated, ready to append to the prompt.
 */
function _pp_ai_page_context_corrupt_block(int $post_id, string $error): string {
    return "Composition: UNREADABLE — no component list can be shown for this page.\n"
        . pp_composition_integrity_message($post_id, $error) . "\n"
        . 'Nothing on this page can be targeted by component_index, and every band-level verb'
        . ' (add_component, update_component, remove_component, reorder_components,'
        . ' style_component) is refused on it while the stored value is unreadable — as is'
        . ' any other step naming this page, including page-level ones like publish_page or'
        . ' update_page_title.'
        . ' The one change admitted here is the repair, and it must travel ALONE: a proposal'
        . ' whose ONLY step is update_composition carrying the whole replacement array, or'
        . ' restore_composition (#756, ruling D-1). Put a second step beside it and the whole'
        . ' proposal is refused again. Ask the operator what this page should contain before'
        . ' you send one — the stored content cannot be read back, so a replacement you invent'
        . ' is authored content, not a repair. '
        . pp_corrupt_repair_route_message($post_id) . "\n";
}

/**
 * Formats messages for OpenAI-compatible chat completions API.
 * Prepends the system prompt as the first message.
 *
 * THE PAGE-CONTEXT SECTION HAS TWO SHAPES, NOT ONE (#750). A page whose stored composition
 * cannot be read gets the corruption block above instead of the component index and the
 * composition JSON. It cannot get both: `[]` is what the degrading accessor returns for a
 * corrupt row, so printing that block underneath the diagnosis would hand the model the
 * exact sentence the diagnosis exists to contradict. A GENUINELY blank page is untouched by
 * this branch and still renders `[]` — "empty" stays reserved for empty (ruling R-C).
 *
 * @param string $system        System prompt text.
 * @param array  $conversation  Array of ['role' => string, 'content' => string].
 * @param int|null $page_id     Optional page ID to include specific page context.
 * @return array  Formatted messages array ready for the API.
 */
function pp_ai_format_messages(string $system, array $conversation, ?int $page_id = null): array {
    // Build system content
    $system_content = $system;

    // Add page-specific context if requested
    if ($page_id) {
        $page_ctx = pp_ai_page_context($page_id);
        if ($page_ctx) {
            $system_content .= "\n\n## Current Page Context\n";
            $system_content .= "Page: {$page_ctx['title']} (ID: {$page_ctx['id']}, status: {$page_ctx['status']})\n";
            // Concurrency baseline (#404): the version the composition below was read at.
            // The chat app threads this back on write to reject a stale overwrite — you do
            // not manage it; just propose changes against the composition as shown.
            $system_content .= "Composition version: {$page_ctx['composition_version']} (concurrency baseline — managed by the app, not you)\n";

            if ($page_ctx['composition_error'] !== null) {
                // Unreadable stored composition (#750): the corruption IS the page context,
                // and this branch is what keeps the `[]` composition block off a corrupt
                // page. The two arms are exclusive by construction — see the docblock.
                $system_content .= _pp_ai_page_context_corrupt_block(
                    $page_ctx['id'],
                    $page_ctx['composition_error']
                );
            } else {
                $comp_json = wp_json_encode($page_ctx['composition'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

                // Component index summary for unambiguous targeting
                if (!empty($page_ctx['composition'])) {
                    // Defensive, not dead (#750): the corruption arm above already covers
                    // every classification this read can report, so a WP_Error here means the
                    // row changed between two reads in one request. Degrade to the plain
                    // summary rather than fabricate targets.
                    $inspect_data = pp_inspect_composition($page_id);
                    if (is_wp_error($inspect_data)) {
                        $inspect_data = null;
                    }

                    $system_content .= "Components (use component_index to target):\n";
                    foreach ($page_ctx['composition'] as $idx => $item) {
                        $target = ($inspect_data && isset($inspect_data[$idx])) ? $inspect_data[$idx] : null;
                        $summary = _pp_summarize_component($item, $target);
                        $system_content .= "  [{$idx}] {$summary}\n";
                    }

                    // Adjacent same-background hint (#378): flag consecutive bands whose
                    // resolved backgrounds match so the AI treats the "two touching
                    // colored bands" case as a structural fact, not an inference (#377
                    // owns the fusing heuristic these lines point at).
                    $adjacency = _pp_adjacent_background_annotations($page_ctx['composition']);
                    if ($adjacency) {
                        $system_content .= "Adjacent bands sharing a background (fuse candidates — zero the facing paddings/margins to close the seam):\n";
                        foreach ($adjacency as $line) {
                            $system_content .= "  {$line}\n";
                        }
                    }
                }

                $system_content .= "Composition:\n```json\n{$comp_json}\n```";
            }
        }
    }

    $messages = [
        ['role' => 'system', 'content' => $system_content],
    ];

    $allowed_roles = ['user', 'assistant'];
    foreach ($conversation as $msg) {
        if (isset($msg['role'], $msg['content']) && in_array($msg['role'], $allowed_roles, true)) {
            $messages[] = [
                'role'    => $msg['role'],
                'content' => $msg['content'],
            ];
        }
    }

    return $messages;
}

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
    $parts[] = 'Style slot values must match the declared type (color, length, number, duration, font-family, shadow, gradient, position, ratio, align, text-transform). Only the `color`, `gradient`, `shadow`, and `font-family` types accept a `var()` reference (color/gradient/shadow bounded as described below; `font-family` takes a font token like `var(--font-mono)`); the `length`, `number`, `duration`, `position`, and `ratio` types are literal-only and reject `var()` in EVERY form — bare (`var(--space-lg)`) and nested inside `clamp()`/`calc()` — so look up the token\'s current value and pass that literal value (this freezes it: those types cannot FOLLOW a token the way `color` can). A `color`-typed slot or design token accepts hex, `rgb()`/`rgba()`, `hsl()`/`hsla()`, the keywords `transparent` and `currentColor`, or a single bare reference to a registered color-typed design token — `var(--color-accent)` exactly, with no fallback, no nesting, and no whitespace inside (`var(--x, #fff)` is rejected); named colors are rejected. Use a `var()` reference when a value should FOLLOW another token (e.g. "the kicker follows the brand accent") instead of duplicating a literal hex; a reference chain that loops back to the token being set is rejected as a cycle. A `gradient`-typed slot accepts either a plain color (including the forms above) or a bounded `linear-gradient()`/`radial-gradient()` (2+ color stops; `conic-gradient()`, `repeating-*-gradient()`, and `var()`/`url()`/`env()` INSIDE a gradient function are not accepted). `radial-gradient()` may carry an optional shape and/or `at <position>` clause where `<position>` is 1-2 placement keywords (`center`/`top`/`bottom`/`left`/`right`) or non-negative percentages (e.g. `radial-gradient(circle at top left, ...)`, `radial-gradient(at 20% 30%, ...)`); radial size keywords like `closest-side` and length positions like `at 10px` are not accepted. A `position`-typed slot (image/background focal point) accepts 1-2 keywords (`center`, `top`, `bottom`, `left`, `right`) or lengths/percentages (e.g. `top left`, `20% 80%`) — no functions, no `var()`. A `ratio`-typed slot (aspect ratio) accepts `auto` (natural proportions), a single positive number, or two positive numbers separated by a slash (e.g. `16/9`). An `align`-typed slot (text alignment, e.g. `--grid-item-text-align`) accepts exactly one `text-align` keyword: `left`, `right`, `center`, `start`, `end`, or `justify` — no lengths, no `position` keywords like `top`/`bottom`, and no bare `unset`/`initial`. A `text-transform`-typed slot (letter-casing, e.g. the eyebrow/kicker `--<component>-eyebrow-text-transform`) accepts exactly one `text-transform` keyword: `none` (render the text as authored, e.g. sentence case), `uppercase`, `lowercase`, or `capitalize` — no `align` keywords, no CJK `full-width`/`full-size-kana`, and no bare `unset`/`initial`. The eyebrow pill defaults to `uppercase`; set the slot to `none` when a reference shows the kicker in sentence case. Most other types reject bare CSS keywords like `unset`/`initial`/`auto` — they will fail validation — with two named exceptions: `shadow`\'s own preset `none` (or `var(--shadow-*)`) and `ratio`\'s own preset `auto` are each explicitly accepted, mirroring their slot\'s documented default. If a user asks to "remove" or "disable" a constraint (e.g. remove a max-width), do not propose an unsupported CSS keyword. Instead, set the slot to the maximum practical value for the type (e.g. `100%` for a max-width length slot) and explain what the slot supports. If the requested change is genuinely not possible through the exposed style slots, say so clearly and offer the closest achievable alternative.';
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
    $parts[] = '';

    // Image selection rules
    $parts[] = '## Image Selection Rules';
    $parts[] = '- When adding or editing components that accept images, select from the Media Library above.';
    $parts[] = '- Match images to the task by filename and alt text. Copy the full URL exactly as listed. Never invent, guess, or modify URLs.';
    $parts[] = '- If the Media Library section shows no images, tell the user no images are available. Do not hallucinate URLs. To bring in an image as a locally-owned asset, use the `import_media` apply — it returns `{attachment_id, url}` with `action` "import" (new) or "reused". Give it EITHER a remote `url` (HTTPS image; re-importing the same source URL reuses the existing attachment, so retrying is safe) OR a `file` (a server-local absolute path to a brand-kit asset — logo, favicon, OG card — copied then sideloaded, the operator\'s source file left untouched). Provide exactly one of url/file.';
    $parts[] = '- Foreground images require `image_alt` (non-empty, descriptive):';
    $parts[] = '  - hero (layout: "split"): `image_url` + `image_alt`, optionally `image_id` (Media Library attachment ID from `import_media`) for responsive srcset/sizes output. A "split" hero with neither an image nor `proof` has no second column and degrades to the single-column "left" layout; add an image or proof to get the two-column split.';
    $parts[] = '  - section (layout: "image-left" or "image-right"): `image_url` + `image_alt`, optionally `image_id` (same as hero)';
    $parts[] = '  - grid items (cards layout only): `items[].image_url` + `items[].image_alt`. By default each card image renders as a full-width 16:9 cover banner; set the grid-level `image_treatment: "icon"` prop to render it instead at a small fixed icon size (default 48px, un-cropped) above the title — the icon+title+text feature card. Unset keeps the banner; the icon box size is the `--grid-item-icon-size` style slot.';
    $parts[] = '  - logos items: `items[].image_url` + `items[].image_alt`, optionally `items[].image_id` (same as hero)';
    $parts[] = '  - nav/footer: `logo_id` (Media Library attachment ID, not a URL) + `logo_alt`';
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
 * Four fields, four jobs:
 *
 *   applies_when         when the declaration does anything at all. Rendered as the
 *                        ANDed clause list so the agent can check the condition
 *                        against the composition it is about to write, instead of
 *                        setting a value that renders nothing.
 *   conditionality_note  the same job for the three condition classes the clause
 *                        grammar deliberately cannot express (disjunction,
 *                        composed-page context, interaction state).
 *   role                 marks a slot as a component's FILL, so "make the button
 *                        blue" resolves to the fill slot and not to some other
 *                        colour slot that happens to be nearby.
 *   aliases              legacy VALUES still accepted at write. Phrased as
 *                        "also accepts legacy", never as part of the value set:
 *                        canonical values stay clean and an agent must never read
 *                        this as a value to choose. (The `theme` prop advertises
 *                        default|muted|inverted and accepts the legacy `dark`.)
 *
 * @param  array $definition  A slot or prop definition object from schema.json.
 * @return string             A leading-space suffix, or '' when nothing is declared.
 */
function pp_ai_definition_suffix(array $definition): string {
    $bits = [];

    if (!empty($definition['aliases']) && is_array($definition['aliases'])) {
        // ACCEPTED ON READ, NEVER A VALUE TO CHOOSE. The runtime catalog is always
        // in context; ai-instructions/style-component.md is read on demand. If this
        // line said only "also accepts legacy dark" it would put the deprecated
        // value in front of the agent, adjacent to `inverted`, with none of the
        // warning the instruction file carries ("renders LIGHT ... never
        // theme:\"dark\""). An agent asked for a dark band would have a brand-new
        // reason to write the one value that silently produces a light one. So the
        // caveat travels WITH the disclosure, not in a file that may never be read.
        $bits[] = 'still accepts the legacy value'
            . (count($definition['aliases']) === 1 ? ' ' : 's ')
            . '"' . implode('", "', $definition['aliases']) . '"'
            . ' on already-stored pages — never write ' . (count($definition['aliases']) === 1 ? 'it' : 'them')
            . ' on new content; use the canonical values above';
    }
    if (($definition['role'] ?? null) === 'fill') {
        $bits[] = 'role: fill (this is the component\'s fill colour)';
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
        // contain ', ' (a multi-value alias list, an `in` clause). Parenthesize the
        // suffix — as the slot catalog already does — so the prop boundaries stay
        // unambiguous and an agent can still split the line back into props.
        $suffix = pp_ai_definition_suffix($prop_def);
        if ($suffix !== '') {
            $suffix = ' (' . ltrim($suffix, '; ') . ')';
        }
        if ($type === 'enum' && !empty($prop_def['values']) && is_array($prop_def['values'])) {
            $enum_str = '"' . implode('"|"', $prop_def['values']) . '"';
            $parts[] = "{$prop_name}{$marker}: {$enum_str}{$suffix}";
        } else {
            $parts[] = "{$prop_name}{$marker}: {$type}{$suffix}";
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
 * @param int $post_id  WordPress post ID.
 * @return array  ['id' => int, 'title' => string, 'status' => string, 'composition' => array,
 *                'composition_version' => int]
 */
function pp_ai_page_context(int $post_id): array {
    $post = get_post($post_id);
    if (!$post) {
        return [];
    }

    return [
        'id'                  => $post_id,
        'title'               => $post->post_title,
        'status'              => $post->post_status,
        'composition'         => pp_get_composition($post_id),
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
                    // (#506/#507/#509) — e.g. "cta_url (string, link_url)".
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
 *      `inverted` -> the dark inverted band; `muted` (and its deprecated `dark`
 *      alias, #442, which renders the SAME light `--dark` surface) -> muted surface.
 *   4. Otherwise (default/absent/unknown theme) -> null (inherited body background).
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
    // #442: `dark` is a deprecated alias of `muted`; both render the same light band.
    // The accepted alias set here mirrors pp_theme_class() in lib/helpers.php (which
    // collapses muted/dark to the same `--dark` class) — if that alias set ever
    // changes (alias removed, new theme added), update both sites in lockstep.
    if ($theme === 'muted' || $theme === 'dark') {
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
 * Formats messages for OpenAI-compatible chat completions API.
 * Prepends the system prompt as the first message.
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
            $comp_json = wp_json_encode($page_ctx['composition'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $system_content .= "\n\n## Current Page Context\n";
            $system_content .= "Page: {$page_ctx['title']} (ID: {$page_ctx['id']}, status: {$page_ctx['status']})\n";
            // Concurrency baseline (#404): the version the composition below was read at.
            // The chat app threads this back on write to reject a stale overwrite — you do
            // not manage it; just propose changes against the composition as shown.
            $system_content .= "Composition version: {$page_ctx['composition_version']} (concurrency baseline — managed by the app, not you)\n";

            // Component index summary for unambiguous targeting
            if (!empty($page_ctx['composition'])) {
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

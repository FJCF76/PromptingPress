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
            $parts[] = "- {$page['title']} (ID: {$page['id']}, status: {$page['status']})";
        }
    } else {
        $parts[] = '## Pages';
        $parts[] = 'No pages exist yet.';
    }
    $parts[] = '';

    // Component catalog (condensed: name + required props only)
    $components = pp_get_registered_components();
    if ($components) {
        $parts[] = '## Available Components';
        foreach ($components as $name => $schema) {
            $props = pp_ai_condense_schema($schema);
            $parts[] = "- **{$name}**: {$props}";
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
        $parts[] = '## Available Applies (file mutations)';
        foreach ($applies as $name => $def) {
            $param_str = pp_ai_format_params($def['params'] ?? []);
            $parts[] = "- **{$name}** ({$def['domain']}): {$def['description']} Params: {$param_str}";
        }
    }
    $parts[] = '';

    // Design tokens
    $tokens = pp_design_tokens();
    if ($tokens) {
        $parts[] = '## Design Tokens (CSS custom properties in base.css)';
        foreach ($tokens as $token_name => $token_data) {
            $type_str = $token_data['type'] ? " ({$token_data['type']})" : '';
            $parts[] = "- `{$token_name}`: `{$token_data['value']}`{$type_str}";
        }
    }
    $parts[] = '';

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

    // Image selection rules
    $parts[] = '## Image Selection Rules';
    $parts[] = '- When adding or editing components that accept images, select from the Media Library above.';
    $parts[] = '- Match images to the task by filename and alt text. Copy the full URL exactly as listed. Never invent, guess, or modify URLs.';
    $parts[] = '- If the Media Library section shows no images, tell the user no images are available. Do not hallucinate URLs.';
    $parts[] = '- Foreground images require `image_alt` (non-empty, descriptive):';
    $parts[] = '  - hero (variant: "split"): `image_url` + `image_alt`';
    $parts[] = '  - section (layout: "image-left" or "image-right"): `image_url` + `image_alt`';
    $parts[] = '  - grid items (default variant only): `items[].image_url` + `items[].image_alt`';
    $parts[] = '  - logos items: `items[].image_url` + `items[].image_alt`';
    $parts[] = '  - nav: `logo_url` + `logo_alt`';
    $parts[] = '- Background images (no `image_alt` needed):';
    $parts[] = '  - hero (variant: "cover"): `image_url` rendered as CSS background-image';
    $parts[] = '  - section: `background_image`';
    $parts[] = '  - cta: `background_image`';
    $parts[] = '  - stats: `background_image`';
    $parts[] = '- Grid component: images only render in the default variant, not the steps variant.';
    $parts[] = '- When editing a single item in a grid or logos component, pass the complete `items` array with the modification applied at the correct index. `update_component` uses shallow merge, not positional patching.';

    return implode("\n", $parts);
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
        $parts[] = "{$prop_name}{$marker}: {$type}";
    }

    return implode(', ', $parts);
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
 * @param int $post_id  WordPress post ID.
 * @return array  ['id' => int, 'title' => string, 'status' => string, 'composition' => array]
 */
function pp_ai_page_context(int $post_id): array {
    $post = get_post($post_id);
    if (!$post) {
        return [];
    }

    return [
        'id'          => $post_id,
        'title'       => $post->post_title,
        'status'      => $post->post_status,
        'composition' => pp_get_composition($post_id),
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
        'posts_per_page' => $limit,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ]);

    $items = [];
    foreach ($attachments as $att) {
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
        'components' => array_keys(pp_get_registered_components()),
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
function _pp_summarize_component(array $item): string {
    $name  = $item['component'] ?? 'unknown';
    $props = $item['props'] ?? [];
    $parts = [$name];

    // Variant or layout (the main structural differentiator)
    if (!empty($props['variant'])) {
        $parts[] = "variant: {$props['variant']}";
    }
    if (!empty($props['layout'])) {
        $parts[] = "layout: {$props['layout']}";
    }

    // Title (short identifier)
    if (!empty($props['title'])) {
        $title = mb_strlen($props['title']) > 40
            ? mb_substr($props['title'], 0, 37) . '...'
            : $props['title'];
        $parts[] = "title: \"{$title}\"";
    }

    // Image filename (key for image-bearing components)
    foreach (['image_url', 'background_image', 'logo_url'] as $img_prop) {
        if (!empty($props[$img_prop])) {
            $parts[] = basename($props[$img_prop]);
            break;
        }
    }

    return implode(' | ', $parts);
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

            // Component index summary for unambiguous targeting
            if (!empty($page_ctx['composition'])) {
                $system_content .= "Components (use component_index to target):\n";
                foreach ($page_ctx['composition'] as $idx => $item) {
                    $summary = _pp_summarize_component($item);
                    $system_content .= "  [{$idx}] {$summary}\n";
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

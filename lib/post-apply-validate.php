<?php
/**
 * lib/post-apply-validate.php — Post-Apply Rendered HTML Validation
 *
 * After a proposal apply, re-renders the composition and inspects the HTML
 * for structural issues: broken images, missing media, empty links, render
 * failures. Gates the "success" message in the admin chat on validation passing.
 *
 * @see https://github.com/FJCF76/PromptingPress/issues/75
 */

/**
 * Validates a composition's rendered HTML after an apply.
 *
 * Renders each component via pp_get_component() in an output buffer,
 * parses with DOMDocument, and checks for structural issues.
 *
 * @param int        $post_id  WordPress post ID with _pp_composition meta.
 * @param array|null $target   Optional ['component_id' => string] or ['component_index' => int].
 *                             Null = validate all components (default, recommended).
 * @return array {
 *     @type bool   $ok       True if no errors found.
 *     @type array  $warnings Warning items (non-blocking).
 *     @type array  $errors   Error items (block success).
 * }
 */
function pp_post_apply_validate(int $post_id, ?array $target = null): array {
    $errors   = [];
    $warnings = [];

    // 1. Composition read-back from DB.
    $composition = pp_get_composition($post_id);
    if (empty($composition)) {
        $errors[] = [
            'check'   => 'composition_readback',
            'message' => 'Composition is empty or invalid after apply.',
        ];
        return ['ok' => false, 'warnings' => $warnings, 'errors' => $errors];
    }

    $db_count = count($composition);

    // Collect all local media URLs for batch verification.
    $local_urls_to_verify = [];
    $uploads              = wp_get_upload_dir();
    $uploads_baseurl      = rtrim($uploads['baseurl'], '/');

    $rendered_count = 0;

    // 2. Render each component and inspect.
    foreach ($composition as $index => $item) {
        $name  = isset($item['component']) ? (string) $item['component'] : '';
        $props = isset($item['props']) && is_array($item['props']) ? $item['props'] : [];
        $style = isset($item['style']) && is_array($item['style']) ? $item['style'] : [];

        if ($style) {
            $props['__pp_style'] = $style;
        }

        if ($name === '') {
            $errors[] = [
                'check'           => 'empty_component_name',
                'component_index' => $index,
                'message'         => "Component #{$index}: empty component name.",
            ];
            continue;
        }

        // Render in output buffer.
        $html = '';
        try {
            ob_start();
            pp_get_component($name, $props);
            $html = ob_get_clean();
        } catch (\Throwable $e) {
            if (ob_get_level()) {
                ob_end_clean();
            }
            $errors[] = [
                'check'           => 'render_exception',
                'component_index' => $index,
                'message'         => "Component #{$index} ({$name}): render threw " . $e->getMessage(),
            ];
            continue;
        }

        // Check for empty render output.
        if (trim($html) === '') {
            $errors[] = [
                'check'           => 'empty_render',
                'component_index' => $index,
                'message'         => "Component #{$index} ({$name}): rendered empty output.",
            ];
            continue;
        }

        $rendered_count++;

        // 3. DOM inspection.
        $doc = new DOMDocument();
        $doc->loadHTML(
            '<!DOCTYPE html><html><body>' . $html . '</body></html>',
            LIBXML_NOERROR | LIBXML_NOWARNING
        );

        $body = $doc->getElementsByTagName('body')->item(0);
        if (!$body) {
            continue;
        }

        // 3a. <img> elements — check src.
        $imgs = $body->getElementsByTagName('img');
        for ($i = 0; $i < $imgs->length; $i++) {
            $img = $imgs->item($i);
            $src = $img->getAttribute('src');

            if ($src === '') {
                $errors[] = [
                    'check'           => 'empty_img_src',
                    'component_index' => $index,
                    'element'         => 'img',
                    'message'         => "Component #{$index} ({$name}): image has empty src attribute.",
                ];
                continue;
            }

            // Check if local URL.
            if (strpos($src, $uploads_baseurl) === 0) {
                $local_urls_to_verify[$src] = [
                    'component_index' => $index,
                    'component_name'  => $name,
                    'element'         => 'img',
                ];
            }
            // External/CDN URLs skipped for MVP.
        }

        // 3b. Inline CSS background-image:url(...) references.
        $xpath    = new DOMXPath($doc);
        $styled   = $xpath->query('//*[@style]');
        for ($i = 0; $i < $styled->length; $i++) {
            $el        = $styled->item($i);
            $style_val = $el->getAttribute('style');
            if (preg_match_all('/background-image\s*:\s*url\(\s*[\'"]?([^\'")]*)[\'"]?\s*\)/i', $style_val, $matches)) {
                foreach ($matches[1] as $bg_url) {
                    $bg_url = trim($bg_url);
                    if ($bg_url === '') {
                        $errors[] = [
                            'check'           => 'empty_background_image',
                            'component_index' => $index,
                            'element'         => 'style',
                            'message'         => "Component #{$index} ({$name}): background-image has empty url().",
                        ];
                        continue;
                    }

                    if (strpos($bg_url, $uploads_baseurl) === 0) {
                        $local_urls_to_verify[$bg_url] = [
                            'component_index' => $index,
                            'component_name'  => $name,
                            'element'         => 'background-image',
                        ];
                    }
                }
            }
        }

        // 3c. <a> elements — check href.
        $links = $body->getElementsByTagName('a');
        for ($i = 0; $i < $links->length; $i++) {
            $a    = $links->item($i);
            $href = $a->getAttribute('href');

            if ($href === '' || $href === '#') {
                $warnings[] = [
                    'check'           => 'empty_link_href',
                    'component_index' => $index,
                    'message'         => "Component #{$index} ({$name}): link has " . ($href === '#' ? 'bare # href' : 'empty href') . '.',
                ];
            }
        }
    }

    // 4. Composition-level checks.

    // 4a. Rendered count vs DB count (D4).
    if ($rendered_count !== $db_count) {
        $errors[] = [
            'check'   => 'component_count_mismatch',
            'message' => "Expected {$db_count} components, but {$rendered_count} rendered non-empty output.",
        ];
    }

    // 4b. Duplicate component IDs.
    $ids = [];
    foreach ($composition as $index => $item) {
        if (isset($item['props']['id']) && $item['props']['id'] !== '') {
            $id = $item['props']['id'];
            if (isset($ids[$id])) {
                $warnings[] = [
                    'check'           => 'duplicate_component_id',
                    'component_index' => $index,
                    'message'         => "Component #{$index}: duplicate ID '{$id}' (also at #{$ids[$id]}).",
                ];
            } else {
                $ids[$id] = $index;
            }
        }
    }

    // 5. Batch media library verification for local URLs.
    if (!empty($local_urls_to_verify)) {
        $relative_paths = [];
        foreach (array_keys($local_urls_to_verify) as $url) {
            // _wp_attached_file stores relative paths, strip uploads baseurl prefix.
            $relative = str_replace($uploads_baseurl . '/', '', $url);
            $relative_paths[$relative] = $url;
        }

        // Single batch query: find which relative paths exist in Media Library.
        $found_paths = [];
        if (!empty($relative_paths)) {
            $query = new WP_Query([
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => count($relative_paths),
                'meta_query'     => [
                    [
                        'key'     => '_wp_attached_file',
                        'value'   => array_keys($relative_paths),
                        'compare' => 'IN',
                    ],
                ],
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ]);

            foreach ($query->posts as $attachment_id) {
                $attached_file = get_post_meta($attachment_id, '_wp_attached_file', true);
                if ($attached_file) {
                    $found_paths[$attached_file] = true;
                }
            }
        }

        // Report missing local media.
        foreach ($relative_paths as $relative => $full_url) {
            if (!isset($found_paths[$relative])) {
                $info = $local_urls_to_verify[$full_url];
                $errors[] = [
                    'check'           => 'missing_local_media',
                    'component_index' => $info['component_index'],
                    'element'         => $info['element'],
                    'detail'          => $relative,
                    'message'         => "Component #{$info['component_index']} ({$info['component_name']}): {$info['element']} references missing media ({$relative} not in Media Library).",
                ];
            }
        }
    }

    return [
        'ok'       => empty($errors),
        'warnings' => $warnings,
        'errors'   => $errors,
    ];
}

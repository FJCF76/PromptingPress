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
    $composition_result = pp_get_composition_result($post_id);
    if (!$composition_result['ok']) {
        // Corrupt/undecodable row after apply — surfaced distinctly from a
        // genuinely-empty read-back so the failure names data corruption
        // (issue #144), not just "empty".
        $errors[] = [
            'check'   => 'composition_decode_error',
            'message' => "Stored composition is corrupted after apply ({$composition_result['error']}): not a valid composition list.",
        ];
        return ['ok' => false, 'warnings' => $warnings, 'errors' => $errors];
    }
    $composition = $composition_result['composition'];
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
        // Without an encoding hint, DOMDocument::loadHTML() assumes
        // ISO-8859-1 and mis-decodes UTF-8 bytes — e.g. "café.jpg" reads
        // back as "cafÃ©.jpg", which then fails the media lookup below
        // for any real, correctly-uploaded file with a multibyte name.
        // Strip any <meta> tags from the rendered fragment first: an
        // in-content <meta charset="..."> takes priority over the
        // document-level XML encoding hint in libxml's HTML sniffing, so
        // a component whose output happens to carry one (e.g. embed's
        // shortcode-rendered content) could silently defeat the fix and
        // reintroduce the mis-decode. This validator has no legitimate
        // use for a <meta> tag inside a component fragment anyway.
        $doc = new DOMDocument();
        $doc->loadHTML(
            '<?xml encoding="utf-8"?><!DOCTYPE html><html><body>' . preg_replace('/<meta\b[^>]*>/i', '', $html) . '</body></html>',
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

            // Classify as same-site media to verify. An exact byte-prefix match
            // against the uploads baseurl is too brittle: a same-site URL whose
            // scheme/host/port differs byte-for-byte (http vs https, :443, a
            // site-relative or protocol-relative path) gets misclassified as
            // external and skipped, so missing_local_media never fires for it
            // (#83 — same defect class as #153 in the action-param validator).
            // Reuse #153's origin-aware classifier (lib/actions.php) to derive
            // the stored _wp_attached_file relative path; null => genuinely
            // external (CDN/offloaded) and skipped.
            $relative = _pp_uploads_relative_path($src, $uploads_baseurl);
            if ($relative !== null) {
                $local_urls_to_verify[$src] = [
                    'component_index' => $index,
                    'component_name'  => $name,
                    'element'         => 'img',
                    'relative'        => $relative,
                ];
            }
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

                    // Same origin-aware classification as the img src scan (#83).
                    $relative = _pp_uploads_relative_path($bg_url, $uploads_baseurl);
                    if ($relative !== null) {
                        $local_urls_to_verify[$bg_url] = [
                            'component_index' => $index,
                            'component_name'  => $name,
                            'element'         => 'background-image',
                            'relative'        => $relative,
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
        foreach ($local_urls_to_verify as $url => $info) {
            // _pp_uploads_relative_path() already resolved the stored
            // _wp_attached_file-relative form during classification (origin +
            // uploads-path aware, query/fragment dropped), so we no longer
            // re-derive it by byte-stripping the raw URL (#83).
            $relative_paths[$info['relative']] = $url;
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

    // Navigation readiness (warning-grade): surface empty/incomplete nav config for
    // the locations this composition references. Never an error — it does not gate
    // the apply's success, only informs the operator.
    foreach (pp_check_nav_readiness($composition) as $nav_check) {
        if (!$nav_check['pass']) {
            $warnings[] = [
                'check'   => 'nav_readiness',
                'message' => $nav_check['message'],
            ];
        }
    }

    return [
        'ok'       => empty($errors),
        'warnings' => $warnings,
        'errors'   => $errors,
    ];
}

/**
 * Resolves a rendered image/background URL to the `_wp_attached_file`-relative
 * path used by the batch Media Library lookup, or null when the URL is
 * genuinely external and should be skipped.
 *
 * Reuses #153's origin-aware classifier from lib/actions.php
 * (_pp_canonicalize_same_site_url, always loaded before this file via both
 * functions.php and tests/bootstrap.php) so the two media validators agree on
 * what "same-site" means:
 *
 *   same-site (absolute-under-baseurl, site-relative, protocol-relative,
 *     scheme- or default-port-mismatched)  → stored relative path (verify it)
 *   same-origin but OUTSIDE the uploads path (/wp-content/themes/…, /about.jpg)
 *                                            → null (canonicalizer rejects it)
 *   different origin (CDN/offloaded/external) → null (skip; out of #83 scope)
 *
 * Only classification changed here (#83). Verification stays the exact
 * `_wp_attached_file` batch query, so its pre-existing limits are unchanged and
 * inherited, not introduced: size derivatives (image-1024x768.jpg vs the stored
 * image.jpg) and CDN/offloaded resolution are still out of scope — the
 * action-param validator (#153) owns those via attachment_url_to_postid().
 *
 * Query strings and fragments are dropped (the canonical path is used), which
 * also removes the old str_replace path's `?ver=` false-missing class for free.
 */
function _pp_uploads_relative_path(string $url, string $uploads_baseurl): ?string {
    // Same-site absolute/relative under the real uploads baseurl. The
    // canonicalizer returns null unless the path sits under the uploads path,
    // so a same-origin URL elsewhere on the site can't produce a bogus lookup.
    if ($uploads_baseurl !== '') {
        $canonical = _pp_canonicalize_same_site_url($url, $uploads_baseurl);
        if ($canonical !== null) {
            $base_path  = rtrim((string) parse_url($uploads_baseurl, PHP_URL_PATH), '/');
            $canon_path = (string) parse_url($canonical, PHP_URL_PATH);
            // The classifier guarantees `rawurldecode(url path)` sits under the
            // (raw) $base_path — that is exactly the boundary it checks via
            // _pp_path_is_under(). Strip on the SAME decoded path so a
            // percent-encoded uploads segment (…/%75ploads/…) yields the real
            // relative path (`foo.jpg`) instead of a length-shifted garbage
            // slice. For an unencoded URL rawurldecode is a no-op, so this is
            // identical to a plain strip; a DOM-decoded multibyte filename
            // (café.jpg) has no `%` and stays byte-for-byte matched against
            // _wp_attached_file.
            return ltrim(substr(rawurldecode($canon_path), strlen($base_path)), '/');
        }
        return null;
    }

    // Fail-open parity with #153: when the uploads baseurl is empty/filtered we
    // can't derive the real path, but a conventional site-relative uploads path
    // is still verifiable rather than silently skipped.
    if (_pp_url_is_relative_uploads_path($url)) {
        // Decode first, mirroring _pp_url_is_relative_uploads_path()'s own
        // decoded prefix test, so an encoded uploads segment strips cleanly.
        $path = rawurldecode((string) parse_url(trim($url), PHP_URL_PATH));
        return ltrim(substr($path, strlen('/wp-content/uploads')), '/');
    }

    return null;
}

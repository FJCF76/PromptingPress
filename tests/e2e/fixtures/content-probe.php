<?php
/**
 * tests/e2e/fixtures/content-probe.php — the PHP half of tests/e2e/content-predicate.spec.ts
 * (#1242 T3a). Loaded by `wp eval` inside wp-env, so it runs LIVE WordPress core: the live
 * `post` allowlist (T-1) and the live WP_HTML_Processor (P-16, T-9). Read-only.
 */

if (!function_exists('pp_t3a_probe')) {
    function pp_t3a_probe(string $payload_b64): string {
        $payload = json_decode((string) base64_decode($payload_b64, true), true);
        $op = is_array($payload) ? ($payload['op'] ?? '') : '';
        if ($op === 'core_post') {
            $tags = wp_kses_allowed_html('post');
            ksort($tags);
            foreach ($tags as &$attrs) {
                if (is_array($attrs)) {
                    ksort($attrs);
                }
            }
            unset($attrs);
            return wp_json_encode(['wp_version' => get_bloginfo('version'), 'tags' => $tags]);
        }
        if ($op === 'judge') {
            $out = [];
            foreach ((array) ($payload['inputs'] ?? []) as $input) {
                $r = pp_content_sanitize((string) $input, (string) ($payload['sink'] ?? 'rich'),
                    ['tier' => (string) ($payload['tier'] ?? 'full')]);
                $out[] = [
                    'clauses' => array_values(array_unique(array_column($r['losses'], 'clause'))),
                    'html'    => $r['html'],
                ];
            }
            return wp_json_encode($out);
        }
        if ($op === 'safecss') {
            // The live core CSS filter the 'core' trust tier compares against.
            $out = [];
            foreach ((array) ($payload['inputs'] ?? []) as $css) {
                $out[] = safecss_filter_attr((string) $css);
            }
            return wp_json_encode($out);
        }
        return wp_json_encode(['error' => 'unknown op']);
    }
}

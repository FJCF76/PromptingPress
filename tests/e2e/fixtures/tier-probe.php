<?php
/**
 * tests/e2e/fixtures/tier-probe.php — the PHP half of tests/e2e/content-write-tier.spec.ts.
 * Loaded by `wp eval` inside wp-env, so it runs LIVE WordPress core and a real WP-CLI run.
 * Reports the content write tier and makes two checked writes on a throwaway draft page
 * (deleted before it returns): one construct only the widened set admits, one core admits.
 */

if (!function_exists('pp_tier_probe')) {
    function pp_tier_probe(): string {
        wp_set_current_user(1);
        $out = [
            'wp_cli'   => defined('WP_CLI') && WP_CLI,
            'disallow' => defined('DISALLOW_UNFILTERED_HTML') && DISALLOW_UNFILTERED_HTML,
            // Core's own answer for the administrator (map_meta_cap()): the web path's tier.
            'user_can' => current_user_can('unfiltered_html'),
            'tier'     => pp_content_write_tier(),
        ];
        $id = pp_create_page('Tier probe', 'draft');
        $section = static fn (string $body): array => ['component' => 'section', 'props' => ['title' => 'T', 'body' => $body]];
        try {
            // SVG: beyond core `post` on every core (a style value is judged by the live CSS filter,
            // which differs between cores).
            $widened = '<svg viewBox="0 0 1 1"><path d="M0 0"/></svg>';
            $r = pp_execute_action('update_composition', ['post_id' => $id, 'composition' => [$section($widened)]]);
            $out['widened_ok'] = (bool) $r['ok'];
            $out['widened_unfiltered_html'] = !$r['ok'] && strpos((string) ($r['error'] ?? ''), 'unfiltered_html') !== false;
            $out['widened_vouched'] = isset(pp_content_vouched($id)['f'][pp_content_value_hash($widened)]);

            $parity = '<p class="lead">Hello <strong>there</strong></p>';
            $r = pp_execute_action('update_composition', ['post_id' => $id, 'composition' => [$section($parity)]]);
            $out['parity_ok'] = (bool) $r['ok'];
            $out['parity_vouched'] = isset(pp_content_vouched($id)['f'][pp_content_value_hash($parity)]);
        } finally {
            wp_delete_post($id, true);
        }
        return (string) wp_json_encode($out);
    }
}

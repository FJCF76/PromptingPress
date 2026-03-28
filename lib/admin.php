<?php
/**
 * lib/admin.php — PromptingPress Admin Composition Editor
 *
 * Responsibilities:
 * - pp_get_registered_components()  scan components/ directory
 * - pp_validate_composition()       validate a composition array
 * - register_post_meta              declare _pp_composition meta
 * - add_meta_boxes                  "Edit Composition →" link on page edit screen
 * - wp_ajax_pp_save_composition     AJAX save handler
 * - admin page pp-composition       full-screen three-pane composition workspace
 * - admin_enqueue_scripts           load assets on workspace page
 * - wp_ajax_pp_preview_composition  AJAX preview (renders composition as full-page HTML)
 */

// ── Component Registry ──────────────────────────────────────────────────────

/**
 * Scans components/ and returns all registered components with their schemas.
 *
 * @return array  Keyed by component name: ['hero' => ['props' => [...]], ...]
 */
function pp_get_registered_components(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $base  = get_template_directory() . '/components/';
    $cache = [];

    if (!is_dir($base)) {
        return $cache;
    }

    foreach (scandir($base) as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $php = $base . $name . '/' . $name . '.php';
        if (!file_exists($php)) {
            continue;
        }
        $schema_file = $base . $name . '/schema.json';
        $schema      = [];
        if (file_exists($schema_file)) {
            $decoded = json_decode(file_get_contents($schema_file), true);
            if (is_array($decoded)) {
                $schema = $decoded;
            }
        }
        $cache[$name] = $schema;
    }

    return $cache;
}

// ── Validation ───────────────────────────────────────────────────────────────

/**
 * Validates a decoded composition array against the component registry.
 *
 * @param  array            $items  Decoded composition array.
 * @return true|WP_Error
 */
function pp_validate_composition(array $items) {
    $registered = pp_get_registered_components();

    foreach ($items as $i => $item) {
        if (!isset($item['component'])) {
            return new WP_Error(
                'invalid_composition',
                sprintf('Item %d is missing the "component" key.', $i)
            );
        }

        $name = (string) $item['component'];

        if (!isset($registered[$name])) {
            return new WP_Error(
                'invalid_composition',
                sprintf('Unknown component: "%s".', $name)
            );
        }

        $schema = $registered[$name];
        if (!empty($schema['props'])) {
            foreach ($schema['props'] as $prop_name => $prop_def) {
                if (
                    !empty($prop_def['required']) &&
                    (!isset($item['props']) || !array_key_exists($prop_name, $item['props']))
                ) {
                    return new WP_Error(
                        'invalid_composition',
                        sprintf('Component "%s" is missing required prop "%s".', $name, $prop_name)
                    );
                }
            }
        }
    }

    return true;
}

// ── Post Meta Registration ───────────────────────────────────────────────────

add_action('init', function () {
    register_post_meta('page', '_pp_composition', [
        'type'              => 'string',
        'single'            => true,
        'show_in_rest'      => false,
        'default'           => '',
        'sanitize_callback' => function ($value) {
            if ($value === '') return '';
            $decoded = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                return '';
            }
            return wp_json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        },
    ]);
});

// ── Meta Box — "Edit Composition" link on post edit screen ───────────────────

add_action('add_meta_boxes', function () {
    add_meta_box(
        'pp-composition-meta-box',
        'Page Composition',
        'pp_composition_meta_box_link_callback',
        'page',
        'side',
        'high'
    );
});

/**
 * Renders a simple "Edit Composition" button pointing to the full workspace.
 */
function pp_composition_meta_box_link_callback(WP_Post $post): void {
    $url        = admin_url('admin.php?page=pp-composition&post=' . $post->ID);
    $raw        = get_post_meta($post->ID, '_pp_composition', true);
    $count      = 0;
    if ($raw) {
        $decoded = json_decode($raw, true);
        $count   = is_array($decoded) ? count($decoded) : 0;
    }
    ?>
    <div class="pp-meta-link-wrap">
        <a href="<?php echo esc_url($url); ?>" class="button button-primary pp-open-workspace-btn">
            Open Composition Editor &rarr;
        </a>
        <?php if ($count > 0) : ?>
        <p class="description" style="margin-top:8px;">
            <?php echo $count; ?> component<?php echo $count !== 1 ? 's' : ''; ?> saved.
        </p>
        <?php else : ?>
        <p class="description" style="margin-top:8px;">No composition yet.</p>
        <?php endif; ?>
    </div>
    <style>
        .pp-open-workspace-btn { display: block; text-align: center; margin-top: 4px; }
    </style>
    <?php
}

// ── AJAX Save ─────────────────────────────────────────────────────────────────

add_action('wp_ajax_pp_save_composition', function () {
    $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;

    if (!$post_id || !isset($_POST['nonce']) ||
        !wp_verify_nonce($_POST['nonce'], 'pp_composition_' . $post_id)) {
        wp_send_json_error('Invalid nonce.');
    }

    if (!current_user_can('edit_post', $post_id)) {
        wp_send_json_error('Insufficient permissions.');
    }

    $raw     = isset($_POST['composition']) ? stripslashes($_POST['composition']) : '';
    $decoded = json_decode($raw, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        wp_send_json_error('Invalid JSON.');
    }

    $result = pp_validate_composition($decoded);
    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    }

    update_post_meta(
        $post_id,
        '_pp_composition',
        wp_slash(wp_json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
    );

    wp_send_json_success('Saved.');
});

// ── Admin Page Registration ───────────────────────────────────────────────────

add_action('admin_menu', function () {
    add_submenu_page(
        null,                          // hidden — no parent menu
        'Edit Composition',
        'Edit Composition',
        'edit_posts',
        'pp-composition',
        'pp_composition_workspace_page'
    );
});

// Add body class for full-width CSS overrides
add_filter('admin_body_class', function (string $classes): string {
    if (isset($_GET['page']) && $_GET['page'] === 'pp-composition') {
        $classes .= ' pp-workspace-page';
    }
    return $classes;
});

// ── Workspace Page Callback ───────────────────────────────────────────────────

function pp_composition_workspace_page(): void {
    $post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;

    if (!$post_id) {
        wp_die('No page specified.');
    }

    $post = get_post($post_id);

    if (!$post || $post->post_type !== 'page') {
        wp_die('Page not found.');
    }

    if (!current_user_can('edit_post', $post_id)) {
        wp_die('You do not have permission to edit this page.');
    }

    $raw        = get_post_meta($post_id, '_pp_composition', true);
    // Pretty-print stored JSON so the editor shows readable multi-line content
    if ($raw) {
        $decoded = json_decode($raw);
        if ($decoded !== null) {
            $raw = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }
    }
    $components = pp_get_registered_components();

    $back_url   = get_edit_post_link($post_id) ?: admin_url('edit.php?post_type=page');
    $page_title = esc_html($post->post_title ?: '(no title)');

    // Build component list for sidebar
    $component_list = [];
    foreach ($components as $name => $schema) {
        $props_summary = [];
        if (!empty($schema['props'])) {
            foreach ($schema['props'] as $prop_name => $def) {
                $type     = $def['type'] ?? 'string';
                $required = !empty($def['required']);
                if ($type === 'enum' && !empty($def['values'])) {
                    $type = '"' . implode('" | "', $def['values']) . '"';
                }
                $props_summary[] = [
                    'name'     => $prop_name,
                    'type'     => $type,
                    'required' => $required,
                ];
            }
        }
        $component_list[] = [
            'name'          => $name,
            'description'   => $schema['description'] ?? '',
            'props_summary' => $props_summary,
            'schema'        => $schema,
        ];
    }

    ?>
    <div class="pp-workspace" id="pp-workspace">

        <!-- ── Toolbar ───────────────────────────────────────────────── -->
        <div class="pp-toolbar">
            <div class="pp-toolbar-left">
                <a href="<?php echo esc_url($back_url); ?>" class="pp-back-link">
                    <span class="pp-back-arrow">&#8592;</span>
                    <span><?php echo $page_title; ?></span>
                </a>
            </div>
            <div class="pp-toolbar-center">
                <span class="pp-save-status" id="pp-save-status"></span>
            </div>
            <div class="pp-toolbar-right">
                <button id="pp-save-btn" class="pp-save-btn button button-primary" title="Save (Ctrl+S)">
                    Save Composition
                </button>
            </div>
        </div>

        <!-- ── Validation bar ────────────────────────────────────────── -->
        <div class="pp-error-bar" id="pp-error-bar"></div>

        <!-- ── Three panes ───────────────────────────────────────────── -->
        <div class="pp-panes">

            <!-- Editor pane -->
            <div class="pp-pane pp-pane--editor">
                <div class="pp-pane-header">JSON Composition</div>
                <div class="pp-pane-body">
                    <textarea
                        id="pp-composition-editor"
                        name="pp_composition"
                        style="display:none;"
                    ><?php echo esc_textarea($raw ?: ''); ?></textarea>
                </div>
            </div>

            <!-- Resize handle: editor | reference -->
            <div class="pp-resize-handle" data-left="editor" data-right="reference"></div>

            <!-- Reference pane -->
            <div class="pp-pane pp-pane--reference">
                <div class="pp-pane-header pp-pane-tabs">
                    <button class="pp-tab-btn pp-tab-btn--active" data-tab="components">Components</button>
                    <button class="pp-tab-btn" data-tab="schema">Schema</button>
                </div>
                <div class="pp-pane-body">

                    <div class="pp-tab-panel pp-tab-panel--active" id="pp-tab-components">
                        <ul class="pp-component-list">
                            <?php foreach ($component_list as $comp) : ?>
                            <li class="pp-component-item">
                                <button
                                    class="pp-component-insert"
                                    data-name="<?php echo esc_attr($comp['name']); ?>"
                                    title="Insert <?php echo esc_attr($comp['name']); ?>"
                                >
                                    <?php echo esc_html($comp['name']); ?>
                                </button>
                                <?php if ($comp['description']) : ?>
                                <p class="pp-comp-desc"><?php echo esc_html($comp['description']); ?></p>
                                <?php endif; ?>
                                <?php if ($comp['props_summary']) : ?>
                                <ul class="pp-props-list">
                                    <?php foreach ($comp['props_summary'] as $p) : ?>
                                    <li class="<?php echo $p['required'] ? 'pp-prop--req' : 'pp-prop--opt'; ?>">
                                        <code><?php echo esc_html($p['name']); ?></code>
                                        <span class="pp-prop-type"><?php echo esc_html($p['type']); ?></span>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                                <?php endif; ?>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                    <div class="pp-tab-panel" id="pp-tab-schema" style="display:none;">
                        <div id="pp-schema-display">
                            <p class="pp-schema-placeholder">Move your cursor inside a component block to see its schema.</p>
                        </div>
                    </div>

                </div>
            </div>

            <!-- Resize handle: reference | preview -->
            <div class="pp-resize-handle" data-left="reference" data-right="preview"></div>

            <!-- Preview pane -->
            <div class="pp-pane pp-pane--preview">
                <div class="pp-pane-header">
                    Live Preview
                    <span class="pp-preview-status" id="pp-preview-status">Loading&hellip;</span>
                </div>
                <div class="pp-pane-body pp-pane-body--preview">
                    <iframe
                        id="pp-preview-frame"
                        class="pp-preview-frame"
                        sandbox="allow-same-origin allow-scripts"
                        title="Composition preview"
                    ></iframe>
                </div>
            </div>

        </div><!-- /.pp-panes -->
    </div><!-- /.pp-workspace -->
    <?php
}

// ── AJAX Preview ──────────────────────────────────────────────────────────────

add_action('wp_ajax_pp_preview_composition', function () {
    $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;

    if (!$post_id || !isset($_POST['nonce']) ||
        !wp_verify_nonce($_POST['nonce'], 'pp_composition_' . $post_id)) {
        wp_send_json_error('Invalid nonce.');
    }

    if (!current_user_can('edit_post', $post_id)) {
        wp_send_json_error('Insufficient permissions.');
    }

    $raw         = isset($_POST['composition']) ? stripslashes($_POST['composition']) : '[]';
    $composition = json_decode($raw, true);

    if (!is_array($composition)) {
        wp_send_json_error('Invalid JSON.');
    }

    $result = pp_validate_composition($composition);
    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    }

    $dir_uri = get_template_directory_uri();

    ob_start();
    try {
        pp_get_component('nav', ['location' => 'primary']);
        echo '<main id="main">';
        foreach ($composition as $item) {
            $name  = isset($item['component']) ? (string) $item['component'] : '';
            $props = isset($item['props']) && is_array($item['props']) ? $item['props'] : [];
            if ($name !== '') {
                pp_get_component($name, $props);
            }
        }
        echo '</main>';
        pp_get_component('footer', ['location' => 'footer']);
    } catch (Throwable $e) {
        ob_end_clean();
        if (defined('WP_DEBUG') && WP_DEBUG) {
            wp_send_json_error('Render failed: ' . $e->getMessage());
        }
        wp_send_json_error('Render failed.');
    }

    $body = ob_get_clean();

    $html = '<!DOCTYPE html><html><head>'
        . '<meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<link rel="stylesheet" href="' . esc_url($dir_uri) . '/assets/css/base.css">'
        . '<link rel="stylesheet" href="' . esc_url($dir_uri) . '/assets/css/components.css">'
        . '<link rel="stylesheet" href="' . esc_url($dir_uri) . '/assets/css/utilities.css">'
        . '</head><body>' . $body . '</body></html>';

    wp_send_json_success(['html' => $html]);
});

// ── Admin Assets ──────────────────────────────────────────────────────────────

add_action('admin_enqueue_scripts', function (string $hook) {
    // Match the composition workspace by both hook name and page parameter
    if (!isset($_GET['page']) || $_GET['page'] !== 'pp-composition') {
        return;
    }

    $post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
    if (!$post_id) {
        return;
    }

    $cm_settings = wp_enqueue_code_editor(['type' => 'application/json']);
    $dir_uri     = get_template_directory_uri();

    wp_enqueue_style(
        'pp-admin-editor',
        $dir_uri . '/assets/css/pp-admin-editor.css',
        [],
        PP_VERSION
    );

    // CodeMirror disabled in user profile — still load JS for save/preview,
    // but signal the editor to show the raw textarea instead.
    $cm_deps = $cm_settings ? ['jquery', 'wp-codemirror'] : ['jquery'];

    wp_enqueue_script(
        'pp-editor-logic',
        $dir_uri . '/assets/js/pp-editor-logic.js',
        [],
        PP_VERSION,
        true
    );

    wp_enqueue_script(
        'pp-admin-editor',
        $dir_uri . '/assets/js/pp-admin-editor.js',
        array_merge($cm_deps, ['pp-editor-logic']),
        PP_VERSION,
        true
    );

    $components = pp_get_registered_components();
    $js_components = [];
    foreach ($components as $name => $schema) {
        $js_components[] = ['name' => $name, 'schema' => $schema];
    }

    wp_localize_script('pp-admin-editor', 'ppAdminEditor', [
        'components'         => $js_components,
        'codeEditorSettings' => $cm_settings ?: new stdClass(),
        'cmDisabled'         => !$cm_settings,
        'ajaxUrl'            => admin_url('admin-ajax.php'),
        'nonce'              => wp_create_nonce('pp_composition_' . $post_id),
        'postId'             => $post_id,
    ]);
});


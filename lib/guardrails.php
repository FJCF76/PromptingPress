<?php
/**
 * lib/guardrails.php — PromptingPress Substrate Guardrails
 *
 * Split-authority detection (Custom CSS vs theme CSS) and composition
 * validation helpers. Report-only — these functions surface warnings,
 * they never block or auto-fix.
 */

/**
 * CSS class prefixes emitted by PP components.
 * Used for word-boundary matching against Custom CSS selectors.
 *
 * @return string[] Root class names for each component.
 */
function pp_component_classes(): array {
    return [
        'hero',
        'section',
        'cta',
        'grid',
        'faq',
        'table-section',
        'stats',
        'logos',
        'embed',
        'nav',
        'site-header',
        'site-footer',
    ];
}

/**
 * Maps a matched ROOT CLASS back to the REGISTERED COMPONENT NAME (issue #576).
 *
 * Matching stays on the root class — that is what a Custom CSS selector actually
 * contains — but the report speaks the name an agent can act on. THREE classes differ
 * from their component name today:
 *
 *   table-section -> table    `.table` is already the inner data-table block
 *                             (components/table/table.php), so the root class is
 *                             deliberately unchanged.
 *   site-header   -> nav      chrome components; the class names the region, the
 *   site-footer   -> footer   component name is what an action parameter accepts.
 *
 * Before #576 the `--table-section-*` slot family said "table-section" too; the
 * canonical vocabulary renamed those to `--table-*`, which left this detector as the
 * LAST surface speaking a name that appears in no schema, no slot and no action
 * parameter.
 *
 * Derived from the registry's own `styling.root_class` rather than a second hardcoded
 * table, so a future component whose root class diverges is covered without touching
 * this function. A class no registered component owns reports itself.
 *
 * CONSUMER NOTE: this value surfaces in `pp_inspect_site()`'s `conflicts[].component`
 * (lib/operate.php) and in the CLI/admin notices. No consumer branches on it — they all
 * print it — but it is a value change in a machine-readable envelope, so it is recorded
 * in the changelog rather than treated as internal.
 *
 * @param  string $class  A root class from pp_component_classes().
 * @return string         The registered component name, or the class when none owns it.
 */
function pp_component_name_for_class(string $class): string {
    if (!function_exists('pp_get_registered_components')) {
        return $class;
    }
    foreach (pp_get_registered_components() as $name => $schema) {
        if (($schema['styling']['root_class'] ?? $name) === $class) {
            return $name;
        }
    }
    return $class;
}

/**
 * Checks WordPress Custom CSS for selectors that target PP component classes.
 *
 * Minimal selector extractor: splits CSS on `{`, extracts the selector part,
 * then word-boundary matches against known PP component classes. No full CSS
 * parser — this is intentional. Report-only.
 *
 * `component` reports the REGISTERED COMPONENT NAME (#576), not the matched class;
 * see pp_component_name_for_class(). Matching itself is unchanged.
 *
 * @return array[] Each entry: ['selector' => string, 'component' => string]
 */
function pp_check_custom_css_conflicts(): array {
    $css = wp_get_custom_css();
    if (!$css || !trim($css)) {
        return [];
    }

    // Strip CSS comments.
    $css = preg_replace('/\/\*.*?\*\//s', '', $css);

    // Split on { to get selector blocks.
    $parts     = explode('{', $css);
    $selectors = [];
    foreach ($parts as $i => $part) {
        if ($i === count($parts) - 1) {
            break; // Last chunk is after the final {, skip.
        }
        // The selector is everything after the last } in this chunk.
        $after_brace = strrpos($part, '}');
        $selector    = $after_brace !== false ? substr($part, $after_brace + 1) : $part;
        $selector    = trim($selector);
        if ($selector !== '') {
            $selectors[] = $selector;
        }
    }

    $classes   = pp_component_classes();
    $conflicts = [];

    foreach ($selectors as $selector) {
        foreach ($classes as $class) {
            // Match .classname or classname at a boundary.
            // Must NOT match .hero-banner when looking for .hero.
            // CSS identifiers can contain [a-zA-Z0-9_-], so we check that the
            // match is not followed or preceded by those characters.
            $pattern = '/(?<![a-zA-Z0-9_-])' . preg_quote($class, '/') . '(?![a-zA-Z0-9_-])/';
            if (preg_match($pattern, $selector)) {
                $conflicts[] = [
                    'selector'  => $selector,
                    'component' => pp_component_name_for_class($class),
                ];
                break; // One match per selector is enough.
            }
        }
    }

    return $conflicts;
}

/**
 * Renders a dismissible admin notice when Custom CSS conflicts with PP components.
 * Scoped to composition page edit screens only.
 *
 * Hooked via add_action('admin_notices', ...) in functions.php.
 */
function pp_admin_notice_css_conflicts(): void {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->base !== 'post' || $screen->post_type !== 'page') {
        return;
    }

    // Only show on composition pages.
    $post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
    if (!$post_id) {
        return;
    }
    $template = get_page_template_slug($post_id);
    if ($template !== 'composition.php') {
        return;
    }

    $conflicts = pp_check_custom_css_conflicts();
    if (empty($conflicts)) {
        return;
    }

    $selectors = array_map(fn($c) => '<code>' . esc_html($c['selector']) . '</code>', $conflicts);
    $list = implode(', ', $selectors);

    echo '<div class="notice notice-warning is-dismissible">';
    echo '<p><strong>PromptingPress:</strong> Custom CSS conflicts detected. ';
    echo 'The following selectors target PP component classes: ' . $list . '. ';
    echo 'This may override theme styling. ';
    echo 'Run <code>wp pp check conflicts</code> for details.</p>';
    echo '</div>';
}

/**
 * Classifies a path as safe, extension, or core.
 *
 * - safe: database operations (composition data, token overrides, font URLs)
 * - extension: components/, templates/, assets/css/ (theme extension files)
 * - core: lib/, functions.php, style.css, AI_RULES.md (theme internals)
 *
 * Paths are normalized relative to the theme root. Absolute paths under the
 * theme directory are converted to relative paths automatically.
 *
 * @param  string $path  File path (relative to theme root, or absolute).
 * @return array{classification: string, guidance: string}
 */
function pp_classify_surface(string $path): array {
    // Normalize to forward slashes first (issue 127) — on Windows hosting,
    // get_template_directory() and an absolute $path may both use `\`, and
    // leaving them unnormalized here produces a `\`-separated relative path
    // that then fails to match forward-slash `planned_files` entries during
    // preflight overlap matching, even though the same file is meant.
    $path      = str_replace('\\', '/', $path);
    $theme_dir = str_replace('\\', '/', get_template_directory());

    // Normalize: strip theme directory prefix if absolute.
    if (str_starts_with($path, $theme_dir)) {
        $path = ltrim(substr($path, strlen($theme_dir)), '/');
    }

    // Normalize leading slashes and resolve . / ..
    $path = ltrim($path, '/');

    // Remove any ../ traversal attempts for safety.
    $path = str_replace('../', '', $path);

    // Database-backed surfaces are "safe" — but they don't have file paths.
    // This function classifies file paths, so "safe" means extension files
    // that are explicitly designed for customization.

    // Core files: lib/*, functions.php, style.css, AI_RULES.md, AI_CONTEXT.md
    $core_patterns = [
        '#^lib/#',
        '#^functions\.php$#',
        '#^style\.css$#',
        '#^AI_RULES\.md$#',
        '#^AI_CONTEXT\.md$#',
        '#^phpunit\.xml$#',
        '#^composer\.json$#',
        '#^package\.json$#',
    ];

    foreach ($core_patterns as $pattern) {
        if (preg_match($pattern, $path)) {
            return [
                'classification' => 'core',
                'guidance'       => _pp_surface_guidance($path),
            ];
        }
    }

    // Extension files: components/*, templates/*, assets/*
    $extension_patterns = [
        '#^components/#',
        '#^templates/#',
        '#^assets/#',
    ];

    foreach ($extension_patterns as $pattern) {
        if (preg_match($pattern, $path)) {
            return [
                'classification' => 'extension',
                'guidance'       => 'Extension file. Editable but may be overwritten by theme updates. Prefer database-backed surfaces (style_component, update_design_token) when possible.',
            ];
        }
    }

    // Everything else defaults to core (conservative).
    return [
        'classification' => 'core',
        'guidance'       => _pp_surface_guidance($path),
    ];
}

/**
 * Returns routing guidance for a core file, pointing toward the correct approved surface.
 *
 * @param  string $path  Relative path within the theme.
 * @return string        Human-readable guidance message.
 */
function _pp_surface_guidance(string $path): string {
    // Route toward specific approved surfaces based on what the file controls.
    if (str_starts_with($path, 'lib/')) {
        return "Blocked: {$path} is a core theme file. To change spacing/colors, use style_component action on the target component instance. To change the color palette, use update_design_token apply.";
    }
    if ($path === 'functions.php') {
        return "Blocked: functions.php is a core theme file. To add fonts, use enqueue_font apply. To change tokens, use update_design_token apply.";
    }
    if ($path === 'style.css') {
        return "Blocked: style.css contains theme metadata only. To change design tokens, use update_design_token apply.";
    }

    return "Blocked: {$path} is a core theme file. Use approved database-backed surfaces (actions, applies) instead of direct file edits.";
}

/**
 * Returns true when a component id matches the reserved auto-generated format.
 *
 * pp_update_composition() assigns `pp-<hex8>` ids (pp_generate_component_id(),
 * lib/wp.php) to entries written without an authored `id` prop — a NEW random id
 * on every full-composition write (#232). This helper is the single detector for
 * that reserved shape, letting read-time validators tell generated ids (not
 * durable across a declarative re-apply) from authored ids (durable). The
 * `pp-<hex8>` format is documented as reserved; an authored id that deliberately
 * matches it is treated as generated.
 *
 * @param  string $id  Component id from props.
 * @return bool
 */
function pp_is_generated_component_id(string $id): bool {
    // \z, not $: PCRE $ also matches before a trailing newline, which would
    // classify "pp-xxxxxxxx\n" as generated while the strict-equality resolver
    // (pp_resolve_component_target) treats it as a distinct id.
    return (bool) preg_match('/^pp-[0-9a-f]{8}\z/', $id);
}

/**
 * Returns the durable (authored) id from a component's props, or '' when the
 * component has no id that survives a full-composition re-apply: absent,
 * non-scalar, falsy, or matching the auto-generated `pp-<hex8>` shape (#232).
 *
 * Single classification point shared by pp_find_generated_component_ids() and
 * pp_validate_composition_styling() so "what counts as a durable id" cannot
 * drift between the two validators.
 *
 * @param  array $props  Component props.
 * @return string        The authored id, or '' when none is durable.
 */
function _pp_component_durable_id(array $props): string {
    $raw = $props['id'] ?? '';
    if (empty($raw) || !is_scalar($raw)) {
        return '';
    }
    $id = (string) $raw;
    return pp_is_generated_component_id($id) ? '' : $id;
}

/**
 * Returns components whose id cannot be targeted durably by component_id.
 *
 * Flags entries whose persisted id is absent or auto-generated (`pp-<hex8>`):
 * a full-composition re-apply from source JSON regenerates those ids, so a
 * recorded component_id stops resolving (#232). Same defensive non-array
 * guards as pp_validate_composition_styling().
 *
 * @param  array $composition  Composition array (component + props).
 * @return array[]             Each entry: ['index' => int, 'component' => string, 'id' => string]
 */
function pp_find_generated_component_ids(array $composition): array {
    $findings = [];

    foreach ($composition as $i => $item) {
        if (!is_array($item)) {
            continue;
        }
        $props = is_array($item['props'] ?? null) ? $item['props'] : [];

        if (_pp_component_durable_id($props) === '') {
            $raw       = $props['id'] ?? '';
            $component = $item['component'] ?? '';
            $findings[] = [
                'index'     => $i,
                // Corrupt rows can carry non-scalar values here; the CLI
                // interpolates both fields, so coerce defensively (same class
                // of guard as pp_validate_composition_errors(), #233).
                'component' => is_scalar($component) ? (string) $component : '',
                'id'        => is_scalar($raw) ? (string) $raw : '',
            ];
        }
    }

    return $findings;
}

/**
 * Validates composition styling for ambiguous targeting.
 *
 * Flags duplicate component types that lack stable IDs — these cannot be
 * targeted individually by CSS and are prone to nth-of-type fragility.
 * An auto-generated `pp-<hex8>` id does not count as a stable id (#232):
 * id injection at write time fills every persisted entry, so counting
 * generated ids as stable made this check unreachable on any composition
 * that had been through pp_update_composition().
 *
 * @param  array $composition  Composition array (component + props).
 * @return array[]             Each entry: ['component' => string, 'indices' => int[]]
 */
function pp_validate_composition_styling(array $composition): array {
    $type_map = [];

    foreach ($composition as $i => $item) {
        // Defensive: a malformed composition that bypassed validation may
        // carry non-array items or props, so guard both before indexing.
        if (!is_array($item)) {
            continue;
        }
        $component = $item['component'] ?? '';
        $component = is_scalar($component) ? (string) $component : '';
        $props     = is_array($item['props'] ?? null) ? $item['props'] : [];

        if (_pp_component_durable_id($props) === '') {
            $type_map[$component][] = $i;
        }
    }

    $warnings = [];
    foreach ($type_map as $component => $indices) {
        if (count($indices) > 1) {
            $warnings[] = [
                'component' => $component,
                'indices'   => $indices,
            ];
        }
    }

    return $warnings;
}

/**
 * Trimmed-non-empty test for a content prop, used by the empty-band cases below
 * (issue #579). Mirrors the loose "did the author put content here?" rule the
 * #488 `content_requirement` gate applies in pp_validate_composition_errors(): a
 * trimmed non-empty string, or a non-empty array.
 *
 * ONE DELIBERATE DIVERGENCE from that gate, and it is load-bearing: **numeric zero
 * counts as EMPTY here.** The attachment-id props (`image_id`) declare `0` as their
 * schema DEFAULT meaning "no image", and they are routinely written as a literal
 * `0` rather than omitted — so treating `0` as content made the hero arm below
 * silently unreachable for the most common stored shape of a blank hero. The write
 * gate cannot adopt this: its `any_of` lists name only text and array props, none
 * of which can be a meaningful zero, and loosening a REJECT rule is a behaviour
 * change this issue does not carry. The string `"0"` is still content (a title of
 * "0" is a legitimate stat), so only the int/float zero is excluded.
 *
 * Kept as a separate predicate from the write gate's inline copy for that reason.
 * If the two ever need to converge, converge them deliberately — do not delete this
 * note and assume they were always the same rule.
 *
 * @param  mixed $value
 * @return bool
 */
function _pp_content_prop_is_filled($value): bool {
    if (is_string($value)) {
        return trim($value) !== '';
    }
    if (is_array($value)) {
        return $value !== [];
    }
    if (is_int($value) || is_float($value)) {
        return (float) $value !== 0.0; // an `image_id` of 0 is "no image", not content
    }
    return $value !== null && $value !== false && $value !== '';
}

/**
 * Returns true when a component's configured content would render no useful
 * frontend output (issue 87, widened to every band component by issue #579) —
 * either the items array is empty, or every item is missing the one subfield its
 * render path requires to produce output at all (mirrors each component's own
 * render-time skip logic in components/*.php, not a duplicate/independent content
 * check).
 *
 * #579, A-27 added `testimonials`, `embed`, `section`, `cta` and `hero`. Before
 * that the smell covered five components and the `default: return false` arm made
 * the other seven unwarnable — a testimonials band whose every item had lost its
 * `quote` renders an empty grid and reported clean, which is the same class of dead
 * band the check was built for. Each new arm mirrors its renderer:
 *
 *   testimonials  components/testimonials/testimonials.php — `if (!$quote) continue;`
 *   embed         components/embed/embed.php — the block is `content`; a title-only
 *                 embed is a heading over nothing
 *   section       needs no arm — it declares `content_requirement.any_of` (#488), so
 *                 the schema-driven branch answers for it and the warn set reads the
 *                 same ONE list the write-time reject set does
 *   cta           components/cta/cta.php — the text block is skipped unless
 *                 eyebrow/title/body, and the button is the band's remaining job
 *   hero          components/hero/hero.php — the `<h1>` renders unconditionally, so
 *                 an all-blank hero paints an empty heading and nothing else
 *
 * @param  string $component  Component slug.
 * @param  array  $props      Component props.
 * @return bool
 */
function _pp_component_is_empty(string $component, array $props): bool {
    // SCHEMA-DRIVEN ARM FIRST. A component that declares `content_requirement.any_of`
    // (#488) has already told the write path what counts as content, so the warn set
    // reads that ONE list rather than a second copy of it. Hoisted above the switch
    // and keyed on $component so ANY component that gains the annotation is covered
    // the day it lands — inside the switch this could only ever have served the one
    // component whose name was hardcoded there. Components without the annotation
    // fall through to their render-mirroring arms below.
    $any_of = pp_get_registered_components()[$component]['content_requirement']['any_of'] ?? null;
    if (is_array($any_of) && $any_of !== []) {
        foreach ($any_of as $content_prop) {
            if (_pp_content_prop_is_filled($props[$content_prop] ?? null)) {
                return false;
            }
        }
        return true;
    }

    switch ($component) {
        case 'faq':
            $items = is_array($props['items'] ?? null) ? $props['items'] : [];
            if (empty($items)) {
                return true;
            }
            foreach ($items as $item) {
                if (!empty($item['question'] ?? '')) {
                    return false;
                }
            }
            return true;

        case 'logos':
            $items = is_array($props['items'] ?? null) ? $props['items'] : [];
            if (empty($items)) {
                return true;
            }
            foreach ($items as $item) {
                if (!empty($item['image_url'] ?? '')) {
                    return false;
                }
            }
            return true;

        case 'testimonials':
            $items = is_array($props['items'] ?? null) ? $props['items'] : [];
            if (empty($items)) {
                return true;
            }
            foreach ($items as $item) {
                if (is_array($item) && !empty($item['quote'] ?? '')) {
                    return false;
                }
            }
            return true;

        case 'grid':
        case 'stats':
            return empty($props['items'] ?? []);

        case 'table':
            return empty($props['headers'] ?? []) || empty($props['rows'] ?? []);

        case 'embed':
            return !_pp_content_prop_is_filled($props['content'] ?? '');

        // `section` needs no arm: it declares `content_requirement.any_of`, so the
        // schema-driven branch above already answered for it.

        case 'cta':
            // The primary button renders unconditionally with the 'Get Started'
            // default when `button_text` is ABSENT, so an absent key is not empty.
            // Only an explicitly blanked label plus no eyebrow/title/body leaves the
            // band painting a bare empty <a>.
            $has_button = !array_key_exists('button_text', $props)
                || _pp_content_prop_is_filled($props['button_text']);
            if ($has_button) {
                return false;
            }
            foreach (['eyebrow', 'title', 'body'] as $content_prop) {
                if (_pp_content_prop_is_filled($props[$content_prop] ?? null)) {
                    return false;
                }
            }
            return true;

        case 'hero':
            // `image_id` counts alongside `image_url`: the renderer resolves either
            // into the same media, so a media-only hero is not a dead band.
            foreach (['title', 'eyebrow', 'subheading', 'button_text', 'image_url', 'image_id', 'proof'] as $content_prop) {
                if (_pp_content_prop_is_filled($props[$content_prop] ?? null)) {
                    return false;
                }
            }
            return true;

        default:
            return false;
    }
}

/**
 * Validates composition for layout smell patterns.
 *
 * Detects composition patterns that produce visually weak desktop output:
 * left-aligned heroes without balancing images, runs of text-only sections,
 * runs of narrow-width or compact-spacing components (issue 51), and
 * structured-content components (faq/grid/stats/logos/table) whose
 * configured content produces no useful frontend output (issue 87) —
 * each is individually valid against its schema, yet the rendered page
 * has an obvious dead section.
 *
 * @param  array $composition  Composition array (component + props).
 * @return array[]             Each entry: ['type' => string, 'message' => string, 'index' => int]
 */
/**
 * Returns non-empty component ids shared by more than one component (issue 238).
 *
 * Single source of truth for "what counts as a duplicate id", used by both
 * write-time validation (pp_validate_composition_errors() -> hard error that
 * rejects the write) and the advisory surfaces (pp_validate_composition_smells()
 * -> warning on `check page` / `validate site` / restore findings). Mirrors the
 * dual error+smell treatment template-owned chrome already gets, so the two
 * surfaces cannot diverge.
 *
 * Only scalar, non-empty ids can collide: pp_resolve_component_target() compares
 * the requested component_id with `===`, so an array/object id is unreachable by a
 * string target and an empty id is never a target. `"0"` is a valid, targetable
 * id, so the empty check is `=== ''`, not empty().
 *
 * @param  array $composition
 * @return array[]  Each: ['id' => string, 'indices' => int[]] for ids seen 2+ times,
 *                  first-seen order, indices ascending.
 */
function pp_find_duplicate_component_ids(array $composition): array {
    $by_id = [];
    foreach ($composition as $i => $item) {
        if (!is_array($item) || !isset($item['props']['id'])) {
            continue;
        }
        $raw = $item['props']['id'];
        if (!is_scalar($raw)) {
            continue;
        }
        $id = (string) $raw;
        if ($id === '') {
            continue;
        }
        $by_id[$id][] = $i;
    }

    $dupes = [];
    foreach ($by_id as $id => $indices) {
        if (count($indices) > 1) {
            $dupes[] = ['id' => (string) $id, 'indices' => $indices];
        }
    }
    return $dupes;
}

function pp_validate_composition_smells(array $composition): array {
    $warnings = [];

    $consecutive_text_only = 0;
    $consecutive_narrow_width = 0;
    $consecutive_compact_spacing = 0;

    foreach ($composition as $i => $item) {
        // Defensive: a malformed composition that bypassed validation may
        // carry non-array items or props, so guard both before indexing.
        if (!is_array($item)) {
            continue;
        }
        $props = is_array($item['props'] ?? null) ? $item['props'] : [];
        $component = $item['component'] ?? '';
        // Same posture, one level down: a corrupt row can hold an array/object here.
        // Casting it below would warn, and _pp_component_is_empty() declares `string`,
        // so it would throw. restore's findings (#233) run these smells over arbitrary
        // history-ring snapshots, so a malformed item must be skipped, not fatal — the
        // collect-all validator reports it as an error on the same pass.
        if (!is_scalar($component)) {
            continue;
        }
        $component = (string) $component;
        $hero_layout = $props['layout'] ?? 'centered';
        $image_url = $props['image_url'] ?? '';

        // Template-owned chrome stored in a composition (issue #223). Write-time
        // validation rejects this now, so a row can only get here by predating
        // the fix or by bypassing the action layer (a raw meta write, or a
        // restore of a legacy history snapshot). Either way the page renders the
        // header and footer twice, so no validator may report it clean.
        if (pp_is_template_owned_component((string) $component)) {
            $warnings[] = [
                'type' => 'template_owned_component',
                // Name the action, not a literal command: this function has no
                // post_id to build one from, and remove_component shifts every
                // later index down — a copy-pasted index removes the wrong
                // component on a page with two chrome items.
                'message' => sprintf(
                    '"%s" at index %d is site chrome rendered by the page template — this page renders it twice. Remove it with the remove_component action. Each removal shifts later indices down, so remove the highest index first.',
                    $component,
                    $i
                ),
                'index' => $i,
            ];
        }

        // Hero left without image
        if ($component === 'hero' && $hero_layout === 'left' && empty($image_url)) {
            $warnings[] = [
                'type' => 'hero_left_no_image',
                'message' => 'Hero layout "left" without an image creates unbalanced dead space on desktop. Consider "centered" or "split" with an image.',
                'index' => $i,
            ];
        }

        // Hero split without media (#440). A split hero with no second-column
        // ingredient (no image and no proof) has nothing to put opposite the
        // text, so the renderer degrades it to the single-column "left" layout
        // (components/hero/hero.php). That is safe, not broken — but the split
        // layout was almost certainly chosen with media in mind, so surface an
        // advisory. Deliberately a warning, never a hard error: the media may be
        // imported in a following step (image_url/image_id or proof can arrive
        // next). image_id counts as media even without image_url because the
        // renderer resolves the attachment.
        // Predicate mirrors the renderer's degradation test in hero.php: no image
        // (empty url, matching the renderer's truthy gate) AND no resolvable image_id
        // ((int) cast, matching hero.php:24 so a non-numeric id is "no media" in both
        // places) AND no proof. Kept in lock-step so the warning fires exactly when
        // the renderer degrades.
        if (
            $component === 'hero'
            && $hero_layout === 'split'
            && empty($image_url)
            && (int) ($props['image_id'] ?? 0) <= 0
            && trim((string) ($props['proof'] ?? '')) === ''
        ) {
            $warnings[] = [
                'type' => 'hero_split_no_media',
                'message' => 'Hero layout "split" has no image or proof content, so it has no second column and renders as a single-column layout. Add image_url/image_id or proof to use the split, or set layout to "centered" or "left".',
                'index' => $i,
            ];
        }

        // Track consecutive text-only sections (no image, no visual anchor)
        $layout = $props['layout'] ?? 'text-only';
        if ($component === 'section' && in_array($layout, ['text-only', 'centered'], true) && empty($image_url) && empty($props['background_image'] ?? '')) {
            $consecutive_text_only++;
        } else {
            $consecutive_text_only = 0;
        }

        if ($consecutive_text_only >= 3) {
            $warnings[] = [
                'type' => 'consecutive_text_sections',
                'message' => '3+ consecutive text-only sections without images or background variety. Consider adding an image layout, grid, or stats component to break the wall-of-text pattern.',
                'index' => $i,
            ];
            $consecutive_text_only = 0; // Reset to avoid repeated warnings.
        }

        // Track consecutive narrow-width components (page rhythm over-constrained)
        if (($props['width'] ?? 'default') === 'narrow') {
            $consecutive_narrow_width++;
        } else {
            $consecutive_narrow_width = 0;
        }

        if ($consecutive_narrow_width >= 3) {
            $warnings[] = [
                'type' => 'consecutive_narrow_width',
                'message' => '3+ consecutive components using width "narrow". Repeated width constraints often over-narrow the page rather than fixing the underlying presentation issue.',
                'index' => $i,
            ];
            $consecutive_narrow_width = 0; // Reset to avoid repeated warnings.
        }

        // Track consecutive compact-spacing components (page rhythm over-constrained)
        if (($props['spacing'] ?? 'default') === 'compact') {
            $consecutive_compact_spacing++;
        } else {
            $consecutive_compact_spacing = 0;
        }

        if ($consecutive_compact_spacing >= 3) {
            $warnings[] = [
                'type' => 'consecutive_compact_spacing',
                'message' => '3+ consecutive components using spacing "compact". Repeated compact spacing tends to cramp the page rather than improving it.',
                'index' => $i,
            ];
            $consecutive_compact_spacing = 0; // Reset to avoid repeated warnings.
        }

        // Transparent fill on a fill-role slot (issue #579, A-34). A NON-BLOCKING
        // warning, by ruling: `transparent` and `currentColor` are well-formed,
        // legal, and useful values for most colour slots — they are only
        // INEFFECTIVE in this one context, which is exactly the "plausible but
        // ineffective" class the smells channel exists for. Rejecting them would
        // break the split the #570 decision record draws between "provably dead"
        // (reject at write) and "plausible but ineffective" (warn, never block).
        //
        // The observed failure it names: fill=rgba(0,0,0,0) ring=rgba(0,0,0,0)
        // ink=rgb(252,253,255) on a white page — a button that is present, focusable,
        // clickable, and completely invisible. An author who wants a see-through
        // button wants the `outline` variant, which keeps a visible border and
        // readable ink; the message says so rather than just naming the problem.
        //
        // Reads the DECLARED `role: "fill"` marker from the schema (#575's field,
        // consumed here), never a `-bg` name convention: a convention is a second
        // source of truth, which is the defect the definition-surface contract fixes
        // one layer down. Legacy slot NAMES are resolved first so a stored
        // `--hero-cta2-bg` warns the same as its canonical twin.
        //
        // The SAME pass also carries the `inert_slot` advisory (issue #580). Both read a
        // DECLARED field off the same slot definition, both resolve the same legacy slot
        // names, and both are non-blocking — so they share one walk of the style map
        // rather than two loops that could drift on which names they canonicalize.
        $style_map = is_array($item['style'] ?? null) ? $item['style'] : [];
        if ($style_map !== []) {
            $slot_defs  = pp_get_style_slots($component);
            $fill_slots = [];
            foreach ($slot_defs as $slot_name => $slot_def) {
                if (is_array($slot_def) && ($slot_def['role'] ?? null) === 'fill') {
                    $fill_slots[$slot_name] = true;
                }
            }
            $slot_aliases = pp_legacy_slot_aliases()[$component] ?? [];
            // The EFFECTIVE style map: the declarations that will actually paint, keyed by
            // canonical name, plus which authored key each one came from. The advisory has
            // to agree with the renderer about this or it reports on declarations the page
            // never sees, so it resolves the same two rules pp_render_style_vars() does,
            // through the SAME pp_style_declaration_renders() predicate rather than a
            // second copy of "will this paint?":
            //
            //   1. CANONICAL-WINS, CONDITIONALLY. A legacy name yields to its canonical
            //      twin only when that twin actually renders (an undeclared, empty or
            //      render-boundary-rejected canonical value does NOT get to kill the legacy
            //      declaration that is doing the painting). Keying blindly on last-write
            //      would make the answer depend on JSON key order.
            //   2. A DECLARATION THAT CANNOT PAINT IS NOT A DECLARATION. An empty value, an
            //      undeclared slot name, or a value the #330 render boundary rejects is
            //      dropped by the renderer — warning that it "has no effect as configured"
            //      would be true for the wrong reason, and would put a stale no-op entry on
            //      a channel that halts `wp pp validate site`.
            //
            // $canonical_style is also what the sibling-slot clause form
            // (`{"slot":"--x","present":true}`) reads, so that form asks about the same
            // painted state the author sees.
            $canonical_of    = [];
            $canonical_style = [];
            $authored_of     = [];
            foreach ($style_map as $raw_name => $raw_value) {
                if (!is_string($raw_name)) {
                    continue;
                }
                $canonical_of[$raw_name] = $slot_aliases[$raw_name] ?? $raw_name;
                $canonical               = $canonical_of[$raw_name];
                // Non-scalar values are skipped BEFORE the predicate, not inside it: a
                // history-ring snapshot or a raw meta write can carry an array here, and
                // pp_style_declaration_renders() casts to string — which emits an "Array to
                // string conversion" warning into a path documented never to block. Same
                // guard, same reason, as the slot loop below.
                if (!is_scalar($raw_value)) {
                    continue;
                }
                if ($canonical !== $raw_name
                    && array_key_exists($canonical, $style_map)
                    && is_scalar($style_map[$canonical])
                    && pp_style_declaration_renders($canonical, $style_map[$canonical], $slot_defs)) {
                    continue;
                }
                if (!pp_style_declaration_renders($canonical, $raw_value, $slot_defs)) {
                    continue;
                }
                $canonical_style[$canonical] = $raw_value;
                $authored_of[$canonical]     = $raw_name;
            }
            // The slots that actually declare a condition, resolved once per item. Most
            // slots declare none, and this keeps the per-slot loop below from asking the
            // component registry about every authored slot on every page of a site scan.
            $conditional_slots = [];
            foreach ($slot_defs as $slot_name => $slot_def) {
                if (is_array($slot_def)
                    && is_array($slot_def['applies_when'] ?? null)
                    && $slot_def['applies_when'] !== []) {
                    $conditional_slots[$slot_name] = $slot_def['applies_when'];
                }
            }
            foreach ($style_map as $slot_name => $slot_value) {
                if (!is_string($slot_name) || !is_scalar($slot_value)) {
                    continue;
                }
                $canonical = $canonical_of[$slot_name];

                // Inert slot (issue #580, A-8b/A-17). A declared slot whose `applies_when`
                // is unmet renders NOTHING — the reported-success-without-effect failure
                // class. Advisory only, exactly like transparent_fill above: the value is
                // well-formed and would work on a sibling component, it just does nothing
                // in THIS configuration, and the fix is an authoring decision (change the
                // prop, or drop the slot) that no validator may make for the author.
                //
                // Derived from the SAME `applies_when` the AI catalog advertises (ruling
                // 8, one source of truth). The condition text is rendered by the catalog's
                // own formatter so the before-the-write advice and the after-the-write
                // warning can never phrase a condition differently.
                //
                // The function_exists pair covers BOTH modules the advisory borrows — the
                // evaluator from lib/admin.php and the formatter from lib/ai-context.php —
                // so a partial include degrades to silence instead of a fatal, and instead
                // of half a defense. functions.php loads all three; the guard is for a
                // caller that includes lib/guardrails.php on its own.
                //
                // KNOWN BOUND, stated so nobody reads more into it: this closes the
                // conditions the four-form grammar can express. The three classes that
                // stay prose in `conditionality_note` — disjunction, `main >` composed-page
                // scope, interaction state — are unevaluable by construction and stay
                // silent here. They reach the author through the catalog, not this channel.
                //
                // ONE warning per PAINTED declaration. `$authored_of[$canonical]` is the
                // key that actually renders, so a composition storing both
                // `--testimonials-card-bg` and `--testimonials-item-bg` — one emitted
                // custom property — warns once, under the name the author's page is
                // actually using, regardless of stored key order.
                if (isset($conditional_slots[$canonical])
                    && ($authored_of[$canonical] ?? null) === $slot_name
                    && function_exists('pp_applies_when_unmet_clauses')
                    && function_exists('pp_ai_format_applies_when_clause')) {
                    $unmet = pp_applies_when_unmet_clauses(
                        $conditional_slots[$canonical],
                        $component,
                        $props,
                        $canonical_style
                    );
                    $phrases = [];
                    foreach ($unmet as $clause) {
                        $rendered = pp_ai_format_applies_when_clause($clause);
                        if ($rendered !== '') {
                            $phrases[] = $rendered;
                        }
                    }
                    if ($phrases) {
                        // ONE warning per slot, listing EVERY unmet clause. Stopping at the
                        // first miss would tell an author on a centered hero to switch to
                        // `split` and leave --hero-surface-bg just as dead, because the
                        // missing `proof` never got named.
                        $warning = [
                            'type'    => 'inert_slot',
                            'message' => sprintf(
                                'Style slot "%s" on this "%s" component has no effect as configured: it applies when %s. Either set that up, or drop the slot — the value is stored and reported as applied, but nothing on the page reads it.',
                                $slot_name,
                                $component,
                                implode(' AND ', $phrases)
                            ),
                            'index'   => $i,
                        ];
                        if (!empty($props['id'] ?? '')) {
                            $warning['id'] = $props['id'];
                        }
                        $warnings[] = $warning;
                        // An inert slot renders NOTHING, so no other advisory about its
                        // VALUE can be true. Without this, a cta with no `button2_text` and
                        // `--cta-button2-bg: transparent` also collected the transparent_fill
                        // warning, which tells the author to switch to the `outline` variant
                        // for a button that is not on the page at all — two entries on the
                        // halting channel, one of them unactionable.
                        continue;
                    }
                }

                if (!isset($fill_slots[$canonical])) {
                    continue;
                }
                $normalized = strtolower(trim((string) $slot_value));
                if ($normalized !== 'transparent' && $normalized !== 'currentcolor') {
                    continue;
                }
                // RESTING vs HOVER get different advice, because the same value means
                // two different things. On a resting fill it is the invisible-button
                // defect. On a HOVER fill it only flattens the pointer state — and
                // pointing an author who is already on the `outline` variant at the
                // `outline` variant is advice that cannot be acted on. The hover
                // wording says what actually happens instead.
                $is_hover = strpos($canonical, '-hover-') !== false;
                $warning  = [
                    'type'    => 'transparent_fill',
                    'message' => $is_hover
                        ? sprintf(
                            'Style slot "%s" on this "%s" component is set to "%s", so the button gets no fill on hover — the hover state will look identical to the resting state unless another hover slot (border or label colour) carries the change. That is correct for a deliberately flat "outline" or "ghost" button; set a visible colour here if the button is meant to respond to the pointer.',
                            $slot_name,
                            $component,
                            (string) $slot_value
                        )
                        : sprintf(
                            'Style slot "%s" on this "%s" component is set to "%s", which removes the button\'s fill entirely — the button stays clickable but has no visible surface. For a see-through button use the "outline" button variant, which keeps a visible border and readable label.',
                            $slot_name,
                            $component,
                            (string) $slot_value
                        ),
                    'index'   => $i,
                ];
                if (!empty($props['id'] ?? '')) {
                    $warning['id'] = $props['id'];
                }
                $warnings[] = $warning;
            }
        }

        // Empty structured-content section (schema-valid, no useful output)
        if (_pp_component_is_empty($component, $props)) {
            $warning = [
                'type' => 'empty_section',
                'message' => sprintf(
                    'This "%s" section has no content to render — it will show as an empty/placeholder block on the live page. Remove it, fill it in, or ask before publishing.',
                    $component
                ),
                'index' => $i,
            ];
            if (!empty($props['id'] ?? '')) {
                $warning['id'] = $props['id'];
            }
            $warnings[] = $warning;
        }
    }

    // Duplicate authored component ids (issue 238). Write-time validation rejects
    // these before persist, so a row reaches here only from a raw or legacy write;
    // surface it as a targeting warning on `check page` / `validate site` and in
    // restore findings, mirroring the write-time error (same pattern as
    // template_owned_component). Shared detector keeps both surfaces in step.
    foreach (pp_find_duplicate_component_ids($composition) as $dupe) {
        $warnings[] = [
            'type'    => 'duplicate_component_id',
            'message' => sprintf(
                'Duplicate component id "%s" on indices %s — id-based targeting can no longer pick one component (update/remove/style fail closed). Give each component a unique id.',
                $dupe['id'],
                implode(', ', $dupe['indices'])
            ),
            'index'   => $dupe['indices'][1],
        ];
    }

    return $warnings;
}

// ── Theme Integrity ──────────────────────────────────────────────────────

/**
 * Checks shipped theme files against the integrity manifest.
 *
 * Loads integrity-manifest.json from the theme root, validates its JSON
 * and schema, hashes all current theme files, and compares. Stores the
 * result in the 'pp_theme_integrity' option.
 *
 * @return array|null  Result array with status/modified/missing/extra, or
 *                     null if no manifest exists (pre-integrity theme).
 */
function pp_check_theme_integrity(): ?array {
    $theme_path = get_template_directory();
    $manifest_path = $theme_path . '/integrity-manifest.json';

    // No manifest = pre-integrity theme version. No opinion.
    if (!file_exists($manifest_path)) {
        return null;
    }

    $now = gmdate('c');

    // Read and validate JSON.
    $raw = file_get_contents($manifest_path);
    $manifest = json_decode($raw, true);

    if (!is_array($manifest)) {
        $result = [
            'status'     => 'invalid_manifest',
            'checked_at' => $now,
            'version'    => PP_VERSION,
            'modified'   => [],
            'missing'    => [],
            'extra'      => [],
            'error'      => 'Invalid JSON: ' . (json_last_error_msg() ?: 'decode returned null'),
        ];
        update_option('pp_theme_integrity', $result, true);
        return $result;
    }

    // Schema validation: require version (string) and file_hashes (non-empty object).
    if (!isset($manifest['version']) || !is_string($manifest['version'])) {
        $result = [
            'status'     => 'invalid_manifest',
            'checked_at' => $now,
            'version'    => PP_VERSION,
            'modified'   => [],
            'missing'    => [],
            'extra'      => [],
            'error'      => 'Missing or invalid required key: version',
        ];
        update_option('pp_theme_integrity', $result, true);
        return $result;
    }

    if (!isset($manifest['file_hashes']) || !is_array($manifest['file_hashes']) || empty($manifest['file_hashes'])) {
        $result = [
            'status'     => 'invalid_manifest',
            'checked_at' => $now,
            'version'    => PP_VERSION,
            'modified'   => [],
            'missing'    => [],
            'extra'      => [],
            'error'      => 'Missing or empty required key: file_hashes',
        ];
        update_option('pp_theme_integrity', $result, true);
        return $result;
    }

    $manifest_hashes = $manifest['file_hashes'];

    // Hash current theme files.
    $current_hashes = _pp_hash_all_theme_files($theme_path);

    // Compare.
    $modified = [];
    $missing  = [];
    $extra    = [];

    foreach ($manifest_hashes as $path => $expected_hash) {
        if (!isset($current_hashes[$path])) {
            $missing[] = $path;
        } elseif ($current_hashes[$path] !== $expected_hash) {
            $modified[] = $path;
        }
    }

    foreach ($current_hashes as $path => $hash) {
        if (!isset($manifest_hashes[$path])) {
            $extra[] = $path;
        }
    }

    $status = (empty($modified) && empty($missing) && empty($extra)) ? 'safe' : 'unsafe';

    $result = [
        'status'     => $status,
        'checked_at' => $now,
        'version'    => $manifest['version'],
        'modified'   => $modified,
        'missing'    => $missing,
        'extra'      => $extra,
        'error'      => null,
    ];

    update_option('pp_theme_integrity', $result, true);

    // Drift is gone — clear any stale "last update blocked" record so the admin
    // notice self-heals (via the daily cron, activation, post-update, or CLI)
    // once the files are restored. Only the unsafe state is worth surfacing.
    if ($status === 'safe') {
        delete_option('pp_last_blocked_update');
    }

    return $result;
}

/**
 * Renders a persistent admin notice when theme files have drifted from the
 * shipped baseline, or when the integrity manifest is invalid.
 *
 * Reads the stored option (no file I/O). Clears stale results when the
 * theme version has changed (the old status no longer applies).
 *
 * Hooked via add_action('admin_notices', ...) in functions.php.
 */
function pp_admin_notice_theme_integrity(): void {
    $option = get_option('pp_theme_integrity');
    if (!is_array($option) || empty($option['status'])) {
        return;
    }

    // Version mismatch = theme was updated. Old status is stale — clear it.
    if (($option['version'] ?? '') !== PP_VERSION) {
        delete_option('pp_theme_integrity');
        return;
    }

    $status = $option['status'];

    if ($status === 'unsafe') {
        $modified_count = count($option['modified'] ?? []);
        $missing_count  = count($option['missing'] ?? []);
        $extra_count    = count($option['extra'] ?? []);

        $parts = [];
        if ($modified_count) {
            $parts[] = $modified_count . ' modified';
        }
        if ($missing_count) {
            $parts[] = $missing_count . ' missing';
        }
        if ($extra_count) {
            $parts[] = $extra_count . ' extra';
        }

        echo '<div class="notice notice-error">';
        echo '<p><strong>PromptingPress:</strong> Theme files have been modified locally';
        if ($parts) {
            echo ' (' . implode(', ', $parts) . ')';
        }
        echo '. Updating the theme will overwrite these changes. ';
        echo 'Run <code>wp pp integrity check</code> for details.</p>';
        echo '</div>';
        return;
    }

    if ($status === 'invalid_manifest') {
        echo '<div class="notice notice-warning">';
        echo '<p><strong>PromptingPress:</strong> Cannot verify theme file integrity &mdash; ';
        echo 'the shipped integrity manifest is invalid or unreadable (version ' . esc_html(PP_VERSION) . '). ';
        echo 'Restore <code>integrity-manifest.json</code> from the matching GitHub release, ';
        echo 'then run <code>wp pp integrity check</code>.</p>';
        echo '</div>';
        return;
    }
}

/**
 * Renders a persistent admin notice when a theme update was blocked because
 * local files had drifted from the shipped baseline.
 *
 * This is a separate notice from pp_admin_notice_theme_integrity() on purpose:
 * a blocked SILENT auto-update never surfaces a live WP_Error to a human, so
 * this is the only place the site owner learns when, why, and which files
 * caused the block. Reads the stored option (no file I/O).
 *
 * Hooked via add_action('admin_notices', ...) in functions.php.
 */
function pp_admin_notice_last_blocked_update(): void {
    $blocked = get_option('pp_last_blocked_update');
    if (!is_array($blocked) || empty($blocked['timestamp'])) {
        return;
    }

    if (($blocked['status'] ?? '') === 'invalid_manifest') {
        $reason = 'theme file integrity could not be verified';
    } else {
        $parts = [];
        if (!empty($blocked['modified'])) { $parts[] = count($blocked['modified']) . ' modified'; }
        if (!empty($blocked['missing']))  { $parts[] = count($blocked['missing'])  . ' missing'; }
        if (!empty($blocked['extra']))    { $parts[] = count($blocked['extra'])    . ' extra'; }
        $reason = 'local theme files had changed'
            . ($parts ? ' (' . implode(', ', $parts) . ')' : '');
    }

    echo '<div class="notice notice-error">';
    echo '<p><strong>PromptingPress:</strong> A theme update was blocked on ';
    echo esc_html($blocked['timestamp']) . ' because ' . esc_html($reason) . '. ';
    echo 'Updating would have overwritten or deleted those changes. ';
    echo 'Run <code>wp pp integrity check</code> for the file list.</p>';
    echo '</div>';
}

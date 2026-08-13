<?php
/**
 * components/section/section.php
 *
 * Generic text + optional image section.
 * Props: see schema.json
 *
 * @var array $props
 */

$id               = $props['id']               ?? '';
$title            = $props['title']            ?? '';
$title_accent     = $props['title_accent']     ?? '';
$eyebrow          = $props['eyebrow']          ?? '';
$subheading       = $props['subheading']       ?? '';
$title_align    = $props['title_align']    ?? 'start';
$body             = $props['body']             ?? '';
$image_url        = $props['image_url']        ?? '';
$image_alt        = $props['image_alt']        ?? '';
// is_numeric() BEFORE the (int) cast (#614, extended to the top-level readers at
// gate 7A). `(int) ['attachment_id' => 42]` and `(int) true` both evaluate to 1, so
// a bare cast resolves attachment ID 1 — usually the site's first upload — and
// discards the author's image_url. The write path rejects that shape now, but the
// validator gates WRITES: restore_composition reports without blocking (#233), so a
// composition authored before the rule still reaches this line. Same guard hero,
// logos, grid and testimonials carry — 5/5.
$raw_image_id     = $props['image_id'] ?? 0;
$image_id         = is_numeric($raw_image_id) ? (int) $raw_image_id : 0;
$layout           = $props['layout']           ?? 'text-only';
$theme            = $props['theme']            ?? 'default';
$background_image = $props['background_image'] ?? '';

// Inline-items row (issue 475): an optional centered row of short plain-text
// items with a CSS-generated, slot-colorable separator between them. Plain
// strings only (no HTML, esc_html at render); the write-time validator caps the
// count/length and rejects non-strings, so here we only drop empty strings for a
// byte-identical unset path. Renders after the body when both are set.
$body_items = is_array($props['body_items'] ?? null) ? $props['body_items'] : [];
$body_items = array_values(array_filter(
    $body_items,
    static function ($item) {
        return is_string($item) && $item !== '';
    }
));

// text-panel layout: right-hand content panel props (see schema.json).
$panel_heading      = $props['panel_heading']      ?? '';
$panel_body         = $props['panel_body']         ?? '';
$panel_items        = is_array($props['panel_items'] ?? null) ? $props['panel_items'] : [];
$panel_cta_text     = $props['panel_cta_text']     ?? '';
$panel_cta_url      = $props['panel_cta_url']      ?? '';
$panel_cta_variant  = $props['panel_cta_variant']  ?? 'primary';
$panel_items_marker = $props['panel_items_marker'] ?? 'disc';
$body_marker        = $props['body_marker']        ?? 'disc';

// A panel entry is EITHER a plain string (a bullet, unchanged) OR a paired-row
// object { label, value, style? } (issue 334). Keep non-empty strings and any
// array that carries a scalar label or value; drop everything else (empty
// strings, numbers, and shapeless arrays) exactly as the string-only form did.
$panel_items = array_values(array_filter(
    $panel_items,
    static function ($item) {
        if (is_string($item)) {
            return $item !== '';
        }
        if (is_array($item)) {
            $has_label = isset($item['label']) && is_scalar($item['label']) && (string) $item['label'] !== '';
            $has_value = isset($item['value']) && is_scalar($item['value']) && (string) $item['value'] !== '';
            return $has_label || $has_value;
        }
        return false;
    }
));

$allowed_panel_cta_variants = ['primary', 'secondary', 'outline', 'ghost'];
if (!in_array($panel_cta_variant, $allowed_panel_cta_variants, true)) {
    $panel_cta_variant = 'primary';
}
// primary is the bare .btn; other variants add a .btn--{variant} modifier.
$panel_cta_variant_class = $panel_cta_variant !== 'primary' ? ' btn--' . $panel_cta_variant : '';

// List-marker selection (issue 339). A list can carry a marker other than the
// default disc — check / dash / arrow — with an authorable marker colour (the
// --section-panel-marker-color / --section-body-marker-color slots). Generic
// marker values only; nothing encodes a use-case. `disc` is the default and adds
// NO class, so an un-opted list renders byte-identically to before.
$allowed_markers = ['disc', 'check', 'dash', 'arrow'];
if (!in_array($panel_items_marker, $allowed_markers, true)) {
    $panel_items_marker = 'disc';
}
if (!in_array($body_marker, $allowed_markers, true)) {
    $body_marker = 'disc';
}
// text-panel list opts in with the shared .pp-marker-list treatment on its <ul>.
$panel_list_marker_class = $panel_items_marker !== 'disc'
    ? ' pp-marker-list pp-marker-list--' . $panel_items_marker
    : '';
// section.body opts in with a modifier on the container we control; the shared
// rules style its direct-child <ul> (nested/plugin lists keep their disc).
$content_marker_class = $body_marker !== 'disc'
    ? ' section__content--marker-' . $body_marker
    : '';

// The panel CTA needs BOTH a label and a URL to render.
$has_panel_cta = $panel_cta_text !== '' && $panel_cta_url !== '';

// The panel column renders only when it has some content; otherwise text-panel
// degrades to a plain text section (mirrors the image-layout fallback below).
// panel_body counts so a body-only panel never silently drops authored content.
$has_panel = $panel_heading !== ''
    || $panel_body !== ''
    || !empty($panel_items)
    || $has_panel_cta;

$allowed_layouts = ['text-only', 'image-left', 'image-right', 'centered', 'text-panel'];
if (!in_array($layout, $allowed_layouts, true)) {
    $layout = 'text-only';
}

// Centered layout suppresses image regardless.
// text-panel falls back to text-only when the panel has no content.
// For image layouts, fall back to text-only if no image URL.
if ($layout === 'text-panel') {
    if (!$has_panel) {
        $layout = 'text-only';
    }
} elseif ($layout !== 'centered' && !$image_url) {
    $layout = 'text-only';
}

$allowed_title_aligns = ['start', 'center'];
if (!in_array($title_align, $allowed_title_aligns, true)) {
    $title_align = 'start';
}
$header_align_class = $title_align === 'center' ? ' section__header--center' : '';

// theme coercion lives in pp_theme_class(); `muted` emits the legacy `--dark` class (#570 DG-4).
$theme_class = pp_theme_class($theme, 'pp-section');
$bg_image_class = $background_image ? ' section--has-bg-image' : '';

// Style slot overrides (per-instance visual customization).
$slot_style = pp_render_style_vars($props['__pp_style'] ?? [], 'section');

$inline_styles = [];
if ($slot_style) {
    $inline_styles[] = $slot_style;
}
if ($background_image) {
    $inline_styles[] = 'background-image:url(' . pp_esc_image_src($background_image) . ')';
}
$style_attr = $inline_styles ? ' style="' . implode('; ', $inline_styles) . ';"' : '';

// Build the inline-items row once (issue 475) and place it in each layout's body
// scope, after .section__content. role="list" keeps list semantics while the
// CSS-generated `li + li::before` separator stays out of the accessibility tree.
//
// Flush-top margin (issue 488): the row carries a body-relative top margin
// (var(--space-md)) only when body copy precedes it. On a body-less strip — the
// primary #475 "trust strip" use case, now authorable without a `body:""`
// placeholder (#488) — that margin would push the row below the band's optical
// centre, so it zeroes when there is no body copy. Keyed on the SAME trimmed-body
// notion the content requirement uses, not on the empty string, so a whitespace-
// only body counts as no body. The empty .section__content wrapper still renders
// (byte-identical markup), but it carries no margin/height, so zeroing the row's
// top margin is the whole fix. Renders after body copy keep today's spacing.
// is_string guard first: $body defaults to '' but a raw/legacy/restore snapshot
// can carry a non-string here (write-time validation doesn't protect those paths),
// and trim() on a non-string is a fatal in PHP 8 — keep the render defensive.
$has_body_copy = is_string($body) && trim($body) !== '';
$inline_items_html = '';
if (!empty($body_items)) {
    $items_markup = '';
    foreach ($body_items as $body_item) {
        $items_markup .= '<li class="section__inline-item">' . esc_html($body_item) . '</li>';
    }
    // Per-line alignment (issue 510): the --section-inline-items-align style slot
    // selects the wrap technique. 'start' (default/unset/anything-else) keeps the
    // #489 hanging-separator clip — left-packed lines, byte-identical to before.
    // 'center' derives the --center modifier, which switches the row to per-line
    // centering with a trailing separator (see components.css). The value is read
    // from the validated component style map (top-level `style` → __pp_style); the
    // strict === 'center' check is fail-safe: any other/absent/malformed value
    // falls through to the unchanged left-packed default. justify-content itself is
    // driven by the slot's own custom property in CSS; the modifier only carries
    // what a raw keyword cannot (the ::before→::after separator switch + margin).
    $inline_items_align = $props['__pp_style']['--section-inline-items-align'] ?? '';
    $inline_items_class = 'section__inline-items'
        . ($has_body_copy ? '' : ' section__inline-items--flush-top')
        . ($inline_items_align === 'center' ? ' section__inline-items--center' : '');
    $inline_items_html = '<ul class="' . $inline_items_class . '" role="list">' . $items_markup . '</ul>';
}

?>
<section<?php echo $id ? ' id="' . esc_attr($id) . '"' : ''; ?> class="section section--<?php echo esc_attr($layout); ?><?php echo esc_attr($theme_class); ?><?php echo esc_attr($bg_image_class); ?>" data-pp-component="section"<?php echo $style_attr; ?>>
    <?php if ($background_image) : ?>
        <div class="section__overlay" aria-hidden="true"></div>
    <?php endif; ?>
    <div class="container">

        <?php if ($layout === 'text-only' || $layout === 'centered') : ?>

            <div class="section__body">
                <?php if ($title || $eyebrow || $subheading) : ?>
                    <div class="section__header<?php echo esc_attr($header_align_class); ?>">
                        <?php if ($eyebrow) : ?>
                            <span class="section__eyebrow"><?php echo esc_html($eyebrow); ?></span>
                        <?php endif; ?>
                        <?php if ($title) : ?>
                            <h2 class="section__title"><?php echo pp_render_heading_with_accent($title, $title_accent, 'section__title-accent'); ?></h2>
                        <?php endif; ?>
                        <?php if ($subheading) : ?>
                            <p class="section__subheading"><?php echo esc_html($subheading); ?></p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <div class="section__content<?php echo esc_attr($content_marker_class); ?>">
                    <?php echo wp_kses_post($body); ?>
                </div>
                <?php echo $inline_items_html; ?>
            </div>

        <?php elseif ($layout === 'text-panel') : ?>

            <div class="section__grid">
                <div class="section__body">
                    <?php if ($title || $eyebrow || $subheading) : ?>
                        <div class="section__header<?php echo esc_attr($header_align_class); ?>">
                            <?php if ($eyebrow) : ?>
                                <span class="section__eyebrow"><?php echo esc_html($eyebrow); ?></span>
                            <?php endif; ?>
                            <?php if ($title) : ?>
                                <h2 class="section__title"><?php echo pp_render_heading_with_accent($title, $title_accent, 'section__title-accent'); ?></h2>
                            <?php endif; ?>
                            <?php if ($subheading) : ?>
                                <p class="section__subheading"><?php echo esc_html($subheading); ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <div class="section__content<?php echo esc_attr($content_marker_class); ?>">
                        <?php echo wp_kses_post($body); ?>
                    </div>
                    <?php echo $inline_items_html; ?>
                </div>

                <div class="section__panel">
                    <?php if ($panel_heading) : ?>
                        <h3 class="section__panel-heading"><?php echo esc_html($panel_heading); ?></h3>
                    <?php endif; ?>
                    <?php if ($panel_body) : ?>
                        <p class="section__panel-body"><?php echo esc_html($panel_body); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($panel_items)) : ?>
                        <ul class="section__panel-list<?php echo esc_attr($panel_list_marker_class); ?>">
                            <?php foreach ($panel_items as $panel_item) : ?>
                                <?php if (is_array($panel_item)) : ?>
                                    <?php
                                    // Paired-row entry (issue 334): label/value, not a bullet.
                                    // Optional per-row style routes through the SAME shared
                                    // engine + slots as grid's per-card style (issue 306); the
                                    // renderer only echoes item_eligible slots (issue 323,
                                    // actually enforced at render since #579).
                                    $row_label      = isset($panel_item['label']) && is_scalar($panel_item['label']) ? (string) $panel_item['label'] : '';
                                    $row_value      = isset($panel_item['value']) && is_scalar($panel_item['value']) ? (string) $panel_item['value'] : '';
                                    $row_style      = is_array($panel_item['style'] ?? null) ? $panel_item['style'] : [];
                                    $row_style_vars = pp_render_style_vars($row_style, 'section', true);
                                    $row_style_attr = $row_style_vars ? ' style="' . $row_style_vars . ';"' : '';
                                    ?>
                                    <li class="section__panel-row"<?php echo $row_style_attr; ?>>
                                        <span class="section__panel-row-label"><?php echo esc_html($row_label); ?></span>
                                        <span class="section__panel-row-value"><?php echo esc_html($row_value); ?></span>
                                    </li>
                                <?php else : ?>
                                    <li class="section__panel-item"><?php echo esc_html($panel_item); ?></li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if ($has_panel_cta) : ?>
                        <a href="<?php echo esc_url($panel_cta_url); ?>" class="section__panel-cta btn<?php echo esc_attr($panel_cta_variant_class); ?>">
                            <?php echo esc_html($panel_cta_text); ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>

        <?php else : ?>

            <div class="section__grid">
                <?php if ($layout === 'image-left') : ?>
                    <div class="section__image-wrap">
                        <?php echo pp_render_responsive_image($image_url, $image_alt, 'section__image', 'lazy', $image_id); ?>
                    </div>
                <?php endif; ?>

                <div class="section__body">
                    <?php if ($title || $eyebrow || $subheading) : ?>
                        <div class="section__header<?php echo esc_attr($header_align_class); ?>">
                            <?php if ($eyebrow) : ?>
                                <span class="section__eyebrow"><?php echo esc_html($eyebrow); ?></span>
                            <?php endif; ?>
                            <?php if ($title) : ?>
                                <h2 class="section__title"><?php echo pp_render_heading_with_accent($title, $title_accent, 'section__title-accent'); ?></h2>
                            <?php endif; ?>
                            <?php if ($subheading) : ?>
                                <p class="section__subheading"><?php echo esc_html($subheading); ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <div class="section__content<?php echo esc_attr($content_marker_class); ?>">
                        <?php echo wp_kses_post($body); ?>
                    </div>
                    <?php echo $inline_items_html; ?>
                </div>

                <?php if ($layout === 'image-right') : ?>
                    <div class="section__image-wrap">
                        <?php echo pp_render_responsive_image($image_url, $image_alt, 'section__image', 'lazy', $image_id); ?>
                    </div>
                <?php endif; ?>
            </div>

        <?php endif; ?>

    </div>
</section>

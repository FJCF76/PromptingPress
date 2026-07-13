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
$heading_align    = $props['heading_align']    ?? 'start';
$body             = $props['body']             ?? '';
$image_url        = $props['image_url']        ?? '';
$image_alt        = $props['image_alt']        ?? '';
$image_id         = (int) ($props['image_id']  ?? 0);
$layout           = $props['layout']           ?? 'text-only';
$theme            = $props['theme']            ?? 'default';
$background_image = $props['background_image'] ?? '';

// text-panel layout: right-hand content panel props (see schema.json).
$panel_heading      = $props['panel_heading']      ?? '';
$panel_body         = $props['panel_body']         ?? '';
$panel_items        = is_array($props['panel_items'] ?? null) ? $props['panel_items'] : [];
$panel_cta_text     = $props['panel_cta_text']     ?? '';
$panel_cta_url      = $props['panel_cta_url']      ?? '';
$panel_cta_variant  = $props['panel_cta_variant']  ?? 'primary';
$panel_items_marker = $props['panel_items_marker'] ?? 'disc';
$body_marker        = $props['body_marker']        ?? 'disc';

// Only string, non-empty list entries render (mirrors grid bullets).
$panel_items = array_values(array_filter(
    $panel_items,
    static fn ($item) => is_string($item) && $item !== ''
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

$allowed_themes = ['default', 'dark', 'inverted'];
if (!in_array($theme, $allowed_themes, true)) {
    $theme = 'default';
}

$allowed_heading_aligns = ['start', 'center'];
if (!in_array($heading_align, $allowed_heading_aligns, true)) {
    $heading_align = 'start';
}
$header_align_class = $heading_align === 'center' ? ' section__header--center' : '';

$theme_class = $theme !== 'default' ? ' pp-section--' . $theme : '';
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
                                <li class="section__panel-item"><?php echo esc_html($panel_item); ?></li>
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

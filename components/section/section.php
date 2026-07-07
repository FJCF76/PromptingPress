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

$allowed_layouts = ['text-only', 'image-left', 'image-right', 'centered'];
if (!in_array($layout, $allowed_layouts, true)) {
    $layout = 'text-only';
}

// Centered layout suppresses image regardless.
// For image layouts, fall back to text-only if no image URL.
if ($layout !== 'centered' && !$image_url) {
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
                <div class="section__content">
                    <?php echo wp_kses_post($body); ?>
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
                    <div class="section__content">
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

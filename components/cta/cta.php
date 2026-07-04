<?php
/**
 * components/cta/cta.php
 *
 * Call-to-action block. Two variants: full-width (centered) and inline (flex row).
 * Props: see schema.json
 *
 * @var array $props
 */

$id               = $props['id']               ?? '';
$title            = $props['title']            ?? '';
$text             = $props['text']             ?? '';
$button_text      = $props['button_text']      ?? 'Get Started';
$button_url       = $props['button_url']       ?? '#';
$variant          = $props['variant']          ?? 'full-width';
$theme            = $props['theme']            ?? 'default';
$background_image = $props['background_image'] ?? '';
$button_variant   = $props['button_variant']   ?? 'primary';

$allowed_variants = ['full-width', 'inline'];
if (!in_array($variant, $allowed_variants, true)) {
    $variant = 'full-width';
}

$allowed_button_variants = ['primary', 'secondary', 'outline', 'ghost'];
if (!in_array($button_variant, $allowed_button_variants, true)) {
    $button_variant = 'primary';
}
// primary is the bare .btn; other variants add a .btn--{variant} modifier.
$button_variant_class = $button_variant !== 'primary' ? ' btn--' . $button_variant : '';

$allowed_themes = ['default', 'dark', 'inverted'];
if (!in_array($theme, $allowed_themes, true)) {
    $theme = 'default';
}

$theme_class    = $theme !== 'default' ? ' cta--' . $theme : '';
$bg_image_class = $background_image ? ' cta--has-bg-image' : '';

// Style slot overrides (per-instance visual customization).
$slot_style = pp_render_style_vars($props['__pp_style'] ?? [], 'cta');

$inline_styles = [];
if ($slot_style) {
    $inline_styles[] = $slot_style;
}
if ($background_image) {
    $inline_styles[] = 'background-image:url(' . pp_esc_image_src($background_image) . ')';
}
$style_attr = $inline_styles ? ' style="' . implode('; ', $inline_styles) . ';"' : '';

?>
<section<?php echo $id ? ' id="' . esc_attr($id) . '"' : ''; ?> class="cta cta--<?php echo esc_attr($variant); ?><?php echo esc_attr($theme_class); ?><?php echo esc_attr($bg_image_class); ?>" data-pp-component="cta"<?php echo $style_attr; ?>>
    <?php if ($background_image) : ?>
        <div class="cta__overlay" aria-hidden="true"></div>
    <?php endif; ?>
    <div class="container">
        <div class="cta__inner">
            <div class="cta__text">
                <?php if ($title) : ?>
                    <h2 class="cta__title"><?php echo esc_html($title); ?></h2>
                <?php endif; ?>

                <?php if ($text) : ?>
                    <p class="cta__body"><?php echo esc_html($text); ?></p>
                <?php endif; ?>
            </div>

            <a href="<?php echo esc_url($button_url); ?>" class="cta__button btn<?php echo esc_attr($button_variant_class); ?>">
                <?php echo esc_html($button_text); ?>
            </a>
        </div>
    </div>
</section>

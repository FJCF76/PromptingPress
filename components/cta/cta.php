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

$allowed_variants = ['full-width', 'inline'];
if (!in_array($variant, $allowed_variants, true)) {
    $variant = 'full-width';
}

$allowed_themes = ['default', 'dark', 'inverted'];
if (!in_array($theme, $allowed_themes, true)) {
    $theme = 'default';
}

$theme_class    = $theme !== 'default' ? ' cta--' . $theme : '';
$bg_image_class = $background_image ? ' cta--has-bg-image' : '';
$bg_image_style = $background_image
    ? sprintf(' style="background-image:url(%s);"', esc_url($background_image))
    : '';
?>
<section<?php echo $id ? ' id="' . esc_attr($id) . '"' : ''; ?> class="cta cta--<?php echo esc_attr($variant); ?><?php echo esc_attr($theme_class); ?><?php echo esc_attr($bg_image_class); ?>"<?php echo $bg_image_style; ?>>
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

            <a href="<?php echo esc_url($button_url); ?>" class="cta__button btn">
                <?php echo esc_html($button_text); ?>
            </a>
        </div>
    </div>
</section>

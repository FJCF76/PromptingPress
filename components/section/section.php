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
$body             = $props['body']             ?? '';
$image_url        = $props['image_url']        ?? '';
$image_alt        = $props['image_alt']        ?? '';
$layout           = $props['layout']           ?? 'text-only';
$variant          = $props['variant']          ?? 'default';
$background_image = $props['background_image'] ?? '';

$allowed_layouts = ['text-only', 'image-left', 'image-right'];
if (!in_array($layout, $allowed_layouts, true)) {
    $layout = 'text-only';
}

// If no image URL, fall back to text-only regardless of requested layout.
if (!$image_url) {
    $layout = 'text-only';
}

$allowed_variants = ['default', 'dark', 'inverted'];
if (!in_array($variant, $allowed_variants, true)) {
    $variant = 'default';
}

$variant_class = $variant !== 'default' ? ' pp-section--' . $variant : '';
$bg_image_class = $background_image ? ' section--has-bg-image' : '';
$bg_image_style = $background_image
    ? sprintf(' style="background-image:url(%s);"', esc_url($background_image))
    : '';
?>
<section<?php echo $id ? ' id="' . esc_attr($id) . '"' : ''; ?> class="section section--<?php echo esc_attr($layout); ?><?php echo esc_attr($variant_class); ?><?php echo esc_attr($bg_image_class); ?>"<?php echo $bg_image_style; ?>>
    <?php if ($background_image) : ?>
        <div class="section__overlay" aria-hidden="true"></div>
    <?php endif; ?>
    <div class="container">

        <?php if ($layout === 'text-only') : ?>

            <div class="section__body">
                <?php if ($title) : ?>
                    <h2 class="section__title"><?php echo esc_html($title); ?></h2>
                <?php endif; ?>
                <div class="section__content">
                    <?php echo wp_kses_post($body); ?>
                </div>
            </div>

        <?php else : ?>

            <div class="section__grid">
                <?php if ($layout === 'image-left') : ?>
                    <div class="section__image-wrap">
                        <img
                            src="<?php echo esc_url($image_url); ?>"
                            alt="<?php echo esc_attr($image_alt); ?>"
                            class="section__image"
                            loading="lazy"
                        >
                    </div>
                <?php endif; ?>

                <div class="section__body">
                    <?php if ($title) : ?>
                        <h2 class="section__title"><?php echo esc_html($title); ?></h2>
                    <?php endif; ?>
                    <div class="section__content">
                        <?php echo wp_kses_post($body); ?>
                    </div>
                </div>

                <?php if ($layout === 'image-right') : ?>
                    <div class="section__image-wrap">
                        <img
                            src="<?php echo esc_url($image_url); ?>"
                            alt="<?php echo esc_attr($image_alt); ?>"
                            class="section__image"
                            loading="lazy"
                        >
                    </div>
                <?php endif; ?>
            </div>

        <?php endif; ?>

    </div>
</section>

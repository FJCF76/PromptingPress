<?php
/**
 * components/hero/hero.php
 *
 * Props: see schema.json
 *
 * @var array $props
 */

$id        = $props['id']        ?? '';
$title     = $props['title']    ?? 'Default Title';
$subtitle  = $props['subtitle'] ?? '';
$cta_text  = $props['cta_text'] ?? '';
$cta_url   = $props['cta_url']  ?? '#';
$cta2_text = $props['cta2_text'] ?? '';
$cta2_url  = $props['cta2_url']  ?? '#';
$variant   = $props['variant']   ?? 'centered';
$image_url = $props['image_url'] ?? '';
$image_alt = $props['image_alt'] ?? '';

// Validate variant.
$allowed_variants = ['left', 'centered', 'split', 'cover'];
if (!in_array($variant, $allowed_variants, true)) {
    $variant = 'centered';
}

// Cover variant: image becomes a background-image with overlay.
$cover_style = '';
if ($variant === 'cover' && $image_url) {
    $cover_style = sprintf(
        ' style="background-image:url(%s);"',
        esc_url($image_url)
    );
}
?>
<section<?php echo $id ? ' id="' . esc_attr($id) . '"' : ''; ?> class="hero hero--<?php echo esc_attr($variant); ?>"<?php echo $cover_style; ?>>
    <?php if ($variant === 'cover') : ?>
        <div class="hero__overlay" aria-hidden="true"></div>
    <?php endif; ?>
    <div class="container">
        <div class="hero__inner">
            <div class="hero__content">
                <h1 class="hero__title"><?php echo esc_html($title); ?></h1>

                <?php if ($subtitle) : ?>
                    <p class="hero__subtitle"><?php echo esc_html($subtitle); ?></p>
                <?php endif; ?>

                <?php if ($cta_text) : ?>
                    <div class="hero__cta-group">
                        <a href="<?php echo esc_url($cta_url); ?>" class="hero__cta btn">
                            <?php echo esc_html($cta_text); ?>
                        </a>
                        <?php if ($cta2_text) : ?>
                            <a href="<?php echo esc_url($cta2_url); ?>" class="hero__cta btn btn--outline">
                                <?php echo esc_html($cta2_text); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($variant === 'split' && $image_url) : ?>
                <div class="hero__image-wrap">
                    <img
                        src="<?php echo esc_url($image_url); ?>"
                        alt="<?php echo esc_attr($image_alt); ?>"
                        class="hero__image"
                        loading="eager"
                    >
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

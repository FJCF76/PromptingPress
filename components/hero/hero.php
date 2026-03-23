<?php
/**
 * components/hero/hero.php
 *
 * Props: see schema.json
 *
 * @var array $props
 */

$title    = $props['title']    ?? 'Default Title';
$subtitle = $props['subtitle'] ?? '';
$cta_text = $props['cta_text'] ?? '';
$cta_url  = $props['cta_url']  ?? '#';
$variant  = $props['variant']  ?? 'centered';
$image_url = $props['image_url'] ?? '';

// Validate variant.
$allowed_variants = ['left', 'centered', 'split'];
if (!in_array($variant, $allowed_variants, true)) {
    $variant = 'centered';
}
?>
<section class="hero hero--<?php echo esc_attr($variant); ?>">
    <div class="container">
        <div class="hero__inner">
            <div class="hero__content">
                <h1 class="hero__title"><?php echo esc_html($title); ?></h1>

                <?php if ($subtitle) : ?>
                    <p class="hero__subtitle"><?php echo esc_html($subtitle); ?></p>
                <?php endif; ?>

                <?php if ($cta_text) : ?>
                    <a href="<?php echo esc_url($cta_url); ?>" class="hero__cta btn">
                        <?php echo esc_html($cta_text); ?>
                    </a>
                <?php endif; ?>
            </div>

            <?php if ($variant === 'split' && $image_url) : ?>
                <div class="hero__image-wrap">
                    <img
                        src="<?php echo esc_url($image_url); ?>"
                        alt=""
                        class="hero__image"
                        loading="eager"
                    >
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

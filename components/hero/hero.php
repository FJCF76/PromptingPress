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
$spacing         = $props['spacing']         ?? 'default';
$width           = $props['width']           ?? 'default';
$split_ratio     = $props['split_ratio']     ?? '50-50';
$vertical_align  = $props['vertical_align']  ?? 'center';
$proof           = $props['proof']           ?? '';

// Validate variant.
$allowed_variants = ['left', 'centered', 'split', 'cover'];
if (!in_array($variant, $allowed_variants, true)) {
    $variant = 'centered';
}

// Validate spacing/width props.
$allowed_spacings = ['default', 'compact', 'spacious'];
if (!in_array($spacing, $allowed_spacings, true)) {
    $spacing = 'default';
}
$allowed_widths = ['default', 'narrow', 'full'];
if (!in_array($width, $allowed_widths, true)) {
    $width = 'default';
}

// Validate new composition props.
$allowed_split_ratios = ['50-50', '60-40', '40-60'];
if (!in_array($split_ratio, $allowed_split_ratios, true)) {
    $split_ratio = '50-50';
}
$allowed_vertical_aligns = ['top', 'center', 'bottom'];
if (!in_array($vertical_align, $allowed_vertical_aligns, true)) {
    $vertical_align = 'center';
}

$spacing_attr        = $spacing !== 'default' ? ' data-pp-spacing="' . esc_attr($spacing) . '"' : '';
$width_attr          = $width !== 'default' ? ' data-pp-width="' . esc_attr($width) . '"' : '';
$split_ratio_attr    = ($variant === 'split' && $split_ratio !== '50-50') ? ' data-pp-split-ratio="' . esc_attr($split_ratio) . '"' : '';
$vertical_align_attr = (in_array($variant, ['cover', 'split'], true) && $vertical_align !== 'center') ? ' data-pp-vertical-align="' . esc_attr($vertical_align) . '"' : '';
$proof_markup        = trim((string) $proof);

// Cover variant: image becomes a background-image with overlay.
$cover_style = '';
if ($variant === 'cover' && $image_url) {
    $cover_style = sprintf(
        ' style="background-image:url(%s);"',
        esc_url($image_url)
    );
}
?>
<section<?php echo $id ? ' id="' . esc_attr($id) . '"' : ''; ?> class="hero hero--<?php echo esc_attr($variant); ?>" data-pp-component="hero"<?php echo $spacing_attr; ?><?php echo $width_attr; ?><?php echo $split_ratio_attr; ?><?php echo $vertical_align_attr; ?><?php echo $cover_style; ?>>
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

                <?php if ($proof_markup && $variant !== 'split') : ?>
                    <div class="hero__proof"><?php echo wp_kses_post($proof_markup); ?></div>
                <?php endif; ?>
            </div>

            <?php if ($variant === 'split' && $proof_markup) : ?>
                <div class="hero__surface" aria-label="Product workflow surface">
                    <?php echo wp_kses_post($proof_markup); ?>
                </div>
            <?php elseif ($variant === 'split' && $image_url) : ?>
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

<?php
/**
 * components/hero/hero.php
 *
 * Props: see schema.json
 *
 * @var array $props
 */

$id           = $props['id']           ?? '';
$title        = $props['title']       ?? 'Default Title';
$title_accent = $props['title_accent'] ?? '';
$eyebrow   = $props['eyebrow']  ?? '';
$subheading  = $props['subheading'] ?? '';
$button_text  = $props['button_text'] ?? '';
$button_url   = $props['button_url']  ?? '#';
$button2_text = $props['button2_text'] ?? '';
$button2_url  = $props['button2_url']  ?? '#';
$button_variant  = $props['button_variant']  ?? 'primary';
$button2_variant = $props['button2_variant'] ?? 'outline';
$layout    = $props['layout']    ?? 'centered';
$image_url = $props['image_url'] ?? '';
$image_alt = $props['image_alt'] ?? '';
// is_numeric() BEFORE the (int) cast (#614, extended to the top-level readers at
// gate 7A). `(int)` is not a rejection: `(int) ['attachment_id' => 42]` and
// `(int) true` both evaluate to 1, so a bare cast resolves attachment ID 1 —
// usually the site's first upload — and discards the author's image_url. The write
// path rejects that shape (image_id declares `type: number`, gated by the #507
// pass), but the validator gates WRITES: restore_composition reports without
// blocking (#233) and a composition authored before the rule still reaches this
// line. Sharper here than anywhere else, because $has_split_media below reads
// `$image_id > 0`, so the coercion would also flip the band's LAYOUT to split.
$raw_image_id = $props['image_id'] ?? 0;
$image_id     = is_numeric($raw_image_id) ? (int) $raw_image_id : 0;
$spacing         = $props['spacing']         ?? 'default';
$width           = $props['width']           ?? 'default';
$split_ratio     = $props['split_ratio']     ?? '50-50';
$vertical_align  = $props['vertical_align']  ?? 'center';
$proof           = $props['proof']           ?? '';

// Validate layout.
$allowed_layouts = ['left', 'centered', 'split', 'cover'];
if (!in_array($layout, $allowed_layouts, true)) {
    $layout = 'centered';
}

// Validate CTA button variants (same shared .btn--* primitive as components/cta/cta.php).
$allowed_button_variants = ['primary', 'secondary', 'outline', 'ghost'];
if (!in_array($button_variant, $allowed_button_variants, true)) {
    $button_variant = 'primary';
}
if (!in_array($button2_variant, $allowed_button_variants, true)) {
    $button2_variant = 'outline';
}
// primary is the bare .btn; other variants add a .btn--{variant} modifier.
$cta_variant_class  = $button_variant !== 'primary' ? ' btn--' . $button_variant : '';
$cta2_variant_class = $button2_variant !== 'primary' ? ' btn--' . $button2_variant : '';

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
// 'stretch' is split-oriented (#477): the media item fills the split row's
// height so one asset balances any headline length. It is accepted for the
// shared enum; only the split layout gives it CSS meaning (see components.css).
// On cover it renders as 'center' (no rule), which is harmless.
$allowed_vertical_aligns = ['top', 'center', 'bottom', 'stretch'];
if (!in_array($vertical_align, $allowed_vertical_aligns, true)) {
    $vertical_align = 'center';
}

$proof_markup        = trim((string) $proof);

// Graceful degradation (#440): the "split" layout only makes sense when the
// second column has something to show — an image or the proof surface.
// pp_render_responsive_image() resolves an attachment from image_id even when
// image_url is empty, so a resolvable image_id counts as media here (and the
// image branch below renders it). When a split hero has neither image nor
// proof, the two-column grid would reserve an empty half-band, so fall back to
// the single-column "left" layout: text renders at full content width. This is
// render-time only — the stored layout prop is unchanged (no schema change).
// !empty($image_url) matches the original image-branch truthy gate byte-for-byte
// (an empty string and the falsy "0" both count as "no image", as before).
$has_split_media  = (!empty($image_url) || $image_id > 0);
$effective_layout = ($layout === 'split' && !$has_split_media && $proof_markup === '')
    ? 'left'
    : $layout;

$spacing_attr        = $spacing !== 'default' ? ' data-pp-spacing="' . esc_attr($spacing) . '"' : '';
$width_attr          = $width !== 'default' ? ' data-pp-width="' . esc_attr($width) . '"' : '';
$split_ratio_attr    = ($effective_layout === 'split' && $split_ratio !== '50-50') ? ' data-pp-split-ratio="' . esc_attr($split_ratio) . '"' : '';
$vertical_align_attr = (in_array($effective_layout, ['cover', 'split'], true) && $vertical_align !== 'center') ? ' data-pp-vertical-align="' . esc_attr($vertical_align) . '"' : '';

// Style slot overrides (per-instance visual customization).
$slot_style = pp_render_style_vars($props['__pp_style'] ?? [], 'hero');

// Build inline style attribute: merge slot vars + cover background-image.
$inline_styles = [];
if ($slot_style) {
    $inline_styles[] = $slot_style;
}
if ($layout === 'cover' && $image_url) {
    $inline_styles[] = 'background-image:url(' . pp_esc_image_src($image_url) . ')';
}
$style_attr = $inline_styles ? ' style="' . implode('; ', $inline_styles) . ';"' : '';
?>
<section<?php echo $id ? ' id="' . esc_attr($id) . '"' : ''; ?> class="hero hero--<?php echo esc_attr($effective_layout); ?>" data-pp-component="hero"<?php echo $spacing_attr; ?><?php echo $width_attr; ?><?php echo $split_ratio_attr; ?><?php echo $vertical_align_attr; ?><?php echo $style_attr; ?>>
    <?php if ($layout === 'cover') : ?>
        <div class="hero__overlay" aria-hidden="true"></div>
    <?php endif; ?>
    <div class="container">
        <div class="hero__inner">
            <div class="hero__content">
                <?php if ($eyebrow) : ?>
                    <span class="hero__eyebrow"><?php echo esc_html($eyebrow); ?></span>
                <?php endif; ?>
                <h1 class="hero__title"><?php echo pp_render_heading_with_accent($title, $title_accent, 'hero__title-accent'); ?></h1>

                <?php if ($subheading) : ?>
                    <p class="hero__subtitle"><?php echo esc_html($subheading); ?></p>
                <?php endif; ?>

                <?php if ($button_text) : ?>
                    <div class="hero__cta-group">
                        <a href="<?php echo esc_url($button_url); ?>" class="hero__cta btn<?php echo esc_attr($cta_variant_class); ?>">
                            <?php echo esc_html($button_text); ?>
                        </a>
                        <?php if ($button2_text) : ?>
                            <a href="<?php echo esc_url($button2_url); ?>" class="hero__cta hero__cta--secondary btn<?php echo esc_attr($cta2_variant_class); ?>">
                                <?php echo esc_html($button2_text); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($proof_markup && $effective_layout !== 'split') : ?>
                    <div class="hero__proof"><?php echo wp_kses_post($proof_markup); ?></div>
                <?php endif; ?>
            </div>

            <?php if ($effective_layout === 'split' && $proof_markup) : ?>
                <div class="hero__surface" aria-label="Product workflow surface">
                    <?php echo wp_kses_post($proof_markup); ?>
                </div>
            <?php elseif ($effective_layout === 'split' && $has_split_media) : ?>
                <div class="hero__image-wrap">
                    <?php echo pp_render_responsive_image($image_url, $image_alt, 'hero__image', 'eager', $image_id); ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

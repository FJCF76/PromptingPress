<?php
/**
 * components/cta/cta.php
 *
 * Call-to-action block. Two layouts: full-width (centered) and inline (flex row).
 * Props: see schema.json
 *
 * @var array $props
 */

$id               = $props['id']               ?? '';
$title            = $props['title']            ?? '';
$title_accent     = $props['title_accent']     ?? '';
$eyebrow          = $props['eyebrow']          ?? '';
$text             = $props['text']             ?? '';
$button_text      = $props['button_text']      ?? 'Get Started';
$button_url       = $props['button_url']       ?? '#';
$button2_text     = $props['button2_text']     ?? '';
$button2_url      = $props['button2_url']      ?? '#';
$layout           = $props['layout']           ?? 'full-width';
$theme            = $props['theme']            ?? 'default';
$background_image = $props['background_image'] ?? '';
$button_variant   = $props['button_variant']   ?? 'primary';
$button2_variant  = $props['button2_variant']  ?? 'outline';

$allowed_layouts = ['full-width', 'inline'];
if (!in_array($layout, $allowed_layouts, true)) {
    $layout = 'full-width';
}

// Shared 4-variant button primitive (same list as components/hero/hero.php).
$allowed_button_variants = ['primary', 'secondary', 'outline', 'ghost'];
if (!in_array($button_variant, $allowed_button_variants, true)) {
    $button_variant = 'primary';
}
if (!in_array($button2_variant, $allowed_button_variants, true)) {
    $button2_variant = 'outline';
}
// primary is the bare .btn; other variants add a .btn--{variant} modifier.
$button_variant_class  = $button_variant !== 'primary' ? ' btn--' . $button_variant : '';
$button2_variant_class = $button2_variant !== 'primary' ? ' btn--' . $button2_variant : '';

// Optional second button (issue 474), the hero's cta2 pattern scoped to cta.
// The pair needs a flex row of its own: .cta__inner is a flex COLUMN on
// full-width (two bare sibling anchors would stack, separated by the full
// --cta-inner-gap) and a `space-between` ROW on inline (they would be flung to
// opposite ends of the band). Both were confirmed by rendering the no-wrapper
// alternative. The wrapper is therefore emitted ONLY when a second button
// exists, so a single-button cta keeps today's markup byte-for-byte.
// is_scalar guard: the write path rejects a non-scalar string prop, but
// restore_composition deliberately never blocks on validation (#233), so an array or
// boolean CAN reach the renderer from a legacy/raw-written history snapshot. A bare
// `!== ''` would treat `false` as a label and emit a button with an empty accessible
// name, and an array would render the literal text "Array" plus a PHP warning. Casting
// after the guard keeps a legitimate "0" label working, which a truthy check would drop.
$has_button2 = is_scalar($button2_text) && (string) $button2_text !== '';

// theme coercion + the deprecated 'dark' -> 'muted' alias live in pp_theme_class() (#442).
$theme_class    = pp_theme_class($theme, 'cta');
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
<section<?php echo $id ? ' id="' . esc_attr($id) . '"' : ''; ?> class="cta cta--<?php echo esc_attr($layout); ?><?php echo esc_attr($theme_class); ?><?php echo esc_attr($bg_image_class); ?>" data-pp-component="cta"<?php echo $style_attr; ?>>
    <?php if ($background_image) : ?>
        <div class="cta__overlay" aria-hidden="true"></div>
    <?php endif; ?>
    <div class="container">
        <div class="cta__inner">
            <?php // Skip the text block entirely when there is no eyebrow/title/text: a
                  // title-less CTA is the standalone-button pattern (issue 294), so it must
                  // render just the button row — no empty heading and no stray flex gap. ?>
            <?php if ($eyebrow || $title || $text) : ?>
            <div class="cta__text">
                <?php if ($eyebrow) : ?>
                    <span class="cta__eyebrow"><?php echo esc_html($eyebrow); ?></span>
                <?php endif; ?>
                <?php if ($title) : ?>
                    <h2 class="cta__title"><?php echo pp_render_heading_with_accent($title, $title_accent, 'cta__title-accent'); ?></h2>
                <?php endif; ?>

                <?php if ($text) : ?>
                    <?php // Inline-HTML supporting-text prop (#439): a link + light
                          // emphasis (a/strong/em/br) is allowed and sanitized via
                          // pp_kses_inline; block/script tags are stripped. ?>
                    <p class="cta__body"><?php echo pp_kses_inline($text); ?></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

<?php // The wrapper control tags below start at column 0 ON PURPOSE. PHP emits
      // everything outside its tags verbatim, so an INDENTED control tag still
      // prints its own leading spaces even when the branch is false. At column 0
      // there is no leading whitespace to print, and the closing tag eats the
      // trailing newline, so an unset second button adds ZERO bytes and the
      // single-button render stays byte-for-byte identical to pre-474 output
      // (pinned by ComponentPropsTest::testCtaWithoutButton2IsByteIdenticalToBefore). ?>
<?php if ($has_button2) : ?>
            <div class="cta__buttons">
<?php endif; ?>
            <a href="<?php echo esc_url($button_url); ?>" class="cta__button btn<?php echo esc_attr($button_variant_class); ?>">
                <?php echo esc_html($button_text); ?>
            </a>
<?php if ($has_button2) : ?>
                <a href="<?php echo esc_url($button2_url); ?>" class="cta__button cta__button--secondary btn<?php echo esc_attr($button2_variant_class); ?>">
                    <?php echo esc_html($button2_text); ?>
                </a>
            </div>
<?php endif; ?>
        </div>
    </div>
</section>

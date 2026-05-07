<?php
/**
 * components/embed/embed.php
 *
 * A generic content embed block. Renders an optional heading and passes
 * the content string through do_shortcode() — use for WP shortcodes
 * (contact forms, Gravity Forms, etc.) or pre-rendered HTML blocks that
 * belong to WP plugins rather than to the PromptingPress composition model.
 *
 * The content prop is the only way to introduce arbitrary HTML into a
 * composition. It is intentional and explicit — not a workaround.
 * Props: see schema.json
 *
 * @var array $props
 */

$id      = $props['id']      ?? '';
$title   = $props['title']   ?? '';
$content = $props['content'] ?? '';
$variant = $props['variant'] ?? 'default';
$spacing = $props['spacing'] ?? 'default';
$width   = $props['width']   ?? 'default';

$allowed_variants = ['default', 'dark', 'inverted'];
if (!in_array($variant, $allowed_variants, true)) {
    $variant = 'default';
}

$variant_class = $variant !== 'default' ? ' embed--' . $variant : '';

$allowed_spacings = ['default', 'compact', 'spacious'];
if (!in_array($spacing, $allowed_spacings, true)) {
    $spacing = 'default';
}
$allowed_widths = ['default', 'narrow', 'full'];
if (!in_array($width, $allowed_widths, true)) {
    $width = 'default';
}

$spacing_attr = $spacing !== 'default' ? ' data-pp-spacing="' . esc_attr($spacing) . '"' : '';
$width_attr   = $width !== 'default' ? ' data-pp-width="' . esc_attr($width) . '"' : '';
?>
<section<?php echo $id ? ' id="' . esc_attr($id) . '"' : ''; ?> class="embed<?php echo esc_attr($variant_class); ?>" data-pp-component="embed"<?php echo $spacing_attr; ?><?php echo $width_attr; ?>>
    <div class="container">

        <?php if ($title) : ?>
            <h2 class="embed__heading"><?php echo esc_html($title); ?></h2>
        <?php endif; ?>

        <?php if ($content) : ?>
            <div class="embed__content">
                <?php echo do_shortcode(wp_kses_post($content)); ?>
            </div>
        <?php endif; ?>

    </div>
</section>

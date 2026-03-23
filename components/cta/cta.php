<?php
/**
 * components/cta/cta.php
 *
 * Call-to-action block. Two variants: full-width (centered) and inline (flex row).
 * Props: see schema.json
 *
 * @var array $props
 */

$title       = $props['title']       ?? '';
$text        = $props['text']        ?? '';
$button_text = $props['button_text'] ?? 'Get Started';
$button_url  = $props['button_url']  ?? '#';
$variant     = $props['variant']     ?? 'full-width';

$allowed_variants = ['full-width', 'inline'];
if (!in_array($variant, $allowed_variants, true)) {
    $variant = 'full-width';
}
?>
<section class="cta cta--<?php echo esc_attr($variant); ?>">
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

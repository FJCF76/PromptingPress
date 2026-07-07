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
$theme = $props['theme'] ?? 'default';

$allowed_themes = ['default', 'dark', 'inverted'];
if (!in_array($theme, $allowed_themes, true)) {
    $theme = 'default';
}

$theme_class = $theme !== 'default' ? ' embed--' . $theme : '';

?>
<section<?php echo $id ? ' id="' . esc_attr($id) . '"' : ''; ?> class="embed<?php echo esc_attr($theme_class); ?>" data-pp-component="embed">
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

<?php
/**
 * components/faq/faq.php
 *
 * FAQ accordion using native HTML details/summary — zero JS required.
 * Props: see schema.json
 *
 * @var array $props
 */

$id           = $props['id']           ?? '';
$title        = $props['title']        ?? 'Frequently Asked Questions';
$title_accent = $props['title_accent'] ?? '';
$eyebrow      = $props['eyebrow']      ?? '';
$theme        = $props['theme']        ?? 'default';
$items = $props['items'] ?? [];

// Tone variant. Clamp to the known set so an unknown value renders as the
// default surface rather than emitting an unstyled `faq--<garbage>` class
// (mirrors section.php / grid.php — composition validation does not check
// enum values, so the render is the actual contract).
$allowed_themes = ['default', 'dark', 'inverted'];
if (!in_array($theme, $allowed_themes, true)) {
    $theme = 'default';
}
$theme_class = $theme !== 'default' ? ' faq--' . $theme : '';

// Style slot overrides (per-instance visual customization).
$slot_style = pp_render_style_vars($props['__pp_style'] ?? [], 'faq');
$style_attr = $slot_style ? ' style="' . $slot_style . ';"' : '';
?>
<section<?php echo $id ? ' id="' . esc_attr($id) . '"' : ''; ?> class="faq<?php echo esc_attr($theme_class); ?>" data-pp-component="faq"<?php echo $style_attr; ?>>
    <div class="container">

        <?php if ($eyebrow) : ?>
            <span class="faq__eyebrow"><?php echo esc_html($eyebrow); ?></span>
        <?php endif; ?>

        <?php if ($title) : ?>
            <h2 class="faq__heading"><?php echo pp_render_heading_with_accent($title, $title_accent, 'faq__heading-accent'); ?></h2>
        <?php endif; ?>

        <?php if (!empty($items)) : ?>
            <div class="faq__list">
                <?php foreach ($items as $item) :
                    $question = $item['question'] ?? '';
                    $answer   = $item['answer']   ?? '';
                    if (!$question) continue;
                ?>
                    <details class="faq__item">
                        <summary class="faq__question">
                            <?php echo esc_html($question); ?>
                        </summary>
                        <div class="faq__answer">
                            <?php echo wp_kses_post($answer); ?>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php else : ?>
            <p class="faq__empty text-muted">No questions yet.</p>
        <?php endif; ?>

    </div>

    <?php
    // FAQPage JSON-LD lives INSIDE the <section>, not after it (#432). A
    // <script> is metadata content valid anywhere in the body flow, and Google
    // reads ld+json from anywhere in the DOM, so SEO is unaffected. Emitting it
    // as a trailing SIBLING of </section> made the script the previous element
    // sibling of the next band, so `main > [data-pp-component] + .band` missed
    // that band and it fell back to its own (larger) top padding. Keeping the
    // script inside the section restores the faq as the following band's
    // immediate component sibling.
    echo pp_render_faq_schema($items);
    ?>
</section>

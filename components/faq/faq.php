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
// #706: guard BOTH raw-value text arguments of pp_render_heading_with_accent()
// (`string $title`, `string $accent`) before they reach the call below. A non-empty
// array is truthy, so the `if ($title)` gate passes on one and the typed call raises a
// TypeError that no caller catches — the whole PUBLIC PAGE 500s. Argument #2 fatals the
// same way on its own, so both props are guarded, not just the title. Guarded at the
// READ because the gate that decides whether the heading renders at all sits upstream of
// the call, so a guarded-away value renders the band with no heading rather than an
// empty one. is_scalar + (string), NOT is_string: only non-scalars ever fataled
// (coercive mode), and the write path stores a scalar title raw (#707), so is_string()
// would silently drop an accepted value. Full reasoning in components/hero/hero.php.
// Local specifics: faq is the OTHER component with a non-empty `??` default, so note
// what the else-branch is doing — it is '' and NOT 'Frequently Asked Questions'. The
// default fires only when the key is ABSENT; a stored non-scalar is PRESENT, and
// degrading it into that placeholder would paint invented content onto a visitor's page.
// The questions and answers below still render, as does their JSON-LD schema.
$raw_title        = $props['title']        ?? 'Frequently Asked Questions';
$title            = is_scalar($raw_title) ? (string) $raw_title : '';
$raw_title_accent = $props['title_accent'] ?? '';
$title_accent     = is_scalar($raw_title_accent) ? (string) $raw_title_accent : '';
$eyebrow      = $props['eyebrow']      ?? '';
$theme        = $props['theme']        ?? 'default';
// NOT guarded by #708, and that is deliberate rather than an oversight. #708 guards
// `items` in grid, where it reaches count(); here it reaches a DIFFERENT typed call,
// pp_render_faq_schema(array $items) at the bottom of this file, and the family's
// admitting criterion is the same typed call, not the same prop. So a stored non-array
// `items` STILL 500s the whole public page from this component — and on falsy shapes
// ('', 0, false) that grid survives, because that call sits OUTSIDE the `!empty($items)`
// gate below. Filed as #739 with the measured shapes; fix it there, not by widening a
// ruling scoped to two named boundaries. The style guard this file did receive (above)
// covers `__pp_style` only.
$items = $props['items'] ?? [];

// Tone variant. Clamp to the known set so an unknown value renders as the
// default surface rather than emitting an unstyled `faq--<garbage>` class
// (mirrors section.php / grid.php — composition validation does not check
// enum values, so the render is the actual contract).
// theme coercion lives in pp_theme_class(); `muted` emits the legacy `--dark` class (#570 DG-4).
$theme_class = pp_theme_class($theme, 'faq');

// Style slot overrides (per-instance visual customization).
// #708: guard the raw `__pp_style` map before it reaches the typed
// pp_render_style_vars(array $style, ...). A stored non-array raises a TypeError that
// no caller catches, so the whole PUBLIC PAGE 500s. It arrives as `__pp_style` stored
// INSIDE props: all four top-level `style` promotions are already is_array guarded, so
// this read is the only reachable boundary and the only place a guard can help.
// is_array, NOT is_scalar — an array IS the contract at this parameter. Degrades to no
// inline custom properties and no `style` attribute at all, byte-identical to a band
// that stored no style. Full reasoning in components/grid/grid.php.
$raw_style = $props['__pp_style'] ?? null;
$style     = is_array($raw_style) ? $raw_style : [];
$slot_style = pp_render_style_vars($style, 'faq');
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

<?php
/**
 * tests/fixtures/components/ppfixture/ppfixture.php
 *
 * TEST FIXTURE ONLY. This file exists so pp_get_registered_components() will register
 * the fixture at all: it skips any components/<name>/ directory that has no
 * <name>.php beside its schema.json (lib/admin.php). It is never loaded by the theme.
 *
 * The markup mirrors a shipped band closely enough for the render-guard suites to
 * exercise the real code paths - it emits its theme class through pp_theme_class()
 * (#570 DG-4) and runs #705's raw-value guard before the typed escaper. A fixture
 * that faked those would prove nothing about the engine.
 *
 * The `__pp_style` render and its #708 is_array guard were deleted at #1101 with
 * pp_render_style_vars(): no component reads a stored slot map any more, so the
 * degradation that guard prevented has no route to a renderer.
 *
 * @var array $props
 */

$id    = $props['id']    ?? '';
$title = $props['title'] ?? '';
$theme = $props['theme'] ?? 'default';
$items = $props['items'] ?? [];

// #705's shape: a raw-value guard before a typed escaper. A non-empty array is TRUTHY,
// so the gate below passes on one and the typed call fatals the whole page.
$raw_background_image = $props['background_image'] ?? '';
$background_image     = is_scalar($raw_background_image) ? (string) $raw_background_image : '';

$theme_class = pp_theme_class($theme, 'ppfixture');

// #705's THREE GATES, all of them, because the guard's whole point is that they move
// together. A call-site-only guard would leave the modifier class and the overlay <div>
// ON with nothing painting underneath — a dark scrim over the band's own background,
// wearing the light on-overlay ink the modifier selects. That is the undesigned state the
// guard exists to prevent, and a fixture carrying only the inline declaration could not
// host the claim.
$bg_image_class = $background_image ? ' ppfixture--has-bg-image' : '';

$inline_styles = [];
if ($background_image) {
    $inline_styles[] = 'background-image:url(' . pp_esc_image_src($background_image) . ')';
}
$style_attr = $inline_styles ? ' style="' . implode('; ', $inline_styles) . ';"' : '';
?>
<section<?php echo $id ? ' id="' . esc_attr($id) . '"' : ''; ?> class="ppfixture<?php echo esc_attr($theme_class); ?><?php echo esc_attr($bg_image_class); ?>" data-pp-component="ppfixture"<?php echo $style_attr; ?>>
    <?php if ($background_image) : ?>
        <div class="ppfixture__overlay" aria-hidden="true"></div>
    <?php endif; ?>
    <div class="container">
        <?php if ($title) : ?>
            <h2 class="ppfixture__heading"><?php echo esc_html($title); ?></h2>
        <?php endif; ?>
        <?php if (!empty($items)) : ?>
            <ul class="ppfixture__list" role="list">
                <?php foreach ($items as $item) :
                    $number = is_array($item) ? ($item['number'] ?? '') : '';
                    $label  = is_array($item) ? ($item['label']  ?? '') : '';
                ?>
                    <li class="ppfixture__item">
                        <?php if ($number) : ?>
                            <span class="ppfixture__number"><?php echo esc_html($number); ?></span>
                        <?php endif; ?>
                        <?php if ($label) : ?>
                            <span class="ppfixture__label"><?php echo esc_html($label); ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</section>

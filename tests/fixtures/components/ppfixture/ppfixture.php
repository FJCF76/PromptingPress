<?php
/**
 * tests/fixtures/components/ppfixture/ppfixture.php
 *
 * TEST FIXTURE ONLY. This file exists so pp_get_registered_components() will register
 * the fixture at all: it skips any components/<name>/ directory that has no
 * <name>.php beside its schema.json (lib/admin.php). It is never loaded by the theme.
 *
 * The markup mirrors a v1 slot-bearing band closely enough for the render-guard suites
 * to exercise the real code paths - it renders the `__pp_style` map through the SAME
 * pp_render_style_vars() call every shipped v1 component uses, carries the same
 * is_array guard (#708), and emits the same theme class through pp_theme_class()
 * (#570 DG-4). A fixture that faked those would prove nothing about the engine.
 *
 * @var array $props
 */

$id    = $props['id']    ?? '';
$title = $props['title'] ?? '';
$theme = $props['theme'] ?? 'default';
$items = $props['items'] ?? [];

// #708's guard, carried verbatim rather than simplified: a stored non-array reaching
// the typed pp_render_style_vars(array $style, ...) raises a TypeError that no caller
// catches, and the render-guard suites point at this fixture to prove the degradation.
$raw_style  = $props['__pp_style'] ?? null;
$style      = is_array($raw_style) ? $raw_style : [];
$slot_style = pp_render_style_vars($style, 'ppfixture');

// #705's shape: a raw-value guard before a typed escaper. A non-empty array is TRUTHY,
// so the gate below passes on one and the typed call fatals the whole page.
$raw_background_image = $props['background_image'] ?? '';
$background_image     = is_scalar($raw_background_image) ? (string) $raw_background_image : '';

$theme_class = pp_theme_class($theme, 'ppfixture');

$inline_styles = [];
if ($slot_style) {
    $inline_styles[] = $slot_style;
}
if ($background_image) {
    $inline_styles[] = 'background-image:url(' . pp_esc_image_src($background_image) . ')';
}
$style_attr = $inline_styles ? ' style="' . implode('; ', $inline_styles) . ';"' : '';
?>
<section<?php echo $id ? ' id="' . esc_attr($id) . '"' : ''; ?> class="ppfixture<?php echo esc_attr($theme_class); ?>" data-pp-component="ppfixture"<?php echo $style_attr; ?>>
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

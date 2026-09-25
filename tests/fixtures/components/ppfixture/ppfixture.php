<?php
/**
 * tests/fixtures/components/ppfixture/ppfixture.php
 *
 * TEST FIXTURE ONLY. This file exists so pp_get_registered_components() will register
 * the fixture at all: it skips any components/<name>/ directory that has no
 * <name>.php beside its schema.json (lib/admin.php). It is never loaded by the theme.
 *
 * The markup is a plain band: an id, a heading and a list of items. It is a FILLER
 * host for write-path suites that need a registered, composable band and do not care
 * which one (see README.md for who uses it and the condition of its death).
 *
 * WHAT LEFT, AND WHEN. The `__pp_style` render and its #708 is_array guard were deleted
 * at #1101 with pp_render_style_vars(). The `theme` prop and its pp_theme_class() call
 * retired at #1111 with the whole `--dark`/`--inverted` output-name vocabulary. The
 * `background_image` prop and #705's raw-value-before-typed-escaper guard retired at
 * #1108 by decision: no shipped component has declared a text-URL band background
 * since #1066, so the guard had no subject, and the live escaper (pp_esc_image_src())
 * stays pinned on the v2 `_band` -> `background.image` path (UdcBackgroundImageTest).
 *
 * @var array $props
 */

$id    = $props['id']    ?? '';
$title = $props['title'] ?? '';
$items = $props['items'] ?? [];
?>
<section<?php echo $id ? ' id="' . esc_attr($id) . '"' : ''; ?> class="ppfixture" data-pp-component="ppfixture">
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

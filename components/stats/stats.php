<?php
/**
 * components/stats/stats.php
 *
 * A row of large-number metrics with labels. Use for quantified social proof
 * or at-a-glance credential statements (e.g. "+30 years experience").
 * Props: see schema.json
 *
 * @var array $props
 */

$id      = $props['id']      ?? '';
$title   = $props['title']   ?? '';
$variant = $props['variant'] ?? 'default';
$items   = $props['items']   ?? [];

$allowed_variants = ['default', 'dark', 'inverted'];
if (!in_array($variant, $allowed_variants, true)) {
    $variant = 'default';
}

$variant_class = $variant !== 'default' ? ' stats--' . $variant : '';
?>
<section<?php echo $id ? ' id="' . esc_attr($id) . '"' : ''; ?> class="stats<?php echo esc_attr($variant_class); ?>">
    <div class="container">

        <?php if ($title) : ?>
            <h2 class="stats__heading"><?php echo esc_html($title); ?></h2>
        <?php endif; ?>

        <?php if (!empty($items)) : ?>
            <ul class="stats__list" role="list">
                <?php foreach ($items as $item) :
                    $number = $item['number'] ?? '';
                    $label  = $item['label']  ?? '';
                ?>
                    <li class="stats__item">
                        <?php if ($number) : ?>
                            <span class="stats__number"><?php echo esc_html($number); ?></span>
                        <?php endif; ?>
                        <?php if ($label) : ?>
                            <span class="stats__label"><?php echo esc_html($label); ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

    </div>
</section>

<?php
/**
 * components/table/table.php
 *
 * Data / comparison table. The wrapper scrolls horizontally whenever the table is
 * wider than the band, at ANY viewport (overflow-x: auto, no media query).
 * Props: see schema.json
 *
 * @var array $props
 */

$id      = $props['id']      ?? '';
$title   = $props['title']   ?? '';
$headers = $props['headers'] ?? [];
$rows    = $props['rows']    ?? [];
$caption = $props['caption'] ?? '';

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
$slot_style = pp_render_style_vars($style, 'table');
$style_attr = $slot_style ? ' style="' . $slot_style . ';"' : '';
?>
<section<?php echo $id ? ' id="' . esc_attr($id) . '"' : ''; ?> class="table-section" data-pp-component="table"<?php echo $style_attr; ?>>
    <div class="container">

        <?php if ($title) : ?>
            <h2 class="table-section__heading"><?php echo esc_html($title); ?></h2>
        <?php endif; ?>

        <?php if (!empty($headers) && !empty($rows)) : ?>
            <div class="table-wrap">
                <table class="table">
                    <?php if ($caption) : ?>
                        <caption class="table__caption"><?php echo esc_html($caption); ?></caption>
                    <?php endif; ?>
                    <thead class="table__head">
                        <tr>
                            <?php foreach ($headers as $header) : ?>
                                <th class="table__header" scope="col">
                                    <?php echo esc_html($header); ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody class="table__body">
                        <?php foreach ($rows as $row) : ?>
                            <tr class="table__row">
                                <?php foreach ((array) $row as $cell) : ?>
                                    <td class="table__cell">
                                        <?php echo wp_kses_post($cell); ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else : ?>
            <p class="table-section__empty text-muted">No data.</p>
        <?php endif; ?>

    </div>
</section>

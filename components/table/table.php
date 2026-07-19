<?php
/**
 * components/table/table.php
 *
 * Data / comparison table with horizontal scroll on mobile.
 * Props: see schema.json
 *
 * @var array $props
 */

$id      = $props['id']      ?? '';
$title   = $props['title']   ?? '';
$headers = $props['headers'] ?? [];
$rows    = $props['rows']    ?? [];
$caption = $props['caption'] ?? '';

$slot_style = pp_render_style_vars($props['__pp_style'] ?? [], 'table');
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

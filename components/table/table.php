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

// ── #730: the ELEMENT-level guard for the rich-text CELL (applied in the row loop) ──
//
// Every cell goes into core's UNTYPED wp_kses_post(), which fatals from the inside on
// an array (str_contains) and on an object (preg_replace). See
// components/section/section.php for that sink and the binding never-try/catch rule,
// and components/cta/cta.php for the family reasoning.
//
// GUARDED AT THE CELL, NOT THE ROW, because those are genuinely different boundaries.
// The `(array) $row` cast in the loop already makes a malformed ROW harmless — measured,
// a scalar row becomes a one-element array and renders one cell, no fatal — so a
// row-level guard would close a door that is not open. The leaf value is the only shape
// that reaches the escaper, so that is where the guard sits. It is UNGATED, like faq's
// answer: an empty array cell fatals exactly as a populated one does.
//
// Degrades to an EMPTY CELL rather than a dropped one. Dropping it would shift every
// later cell in the row one column left and silently misalign the table against its
// headers — a worse lie to a reader than a blank cell.
//
// THE GUARD LIVES IN THE LOOP, BUT THIS EXPLANATION LIVES HERE, deliberately, and it
// cost two separate regressions to learn why. Both were caught by the byte-equality
// sweep against a clean control render, neither by eye:
//
//   1. PHP emits everything outside its tags verbatim, so a multi-line comment block
//      opened at the loop's indentation PRINTS ITS OWN LEADING WHITESPACE into every
//      table body. That changed the emitted bytes of every well-formed table on the
//      site. Same hazard as the column-0 note in components/cta/cta.php.
//   2. Moving the prose up here is not enough on its own: a PHP CLOSE TAG written
//      inside a `//` line comment still ends PHP mode, because the lexer sees the tag
//      before the comment ever ends. One such sequence quoted illustratively in this
//      very block dumped the remainder of the file to the browser as raw text.
//
// So: keep prose in the header, keep the loop terse, and never quote a close tag in it.

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
                                <?php // #730 cell guard — full reasoning in this file's header.
                                foreach ((array) $row as $raw_cell) :
                                    $cell = is_scalar($raw_cell) ? (string) $raw_cell : '';
                                ?>
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

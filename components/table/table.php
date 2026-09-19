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

// ── v2: what stayed a prop, and why ─────────────────────────────────────────
//
// NOTHING RETIRED. table is the only component in the whole v2 migration whose rebuild
// retires no prop at all: it never declared `theme`, and `styling.variant_classes` was
// already empty, so there is no tone bundle to translate into `_band` groups and no
// `retired_props` block to declare. Its entire change is style slots to roles.
//
// Every prop that was here still is: `id`, `title`, `headers`, `rows` and `caption`
// carry what the band SAYS, not how it looks.
//
// NO `refuse_props_when`, AND THAT IS A CHECKED ANSWER RATHER THAN AN OMISSION. The
// rebuild contract asks for props that are declared, well-typed and stored but paint
// nothing in the configuration the band is in. table has no layout modes, so no prop can
// be layout-dead. The one genuinely inert pair is `caption` without `headers`/`rows` —
// the <caption> renders inside the gate below, so a caption stored on an empty table
// paints nothing — and the shared `applies_when` grammar cannot express it:
// pp_applies_when_clause_errors() bounds predicates to `equals` / `in` / `present`, and
// pp_applies_when_clause_met() reads `present` by KEY and ignores its value, so
// `present: false` is a synonym rather than an inverse. Widening a shared grammar on
// behalf of one component is not this rebuild's call, so it stays filed as #1037.

// ── v2: the band's styling identity ─────────────────────────────────────────
//
// Where v1 read a `__pp_style` map of 6 slots and painted it into an inline `style`
// attribute, this emits one attribute and nothing else: `data-pp-band`. Every designable
// value for this band is in a scoped block in the document head, keyed on that attribute
// (lib/udc.php). No inline style means no specificity cliff.
//
// An absent or malformed id emits NO attribute. That is the whole guard: the engine
// mints ids on WRITE only, so a band that reached storage without one (raw meta, data
// written before the rule, or restore_composition, which reports without blocking per
// #233) must render structurally rather than be handed a fabricated id here. An EMPTY
// attribute would be worse than none — it would make `[data-pp-band=""]` match every
// other id-less band on the page and paint one band's design onto another. The canonical
// reasoning for this pair lives in components/cta/cta.php.
$raw_band  = $props['__pp_udc_band'] ?? '';
$band_id   = (is_scalar($raw_band) && pp_udc_valid_band_id((string) $raw_band)) ? (string) $raw_band : '';
$band_attr = $band_id !== '' ? ' data-pp-band="' . esc_attr($band_id) . '"' : '';

// THE FOCUS RING FOLLOWS THE OVERLAY (#986's mechanism). v1 table had no
// `background_image` prop, so a scrim was not a state this component could reach;
// `_band` -> `background.image` + `background.overlay` makes it one. `--color-accent`
// measures 1.17:1 over the worst-case scrim, a WCAG 1.4.11 failure. The attribute is
// emitted by the ENGINE, which is the only thing that knows a scrim is being painted.
// Consuming it here is what keeps table out of #1035's reach (section and testimonials
// never emit it, so a scrim band there keeps a 1.17:1 ring). What the existing rule in
// components.css then covers is a `.btn` an author wrote into a cell's rich text;
// widening that ring to every focusable is a separate blast radius and a recorded
// out-of-scope decision, so a plain cell link keeps the bare accent ring as it always has.
$overlay_attr = !empty($props['__pp_udc_overlay']) ? ' data-pp-band-overlay' : '';
?>
<section<?php echo $id ? ' id="' . esc_attr($id) . '"' : ''; ?> class="table-section" data-pp-component="table"<?php echo $band_attr; ?><?php echo $overlay_attr; ?>>
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
            <?php // THE `text-muted` UTILITY IS GONE (#1066), so the `empty` role owns
                  // this line's colour instead of a utility class — the same call #994
                  // made for footer and #1046 for faq. The measured grey is the role's
                  // `typography.color` default now, and its 16px top and bottom padding
                  // is the role's `spacing` default: base.css zeroes every element's
                  // padding, so that one is table's own value and not a global.
                  //
                  // THE CANONICAL CASCADE REASONING LIVES IN `components/table/schema.json`
                  // under the `empty` role, stated once because it is subtle in both
                  // directions — a write aimed at the ROLE always beat the layered utility,
                  // while a `_band` write reaches this line only by inheritance and lost to
                  // it. The `_band` case is the one the class stranded, which is why a dark
                  // band needs a write here (and on `caption`, which sits on the band fill
                  // too — see that role). ?>
            <p class="table-section__empty">No data.</p>
        <?php endif; ?>

    </div>
</section>

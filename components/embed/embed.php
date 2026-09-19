<?php
/**
 * components/embed/embed.php
 *
 * A generic content embed block. Renders an optional heading and passes
 * the content string through do_shortcode() — use for WP shortcodes
 * (contact forms, Gravity Forms, etc.) or pre-rendered HTML blocks that
 * belong to WP plugins rather than to the PromptingPress composition model.
 *
 * The content prop is the only way to introduce arbitrary HTML into a
 * composition. It is intentional and explicit — not a workaround.
 * Props: see schema.json
 *
 * @var array $props
 */

$id      = $props['id']      ?? '';
$title   = $props['title']   ?? '';
// #730: `content` is this component's whole reason to exist and it goes straight into
// core's UNTYPED wp_kses_post(), which fatals on an array (str_contains) and on an
// object (preg_replace). Full reasoning for the esc_url() half in
// components/cta/cta.php; for this sink and the never-try/catch rule, see
// components/section/section.php.
//
// WHAT DEGRADATION LOOKS LIKE HERE, stated because this band has less left over than
// the others: the `if ($content)` gate closes, so the .embed__content wrapper is not
// emitted at all and the band renders as its <section> plus the heading — byte-
// identical to a band that stored an empty content, which is an already-designed
// state. It is not a blank structural shell: the wrapper is inside the gate, not
// outside it.
//
// -0.0 applies, same truthiness gate and same #705 precedent as grid's link_url;
// see the note there. Both storage channels pinned.
$raw_content = $props['content'] ?? '';
$content     = is_scalar($raw_content) ? (string) $raw_content : '';

// ── v2: what stayed a prop, and why ─────────────────────────────────────────
//
// `theme` is GONE. It was a bundle of designable values — a band fill, a pair of framing
// rules, a heading colour, a body colour — which is exactly what the UDC expresses
// directly: the `_band` role's `background`, `border` and `typography` groups. All three
// of its values were measured before it went, and the route in `retired_props` names all
// three groups rather than only the fill, because `muted` drew borders that a
// background-only route would silently drop.
//
// Every CONTENT prop stays: `id`, `title` and `content` carry what the band says, not how
// it looks. embed has no layout prop, so unlike hero, section and cta nothing here
// survives on "the UDC has no group for it" grounds.
//
// NO `refuse_props_when`, AND THAT IS A CHECKED ANSWER RATHER THAN AN OMISSION. The
// rebuild contract asks for props that are declared, well-typed and stored but paint
// nothing in the configuration the band is in. embed has no layout modes, so no prop can
// be layout-dead, and its two optional props are independent — a `title` renders with or
// without `content`, and `content` is required. There is no inert pair to express, which
// is a stronger answer than #1037's grammar gap: this component would decline the clause
// even if the grammar could carry it.

// ── v2: the band's styling identity ─────────────────────────────────────────
//
// Where v1 read a `__pp_style` map of 8 slots and painted it into an inline `style`
// attribute, this emits one attribute and nothing else: `data-pp-band`. Every designable
// value for this band is in a scoped block in the document head, keyed on that attribute
// (lib/udc.php). No inline style means no specificity cliff.
//
// An absent or malformed id emits NO attribute. That is the whole guard: the engine mints
// ids on WRITE only, so a band that reached storage without one (raw meta, data written
// before the rule, or restore_composition, which reports without blocking per #233) must
// render structurally rather than be handed a fabricated id here. An EMPTY attribute
// would be worse than none — it would make `[data-pp-band=""]` match every other id-less
// band on the page and paint one band's design onto another. The canonical reasoning for
// this pair lives in components/cta/cta.php.
$raw_band  = $props['__pp_udc_band'] ?? '';
$band_id   = (is_scalar($raw_band) && pp_udc_valid_band_id((string) $raw_band)) ? (string) $raw_band : '';
$band_attr = $band_id !== '' ? ' data-pp-band="' . esc_attr($band_id) . '"' : '';

// THE FOCUS RING FOLLOWS THE OVERLAY (#986's mechanism). v1 embed had no
// `background_image` prop, so a scrim was not a state this component could reach;
// `_band` -> `background.image` + `background.overlay` makes it one. `--color-accent`
// measures 1.17:1 over the worst-case scrim, a WCAG 1.4.11 failure. The attribute is
// emitted by the ENGINE, which is the only thing that knows a scrim is being painted, and
// consuming it here is what keeps embed out of #1035's reach.
//
// IT MATTERS MORE HERE THAN ON MOST BANDS: `content` goes through wp_kses_post(), which
// admits `class`, so an author-written `<a class="btn">` really does render inside an
// embed — measured, with the full premium treatment — and the existing ring rule covers
// exactly that element. A plain link keeps the bare accent ring, which is the recorded
// out-of-scope decision rather than an oversight: widening the ring to every focusable is
// a separate blast radius.
$overlay_attr = !empty($props['__pp_udc_overlay']) ? ' data-pp-band-overlay' : '';
?>
<section<?php echo $id ? ' id="' . esc_attr($id) . '"' : ''; ?> class="embed" data-pp-component="embed"<?php echo $band_attr; ?><?php echo $overlay_attr; ?>>
    <div class="container">

        <?php if ($title) : ?>
            <h2 class="embed__heading"><?php echo esc_html($title); ?></h2>
        <?php endif; ?>

        <?php if ($content) : ?>
            <div class="embed__content">
                <?php echo do_shortcode(wp_kses_post($content)); ?>
            </div>
        <?php endif; ?>

    </div>
</section>

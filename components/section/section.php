<?php
/**
 * components/section/section.php
 *
 * Generic content band: heading block plus rich-text body, with an optional image
 * column, inline-items strip, or right-hand content panel.
 * Props: see schema.json
 *
 * @var array $props
 */

$id               = $props['id']               ?? '';
// #706: guard BOTH raw-value text arguments of pp_render_heading_with_accent()
// (`string $title`, `string $accent`) before they reach the calls below. A non-empty
// array is truthy, so the `if ($title)` gate passes on one and the typed call raises a
// TypeError that no caller catches — the whole PUBLIC PAGE 500s. Argument #2 fatals the
// same way on its own, so both props are guarded, not just the title. Guarded at the
// READ because the gates that decide whether the heading renders at all sit upstream of
// the calls, so a guarded-away value renders the band with no heading rather than an
// empty one. is_scalar + (string), NOT is_string: only non-scalars ever fataled
// (coercive mode), and the write path stores a scalar title raw (#707), so is_string()
// would silently drop an accepted value. Full reasoning in components/hero/hero.php.
// Local specifics: section reaches the helper from THREE layout branches (one per
// layout), each behind its own `section__header` gate. One read upstream of all three
// is why this is a single guard and not three — measured: every layout value fatals
// today, and every one degrades after. Distinct from the image_url guard below, which
// makes the image-layout fallback fire; a malformed title costs only the header, so the
// band keeps its layout and its body.
$raw_title        = $props['title']            ?? '';
$title            = is_scalar($raw_title) ? (string) $raw_title : '';
$raw_title_accent = $props['title_accent']     ?? '';
$title_accent     = is_scalar($raw_title_accent) ? (string) $raw_title_accent : '';
$eyebrow          = $props['eyebrow']          ?? '';
$subheading       = $props['subheading']       ?? '';
// #730: guard the rich-text `body` before it reaches core's UNTYPED wp_kses_post(),
// which fatals from the inside on both non-string shapes. Full reasoning for the
// esc_url() half lives in components/cta/cta.php; this is the OTHER core sink, and its
// two failure modes are reached through different builtins:
//
//   wp_kses_post( ['x'] )     TypeError: str_contains(): Argument #1 ($haystack) must be of type string, array given
//                             (via pre_kses -> wp_pre_kses_block_attributes -> filter_block_content)
//   wp_kses_post( new Foo )   TypeError: preg_replace(): Argument #3 ($subject) must be of type array|string, Foo given
//                             (via wp_kses_no_null)
//
// GUARD BEFORE THE CALL, NEVER try/catch IT. This is recorded on #730 as a binding
// constraint rather than a preference: wp_pre_kses_block_attributes() calls
// remove_filter('pre_kses', ...), then filter_block_content(), then the matching
// add_filter(). When the middle step throws, the re-add never runs, so swallowing the
// TypeError would silently de-register block-attribute KSES for the REST OF THE
// REQUEST — turning a visible 500 into an invisible sanitization hole. The same
// mechanism is a measurement trap: a probe that catches the first throw reports every
// later wp_kses_post() as safe. Measure one process per case.
//
// ALL THREE CALL SITES ARE UNGATED. `body` renders inside .section__content in each of
// the three layout branches, with no `if` around the echo, so every layout fatals and
// an EMPTY array fatals as readily as a populated one. Guarding at the read fixes all
// three from one place and is why there is exactly one read of this prop.
$raw_body         = $props['body']             ?? '';
$body             = is_scalar($raw_body) ? (string) $raw_body : '';
// #641: guard BOTH raw-value arguments of pp_render_responsive_image() (`string $url`,
// `string $alt`) before they reach it. A non-empty array is truthy, so the `!$image_url`
// layout fallback below does NOT fire on one, the band keeps its image layout, and the
// typed call raises a TypeError that no caller catches — the whole PUBLIC PAGE 500s.
// is_scalar + (string), NOT is_string: only non-scalars ever fataled (coercive mode),
// and the write path stores a scalar image_url raw (#707), so is_string() would silently
// drop an accepted value AND its resolvable image_id attachment with it. Full reasoning
// in components/logos/logos.php. Same STORED-data reachability as the image_id guard
// below (#233 restore, pre-rule compositions, raw meta). Here a guarded-away image_url
// makes the EXISTING image-layout fallback fire, so the band renders text-only — exactly
// what an empty image_url already does.
//
// #1023: the v1 sibling of this guard covered `background_image`, which is GONE. A band
// background is the `_band` role's `background.image` now (ruling A2), so the engine
// resolves the attachment and builds the url() — this file never touches it, and the
// pp_esc_image_src() call site it used to guard no longer exists.
$raw_image_url    = $props['image_url']        ?? '';
$image_url        = is_scalar($raw_image_url) ? (string) $raw_image_url : '';
$raw_image_alt    = $props['image_alt']        ?? '';
$image_alt        = is_scalar($raw_image_alt) ? (string) $raw_image_alt : '';
// is_numeric() BEFORE the (int) cast (#614, extended to the top-level readers at
// gate 7A). `(int) ['attachment_id' => 42]` and `(int) true` both evaluate to 1, so
// a bare cast resolves attachment ID 1 — usually the site's first upload — and
// discards the author's image_url. The write path rejects that shape now, but the
// validator gates WRITES: restore_composition reports without blocking (#233), so a
// composition authored before the rule still reaches this line. Same guard hero,
// logos, grid and testimonials carry.
$raw_image_id     = $props['image_id'] ?? 0;
$image_id         = is_numeric($raw_image_id) ? (int) $raw_image_id : 0;
$layout           = $props['layout']           ?? 'text-only';

// Inline-items row (issue 475): an optional centered row of short plain-text
// items with a CSS-generated separator between them. Plain strings only (no HTML,
// esc_html at render); the write-time validator caps the count/length and rejects
// non-strings, so here we only drop empty strings for a byte-identical unset path.
// Renders after the body when both are set.
$body_items = is_array($props['body_items'] ?? null) ? $props['body_items'] : [];
$body_items = array_values(array_filter(
    $body_items,
    static function ($item) {
        return is_string($item) && $item !== '';
    }
));

// text-panel layout: right-hand content panel props (see schema.json).
$panel_heading      = $props['panel_heading']      ?? '';
$panel_body         = $props['panel_body']         ?? '';
$panel_items        = is_array($props['panel_items'] ?? null) ? $props['panel_items'] : [];
$panel_cta_text     = $props['panel_cta_text']     ?? '';
// #730: the panel CTA's link prop into core's esc_url(). Same boundary as cta/hero
// (full reasoning in components/cta/cta.php); the gate below is what makes this site
// different, so read the $has_panel_cta note there before changing either line.
$raw_panel_cta_url  = $props['panel_cta_url']      ?? '';
$panel_cta_url      = is_scalar($raw_panel_cta_url) ? (string) $raw_panel_cta_url : '';
$panel_items_marker = $props['panel_items_marker'] ?? 'disc';
$body_marker        = $props['body_marker']        ?? 'disc';
$body_items_align   = $props['body_items_align']   ?? 'start';

// A panel entry is EITHER a plain string (a bullet, unchanged) OR a paired-row
// object { label, value } (issue 334). Keep non-empty strings and any array that
// carries a scalar label or value; drop everything else (empty strings, numbers,
// and shapeless arrays) exactly as the string-only form did.
//
// #1023: the per-row `style` map is GONE with the rest of the slot system. Per-item
// design addressing has no address in the v2 contract — roles are band-grain — and
// giving it one is a contract question, staged as BUILD-SPEC Addendum B and gated on
// issue #1024. Until that is ruled, a panel row is styled through the `panel-row`
// role, which reaches every row in the band.
$panel_items = array_values(array_filter(
    $panel_items,
    static function ($item) {
        if (is_string($item)) {
            return $item !== '';
        }
        if (is_array($item)) {
            $has_label = isset($item['label']) && is_scalar($item['label']) && (string) $item['label'] !== '';
            $has_value = isset($item['value']) && is_scalar($item['value']) && (string) $item['value'] !== '';
            return $has_label || $has_value;
        }
        return false;
    }
));

// List-marker selection (issue 339). A list can carry a marker other than the
// default disc — check / dash / arrow. THE GLYPH IS CONTENT, which is why these
// stay props; the marker COLOUR was a style slot and is not authorable in v2,
// because the glyph is painted by a ::before pseudo-element and ruling A3 defers
// pseudo-elements to their own ruling (recorded in schema.json's retired_props and
// in the Addendum B draft's exclusion list). `disc` is the default and adds NO
// class, so an un-opted list renders exactly as before.
$allowed_markers = ['disc', 'check', 'dash', 'arrow'];
if (!in_array($panel_items_marker, $allowed_markers, true)) {
    $panel_items_marker = 'disc';
}
if (!in_array($body_marker, $allowed_markers, true)) {
    $body_marker = 'disc';
}
// text-panel list opts in with the shared .pp-marker-list treatment on its <ul>.
$panel_list_marker_class = $panel_items_marker !== 'disc'
    ? ' pp-marker-list pp-marker-list--' . $panel_items_marker
    : '';
// section.body opts in with a modifier on the container we control; the shared
// rules style its direct-child <ul> (nested/plugin lists keep their disc).
$content_marker_class = $body_marker !== 'disc'
    ? ' section__content--marker-' . $body_marker
    : '';

// The panel CTA needs BOTH a label and a URL to render.
// #730: this gate reads the RAW url, plus an explicit is_scalar term, and both halves
// are deliberate. Getting it wrong in either direction is a behaviour change:
//
//   gate on the GUARDED value  -> a stored `false` stops rendering its button. But
//     `false` is STORED-AND-RENDERING: it was write-accepted for the whole history of
//     the product (measured at the time: pp_validate_composition() returned ok=true with
//     ZERO findings for panel_cta_url:false), so real pages hold it, and (string) false
//     is '', which fails `!== ''`. #707 has since closed that door for NEW writes, which
//     changes nothing here — the stored values are still stored.
//   gate on the RAW value ALONE -> an array passes `!== ''` (it is not the empty
//     string), the gate opens, and the guarded '' renders `<a href="">Go</a>`. An
//     empty-href anchor is not "the band renders without the affected fragment"; it is
//     a broken button pointing at the current page. So the shape test has to be here.
//
// Together they give exactly the intended split: every SCALAR keeps its existing
// behaviour byte-for-byte, and only the shapes that used to FATAL change, to "no
// button", which is what an empty panel_cta_url has always meant here. Pinned both ways
// in tests/StoredLinkAndRichTextRenderGuardTest.php.
//
// "NO BUTTON" UNDERSTATES IT ON ONE BAND SHAPE, so state the second-order effect rather
// than let a future reader trust the smaller claim. $has_panel_cta is one of the four
// terms in $has_panel below, and $has_panel decides whether the text-panel layout
// renders its panel column at all or falls back to text-only. So on a panel whose ONLY
// content is the CTA — panel_cta_text plus panel_cta_url, no heading, no body, no items
// — a guarded-away url collapses the entire panel column and changes the band's layout,
// not just its button. That is still exactly what the same band stored with an empty
// panel_cta_url does today, so it remains "degrade to the empty state" rather than a new
// one, and it is strictly better than the 500 it replaces. Pinned as its own case.
$has_panel_cta = $panel_cta_text !== '' && is_scalar($raw_panel_cta_url) && $raw_panel_cta_url !== '';

// The panel column renders only when it has some content; otherwise text-panel
// degrades to a plain text section (mirrors the image-layout fallback below).
// panel_body counts so a body-only panel never silently drops authored content.
$has_panel = $panel_heading !== ''
    || $panel_body !== ''
    || !empty($panel_items)
    || $has_panel_cta;

$allowed_layouts = ['text-only', 'image-left', 'image-right', 'centered', 'text-panel'];
if (!in_array($layout, $allowed_layouts, true)) {
    $layout = 'text-only';
}

// Centered layout suppresses image regardless.
// text-panel falls back to text-only when the panel has no content.
// For image layouts, fall back to text-only if no image URL.
if ($layout === 'text-panel') {
    if (!$has_panel) {
        $layout = 'text-only';
    }
} elseif ($layout !== 'centered' && !$image_url) {
    $layout = 'text-only';
}

// ── v2: what stayed a prop, and why ─────────────────────────────────────────
//
// `theme`, `title_align`, `background_image` and `panel_cta_variant` are GONE. Each
// was a bundle of designable values — a set of band colours, a text alignment, a
// painted background, a set of button colours — which is exactly what the UDC
// expresses directly: the `_band` role's `background` group plus `typography.color`
// on the text roles, the `header` role's `typography.align`, `_band` ->
// `background.image` (ruling A2), and the `panel-cta` role or a button preset.
//
// WHAT REMAINS A PROP, AND WHY — RESTATED AT #1084, WHEN THE LAYOUT GROUP ARRIVED.
// This comment used to read "the taxonomy carries no layout group". It does now, so
// the honest reason is the one that survives it: both props select a MECHANISM
// BUNDLE rather than a value. `layout` swaps a whole geometry, and
// `body_items_align` picks a wrap TECHNIQUE — a justify-content value AND the
// ::before -> ::after separator switch that technique requires, which no role value
// could ever carry. The group retunes what the bundle sets: `columns.layout.*`
// outranks the track and the alignment this file's rules declare, and
// `inline-items.layout.justify` outranks the packing — all unlayered against
// `pp-v1`. The same reading now applies to hero's `split_ratio`/`vertical_align`.
$allowed_body_items_aligns = ['start', 'center'];
if (!in_array($body_items_align, $allowed_body_items_aligns, true)) {
    $body_items_align = 'start';
}

// ── v2: the band's styling identity ─────────────────────────────────────────
//
// Where v1 read a `__pp_style` map of 47 slots and painted it into an inline
// `style` attribute — plus an inline `background-image` built from
// `background_image`, and a `.section__overlay` scrim element — this emits one
// attribute and nothing else: `data-pp-band`. Every designable value for this band
// is in a scoped block in the document head, keyed on that attribute (lib/udc.php).
// No inline style means no specificity cliff — a band's rules and the stylesheet's
// structural rules sit at comparable weight and resolve in source order, which is
// what makes the cascade a cascade. The scrim is `background.overlay`, composed into
// the same background layer list by the engine, so the extra element is gone too.
//
// An absent or malformed id emits NO attribute. That is the whole guard: the engine
// mints ids on WRITE only, so a band that reached storage without one (raw meta, data
// written before the rule, or restore_composition, which reports without blocking per
// #233) must render structurally rather than be handed a fabricated id here. An EMPTY
// attribute would be worse than none — it would make `[data-pp-band=""]` match every
// other id-less band on the page and paint one band's design onto another.
$raw_band  = $props['__pp_udc_band'] ?? '';
$band_id   = (is_scalar($raw_band) && pp_udc_valid_band_id((string) $raw_band)) ? (string) $raw_band : '';
$band_attr = $band_id !== '' ? ' data-pp-band="' . esc_attr($band_id) . '"' : '';

// Build the inline-items row once (issue 475) and place it in each layout's body
// scope, after .section__content. role="list" keeps list semantics while the
// CSS-generated separator stays out of the accessibility tree.
//
// #488's AUTOMATIC FLUSH-TOP IS RETIRED (#1023), and the `$has_body_copy` flag that
// drove it is gone with the modifier class. The behaviour was: a body-copy-less strip
// got its top margin zeroed so the band's symmetric padding centred it optically.
//
// It could not survive the rebuild. The modifier's rule lives in `components.css`,
// which is in `@layer pp-v1`, while the `inline-items` role's `spacing.margin-top`
// default emits UNLAYERED — so the modifier could never win again whatever it declared.
// Keeping it would have meant emitting a class in the markup that nothing on the page
// can act on, which is the reported-success-without-effect class the UDC exists to end.
//
// THE CAPABILITY IS STILL REACHABLE, as an author idiom rather than an inference: set
// `spacing.margin-top: 0` on the `inline-items` role for a strip with no body copy
// above it. Named in this component's README, the CHANGELOG and the AI-facing authoring
// docs, so the model teaches the idiom where it used to describe the automatic
// behaviour.
$inline_items_html = '';
if (!empty($body_items)) {
    $items_markup = '';
    foreach ($body_items as $body_item) {
        $items_markup .= '<li class="section__inline-item">' . esc_html($body_item) . '</li>';
    }
    // Per-line alignment (issue 510, repriced at #1023): 'start' keeps the #489
    // hanging-separator clip — left-packed lines. 'center' derives the --center
    // modifier, which switches the row to per-line centering with a trailing
    // separator (see components.css). In v1 this came from a style slot read out of
    // the `__pp_style` map; v2 has no slot map, and justify-content is a LAYOUT
    // property the taxonomy carries no group for, so it is a declared prop now. The
    // modifier carries what a raw keyword cannot (the separator switch + margin),
    // which is why the class is derived here rather than left to a role value.
    $inline_items_class = 'section__inline-items'
        . ($body_items_align === 'center' ? ' section__inline-items--center' : '');
    $inline_items_html = '<ul class="' . $inline_items_class . '" role="list">' . $items_markup . '</ul>';
}

?>
<section<?php echo $id ? ' id="' . esc_attr($id) . '"' : ''; ?> class="section section--<?php echo esc_attr($layout); ?>" data-pp-component="section"<?php echo $band_attr; ?>>
    <div class="container">

        <?php if ($layout === 'text-only' || $layout === 'centered') : ?>

            <div class="section__body">
                <?php if ($title || $eyebrow || $subheading) : ?>
                    <div class="section__header">
                        <?php if ($eyebrow) : ?>
                            <span class="section__eyebrow"><?php echo esc_html($eyebrow); ?></span>
                        <?php endif; ?>
                        <?php if ($title) : ?>
                            <h2 class="section__title"><?php echo pp_render_heading_with_accent($title, $title_accent, 'section__title-accent'); ?></h2>
                        <?php endif; ?>
                        <?php if ($subheading) : ?>
                            <p class="section__subheading"><?php echo esc_html($subheading); ?></p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <div class="section__content<?php echo esc_attr($content_marker_class); ?>">
                    <?php echo wp_kses_post($body); ?>
                </div>
                <?php echo $inline_items_html; ?>
            </div>

        <?php elseif ($layout === 'text-panel') : ?>

            <div class="section__grid">
                <div class="section__body">
                    <?php if ($title || $eyebrow || $subheading) : ?>
                        <div class="section__header">
                            <?php if ($eyebrow) : ?>
                                <span class="section__eyebrow"><?php echo esc_html($eyebrow); ?></span>
                            <?php endif; ?>
                            <?php if ($title) : ?>
                                <h2 class="section__title"><?php echo pp_render_heading_with_accent($title, $title_accent, 'section__title-accent'); ?></h2>
                            <?php endif; ?>
                            <?php if ($subheading) : ?>
                                <p class="section__subheading"><?php echo esc_html($subheading); ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <div class="section__content<?php echo esc_attr($content_marker_class); ?>">
                        <?php echo wp_kses_post($body); ?>
                    </div>
                    <?php echo $inline_items_html; ?>
                </div>

                <div class="section__panel">
                    <?php if ($panel_heading) : ?>
                        <h3 class="section__panel-heading"><?php echo esc_html($panel_heading); ?></h3>
                    <?php endif; ?>
                    <?php if ($panel_body) : ?>
                        <p class="section__panel-body"><?php echo esc_html($panel_body); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($panel_items)) : ?>
                        <ul class="section__panel-list<?php echo esc_attr($panel_list_marker_class); ?>">
                            <?php foreach ($panel_items as $panel_item) : ?>
                                <?php if (is_array($panel_item)) : ?>
                                    <?php
                                    // Paired-row entry (issue 334): label/value, not a bullet.
                                    // The v1 per-row `style` map is gone with the slot system
                                    // (#1023); the row is styled through the `panel-row` role,
                                    // which reaches every row in the band. Per-ROW addressing is
                                    // the contract question staged as Addendum B (#1024).
                                    $row_label = isset($panel_item['label']) && is_scalar($panel_item['label']) ? (string) $panel_item['label'] : '';
                                    $row_value = isset($panel_item['value']) && is_scalar($panel_item['value']) ? (string) $panel_item['value'] : '';
                                    ?>
                                    <li class="section__panel-row">
                                        <span class="section__panel-row-label"><?php echo esc_html($row_label); ?></span>
                                        <span class="section__panel-row-value"><?php echo esc_html($row_value); ?></span>
                                    </li>
                                <?php else : ?>
                                    <li class="section__panel-item"><?php echo esc_html($panel_item); ?></li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if ($has_panel_cta) : ?>
                        <a href="<?php echo esc_url($panel_cta_url); ?>" class="section__panel-cta btn">
                            <?php echo esc_html($panel_cta_text); ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>

        <?php else : ?>

            <div class="section__grid">
                <?php if ($layout === 'image-left') : ?>
                    <div class="section__image-wrap">
                        <?php echo pp_render_responsive_image($image_url, $image_alt, 'section__image', 'lazy', $image_id); ?>
                    </div>
                <?php endif; ?>

                <div class="section__body">
                    <?php if ($title || $eyebrow || $subheading) : ?>
                        <div class="section__header">
                            <?php if ($eyebrow) : ?>
                                <span class="section__eyebrow"><?php echo esc_html($eyebrow); ?></span>
                            <?php endif; ?>
                            <?php if ($title) : ?>
                                <h2 class="section__title"><?php echo pp_render_heading_with_accent($title, $title_accent, 'section__title-accent'); ?></h2>
                            <?php endif; ?>
                            <?php if ($subheading) : ?>
                                <p class="section__subheading"><?php echo esc_html($subheading); ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <div class="section__content<?php echo esc_attr($content_marker_class); ?>">
                        <?php echo wp_kses_post($body); ?>
                    </div>
                    <?php echo $inline_items_html; ?>
                </div>

                <?php if ($layout === 'image-right') : ?>
                    <div class="section__image-wrap">
                        <?php echo pp_render_responsive_image($image_url, $image_alt, 'section__image', 'lazy', $image_id); ?>
                    </div>
                <?php endif; ?>
            </div>

        <?php endif; ?>

    </div>
</section>

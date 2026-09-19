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

$id               = $props['id']               ?? '';
// #706: guard BOTH raw-value text arguments of pp_render_heading_with_accent()
// (`string $title`, `string $accent`) before they reach the call below. A non-empty
// array is truthy, so the `if ($title)` gate passes on one and the typed call raises a
// TypeError that no caller catches — the whole PUBLIC PAGE 500s. Argument #2 fatals the
// same way on its own, so both props are guarded, not just the title. Guarded at the
// READ because the gate that decides whether the heading renders at all sits upstream of
// the call, so a guarded-away value renders the band with no heading rather than an
// empty one. is_scalar + (string), NOT is_string: only non-scalars ever fataled
// (coercive mode), and the write path stores a scalar title raw (#707), so is_string()
// would silently drop an accepted value. Full reasoning in components/hero/hero.php.
// Local specifics: the heading is the only thing `$title` drives here, so a malformed
// one costs exactly the `<h2>` — the numbers below still render. The background_image
// guard below is #705's, a different prop into a different typed helper.
$raw_title        = $props['title']            ?? '';
$title            = is_scalar($raw_title) ? (string) $raw_title : '';
$raw_title_accent = $props['title_accent']     ?? '';
$title_accent     = is_scalar($raw_title_accent) ? (string) $raw_title_accent : '';
$theme            = $props['theme']            ?? 'default';
$items            = $props['items']            ?? [];
// ── #705: THE CANONICAL RAW-VALUE GUARD FOR background_image INTO pp_esc_image_src() ──
//
// THE CANONICAL EXPLANATION FOR background_image LIVES HERE, as of #1026. It lived in
// components/cta/cta.php from #705 until cta's v2 rebuild retired the prop; stats is the
// last component that declares it, so it is the last place the reasoning can live. If
// stats is itself rebuilt, this prop leaves the theme entirely and this block goes with
// it rather than moving again. It is the same idiom components/logos/logos.php documents
// for image_url (#641) and components/hero/hero.php for title (#706), ratified as the
// family standard at gate D-B.
//
// The helper's first argument is typed:
//   pp_esc_image_src(string $url, int $depth = 0)
// A non-empty array is TRUTHY, so the `if ($background_image)` gate below PASSES on one
// and the typed call raises a TypeError. templates/composition.php calls
// pp_get_component() with no try/catch, so ONE malformed stored value returns a
// whole-page 500 instead of a band missing its background.
//
// GUARDED AT THE READ, NOT AT THE CALL, and that placement is the behaviour. This prop
// drives THREE gates — the --has-bg-image modifier, the inline background-image
// declaration, and the overlay <div> — and the read is upstream of all three. A
// call-site-only guard would leave the modifier and the overlay ON with nothing painting
// underneath: a dark scrim over the band's own background, wearing the light on-overlay
// ink the modifier selects. That is a visual state nobody designed. Guarding here reuses
// one that shipped long ago — the band renders exactly as it does with an empty
// background_image.
//
// HONEST LIMIT of that argument, so the next reader is not misled: this guard closes the
// NON-SCALAR route into that undesigned state, not every route. All three gates key on
// the PRE-escaper string, and pp_esc_image_src() returns '' for anything it rejects, so a
// stored STRING the sanitizer refuses (a data:text/html URI, a scripted SVG) still renders
// `background-image:url()` with the modifier and the overlay ON — the scrim-over-nothing
// state, reached by a different door. That is PRE-EXISTING behaviour, unchanged here and
// pinned as-is in StoredBackgroundImageRenderGuardTest.
//
// is_scalar, NOT is_string. PHP runs COERCIVE here (no declare(strict_types)), so only
// NON-SCALARS ever fataled: a stored `42` coerced at the boundary and PAINTED a
// background. #707 has since narrowed the WRITE path so `background_image: 42` is refused,
// but that gates writes and not storage — a pre-#707 composition, a restore (#233) and a
// raw meta write all still hold it, and it still has to paint — so is_string() would
// silently drop a value that renders correctly today. One half of the #641 rationale does
// NOT carry over: background_image has no image_id companion (it is CSS background-image,
// not an <img>), so there is no resolvable attachment to discard here. Stated directly
// rather than by comparison — stats has no image_url prop, so there is no local guard to
// point at.
//
// NOTE ON THE EXACT BYTES, because it is easy to get wrong from the test suite: what a
// schemeless scalar paints is decided by core's esc_url(), NOT by this guard. Real
// WordPress prepends a scheme to a value with no ':' and no leading /#?, so production
// emits `url(http://42)`. The PHPUnit stub in tests/bootstrap.php does not reproduce that
// character work (it is type-faithful, not byte-faithful — pinned in
// tests/EscapingStubContractTest.php), so under test the same value reads `url(42)`.
//
// The (string) cast leaves the three gates alone for every scalar but one: FLOAT NEGATIVE
// ZERO. `-0.0` is falsy, but `(string) -0.0` is `'-0'`, which is truthy, so it opens the
// three gates it used to leave shut. What decides it is the stored JSON TEXT, not the PHP
// value written: json_encode(-0.0) emits `-0`, which decodes to INT 0 and stays falsy, so
// only stored bytes that already contain the literal text `-0.0` reach the flip. Both
// halves are pinned in StoredBackgroundImageRenderGuardTest, so the claim stays measured.
// Left as-is deliberately: `-0` is inert in both the CSS url() token and the attribute, so
// the consequence is one absurd stored value painting a scrim, not a safety hole.
//
// STORED data is the point. The write path rejects non-scalars, but it gates WRITES, not
// storage: restore_composition reports without blocking (#233), a composition authored
// before the rule still carries the value, and a raw _pp_composition meta write is not
// gated at all. Nothing here rewrites the store — the value is read, not migrated, and
// _pp_composition_findings() still reports it to the operator.
$raw_background_image = $props['background_image'] ?? '';
$background_image     = is_scalar($raw_background_image) ? (string) $raw_background_image : '';

// theme coercion lives in pp_theme_class(); `muted` emits the legacy `--dark` class (#570 DG-4).
$theme_class    = pp_theme_class($theme, 'stats');
$bg_image_class = $background_image ? ' stats--has-bg-image' : '';

// Style slot overrides (per-instance visual customization).
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
$slot_style = pp_render_style_vars($style, 'stats');

$inline_styles = [];
if ($slot_style) {
    $inline_styles[] = $slot_style;
}
if ($background_image) {
    $inline_styles[] = 'background-image:url(' . pp_esc_image_src($background_image) . ')';
}
$style_attr = $inline_styles ? ' style="' . implode('; ', $inline_styles) . ';"' : '';

?>
<section<?php echo $id ? ' id="' . esc_attr($id) . '"' : ''; ?> class="stats<?php echo esc_attr($theme_class); ?><?php echo esc_attr($bg_image_class); ?>" data-pp-component="stats"<?php echo $style_attr; ?>>
    <?php if ($background_image) : ?>
        <div class="stats__overlay" aria-hidden="true"></div>
    <?php endif; ?>
    <div class="container">

        <?php if ($title) : ?>
            <h2 class="stats__heading"><?php echo pp_render_heading_with_accent($title, $title_accent, 'stats__heading-accent'); ?></h2>
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

<?php
/**
 * components/cta/cta.php
 *
 * Call-to-action block. Two layouts: full-width (centered) and inline (flex row).
 * Props: see schema.json
 *
 * @var array $props
 */

$id               = $props['id']               ?? '';
$title            = $props['title']            ?? '';
$title_accent     = $props['title_accent']     ?? '';
$eyebrow          = $props['eyebrow']          ?? '';
$body             = $props['body']             ?? '';
$button_text      = $props['button_text']      ?? 'Get Started';
$button_url       = $props['button_url']       ?? '#';
$button2_text     = $props['button2_text']     ?? '';
$button2_url      = $props['button2_url']      ?? '#';
$layout           = $props['layout']           ?? 'full-width';
$theme            = $props['theme']            ?? 'default';
// ── #705: the raw-value guard for pp_esc_image_src() ────────────────────────
//
// THE CANONICAL EXPLANATION FOR background_image LIVES HERE. stats and section
// carry the same two-line guard with a pointer back to this block; keep the
// reasoning in one place so a correction lands once. It is the same idiom
// components/logos/logos.php documents for image_url (#641), ratified as the
// family standard at gate D-B.
//
// The helper's first argument is typed:
//   pp_esc_image_src(string $url, int $depth = 0)
// A non-empty array is TRUTHY, so the `if ($background_image)` gate below PASSES
// on one and the typed call raises a TypeError. templates/composition.php calls
// pp_get_component() with no try/catch, so ONE malformed stored value returns a
// whole-page 500 instead of a band missing its background.
//
// GUARDED AT THE READ, NOT AT THE CALL, and that placement is the behaviour. This
// prop drives THREE gates — the --has-bg-image modifier, the inline
// background-image declaration, and the overlay <div> — and the read is upstream
// of all three. A call-site-only guard would leave the modifier and the overlay
// ON with nothing painting underneath: a dark scrim over the band's own
// background, wearing the light on-overlay ink the modifier selects. That is a
// visual state nobody designed. Guarding here reuses one that shipped long ago —
// the band renders exactly as it does with an empty background_image.
//
// HONEST LIMIT of that argument, so the next reader is not misled: this guard closes
// the NON-SCALAR route into that undesigned state, not every route. All three gates key
// on the PRE-escaper string, and pp_esc_image_src() returns '' for anything it rejects,
// so a stored STRING the sanitizer refuses (a data:text/html URI, a scripted SVG, a
// malformed base64 payload) still renders `background-image:url()` with the modifier and
// the overlay ON — the scrim-over-nothing state, reached by a different door. That is
// PRE-EXISTING behaviour, unchanged here and pinned as-is in
// StoredBackgroundImageRenderGuardTest::testTheEscaperStillRunsAtEveryBackgroundImageCallSite.
// Gating the three states on the escaper's OUTPUT instead of its input would close it,
// but that is a behaviour change on three components and needs its own ruling — filed
// separately rather than smuggled in here.
//
// is_scalar, NOT is_string. PHP runs COERCIVE here (no declare(strict_types)), so
// only NON-SCALARS ever fataled: a stored `42` coerced at the boundary and PAINTED a
// background, and create_page ACCEPTS `background_image: 42` and stores it raw with no
// finding (#707). is_string() would silently drop a value the front door had just
// accepted. Stated honestly, one half of the #641 rationale does NOT carry over:
// background_image has no image_id companion (it is CSS background-image, not an
// <img>), so there is no resolvable attachment to discard here. The
// write-accepted-scalar half carries on its own and is sufficient.
//
// NOTE ON THE EXACT BYTES, because it is easy to get wrong from the test suite: what a
// schemeless scalar paints is decided by core's esc_url(), NOT by this guard. Real
// WordPress prepends a scheme to a value with no ':' and no leading /#? — see
// wp-includes/formatting.php, `$url = $scheme . $url` — so production emits
// `url(http://42)`. The PHPUnit stub in tests/bootstrap.php does not reproduce that
// character work (it is type-faithful, not byte-faithful — pinned as such in
// tests/EscapingStubContractTest.php), so under test the same value reads `url(42)`.
// Either way the guard's contract is unchanged and is the thing worth stating: the
// scalar is passed through to the escaper exactly as before.
//
// The (string) cast leaves the three gates alone for every scalar but one. Measured
// across 0, 0.0, -0, false, true, 42, 3.14, -1, '', '0', '0.0', '+0', 'x', '00', NAN,
// INF, -INF: raw and cast agree on truthiness, because PHP's "0" is itself falsy. The
// exception is FLOAT NEGATIVE ZERO: -0.0 is falsy, but (string) -0.0 is '-0', which is
// truthy (only '' and '0' are falsy strings), so it opens the three gates it used to
// leave shut.
//
// That exception is REAL BUT BARELY REACHABLE, and the difference is worth stating
// precisely because two reviewers read it opposite ways. What decides it is the stored
// JSON TEXT, not the PHP value that was written:
//
//   json_encode(-0.0)            -> the text `-0`   -> json_decode gives INT 0   -> falsy, NO flip
//   stored text `-0.0` (literal) -> json_decode gives FLOAT -0                   -> truthy, FLIP
//
// PHP's own json_encode never emits the decimal-point form, so every writer that
// re-encodes — pp_update_composition, and create_page, which ACCEPTS -0.0 with ok=true
// and no findings — round-trips it to int 0 and paints nothing, exactly as before. Only
// stored bytes that already contain the literal text `-0.0` (a raw _pp_composition meta
// write, a hand-edited row) reach the flip. Both halves are pinned — see
// testTheStringCastFlipsTheGateOnlyForNegativeZero here for the renderer level and
// testNegativeZeroFlipsTheGateOnlyThroughARawMetaWrite in
// StoredBackgroundImageRenderGuardTest for both storage channels — so the claim stays
// measured, not asserted.
//
// Left as-is deliberately. -0.0 still routes through pp_esc_image_src(), and '-0' is
// inert in both the CSS url() token and the attribute, so the consequence is one absurd
// stored value painting a scrim, not a safety hole. Special-casing it would mean
// inspecting and rewriting the stored value, which is exactly what D-B forbids.
// Everything else that changes is a shape that used to FATAL, and it changes to "no
// background image" — what an empty value has always meant here.
//
// The scalar URL semantics this preserves are COMPATIBILITY, not a claim that they are
// correct. Tightening what the write path ACCEPTS is #707; this guard deliberately does
// not prejudge it by rejecting at render what the front door still admits.
//
// STORED data is the point. The write path rejects non-scalars, but it gates WRITES,
// not storage: restore_composition reports without blocking (#233), a composition
// authored before the rule still carries the value, and a raw _pp_composition meta
// write is not gated at all. Nothing here rewrites the store — the value is read,
// not migrated, and _pp_composition_findings() still reports it to the operator.
$raw_background_image = $props['background_image'] ?? '';
$background_image     = is_scalar($raw_background_image) ? (string) $raw_background_image : '';
$button_variant   = $props['button_variant']   ?? 'primary';
$button2_variant  = $props['button2_variant']  ?? 'outline';

$allowed_layouts = ['full-width', 'inline'];
if (!in_array($layout, $allowed_layouts, true)) {
    $layout = 'full-width';
}

// Shared 4-variant button primitive (same list as components/hero/hero.php).
$allowed_button_variants = ['primary', 'secondary', 'outline', 'ghost'];
if (!in_array($button_variant, $allowed_button_variants, true)) {
    $button_variant = 'primary';
}
if (!in_array($button2_variant, $allowed_button_variants, true)) {
    $button2_variant = 'outline';
}
// primary is the bare .btn; other variants add a .btn--{variant} modifier.
$button_variant_class  = $button_variant !== 'primary' ? ' btn--' . $button_variant : '';
$button2_variant_class = $button2_variant !== 'primary' ? ' btn--' . $button2_variant : '';

// Optional second button (issue 474), the hero's cta2 pattern scoped to cta.
// The pair needs a flex row of its own: .cta__inner is a flex COLUMN on
// full-width (two bare sibling anchors would stack, separated by the full
// --cta-inner-gap) and a `space-between` ROW on inline (they would be flung to
// opposite ends of the band). Both were confirmed by rendering the no-wrapper
// alternative. The wrapper is therefore emitted ONLY when a second button
// exists, so a single-button cta keeps today's markup byte-for-byte.
// is_scalar guard: the write path rejects a non-scalar string prop, but
// restore_composition deliberately never blocks on validation (#233), so an array or
// boolean CAN reach the renderer from a legacy/raw-written history snapshot. A bare
// `!== ''` would treat `false` as a label and emit a button with an empty accessible
// name, and an array would render the literal text "Array" plus a PHP warning. Casting
// after the guard keeps a legitimate "0" label working, which a truthy check would drop.
$has_button2 = is_scalar($button2_text) && (string) $button2_text !== '';

// theme coercion lives in pp_theme_class(); `muted` emits the legacy `--dark` class (#570 DG-4).
$theme_class    = pp_theme_class($theme, 'cta');
$bg_image_class = $background_image ? ' cta--has-bg-image' : '';

// Style slot overrides (per-instance visual customization).
$slot_style = pp_render_style_vars($props['__pp_style'] ?? [], 'cta');

$inline_styles = [];
if ($slot_style) {
    $inline_styles[] = $slot_style;
}
if ($background_image) {
    $inline_styles[] = 'background-image:url(' . pp_esc_image_src($background_image) . ')';
}
$style_attr = $inline_styles ? ' style="' . implode('; ', $inline_styles) . ';"' : '';

?>
<section<?php echo $id ? ' id="' . esc_attr($id) . '"' : ''; ?> class="cta cta--<?php echo esc_attr($layout); ?><?php echo esc_attr($theme_class); ?><?php echo esc_attr($bg_image_class); ?>" data-pp-component="cta"<?php echo $style_attr; ?>>
    <?php if ($background_image) : ?>
        <div class="cta__overlay" aria-hidden="true"></div>
    <?php endif; ?>
    <div class="container">
        <div class="cta__inner">
            <?php // Skip the text block entirely when there is no eyebrow/title/body: a
                  // title-less CTA is the standalone-button pattern (issue 294), so it must
                  // render just the button row — no empty heading and no stray flex gap. ?>
            <?php if ($eyebrow || $title || $body) : ?>
            <div class="cta__text">
                <?php if ($eyebrow) : ?>
                    <span class="cta__eyebrow"><?php echo esc_html($eyebrow); ?></span>
                <?php endif; ?>
                <?php if ($title) : ?>
                    <h2 class="cta__title"><?php echo pp_render_heading_with_accent($title, $title_accent, 'cta__title-accent'); ?></h2>
                <?php endif; ?>

                <?php if ($body) : ?>
                    <?php // Inline-HTML supporting-body prop (#439): a link + light
                          // emphasis (a/strong/em/br) is allowed and sanitized via
                          // pp_kses_inline; block/script tags are stripped. ?>
                    <p class="cta__body"><?php echo pp_kses_inline($body); ?></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

<?php // The wrapper control tags below start at column 0 ON PURPOSE. PHP emits
      // everything outside its tags verbatim, so an INDENTED control tag still
      // prints its own leading spaces even when the branch is false. At column 0
      // there is no leading whitespace to print, and the closing tag eats the
      // trailing newline, so an unset second button adds ZERO bytes and the
      // single-button render stays byte-for-byte identical to pre-474 output
      // (pinned by ComponentPropsTest::testCtaWithoutButton2IsByteIdenticalToBefore). ?>
<?php if ($has_button2) : ?>
            <div class="cta__buttons">
<?php endif; ?>
            <a href="<?php echo esc_url($button_url); ?>" class="cta__button btn<?php echo esc_attr($button_variant_class); ?>">
                <?php echo esc_html($button_text); ?>
            </a>
<?php if ($has_button2) : ?>
                <a href="<?php echo esc_url($button2_url); ?>" class="cta__button cta__button--secondary btn<?php echo esc_attr($button2_variant_class); ?>">
                    <?php echo esc_html($button2_text); ?>
                </a>
            </div>
<?php endif; ?>
        </div>
    </div>
</section>

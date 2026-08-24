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
// #706: guard BOTH raw-value text arguments of pp_render_heading_with_accent()
// (`string $title`, `string $accent`) before they reach the call below. A non-empty
// array is truthy, so the `if ($title)` gate passes on one and the typed call raises a
// TypeError that no caller catches — the whole PUBLIC PAGE 500s. Argument #2 fatals the
// same way on its own, so both props are guarded, not just the title. Guarded at the
// READ because the gates that decide whether the heading renders at all sit upstream of
// the call, so a guarded-away value renders the band with no heading rather than an
// empty one. is_scalar + (string), NOT is_string: only non-scalars ever fataled
// (coercive mode), and the write path stores a scalar title raw (#707), so is_string()
// would silently drop an accepted value. Full reasoning in components/hero/hero.php.
// Local specifics: `$title` also drives the `$eyebrow || $title || $body` text-block
// gate below (named by its shape, not its line — this very guard block displaces line
// numbers, which is how five stale citations elsewhere in the repo got repointed in
// the same change), so a CTA whose only text was a malformed title
// collapses to the standalone-button pattern (issue 294) — an intentional, already
// designed state, not a new one. The background_image guard below is #705's, a
// different prop into a different typed helper; both now sit in this file.
$raw_title        = $props['title']            ?? '';
$title            = is_scalar($raw_title) ? (string) $raw_title : '';
$raw_title_accent = $props['title_accent']     ?? '';
$title_accent     = is_scalar($raw_title_accent) ? (string) $raw_title_accent : '';
$eyebrow          = $props['eyebrow']          ?? '';
$body             = $props['body']             ?? '';
$button_text      = $props['button_text']      ?? 'Get Started';
// ── #730: THE CANONICAL RAW-VALUE GUARD FOR LINK PROPS INTO core's esc_url() ──
//
// THE CANONICAL EXPLANATION FOR THE esc_url() HALF OF #730 LIVES HERE. hero, section
// and grid carry the same two-line guard with a pointer back to this block; keep the
// reasoning in one place so a correction lands once. Same idiom as #641 (image_url),
// #705 (background_image) and #706 (title), ratified as the family standard at D-B.
//
// WHAT IS DIFFERENT ABOUT THIS ONE, and it is the reason #730 exists at all: the
// fataling function is not one of the theme's own typed helpers. It is WordPress
// CORE's esc_url(), which declares NO parameter type:
//
//   wp-includes/formatting.php   function esc_url( $url, $protocols = null, $_context = 'display' )
//
// An untyped parameter reads as safe and is not. Core reaches a string-only PHP
// builtin before it makes any sanitization decision, so the TypeError comes from
// INSIDE core rather than from its signature:
//
//   esc_url() ──> ltrim( $url )   ──> TypeError on array AND on object
//
// Measured on real WordPress 7.0 / PHP 8.3.31 (one fresh process per case, #709):
//   esc_url( ['x'] )      TypeError: ltrim(): Argument #1 ($string) must be of type string, array given
//   esc_url( new Foo )    TypeError: ltrim(): Argument #1 ($string) must be of type string, Foo given
//   esc_html/esc_attr     NO fatal — they render the literal word `Array` plus an E_WARNING
//
// That last row is why this issue's inventory is exactly the esc_url and wp_kses_post
// sinks and not "every escaper": esc_html/esc_attr coerce-and-warn, which is the
// separate, still-open #736/#721 class and deliberately NOT fixed here.
//
// templates/composition.php calls pp_get_component() with no try/catch, so ONE
// malformed stored value returns a whole-page 500 instead of a band missing its link.
//
// NO GATE PROTECTS THESE TWO. Unlike background_image (#705), which at least had a
// truthiness gate an array happened to pass, cta's primary button is rendered
// UNCONDITIONALLY — the anchor below has no `if` around it at all. So `button_url`
// reaches ltrim() on every single cta render, and even an EMPTY array fatals here
// while it would have been gated away elsewhere. Measured both ways.
//
// is_scalar, NOT is_string, per the D-B rationale: PHP runs COERCIVE here (no
// declare(strict_types) anywhere in this theme), so only NON-SCALARS ever fataled. A
// stored `42` already coerced inside ltrim() and painted `href="42"`, so is_string()
// would silently drop a value that renders fine. #707 narrowed the WRITE path — a
// `type: "string"` prop no longer accepts a non-string scalar — but that gates writes,
// not storage, and storage is this guard's entire subject: a pre-#707 composition, a
// restored snapshot (#233) and a raw meta write all still hold `42`, and `42` still
// has to paint. The front door closing does not empty the room. An object with a __toString() is
// NOT a scalar and therefore degrades too — deliberate, and stated because the
// tempting "fix" of admitting Stringable would move the safety boundary rather than
// hold it: core's ltrim() would accept it, but nothing here can vouch for what its
// __toString() does, and stored data cannot carry an object through the JSON channel
// at all (see below).
//
// THE (string) CAST IS BEHAVIOURALLY INERT AT THIS SITE, and that is worth stating
// because it is NOT inert everywhere in this change. A cast can only matter where the
// cast result meets a GATE. Neither anchor below is gated on the url — the second
// button's gate keys on button2_TEXT — so nothing here can flip. Measured across
// '', '0', 'x', '/go', 0, 42, 3.14, 0.0, -0.0, true, false: raw and cast produce
// byte-identical renders at both call sites. The two sites in this change where a
// cast DOES meet a gate are section.php (panel_cta_url, `!== ''`) and the truthiness
// pair grid/embed; each documents its own handling there.
//
// STORED data is the point. The write path rejects a non-scalar, but it gates WRITES,
// not storage: restore_composition reports without blocking (#233), a composition
// authored before the rule still carries the value, and a raw _pp_composition meta
// write is not gated at all. Nothing here rewrites the store — the value is read, not
// migrated, and _pp_composition_findings() still reports it to the operator.
//
// TWO STORAGE CHANNELS, and they do not carry the same shapes. Normal storage is a
// JSON string, so json_decode(assoc) can only ever produce null/bool/int/float/string/
// array — an OBJECT is unreachable through it. But pp_get_composition_result()
// (lib/wp.php) also accepts an already-decoded ARRAY from meta, and WordPress
// serializes array-valued meta with PHP serialize(), which DOES carry objects. So the
// object row of the matrix above is reachable, through that second channel only. Both
// channels are pinned in tests/StoredLinkAndRichTextRenderGuardTest.php.
$raw_button_url   = $props['button_url']       ?? '#';
$button_url       = is_scalar($raw_button_url) ? (string) $raw_button_url : '';
$button2_text     = $props['button2_text']     ?? '';
// Second button, same boundary, same reasoning as $button_url above. Guarded on its
// own rather than folded in, because argument-level coverage is what the drift catcher
// in tests/InvariantTest.php asserts: a per-file guard that covered only the first url
// would leave this anchor carrying a raw stored value into ltrim().
$raw_button2_url  = $props['button2_url']      ?? '#';
$button2_url      = is_scalar($raw_button2_url) ? (string) $raw_button2_url : '';
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
// background. #707 has since narrowed the WRITE path so `background_image: 42` is
// refused, but that gates writes and not storage — a pre-#707 composition, a restore
// (#233) and a raw meta write all still hold it, and it still has to paint — so
// is_string() would silently drop a value that renders correctly today. Stated
// honestly, one half of the #641 rationale does NOT carry over:
// background_image has no image_id companion (it is CSS background-image, not an
// <img>), so there is no resolvable attachment to discard here. The
// stored-scalar half carries on its own and is sufficient.
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
// correct. #707 has since tightened what the write path ACCEPTS, and this guard was
// deliberately written not to prejudge that — which is why nothing here had to change
// when it landed: closing the front door does not empty the room, and the stored values
// this renders arrived before the door closed or through a channel that bypasses it.
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
$slot_style = pp_render_style_vars($style, 'cta');

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

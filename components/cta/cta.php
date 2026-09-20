<?php
/**
 * components/cta/cta.php
 *
 * Call-to-action band. Two layouts: full-width (centered) and inline (flex row).
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
// gate below, so a CTA whose only text was a malformed title collapses to the
// standalone-button pattern (issue 294) — an intentional, already designed state, not a
// new one.
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
// #705 (background_image, whose own canonical block moved to components/stats/stats.php
// when this component's `background_image` prop retired at #1026) and #706 (title),
// ratified as the family standard at D-B.
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
// NO GATE PROTECTS THE FIRST OF THESE. cta's primary button is rendered
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
// has to paint. The front door closing does not empty the room. An object with a
// __toString() is NOT a scalar and therefore degrades too — deliberate, and stated
// because the tempting "fix" of admitting Stringable would move the safety boundary
// rather than hold it: core's ltrim() would accept it, but nothing here can vouch for
// what its __toString() does, and stored data cannot carry an object through the JSON
// channel at all (see below).
//
// THE (string) CAST IS BEHAVIOURALLY INERT AT THIS SITE, and that is worth stating
// because it is NOT inert everywhere in this family. A cast can only matter where the
// cast result meets a GATE. Neither anchor below is gated on the url — the second
// button's gate keys on button2_TEXT — so nothing here can flip. Measured across
// '', '0', 'x', '/go', 0, 42, 3.14, 0.0, -0.0, true, false: raw and cast produce
// byte-identical renders at both call sites. The sites where a cast DOES meet a gate are
// section.php (panel_cta_url, `!== ''`) and the truthiness pair grid.php (link_url) and
// embed.php — each documents its own handling there. The enumeration is worth keeping
// complete: an earlier draft narrowed it to section.php alone while widening the claim from
// "in this change" to "in this FAMILY", which made it false for two components that still
// self-document as cast-meets-gate sites.
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

$allowed_layouts = ['full-width', 'inline'];
if (!in_array($layout, $allowed_layouts, true)) {
    $layout = 'full-width';
}

// Optional second button (issue 474), the hero's cta2 pattern scoped to cta.
// The pair needs a flex row of its own: .cta__inner is a flex COLUMN on
// full-width (two bare sibling anchors would stack, separated by the full
// `inner` role gap) and a `space-between` ROW on inline (they would be flung to
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

// ── v2: what stayed a prop, and why ─────────────────────────────────────────
//
// `theme`, `background_image`, `button_variant` and `button2_variant` are GONE. Each
// was a bundle of designable values — a set of band colours, a painted background, two
// sets of button colours — which is exactly what the UDC expresses directly: the
// `_band` role's `background` group plus `typography.color` on the text roles, `_band`
// -> `background.image` + `background.overlay` (ruling A2), and the `button` /
// `button-secondary` roles or a button preset.
//
// WHAT REMAINS A PROP, AND WHY — RESTATED AT #1084, WHEN THE LAYOUT GROUP ARRIVED.
// This comment used to read "the taxonomy carries no layout group". It does now, so
// the reason `layout` stays is the one that was always underneath it: the prop
// selects a MECHANISM BUNDLE — `.cta--inline` sets a direction, a packing and an
// alignment together at >=768px only, and `.cta--full-width` centres the text block
// and its buttons — while a role carries breakpoints and states but no variant
// dimension. The group retunes the bundle's values: `inner.layout.orientation` and
// `buttons.layout.justify` are authored, unlayered, and outrank these rules in
// `pp-v1`. Same reading as hero's `split_ratio` and section's `body_items_align`.
//
// NO `refuse_props_when`, AND THAT IS A MEASURED ANSWER RATHER THAN AN OMISSION. The
// rebuild contract says to check for props that are declared, well-typed and stored but
// paint nothing in the configuration the band is in. cta has none: `$has_button2` above
// is the second button's ONLY gate, and `.cta__buttons` renders inside `.cta__inner` on
// BOTH layouts, so no cta prop is layout-dead. The genuine inert pairs here are
// `button2_url` without `button2_text` and `title_accent` without `title` — and the
// shared `applies_when` grammar cannot express either, because it has no negation:
// pp_applies_when_clause_errors() bounds predicates to `equals` / `in` / `present`, and
// pp_applies_when_clause_met() reads `present` by KEY and ignores its value, so
// `present: false` is a synonym rather than an inverse. Widening a shared grammar on
// behalf of one component is not this rebuild's call, so it is filed as #1037 rather than
// done here. (The issue number is cited on purpose: an earlier draft said only "filed
// instead", which is unverifiable from the repo and leaves a reader no way to find it.)

// ── v2: the band's styling identity ─────────────────────────────────────────
//
// Where v1 read a `__pp_style` map of 40 slots and painted it into an inline `style`
// attribute — plus an inline `background-image` built from `background_image`, and a
// `.cta__overlay` scrim element — this emits one attribute and nothing else:
// `data-pp-band`. Every designable value for this band is in a scoped block in the
// document head, keyed on that attribute (lib/udc.php). No inline style means no
// specificity cliff — a band's rules and the stylesheet's structural rules sit at
// comparable weight and resolve in source order, which is what makes the cascade a
// cascade. The scrim is `background.overlay`, composed into the same background layer
// list by the engine, so the extra element is gone too.
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

// THE FOCUS RING FOLLOWS THE OVERLAY, NOT A LAYOUT OR THEME CLASS (#986, ported here at
// #1026). v1 keyed the on-overlay focus outline to the background-image variant class,
// derived from the `background_image` prop. That prop is gone and so is the class (its name
// is deliberately not spelled here: SchemaValidationTest derives this component's
// `variant_classes` by scanning THIS FILE for root modifiers, so a retired modifier named
// even inside a comment reads as one the template can still emit). But the state
// it marked is not: a band painting a scrim still needs a focus outline the eye can find
// against it, and `--color-accent` measures 1.17:1 over the worst-case scrim.
// `data-pp-band-overlay` is emitted by the ENGINE, which is the only thing that knows a
// scrim is being painted, and only when an image and an overlay are both present. Same
// posture as the reduced-motion guard under ruling A3: an accessibility affordance is
// structural, emitted rather than authored, and not something an author can forget to
// switch on.
//
// Its inverted-theme sibling has NO v2 successor and is deleted rather than ported,
// because there is nothing left to key it on: a band's darkness is now an author's
// choice in `_band.background.fill`, which the engine cannot inspect. That is the same
// answer #986 gave for `.hero--cover`'s re-coloured title — "v2 has no variant-scoped
// role defaults and does not guess" — and it is why a dark cta owns its own contrast.
$overlay_attr = !empty($props['__pp_udc_overlay']) ? ' data-pp-band-overlay' : '';

?>
<section<?php echo $id ? ' id="' . esc_attr($id) . '"' : ''; ?> class="cta cta--<?php echo esc_attr($layout); ?>" data-pp-component="cta"<?php echo $band_attr; ?><?php echo $overlay_attr; ?>>
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

<?php // THE PRIMARY CARRIES ITS OWN MODIFIER (`cta__button--primary`) so the `button` role
      // has a FLAT class selector, exactly as hero's primary does (#986). A role selector's
      // charset admits no `:`, so `.cta__button:not(.cta__button--secondary)` is not
      // expressible — and WITHOUT the modifier `.cta__button` matches BOTH anchors, which
      // would make a role documented as "the primary button" silently style the pair for
      // every group `button-secondary` does not itself declare (spacing, sizing, motion).
      // It costs no emitted CSS: `button` declares no defaults, so nothing is emitted for
      // that selector until an author writes a value on it.
      //
      // The wrapper control tags below start at column 0 ON PURPOSE. PHP emits
      // everything outside its tags verbatim, so an INDENTED control tag still
      // prints its own leading spaces even when the branch is false. At column 0
      // there is no leading whitespace to print, and the closing tag eats the
      // trailing newline, so an unset second button adds ZERO bytes and the
      // single-button render stays byte-for-byte identical to pre-474 output
      // (pinned by ComponentPropsTest::testCtaWithoutButton2IsByteIdenticalToBefore). ?>
<?php if ($has_button2) : ?>
            <div class="cta__buttons">
<?php endif; ?>
            <a href="<?php echo esc_url($button_url); ?>" class="cta__button cta__button--primary btn">
                <?php echo esc_html($button_text); ?>
            </a>
<?php if ($has_button2) : ?>
                <a href="<?php echo esc_url($button2_url); ?>" class="cta__button cta__button--secondary btn">
                    <?php echo esc_html($button2_text); ?>
                </a>
            </div>
<?php endif; ?>
        </div>
    </div>
</section>

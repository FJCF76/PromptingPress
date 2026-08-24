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
$theme = $props['theme'] ?? 'default';

// theme coercion lives in pp_theme_class(); `muted` emits the legacy `--dark` class (#570 DG-4).
$theme_class = pp_theme_class($theme, 'embed');

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
$slot_style = pp_render_style_vars($style, 'embed');
$style_attr = $slot_style ? ' style="' . $slot_style . ';"' : '';
?>
<section<?php echo $id ? ' id="' . esc_attr($id) . '"' : ''; ?> class="embed<?php echo esc_attr($theme_class); ?>" data-pp-component="embed"<?php echo $style_attr; ?>>
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

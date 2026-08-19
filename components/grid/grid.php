<?php
/**
 * components/grid/grid.php
 *
 * Card grid for discrete content objects (posts, features, team members, etc.).
 * NOT for icon-in-circle decoration. Every card must represent real content.
 * Props: see schema.json
 *
 * @var array $props
 */

$id            = $props['id']            ?? '';
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
// Local specifics: `$title` drives TWO gates here — the `grid__header` wrapper gate
// below (`$title || $eyebrow || $subheading`) and the heading itself — and the read is
// upstream of both, so a grid whose only header content was a malformed title emits no
// header wrapper at all. Distinct from the per-card `$item['title']` read further down,
// which reaches esc_html() and not this helper, so it is deliberately NOT guarded here.
$raw_title         = $props['title']         ?? '';
$title             = is_scalar($raw_title) ? (string) $raw_title : '';
$raw_title_accent  = $props['title_accent']  ?? '';
$title_accent      = is_scalar($raw_title_accent) ? (string) $raw_title_accent : '';
$eyebrow       = $props['eyebrow']       ?? '';
$subheading    = $props['subheading']    ?? '';
$title_align = $props['title_align'] ?? 'start';
$items   = $props['items']   ?? [];
$layout  = $props['layout']  ?? 'cards';
$theme   = $props['theme']   ?? 'default';
$card_emphasis = $props['card_emphasis'] ?? 'featured';

$allowed_layouts = ['cards', 'steps'];
if (!in_array($layout, $allowed_layouts, true)) {
    $layout = 'cards';
}

$allowed_card_emphasis = ['featured', 'uniform'];
if (!in_array($card_emphasis, $allowed_card_emphasis, true)) {
    $card_emphasis = 'featured';
}

$allowed_title_aligns = ['start', 'center'];
if (!in_array($title_align, $allowed_title_aligns, true)) {
    $title_align = 'start';
}

// Explicit desktop column-count override (issue 379). Write-time validation
// (pp_validate_composition_errors) already rejects out-of-range/non-integer
// values, so this is a defensive coercion for raw-written state (mirroring the
// layout/card_emphasis in_array guards above; theme via pp_theme_class, #442): only an integer 1-4 emits
// the data-pp-columns attribute the CSS reads; anything else falls through to
// the auto-by-count grain, so unset output stays byte-identical.
// $is_steps is computed below; forward-declare the steps check here so a forced
// column count is inert on steps at the RENDER layer too, not only via the CSS
// :not(.grid--steps) scope — steps keeps its fixed process grain, so its markup
// stays byte-identical (no dead data-pp-columns attribute leaks onto it).
$columns_is_steps = ($layout === 'steps');
$columns_raw = $props['columns'] ?? '';
$columns = (is_int($columns_raw) || (is_string($columns_raw) && preg_match('/^\d+$/', $columns_raw)))
    ? (int) $columns_raw
    : 0;
$columns_attr = (!$columns_is_steps && $columns >= 1 && $columns <= 4)
    ? ' data-pp-columns="' . esc_attr((string) $columns) . '"'
    : '';
$header_align_class = $title_align === 'center' ? ' grid__header--center' : '';

$is_steps      = $layout === 'steps';
$layout_class  = $is_steps ? ' grid--steps' : '';
// theme coercion lives in pp_theme_class(); `muted` emits the legacy `--dark` class (#570 DG-4).
$theme_class   = pp_theme_class($theme, 'grid');
// 'uniform' opts the first card out of the featured emphasis so every card
// renders identically (issue 226). Default 'featured' emits no class, keeping
// existing pages byte-identical. The featured CSS selectors carry a
// :not(.grid--uniform) guard, so this class makes the first card fall through
// to the shared all-cards rules.
$emphasis_class = $card_emphasis === 'uniform' ? ' grid--uniform' : '';

// Item image treatment (issue 380). 'icon' renders each card image at a small
// fixed icon size (--grid-item-icon-size) above the title instead of the default
// 16:9 cover banner. Write-time validation (pp_validate_composition_errors) rejects
// invalid values via the schema strict-enum check; this in_array guard mirrors the
// layout/theme/card_emphasis guards above for raw-written state, so an invalid value
// falls through to 'banner' and output stays byte-identical. Icon treatment is a
// cards concept: steps renders no item images, so it is inert on steps (no dead
// class leaks onto steps markup), keeping steps byte-identical too. Default 'banner'
// emits no class, so existing pages render identically.
$image_treatment = $props['image_treatment'] ?? 'banner';
$allowed_image_treatments = ['banner', 'icon'];
if (!in_array($image_treatment, $allowed_image_treatments, true)) {
    $image_treatment = 'banner';
}
$image_treatment_class = ($image_treatment === 'icon' && !$is_steps) ? ' grid--image-icon' : '';

// Style slot overrides (per-instance visual customization). The card link/button
// follows the card's --grid-item-text-align via the derived --pp-grid-link-align
// plumbing property (issue 361), so a centered card centers its link too; it is
// appended here at grid level and per card below so cascade proximity holds.
$grid_style_parts = [];
$slot_style       = pp_render_style_vars($props['__pp_style'] ?? [], 'grid');
if ($slot_style !== '') {
    $grid_style_parts[] = $slot_style;
}
$grid_link_align = pp_grid_link_align_decl($props['__pp_style'] ?? []);
if ($grid_link_align !== '') {
    $grid_style_parts[] = $grid_link_align;
}
$style_attr = $grid_style_parts ? ' style="' . implode('; ', $grid_style_parts) . ';"' : '';

?>
<section<?php echo $id ? ' id="' . esc_attr($id) . '"' : ''; ?> class="grid<?php echo esc_attr($layout_class); ?><?php echo esc_attr($theme_class); ?><?php echo esc_attr($emphasis_class); ?><?php echo esc_attr($image_treatment_class); ?>" data-pp-component="grid"<?php echo $style_attr; ?>>
    <div class="container">

        <?php if ($title || $eyebrow || $subheading) : ?>
            <div class="grid__header<?php echo esc_attr($header_align_class); ?>">
                <?php if ($eyebrow) : ?>
                    <span class="grid__eyebrow"><?php echo esc_html($eyebrow); ?></span>
                <?php endif; ?>
                <?php if ($title) : ?>
                    <h2 class="grid__heading"><?php echo pp_render_heading_with_accent($title, $title_accent, 'grid__heading-accent'); ?></h2>
                <?php endif; ?>
                <?php if ($subheading) : ?>
                    <p class="grid__subheading"><?php echo esc_html($subheading); ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($items)) : ?>
            <ul class="grid__list" role="list" data-pp-count="<?php echo esc_attr(count($items)); ?>"<?php echo $columns_attr; ?>>
                <?php foreach ($items as $index => $item) :
                    $item_number = $item['number']    ?? (string)($index + 1);
                    $item_title  = $item['title']     ?? '';
                    $item_text   = $item['text']      ?? '';
                    $bullets     = is_array($item['bullets'] ?? null) ? $item['bullets'] : [];
                    // #641: guard BOTH raw-value arguments of pp_render_responsive_image()
                    // (`string $url`, `string $alt`) before they reach it. A non-empty array
                    // is truthy, so the `if ($image_url && !$is_steps)` gate below passes on
                    // one and the typed call raises a TypeError that no caller catches — the
                    // whole PUBLIC PAGE 500s. is_scalar + (string), NOT is_string: only
                    // non-scalars ever fataled (coercive mode), and the write path stores a
                    // scalar image_url raw (#707), so is_string() would silently drop an
                    // accepted value AND its resolvable image_id attachment with it. Full
                    // reasoning in components/logos/logos.php. Same STORED-data reachability
                    // as the image_id guard below (#233 restore, pre-rule compositions, raw
                    // meta). Here a guarded-away image means the card renders its body with
                    // no image wrap, exactly as an empty image_url already does.
                    $raw_image_url = $item['image_url'] ?? '';
                    $image_url     = is_scalar($raw_image_url) ? (string) $raw_image_url : '';
                    $raw_image_alt = $item['image_alt'] ?? '';
                    $image_alt     = is_scalar($raw_image_alt) ? (string) $raw_image_alt : '';
                    // Responsive card image (issue 584): the attachment-ID companion the
                    // hero, section and logos images already carry.
                    // is_numeric() BEFORE the (int) cast, deliberately. `(int)` is not a
                    // rejection: `(int) ['attachment_id' => 42]` and `(int) true` both
                    // evaluate to 1, so the plain cast would render attachment ID 1 —
                    // usually the site's first upload — and discard the author's image_url.
                    // #614 closed the WRITE path (a nested field's declared scalar type is
                    // enforced now), but this guard is what covers STORED data: the
                    // validator gates writes, and restore_composition reports without
                    // blocking (#233), so a composition written before that rule still
                    // reaches this line. Guarding at the read makes a malformed value mean
                    // "no attachment", which is what every other bad value already means.
                    $raw_image_id = $item['image_id'] ?? 0;
                    $image_id     = is_numeric($raw_image_id) ? (int) $raw_image_id : 0;
                    $link_url    = $item['link_url']  ?? '';
                    $link_text   = $item['link_text'] ?? 'Read more';
                    $text_role   = $item['text_role'] ?? '';
                    $allowed_text_roles = ['mono', 'meta', 'label', 'kicker'];
                    $text_role_class = in_array($text_role, $allowed_text_roles, true) ? ' text-' . $text_role : '';

                    // Per-item style overrides (issue 306): render this card's `style`
                    // map as inline custom properties on the .grid__item element,
                    // validated against the SAME grid style slots as grid-level style.
                    // The consuming CSS reads var(--slot, fallback), so a per-item slot
                    // set here overrides the grid-level value by cascade proximity.
                    // A per-card --grid-item-text-align also derives the card's
                    // --pp-grid-link-align companion (issue 361); appended on the
                    // .grid__item so it overrides any grid-level companion by
                    // cascade proximity, exactly like the text-align slot itself.
                    $item_style       = is_array($item['style'] ?? null) ? $item['style'] : [];
                    // Item scope (#579): only the card-scoped (item_eligible) slots
                    // may be emitted here, matching what the write path accepts.
                    $item_style_vars  = pp_render_style_vars($item_style, 'grid', true);
                    $item_link_align  = pp_grid_link_align_decl($item_style);
                    $item_style_parts = [];
                    if ($item_style_vars !== '') {
                        $item_style_parts[] = $item_style_vars;
                    }
                    if ($item_link_align !== '') {
                        $item_style_parts[] = $item_link_align;
                    }
                    $item_style_attr = $item_style_parts ? ' style="' . implode('; ', $item_style_parts) . ';"' : '';
                ?>
                    <li class="grid__item"<?php echo $item_style_attr; ?>>
                        <?php if ($is_steps) : ?>
                            <span class="grid__step-number"><?php echo esc_html($item_number); ?></span>
                        <?php endif; ?>

                        <?php if ($image_url && !$is_steps) : ?>
                            <div class="grid__item-image-wrap">
                                <?php // Responsive image (issue 584), the same helper
                                      // logos.php, hero.php and section.php
                                      // already call. A resolvable image_id renders through
                                      // wp_get_attachment_image() with real srcset/sizes;
                                      // unset or unresolvable, the helper emits exactly
                                      // today's single-source <img> — same src, alt, class
                                      // and loading, so the paint is unchanged either way.
                                      // The `$image_url` gate above is deliberately kept
                                      // (matching logos.php): image_id is a companion to a
                                      // URL, never a replacement for one. ?>
                                <?php echo pp_render_responsive_image($image_url, $image_alt, 'grid__item-image', 'lazy', $image_id); ?>
                            </div>
                        <?php endif; ?>

                        <div class="grid__item-body">
                            <?php if ($item_title) : ?>
                                <h3 class="grid__item-title"><?php echo esc_html($item_title); ?></h3>
                            <?php endif; ?>

                            <?php if ($item_text) : ?>
                                <?php // Inline-HTML supporting-text prop (#439): a/strong/em/br
                                      // allowed and sanitized; block/script tags stripped. The
                                      // link sits inside the LIGHT card, so it keeps --color-accent. ?>
                                <p class="grid__item-text<?php echo esc_attr($text_role_class); ?>"><?php echo pp_kses_inline($item_text); ?></p>
                            <?php endif; ?>

                            <?php if (!empty($bullets)) : ?>
                                <ul class="grid__item-bullets">
                                    <?php foreach ($bullets as $bullet) :
                                        if (!is_string($bullet) || $bullet === '') {
                                            continue;
                                        }
                                    ?>
                                        <li class="grid__item-bullet"><?php echo esc_html($bullet); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>

                            <?php if ($link_url) : ?>
                                <a href="<?php echo esc_url($link_url); ?>" class="grid__item-link">
                                    <?php echo esc_html($link_text); ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else : ?>
            <p class="grid__empty text-muted">Nothing here yet.</p>
        <?php endif; ?>

    </div>
</section>

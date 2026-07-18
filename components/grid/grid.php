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
$title         = $props['title']         ?? '';
$title_accent  = $props['title_accent']  ?? '';
$eyebrow       = $props['eyebrow']       ?? '';
$subheading    = $props['subheading']    ?? '';
$heading_align = $props['heading_align'] ?? 'start';
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

$allowed_themes = ['default', 'dark', 'inverted'];
if (!in_array($theme, $allowed_themes, true)) {
    $theme = 'default';
}

$allowed_heading_aligns = ['start', 'center'];
if (!in_array($heading_align, $allowed_heading_aligns, true)) {
    $heading_align = 'start';
}

// Explicit desktop column-count override (issue 379). Write-time validation
// (pp_validate_composition_errors) already rejects out-of-range/non-integer
// values, so this is a defensive coercion for raw-written state (mirroring the
// layout/theme/card_emphasis in_array guards above): only an integer 1-4 emits
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
$header_align_class = $heading_align === 'center' ? ' grid__header--center' : '';

$is_steps      = $layout === 'steps';
$layout_class  = $is_steps ? ' grid--steps' : '';
$theme_class   = $theme !== 'default' ? ' grid--' . $theme : '';
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
                    $image_url   = $item['image_url'] ?? '';
                    $image_alt   = $item['image_alt'] ?? '';
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
                    $item_style_vars  = pp_render_style_vars($item_style, 'grid');
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
                            <span class="pp-step-number"><?php echo esc_html($item_number); ?></span>
                        <?php endif; ?>

                        <?php if ($image_url && !$is_steps) : ?>
                            <div class="grid__item-image-wrap">
                                <img
                                    src="<?php echo pp_esc_image_src($image_url); ?>"
                                    alt="<?php echo esc_attr($image_alt); ?>"
                                    class="grid__item-image"
                                    loading="lazy"
                                >
                            </div>
                        <?php endif; ?>

                        <div class="grid__item-body">
                            <?php if ($item_title) : ?>
                                <h3 class="grid__item-title"><?php echo esc_html($item_title); ?></h3>
                            <?php endif; ?>

                            <?php if ($item_text) : ?>
                                <p class="grid__item-text<?php echo esc_attr($text_role_class); ?>"><?php echo esc_html($item_text); ?></p>
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

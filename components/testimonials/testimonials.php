<?php
/**
 * components/testimonials/testimonials.php
 *
 * Customer quotes with attribution, for social-proof sections.
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
// Local specifics: `$title` drives the `testimonials__header` wrapper gate below
// (`$title || $eyebrow || $subheading`) as well as the heading, and the read is upstream
// of both, so the quotes below still render — a malformed title costs the header, never
// the testimonials.
$raw_title         = $props['title']         ?? '';
$title             = is_scalar($raw_title) ? (string) $raw_title : '';
$raw_title_accent  = $props['title_accent']  ?? '';
$title_accent      = is_scalar($raw_title_accent) ? (string) $raw_title_accent : '';
$eyebrow       = $props['eyebrow']       ?? '';
$subheading    = $props['subheading']    ?? '';
$title_align = $props['title_align'] ?? 'start';
$items   = $props['items']   ?? [];
$layout  = $props['layout']  ?? 'grid';
$theme   = $props['theme']   ?? 'default';

$allowed_layouts = ['grid', 'stack'];
if (!in_array($layout, $allowed_layouts, true)) {
    $layout = 'grid';
}

$allowed_title_aligns = ['start', 'center'];
if (!in_array($title_align, $allowed_title_aligns, true)) {
    $title_align = 'start';
}
$header_align_class = $title_align === 'center' ? ' testimonials__header--center' : '';

$is_stack      = $layout === 'stack';
$layout_class  = $is_stack ? ' testimonials--stack' : '';
// theme coercion lives in pp_theme_class(); `muted` emits the legacy `--dark` class (#570 DG-4).
$theme_class   = pp_theme_class($theme, 'testimonials');

// Style slot overrides (per-instance visual customization).
$slot_style = pp_render_style_vars($props['__pp_style'] ?? [], 'testimonials');
$style_attr = $slot_style ? ' style="' . $slot_style . ';"' : '';

?>
<section<?php echo $id ? ' id="' . esc_attr($id) . '"' : ''; ?> class="testimonials<?php echo esc_attr($layout_class); ?><?php echo esc_attr($theme_class); ?>" data-pp-component="testimonials"<?php echo $style_attr; ?>>
    <div class="container">

        <?php if ($title || $eyebrow || $subheading) : ?>
            <div class="testimonials__header<?php echo esc_attr($header_align_class); ?>">
                <?php if ($eyebrow) : ?>
                    <span class="testimonials__eyebrow"><?php echo esc_html($eyebrow); ?></span>
                <?php endif; ?>
                <?php if ($title) : ?>
                    <h2 class="testimonials__heading"><?php echo pp_render_heading_with_accent($title, $title_accent, 'testimonials__heading-accent'); ?></h2>
                <?php endif; ?>
                <?php if ($subheading) : ?>
                    <p class="testimonials__subheading"><?php echo esc_html($subheading); ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php
        // issue 581 (A-28): the list carried data-pp-count and no CSS rule read it. grid
        // emits the same attribute and DOES select on it at five rules, so this was not a
        // shared convention — just an inert emission. Removed rather than wired: inventing
        // count-keyed testimonial layouts is a design decision, not a cleanup. The comment
        // lives inside this PHP block on purpose, so removing the attribute is the ONLY
        // change to the emitted markup: an inline PHP comment tag between the markup
        // lines would leave its own indentation behind in the rendered output.
        if (!empty($items)) : ?>
            <div class="testimonials__list">
                <?php foreach ($items as $item) :
                    $quote     = $item['quote']     ?? '';
                    $author    = $item['author']    ?? '';
                    $role      = $item['role']      ?? '';
                    $company   = $item['company']   ?? '';
                    // #641: guard BOTH raw-value arguments of pp_render_responsive_image()
                    // (`string $url`, `string $alt`) before they reach it. A non-empty array
                    // is truthy, so both the `$author || $meta || $image_url` attribution
                    // gate and the `if ($image_url)` avatar gate below pass on one, and the
                    // typed call raises a TypeError that no caller catches — the whole PUBLIC
                    // PAGE 500s. is_scalar + (string), NOT is_string: only non-scalars ever
                    // fataled (coercive mode), and the write path stores a scalar image_url
                    // raw (#707), so is_string() would silently drop an accepted value AND
                    // its resolvable image_id attachment with it. Full reasoning in
                    // components/logos/logos.php. Same STORED-data reachability as the
                    // image_id guard below (#233 restore, pre-rule compositions, raw meta).
                    // Here a guarded-away image means the quote and its attribution still
                    // render, just with no avatar, as an empty image_url already does.
                    $raw_image_url = $item['image_url'] ?? '';
                    $image_url = is_scalar($raw_image_url) ? (string) $raw_image_url : '';
                    $raw_image_alt = $item['image_alt'] ?? '';
                    $image_alt = is_scalar($raw_image_alt) ? (string) $raw_image_alt : '';
                    // Responsive avatar (issue 584): the attachment-ID companion the
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
                    if (!$quote) continue;

                    $meta = '';
                    if ($role && $company) {
                        $meta = $role . ', ' . $company;
                    } elseif ($role) {
                        $meta = $role;
                    } elseif ($company) {
                        $meta = $company;
                    }
                ?>
                    <figure class="testimonials__item">
                        <blockquote class="testimonials__quote">
                            <?php // Inline-HTML supporting-text prop (#439): a/strong/em/br
                                  // allowed and sanitized; block/script tags stripped. ?>
                            <p><?php echo pp_kses_inline($quote); ?></p>
                        </blockquote>
                        <?php if ($author || $meta || $image_url) : ?>
                            <figcaption class="testimonials__attribution">
                                <?php if ($image_url) : ?>
                                    <?php // Responsive avatar (issue 584), the same helper
                                          // logos.php, hero.php and section.php
                                          // already call. A resolvable image_id renders
                                          // through wp_get_attachment_image() with real
                                          // srcset/sizes; unset or unresolvable, the helper
                                          // emits exactly today's single-source <img> —
                                          // same src, alt, class and loading. The painted box
                                          // is unchanged either way: .testimonials__avatar is
                                          // a fixed 2.75rem (44px) circle with object-fit:
                                          // cover. NOTE: the shared helper hardcodes the
                                          // `large` size and passes no `sizes`, so WP's
                                          // default 100vw descriptor means the browser does
                                          // not yet pick a small candidate for a box this
                                          // size — a pre-existing property of the helper that
                                          // this call site inherits rather than introduces.
                                          // The `$image_url` gate is deliberately kept
                                          // (matching logos.php): image_id is a companion
                                          // to a URL, never a replacement for one. ?>
                                    <?php echo pp_render_responsive_image($image_url, $image_alt, 'testimonials__avatar', 'lazy', $image_id); ?>
                                <?php endif; ?>
                                <span class="testimonials__attribution-text">
                                    <?php if ($author) : ?>
                                        <span class="testimonials__author"><?php echo esc_html($author); ?></span>
                                    <?php endif; ?>
                                    <?php if ($meta) : ?>
                                        <span class="testimonials__meta"><?php echo esc_html($meta); ?></span>
                                    <?php endif; ?>
                                </span>
                            </figcaption>
                        <?php endif; ?>
                    </figure>
                <?php endforeach; ?>
            </div>
        <?php else : ?>
            <p class="testimonials__empty text-muted">No testimonials yet.</p>
        <?php endif; ?>

    </div>
</section>

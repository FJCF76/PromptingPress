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
$title         = $props['title']         ?? '';
$title_accent  = $props['title_accent']  ?? '';
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
// theme coercion + the deprecated 'dark' -> 'muted' alias live in pp_theme_class() (#442).
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

        <?php if (!empty($items)) : ?>
            <div class="testimonials__list" data-pp-count="<?php echo esc_attr(count($items)); ?>">
                <?php foreach ($items as $item) :
                    $quote     = $item['quote']     ?? '';
                    $author    = $item['author']    ?? '';
                    $role      = $item['role']      ?? '';
                    $company   = $item['company']   ?? '';
                    $image_url = $item['image_url'] ?? '';
                    $image_alt = $item['image_alt'] ?? '';
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
                                    <img
                                        src="<?php echo pp_esc_image_src($image_url); ?>"
                                        alt="<?php echo esc_attr($image_alt); ?>"
                                        class="testimonials__avatar"
                                        loading="lazy"
                                    >
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

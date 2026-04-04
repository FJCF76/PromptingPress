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

$id      = $props['id']      ?? '';
$title   = $props['title']   ?? '';
$items   = $props['items']   ?? [];
$variant = $props['variant'] ?? 'default';
$theme   = $props['theme']   ?? 'default';

$allowed_variants = ['default', 'steps'];
if (!in_array($variant, $allowed_variants, true)) {
    $variant = 'default';
}

$allowed_themes = ['default', 'dark', 'inverted'];
if (!in_array($theme, $allowed_themes, true)) {
    $theme = 'default';
}

$is_steps      = $variant === 'steps';
$variant_class = $is_steps ? ' grid--steps' : '';
$theme_class   = $theme !== 'default' ? ' grid--' . $theme : '';
?>
<section<?php echo $id ? ' id="' . esc_attr($id) . '"' : ''; ?> class="grid<?php echo esc_attr($variant_class); ?><?php echo esc_attr($theme_class); ?>">
    <div class="container">

        <?php if ($title) : ?>
            <h2 class="grid__heading"><?php echo esc_html($title); ?></h2>
        <?php endif; ?>

        <?php if (!empty($items)) : ?>
            <ul class="grid__list" role="list">
                <?php foreach ($items as $index => $item) :
                    $item_number = $item['number']    ?? (string)($index + 1);
                    $item_title  = $item['title']     ?? '';
                    $item_text   = $item['text']      ?? '';
                    $image_url   = $item['image_url'] ?? '';
                    $image_alt   = $item['image_alt'] ?? '';
                    $link_url    = $item['link_url']  ?? '';
                    $link_text   = $item['link_text'] ?? 'Read more';
                ?>
                    <li class="grid__item">
                        <?php if ($is_steps) : ?>
                            <span class="pp-step-number"><?php echo esc_html($item_number); ?></span>
                        <?php endif; ?>

                        <?php if ($image_url && !$is_steps) : ?>
                            <div class="grid__item-image-wrap">
                                <img
                                    src="<?php echo esc_url($image_url); ?>"
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
                                <p class="grid__item-text"><?php echo esc_html($item_text); ?></p>
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

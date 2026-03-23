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

$title = $props['title'] ?? '';
$items = $props['items'] ?? [];
?>
<section class="grid">
    <div class="container">

        <?php if ($title) : ?>
            <h2 class="grid__heading"><?php echo esc_html($title); ?></h2>
        <?php endif; ?>

        <?php if (!empty($items)) : ?>
            <ul class="grid__list" role="list">
                <?php foreach ($items as $item) :
                    $item_title = $item['title']     ?? '';
                    $item_text  = $item['text']      ?? '';
                    $image_url  = $item['image_url'] ?? '';
                    $image_alt  = $item['image_alt'] ?? '';
                    $link_url   = $item['link_url']  ?? '';
                    $link_text  = $item['link_text'] ?? 'Read more';
                ?>
                    <li class="grid__item">
                        <?php if ($image_url) : ?>
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

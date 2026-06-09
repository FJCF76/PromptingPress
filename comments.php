<?php
/**
 * comments.php — Comments Template
 *
 * Loaded by pp_comments_template() in single post views.
 */

if (post_password_required()) {
    return;
}
?>

<div id="comments" class="comments-area">

    <?php if (have_comments()) : ?>
        <h2 class="comments-title">
            <?php
            printf(
                esc_html(_n('%d comment', '%d comments', get_comments_number(), 'promptingpress')),
                number_format_i18n(get_comments_number())
            );
            ?>
        </h2>

        <ol class="comment-list">
            <?php
            wp_list_comments([
                'style'      => 'ol',
                'short_ping' => true,
            ]);
            ?>
        </ol>

        <?php
        the_comments_navigation();
    endif;

    if (comments_open()) :
        comment_form();
    else :
        if (have_comments()) : ?>
            <p class="no-comments"><?php esc_html_e('Comments are closed.', 'promptingpress'); ?></p>
        <?php endif;
    endif;
    ?>

</div>

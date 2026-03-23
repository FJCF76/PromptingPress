<?php
/**
 * components/faq/faq.php
 *
 * FAQ accordion using native HTML details/summary — zero JS required.
 * Props: see schema.json
 *
 * @var array $props
 */

$title = $props['title'] ?? 'Frequently Asked Questions';
$items = $props['items'] ?? [];
?>
<section class="faq">
    <div class="container">

        <?php if ($title) : ?>
            <h2 class="faq__heading"><?php echo esc_html($title); ?></h2>
        <?php endif; ?>

        <?php if (!empty($items)) : ?>
            <div class="faq__list">
                <?php foreach ($items as $item) :
                    $question = $item['question'] ?? '';
                    $answer   = $item['answer']   ?? '';
                    if (!$question) continue;
                ?>
                    <details class="faq__item">
                        <summary class="faq__question">
                            <?php echo esc_html($question); ?>
                        </summary>
                        <div class="faq__answer">
                            <?php echo wp_kses_post($answer); ?>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php else : ?>
            <p class="faq__empty text-muted">No questions yet.</p>
        <?php endif; ?>

    </div>
</section>

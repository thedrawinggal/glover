<?php
/**
 * "Latest documents" feed for a Departments & Boards page.
 *
 * @var array $args { @type int $board_id Board post ID. @type int $limit How many. }
 */

if (!defined('ABSPATH')) {
    exit;
}

$board_id = isset($args['board_id']) ? (int) $args['board_id'] : get_the_ID();
$documents = glover_document_library_latest_for_board($board_id, isset($args['limit']) ? (int) $args['limit'] : 5);
$board_name = get_the_title($board_id);
?>
<section class="document-feed" aria-labelledby="glover-latest-<?php echo (int) $board_id; ?>">
    <h2 id="glover-latest-<?php echo (int) $board_id; ?>">Latest documents</h2>

    <?php if (!$documents) : ?>
        <p>No documents have been posted for <?php echo esc_html($board_name); ?> yet.</p>
    <?php else : ?>
        <ul class="document-feed__list" role="list">
            <?php foreach ($documents as $document) : ?>
                <?php $categories = get_the_terms($document, GLOVER_DOCUMENT_LIBRARY_CATEGORY_TAXONOMY); ?>
                <li class="document-feed__item">
                    <a class="document-feed__title" href="<?php echo esc_url(get_permalink($document)); ?>"><?php echo esc_html(get_the_title($document)); ?><?php echo glover_document_library_link_format_text($document->ID); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the helper. ?></a>
                    <?php if (glover_document_library_is_draft_minutes($document->ID)) : ?>
                        <span class="status-label">Draft</span>
                    <?php endif; ?>
                    <p class="document-feed__meta">
                        <?php
                        $meta = array();
                        if ($categories && !is_wp_error($categories)) {
                            $meta[] = $categories[0]->name;
                        }
                        $meta[] = glover_document_library_display_date($document->ID);
                        echo esc_html(implode(' · ', array_filter($meta)));
                        ?>
                    </p>
                    <?php echo glover_document_library_download_link($document->ID, ''); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the helper. ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <p><a href="<?php echo esc_url(glover_document_library_url(array('board' => $board_id))); ?>">See all <?php echo esc_html($board_name); ?> documents</a></p>
</section>

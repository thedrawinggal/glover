<?php
/**
 * A single library document. Its web address stays the same when the
 * file is replaced, e.g. draft minutes becoming approved minutes.
 */

get_header();
?>
<main id="main-content" class="document-detail" tabindex="-1">
    <?php while (have_posts()) : the_post(); ?>
        <?php
        $post_id = get_the_ID();
        $boards = glover_document_library_document_board_ids($post_id);
        $categories = get_the_terms($post_id, GLOVER_DOCUMENT_LIBRARY_CATEGORY_TAXONOMY);
        $topics = get_the_terms($post_id, GLOVER_DOCUMENT_LIBRARY_TOPIC_TAXONOMY);
        $details = glover_document_library_file_details($post_id);
        $request_url = glover_document_library_request_url($post_id);
        ?>
        <?php
        get_template_part('components/common/page-banner', null, array(
            'title' => get_the_title(),
            'breadcrumbs' => array(
                array('label' => 'Home', 'url' => home_url('/')),
                array('label' => 'Documents', 'url' => glover_document_library_url()),
                array('label' => get_the_title(), 'url' => get_permalink()),
            ),
            'note' => glover_document_library_is_draft_minutes($post_id) ? 'Draft minutes. Not yet approved by the board.' : '',
        ));
        ?>

        <div class="content-narrow">
            <dl class="document-detail__list">
                <?php if ($boards) : ?>
                    <dt><?php echo count($boards) > 1 ? 'Boards' : 'Board'; ?></dt>
                    <dd>
                        <?php
                        echo implode(', ', array_map(function ($board_id) {
                            return sprintf('<a href="%s">%s</a>', esc_url(get_permalink($board_id)), esc_html(get_the_title($board_id)));
                        }, $boards)); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped per item.
                        ?>
                    </dd>
                <?php endif; ?>

                <?php if ($categories && !is_wp_error($categories)) : ?>
                    <dt>Category</dt>
                    <dd><a href="<?php echo esc_url(glover_document_library_url(array('category' => array($categories[0]->slug)))); ?>"><?php echo esc_html($categories[0]->name); ?></a></dd>
                <?php endif; ?>

                <?php if ($topics && !is_wp_error($topics)) : ?>
                    <dt>Topics</dt>
                    <dd>
                        <ul class="chip-list" role="list">
                            <?php foreach ($topics as $topic) : ?>
                                <li><a class="chip" href="<?php echo esc_url(glover_document_library_url(array('topic' => array($topic->slug)))); ?>"><?php echo esc_html($topic->name); ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    </dd>
                <?php endif; ?>

                <?php $date = glover_document_library_display_date($post_id, 'F j, Y'); ?>
                <?php if ($date) : ?>
                    <dt>Date</dt>
                    <dd><?php echo esc_html($date); ?></dd>
                <?php endif; ?>

                <?php if ($details['label']) : ?>
                    <dt>File</dt>
                    <dd><?php echo esc_html(trim($details['label'] . ($details['size'] ? ', ' . $details['size'] : ''))); ?></dd>
                <?php endif; ?>
            </dl>

            <p><?php echo glover_document_library_download_link($post_id, 'button button--primary'); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the helper. ?></p>

            <?php if ($request_url) : ?>
                <p class="document-detail__request">
                    Need this document in another format?
                    <a href="<?php echo esc_url($request_url); ?>">Request an accessible copy<span class="visually-hidden"> of <?php the_title(); ?></span></a>.
                </p>
            <?php endif; ?>
        </div>
    <?php endwhile; ?>
</main>
<?php
get_footer();

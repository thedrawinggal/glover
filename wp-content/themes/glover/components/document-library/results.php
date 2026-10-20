<?php
/**
 * Results table and pagination for the library.
 *
 * @var array $args Results from glover_document_library_results().
 */

if (!defined('ABSPATH')) {
    exit;
}

$query = $args['query'];
$filters = $args['filters'];
$total = (int) $args['total'];
$contact = glover_document_library_help_contact();

if (!$query->have_posts()) : ?>
    <div class="documents__empty">
        <h2>No documents match</h2>
        <p>Try fewer words, a wider range of years, or <a href="<?php echo esc_url(glover_document_library_url()); ?>" data-library-link>clear all filters</a>.</p>
        <p>
            Still can't find it? Contact <?php echo esc_html($contact['name']); ?><?php
            if ($contact['phone']) {
                echo ' at ' . esc_html($contact['phone']);
            }
            if ($contact['email']) {
                printf(' or <a href="mailto:%1$s">%1$s</a>', esc_attr($contact['email']));
            }
            ?>.
        </p>
    </div>
    <?php
    return;
endif;

$sort_labels = array('recent' => 'most recent first', 'oldest' => 'oldest first', 'title' => 'title, A to Z');
$first = ($filters['pg'] - 1) * GLOVER_DOCUMENT_LIBRARY_PER_PAGE + 1;
$last = min($total, $first + GLOVER_DOCUMENT_LIBRARY_PER_PAGE - 1);
?>
<table class="results-table" role="table">
    <caption class="visually-hidden">Documents, sorted <?php echo esc_html($sort_labels[$filters['sort']]); ?></caption>
    <thead role="rowgroup">
        <tr role="row">
            <th scope="col" role="columnheader">Type</th>
            <th scope="col" role="columnheader">Title</th>
            <th scope="col" role="columnheader">Topic</th>
            <th scope="col" role="columnheader">Department or board</th>
            <th scope="col" role="columnheader">Date</th>
            <th scope="col" role="columnheader"><span class="visually-hidden">Download</span></th>
        </tr>
    </thead>
    <tbody role="rowgroup">
        <?php while ($query->have_posts()) : $query->the_post(); ?>
            <?php
            $post_id = get_the_ID();
            $details = glover_document_library_file_details($post_id);
            $topics = get_the_terms($post_id, GLOVER_DOCUMENT_LIBRARY_TOPIC_TAXONOMY);
            $boards = array_map('get_the_title', glover_document_library_document_board_ids($post_id));
            $raw_date = get_field('document_date', $post_id, false);
            ?>
            <tr role="row">
                <td role="cell" data-label="Type"><span class="badge"><?php echo esc_html($details['label']); ?></span></td>
                <td role="cell" data-label="Title" class="results-table__title">
                    <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                    <?php if (glover_document_library_is_draft_minutes($post_id)) : ?>
                        <span class="status-label">Draft</span>
                    <?php endif; ?>
                </td>
                <td role="cell" data-label="Topic"<?php echo ($topics && !is_wp_error($topics)) ? '' : ' class="results-table__empty-cell"'; ?>>
                    <?php if ($topics && !is_wp_error($topics)) : ?>
                        <ul class="chip-list" role="list">
                            <?php foreach ($topics as $topic) : ?>
                                <li><span class="chip chip--small"><?php echo esc_html($topic->name); ?></span></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </td>
                <td role="cell" data-label="Department or board"><?php echo esc_html(implode(', ', $boards)); ?></td>
                <td role="cell" data-label="Date" class="results-table__date">
                    <?php if ($raw_date) : ?>
                        <time datetime="<?php echo esc_attr(glover_document_library_format_date($raw_date, 'Y-m-d')); ?>"><?php echo esc_html(glover_document_library_display_date($post_id)); ?></time>
                    <?php endif; ?>
                </td>
                <td role="cell" class="results-table__action"><?php echo glover_document_library_download_link($post_id); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the helper. ?></td>
            </tr>
        <?php endwhile; wp_reset_postdata(); ?>
    </tbody>
</table>

<div class="documents__footer">
    <p>Showing <?php echo esc_html(number_format_i18n($first) . ' to ' . number_format_i18n($last) . ' of ' . number_format_i18n($total)); ?>. Older records are on paper at the Town Clerk's office.</p>

    <?php if ($args['pages'] > 1) : ?>
        <nav class="pagination" aria-label="Results pages">
            <ul role="list">
                <?php if ($filters['pg'] > 1) : ?>
                    <li><a href="<?php echo esc_url(glover_document_library_url(array_merge($filters, array('pg' => $filters['pg'] - 1)))); ?>" data-library-link>Previous<span class="visually-hidden"> page</span></a></li>
                <?php endif; ?>
                <?php for ($page = 1; $page <= $args['pages']; $page++) : ?>
                    <?php if ($page === 1 || $page === $args['pages'] || abs($page - $filters['pg']) <= 1) : ?>
                        <li>
                            <?php if ($page === $filters['pg']) : ?>
                                <span class="pagination__current" aria-current="page"><span class="visually-hidden">Page </span><?php echo (int) $page; ?></span>
                            <?php else : ?>
                                <a href="<?php echo esc_url(glover_document_library_url(array_merge($filters, array('pg' => $page)))); ?>" data-library-link><span class="visually-hidden">Page </span><?php echo (int) $page; ?></a>
                            <?php endif; ?>
                        </li>
                    <?php elseif (abs($page - $filters['pg']) === 2) : ?>
                        <li aria-hidden="true">…</li>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if ($filters['pg'] < $args['pages']) : ?>
                    <li><a href="<?php echo esc_url(glover_document_library_url(array_merge($filters, array('pg' => $filters['pg'] + 1)))); ?>" data-library-link>Next<span class="visually-hidden"> page</span></a></li>
                <?php endif; ?>
            </ul>
        </nav>
    <?php endif; ?>
</div>

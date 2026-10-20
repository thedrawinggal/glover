<?php
/**
 * Results table and pagination for the library.
 *
 * Each result is its own <tbody>. When a search matches text inside the
 * file, the snippets appear twice in the markup: in their own row under the
 * result (full table) and inside the Title cell (stacked cards). CSS shows
 * one copy at a time with display: none, so screen readers read them once.
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
$terms = $filters['q'] !== '' ? glover_document_search_query_terms($query) : array();

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

$sort_labels = array('relevance' => 'best match first', 'recent' => 'most recent first', 'oldest' => 'oldest first', 'title' => 'title, A to Z');
$first = ($filters['pg'] - 1) * GLOVER_DOCUMENT_LIBRARY_PER_PAGE + 1;
$last = min($total, $first + GLOVER_DOCUMENT_LIBRARY_PER_PAGE - 1);

/**
 * Print a result's snippets as a list.
 *
 * @param array  $snippets From glover_document_search_snippets().
 * @param string $modifier search-matches--row or search-matches--stacked.
 * @param string $file_url Public file URL.
 * @param string $name     Accessible name of the document, e.g. "Minutes (PDF)".
 */
$print_snippets = function ($snippets, $modifier, $file_url, $name) {
    echo '<ul class="search-matches ' . esc_attr($modifier) . '" role="list">';

    foreach ($snippets as $snippet) {
        if ($snippet['kind'] === 'page' && $file_url) {
            $where = sprintf(
                '<a href="%s">%s<span class="visually-hidden"> of %s</span></a>',
                esc_url($file_url . '#page=' . preg_replace('/\D/', '', $snippet['label'])),
                esc_html($snippet['label']),
                esc_html($name)
            );
        } elseif ($snippet['kind'] === 'sheet') {
            $where = esc_html(sprintf('Sheet “%s”', $snippet['label']));
        } else {
            $where = 'In the document';
        }

        printf(
            '<li class="search-match"><span class="search-match__where">%s:</span> <span class="search-match__text">%s</span></li>',
            $where, // Escaped above.
            $snippet['html'] // Escaped in glover_document_search_highlight().
        );
    }

    echo '</ul>';
};
?>
<table class="results-table" role="table">
    <caption class="visually-hidden">Documents, sorted <?php echo esc_html($sort_labels[$filters['sort']]); ?></caption>
    <thead role="rowgroup">
        <tr role="row">
            <th scope="col" role="columnheader">Type</th>
            <th scope="col" role="columnheader">Title</th>
            <th scope="col" role="columnheader">Topic</th>
            <th scope="col" role="columnheader">Department or board</th>
            <th scope="col" role="columnheader">Effective date</th>
            <th scope="col" role="columnheader"><span class="visually-hidden">Download</span></th>
        </tr>
    </thead>
    <?php while ($query->have_posts()) : $query->the_post(); ?>
        <?php
        $post_id = get_the_ID();
        $details = glover_document_library_file_details($post_id);
        $topics = get_the_terms($post_id, GLOVER_DOCUMENT_LIBRARY_TOPIC_TAXONOMY);
        $boards = array_map('get_the_title', glover_document_library_document_board_ids($post_id));
        $raw_date = get_field('document_date', $post_id, false);
        $snippets = $terms ? glover_document_search_snippets($post_id, $terms) : array();
        $file_url = glover_document_library_file_url($post_id);
        $name = get_the_title() . ' (' . $details['label'] . ')';
        ?>
        <tbody class="results-table__result<?php echo $snippets ? ' results-table__result--matches' : ''; ?>" role="rowgroup">
            <tr role="row">
                <td role="cell" data-label="Type"><span class="badge"><?php echo esc_html($details['label']); ?></span></td>
                <td role="cell" data-label="Title" class="results-table__title">
                    <a href="<?php the_permalink(); ?>"><?php echo glover_document_search_highlight(get_the_title(), $terms); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the helper. ?></a>
                    <?php if (glover_document_library_is_draft_minutes($post_id)) : ?>
                        <span class="status-label">Draft</span>
                    <?php endif; ?>
                    <?php if ($snippets) {
                        $print_snippets($snippets, 'search-matches--stacked', $file_url, $name);
                    } ?>
                </td>
                <td role="cell" data-label="Topic"<?php echo ($topics && !is_wp_error($topics)) ? '' : ' class="results-table__empty-cell"'; ?>>
                    <?php if ($topics && !is_wp_error($topics)) : ?>
                        <ul class="chip-list" role="list">
                            <?php foreach ($topics as $topic) : ?>
                                <li><span class="chip chip--outline"><?php echo esc_html($topic->name); ?></span></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </td>
                <td role="cell" data-label="Department or board" class="results-table__board"><?php echo esc_html(implode(', ', $boards)); ?></td>
                <td role="cell" data-label="Date" class="results-table__date">
                    <?php if ($raw_date) : ?>
                        <span class="results-table__date-label" aria-hidden="true">Effective Date</span>
                        <time datetime="<?php echo esc_attr(glover_document_library_format_date($raw_date, 'Y-m-d')); ?>"><?php echo esc_html(glover_document_library_display_date($post_id)); ?></time>
                    <?php endif; ?>
                </td>
                <td role="cell" class="results-table__action"><?php echo glover_document_library_download_link($post_id); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the helper. ?></td>
            </tr>
            <?php if ($snippets) : ?>
                <tr role="row" class="results-table__matches-row">
                    <td role="cell" class="results-table__matches-spacer"></td>
                    <td role="cell" colspan="4" class="results-table__matches"><?php $print_snippets($snippets, 'search-matches--row', $file_url, $name); ?></td>
                    <td role="cell" class="results-table__matches-spacer"></td>
                </tr>
            <?php endif; ?>
        </tbody>
    <?php endwhile; wp_reset_postdata(); ?>
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

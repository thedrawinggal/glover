<?php

if (!defined('ABSPATH')) {
    exit;
}

/*
 * Document Library > Documents: the one list of documents for approvers,
 * replacing WordPress's "All Documents" list in the menu, with tabs.
 * - Pending: one row per published document with pending replacements
 *   (compared in a dialog, see document-library-review-compare.php), then
 *   new submissions.
 * - Approved: every published document, searchable and paged.
 * - Returned: documents sent back to their submitters.
 */

const GLOVER_DOCUMENT_LIBRARY_REVIEW_PAGE = 'glover-document-review';

/**
 * Review screen URL for a tab.
 *
 * @param string $tab  pending, approved or returned.
 * @param array  $args Extra query args.
 */
function glover_document_library_review_screen_url($tab = 'pending', $args = array())
{
    return add_query_arg(array_merge(array(
        'post_type' => GLOVER_DOCUMENT_LIBRARY_POST_TYPE,
        'page' => GLOVER_DOCUMENT_LIBRARY_REVIEW_PAGE,
        'tab' => $tab,
    ), $args), admin_url('edit.php'));
}

/**
 * Add Review as the first item under Document Library, with the pending count.
 */
function glover_document_library_register_review_screen()
{
    $pending = (int) wp_count_posts(GLOVER_DOCUMENT_LIBRARY_POST_TYPE)->pending;
    $label = 'Documents';

    if ($pending && glover_document_library_user_can_review()) {
        $label .= sprintf(
            ' <span class="awaiting-mod"><span class="pending-count" aria-hidden="true">%1$d</span><span class="screen-reader-text">%1$d waiting for review</span></span>',
            $pending
        );
    }

    add_submenu_page(
        'edit.php?post_type=' . GLOVER_DOCUMENT_LIBRARY_POST_TYPE,
        'Documents',
        $label,
        'edit_posts',
        GLOVER_DOCUMENT_LIBRARY_REVIEW_PAGE,
        'glover_document_library_render_review_screen',
        0
    );
}
add_action('admin_menu', 'glover_document_library_register_review_screen');

/**
 * Hide WordPress's own "All Documents" item; this screen replaces it. The
 * classic list stays reachable for bulk actions and the trash.
 */
function glover_document_library_hide_classic_list()
{
    remove_submenu_page('edit.php?post_type=' . GLOVER_DOCUMENT_LIBRARY_POST_TYPE, 'edit.php?post_type=' . GLOVER_DOCUMENT_LIBRARY_POST_TYPE);
}
add_action('admin_menu', 'glover_document_library_hide_classic_list', 100);

/**
 * Styles and the compare dialog script, on the Documents screen only.
 *
 * @param string $hook_suffix Current admin page.
 */
function glover_document_library_review_screen_assets($hook_suffix)
{
    if (strpos($hook_suffix, GLOVER_DOCUMENT_LIBRARY_REVIEW_PAGE) === false) {
        return;
    }

    $base = get_template_directory_uri() . '/includes/features/document-library/assets/';
    wp_enqueue_style('glover-document-review', $base . 'review-admin.css', array(), (string) filemtime(__DIR__ . '/assets/review-admin.css'));
    wp_enqueue_script('glover-document-review', $base . 'review-admin.js', array(), (string) filemtime(__DIR__ . '/assets/review-admin.js'), true);
}
add_action('admin_enqueue_scripts', 'glover_document_library_review_screen_assets');

/**
 * Split pending documents into replacement groups (keyed by the published
 * document they replace) and new submissions. Oldest first, so the queue is
 * worked in the order things arrived.
 *
 * @return array{groups:array<int,int[]>,new:int[],orphans:int[]}
 */
function glover_document_library_pending_groups()
{
    $pending = get_posts(array(
        'post_type' => GLOVER_DOCUMENT_LIBRARY_POST_TYPE,
        'post_status' => 'pending',
        'posts_per_page' => -1,
        'orderby' => 'date',
        'order' => 'ASC',
        'fields' => 'ids',
        'no_found_rows' => true,
    ));
    $groups = array();
    $new = array();
    $orphans = array();

    foreach ($pending as $post_id) {
        $original = (int) get_field('document_replaces', $post_id, false);

        if ($original && get_post_status($original) === 'publish') {
            $groups[$original][] = $post_id;
        } elseif ($original) {
            $orphans[] = $post_id;
            $new[] = $post_id;
        } else {
            $new[] = $post_id;
        }
    }

    return array('groups' => $groups, 'new' => $new, 'orphans' => $orphans);
}

/**
 * A document's details as plain text, for the review lists.
 *
 * @param int $post_id Document ID.
 * @return array<string,string> Label => plain-text value.
 */
function glover_document_library_review_details($post_id)
{
    $terms = function ($taxonomy) use ($post_id) {
        $list = get_the_terms($post_id, $taxonomy);
        return ($list && !is_wp_error($list)) ? implode(', ', wp_list_pluck($list, 'name')) : '';
    };
    $status = get_field('document_minutes_status', $post_id, false);
    $file = glover_document_library_file_details($post_id);
    $path = get_attached_file(glover_document_library_file_id($post_id));

    return array(
        'Title' => get_post_field('post_title', $post_id, 'raw'),
        'Document date' => glover_document_library_display_date($post_id, 'F j, Y'),
        // The review tables' short form, e.g. "Oct 14, 2026".
        'Short date' => glover_document_library_display_date($post_id, 'M j, Y'),
        'Minutes status' => $status === 'draft' ? 'Draft' : ($status === 'approved' ? 'Approved by the board' : ''),
        'Boards' => implode(', ', array_map('get_the_title', glover_document_library_document_board_ids($post_id))),
        'Category' => $terms(GLOVER_DOCUMENT_LIBRARY_CATEGORY_TAXONOMY),
        'Topics' => $terms(GLOVER_DOCUMENT_LIBRARY_TOPIC_TAXONOMY),
        'File' => $path ? basename($path) . ' (' . trim($file['label'] . ', ' . $file['size'], ', ') . ')' : '',
    );
}

/**
 * A file link that opens in a new tab, or "None".
 *
 * @param int    $post_id Document ID.
 * @param string $label   Link text.
 */
function glover_document_library_review_file_link($post_id, $label)
{
    $url = glover_document_library_file_url($post_id);

    if (!$url) {
        return 'None';
    }

    return sprintf(
        '<a href="%s" target="_blank" rel="noopener">%s<span class="screen-reader-text"> (opens in a new tab)</span></a>',
        esc_url($url),
        esc_html($label)
    );
}

/**
 * Edit | Return, Review buttons for a new document.
 *
 * @param int $post_id Document ID.
 */
function glover_document_library_review_actions($post_id)
{
    $title = get_the_title($post_id);

    echo '<div class="glover-review-buttons">';
    printf(
        '<a class="button" href="%s" aria-label="%s">Edit</a>',
        esc_url(get_edit_post_link($post_id, 'url')),
        esc_attr(sprintf('Edit "%s"', $title))
    );
    echo '<span class="glover-review-sep" aria-hidden="true">|</span>';
    printf(
        '<a class="button" href="%s" title="Return to submitter" aria-label="%s" aria-haspopup="dialog" %s>Return</a>',
        esc_url(glover_document_library_return_url($post_id, 'review')),
        esc_attr(sprintf('Return "%s" to its submitter', $title)),
        glover_document_library_return_data($post_id) // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the helper.
    );
    glover_document_library_preview_link($post_id);
    echo '</div>';
}

/**
 * Who submitted a document and when.
 *
 * @param int $post_id Document ID.
 */
function glover_document_library_review_submitted($post_id)
{
    $submitter = glover_document_library_review_submitter($post_id);

    return $submitter['name'] . ', ' . $submitter['date'];
}

/**
 * Print the Review screen.
 */
function glover_document_library_render_review_screen()
{
    if (!glover_document_library_user_can_review()) {
        echo '<div class="wrap"><h1>Documents</h1><p>Only approvers and administrators can review documents. Set the approvers in Document Library &gt; Settings.</p></div>';
        return;
    }

    $data = glover_document_library_pending_groups();
    $counts = wp_count_posts(GLOVER_DOCUMENT_LIBRARY_POST_TYPE);
    $returned = glover_document_library_returned_ids();
    $drafts = glover_document_library_draft_ids();
    $trash = glover_document_library_trash_ids();
    $tabs = array(
        'pending' => array('Pending', (int) $counts->pending),
        'approved' => array('Approved', (int) $counts->publish),
        'returned' => array('Returned', count($returned)),
    );

    // Drafts only appear when there are some; most documents never are one.
    if ($drafts || (isset($_GET['tab']) && $_GET['tab'] === 'drafts')) {
        $tabs['drafts'] = array('Drafts', count($drafts));
    }

    $tabs['trash'] = array('Trash', count($trash));
    $tab = isset($_GET['tab']) && isset($tabs[$_GET['tab']]) ? sanitize_key($_GET['tab']) : 'pending';
    ?>
    <div class="wrap glover-review">
        <h1 class="wp-heading-inline">Documents</h1>
        <a href="<?php echo esc_url(admin_url('post-new.php?post_type=' . GLOVER_DOCUMENT_LIBRARY_POST_TYPE)); ?>" class="page-title-action">Add New Document</a>
        <hr class="wp-header-end">

        <nav class="nav-tab-wrapper" aria-label="Document tabs">
            <?php foreach ($tabs as $key => $info) : ?>
                <a href="<?php echo esc_url(glover_document_library_review_screen_url($key)); ?>" class="nav-tab<?php echo $key === $tab ? ' nav-tab-active' : ''; ?>"<?php echo $key === $tab ? ' aria-current="page"' : ''; ?>>
                    <?php echo esc_html($info[0]); ?> <span class="count">(<?php echo (int) $info[1]; ?>)</span>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php
        if ($tab === 'pending') {
            glover_document_library_render_pending_tab($data);
        } elseif ($tab === 'approved') {
            glover_document_library_render_approved_tab();
        } elseif ($tab === 'drafts') {
            glover_document_library_render_drafts_tab($drafts);
        } elseif ($tab === 'trash') {
            glover_document_library_render_trash_tab($trash);
        } else {
            glover_document_library_render_returned_tab($returned);
        }
        ?>
    </div>
    <?php
}

/**
 * Pending tab: replacement groups first, then new submissions.
 *
 * @param array $data From glover_document_library_pending_groups().
 */
function glover_document_library_render_pending_tab($data)
{
    if (!$data['groups'] && !$data['new']) {
        echo '<p class="glover-review-empty">Nothing is waiting for review.</p>';
        return;
    }

    $compare = isset($_GET['compare']) ? absint($_GET['compare']) : 0;

    if ($compare && isset($data['groups'][$compare])) {
        printf('<p><a href="%s">Back to Pending</a></p>', esc_url(glover_document_library_review_screen_url('pending')));
        echo '<div class="glover-compare-page">';
        glover_document_library_render_compare($compare, $data['groups'][$compare]);
        echo '</div>';
        return;
    }

    if ($data['groups']) {
        $count = array_sum(array_map('count', $data['groups']));
        printf('<h2>Replacements <span class="count">(%d)</span></h2>', (int) $count);
        echo '<p class="glover-review-intro">Each one updates a document that is already published. Open one to compare it with the published version.</p>';
        glover_document_library_render_replacement_queue($data['groups']);
    }

    if ($data['new']) {
        printf('<h2>New documents <span class="count">(%d)</span></h2>', count($data['new']));
        ?>
        <table class="wp-list-table widefat striped glover-review-table">
            <caption class="screen-reader-text">New documents waiting for review, oldest first</caption>
            <thead>
                <tr>
                    <th scope="col" class="column-primary">Title</th>
                    <th scope="col">Boards</th>
                    <th scope="col">Category</th>
                    <th scope="col" class="glover-review-tight">Document date</th>
                    <th scope="col" class="glover-review-tight">File</th>
                    <th scope="col">Submitted</th>
                    <th scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($data['new'] as $post_id) : ?>
                    <?php
                    $details = glover_document_library_review_details($post_id);
                    $flags = glover_document_library_review_flags($post_id);
                    $note = get_post_meta($post_id, 'document_reviewer_note', true);

                    if (in_array($post_id, $data['orphans'], true)) {
                        $flags[] = 'Was meant to replace a document that is no longer published';
                    }
                    ?>
                    <tr>
                        <td class="column-primary">
                            <strong><?php echo esc_html($details['Title']); ?></strong>
                            <?php if ($details['Minutes status'] === 'Draft' || $note) : ?>
                                <div class="glover-review-tags">
                                    <?php if ($details['Minutes status'] === 'Draft') : ?>
                                        <span class="glover-review-tag">Draft minutes</span>
                                    <?php endif; ?>
                                    <?php if ($note) : ?>
                                        <span class="glover-review-tag">Note from submitter</span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($flags) : ?>
                                <ul class="glover-review-flags">
                                    <?php foreach ($flags as $flag) : ?>
                                        <li><?php echo esc_html($flag); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($details['Boards']); ?></td>
                        <td><?php echo esc_html($details['Category']); ?></td>
                        <td class="glover-review-tight"><?php echo esc_html($details['Short date']); ?></td>
                        <td class="glover-review-tight"><?php echo esc_html(trim(glover_document_library_file_details($post_id)['label'] . ', ' . glover_document_library_file_details($post_id)['size'], ', ')); ?></td>
                        <td><?php echo esc_html(glover_document_library_review_submitted($post_id)); ?></td>
                        <td class="glover-review-actions"><?php glover_document_library_review_actions($post_id); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    // Shared by both tables' Return and Preview buttons.
    glover_document_library_render_preview_dialog();
    glover_document_library_render_return_dialog();
}

/**
 * Approved tab: published documents, newest documents first, with search.
 */
function glover_document_library_render_approved_tab()
{
    $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
    $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
    $views = glover_document_library_approved_views();
    $view = isset($_GET['view']) && isset($views[$_GET['view']]) ? sanitize_key($_GET['view']) : '';
    $meta_query = array('document_date' => array('key' => 'document_date', 'compare' => 'EXISTS'));

    if ($view !== '') {
        $meta_query[] = $views[$view]['meta'];
    }

    $query = new WP_Query(array(
        'post_type' => GLOVER_DOCUMENT_LIBRARY_POST_TYPE,
        'post_status' => 'publish',
        'posts_per_page' => 25,
        'paged' => $paged,
        's' => $search,
        'glover_title_search' => true,
        'meta_query' => $meta_query,
        'orderby' => array('document_date' => 'DESC', 'date' => 'DESC'),
    ));
    $keep = array_filter(array('s' => $search, 'view' => $view));
    $statuses = GLOVER_DOCUMENT_LIBRARY_BACKUP_STATUSES;
    ?>
    <form method="get" class="glover-review-search" role="search">
        <input type="hidden" name="post_type" value="<?php echo esc_attr(GLOVER_DOCUMENT_LIBRARY_POST_TYPE); ?>">
        <input type="hidden" name="page" value="<?php echo esc_attr(GLOVER_DOCUMENT_LIBRARY_REVIEW_PAGE); ?>">
        <input type="hidden" name="tab" value="approved">
        <?php if ($view !== '') : ?>
            <input type="hidden" name="view" value="<?php echo esc_attr($view); ?>">
        <?php endif; ?>
        <label for="glover-review-search">Search approved documents by title</label>
        <input type="search" id="glover-review-search" name="s" value="<?php echo esc_attr($search); ?>">
        <?php submit_button('Search', 'secondary', '', false); ?>
        <?php if ($search !== '') : ?>
            <a href="<?php echo esc_url(glover_document_library_review_screen_url('approved', $view !== '' ? array('view' => $view) : array())); ?>">Clear search</a>
        <?php endif; ?>
    </form>

    <?php if ($view !== '') : ?>
        <p class="glover-review-view">Showing: <strong><?php echo esc_html($views[$view]['label']); ?></strong>. <a href="<?php echo esc_url(glover_document_library_review_screen_url('approved', $search !== '' ? array('s' => $search) : array())); ?>">Show all approved documents</a></p>
    <?php endif; ?>

    <?php if (!$query->have_posts()) : ?>
        <p class="glover-review-empty"><?php echo $search !== '' ? 'No approved documents match that search.' : 'No documents have been approved yet.'; ?></p>
        <?php return; ?>
    <?php endif; ?>

    <table class="wp-list-table widefat striped glover-review-table glover-review-table--approved">
        <caption class="screen-reader-text">Approved documents, newest document date first</caption>
        <thead>
            <tr>
                <th scope="col" class="column-primary">Title</th>
                <th scope="col">Boards</th>
                <th scope="col">Category</th>
                <th scope="col" class="glover-review-tight">Document date</th>
                <th scope="col" class="glover-review-tight">File</th>
                <th scope="col" class="glover-review-tight">Drive backup</th>
                <th scope="col" class="glover-review-tight">Published</th>
                <th scope="col">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($query->have_posts()) : $query->the_post(); ?>
                <?php
                $post_id = get_the_ID();
                $details = glover_document_library_review_details($post_id);
                $backup = get_post_meta($post_id, '_glover_drive_backup_status', true);
                $versions = count(glover_document_library_meta_list($post_id, '_glover_previous_files'));
                ?>
                <tr>
                    <td class="column-primary">
                        <strong><a href="<?php echo esc_url(get_edit_post_link($post_id, 'url')); ?>"><?php echo esc_html($details['Title']); ?></a></strong>
                        <?php if ($details['Minutes status'] === 'Draft' || $versions) : ?>
                            <div class="glover-review-tags">
                                <?php if ($details['Minutes status'] === 'Draft') : ?>
                                    <span class="glover-review-tag">Draft minutes</span>
                                <?php endif; ?>
                                <?php if ($versions) : ?>
                                    <span class="glover-review-tag"><?php echo esc_html(sprintf(_n('Replaced %d time', 'Replaced %d times', $versions), $versions)); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html($details['Boards']); ?></td>
                    <td><?php echo esc_html($details['Category']); ?></td>
                    <td class="glover-review-tight"><?php echo esc_html($details['Short date']); ?></td>
                    <td class="glover-review-tight"><?php echo glover_document_library_review_file_link($post_id, glover_document_library_file_details($post_id)['label'] . ' ' . glover_document_library_file_details($post_id)['size']); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the helper. ?></td>
                    <td class="glover-review-tight"><?php echo esc_html(isset($statuses[$backup]) ? $statuses[$backup] : 'Not requested'); ?></td>
                    <td class="glover-review-tight"><?php echo esc_html(get_the_date('M j, Y')); ?></td>
                    <td class="glover-review-actions"><?php glover_document_library_approved_actions($post_id); ?></td>
                </tr>
            <?php endwhile; wp_reset_postdata(); ?>
        </tbody>
    </table>

    <?php

    if ($query->max_num_pages > 1) {
        echo '<nav class="glover-review-pages" aria-label="Approved documents pages">';
        echo paginate_links(array( // phpcs:ignore WordPress.Security.EscapeOutput -- core output.
            'base' => add_query_arg('paged', '%#%', glover_document_library_review_screen_url('approved', $keep)),
            'format' => '',
            'current' => $paged,
            'total' => $query->max_num_pages,
            'before_page_number' => '<span class="screen-reader-text">Page </span>',
        ));
        echo '</nav>';
    }
}

/**
 * Drafts that were returned to their submitters, newest first.
 *
 * @return int[]
 */
function glover_document_library_returned_ids()
{
    return get_posts(array(
        'post_type' => GLOVER_DOCUMENT_LIBRARY_POST_TYPE,
        'post_status' => 'draft',
        'posts_per_page' => 100,
        'fields' => 'ids',
        'no_found_rows' => true,
        'orderby' => 'modified',
        'order' => 'DESC',
        'meta_key' => '_glover_returned_reason',
        'meta_compare' => 'EXISTS',
    ));
}

/**
 * Returned tab.
 *
 * @param int[] $ids Returned documents.
 */
function glover_document_library_render_returned_tab($ids)
{
    if (!$ids) {
        echo '<p class="glover-review-empty">No documents have been returned.</p>';
        return;
    }

    ?>
    <div class="glover-review-toolbar">
        <p class="glover-review-intro">These went back to their submitters, who were emailed the reason. A corrected version comes back as a new submission.</p>
        <?php glover_document_library_trash_returned_button(); ?>
    </div>
    <table class="wp-list-table widefat fixed striped glover-review-table">
        <caption class="screen-reader-text">Returned documents, most recently returned first</caption>
        <thead>
            <tr>
                <th scope="col" class="column-primary">Title</th>
                <th scope="col">Boards</th>
                <th scope="col">Submitted</th>
                <th scope="col">Reason returned</th>
                <th scope="col">Returned</th>
                <th scope="col">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($ids as $post_id) : ?>
                <?php
                $returned_at = '';

                foreach (array_reverse(glover_document_library_meta_list($post_id, '_glover_audit_log')) as $entry) {
                    if (($entry['action'] ?? '') === 'returned') {
                        $returned_at = wp_date('M j, Y', (int) $entry['time']);
                        break;
                    }
                }
                ?>
                <tr>
                    <td class="column-primary"><strong><a href="<?php echo esc_url(get_edit_post_link($post_id, 'url')); ?>"><?php echo esc_html(get_post_field('post_title', $post_id, 'raw')); ?></a></strong></td>
                    <td><?php echo esc_html(implode(', ', array_map('get_the_title', glover_document_library_document_board_ids($post_id)))); ?></td>
                    <td><?php echo esc_html(glover_document_library_review_submitted($post_id)); ?></td>
                    <td><?php echo esc_html(get_post_meta($post_id, '_glover_returned_reason', true)); ?></td>
                    <td><?php echo esc_html($returned_at); ?></td>
                    <td class="glover-review-actions"><?php glover_document_library_trash_returned_button($post_id); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}

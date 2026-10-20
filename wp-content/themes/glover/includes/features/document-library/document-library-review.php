<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Review columns on the Documents list.
 *
 * @param string[] $columns Existing columns.
 * @return string[]
 */
function glover_document_library_admin_columns($columns)
{
    $date = isset($columns['date']) ? $columns['date'] : 'Date';
    unset($columns['date']);

    $columns['glover_boards'] = 'Boards';
    $columns['glover_document_date'] = 'Document date';
    $columns['glover_file'] = 'File';
    $columns['glover_submitted_by'] = 'Submitted by';
    $columns['glover_flags'] = 'Review notes';
    $columns['date'] = $date;

    return $columns;
}
add_filter('manage_' . GLOVER_DOCUMENT_LIBRARY_POST_TYPE . '_posts_columns', 'glover_document_library_admin_columns');

/**
 * Print a review column.
 *
 * @param string $column  Column key.
 * @param int    $post_id Document ID.
 */
function glover_document_library_admin_column($column, $post_id)
{
    switch ($column) {
        case 'glover_boards':
            $titles = array_map('get_the_title', glover_document_library_document_board_ids($post_id));
            echo esc_html($titles ? implode(', ', $titles) : 'None');
            break;

        case 'glover_document_date':
            $date = get_field('document_date', $post_id, false);
            echo esc_html($date ? glover_document_library_format_date($date) : 'None');
            break;

        case 'glover_file':
            $url = glover_document_library_file_url($post_id);
            $details = glover_document_library_file_details($post_id);

            if ($url) {
                printf(
                    '<a href="%s" target="_blank" rel="noopener">%s</a>',
                    esc_url($url),
                    esc_html(trim($details['label'] . ' ' . $details['size']))
                );
            } else {
                echo 'None';
            }
            break;

        case 'glover_submitted_by':
            $user = get_userdata((int) get_post_meta($post_id, '_glover_submitted_by', true));
            echo esc_html($user ? $user->display_name : 'None');
            break;

        case 'glover_flags':
            echo esc_html(implode('; ', glover_document_library_review_flags($post_id)));
            break;
    }
}
add_action('manage_' . GLOVER_DOCUMENT_LIBRARY_POST_TYPE . '_posts_custom_column', 'glover_document_library_admin_column', 10, 2);

/**
 * Short notes an approver should see at a glance.
 *
 * @param int $post_id Document ID.
 * @return string[]
 */
function glover_document_library_review_flags($post_id)
{
    $flags = array();

    if (get_post_meta($post_id, '_glover_auto_published', true)) {
        $flags[] = 'Published without review';
    }

    if (get_post_meta($post_id, '_glover_possible_duplicates', true)) {
        $flags[] = 'Possible duplicate';
    }

    $replaces = (int) get_field('document_replaces', $post_id, false);

    if ($replaces) {
        $flags[] = 'Replaces "' . get_the_title($replaces) . '"';
    }

    if (get_post_meta($post_id, 'document_suggested_topic', true)) {
        $flags[] = 'Suggests a topic';
    }

    if (get_post_meta($post_id, '_glover_returned_reason', true) && get_post_status($post_id) !== 'publish') {
        $flags[] = 'Returned';
    }

    return $flags;
}

/**
 * Approve and Return links on pending documents.
 *
 * @param string[] $actions Row actions.
 * @param WP_Post  $post    Document.
 * @return string[]
 */
function glover_document_library_row_actions($actions, $post)
{
    if ($post->post_type !== GLOVER_DOCUMENT_LIBRARY_POST_TYPE || $post->post_status !== 'pending' || !glover_document_library_user_can_review()) {
        return $actions;
    }

    $actions['glover_approve'] = sprintf(
        '<a href="%s" aria-label="%s">Approve</a>',
        esc_url(glover_document_library_approve_url($post->ID)),
        esc_attr(sprintf('Approve and publish "%s"', get_the_title($post)))
    );
    $actions['glover_return'] = sprintf(
        '<a href="%s" aria-label="%s">Return</a>',
        esc_url(glover_document_library_return_url($post->ID)),
        esc_attr(sprintf('Return "%s" to its submitter', get_the_title($post)))
    );

    return $actions;
}
add_filter('post_row_actions', 'glover_document_library_row_actions', 10, 2);

/**
 * @param int $post_id Document ID.
 */
function glover_document_library_approve_url($post_id)
{
    return wp_nonce_url(admin_url('admin-post.php?action=glover_document_approve&post=' . (int) $post_id), 'glover_document_approve_' . (int) $post_id);
}

/**
 * @param int $post_id Document ID.
 */
function glover_document_library_return_url($post_id)
{
    return admin_url('admin.php?page=glover-document-return&post=' . (int) $post_id);
}

/**
 * Approve: publish a pending document.
 */
function glover_document_library_handle_approve()
{
    $post_id = isset($_GET['post']) ? absint($_GET['post']) : 0;
    check_admin_referer('glover_document_approve_' . $post_id);

    if (!glover_document_library_user_can_review() || get_post_type($post_id) !== GLOVER_DOCUMENT_LIBRARY_POST_TYPE) {
        wp_die('You do not have permission to approve documents.', 'Forbidden', array('response' => 403));
    }

    glover_document_library_log($post_id, 'approved');
    wp_publish_post($post_id);

    wp_safe_redirect(add_query_arg('glover_notice', 'approved', admin_url('edit.php?post_type=' . GLOVER_DOCUMENT_LIBRARY_POST_TYPE . '&post_status=pending')));
    exit;
}
add_action('admin_post_glover_document_approve', 'glover_document_library_handle_approve');

/**
 * Hidden admin page asking for the reason a document is being returned.
 */
function glover_document_library_register_return_page()
{
    add_submenu_page('', 'Return document', 'Return document', 'read', 'glover-document-return', 'glover_document_library_render_return_page');
}
add_action('admin_menu', 'glover_document_library_register_return_page');

/**
 * Print the Return form.
 */
function glover_document_library_render_return_page()
{
    $post_id = isset($_GET['post']) ? absint($_GET['post']) : 0;

    if (!glover_document_library_user_can_review() || get_post_type($post_id) !== GLOVER_DOCUMENT_LIBRARY_POST_TYPE) {
        wp_die('You do not have permission to return documents.');
    }
    ?>
    <div class="wrap">
        <h1>Return "<?php echo esc_html(get_the_title($post_id)); ?>"</h1>
        <p>The document stays off the website. Its submitter gets an email with your reason.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="glover_document_return">
            <input type="hidden" name="post" value="<?php echo (int) $post_id; ?>">
            <?php wp_nonce_field('glover_document_return_' . $post_id); ?>
            <p>
                <label for="glover-return-reason"><strong>Reason for returning it</strong></label><br>
                <textarea id="glover-return-reason" name="reason" rows="5" cols="70" required aria-describedby="glover-return-help"></textarea>
            </p>
            <p id="glover-return-help" class="description">Say what needs to change, for example "This is a scan; please upload a text-based PDF."</p>
            <?php submit_button('Return document'); ?>
        </form>
    </div>
    <?php
}

/**
 * Return: move a document to Draft with the reason, and email the submitter.
 */
function glover_document_library_handle_return()
{
    $post_id = isset($_POST['post']) ? absint($_POST['post']) : 0;
    check_admin_referer('glover_document_return_' . $post_id);

    if (!glover_document_library_user_can_review() || get_post_type($post_id) !== GLOVER_DOCUMENT_LIBRARY_POST_TYPE) {
        wp_die('You do not have permission to return documents.', 'Forbidden', array('response' => 403));
    }

    $reason = isset($_POST['reason']) ? sanitize_textarea_field(wp_unslash($_POST['reason'])) : '';

    if ($reason === '') {
        wp_die('Please give a reason for returning the document.', 'Reason required', array('back_link' => true));
    }

    update_post_meta($post_id, '_glover_returned_reason', $reason);
    wp_update_post(array('ID' => $post_id, 'post_status' => 'draft'));
    glover_document_library_log($post_id, 'returned', $reason);
    glover_document_library_notify_returned($post_id, $reason);

    wp_safe_redirect(add_query_arg('glover_notice', 'returned', admin_url('edit.php?post_type=' . GLOVER_DOCUMENT_LIBRARY_POST_TYPE . '&post_status=pending')));
    exit;
}
add_action('admin_post_glover_document_return', 'glover_document_library_handle_return');

/**
 * Confirmation after Approve or Return.
 */
function glover_document_library_review_notice()
{
    if (empty($_GET['glover_notice'])) {
        return;
    }

    $messages = array(
        'approved' => 'Document approved and published.',
        'returned' => 'Document returned to its submitter.',
    );
    $key = sanitize_key($_GET['glover_notice']);

    if (isset($messages[$key])) {
        printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html($messages[$key]));
    }
}
add_action('admin_notices', 'glover_document_library_review_notice');

/**
 * "Published without review" view on the Documents list.
 *
 * @param string[] $views Existing views.
 * @return string[]
 */
function glover_document_library_admin_views($views)
{
    $count = count(get_posts(array(
        'post_type' => GLOVER_DOCUMENT_LIBRARY_POST_TYPE,
        'post_status' => 'publish',
        'fields' => 'ids',
        'posts_per_page' => -1,
        'meta_key' => '_glover_auto_published',
        'meta_value' => '1',
        'no_found_rows' => true,
    )));
    $current = isset($_GET['glover_view']) && $_GET['glover_view'] === 'auto';

    $views['glover_auto'] = sprintf(
        '<a href="%s"%s>Published without review <span class="count">(%d)</span></a>',
        esc_url(admin_url('edit.php?post_type=' . GLOVER_DOCUMENT_LIBRARY_POST_TYPE . '&glover_view=auto')),
        $current ? ' class="current" aria-current="page"' : '',
        $count
    );

    return $views;
}
add_filter('views_edit-' . GLOVER_DOCUMENT_LIBRARY_POST_TYPE, 'glover_document_library_admin_views');

/**
 * Apply the "Published without review" view.
 *
 * @param WP_Query $query Admin list query.
 */
function glover_document_library_filter_admin_list($query)
{
    if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== GLOVER_DOCUMENT_LIBRARY_POST_TYPE) {
        return;
    }

    if (isset($_GET['glover_view']) && $_GET['glover_view'] === 'auto') {
        $query->set('meta_key', '_glover_auto_published');
        $query->set('meta_value', '1');
    }
}
add_action('pre_get_posts', 'glover_document_library_filter_admin_list');

/**
 * Pending count beside the Document Library menu, for approvers.
 */
function glover_document_library_menu_count()
{
    global $menu;

    if (!glover_document_library_user_can_review()) {
        return;
    }

    $pending = (int) wp_count_posts(GLOVER_DOCUMENT_LIBRARY_POST_TYPE)->pending;

    if (!$pending) {
        return;
    }

    foreach ($menu as $index => $item) {
        if (isset($item[2]) && $item[2] === 'edit.php?post_type=' . GLOVER_DOCUMENT_LIBRARY_POST_TYPE) {
            $menu[$index][0] .= sprintf(
                ' <span class="awaiting-mod"><span class="pending-count" aria-hidden="true">%1$d</span><span class="screen-reader-text">%1$d waiting for review</span></span>',
                $pending
            );
        }
    }
}
add_action('admin_menu', 'glover_document_library_menu_count', 99);

/**
 * Review details box on the document edit screen.
 */
function glover_document_library_add_review_box()
{
    add_meta_box('glover-document-review', 'Review details', 'glover_document_library_render_review_box', GLOVER_DOCUMENT_LIBRARY_POST_TYPE, 'side', 'high');
}
add_action('add_meta_boxes', 'glover_document_library_add_review_box');

/**
 * Print the Review details box.
 *
 * @param WP_Post $post Document.
 */
function glover_document_library_render_review_box($post)
{
    $submitter = get_userdata((int) get_post_meta($post->ID, '_glover_submitted_by', true));
    $flags = glover_document_library_review_flags($post->ID);
    $backup = get_post_meta($post->ID, '_glover_drive_backup_status', true);
    $statuses = GLOVER_DOCUMENT_LIBRARY_BACKUP_STATUSES;

    echo '<p><strong>Submitted by:</strong> ' . esc_html($submitter ? $submitter->display_name : 'Added in wp-admin') . '</p>';

    if ($flags) {
        echo '<ul>';
        foreach ($flags as $flag) {
            echo '<li>' . esc_html($flag) . '</li>';
        }
        echo '</ul>';
    }

    foreach ((array) get_post_meta($post->ID, '_glover_possible_duplicates', true) as $duplicate_id) {
        if ($duplicate_id && get_post($duplicate_id)) {
            printf('<p>Possible duplicate of <a href="%s">%s</a></p>', esc_url(get_edit_post_link($duplicate_id)), esc_html(get_the_title($duplicate_id)));
        }
    }

    $reason = get_post_meta($post->ID, '_glover_returned_reason', true);

    if ($reason && $post->post_status !== 'publish') {
        echo '<p><strong>Returned because:</strong> ' . esc_html($reason) . '</p>';
    }

    echo '<p><strong>Drive backup:</strong> ' . esc_html(isset($statuses[$backup]) ? $statuses[$backup] : 'Not yet requested') . '</p>';

    if ($post->post_status === 'pending' && glover_document_library_user_can_review()) {
        printf(
            '<p><a class="button button-primary" href="%s">Approve and publish</a> <a class="button" href="%s">Return</a></p>',
            esc_url(glover_document_library_approve_url($post->ID)),
            esc_url(glover_document_library_return_url($post->ID))
        );
    }

    $history = get_post_meta($post->ID, '_glover_previous_files', true);

    if ($history) {
        echo '<p><strong>Previous versions</strong></p><ul>';
        foreach ((array) $history as $version) {
            $url = add_query_arg(array(
                'action' => 'glover_document_file',
                'document' => $post->ID,
                'attachment' => (int) $version['attachment'],
            ), admin_url('admin-post.php'));
            printf('<li><a href="%s" target="_blank" rel="noopener">Replaced %s</a></li>', esc_url($url), esc_html(wp_date('M j, Y', (int) $version['replaced_at'])));
        }
        echo '</ul>';
    }

    $log = get_post_meta($post->ID, '_glover_audit_log', true);

    if ($log) {
        echo '<details><summary>Activity</summary><ul>';
        foreach (array_reverse((array) $log) as $entry) {
            $user = get_userdata((int) $entry['user']);
            printf(
                '<li>%s: %s%s%s</li>',
                esc_html(wp_date('M j, Y g:i a', (int) $entry['time'])),
                esc_html($entry['action']),
                $user ? esc_html(' by ' . $user->display_name) : '',
                $entry['note'] !== '' ? esc_html(' (' . $entry['note'] . ')') : ''
            );
        }
        echo '</ul></details>';
    }
}

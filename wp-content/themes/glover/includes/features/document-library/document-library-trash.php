<?php

if (!defined('ABSPATH')) {
    exit;
}

/*
 * Documents screen: the Trash and Drafts tabs, and the redirect that sends
 * WordPress's own document list (edit.php?post_type=document-library) to the
 * matching tab, so approvers only ever see the designed Documents screen.
 *
 * Replacements that were merged into a published document stay in the trash
 * behind the scenes (Undo replacement needs them) and are never listed here.
 */

/**
 * Trashed documents people trashed themselves, newest first.
 *
 * @return int[]
 */
function glover_document_library_trash_ids()
{
    return get_posts(array(
        'post_type' => GLOVER_DOCUMENT_LIBRARY_POST_TYPE,
        'post_status' => 'trash',
        'posts_per_page' => 200,
        'fields' => 'ids',
        'no_found_rows' => true,
        'orderby' => 'modified',
        'order' => 'DESC',
        'meta_query' => array(array('key' => '_glover_merged_into', 'compare' => 'NOT EXISTS')),
    ));
}

/**
 * Drafts that were not returned to a submitter (e.g. started in wp-admin).
 *
 * @return int[]
 */
function glover_document_library_draft_ids()
{
    return get_posts(array(
        'post_type' => GLOVER_DOCUMENT_LIBRARY_POST_TYPE,
        'post_status' => 'draft',
        'posts_per_page' => 200,
        'fields' => 'ids',
        'no_found_rows' => true,
        'orderby' => 'modified',
        'order' => 'DESC',
        'meta_query' => array(array('key' => '_glover_returned_reason', 'compare' => 'NOT EXISTS')),
    ));
}

/**
 * Send WordPress's document list to the Documents screen, keeping the status,
 * search and view the link asked for.
 */
function glover_document_library_redirect_classic_list()
{
    if (!empty($_GET['page']) || !isset($_GET['post_type']) || $_GET['post_type'] !== GLOVER_DOCUMENT_LIBRARY_POST_TYPE
        || !glover_document_library_user_can_review()) {
        return;
    }

    $status = isset($_GET['post_status']) ? sanitize_key($_GET['post_status']) : '';
    $tabs = array('pending' => 'pending', 'publish' => 'approved', 'draft' => 'drafts', 'trash' => 'trash');
    $tab = isset($tabs[$status]) ? $tabs[$status] : 'pending';
    $args = array();

    if (!empty($_GET['glover_view'])) {
        $tab = 'approved';
        $args['view'] = sanitize_key($_GET['glover_view']);
    }

    if (!empty($_GET['s'])) {
        $tab = 'approved';
        $args['s'] = sanitize_text_field(wp_unslash($_GET['s']));
    }

    // WordPress comes back here after trashing, restoring or deleting from the edit screen.
    foreach (array('trashed' => 'trashed', 'untrashed' => 'restored', 'deleted' => 'deleted') as $key => $notice) {
        if (!empty($_GET[$key])) {
            $args['glover_notice'] = $notice;
            $tab = $notice === 'restored' ? $tab : ($notice === 'trashed' ? 'trash' : 'trash');
        }
    }

    wp_safe_redirect(glover_document_library_review_screen_url($tab, $args));
    exit;
}
add_action('load-edit.php', 'glover_document_library_redirect_classic_list');

/**
 * Restore or permanently delete a trashed document.
 */
function glover_document_library_handle_trash_action()
{
    $post_id = isset($_POST['post']) ? absint($_POST['post']) : 0;
    $do = isset($_POST['do']) ? sanitize_key($_POST['do']) : '';
    check_admin_referer('glover_document_trash_' . $post_id);

    if (!glover_document_library_user_can_review() || get_post_type($post_id) !== GLOVER_DOCUMENT_LIBRARY_POST_TYPE
        || get_post_status($post_id) !== 'trash' || get_post_meta($post_id, '_glover_merged_into', true)) {
        wp_die('You do not have permission to change this document.', 'Forbidden', array('response' => 403));
    }

    if ($do === 'restore') {
        // Back to the status it had before it was trashed, without re-sending "published" emails.
        $previous = get_post_meta($post_id, '_wp_trash_meta_status', true);
        $restore = function () use ($previous) {
            return $previous ? $previous : 'draft';
        };
        add_filter('wp_untrash_post_status', $restore);
        glover_document_library_restoring(true);
        wp_untrash_post($post_id);
        glover_document_library_restoring(false);
        remove_filter('wp_untrash_post_status', $restore);
        glover_document_library_log($post_id, 'restored from trash');
        $tabs = array('pending' => 'pending', 'publish' => 'approved', 'draft' => 'drafts');
        $tab = isset($tabs[$previous]) ? $tabs[$previous] : 'drafts';

        if ($tab === 'drafts' && get_post_meta($post_id, '_glover_returned_reason', true)) {
            $tab = 'returned';
        }

        wp_safe_redirect(glover_document_library_review_screen_url($tab, array('glover_notice' => 'restored')));
        exit;
    }

    if ($do === 'delete') {
        $attachments = array(glover_document_library_file_id($post_id));

        foreach (glover_document_library_meta_list($post_id, '_glover_previous_files') as $version) {
            $attachments[] = isset($version['attachment']) ? (int) $version['attachment'] : 0;
        }

        foreach (array_unique(array_filter($attachments)) as $attachment_id) {
            wp_delete_attachment($attachment_id, true);
        }

        wp_delete_post($post_id, true);
        wp_safe_redirect(glover_document_library_review_screen_url('trash', array('glover_notice' => 'deleted')));
        exit;
    }

    wp_die('Unknown action.');
}
add_action('admin_post_glover_document_trash', 'glover_document_library_handle_trash_action');

/**
 * Move one returned document, or all of them, to the Trash. Both can be
 * restored from the Trash tab, back to Returned.
 */
function glover_document_library_handle_trash_returned()
{
    $post_id = isset($_POST['post']) ? absint($_POST['post']) : 0;
    check_admin_referer($post_id ? 'glover_document_trash_returned_' . $post_id : 'glover_document_trash_returned_all');

    if (!glover_document_library_user_can_review()) {
        wp_die('You do not have permission to change these documents.', 'Forbidden', array('response' => 403));
    }

    $returned = glover_document_library_returned_ids();
    $ids = $post_id ? array_intersect(array($post_id), $returned) : $returned;

    foreach ($ids as $id) {
        wp_trash_post($id);
        glover_document_library_log($id, 'trashed');
    }

    wp_safe_redirect(glover_document_library_review_screen_url('returned', array('glover_notice' => $post_id ? 'trashed' : 'returned_trashed')));
    exit;
}
add_action('admin_post_glover_document_trash_returned', 'glover_document_library_handle_trash_returned');

/**
 * Red Trash button for one returned document, or Trash all when no ID is
 * given. Trash all confirms in the shared admin dialog.
 *
 * @param int $post_id Document ID, or 0 for all returned documents.
 */
function glover_document_library_trash_returned_button($post_id = 0)
{
    $title = $post_id ? get_post_field('post_title', $post_id, 'raw') : '';

    if (!$post_id) {
        $GLOBALS['glover_document_library_undo_dialog_needed'] = true;
    }
    ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="glover-undo-form<?php echo $post_id ? ' glover-undo-form--direct' : ''; ?>"
        <?php if (!$post_id) : ?>
            data-glover-undo-label="Trash all"
            data-glover-undo-title="<?php echo esc_attr(sprintf(_n('%d returned document', '%d returned documents', count(glover_document_library_returned_ids())), count(glover_document_library_returned_ids()))); ?>"
            data-glover-undo-message="Every returned document moves to the Trash."
            data-glover-undo-note="You can restore them from the Trash tab."
            data-glover-undo-confirm="Trash all"
            data-glover-undo-danger="1"
        <?php endif; ?>>
        <input type="hidden" name="action" value="glover_document_trash_returned">
        <?php if ($post_id) : ?>
            <input type="hidden" name="post" value="<?php echo (int) $post_id; ?>">
        <?php endif; ?>
        <?php wp_nonce_field($post_id ? 'glover_document_trash_returned_' . $post_id : 'glover_document_trash_returned_all'); ?>
        <button type="submit" class="button glover-button-delete"<?php echo $post_id ? ' aria-label="' . esc_attr('Trash: ' . $title) . '"' : ' aria-haspopup="dialog"'; ?>><?php echo $post_id ? 'Trash' : 'Trash all'; ?></button>
    </form>
    <?php
}

/**
 * Whether a restore from the trash is in progress (publish emails are skipped).
 *
 * @param bool|null $set New state, or null to read.
 */
function glover_document_library_restoring($set = null)
{
    static $restoring = false;

    if ($set !== null) {
        $restoring = (bool) $set;
    }

    return $restoring;
}

/**
 * One Restore or Delete permanently button. Delete confirms in the shared
 * admin dialog.
 *
 * @param int    $post_id Document ID.
 * @param string $do      restore or delete.
 */
function glover_document_library_trash_button($post_id, $do)
{
    $title = get_post_field('post_title', $post_id, 'raw');
    $label = $do === 'restore' ? 'Restore' : 'Delete permanently';
    $confirm = $do === 'delete';

    if ($confirm) {
        $GLOBALS['glover_document_library_undo_dialog_needed'] = true;
    }
    ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="glover-undo-form<?php echo $confirm ? '' : ' glover-undo-form--direct'; ?>"
        <?php if ($confirm) : ?>
            data-glover-undo-label="Delete permanently"
            data-glover-undo-title="<?php echo esc_attr($title); ?>"
            data-glover-undo-message="The document and its files are deleted from the website for good. This can't be undone."
            data-glover-undo-note="A copy stays in Google Drive if it was backed up."
            data-glover-undo-confirm="Delete permanently"
            data-glover-undo-danger="1"
        <?php endif; ?>>
        <input type="hidden" name="action" value="glover_document_trash">
        <input type="hidden" name="do" value="<?php echo esc_attr($do); ?>">
        <input type="hidden" name="post" value="<?php echo (int) $post_id; ?>">
        <?php wp_nonce_field('glover_document_trash_' . $post_id); ?>
        <button type="submit" class="button<?php echo $confirm ? ' glover-button-delete' : ''; ?>"<?php echo $confirm ? ' aria-haspopup="dialog"' : ''; ?> aria-label="<?php echo esc_attr($label . ': ' . $title); ?>"><?php echo esc_html($label); ?></button>
    </form>
    <?php
}

/**
 * Trash tab.
 *
 * @param int[] $ids Trashed documents.
 */
function glover_document_library_render_trash_tab($ids)
{
    if (!$ids) {
        echo '<p class="glover-review-empty">The trash is empty.</p>';
        return;
    }

    echo '<p>Restoring puts a document back where it was (Pending, Approved, Returned or Drafts). Deleting removes it and its files from the website for good.</p>';
    ?>
    <table class="wp-list-table widefat striped glover-review-table glover-review-table--approved">
        <caption class="screen-reader-text">Documents in the trash, most recently trashed first</caption>
        <thead>
            <tr>
                <th scope="col" class="column-primary">Title</th>
                <th scope="col">Boards</th>
                <th scope="col">Category</th>
                <th scope="col">Document date</th>
                <th scope="col">Was</th>
                <th scope="col">Trashed</th>
                <th scope="col">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($ids as $post_id) : ?>
                <?php
                $details = glover_document_library_review_details($post_id);
                $was = get_post_meta($post_id, '_wp_trash_meta_status', true);
                $labels = array('publish' => 'Approved', 'pending' => 'Pending', 'draft' => get_post_meta($post_id, '_glover_returned_reason', true) ? 'Returned' : 'Draft');
                $trashed = (int) get_post_meta($post_id, '_wp_trash_meta_time', true);
                ?>
                <tr>
                    <td class="column-primary"><strong><?php echo esc_html($details['Title']); ?></strong></td>
                    <td><?php echo esc_html($details['Boards']); ?></td>
                    <td><?php echo esc_html($details['Category']); ?></td>
                    <td><?php echo esc_html($details['Document date']); ?></td>
                    <td><?php echo esc_html(isset($labels[$was]) ? $labels[$was] : 'Draft'); ?></td>
                    <td><?php echo esc_html($trashed ? wp_date('M j, Y', $trashed) : ''); ?></td>
                    <td class="glover-review-actions">
                        <div class="glover-review-buttons">
                            <?php glover_document_library_trash_button($post_id, 'restore'); ?>
                            <span class="glover-review-sep" aria-hidden="true">|</span>
                            <?php glover_document_library_trash_button($post_id, 'delete'); ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}

/**
 * Drafts tab.
 *
 * @param int[] $ids Drafts.
 */
function glover_document_library_render_drafts_tab($ids)
{
    if (!$ids) {
        echo '<p class="glover-review-empty">There are no drafts.</p>';
        return;
    }

    echo '<p>Documents saved as drafts in wp-admin. They are not on the website until they are published.</p>';
    ?>
    <table class="wp-list-table widefat striped glover-review-table glover-review-table--approved">
        <caption class="screen-reader-text">Draft documents, most recently edited first</caption>
        <thead>
            <tr>
                <th scope="col" class="column-primary">Title</th>
                <th scope="col">Boards</th>
                <th scope="col">Category</th>
                <th scope="col">Document date</th>
                <th scope="col">Last edited</th>
                <th scope="col">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($ids as $post_id) : ?>
                <?php $details = glover_document_library_review_details($post_id); ?>
                <tr>
                    <td class="column-primary"><strong><a href="<?php echo esc_url(get_edit_post_link($post_id, 'url')); ?>"><?php echo esc_html($details['Title'] !== '' ? $details['Title'] : '(no title)'); ?></a></strong></td>
                    <td><?php echo esc_html($details['Boards']); ?></td>
                    <td><?php echo esc_html($details['Category']); ?></td>
                    <td><?php echo esc_html($details['Document date']); ?></td>
                    <td><?php echo esc_html(get_the_modified_date('M j, Y', $post_id)); ?></td>
                    <td class="glover-review-actions">
                        <div class="glover-review-buttons">
                            <a class="button" href="<?php echo esc_url(get_edit_post_link($post_id, 'url')); ?>" aria-label="<?php echo esc_attr('Edit "' . $details['Title'] . '"'); ?>">Edit</a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}

/**
 * Filtered views of the Approved tab, linked from notices and settings.
 *
 * @return array<string, array{label:string, meta:array}>
 */
function glover_document_library_approved_views()
{
    $views = array(
        'auto' => array('label' => 'Published without review', 'meta' => array('key' => '_glover_auto_published', 'value' => '1')),
        'backup_failed' => array('label' => 'Drive backup failed', 'meta' => array('key' => '_glover_drive_backup_status', 'value' => 'failed')),
    );

    if (defined('GLOVER_DOCUMENT_SEARCH_STATUSES')) {
        foreach (GLOVER_DOCUMENT_SEARCH_STATUSES as $status => $label) {
            $views['search_' . $status] = array('label' => 'Text search: ' . $label, 'meta' => array('key' => '_glover_text_index_status', 'value' => $status));
        }

        $views['search_none'] = array('label' => 'Text search: Not yet indexed', 'meta' => array('key' => '_glover_text_index_status', 'compare' => 'NOT EXISTS'));
    }

    return $views;
}

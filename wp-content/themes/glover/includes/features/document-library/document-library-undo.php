<?php

if (!defined('ABSPATH')) {
    exit;
}

/*
 * Undo a mistaken approval, within 7 days:
 * - Move back to Pending: a new document approved by mistake comes off the
 *   public site and returns to the Pending tab.
 * - Undo replacement: a replacement approved by mistake is reversed. The
 *   document gets its previous file and details back, and the replacement
 *   returns to the Pending tab, still grouped with the document it replaces.
 * Emails already sent can't be recalled, and Drive keeps every copy it made.
 */

const GLOVER_DOCUMENT_LIBRARY_UNDO_WINDOW = 7 * DAY_IN_SECONDS;

/**
 * Whether a published document can be moved back to Pending.
 *
 * @param int $post_id Document ID.
 */
function glover_document_library_can_move_back($post_id)
{
    $published_at = (int) get_post_meta($post_id, '_glover_published_at', true);

    // Documents approved before the approval time was recorded: use the
    // most recent "published" entry in the activity log.
    if (!$published_at) {
        foreach (array_reverse(glover_document_library_meta_list($post_id, '_glover_audit_log')) as $entry) {
            if (($entry['action'] ?? '') === 'published') {
                $published_at = (int) $entry['time'];
                break;
            }
        }
    }

    return get_post_status($post_id) === 'publish'
        && $published_at
        && time() - $published_at <= GLOVER_DOCUMENT_LIBRARY_UNDO_WINDOW;
}

/**
 * The most recent replacement that can still be undone, or null.
 *
 * @param int $post_id Document ID.
 * @return array|null History entry.
 */
function glover_document_library_undoable_replacement($post_id)
{
    if (get_post_status($post_id) !== 'publish') {
        return null;
    }

    $history = glover_document_library_meta_list($post_id, '_glover_previous_files');
    $entry = $history ? end($history) : null;

    if (!$entry || empty($entry['snapshot']) || empty($entry['replacement'])
        || time() - (int) $entry['replaced_at'] > GLOVER_DOCUMENT_LIBRARY_UNDO_WINDOW
        || get_post_status((int) $entry['replacement']) !== 'trash') {
        return null;
    }

    return $entry;
}

/**
 * Take a document off the public site and put it back in Pending.
 *
 * @param int $post_id Document ID.
 * @return true|WP_Error
 */
function glover_document_library_move_back_to_pending($post_id)
{
    if (!glover_document_library_can_move_back($post_id)) {
        return new WP_Error('glover_undo_window', 'This document can only be moved back to Pending within 7 days of approval.');
    }

    // The unpublish handler hides the file and removes it from search.
    wp_update_post(array('ID' => $post_id, 'post_status' => 'pending'));
    delete_post_meta($post_id, '_glover_published_at');
    glover_document_library_log($post_id, 'moved back to pending');

    return true;
}

/**
 * Reverse the most recent replacement of a document.
 *
 * @param int $post_id Document ID.
 * @return true|WP_Error
 */
function glover_document_library_undo_replacement($post_id)
{
    $entry = glover_document_library_undoable_replacement($post_id);

    if (!$entry) {
        return new WP_Error('glover_undo_window', 'This replacement can only be undone within 7 days, and only if it was approved after undo was added.');
    }

    $replacement_id = (int) $entry['replacement'];
    $old_attachment = (int) $entry['attachment'];
    $new_attachment = glover_document_library_file_id($post_id);
    $snapshot = $entry['snapshot'];

    // 1. Give the document its previous file and details back.
    update_field('field_glover_document_file', $old_attachment, $post_id);
    update_field('field_glover_document_date', $snapshot['document_date'], $post_id);
    update_field('field_glover_document_minutes_status', $snapshot['minutes_status'], $post_id);
    update_field('field_glover_document_boards', $snapshot['boards'], $post_id);
    wp_set_object_terms($post_id, array_map('intval', (array) $snapshot['categories']), GLOVER_DOCUMENT_LIBRARY_CATEGORY_TAXONOMY, false);
    wp_set_object_terms($post_id, array_map('intval', (array) $snapshot['topics']), GLOVER_DOCUMENT_LIBRARY_TOPIC_TAXONOMY, false);
    wp_update_post(array(
        'ID' => $post_id,
        'post_title' => $snapshot['title'],
        'post_name' => get_post_field('post_name', $post_id, 'raw'),
    ));
    wp_update_post(array('ID' => $old_attachment, 'post_parent' => $post_id));

    $history = glover_document_library_meta_list($post_id, '_glover_previous_files');
    array_pop($history);
    update_post_meta($post_id, '_glover_previous_files', $history);

    $drive_files = glover_document_library_meta_list($post_id, '_glover_drive_files');

    if (isset($drive_files[$old_attachment])) {
        update_post_meta($post_id, '_glover_drive_file_id', $drive_files[$old_attachment]);
    }

    glover_document_library_sync_document_boards($post_id);
    glover_document_library_sync_file_visibility($post_id);

    // 2. Bring the replacement back to Pending with its own file.
    delete_post_meta($replacement_id, '_glover_merged_into');
    $to_pending = function () {
        return 'pending';
    };
    add_filter('wp_untrash_post_status', $to_pending);
    wp_untrash_post($replacement_id);
    remove_filter('wp_untrash_post_status', $to_pending);

    if (get_post_status($replacement_id) !== 'pending') {
        wp_update_post(array('ID' => $replacement_id, 'post_status' => 'pending'));
    }

    if ($new_attachment) {
        update_field('field_glover_document_file', $new_attachment, $replacement_id);
        wp_update_post(array('ID' => $new_attachment, 'post_parent' => $replacement_id));
    }

    glover_document_library_sync_file_visibility($replacement_id);

    // 3. Search shows the restored file's text again.
    if (function_exists('glover_document_search_delete_rows') && function_exists('glover_document_search_queue')) {
        glover_document_search_delete_rows($post_id);
        glover_document_search_queue($post_id);
    }

    glover_document_library_log($post_id, 'replacement undone', 'Submission #' . $replacement_id . ' is back in Pending');
    glover_document_library_log($replacement_id, 'back in pending', 'Replacement of #' . $post_id . ' was undone');
    do_action('glover_document_library/replacement_undone', $post_id, $old_attachment, $new_attachment);

    return true;
}

/**
 * Handle both undo buttons.
 */
function glover_document_library_handle_undo()
{
    $post_id = isset($_POST['post']) ? absint($_POST['post']) : 0;
    $mode = isset($_POST['undo']) ? sanitize_key($_POST['undo']) : '';
    check_admin_referer('glover_document_undo_' . $post_id);

    if (!glover_document_library_user_can_review() || get_post_type($post_id) !== GLOVER_DOCUMENT_LIBRARY_POST_TYPE) {
        wp_die('You do not have permission to undo approvals.', 'Forbidden', array('response' => 403));
    }

    $result = $mode === 'replacement' ? glover_document_library_undo_replacement($post_id) : glover_document_library_move_back_to_pending($post_id);

    if (is_wp_error($result)) {
        wp_die(esc_html($result->get_error_message()), 'Can\'t undo', array('back_link' => true));
    }

    $_REQUEST['back'] = 'review';
    wp_safe_redirect(glover_document_library_after_review_url($mode === 'replacement' ? 'replacement_undone' : 'moved_back'));
    exit;
}
add_action('admin_post_glover_document_undo', 'glover_document_library_handle_undo');

/**
 * Print the undo button(s) available for a document. Each opens the shared
 * confirmation dialog (glover_document_library_render_undo_dialog()).
 *
 * @param int $post_id Document ID.
 */
function glover_document_library_undo_buttons($post_id, $separator = false, $detached = false)
{
    if (!glover_document_library_user_can_review()) {
        return;
    }

    $title = get_post_field('post_title', $post_id, 'raw');
    $buttons = array();

    if (glover_document_library_undoable_replacement($post_id)) {
        $buttons['replacement'] = array(
            'label' => 'Undo replacement',
            'message' => 'The document gets its previous file and details back. The replacement returns to Pending, grouped with this document, so you can approve or return it.',
            'confirm' => 'Undo replacement',
        );
    }

    if (glover_document_library_can_move_back($post_id)) {
        $buttons['pending'] = array(
            'label' => 'Move to Pending',
            'message' => 'The document comes off the public site and out of search, and returns to the Pending tab until it is approved again.',
            'confirm' => 'Move to Pending',
        );
    }

    foreach ($buttons as $mode => $button) {
        $GLOBALS['glover_document_library_undo_dialog_needed'] = true;

        // Inside another form (a document's edit screen) the form itself is
        // printed in the footer and the button points at it.
        if ($detached) {
            $form_id = 'glover-undo-' . $mode . '-' . (int) $post_id;
            ob_start();
        }
        ?>
        <form <?php echo $detached ? 'id="' . esc_attr($form_id) . '" ' : ''; ?>method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="glover-undo-form"
            data-glover-undo-label="<?php echo esc_attr($button['label']); ?>"
            data-glover-undo-title="<?php echo esc_attr($title); ?>"
            data-glover-undo-message="<?php echo esc_attr($button['message']); ?>"
            data-glover-undo-confirm="<?php echo esc_attr($button['confirm']); ?>">
            <input type="hidden" name="action" value="glover_document_undo">
            <input type="hidden" name="undo" value="<?php echo esc_attr($mode); ?>">
            <input type="hidden" name="post" value="<?php echo (int) $post_id; ?>">
            <?php wp_nonce_field('glover_document_undo_' . $post_id, '_wpnonce', true, true); ?>
            <?php if (!$detached) : ?>
                <button type="submit" class="button" aria-haspopup="dialog" aria-label="<?php echo esc_attr($button['label'] . ': ' . $title); ?>"><?php echo esc_html($button['label']); ?></button>
            <?php endif; ?>
        </form>
        <?php
        if ($detached) {
            $GLOBALS['glover_document_library_footer_forms'][] = ob_get_clean();
            printf(
                '<button type="submit" form="%s" class="button" aria-haspopup="dialog" aria-label="%s">%s</button>',
                esc_attr($form_id),
                esc_attr($button['label'] . ': ' . $title),
                esc_html($button['label'])
            );
        }
        if ($separator) {
            echo '<span class="glover-review-sep" aria-hidden="true">|</span>';
        }
    }
}

/**
 * Actions for an approved document, styled like the Pending tab's buttons:
 * any undo that is still available, a separator, then View (opens the file
 * in a new tab) and Edit, which are always shown.
 *
 * @param int $post_id Document ID.
 */
function glover_document_library_approved_actions($post_id)
{
    $title = get_post_field('post_title', $post_id, 'raw');

    echo '<div class="glover-review-buttons">';
    glover_document_library_undo_buttons($post_id, true);
    printf(
        '<a class="button" href="%s" target="_blank" rel="noopener" aria-label="%s">View</a>',
        esc_url(get_permalink($post_id)),
        esc_attr(sprintf('View "%s" (opens the file in a new tab)', $title))
    );
    printf(
        '<a class="button" href="%s" aria-label="%s">Edit</a>',
        esc_url(get_edit_post_link($post_id, 'url')),
        esc_attr(sprintf('Edit "%s"', get_post_field('post_title', $post_id, 'raw')))
    );
    echo '</div>';
}

/**
 * The shared confirmation dialog for undo, built like the Documents
 * screen's Return dialog (WordPress admin styling, never the public site's).
 */
function glover_document_library_render_undo_dialog()
{
    if (empty($GLOBALS['glover_document_library_undo_dialog_needed'])) {
        return;
    }
    ?>
    <dialog id="glover-undo-dialog" class="glover-compare-dialog glover-return-dialog glover-undo-dialog" aria-labelledby="glover-undo-title" aria-describedby="glover-undo-message">
        <div class="glover-compare">
            <div class="glover-compare__header">
                <div class="glover-compare__heading">
                    <span class="glover-compare__label" data-glover-undo-label></span>
                    <h2 id="glover-undo-title" tabindex="-1" data-glover-undo-heading></h2>
                </div>
                <button type="button" class="glover-compare__close" data-glover-undo-close aria-label="Close">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18"/></svg>
                </button>
            </div>
            <div class="glover-compare__body glover-return-dialog__body">
                <p id="glover-undo-message" data-glover-undo-message></p>
                <p class="glover-compare__muted" data-glover-undo-note>Emails that already went out can't be recalled, and Google Drive keeps every copy it made.</p>
            </div>
            <div class="glover-compare__footer">
                <div class="glover-compare__actions">
                    <button type="button" class="button" data-glover-undo-close>Cancel</button>
                    <button type="button" class="button button-primary" data-glover-undo-ok></button>
                </div>
            </div>
        </div>
    </dialog>
    <?php
}
add_action('admin_footer', 'glover_document_library_render_undo_dialog');

/**
 * Forms whose buttons sit inside another form, printed outside it.
 */
function glover_document_library_print_footer_forms()
{
    foreach ((array) ($GLOBALS['glover_document_library_footer_forms'] ?? array()) as $form) {
        echo $form; // phpcs:ignore WordPress.Security.EscapeOutput -- built and escaped by glover_document_library_undo_buttons().
    }
}
add_action('admin_footer', 'glover_document_library_print_footer_forms', 5);

/**
 * Load the dialog's script and the Documents screen styles wherever undo
 * buttons can appear: the Documents screen and a document's edit screen.
 *
 * @param string $hook_suffix Current admin page.
 */
function glover_document_library_undo_assets($hook_suffix)
{
    $screen = get_current_screen();
    $on_documents = defined('GLOVER_DOCUMENT_LIBRARY_REVIEW_PAGE') && strpos($hook_suffix, GLOVER_DOCUMENT_LIBRARY_REVIEW_PAGE) !== false;
    $on_edit = in_array($hook_suffix, array('post.php', 'post-new.php'), true) && $screen && $screen->post_type === GLOVER_DOCUMENT_LIBRARY_POST_TYPE;

    if (!$on_documents && !$on_edit) {
        return;
    }

    $base = get_template_directory_uri() . '/includes/features/document-library/assets/';
    wp_enqueue_style('glover-document-review', $base . 'review-admin.css', array(), (string) filemtime(__DIR__ . '/assets/review-admin.css'));
    wp_enqueue_script('glover-document-undo', $base . 'undo-confirm.js', array(), (string) filemtime(__DIR__ . '/assets/undo-confirm.js'), true);
}
add_action('admin_enqueue_scripts', 'glover_document_library_undo_assets');

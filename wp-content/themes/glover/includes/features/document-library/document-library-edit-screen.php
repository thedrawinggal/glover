<?php

if (!defined('ABSPATH')) {
    exit;
}

/*
 * A document's edit screen, curated like the Documents screen (design: the
 * "Glover Document Edit Screen" canvas). WordPress's Publish box is replaced
 * by one Document box whose buttons follow the document's state; Details and
 * History hold what the review needs and the record. Everything uses
 * WordPress's own admin markup and classes, so it follows the admin color
 * scheme.
 */

/**
 * The document's state for the edit screen.
 *
 * @param WP_Post $post Document.
 * @return string pending, approved, returned or draft.
 */
function glover_document_library_edit_state($post)
{
    if ($post->post_status === 'publish') {
        return 'approved';
    }

    if ($post->post_status === 'pending') {
        return 'pending';
    }

    if ($post->post_status === 'draft' && get_post_meta($post->ID, '_glover_returned_reason', true)) {
        return 'returned';
    }

    return 'draft';
}

/**
 * The most recent activity-log entry with one of these actions, or null.
 *
 * @param int      $post_id Document ID.
 * @param string[] $actions Actions, e.g. array('approved', 'auto-approved').
 * @return array|null
 */
function glover_document_library_last_log($post_id, array $actions)
{
    foreach (array_reverse(glover_document_library_meta_list($post_id, '_glover_audit_log')) as $entry) {
        if (in_array($entry['action'] ?? '', $actions, true)) {
            return $entry;
        }
    }

    return null;
}

/**
 * "Olivia, Nov 5, 10:15 am" for a log entry.
 *
 * @param array|null $entry Log entry.
 */
function glover_document_library_log_line($entry)
{
    if (!$entry) {
        return '';
    }

    $user = get_userdata((int) $entry['user']);

    return trim(($user ? $user->display_name . ', ' : '') . wp_date('M j, g:i a', (int) $entry['time']));
}

/**
 * Swap WordPress's Publish box and the old Review details box for the
 * Document, Details and History boxes.
 */
function glover_document_library_edit_screen_boxes()
{
    remove_meta_box('submitdiv', GLOVER_DOCUMENT_LIBRARY_POST_TYPE, 'side');

    add_meta_box('glover-document-status', 'Document', 'glover_document_library_render_status_box', GLOVER_DOCUMENT_LIBRARY_POST_TYPE, 'side', 'high');
    add_meta_box('glover-document-details', 'Details', 'glover_document_library_render_details_box', GLOVER_DOCUMENT_LIBRARY_POST_TYPE, 'side', 'default');
    add_meta_box('glover-document-history', 'History', 'glover_document_library_render_history_box', GLOVER_DOCUMENT_LIBRARY_POST_TYPE, 'side', 'low');
}
add_action('add_meta_boxes_' . GLOVER_DOCUMENT_LIBRARY_POST_TYPE, 'glover_document_library_edit_screen_boxes', 20);

/**
 * The Document box: status lines and the one set of actions for this state.
 * Its markup is WordPress's own Publish box layout, so it looks native.
 *
 * @param WP_Post $post Document.
 */
function glover_document_library_render_status_box($post)
{
    $state = glover_document_library_edit_state($post);
    $reviewer = glover_document_library_user_can_review();
    $submitter = glover_document_library_review_submitter($post->ID);
    $submitted = get_post_meta($post->ID, '_glover_submitted_by', true);
    $statuses = array(
        'pending' => 'Waiting for review',
        'approved' => 'On the website',
        'returned' => 'Returned to the submitter',
        'draft' => 'Draft, not submitted',
    );
    // An unsaved new document becomes a draft; otherwise the status is kept.
    $keep_status = $post->post_status === 'auto-draft' ? 'draft' : $post->post_status;
    ?>
    <div class="submitbox" id="submitpost">
        <input type="hidden" name="post_status" value="<?php echo esc_attr($keep_status); ?>">
        <div id="minor-publishing">
            <?php if ($reviewer && in_array($state, array('pending', 'draft'), true)) : ?>
                <div id="minor-publishing-actions" class="glover-edit-review-actions">
                    <?php if ($state === 'pending') : ?>
                        <a class="button" href="<?php echo esc_url(glover_document_library_return_url($post->ID)); ?>" aria-haspopup="dialog" <?php echo glover_document_library_return_data($post->ID); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the helper. ?>>Return</a>
                        <button type="submit" name="glover_approve" value="1" class="button button-primary">Approve</button>
                    <?php else : ?>
                        <button type="submit" name="glover_approve" value="1" class="button button-primary">Publish</button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <div id="misc-publishing-actions" class="glover-edit-facts">
                <div class="misc-pub-section glover-edit-status-row">
                    <?php glover_document_library_status_pill($state); ?>
                    <?php if ($state === 'approved' && glover_document_library_file_url($post->ID)) : ?>
                        <a class="glover-edit-external" href="<?php echo esc_url(glover_document_library_file_url($post->ID)); ?>" target="_blank" rel="noopener">Preview<span class="screen-reader-text"> (opens in a new tab)</span><span class="dashicons dashicons-external" aria-hidden="true"></span></a>
                    <?php endif; ?>
                </div>
                <dl class="misc-pub-section glover-edit-dl">
                    <?php if ($state === 'approved') : ?>
                        <?php glover_document_library_fact('yes', 'Approved', glover_document_library_log_line(glover_document_library_last_log($post->ID, array('approved', 'auto-approved', 'replaced')))); ?>
                    <?php elseif ($state === 'returned') : ?>
                        <?php glover_document_library_fact('undo', 'Returned', glover_document_library_log_line(glover_document_library_last_log($post->ID, array('returned')))); ?>
                    <?php endif; ?>
                    <?php if ($submitted) : ?>
                        <?php glover_document_library_fact('admin-users', 'Submitted', $submitter['name'] . ' · ' . get_the_date('M j, g:i a', $post)); ?>
                    <?php endif; ?>
                </dl>
                <?php if ($state === 'returned') : ?>
                    <div class="misc-pub-section">
                        <p class="glover-edit-label"><?php echo esc_html('Reason sent to ' . glover_document_library_first_name($submitter)); ?></p>
                        <p class="glover-edit-quote"><?php echo nl2br(esc_html(get_post_meta($post->ID, '_glover_returned_reason', true))); ?></p>
                    </div>
                <?php endif; ?>
                <?php if ($state === 'approved' && $reviewer && (glover_document_library_can_move_back($post->ID) || glover_document_library_undoable_replacement($post->ID))) : ?>
                    <?php
                    $published = (int) get_post_meta($post->ID, '_glover_published_at', true);
                    $until = $published ? wp_date('M j', $published + GLOVER_DOCUMENT_LIBRARY_UNDO_WINDOW) : '';
                    ?>
                    <div class="misc-pub-section">
                        <div class="glover-edit-panel">
                            <p><strong>Approved by mistake?</strong><br><span class="glover-edit-muted"><?php echo esc_html($until ? 'You can undo it until ' . $until . '.' : 'You can undo it for 7 days.'); ?></span></p>
                            <div class="glover-edit-buttons"><?php glover_document_library_undo_buttons($post->ID, false, true); ?></div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div id="major-publishing-actions">
            <?php if ($post->post_status !== 'auto-draft' && current_user_can('delete_post', $post->ID)) : ?>
                <div id="delete-action">
                    <a class="submitdelete deletion" href="<?php echo esc_url(get_delete_post_link($post->ID)); ?>">Move to Trash</a>
                </div>
            <?php endif; ?>
            <div id="publishing-action">
                <?php if ($state === 'approved') : ?>
                    <button type="submit" name="save" value="update" class="button button-primary button-large">Update</button>
                <?php elseif ($state === 'draft') : ?>
                    <button type="submit" name="save" value="save" class="button button-large">Save draft</button>
                <?php else : ?>
                    <?php // Approve is the main action on a pending document, so Save changes stays secondary. ?>
                    <button type="submit" name="save" value="save" class="button<?php echo $state === 'pending' && $reviewer ? '' : ' button-primary'; ?> button-large">Save changes</button>
                <?php endif; ?>
            </div>
            <div class="clear"></div>
        </div>
    </div>
    <?php
}

/**
 * Approve (or Publish) from the Document box: WordPress saves the edits
 * first, then the document is published and the approver goes back to the
 * Documents screen, as from the queue.
 *
 * @param string $location Where WordPress would go after saving.
 * @param int    $post_id  Document ID.
 * @return string
 */
function glover_document_library_approve_after_save($location, $post_id)
{
    if (empty($_POST['glover_approve']) || get_post_type($post_id) !== GLOVER_DOCUMENT_LIBRARY_POST_TYPE
        || !glover_document_library_user_can_review() || !in_array(get_post_status($post_id), array('pending', 'draft'), true)) {
        return $location;
    }

    glover_document_library_log($post_id, 'approved');
    wp_publish_post($post_id);

    return glover_document_library_after_review_url('approved');
}
add_filter('redirect_post_location', 'glover_document_library_approve_after_save', 10, 2);

/**
 * The Details box: what a reviewer checks.
 *
 * @param WP_Post $post Document.
 */
function glover_document_library_render_details_box($post)
{
    $published = $post->post_status === 'publish';
    // The note itself shows below, so no "Note from submitter" tag here.
    $tags = array_values(array_filter(glover_document_library_email_tags($post->ID, true), function ($tag) {
        return $tag['label'] !== 'Note from submitter';
    }));
    $versions = count(glover_document_library_meta_list($post->ID, '_glover_previous_files'));

    if ($published && $versions) {
        $tags[] = array('label' => sprintf(_n('Replaced %d time', 'Replaced %d times', $versions), $versions));
    }

    if (get_post_meta($post->ID, '_glover_auto_published', true)) {
        $tags[] = array('label' => 'Published without review');
    }

    echo '<div class="glover-edit-details">';

    if ($tags) {
        echo '<div class="glover-review-tags">';
        foreach ($tags as $tag) {
            echo '<span class="glover-review-tag">' . esc_html($tag['label']) . '</span>';
        }
        echo '</div>';
    }

    $note = (string) get_post_meta($post->ID, 'document_reviewer_note', true);

    if ($note !== '') {
        $submitter = glover_document_library_review_submitter($post->ID);
        echo '<div class="glover-edit-note"><div class="glover-edit-note__who">';
        echo $submitter['user'] ? get_avatar($submitter['user']->ID, 28, '', '') : ''; // phpcs:ignore WordPress.Security.EscapeOutput -- core output.
        echo '<strong>' . esc_html('Note from ' . glover_document_library_first_name($submitter)) . '</strong></div>';
        echo '<p class="glover-edit-quote">' . nl2br(esc_html($note)) . '</p></div>';
    }

    $rows = array();
    $original = (int) get_field('document_replaces', $post->ID, false);

    if ($original && !$published) {
        $rows[] = array('Replaces', sprintf('<a href="%s">%s</a>', esc_url(get_edit_post_link($original)), esc_html(get_the_title($original))));
    }

    foreach (glover_document_library_meta_list($post->ID, '_glover_possible_duplicates') as $duplicate_id) {
        if ($duplicate_id && get_post($duplicate_id)) {
            $rows[] = array('May duplicate', sprintf('<a href="%s">%s</a>', esc_url(get_edit_post_link($duplicate_id)), esc_html(get_the_title($duplicate_id))));
        }
    }

    $backup = get_post_meta($post->ID, '_glover_drive_backup_status', true);
    $statuses = GLOVER_DOCUMENT_LIBRARY_BACKUP_STATUSES;
    $rows[] = array('Drive backup', $published
        ? glover_document_library_fact_value(isset($statuses[$backup]) ? $statuses[$backup] : 'Not yet requested', (string) get_post_meta($post->ID, '_glover_drive_backup_note', true))
        : '<span class="glover-edit-muted">After approval</span>');

    $index = get_post_meta($post->ID, '_glover_text_index_status', true);
    $rows[] = array('Text search', $published
        ? glover_document_library_fact_value(isset(GLOVER_DOCUMENT_SEARCH_STATUSES[$index]) ? GLOVER_DOCUMENT_SEARCH_STATUSES[$index] : 'Not yet indexed', (string) get_post_meta($post->ID, '_glover_text_index_note', true))
        : '<span class="glover-edit-muted">After approval</span>');

    echo '<dl class="glover-edit-rows">';
    foreach ($rows as $row) {
        echo '<div><dt>' . esc_html($row[0]) . '</dt><dd>' . $row[1] . '</dd></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts above.
    }
    echo '</dl>';

    $reviewer = glover_document_library_user_can_review();
    echo '<div class="glover-edit-buttons glover-edit-buttons--start">';

    if (glover_document_library_file_id($post->ID) && $reviewer) {
        glover_document_library_preview_link($post->ID, 'check');
    }

    if ($published && $reviewer && $index !== 'excluded') {
        printf('<a class="button" href="%s">Re-index text</a>', esc_url(glover_document_search_reindex_url($post->ID)));
    }

    if ($published && $reviewer && in_array($backup, array('failed', 'not_configured'), true)
        && function_exists('glover_document_library_drive_ready') && glover_document_library_drive_ready()) {
        printf('<a class="button" href="%s">Retry backup</a>', esc_url(glover_document_library_drive_retry_url($post->ID)));
    }

    echo '</div></div>';
}

/**
 * The History box: earlier files and the activity log as a timeline.
 *
 * @param WP_Post $post Document.
 */
function glover_document_library_render_history_box($post)
{
    $history = glover_document_library_meta_list($post->ID, '_glover_previous_files');
    // "published" and "unpublished" repeat the approve and undo entries beside them.
    $log = array_values(array_filter(array_reverse(glover_document_library_meta_list($post->ID, '_glover_audit_log')), function ($entry) {
        return !in_array($entry['action'] ?? '', array('published', 'unpublished'), true);
    }));

    if (!$history && !$log) {
        echo '<p class="glover-edit-muted">No activity yet.</p>';
        return;
    }

    echo '<div class="glover-edit-history">';

    if ($history) {
        echo '<h3 class="glover-edit-label">Previous versions</h3><ul class="glover-edit-versions">';
        foreach (array_reverse($history) as $version) {
            $attachment = (int) $version['attachment'];
            $path = get_attached_file($attachment);
            $type = $path ? strtoupper((string) pathinfo($path, PATHINFO_EXTENSION)) : '';
            $size = $path && file_exists($path) ? size_format(filesize($path)) : '';
            $name = !empty($version['snapshot']['title']) ? $version['snapshot']['title'] : 'Earlier version';
            $url = add_query_arg(array('action' => 'glover_document_file', 'document' => $post->ID, 'attachment' => $attachment), admin_url('admin-post.php'));
            printf(
                '<li><span class="dashicons dashicons-media-document" aria-hidden="true"></span><span><a href="%s" target="_blank" rel="noopener">%s</a><br><span class="glover-edit-muted">%s</span></span></li>',
                esc_url($url),
                esc_html($name . ($type ? ', ' . $type : '') . ($size ? ' ' . $size : '')),
                esc_html('Replaced ' . wp_date('M j, Y', (int) $version['replaced_at']))
            );
        }
        echo '</ul>';
    }

    if ($log) {
        echo '<h3 class="glover-edit-label">Activity</h3><ol class="glover-edit-timeline">';
        foreach ($log as $entry) {
            $user = get_userdata((int) $entry['user']);
            $who = $user ? $user->display_name . ' · ' : '';
            printf(
                '<li><strong>%s</strong><br><span class="glover-edit-muted">%s</span></li>',
                esc_html(glover_document_library_activity_label($entry)),
                esc_html($who . wp_date('M j, g:i a', (int) $entry['time']))
            );
        }
        echo '</ol>';
    }

    echo '</div>';
}

/**
 * Plain wording for an activity-log entry.
 *
 * @param array $entry Log entry.
 */
function glover_document_library_activity_label($entry)
{
    $labels = array(
        'submitted' => 'Submitted',
        'approved' => 'Approved',
        'auto-approved' => 'Published without review',
        'returned' => 'Returned',
        'replaced' => 'New version approved',
        'merged' => 'Merged into the published document',
        'moved back to pending' => 'Moved back to Pending',
        'restored from trash' => 'Restored from the Trash',
        'trashed' => 'Moved to the Trash',
        'reindexed' => 'Text re-indexed',
    );
    $action = (string) ($entry['action'] ?? '');

    if ($action === 'title tidied') {
        return 'Title tidied' . (!empty($entry['note']) ? ' ' . $entry['note'] : '');
    }

    return isset($labels[$action]) ? $labels[$action] : ucfirst($action);
}

/**
 * A status pill: Live, Pending review, Returned or Draft.
 *
 * @param string $state Edit-screen state.
 */
function glover_document_library_status_pill($state)
{
    $labels = array('approved' => 'Live', 'pending' => 'Pending review', 'returned' => 'Returned', 'draft' => 'Draft');
    printf('<span class="glover-status-pill glover-status-pill--%s">%s</span>', esc_attr($state), esc_html($labels[$state]));
}

/**
 * One labelled fact in the Document box, with a WordPress dashicon.
 *
 * @param string $icon  Dashicon name without the prefix.
 * @param string $label Label.
 * @param string $value Value (plain text).
 */
function glover_document_library_fact($icon, $label, $value)
{
    if ($value === '') {
        return;
    }

    printf(
        '<div class="glover-edit-fact"><span class="dashicons dashicons-%s" aria-hidden="true"></span><div><dt>%s</dt><dd>%s</dd></div></div>',
        esc_attr($icon),
        esc_html($label),
        esc_html($value)
    );
}

/**
 * A Details value: the status, with an optional note on a second line.
 *
 * @param string $status Status.
 * @param string $note   Note.
 * @return string HTML.
 */
function glover_document_library_fact_value($status, $note = '')
{
    return '<strong>' . esc_html($status) . '</strong>' . ($note !== '' ? '<br><span class="glover-edit-muted">' . esc_html($note) . '</span>' : '');
}

/**
 * A submitter's first name, falling back to their display name.
 *
 * @param array $submitter glover_document_library_review_submitter() result.
 */
function glover_document_library_first_name($submitter)
{
    return $submitter['user'] && $submitter['user']->first_name !== '' ? $submitter['user']->first_name : $submitter['name'];
}

/**
 * History starts closed until someone opens it; WordPress then remembers.
 *
 * @param string[] $classes Box classes.
 * @return string[]
 */
function glover_document_library_history_closed($classes)
{
    $closed = get_user_option('closedpostboxes_' . GLOVER_DOCUMENT_LIBRARY_POST_TYPE);

    if ($closed === false && !in_array('closed', $classes, true)) {
        $classes[] = 'closed';
    }

    return $classes;
}
add_filter('postbox_classes_' . GLOVER_DOCUMENT_LIBRARY_POST_TYPE . '_glover-document-history', 'glover_document_library_history_closed');

/**
 * "Back to Documents" and the status tag. The script moves them beside the
 * heading; without it they sit just under it.
 *
 * @param WP_Post $post Document.
 */
function glover_document_library_edit_screen_header($post)
{
    if ($post->post_type !== GLOVER_DOCUMENT_LIBRARY_POST_TYPE || $post->post_status === 'auto-draft') {
        return;
    }

    $state = glover_document_library_edit_state($post);
    $tabs = array('pending' => array('pending', 'Pending'), 'approved' => array('approved', 'Approved'), 'returned' => array('returned', 'Returned'), 'draft' => array('drafts', 'Drafts'));
    $labels = array('pending' => 'Pending review', 'approved' => 'Approved', 'returned' => 'Returned', 'draft' => 'Draft');
    printf(
        '<p class="glover-edit-back" data-glover-edit-back><a href="%s">← Back to Documents (%s)</a></p><span class="glover-review-tag glover-edit-status" data-glover-edit-status>%s</span>',
        esc_url(glover_document_library_review_screen_url($tabs[$state][0])),
        esc_html($tabs[$state][1]),
        esc_html($labels[$state])
    );
}
add_action('edit_form_top', 'glover_document_library_edit_screen_header');

/**
 * The address row under the title: the document's permanent link with a
 * Copy link button, and no slug editing (changing it would break links
 * people have shared). Returned and draft documents have no link yet.
 *
 * @param string $html    WordPress's permalink row.
 * @param int    $post_id Post ID.
 * @return string
 */
function glover_document_library_link_row($html, $post_id)
{
    if (get_post_type($post_id) !== GLOVER_DOCUMENT_LIBRARY_POST_TYPE) {
        return $html;
    }

    $status = get_post_status($post_id);

    if (!in_array($status, array('publish', 'pending'), true)) {
        return '';
    }

    list($pattern, $slug) = get_sample_permalink($post_id);
    $url = str_replace(array('%pagename%', '%postname%'), $slug, $pattern);
    $shown = preg_replace('#^https?://#', '', $url);

    return sprintf(
        '<strong>Link:</strong> %s <button type="button" class="button button-small" data-glover-copy="%s">Copy link</button>',
        $status === 'publish' ? '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($shown) . '</a>' : '<span>' . esc_html($shown) . '</span>',
        esc_attr($url)
    );
}
add_filter('get_sample_permalink_html', 'glover_document_library_link_row', 10, 2);

/**
 * Notices after saving, in document terms and without "Preview post" links
 * (documents have no page of their own).
 *
 * @param array $messages Messages by post type.
 * @return array
 */
function glover_document_library_updated_messages($messages)
{
    $saved = 'Changes saved.';
    $messages[GLOVER_DOCUMENT_LIBRARY_POST_TYPE] = array(
        0 => '',
        1 => $saved,
        2 => $saved,
        3 => $saved,
        4 => $saved,
        5 => 'Earlier version restored.',
        6 => 'Document published.',
        7 => $saved,
        8 => $saved,
        9 => $saved,
        10 => 'Draft saved.',
    );

    return $messages;
}
add_filter('post_updated_messages', 'glover_document_library_updated_messages');

<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Send a Document Library email in the site's shared layout
 * (glover_email_send() in the Glover Google Workspace must-use plugin).
 *
 * @param string|string[] $to      Recipient address(es).
 * @param string          $subject Subject line.
 * @param array           $args    glover_email_render() arguments.
 * @param string[]        $headers Extra headers, e.g. Reply-To.
 * @return bool
 */
function glover_document_library_mail($to, $subject, array $args, array $headers = array())
{
    if (!function_exists('glover_email_send')) {
        return false;
    }

    return glover_email_send($to, $subject, $args, $headers);
}

/**
 * Email addresses of the approvers.
 *
 * @return string[]
 */
function glover_document_library_approver_emails()
{
    $emails = array();

    foreach (glover_document_library_approver_ids() as $user_id) {
        $user = get_userdata($user_id);

        if ($user) {
            $emails[] = $user->user_email;
        }
    }

    return $emails;
}

/**
 * "Questions? Town Clerk: …" for emails to submitters, when a help email is set.
 *
 * @return array Footer arguments.
 */
function glover_document_library_email_clerk_footer()
{
    $email = glover_document_library_help_contact()['email'];

    if (!$email) {
        return array();
    }

    return array('footer' => 'Questions? Town Clerk:', 'footer_link' => array('label' => $email, 'url' => 'mailto:' . $email));
}

/**
 * A document's tag list for emails.
 *
 * @param int  $post_id  Document ID.
 * @param bool $reviewer Include the reviewer-only tags (duplicate, note).
 * @return array[]
 */
function glover_document_library_email_tags($post_id, $reviewer = false)
{
    $tags = array();
    $original = (int) get_field('document_replaces', $post_id, false);

    if ($reviewer && $original && get_post_status($original) === 'publish') {
        $tags[] = array('label' => 'Updated version', 'changed' => true);
    }

    if (get_field('document_minutes_status', $post_id, false) === 'draft') {
        $tags[] = array('label' => 'Draft minutes', 'changed' => false);
    }

    if ($reviewer && get_post_meta($post_id, '_glover_possible_duplicates', true)) {
        $tags[] = array('label' => 'Possible duplicate', 'changed' => false);
    }

    if ($reviewer && get_post_meta($post_id, 'document_reviewer_note', true)) {
        $tags[] = array('label' => 'Note from submitter', 'changed' => false);
    }

    return $tags;
}

/**
 * Email the approvers a summary of every document waiting for review,
 * newest first. Sent each time a document is submitted.
 *
 * @param int $post_id The document just submitted (unused; the summary lists all).
 */
function glover_document_library_notify_pending($post_id = 0)
{
    $waiting = get_posts(array(
        'post_type' => GLOVER_DOCUMENT_LIBRARY_POST_TYPE,
        'post_status' => 'pending',
        'posts_per_page' => -1,
        'orderby' => 'date',
        'order' => 'DESC',
        'fields' => 'ids',
        'no_found_rows' => true,
    ));

    if (!$waiting) {
        return false;
    }

    $count = count($waiting);
    $items = array();

    foreach ($waiting as $id) {
        $submitter = glover_document_library_review_submitter($id);
        $boards = implode(', ', array_map('get_the_title', glover_document_library_document_board_ids($id)));
        $items[] = array(
            'title' => get_post_field('post_title', $id, 'raw'),
            'url' => admin_url('post.php?post=' . (int) $id . '&action=edit'),
            'tags' => glover_document_library_email_tags($id, true),
            'meta' => trim($boards . ' · ' . $submitter['name'] . ', ' . get_the_date('M j, g:i a', $id), ' ·'),
        );
    }

    return glover_document_library_mail(
        glover_document_library_approver_emails(),
        sprintf(_n('%d document waiting for review', '%d documents waiting for review', $count), $count),
        array(
            'heading' => sprintf(_n('%d document is waiting for your review', '%d documents are waiting for your review', $count), $count),
            'intro' => $count === 1 ? "It won't appear on the website until you approve it." : 'None of them appear on the website until you approve them.',
            'items' => $items,
            'button' => array('label' => 'Open the Documents screen', 'url' => glover_document_library_review_screen_url('pending')),
            'footer' => "You're an approver for the Town of Glover Document Library.",
        )
    );
}

/**
 * The submitter of a document, or null.
 *
 * @param int $post_id Document ID.
 * @return WP_User|null
 */
function glover_document_library_email_submitter($post_id)
{
    $user = get_userdata((int) get_post_field('post_author', $post_id));

    return $user ? $user : null;
}

/**
 * Tell the submitter their document is live.
 *
 * @param int $post_id Document ID.
 */
function glover_document_library_notify_published($post_id)
{
    $submitter = glover_document_library_email_submitter($post_id);

    if (!$submitter) {
        return;
    }

    $details = glover_document_library_review_details($post_id);
    $title = get_post_field('post_title', $post_id, 'raw');
    $args = array(
        'heading' => 'Your document is on the website',
        'card' => array(
            'title' => $title,
            'tags' => glover_document_library_email_tags($post_id),
            'rows' => array_values(array_filter(array(
                $details['Boards'] !== '' ? array('label' => 'Board', 'value' => $details['Boards']) : null,
                $details['Category'] !== '' ? array('label' => 'Category', 'value' => $details['Category']) : null,
                $details['Document date'] !== '' ? array('label' => 'Document date', 'value' => $details['Document date']) : null,
                array('label' => 'Approved', 'value' => wp_date('M j, Y')),
            ))),
        ),
        'button' => array('label' => 'View the document', 'url' => get_permalink($post_id)),
    );

    if (glover_document_library_is_draft_minutes($post_id)) {
        $args['secondary'] = array(
            'before' => 'When the board approves these minutes, ',
            'label' => 'submit the approved version',
            'url' => glover_document_library_submission_url(),
            'after' => ' as a replacement.',
        );
    }

    glover_document_library_mail($submitter->user_email, 'Published: ' . $title, $args + glover_document_library_email_clerk_footer());
}

/**
 * Tell the submitter their new version replaced the published document.
 *
 * @param int $original_id    The published document, now showing the new version.
 * @param int $replacement_id The submission that brought the new version.
 */
function glover_document_library_notify_replaced($original_id, $replacement_id)
{
    $submitter = glover_document_library_email_submitter($replacement_id);

    if (!$submitter) {
        return;
    }

    $details = glover_document_library_review_details($original_id);
    $title = get_post_field('post_title', $original_id, 'raw');

    glover_document_library_mail($submitter->user_email, 'Update published: ' . $title, array(
        'heading' => 'Your updated version is on the website',
        'intro' => 'Links to this document now open your new version.',
        'card' => array(
            'title' => $title,
            'tags' => array(array('label' => 'Updated version', 'changed' => true)),
            'rows' => array_values(array_filter(array(
                $details['Boards'] !== '' ? array('label' => 'Board', 'value' => $details['Boards']) : null,
                $details['Minutes status'] !== '' ? array('label' => 'Minutes status', 'value' => $details['Minutes status']) : null,
                $details['Document date'] !== '' ? array('label' => 'Document date', 'value' => $details['Document date']) : null,
                array('label' => 'Approved', 'value' => wp_date('M j, Y')),
            ))),
        ),
        'button' => array('label' => 'View the document', 'url' => get_permalink($original_id)),
    ) + glover_document_library_email_clerk_footer());
}

/**
 * Tell the submitter their document was returned, with the reason.
 *
 * @param int    $post_id Document ID.
 * @param string $reason  The approver's reason.
 */
function glover_document_library_notify_returned($post_id, $reason)
{
    $submitter = glover_document_library_email_submitter($post_id);

    if (!$submitter) {
        return;
    }

    $details = glover_document_library_review_details($post_id);
    $title = get_post_field('post_title', $post_id, 'raw');
    $args = array(
        'heading' => 'Your document needs changes',
        'intro' => "It isn't on the website. Make the changes below and submit it again.",
        'card' => array(
            'title' => $title,
            'rows' => array_values(array_filter(array(
                $details['Boards'] !== '' ? array('label' => 'Board', 'value' => $details['Boards']) : null,
                array('label' => 'Submitted', 'value' => get_the_date('M j, Y, g:i a', $post_id)),
            ))),
        ),
        'note' => array('label' => 'What to change', 'text' => (string) $reason),
        'button' => array('label' => 'Submit a corrected version', 'url' => glover_document_library_submission_url()),
    );
    $requirements = (int) glover_document_library_setting('requirements_page', 0);

    if ($requirements && get_post_status($requirements) === 'publish') {
        $args['secondary'] = array('label' => 'Document requirements', 'url' => get_permalink($requirements));
    }

    glover_document_library_mail($submitter->user_email, 'Needs changes: ' . $title, $args + glover_document_library_email_clerk_footer());
}

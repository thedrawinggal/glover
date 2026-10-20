<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Send a plain-text Document Library email. Delivery depends on the site's
 * mail setup (Google Workspace once access is granted).
 *
 * @param string|string[] $to      Recipient address(es).
 * @param string          $subject Subject line.
 * @param string[]        $lines   Body lines.
 */
function glover_document_library_mail($to, $subject, array $lines)
{
    $to = array_filter((array) $to, 'is_email');

    if (!$to) {
        return false;
    }

    $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);

    return wp_mail($to, '[' . $site . '] ' . $subject, implode("\n\n", $lines));
}

/**
 * Email addresses of the approver and backup approver.
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
 * Tell the approvers a document is waiting.
 *
 * @param int $post_id Document ID.
 */
function glover_document_library_notify_pending($post_id)
{
    $submitter = get_userdata((int) get_post_field('post_author', $post_id));
    $lines = array(
        sprintf('A document is waiting for your review: "%s".', get_the_title($post_id)),
        'Submitted by: ' . ($submitter ? $submitter->display_name : 'unknown'),
    );

    $suggested = get_post_meta($post_id, 'document_suggested_topic', true);

    if ($suggested) {
        $lines[] = 'Suggested new topic: ' . $suggested;
    }

    if (get_post_meta($post_id, '_glover_possible_duplicates', true)) {
        $lines[] = 'This may duplicate an existing document. Please check before approving.';
    }

    $lines[] = 'Review it here: ' . admin_url('post.php?post=' . (int) $post_id . '&action=edit');

    glover_document_library_mail(glover_document_library_approver_emails(), 'Document waiting for review', $lines);
}

/**
 * Tell the approvers about an auto-approved document that suggested a new topic.
 *
 * @param int $post_id Document ID.
 */
function glover_document_library_notify_topic_suggestion($post_id)
{
    $suggested = get_post_meta($post_id, 'document_suggested_topic', true);

    if (!$suggested) {
        return;
    }

    glover_document_library_mail(glover_document_library_approver_emails(), 'New topic suggested', array(
        sprintf('"%s" was published and suggests a new topic: %s', get_the_title($post_id), $suggested),
        'Add it under Document Library > Topics if it fits: ' . admin_url('edit-tags.php?taxonomy=' . GLOVER_DOCUMENT_LIBRARY_TOPIC_TAXONOMY . '&post_type=' . GLOVER_DOCUMENT_LIBRARY_POST_TYPE),
    ));
}

/**
 * Tell the submitter their document is live.
 *
 * @param int $post_id Document ID.
 */
function glover_document_library_notify_published($post_id)
{
    $submitter = get_userdata((int) get_post_field('post_author', $post_id));

    if (!$submitter) {
        return;
    }

    glover_document_library_mail($submitter->user_email, 'Your document is published', array(
        sprintf('"%s" is now on the website.', get_the_title($post_id)),
        get_permalink($post_id),
    ));
}

/**
 * Tell the submitter their document was returned, with the reason.
 *
 * @param int    $post_id Document ID.
 * @param string $reason  The approver's reason.
 */
function glover_document_library_notify_returned($post_id, $reason)
{
    $submitter = get_userdata((int) get_post_field('post_author', $post_id));

    if (!$submitter) {
        return;
    }

    glover_document_library_mail($submitter->user_email, 'Your document needs changes', array(
        sprintf('"%s" was returned and is not on the website.', get_the_title($post_id)),
        'Reason: ' . $reason,
        'Please fix it and submit it again: ' . glover_document_library_submission_url(),
    ));
}

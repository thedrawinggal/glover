<?php

if (!defined('ABSPATH')) {
    exit;
}

/*
 * Extension points for the parts deferred until Google Workspace access exists.
 *
 * Drive backup (Phase 3): register a handler on the
 * 'glover_document_library/backup_handlers' filter, then listen to
 * 'glover_document_library/backup_requested'. Record progress with
 * glover_document_library_set_backup_status() and store the Drive file ID
 * in the _glover_drive_file_id meta (the ID, never a URL).
 *
 * Google and emailed-code sign-in (Phase 2): the Glover Google Workspace
 * must-use plugin signs people in as ordinary WordPress users, on Submit a
 * Document's own sign-in panel and on the restyled WordPress login. Who may submit, their boards and Auto-approve live in
 * Settings > Uploaders, so the rest of the feature does not change.
 *
 * Lifecycle actions fired by this feature:
 * - glover_document_library/document_published   ($post_id)
 * - glover_document_library/document_unpublished ($post_id)
 * - glover_document_library/document_replaced    ($post_id, $new_attachment_id, $previous_attachment_id)
 */

const GLOVER_DOCUMENT_LIBRARY_BACKUP_STATUSES = array(
    'not_configured' => 'Not configured',
    'queued' => 'Queued',
    'backed_up' => 'Backed up',
    'failed' => 'Failed',
);

/**
 * Registered backup handlers, keyed by a short name such as "google_drive".
 *
 * @return array<string, string> Handler key => human label.
 */
function glover_document_library_backup_handlers()
{
    return (array) apply_filters('glover_document_library/backup_handlers', array());
}

/**
 * Record a document's backup status.
 *
 * @param int    $post_id Document ID.
 * @param string $status  One of GLOVER_DOCUMENT_LIBRARY_BACKUP_STATUSES.
 * @param string $note    Optional detail, such as an error message.
 */
function glover_document_library_set_backup_status($post_id, $status, $note = '')
{
    if (!isset(GLOVER_DOCUMENT_LIBRARY_BACKUP_STATUSES[$status])) {
        return;
    }

    update_post_meta($post_id, '_glover_drive_backup_status', $status);
    update_post_meta($post_id, '_glover_drive_backup_note', $note);
}

/**
 * Ask any registered backup handler to copy a document's current file.
 *
 * @param int $post_id       Document ID.
 * @param int $attachment_id File to back up.
 */
function glover_document_library_request_backup($post_id, $attachment_id)
{
    if (!glover_document_library_backup_handlers()) {
        glover_document_library_set_backup_status($post_id, 'not_configured');
        return;
    }

    glover_document_library_set_backup_status($post_id, 'queued');
    do_action('glover_document_library/backup_requested', $post_id, $attachment_id);
}

add_action('glover_document_library/document_published', function ($post_id) {
    glover_document_library_request_backup($post_id, glover_document_library_file_id($post_id));
});

add_action('glover_document_library/document_replaced', function ($post_id, $new_attachment_id) {
    glover_document_library_request_backup($post_id, $new_attachment_id);
}, 10, 2);

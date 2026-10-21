<?php

if (!defined('ABSPATH')) {
    exit;
}

/*
 * Google Drive backup (Phase 3). Registers as a backup handler when the
 * Glover Google Workspace must-use plugin has Drive configured. Each approved
 * file (and each replacement) is copied in the background to the town's
 * Shared Drive under Board / Year / Category. Drive is a backup only: the
 * site never reads from it, and nothing done in Drive changes the site.
 */

const GLOVER_DOCUMENT_LIBRARY_DRIVE_HOOK = 'glover_document_library_drive_backup';
const GLOVER_DOCUMENT_LIBRARY_DRIVE_MAX_ATTEMPTS = 5;

/**
 * Whether Drive backup can run.
 */
function glover_document_library_drive_ready()
{
    return function_exists('glover_google_is_configured') && glover_google_is_configured('drive');
}

add_filter('glover_document_library/backup_handlers', function ($handlers) {
    if (glover_document_library_drive_ready()) {
        $handlers['google_drive'] = 'Google Drive';
    }

    return $handlers;
});

/**
 * Queue a backup to run in the background.
 *
 * @param int $post_id       Document ID.
 * @param int $attachment_id File to back up.
 * @param int $attempt       Attempt number, starting at 1.
 * @param int $delay         Seconds to wait.
 */
function glover_document_library_drive_schedule($post_id, $attachment_id, $attempt = 1, $delay = 0)
{
    $args = array((int) $post_id, (int) $attachment_id, (int) $attempt);

    if (!wp_next_scheduled(GLOVER_DOCUMENT_LIBRARY_DRIVE_HOOK, $args)) {
        wp_schedule_single_event(time() + $delay, GLOVER_DOCUMENT_LIBRARY_DRIVE_HOOK, $args);
    }
}

add_action('glover_document_library/backup_requested', function ($post_id, $attachment_id) {
    if (glover_document_library_drive_ready() && $attachment_id) {
        glover_document_library_drive_schedule($post_id, $attachment_id);
    }
}, 10, 2);

/**
 * Find or create a folder by name inside a parent, caching IDs.
 *
 * @param string $token     Access token.
 * @param string $parent_id Parent folder (or Shared Drive) ID.
 * @param string $name      Folder name.
 * @return string|WP_Error Folder ID.
 */
function glover_document_library_drive_folder($token, $parent_id, $name)
{
    $cache = get_option('glover_document_library_drive_folders', array());
    $cache = is_array($cache) ? $cache : array();
    $key = $parent_id . '/' . $name;

    // A remembered folder may have been deleted or trashed in Drive since; check it still exists.
    if (!empty($cache[$key])) {
        $existing = glover_google_request('GET', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($cache[$key]) . '?supportsAllDrives=true&fields=id,trashed', $token);

        if (!is_wp_error($existing) && empty($existing['trashed'])) {
            return $cache[$key];
        }

        unset($cache[$key]);
        update_option('glover_document_library_drive_folders', $cache, false);
    }

    $drive_id = glover_google_setting('drive_id');
    $query = sprintf(
        "name = '%s' and '%s' in parents and mimeType = 'application/vnd.google-apps.folder' and trashed = false",
        str_replace(array('\\', "'"), array('\\\\', "\\'"), $name),
        $parent_id
    );
    $found = glover_google_request('GET', 'https://www.googleapis.com/drive/v3/files?' . http_build_query(array(
        'q' => $query,
        'fields' => 'files(id,name)',
        'corpora' => 'drive',
        'driveId' => $drive_id,
        'includeItemsFromAllDrives' => 'true',
        'supportsAllDrives' => 'true',
    ), '', '&', PHP_QUERY_RFC3986), $token);

    if (is_wp_error($found)) {
        return $found;
    }

    if (!empty($found['files'][0]['id'])) {
        $id = $found['files'][0]['id'];
    } else {
        $created = glover_google_request('POST', 'https://www.googleapis.com/drive/v3/files?supportsAllDrives=true&fields=id', $token, array(
            'json' => array(
                'name' => $name,
                'mimeType' => 'application/vnd.google-apps.folder',
                'parents' => array($parent_id),
            ),
        ));

        if (is_wp_error($created)) {
            return $created;
        }

        $id = $created['id'];
    }

    $cache[$key] = $id;
    update_option('glover_document_library_drive_folders', $cache, false);

    return $id;
}

/**
 * Folder names (Board / Year / Category) and the file name for a backup,
 * e.g. "2026-10-14_select-board_minutes.pdf".
 *
 * @param int $post_id       Document ID.
 * @param int $attachment_id File.
 * @return array{folders:string[],name:string}
 */
function glover_document_library_drive_location($post_id, $attachment_id)
{
    $boards = glover_document_library_document_board_ids($post_id);
    $board = $boards ? (string) get_post_field('post_title', $boards[0], 'raw') : 'No board';
    $categories = get_the_terms($post_id, GLOVER_DOCUMENT_LIBRARY_CATEGORY_TAXONOMY);
    $category = ($categories && !is_wp_error($categories)) ? $categories[0]->name : 'Uncategorized';
    $raw_date = get_field('document_date', $post_id, false);
    $date = $raw_date ? glover_document_library_format_date($raw_date, 'Y-m-d') : wp_date('Y-m-d');
    $ext = strtolower(pathinfo((string) get_attached_file($attachment_id), PATHINFO_EXTENSION));
    $versions = count(glover_document_library_meta_list($post_id, '_glover_previous_files'));

    $name = $date . '_' . sanitize_title($board) . '_' . sanitize_title($category);
    $name .= $versions ? '_v' . ($versions + 1) : '';

    return array(
        'folders' => array($board, substr($date, 0, 4), html_entity_decode($category, ENT_QUOTES)),
        'name' => $name . ($ext ? '.' . $ext : ''),
    );
}

/**
 * Upload one file to Drive with a resumable upload, which handles large PDFs.
 *
 * @param int $post_id       Document ID.
 * @param int $attachment_id File.
 * @return array|WP_Error Drive file data (id) plus the folder path.
 */
function glover_document_library_drive_upload($post_id, $attachment_id)
{
    $path = get_attached_file($attachment_id);

    if (!$path || !is_readable($path)) {
        return new WP_Error('glover_drive_missing_file', 'The document file is missing from the server.');
    }

    $token = glover_google_access_token(GLOVER_GOOGLE_DRIVE_SCOPE);

    if (is_wp_error($token)) {
        return $token;
    }

    $location = glover_document_library_drive_location($post_id, $attachment_id);
    $parent = glover_google_setting('drive_id');

    foreach ($location['folders'] as $folder) {
        $parent = glover_document_library_drive_folder($token, $parent, $folder);

        if (is_wp_error($parent)) {
            return $parent;
        }
    }

    $type = wp_check_filetype($path);
    $mime = $type['type'] ? $type['type'] : 'application/octet-stream';
    $start = wp_remote_post('https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&supportsAllDrives=true', array(
        'timeout' => 30,
        'headers' => array(
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json; charset=UTF-8',
            'X-Upload-Content-Type' => $mime,
            'X-Upload-Content-Length' => (string) filesize($path),
        ),
        'body' => wp_json_encode(array(
            'name' => $location['name'],
            'parents' => array($parent),
            'description' => sprintf('%s (document #%d) %s', get_post_field('post_title', $post_id, 'raw'), $post_id, get_permalink($post_id)),
        )),
    ));
    $checked = glover_google_decode_response($start);

    if (is_wp_error($checked)) {
        return $checked;
    }

    $session_url = wp_remote_retrieve_header($start, 'location');

    if (!$session_url) {
        return new WP_Error('glover_drive_no_session', 'Drive did not start the upload.');
    }

    $uploaded = glover_google_decode_response(wp_remote_request($session_url, array(
        'method' => 'PUT',
        'timeout' => 120,
        'headers' => array('Content-Type' => $mime),
        'body' => file_get_contents($path),
    )));

    if (is_wp_error($uploaded)) {
        return $uploaded;
    }

    if (empty($uploaded['id'])) {
        return new WP_Error('glover_drive_no_id', 'Drive did not return a file ID.');
    }

    $uploaded['path'] = implode(' / ', $location['folders']) . ' / ' . $location['name'];

    return $uploaded;
}

/**
 * Background job: back up a file, retrying with growing delays.
 *
 * @param int $post_id       Document ID.
 * @param int $attachment_id File.
 * @param int $attempt       Attempt number.
 */
function glover_document_library_drive_run($post_id, $attachment_id, $attempt = 1)
{
    if (!glover_document_library_drive_ready() || get_post_status($post_id) !== 'publish') {
        return;
    }

    $result = glover_document_library_drive_upload($post_id, $attachment_id);

    if (!is_wp_error($result)) {
        $files = get_post_meta($post_id, '_glover_drive_files', true);
        $files = is_array($files) ? $files : array();
        $files[$attachment_id] = $result['id'];
        update_post_meta($post_id, '_glover_drive_files', $files);
        update_post_meta($post_id, '_glover_drive_file_id', $result['id']);
        glover_document_library_set_backup_status($post_id, 'backed_up', $result['path']);
        glover_document_library_log($post_id, 'backed up to Drive', $result['path']);
        return;
    }

    $message = $result->get_error_message();

    if ($attempt < GLOVER_DOCUMENT_LIBRARY_DRIVE_MAX_ATTEMPTS) {
        glover_document_library_set_backup_status($post_id, 'queued', sprintf('Attempt %d failed (%s). Retrying.', $attempt, $message));
        glover_document_library_drive_schedule($post_id, $attachment_id, $attempt + 1, $attempt * 15 * MINUTE_IN_SECONDS);
        return;
    }

    glover_document_library_set_backup_status($post_id, 'failed', $message);
    glover_document_library_log($post_id, 'Drive backup failed', $message);
}
add_action(GLOVER_DOCUMENT_LIBRARY_DRIVE_HOOK, 'glover_document_library_drive_run', 10, 3);

/**
 * "Retry backup" from the Review details box.
 */
function glover_document_library_drive_handle_retry()
{
    $post_id = isset($_GET['post']) ? absint($_GET['post']) : 0;
    check_admin_referer('glover_drive_retry_' . $post_id);

    if (!glover_document_library_user_can_review() || get_post_type($post_id) !== GLOVER_DOCUMENT_LIBRARY_POST_TYPE) {
        wp_die('You do not have permission to retry backups.', 'Forbidden', array('response' => 403));
    }

    glover_document_library_set_backup_status($post_id, 'queued', 'Retry requested.');
    glover_document_library_drive_schedule($post_id, glover_document_library_file_id($post_id));

    wp_safe_redirect(add_query_arg('glover_notice', 'backup_retry', get_edit_post_link($post_id, 'url')));
    exit;
}
add_action('admin_post_glover_document_drive_retry', 'glover_document_library_drive_handle_retry');

/**
 * Retry link for the Review details box.
 *
 * @param int $post_id Document ID.
 */
function glover_document_library_drive_retry_url($post_id)
{
    return wp_nonce_url(admin_url('admin-post.php?action=glover_document_drive_retry&post=' . (int) $post_id), 'glover_drive_retry_' . (int) $post_id);
}

/**
 * Tell approvers on any admin screen when backups have failed.
 */
function glover_document_library_drive_failure_notice()
{
    if (!glover_document_library_drive_ready() || !glover_document_library_user_can_review()) {
        return;
    }

    $failed = get_posts(array(
        'post_type' => GLOVER_DOCUMENT_LIBRARY_POST_TYPE,
        'post_status' => 'publish',
        'fields' => 'ids',
        'posts_per_page' => -1,
        'no_found_rows' => true,
        'meta_key' => '_glover_drive_backup_status',
        'meta_value' => 'failed',
    ));

    if (!$failed) {
        return;
    }

    printf(
        '<div class="notice notice-error"><p>%s <a href="%s">Review failed backups</a>.</p></div>',
        esc_html(sprintf(_n('%d document failed to back up to Google Drive.', '%d documents failed to back up to Google Drive.', count($failed)), count($failed))),
        esc_url(glover_document_library_review_screen_url('approved', array('view' => 'backup_failed')))
    );
}
add_action('admin_notices', 'glover_document_library_drive_failure_notice');

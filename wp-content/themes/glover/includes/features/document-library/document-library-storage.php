<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Absolute path of the private folder for unpublished document files.
 * It is inside uploads but denied to web requests; approvers read files
 * through glover_document_library_stream_file() instead.
 */
function glover_document_library_private_dir()
{
    $uploads = wp_get_upload_dir();

    return glover_document_library_protect_private_dir($uploads['basedir']);
}

/**
 * Create the private folder under an uploads base directory and deny web
 * access to it. Takes the base directory so the upload_dir filter can call
 * it without re-running itself.
 *
 * @param string $basedir Uploads base directory.
 * @return string Private folder path.
 */
function glover_document_library_protect_private_dir($basedir)
{
    $dir = trailingslashit($basedir) . 'glover-private-documents';

    if (!is_dir($dir)) {
        wp_mkdir_p($dir);
    }

    if (!file_exists($dir . '/.htaccess')) {
        // Apache only. On nginx the host must deny this folder; see the feature spec.
        file_put_contents($dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    }

    if (!file_exists($dir . '/index.php')) {
        file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
    }

    return $dir;
}

/**
 * upload_dir filter that sends new uploads to the private folder.
 *
 * @param array $dirs Upload directory data.
 * @return array
 */
function glover_document_library_private_upload_dir($dirs)
{
    $subdir = '/glover-private-documents/' . gmdate('Y');
    glover_document_library_protect_private_dir($dirs['basedir']);

    $dirs['subdir'] = $subdir;
    $dirs['path'] = $dirs['basedir'] . $subdir;
    $dirs['url'] = $dirs['baseurl'] . $subdir;

    return $dirs;
}

/**
 * Whether an attachment's file currently sits in the private folder.
 *
 * @param int $attachment_id Attachment ID.
 */
function glover_document_library_attachment_is_private($attachment_id)
{
    $file = get_attached_file($attachment_id);

    return $file && strpos(wp_normalize_path($file), wp_normalize_path(glover_document_library_private_dir())) === 0;
}

/**
 * Move an attachment's file (and any generated preview sizes) between the
 * private folder and the public documents folder.
 *
 * @param int  $attachment_id Attachment ID.
 * @param bool $to_private    True to hide the file, false to publish it.
 * @return bool Whether the file ended up where it was asked to be.
 */
function glover_document_library_move_attachment($attachment_id, $to_private)
{
    $attachment_id = (int) $attachment_id;
    $current = get_attached_file($attachment_id);

    if (!$attachment_id || !$current || !file_exists($current)) {
        return false;
    }

    if (glover_document_library_attachment_is_private($attachment_id) === (bool) $to_private) {
        return true;
    }

    $uploads = wp_get_upload_dir();
    $target_dir = $to_private
        ? glover_document_library_private_dir() . '/' . gmdate('Y')
        : trailingslashit($uploads['basedir']) . 'documents/' . gmdate('Y');

    if (!wp_mkdir_p($target_dir)) {
        return false;
    }

    $source_dir = dirname($current);
    $filename = wp_unique_filename($target_dir, basename($current));
    $target = $target_dir . '/' . $filename;

    if (!@rename($current, $target)) {
        return false;
    }

    $metadata = wp_get_attachment_metadata($attachment_id);

    if (is_array($metadata)) {
        foreach (array('sizes') as $key) {
            if (empty($metadata[$key]) || !is_array($metadata[$key])) {
                continue;
            }

            foreach ($metadata[$key] as $size => $info) {
                if (empty($info['file']) || !file_exists($source_dir . '/' . $info['file'])) {
                    continue;
                }

                $size_name = wp_unique_filename($target_dir, $info['file']);
                @rename($source_dir . '/' . $info['file'], $target_dir . '/' . $size_name);
                $metadata[$key][$size]['file'] = $size_name;
            }
        }

        $metadata['file'] = _wp_relative_upload_path($target);
        wp_update_attachment_metadata($attachment_id, $metadata);
    }

    update_attached_file($attachment_id, $target);

    return true;
}

/**
 * The attachment ID stored on a document, or 0.
 *
 * @param int $post_id Document ID.
 */
function glover_document_library_file_id($post_id)
{
    return function_exists('get_field') ? (int) get_field('document_file', $post_id, false) : 0;
}

/**
 * Put a document's file where its status says it belongs: public when
 * published, private otherwise. Previous versions always stay private.
 *
 * @param int $post_id Document ID.
 */
function glover_document_library_sync_file_visibility($post_id)
{
    if (get_post_type($post_id) !== GLOVER_DOCUMENT_LIBRARY_POST_TYPE) {
        return;
    }

    $attachment_id = glover_document_library_file_id($post_id);

    if ($attachment_id) {
        glover_document_library_move_attachment($attachment_id, get_post_status($post_id) !== 'publish');

        if ((int) wp_get_post_parent_id($attachment_id) !== (int) $post_id) {
            wp_update_post(array('ID' => $attachment_id, 'post_parent' => $post_id));
        }
    }

    foreach (glover_document_library_meta_list($post_id, '_glover_previous_files') as $version) {
        if (!empty($version['attachment'])) {
            glover_document_library_move_attachment((int) $version['attachment'], true);
        }
    }
}

/**
 * URL for a document's file: the public file once published, otherwise a
 * signed-in preview link that checks permissions.
 *
 * @param int $post_id Document ID.
 */
function glover_document_library_file_url($post_id)
{
    $attachment_id = glover_document_library_file_id($post_id);

    if (!$attachment_id) {
        return '';
    }

    if (!glover_document_library_attachment_is_private($attachment_id)) {
        return wp_get_attachment_url($attachment_id);
    }

    return add_query_arg(array(
        'action' => 'glover_document_file',
        'document' => (int) $post_id,
    ), admin_url('admin-post.php'));
}

/**
 * A private document file's direct address is blocked by the server, so any
 * link WordPress or ACF builds to it (the file name under Document file, the
 * media library) goes through the permission-checked stream instead.
 *
 * @param string $url           Attachment URL.
 * @param int    $attachment_id Attachment ID.
 * @return string
 */
function glover_document_library_private_attachment_url($url, $attachment_id)
{
    $document_id = wp_get_post_parent_id($attachment_id);

    if (!$document_id || get_post_type($document_id) !== GLOVER_DOCUMENT_LIBRARY_POST_TYPE
        || !glover_document_library_attachment_is_private($attachment_id)) {
        return $url;
    }

    return add_query_arg(array(
        'action' => 'glover_document_file',
        'document' => (int) $document_id,
        'attachment' => (int) $attachment_id,
    ), admin_url('admin-post.php'));
}
add_filter('wp_get_attachment_url', 'glover_document_library_private_attachment_url', 10, 2);

/**
 * Stream a private document file to someone allowed to see it: a reviewer,
 * or the person who submitted it.
 */
function glover_document_library_stream_file()
{
    $post_id = isset($_GET['document']) ? absint($_GET['document']) : 0;
    $attachment_id = isset($_GET['attachment']) ? absint($_GET['attachment']) : 0;
    $post = $post_id ? get_post($post_id) : null;

    if (!$post || $post->post_type !== GLOVER_DOCUMENT_LIBRARY_POST_TYPE) {
        wp_die('Document not found.', 'Not found', array('response' => 404));
    }

    $is_owner = (int) $post->post_author === get_current_user_id();

    if (!glover_document_library_user_can_review() && !$is_owner) {
        wp_die('You do not have permission to view this file.', 'Forbidden', array('response' => 403));
    }

    $allowed = array(glover_document_library_file_id($post_id));

    foreach (glover_document_library_meta_list($post_id, '_glover_previous_files') as $version) {
        $allowed[] = isset($version['attachment']) ? (int) $version['attachment'] : 0;
    }

    $attachment_id = $attachment_id && in_array($attachment_id, $allowed, true) ? $attachment_id : $allowed[0];
    $file = $attachment_id ? get_attached_file($attachment_id) : '';

    if (!$file || !file_exists($file)) {
        wp_die('The file is missing.', 'Not found', array('response' => 404));
    }

    $type = wp_check_filetype($file);

    nocache_headers();
    header('Content-Type: ' . ($type['type'] ? $type['type'] : 'application/octet-stream'));
    header('Content-Disposition: inline; filename="' . sanitize_file_name(basename($file)) . '"');
    header('Content-Length: ' . filesize($file));
    header('X-Content-Type-Options: nosniff');
    readfile($file);
    exit;
}
add_action('admin_post_glover_document_file', 'glover_document_library_stream_file');

/**
 * Human details for a document's file: label (PDF, Word, Excel), size and
 * extension, used in download link names.
 *
 * @param int $post_id Document ID.
 * @return array{label:string,size:string,ext:string}
 */
function glover_document_library_file_details($post_id)
{
    $file = get_attached_file(glover_document_library_file_id($post_id));
    $ext = $file ? strtolower(pathinfo($file, PATHINFO_EXTENSION)) : '';
    $labels = array('pdf' => 'PDF', 'docx' => 'Word', 'doc' => 'Word', 'xlsx' => 'Excel', 'xls' => 'Excel', 'jpg' => 'JPEG', 'jpeg' => 'JPEG');

    return array(
        'label' => isset($labels[$ext]) ? $labels[$ext] : strtoupper($ext),
        'size' => ($file && file_exists($file)) ? size_format(filesize($file), 0) : '',
        'ext' => $ext,
    );
}

<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Allow the next status change to publish without a reviewer, for
 * Auto-approve submissions. Returns the previous state.
 *
 * @param bool|null $allow New state, or null to read only.
 */
function glover_document_library_allow_publish($allow = null)
{
    static $allowed = false;
    $previous = $allowed;

    if ($allow !== null) {
        $allowed = (bool) $allow;
    }

    return $previous;
}

/**
 * Only approvers (and Auto-approve submissions) can publish a document.
 * Anyone else's attempt to publish saves it as Pending instead.
 *
 * @param array $data    Sanitized post data.
 * @param array $postarr Raw post data.
 * @return array
 */
function glover_document_library_guard_publishing($data, $postarr)
{
    if ($data['post_type'] !== GLOVER_DOCUMENT_LIBRARY_POST_TYPE || $data['post_status'] !== 'publish') {
        return $data;
    }

    $current_status = !empty($postarr['ID']) ? get_post_status((int) $postarr['ID']) : '';

    if ($current_status === 'publish' || glover_document_library_allow_publish() || glover_document_library_user_can_review()) {
        return $data;
    }

    $data['post_status'] = 'pending';

    return $data;
}
add_filter('wp_insert_post_data', 'glover_document_library_guard_publishing', 10, 2);

/**
 * Keep the board mirror and file location current after fields save.
 *
 * @param int|string $post_id ACF post ID.
 */
function glover_document_library_after_fields_saved($post_id)
{
    if (!is_numeric($post_id) || get_post_type((int) $post_id) !== GLOVER_DOCUMENT_LIBRARY_POST_TYPE) {
        return;
    }

    glover_document_library_sync_document_boards((int) $post_id);
    glover_document_library_sync_file_visibility((int) $post_id);
}
add_action('acf/save_post', 'glover_document_library_after_fields_saved', 15);

/**
 * React to publish and unpublish once the post and its fields are saved.
 *
 * @param int          $post_id     Post ID.
 * @param WP_Post      $post        Post after the change.
 * @param bool         $update      Whether this was an update.
 * @param WP_Post|null $post_before Post before the change.
 */
function glover_document_library_after_insert($post_id, $post, $update, $post_before)
{
    if ($post->post_type !== GLOVER_DOCUMENT_LIBRARY_POST_TYPE) {
        return;
    }

    $was_published = $post_before && $post_before->post_status === 'publish';
    $is_published = $post->post_status === 'publish';

    glover_document_library_sync_file_visibility($post_id);

    if ($is_published && !$was_published) {
        glover_document_library_handle_publish($post_id);
    } elseif ($was_published && !$is_published && !get_post_meta($post_id, '_glover_merged_into', true)) {
        glover_document_library_log($post_id, 'unpublished', $post->post_status);
        do_action('glover_document_library/document_unpublished', $post_id);
    }
}
add_action('wp_after_insert_post', 'glover_document_library_after_insert', 10, 4);

/**
 * A document just went live: either merge it into the document it
 * replaces, or announce it as a new document.
 *
 * @param int $post_id Document ID.
 */
function glover_document_library_handle_publish($post_id)
{
    // wp_publish_post() never gives a pending post a slug, which would leave
    // the document without a stable address of its own.
    if (get_post_field('post_name', $post_id) === '') {
        wp_update_post(array(
            'ID' => $post_id,
            'post_name' => wp_unique_post_slug(sanitize_title(get_the_title($post_id)), $post_id, 'publish', GLOVER_DOCUMENT_LIBRARY_POST_TYPE, 0),
        ));
    }

    $original_id = function_exists('get_field') ? (int) get_field('document_replaces', $post_id, false) : 0;

    if ($original_id && $original_id !== $post_id && get_post_status($original_id) === 'publish') {
        glover_document_library_merge_replacement($original_id, $post_id);
        return;
    }

    glover_document_library_log($post_id, 'published');

    if ((int) get_post_field('post_author', $post_id) !== get_current_user_id()) {
        glover_document_library_notify_published($post_id);
    }

    do_action('glover_document_library/document_published', $post_id);
}

/**
 * Move an approved replacement into the original document so the
 * original's web address stays the same and shows the new file. The old
 * file is kept, privately, in the document's version history.
 *
 * @param int $original_id    Published document being replaced.
 * @param int $replacement_id Newly approved submission.
 */
function glover_document_library_merge_replacement($original_id, $replacement_id)
{
    $new_attachment = glover_document_library_file_id($replacement_id);
    $previous_attachment = glover_document_library_file_id($original_id);

    $copy = array(
        'field_glover_document_file' => $new_attachment,
        'field_glover_document_date' => get_field('document_date', $replacement_id, false),
        'field_glover_document_minutes_status' => get_field('document_minutes_status', $replacement_id, false),
        'field_glover_document_boards' => get_field('document_boards', $replacement_id, false),
    );

    foreach ($copy as $key => $value) {
        update_field($key, $value, $original_id);
    }

    foreach (array(GLOVER_DOCUMENT_LIBRARY_CATEGORY_TAXONOMY, GLOVER_DOCUMENT_LIBRARY_TOPIC_TAXONOMY) as $taxonomy) {
        $terms = wp_get_object_terms($replacement_id, $taxonomy, array('fields' => 'ids'));

        if (!is_wp_error($terms)) {
            wp_set_object_terms($original_id, $terms, $taxonomy, false);
        }
    }

    $history = get_post_meta($original_id, '_glover_previous_files', true);
    $history = is_array($history) ? $history : array();
    $history[] = array(
        'attachment' => $previous_attachment,
        'replaced_at' => time(),
        'replacement' => $replacement_id,
    );
    update_post_meta($original_id, '_glover_previous_files', $history);

    // Keep the original's slug so its web address never changes.
    wp_update_post(array(
        'ID' => $original_id,
        'post_title' => get_post_field('post_title', $replacement_id, 'raw'),
        'post_name' => get_post_field('post_name', $original_id, 'raw'),
    ));
    wp_update_post(array('ID' => $new_attachment, 'post_parent' => $original_id));

    glover_document_library_sync_document_boards($original_id);
    glover_document_library_sync_file_visibility($original_id);

    // The replacement no longer owns the file; retire it without touching the file.
    update_field('field_glover_document_file', '', $replacement_id);
    update_post_meta($replacement_id, '_glover_merged_into', $original_id);
    glover_document_library_log($replacement_id, 'merged', 'Replaced document #' . $original_id);
    glover_document_library_log($original_id, 'replaced', 'New file from submission #' . $replacement_id);
    wp_trash_post($replacement_id);

    if ((int) get_post_field('post_author', $replacement_id) !== get_current_user_id()) {
        $author = (int) get_post_field('post_author', $replacement_id);
        $submitter = get_userdata($author);

        if ($submitter) {
            glover_document_library_mail($submitter->user_email, 'Your document is published', array(
                sprintf('"%s" is now on the website, replacing the earlier version.', get_the_title($original_id)),
                get_permalink($original_id),
            ));
        }
    }

    do_action('glover_document_library/document_replaced', $original_id, $new_attachment, $previous_attachment);
}

/**
 * Other documents with the same category, date and at least one shared board.
 *
 * @param int $post_id Document ID.
 * @return int[]
 */
function glover_document_library_find_duplicates($post_id)
{
    $date = get_field('document_date', $post_id, false);
    $categories = wp_get_object_terms($post_id, GLOVER_DOCUMENT_LIBRARY_CATEGORY_TAXONOMY, array('fields' => 'ids'));
    $boards = wp_get_object_terms($post_id, GLOVER_DOCUMENT_LIBRARY_BOARD_TAXONOMY, array('fields' => 'ids'));

    if (!$date || is_wp_error($categories) || is_wp_error($boards) || !$categories || !$boards) {
        return array();
    }

    return get_posts(array(
        'post_type' => GLOVER_DOCUMENT_LIBRARY_POST_TYPE,
        'post_status' => array('publish', 'pending', 'draft'),
        'post__not_in' => array($post_id),
        'fields' => 'ids',
        'posts_per_page' => 10,
        'no_found_rows' => true,
        'meta_query' => array(array('key' => 'document_date', 'value' => $date)),
        'tax_query' => array(
            'relation' => 'AND',
            array('taxonomy' => GLOVER_DOCUMENT_LIBRARY_CATEGORY_TAXONOMY, 'terms' => $categories),
            array('taxonomy' => GLOVER_DOCUMENT_LIBRARY_BOARD_TAXONOMY, 'terms' => $boards),
        ),
    ));
}

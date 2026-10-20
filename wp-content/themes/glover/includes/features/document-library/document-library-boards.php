<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The hidden document-board term slug for a Departments & Boards post.
 * Slugs use the post ID so renaming a board never breaks the link.
 *
 * @param int $board_id Board post ID.
 */
function glover_document_library_board_term_slug($board_id)
{
    return 'board-' . (int) $board_id;
}

/**
 * Board post ID for a document-board term, or 0.
 *
 * @param WP_Term $term Term.
 */
function glover_document_library_board_id_from_term($term)
{
    return preg_match('/^board-(\d+)$/', $term->slug, $matches) ? (int) $matches[1] : 0;
}

/**
 * Keep the hidden document-board term in step with its board post.
 *
 * @param int $board_id Board post ID.
 */
function glover_document_library_sync_board_term($board_id)
{
    if (wp_is_post_revision($board_id) || get_post_type($board_id) !== GLOVER_DOCUMENT_LIBRARY_BOARD_POST_TYPE) {
        return;
    }

    $slug = glover_document_library_board_term_slug($board_id);
    $term = get_term_by('slug', $slug, GLOVER_DOCUMENT_LIBRARY_BOARD_TAXONOMY);
    $title = (string) get_post_field('post_title', $board_id, 'raw');

    if (get_post_status($board_id) === 'trash') {
        if ($term) {
            wp_delete_term($term->term_id, GLOVER_DOCUMENT_LIBRARY_BOARD_TAXONOMY);
        }
        return;
    }

    if ($term) {
        if ($term->name !== $title) {
            wp_update_term($term->term_id, GLOVER_DOCUMENT_LIBRARY_BOARD_TAXONOMY, array('name' => $title));
        }
        return;
    }

    wp_insert_term($title, GLOVER_DOCUMENT_LIBRARY_BOARD_TAXONOMY, array('slug' => $slug));
}
add_action('save_post_' . GLOVER_DOCUMENT_LIBRARY_BOARD_POST_TYPE, 'glover_document_library_sync_board_term');
add_action('trashed_post', 'glover_document_library_sync_board_term');

/**
 * Board post IDs saved on a document.
 *
 * @param int $post_id Document ID.
 * @return int[]
 */
function glover_document_library_document_board_ids($post_id)
{
    $ids = function_exists('get_field') ? get_field('document_boards', $post_id) : array();

    return array_values(array_filter(array_map('intval', (array) $ids)));
}

/**
 * Mirror a document's selected boards into the hidden taxonomy so the
 * library can filter and count by board with ordinary term queries.
 *
 * @param int $post_id Document ID.
 */
function glover_document_library_sync_document_boards($post_id)
{
    $slugs = array();

    foreach (glover_document_library_document_board_ids($post_id) as $board_id) {
        glover_document_library_sync_board_term($board_id);
        $slugs[] = glover_document_library_board_term_slug($board_id);
    }

    wp_set_object_terms($post_id, $slugs, GLOVER_DOCUMENT_LIBRARY_BOARD_TAXONOMY, false);
}

/**
 * Fill the Board checkboxes. wp-admin lists every board; the public
 * submission form lists only the boards the current person may post for.
 *
 * @param array $field Field definition.
 * @return array
 */
function glover_document_library_load_board_choices($field)
{
    $is_form_screen = !is_admin() || wp_doing_ajax();
    $choices = $is_form_screen ? glover_document_library_allowed_boards() : glover_document_library_all_boards();

    $field['choices'] = array();

    foreach ($choices as $id => $title) {
        $field['choices'][(string) $id] = $title;
    }

    if ($is_form_screen && count($choices) === 1) {
        $field['default_value'] = array((string) array_key_first($choices));
    }

    return $field;
}
add_filter('acf/load_field/key=field_glover_document_boards', 'glover_document_library_load_board_choices');

/**
 * Reject boards the submitter is not allowed to post for, whatever the
 * browser sent.
 *
 * @param bool|string $valid Current validity.
 * @param mixed       $value Submitted value.
 * @return bool|string
 */
function glover_document_library_validate_boards($valid, $value)
{
    if ($valid !== true || is_admin() && !wp_doing_ajax()) {
        return $valid;
    }

    $allowed = array_map('intval', array_keys(glover_document_library_allowed_boards()));

    foreach ((array) $value as $board_id) {
        if (!in_array((int) $board_id, $allowed, true)) {
            return 'You can only post documents for your own boards. Choose one of the boards listed.';
        }
    }

    return $valid;
}
add_filter('acf/validate_value/key=field_glover_document_boards', 'glover_document_library_validate_boards', 10, 2);

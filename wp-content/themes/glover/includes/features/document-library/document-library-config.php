<?php

if (!defined('ABSPATH')) {
    exit;
}

const GLOVER_DOCUMENT_LIBRARY_POST_TYPE = 'document-library';
const GLOVER_DOCUMENT_LIBRARY_CATEGORY_TAXONOMY = 'document-category';
const GLOVER_DOCUMENT_LIBRARY_TOPIC_TAXONOMY = 'document-subject';
const GLOVER_DOCUMENT_LIBRARY_BOARD_TAXONOMY = 'document-board';
const GLOVER_DOCUMENT_LIBRARY_BOARD_POST_TYPE = 'department-board';
const GLOVER_DOCUMENT_LIBRARY_REQUEST_POST_TYPE = 'access-copy-request';
const GLOVER_DOCUMENT_LIBRARY_SETTINGS_SLUG = 'glover-document-library-settings';
const GLOVER_DOCUMENT_LIBRARY_ROLE = 'document_submitter';
const GLOVER_DOCUMENT_LIBRARY_SUBMIT_CAP = 'submit_glover_documents';
const GLOVER_DOCUMENT_LIBRARY_ROLES_VERSION = '1';

/**
 * Read a Document Library setting from the ACF options page.
 *
 * @param string $name    Field name without the document_library_ prefix.
 * @param mixed  $default Value returned when the setting is empty.
 * @return mixed
 */
function glover_document_library_setting($name, $default = '')
{
    if (!function_exists('get_field')) {
        return $default;
    }

    $value = get_field('document_library_' . $name, 'option');

    return ($value === null || $value === '' || $value === false) ? $default : $value;
}

/**
 * User IDs allowed to approve documents: the approver and the backup approver.
 *
 * @return int[]
 */
function glover_document_library_approver_ids()
{
    $ids = array(
        (int) glover_document_library_setting('approver', 0),
        (int) glover_document_library_setting('backup_approver', 0),
    );

    return array_values(array_unique(array_filter($ids)));
}

/**
 * Whether a user may approve or return submissions.
 *
 * @param int|null $user_id Defaults to the current user.
 */
function glover_document_library_user_can_review($user_id = null)
{
    $user_id = $user_id ? (int) $user_id : get_current_user_id();

    if (!$user_id) {
        return false;
    }

    return in_array($user_id, glover_document_library_approver_ids(), true) || user_can($user_id, 'manage_options');
}

/**
 * Whether a user may submit documents through the submission form.
 *
 * @param int|null $user_id Defaults to the current user.
 */
function glover_document_library_user_can_submit($user_id = null)
{
    $user_id = $user_id ? (int) $user_id : get_current_user_id();

    return $user_id && (user_can($user_id, GLOVER_DOCUMENT_LIBRARY_SUBMIT_CAP) || glover_document_library_user_can_review($user_id));
}

/**
 * Whether a user's submissions publish without review.
 *
 * @param int|null $user_id Defaults to the current user.
 */
function glover_document_library_user_auto_approves($user_id = null)
{
    $user_id = $user_id ? (int) $user_id : get_current_user_id();

    if (!$user_id || !function_exists('get_field')) {
        return false;
    }

    return (bool) get_field('document_submitter_auto_approve', 'user_' . $user_id);
}

/**
 * Published Departments & Boards, keyed by post ID.
 *
 * @return array<int, string>
 */
function glover_document_library_all_boards()
{
    $boards = get_posts(array(
        'post_type' => GLOVER_DOCUMENT_LIBRARY_BOARD_POST_TYPE,
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
        'no_found_rows' => true,
    ));

    $choices = array();

    foreach ($boards as $board) {
        $choices[$board->ID] = get_the_title($board);
    }

    return $choices;
}

/**
 * Boards a user may post for. Reviewers, and submitters with no boards set
 * who can edit others' posts, may post for every board.
 *
 * @param int|null $user_id Defaults to the current user.
 * @return array<int, string>
 */
function glover_document_library_allowed_boards($user_id = null)
{
    $user_id = $user_id ? (int) $user_id : get_current_user_id();
    $all = glover_document_library_all_boards();

    if (!$user_id) {
        return array();
    }

    $assigned = function_exists('get_field') ? get_field('document_submitter_boards', 'user_' . $user_id) : array();
    $assigned = array_map('intval', (array) $assigned);

    if (!$assigned && (glover_document_library_user_can_review($user_id) || user_can($user_id, 'edit_others_posts'))) {
        return $all;
    }

    return array_intersect_key($all, array_flip($assigned));
}

/**
 * Where signed-out submitters go to sign in. Phase 2 (Google and emailed
 * codes) replaces this through the filter; nothing else needs to change.
 *
 * @param string $redirect URL to return to after signing in.
 */
function glover_document_library_sign_in_url($redirect)
{
    return apply_filters('glover_document_library/sign_in_url', wp_login_url($redirect), $redirect);
}

/**
 * Create the submitter role and grant the submit capability once per version.
 */
function glover_document_library_install_roles()
{
    if (get_option('glover_document_library_roles_version') === GLOVER_DOCUMENT_LIBRARY_ROLES_VERSION) {
        return;
    }

    remove_role(GLOVER_DOCUMENT_LIBRARY_ROLE);
    add_role(GLOVER_DOCUMENT_LIBRARY_ROLE, 'Document Submitter', array(
        'read' => true,
        GLOVER_DOCUMENT_LIBRARY_SUBMIT_CAP => true,
    ));

    foreach (array('administrator', 'editor') as $role_name) {
        $role = get_role($role_name);

        if ($role) {
            $role->add_cap(GLOVER_DOCUMENT_LIBRARY_SUBMIT_CAP);
        }
    }

    update_option('glover_document_library_roles_version', GLOVER_DOCUMENT_LIBRARY_ROLES_VERSION);
}
add_action('init', 'glover_document_library_install_roles');

/**
 * Register the settings screen under the Document Library menu.
 */
function glover_document_library_register_settings_page()
{
    if (!function_exists('acf_add_options_sub_page')) {
        return;
    }

    acf_add_options_sub_page(array(
        'page_title' => 'Document Library Settings',
        'menu_title' => 'Settings',
        'menu_slug' => GLOVER_DOCUMENT_LIBRARY_SETTINGS_SLUG,
        'parent_slug' => 'edit.php?post_type=' . GLOVER_DOCUMENT_LIBRARY_POST_TYPE,
        'capability' => 'manage_options',
        'autoload' => true,
    ));
}
add_action('acf/init', 'glover_document_library_register_settings_page');

/**
 * Only people who can manage users may change a submitter's boards or
 * Auto-approve, even on their own profile.
 *
 * @param mixed      $value   New value.
 * @param int|string $post_id ACF post ID, e.g. user_12.
 * @param array      $field   Field definition.
 * @return mixed
 */
function glover_document_library_protect_submitter_fields($value, $post_id, $field)
{
    if (current_user_can('promote_users')) {
        return $value;
    }

    return get_field($field['name'], $post_id, false);
}
add_filter('acf/update_value/key=field_glover_document_submitter_boards', 'glover_document_library_protect_submitter_fields', 10, 3);
add_filter('acf/update_value/key=field_glover_document_submitter_auto_approve', 'glover_document_library_protect_submitter_fields', 10, 3);

/**
 * A list stored in post meta. get_post_meta() returns '' when nothing is
 * saved, and (array) '' would count as one item.
 *
 * @param int    $post_id Post ID.
 * @param string $key     Meta key.
 * @return array
 */
function glover_document_library_meta_list($post_id, $key)
{
    $value = get_post_meta($post_id, $key, true);

    return is_array($value) ? $value : array();
}

/**
 * Append an entry to a document's audit log.
 *
 * @param int    $post_id Document ID.
 * @param string $action  Short machine-readable action.
 * @param string $note    Optional human-readable detail.
 */
function glover_document_library_log($post_id, $action, $note = '')
{
    $log = get_post_meta($post_id, '_glover_audit_log', true);
    $log = is_array($log) ? $log : array();
    $log[] = array(
        'time' => time(),
        'user' => get_current_user_id(),
        'action' => $action,
        'note' => $note,
    );
    update_post_meta($post_id, '_glover_audit_log', $log);
}

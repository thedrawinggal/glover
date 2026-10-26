<?php

if (!defined('ABSPATH')) {
    exit;
}

const GLOVER_DOCUMENT_LIBRARY_POST_TYPE = 'document-library';
const GLOVER_DOCUMENT_LIBRARY_CATEGORY_TAXONOMY = 'document-category';
const GLOVER_DOCUMENT_LIBRARY_TOPIC_TAXONOMY = 'document-subject';
const GLOVER_DOCUMENT_LIBRARY_BOARD_TAXONOMY = 'document-board';
const GLOVER_DOCUMENT_LIBRARY_BOARD_POST_TYPE = 'department-board';
// The Town Clerk's public address: accessible-copy requests and Submit a Document's Questions? panel.
const GLOVER_DOCUMENT_LIBRARY_CLERK_EMAIL = 'clerk@townofglover.com';
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
 * User IDs allowed to approve documents: everyone in the Approvers setting.
 *
 * @return int[]
 */
function glover_document_library_approver_ids()
{
    $ids = array_map('intval', (array) glover_document_library_setting('approver', array()));

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
 * A person's row in Settings > Uploaders, or null if they aren't listed.
 *
 * @param int $user_id User ID.
 * @return array{user:int,all_boards:bool,boards:int[],auto_approve:bool}|null
 */
function glover_document_library_uploader($user_id)
{
    $rows = (array) glover_document_library_setting('uploaders', array());

    foreach ($rows as $row) {
        if ((int) ($row['user'] ?? 0) === (int) $user_id) {
            return array(
                'user' => (int) $row['user'],
                'all_boards' => !empty($row['all_boards']),
                'boards' => array_map('intval', (array) ($row['boards'] ?? array())),
                'auto_approve' => !empty($row['auto_approve']),
            );
        }
    }

    return null;
}

/**
 * Whether a user may submit documents: approvers, administrators, and
 * everyone with the Document Submitter role. The boards they may post for
 * come from their Uploaders row (glover_document_library_allowed_boards()).
 *
 * @param int|null $user_id Defaults to the current user.
 */
function glover_document_library_user_can_submit($user_id = null)
{
    $user_id = $user_id ? (int) $user_id : get_current_user_id();

    return $user_id && (glover_document_library_user_can_review($user_id) || glover_document_library_is_submitter_role($user_id));
}

/**
 * Whether a user has the Document Submitter role.
 *
 * @param int $user_id User ID.
 */
function glover_document_library_is_submitter_role($user_id)
{
    $user = get_userdata((int) $user_id);

    return $user && in_array(GLOVER_DOCUMENT_LIBRARY_ROLE, (array) $user->roles, true);
}

/**
 * Whether a user's submissions publish without review (Auto-approve in
 * Settings > Uploaders).
 *
 * @param int|null $user_id Defaults to the current user.
 */
function glover_document_library_user_auto_approves($user_id = null)
{
    $user_id = $user_id ? (int) $user_id : get_current_user_id();
    $uploader = $user_id ? glover_document_library_uploader($user_id) : null;

    return $uploader ? $uploader['auto_approve'] : false;
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
 * Boards a user may post for: every board for approvers, administrators and
 * uploaders with All boards on, otherwise the boards on their Uploaders row.
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

    if (glover_document_library_user_can_review($user_id)) {
        return $all;
    }

    $uploader = glover_document_library_uploader($user_id);

    if (!$uploader) {
        return array();
    }

    return $uploader['all_boards'] ? $all : array_intersect_key($all, array_flip($uploader['boards']));
}

/**
 * Where a signed-out person goes to sign in, for example from a pending
 * document's preview link: the WordPress login, which offers emailed codes
 * and Google. Submit a Document signs people in on its own page instead.
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

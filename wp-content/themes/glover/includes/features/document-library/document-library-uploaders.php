<?php

if (!defined('ABSPATH')) {
    exit;
}

/*
 * Uploaders: who may submit documents, set on WordPress's own user screens.
 *
 * The Document Submitter role is what lets someone submit. Users > Add New
 * User and Edit User show the Document Submitter field group
 * (group_glover_user_document_submitter: boards and Auto-Approve) for that
 * role and for administrators. Its values are read from and saved to the
 * Uploaders repeater in Document Library settings, never to user meta.
 * Changing someone to another role takes them off the repeater. The first
 * time someone is added, they get the welcome email with how to sign in.
 *
 * The settings page's Uploaders tab has two read-only tables: approved
 * document submitters, and everyone else. Add User, Edit User and Add as
 * Document Submitter open those same user screens in a dialog (an iframe
 * with the admin menu and toolbar hidden), so there is no second form to
 * keep in step.
 */

const GLOVER_DOCUMENT_LIBRARY_ADD_UPLOADER_FIELD = 'field_glover_document_settings_add_uploader';
const GLOVER_DOCUMENT_LIBRARY_UPLOADERS_FIELD = 'field_glover_document_settings_uploaders';
const GLOVER_DOCUMENT_LIBRARY_SUBMITTER_FIELDS = array(
    'all_boards' => 'field_glover_user_submitter_all_boards',
    'boards' => 'field_glover_user_submitter_boards',
    'auto_approve' => 'field_glover_user_submitter_auto_approve',
);
const GLOVER_DOCUMENT_LIBRARY_WELCOMED_META = 'glover_document_library_welcomed';

/**
 * Whether the current screen is the Document Library settings page.
 */
function glover_document_library_is_settings_screen()
{
    return is_admin() && isset($_GET['page']) && $_GET['page'] === GLOVER_DOCUMENT_LIBRARY_SETTINGS_SLUG;
}

/**
 * Whether this user screen is shown inside the Uploaders dialog.
 */
function glover_document_library_is_user_dialog()
{
    global $pagenow;

    return is_admin() && !empty($_REQUEST['glover_dialog']) && in_array($pagenow, array('user-new.php', 'user-edit.php', 'profile.php'), true);
}

/**
 * The town's Google Workspace domain (sign-in with Google works only for it).
 */
function glover_document_library_town_domain()
{
    return defined('GLOVER_GOOGLE_DOMAIN') ? (string) GLOVER_GOOGLE_DOMAIN : 'townofglover.com';
}

/**
 * Send the welcome email to a new uploader: their boards, whether they are
 * reviewed, and step-by-step sign-in for their kind of address.
 *
 * @param WP_User $user         The uploader.
 * @param bool    $all_boards   Whether they post for every board.
 * @param int[]   $boards       Board IDs otherwise.
 * @param bool    $auto_approve Whether their documents skip review.
 */
function glover_document_library_send_uploader_welcome($user, $all_boards, $boards, $auto_approve)
{
    $domain = glover_document_library_town_domain();
    $town = strtolower((string) substr(strrchr($user->user_email, '@'), 1)) === strtolower($domain);
    $board_names = array_map('get_the_title', $boards);
    $minutes = defined('GLOVER_GOOGLE_CODE_LIFETIME') ? (int) round(GLOVER_GOOGLE_CODE_LIFETIME / 60) : 10;
    $steps = $town
        ? array(
            'Choose Submit a Document below.',
            'Choose Sign In With Google.',
            'Pick your ' . $user->user_email . ' account. If Google asks, sign in with your ' . $domain . ' password.',
        )
        : array(
            'Choose Submit a Document below.',
            'Enter ' . $user->user_email . ' under Email Address and choose Email Me a Code.',
            'We email you a 6-digit code. Enter it on the sign-in page within ' . $minutes . ' minutes. There is no password to remember.',
        );
    $rows = array();

    foreach ($steps as $index => $step) {
        $rows[] = array('label' => 'Step ' . ($index + 1), 'value' => $step);
    }

    return glover_document_library_mail($user->user_email, 'Welcome, You\'re Approved for Document Submission', array_merge(array(
        'heading' => 'Submit documents to the Town of Glover website',
        'intro' => 'You\'ve been added as an approved user to submit documents for ' . ($all_boards ? 'every board' : glover_document_library_join_names($board_names)) . '.'
            . ($auto_approve ? ' Your documents go on the website as soon as you submit them.' : ' Your documents go on the website once they are reviewed.'),
        'card' => array(
            'title' => 'How to Sign In',
            'rows' => $rows,
        ),
        'button' => array('label' => 'Submit a Document', 'url' => glover_document_library_submission_url()),
    ), glover_document_library_email_clerk_footer()));
}

/**
 * Send a Document Submitter the welcome email again, for Send Sign-In
 * Instructions on Edit User. It holds their boards and how to sign in.
 *
 * @param WP_User $user The person.
 * @return bool|null Whether it was sent, or null when they aren't a
 *                   Document Submitter with boards (send the account email).
 */
function glover_document_library_send_sign_in_instructions($user)
{
    $uploader = glover_document_library_is_submitter_role($user->ID) ? glover_document_library_uploader($user->ID) : null;

    if (!$uploader || (!$uploader['all_boards'] && !$uploader['boards'])) {
        return null;
    }

    return (bool) glover_document_library_send_uploader_welcome($user, $uploader['all_boards'], $uploader['boards'], $uploader['auto_approve']);
}

/*
 * The Uploaders repeater: one row per person.
 */

/**
 * The person's 1-based row number in the Uploaders repeater, or 0.
 *
 * @param int $user_id User ID.
 */
function glover_document_library_uploader_row_number($user_id)
{
    foreach (array_values((array) glover_document_library_setting('uploaders', array())) as $index => $row) {
        if ((int) ($row['user'] ?? 0) === (int) $user_id) {
            return $index + 1;
        }
    }

    return 0;
}

/**
 * Add the person's Uploaders row, or update it if they already have one.
 *
 * @param int   $user_id      User ID.
 * @param bool  $all_boards   Whether they post for every board.
 * @param int[] $boards       Board IDs otherwise.
 * @param bool  $auto_approve Whether their documents skip review.
 */
function glover_document_library_save_uploader($user_id, $all_boards, $boards, $auto_approve)
{
    $row = array(
        'field_glover_document_settings_uploader_user' => (int) $user_id,
        'field_glover_document_settings_uploader_all_boards' => $all_boards ? 1 : 0,
        'field_glover_document_settings_uploader_boards' => $all_boards ? array() : $boards,
        'field_glover_document_settings_uploader_auto_approve' => $auto_approve ? 1 : 0,
    );
    $number = glover_document_library_uploader_row_number($user_id);

    return $number
        ? update_row(GLOVER_DOCUMENT_LIBRARY_UPLOADERS_FIELD, $number, $row, 'option')
        : add_row(GLOVER_DOCUMENT_LIBRARY_UPLOADERS_FIELD, $row, 'option');
}

/**
 * Take the person off the Uploaders list.
 *
 * @param int $user_id User ID.
 */
function glover_document_library_remove_uploader($user_id)
{
    $number = glover_document_library_uploader_row_number($user_id);

    return $number ? delete_row(GLOVER_DOCUMENT_LIBRARY_UPLOADERS_FIELD, $number, 'option') : false;
}
add_action('delete_user', 'glover_document_library_remove_uploader');

/*
 * The Document Submitter fields on the user screens.
 */

/**
 * The Document Submitter field group's posted values. Boards are limited to
 * real boards and dropped for All boards.
 *
 * @return array{posted:bool,all_boards:bool,boards:int[],auto_approve:bool}
 */
function glover_document_library_submitter_input()
{
    $posted = isset($_POST['acf']) && is_array($_POST['acf']) ? wp_unslash($_POST['acf']) : array();
    $value = function ($name) use ($posted) {
        $key = GLOVER_DOCUMENT_LIBRARY_SUBMITTER_FIELDS[$name];

        return isset($posted[$key]) ? $posted[$key] : null;
    };

    $all_boards = !empty($value('all_boards'));
    $boards = array_values(array_intersect(array_map('absint', (array) $value('boards')), array_keys(glover_document_library_all_boards())));

    return array(
        'posted' => $value('all_boards') !== null || $value('auto_approve') !== null,
        'all_boards' => $all_boards,
        'boards' => $all_boards ? array() : $boards,
        'auto_approve' => !empty($value('auto_approve')),
    );
}

/**
 * The user ID in an ACF user post ID ("user_12"), or 0.
 *
 * @param mixed $post_id ACF post ID.
 */
function glover_document_library_acf_user_id($post_id)
{
    return is_string($post_id) && preg_match('/^user_(\d+)$/', $post_id, $matches) ? (int) $matches[1] : 0;
}

/**
 * User screens show the person's Uploaders row, not user meta.
 *
 * @param mixed      $value   Null to load normally.
 * @param int|string $post_id ACF post ID.
 * @param array      $field   ACF field.
 * @return mixed
 */
function glover_document_library_submitter_load_value($value, $post_id, $field)
{
    $name = array_search($field['key'] ?? '', GLOVER_DOCUMENT_LIBRARY_SUBMITTER_FIELDS, true);
    $user_id = glover_document_library_acf_user_id($post_id);

    if ($name === false || !$user_id) {
        return $value;
    }

    $uploader = glover_document_library_uploader($user_id);

    switch ($name) {
        case 'boards':
            return $uploader ? $uploader['boards'] : array();
        default:
            return $uploader && $uploader[$name] ? 1 : 0;
    }
}
add_filter('acf/pre_load_value', 'glover_document_library_submitter_load_value', 10, 3);

/**
 * The roles that see the Document Submitter fields: Document Submitter, and
 * administrators (who can always submit, but may set Auto-Approve).
 *
 * @return string[]
 */
function glover_document_library_submitter_field_roles()
{
    return array(GLOVER_DOCUMENT_LIBRARY_ROLE, 'administrator');
}

/**
 * The role a user screen is saving: the posted Role menu, or the person's
 * current role on screens without one (your own profile).
 *
 * @param int $user_id User ID, 0 when adding.
 */
function glover_document_library_posted_role($user_id)
{
    if (isset($_POST['role'])) {
        return sanitize_key(wp_unslash($_POST['role']));
    }

    $user = $user_id ? get_userdata($user_id) : false;

    return $user && $user->roles ? (string) reset($user->roles) : '';
}

/**
 * A Document Submitter needs at least one board, or All Boards. ACF can't
 * tie a field's Required setting to the Role menu, so this checks it.
 *
 * @param bool|string $valid Whether the value is valid, or an error.
 * @param mixed       $value Posted board IDs.
 * @return bool|string
 */
function glover_document_library_validate_submitter_boards($valid, $value)
{
    if ($valid !== true || !empty($value)) {
        return $valid;
    }

    $posted = isset($_POST['acf']) && is_array($_POST['acf']) ? $_POST['acf'] : array();
    $post_id = isset($_POST['_acf_post_id']) ? sanitize_text_field(wp_unslash($_POST['_acf_post_id'])) : '';

    if (!empty($posted[GLOVER_DOCUMENT_LIBRARY_SUBMITTER_FIELDS['all_boards']])) {
        return $valid;
    }

    return glover_document_library_posted_role(glover_document_library_acf_user_id($post_id)) === GLOVER_DOCUMENT_LIBRARY_ROLE
        ? 'Choose at least one board, or turn on All Boards.'
        : $valid;
}
add_filter('acf/validate_value/key=' . GLOVER_DOCUMENT_LIBRARY_SUBMITTER_FIELDS['boards'], 'glover_document_library_validate_submitter_boards', 10, 2);

/**
 * Show the Document Submitter fields only for the roles that use them, as
 * soon as the Role menu changes (assets/submitter-fields.js). Add as
 * Document Submitter on the Uploaders tab opens the screen with the role
 * already chosen.
 */
function glover_document_library_submitter_fields_assets()
{
    global $pagenow;

    if (!in_array($pagenow, array('user-new.php', 'user-edit.php', 'profile.php'), true) || !current_user_can('manage_options')) {
        return;
    }

    wp_enqueue_script('glover-submitter-fields', get_template_directory_uri() . '/includes/features/document-library/assets/submitter-fields.js', array(), (string) filemtime(__DIR__ . '/assets/submitter-fields.js'), true);
    wp_localize_script('glover-submitter-fields', 'gloverSubmitterFields', array(
        'roles' => glover_document_library_submitter_field_roles(),
        'submitterRole' => GLOVER_DOCUMENT_LIBRARY_ROLE,
        'chooseSubmitter' => !empty($_REQUEST['glover_add_submitter']),
    ));
}
add_action('admin_enqueue_scripts', 'glover_document_library_submitter_fields_assets');

/**
 * Save the user screens' Document Submitter fields to the Uploaders list,
 * then drop them from ACF's save so nothing is stored in user meta. Runs
 * before ACF saves (priority 10), after WordPress has saved the role.
 *
 * Someone whose role doesn't use the fields comes off the list. A new
 * Document Submitter row sends the welcome email once; a user meta timestamp
 * keeps it from being sent again if they are taken off and added back.
 *
 * @param int|string $post_id ACF post ID.
 */
function glover_document_library_submitter_save($post_id)
{
    $user_id = glover_document_library_acf_user_id($post_id);

    if (!$user_id || !current_user_can('manage_options')) {
        return;
    }

    $user = get_userdata($user_id);
    $input = glover_document_library_submitter_input();

    if (!$user || !array_intersect(glover_document_library_submitter_field_roles(), (array) $user->roles)) {
        glover_document_library_remove_uploader($user_id);
    } elseif ($input['posted']) {
        if ($input['all_boards'] || $input['boards'] || $input['auto_approve']) {
            $is_new = !glover_document_library_uploader_row_number($user_id);
            glover_document_library_save_uploader($user_id, $input['all_boards'], $input['boards'], $input['auto_approve']);

            if ($is_new && in_array(GLOVER_DOCUMENT_LIBRARY_ROLE, (array) $user->roles, true) && !get_user_meta($user_id, GLOVER_DOCUMENT_LIBRARY_WELCOMED_META, true)
                && glover_document_library_send_uploader_welcome($user, $input['all_boards'], $input['boards'], $input['auto_approve'])) {
                update_user_meta($user_id, GLOVER_DOCUMENT_LIBRARY_WELCOMED_META, time());
            }
        } else {
            // An administrator with nothing set needs no row.
            glover_document_library_remove_uploader($user_id);
        }
    }

    if (isset($_POST['acf']) && is_array($_POST['acf'])) {
        foreach (GLOVER_DOCUMENT_LIBRARY_SUBMITTER_FIELDS as $key) {
            unset($_POST['acf'][$key]);
        }
    }
}
add_action('acf/save_post', 'glover_document_library_submitter_save', 5);

/**
 * Changing someone's role anywhere (Edit User, or Users > Change role to)
 * takes them off the Uploaders list unless the new role uses it.
 *
 * @param int    $user_id User ID.
 * @param string $role    New role.
 */
function glover_document_library_submitter_role_changed($user_id, $role)
{
    if (!in_array($role, glover_document_library_submitter_field_roles(), true)) {
        glover_document_library_remove_uploader($user_id);
    }
}
add_action('set_user_role', 'glover_document_library_submitter_role_changed', 10, 2);

/*
 * The Uploaders tab: a read-only list, and the user screens in a dialog.
 */

/**
 * The repeater's editing table is replaced by the list. Not rendering it
 * also keeps the settings form from posting, and so saving, its rows.
 *
 * @param array $field ACF field.
 * @return array|false
 */
function glover_document_library_hide_uploaders_repeater($field)
{
    return glover_document_library_is_settings_screen() ? false : $field;
}
add_filter('acf/prepare_field/key=' . GLOVER_DOCUMENT_LIBRARY_UPLOADERS_FIELD, 'glover_document_library_hide_uploaders_repeater');

/**
 * A user's roles as display names.
 *
 * @param WP_User $user User.
 */
function glover_document_library_role_text($user)
{
    $names = wp_roles()->get_names();
    $roles = array_map(function ($role) use ($names) {
        return isset($names[$role]) ? translate_user_role($names[$role]) : $role;
    }, $user->roles);

    return $roles ? implode(', ', $roles) : 'No Role';
}

/**
 * A button that opens a user screen in the Uploaders dialog.
 *
 * @param string  $label Button text.
 * @param string  $url   The user screen.
 * @param string  $title Dialog title.
 * @param WP_User $user  The person, named for screen readers; null for Add User.
 * @param string  $class Extra button classes.
 */
function glover_document_library_user_dialog_button($label, $url, $title, $user = null, $class = '')
{
    printf(
        '<button type="button" class="button %s" data-glover-user-dialog="%s" data-glover-user-dialog-title="%s">%s%s</button>',
        esc_attr($class),
        esc_url(add_query_arg('glover_dialog', 1, $url)),
        esc_attr($title),
        esc_html($label),
        $user ? '<span class="screen-reader-text"> ' . esc_html($user->display_name) . '</span>' : ''
    );
}

/**
 * The Uploaders tab, in its message field: a table of approved document
 * submitters, then a table of everyone else, so whoever manages this can see
 * who already has an account before adding someone.
 *
 * @param array $field ACF message field.
 * @return array
 */
function glover_document_library_uploaders_list($field)
{
    if (!glover_document_library_is_settings_screen()) {
        return $field;
    }

    $boards = glover_document_library_all_boards();
    $submitters = array();
    $others = array();

    foreach (get_users(array('orderby' => 'display_name', 'order' => 'ASC')) as $user) {
        $uploader = glover_document_library_uploader($user->ID);

        if (glover_document_library_user_can_submit($user->ID)) {
            $submitters[] = array($user, $uploader);
        } else {
            $others[] = $user;
        }
    }

    ob_start();
    ?>
    <p><?php glover_document_library_user_dialog_button('Add User', admin_url('user-new.php'), 'Add User'); ?></p>

    <h3>Approved Document Submitters</h3>
    <p class="description">Everyone with the Document Submitter role, plus approvers and administrators, who can always submit for every board.</p>
    <table class="widefat striped glover-uploaders">
        <thead>
            <tr>
                <th scope="col">Name</th>
                <th scope="col">Role</th>
                <th scope="col">Boards</th>
                <th scope="col">Auto-Approve</th>
                <th scope="col"><span class="screen-reader-text">Actions</span></th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$submitters) : ?>
                <tr><td colspan="5">No approved document submitters yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($submitters as list($user, $uploader)) : ?>
                <?php
                if (glover_document_library_user_can_review($user->ID)) {
                    $board_text = user_can($user->ID, 'manage_options') ? 'All Boards, as an Administrator' : 'All Boards, as an Approver';
                } elseif ($uploader) {
                    $names = array_values(array_intersect_key($boards, array_flip($uploader['boards'])));
                    $board_text = $uploader['all_boards'] ? 'All Boards' : ($names ? implode(', ', $names) : 'None Yet');
                } else {
                    $board_text = 'None Yet';
                }

                $auto = $uploader && $uploader['auto_approve'] ? 'Yes' : 'No';
                ?>
                <tr>
                    <td><strong><?php echo esc_html($user->display_name); ?></strong><br><span class="description"><?php echo esc_html($user->user_email); ?></span></td>
                    <td><?php echo esc_html(glover_document_library_role_text($user)); ?></td>
                    <td><?php echo esc_html($board_text); ?></td>
                    <td><?php echo esc_html($auto); ?></td>
                    <td class="glover-uploaders__actions"><?php glover_document_library_user_dialog_button('Edit User', add_query_arg('user_id', $user->ID, admin_url('user-edit.php')), 'Edit User: ' . $user->display_name, $user, 'button-small'); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h3>Other Users</h3>
    <p class="description">People with an account who can't submit documents. Add as Document Submitter opens their account with the Document Submitter role chosen.</p>
    <table class="widefat striped glover-uploaders">
        <thead>
            <tr>
                <th scope="col">Name</th>
                <th scope="col">Role</th>
                <th scope="col"><span class="screen-reader-text">Actions</span></th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$others) : ?>
                <tr><td colspan="3">Everyone with an account can submit documents.</td></tr>
            <?php endif; ?>
            <?php foreach ($others as $user) : ?>
                <tr>
                    <td><strong><?php echo esc_html($user->display_name); ?></strong><br><span class="description"><?php echo esc_html($user->user_email); ?></span></td>
                    <td><?php echo esc_html(glover_document_library_role_text($user)); ?></td>
                    <td class="glover-uploaders__actions"><?php glover_document_library_user_dialog_button('Add as Document Submitter', add_query_arg(array('user_id' => $user->ID, 'glover_add_submitter' => 1), admin_url('user-edit.php')), 'Add as Document Submitter: ' . $user->display_name, $user, 'button-small'); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
    $field['message'] = ob_get_clean();
    $field['esc_html'] = 0;

    return $field;
}
add_filter('acf/load_field/key=' . GLOVER_DOCUMENT_LIBRARY_ADD_UPLOADER_FIELD, 'glover_document_library_uploaders_list');

/**
 * The dialog's styles (shared with the Documents screen) and script.
 */
function glover_document_library_uploaders_assets()
{
    if (!glover_document_library_is_settings_screen()) {
        return;
    }

    $base = get_template_directory_uri() . '/includes/features/document-library/assets/';
    wp_enqueue_style('glover-document-review', $base . 'review-admin.css', array(), (string) filemtime(__DIR__ . '/assets/review-admin.css'));
    wp_enqueue_script('glover-document-uploaders', $base . 'uploaders-admin.js', array(), (string) filemtime(__DIR__ . '/assets/uploaders-admin.js'), true);
}
add_action('admin_enqueue_scripts', 'glover_document_library_uploaders_assets');

/**
 * The empty dialog the user screens load into.
 */
function glover_document_library_render_user_dialog()
{
    if (!glover_document_library_is_settings_screen() || !current_user_can('manage_options')) {
        return;
    }
    ?>
    <dialog id="glover-user-dialog" class="glover-compare-dialog glover-user-dialog" aria-labelledby="glover-user-dialog-title">
        <div class="glover-compare">
            <div class="glover-compare__header">
                <div class="glover-compare__heading">
                    <span class="glover-compare__label">Uploaders</span>
                    <h2 id="glover-user-dialog-title" tabindex="-1"></h2>
                </div>
                <button type="button" class="glover-compare__close" data-glover-close aria-label="Close">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18"/></svg>
                </button>
            </div>
            <iframe class="glover-user-dialog__frame" title="User account" src="about:blank"></iframe>
        </div>
    </dialog>
    <?php
}
add_action('admin_footer', 'glover_document_library_render_user_dialog');

/**
 * Inside the dialog, a user screen keeps the dialog flag across its own
 * form post, and hides the admin menu, toolbar and its page heading (the
 * dialog has its own).
 */
function glover_document_library_user_dialog_flag()
{
    if (glover_document_library_is_user_dialog()) {
        echo '<input type="hidden" name="glover_dialog" value="1">';
    }
}
add_action('user_new_form', 'glover_document_library_user_dialog_flag');
add_action('show_user_profile', 'glover_document_library_user_dialog_flag');
add_action('edit_user_profile', 'glover_document_library_user_dialog_flag');

function glover_document_library_user_dialog_styles()
{
    if (!glover_document_library_is_user_dialog()) {
        return;
    }
    ?>
    <style>
        html.wp-toolbar { padding-top: 0; }
        #wpadminbar, #adminmenumain, #wpfooter, #screen-meta-links, .wrap > .wp-heading-inline, .wrap > .page-title-action, .wrap > h1 { display: none; }
        #wpcontent { margin-left: 0; padding-left: 20px; }
        /* Below 960px WordPress keeps room for the folded menu, which the dialog hides. */
        body.auto-fold #wpcontent, body.folded #wpcontent { margin-left: 0; }
        /* Below 782px WordPress leaves room for the toolbar, which the dialog hides. */
        body #wpbody { padding-top: 0; }
        #wpbody-content { padding-bottom: 20px; }
    </style>
    <?php
}
add_action('admin_head', 'glover_document_library_user_dialog_styles');

/**
 * Adding someone from the dialog starts them as a Document Submitter.
 *
 * @param mixed $role The site's default role.
 * @return mixed
 */
function glover_document_library_user_dialog_default_role($role)
{
    global $pagenow;

    return $pagenow === 'user-new.php' && glover_document_library_is_user_dialog() ? GLOVER_DOCUMENT_LIBRARY_ROLE : $role;
}
add_filter('option_default_role', 'glover_document_library_user_dialog_default_role');

/**
 * After a save inside the dialog, land on the person's Edit User screen with
 * glover_saved, which tells the dialog to close and refresh the list.
 *
 * @param string $location Redirect address.
 * @return string
 */
function glover_document_library_user_dialog_redirect($location)
{
    if (!glover_document_library_is_user_dialog()) {
        return $location;
    }

    $query = array();
    parse_str((string) wp_parse_url($location, PHP_URL_QUERY), $query);
    $saved = !empty($query['updated']) || (isset($query['update']) && $query['update'] === 'add');

    if (!$saved) {
        return add_query_arg('glover_dialog', 1, $location);
    }

    $user_id = !empty($query['id']) ? (int) $query['id'] : (!empty($query['user_id']) ? (int) $query['user_id'] : get_current_user_id());

    // WordPress sends people editing themselves to profile.php.
    if ($user_id === get_current_user_id()) {
        return add_query_arg(array('glover_dialog' => 1, 'glover_saved' => 1), admin_url('profile.php'));
    }

    return add_query_arg(array('user_id' => $user_id, 'glover_dialog' => 1, 'glover_saved' => 1), admin_url('user-edit.php'));
}
add_filter('wp_redirect', 'glover_document_library_user_dialog_redirect');

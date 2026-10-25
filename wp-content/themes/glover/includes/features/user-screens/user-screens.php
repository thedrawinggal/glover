<?php
/**
 * User Screens: the town's layout for WordPress's Profile, Edit User and
 * Add New User screens. A header names the person, and the fields are
 * regrouped into open sections (Profile, Document Submitter, Sign-In and
 * Security, Preferences) by assets/user-screens.js. WordPress's own fields
 * are moved, never copied, so every save works exactly as before. Also
 * removes the Admin Color Scheme picker (everyone sees the Modern scheme),
 * hides Keyboard Shortcuts and the About the User section, turns off
 * Application Passwords, and leaves only Administrator, Editor and Document
 * Submitter in the role menus. Edit User also gets Send Sign-In Instructions
 * (user-screens-sign-in.php). See docs/features/user-screens.md.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/user-screens-sign-in.php';

// No color scheme picker, and everyone sees WordPress's Modern scheme (its
// current default), whatever they chose before. WordPress adds the picker
// after the theme loads, so remove it on admin_init.
add_action('admin_init', function () {
    remove_action('admin_color_scheme_picker', 'admin_color_scheme_picker');
});
add_filter('get_user_option_admin_color', function () {
    return 'modern';
});

// No Application Passwords: nothing connects to the site that way, and the
// Google Drive backup uses its own Google account. Turning the feature off
// also removes its section from every Profile and Edit User screen. Remove
// this line if the town ever connects an app that needs one.
add_filter('wp_is_application_passwords_available', '__return_false');

/**
 * Without HTTPS (as on the local site), WordPress still shows the section,
 * to say the feature needs HTTPS. Hide it while the feature is off.
 */
function glover_user_screens_hide_application_passwords()
{
    if (glover_user_screens_screen() === 'edit' && !wp_is_application_passwords_available()) {
        echo '<style>#application-passwords-section { display: none; }</style>';
    }
}
add_action('admin_head', 'glover_user_screens_hide_application_passwords');

/**
 * Which user screen this is: 'edit' (Profile or Edit User), 'new' (Add New
 * User) or '' for any other screen.
 */
function glover_user_screens_screen()
{
    global $pagenow;

    if (!is_admin()) {
        return '';
    }

    if (in_array($pagenow, array('profile.php', 'user-edit.php'), true)) {
        return 'edit';
    }

    return $pagenow === 'user-new.php' && current_user_can('create_users') ? 'new' : '';
}

/**
 * The user shown on Profile or Edit User.
 *
 * @return WP_User|false
 */
function glover_user_screens_user()
{
    global $pagenow;

    $user_id = $pagenow === 'profile.php' ? get_current_user_id() : (isset($_GET['user_id']) ? absint($_GET['user_id']) : get_current_user_id());

    return get_userdata($user_id);
}

/**
 * How a person signs in on the public site: 'google' for town Google
 * accounts when Google sign-in is set up, 'code' for an emailed code, or
 * '' when the Glover Google must-use plugin isn't loaded.
 *
 * @param WP_User $user The person.
 */
function glover_user_screens_sign_in_method($user)
{
    if (!function_exists('glover_google_request_code')) {
        return '';
    }

    $domain = glover_google_setting('domain');
    $user_domain = (string) substr(strrchr($user->user_email, '@'), 1);

    if ($domain !== '' && strtolower($user_domain) === strtolower($domain) && glover_google_is_configured('sign_in')) {
        return 'google';
    }

    return 'code';
}

/**
 * Whether the screen is open in the Document Library's Uploaders dialog.
 */
function glover_user_screens_in_dialog()
{
    return function_exists('glover_document_library_is_user_dialog') && glover_document_library_is_user_dialog();
}

/**
 * The sections, in order, with the WordPress rows each one takes. Rows are
 * named by their `user-<name>-wrap` class, or by their control's id with
 * underscores as hyphens (Add New User's rows have no classes). Rows in
 * `actions` show their button beside the description. Any other group on
 * the screen (Document Submitter, a plugin's fields) becomes a section of
 * its own, titled by its heading, after Profile.
 *
 * @param string       $screen 'edit' or 'new'.
 * @param WP_User|null $user   The person on Profile or Edit User.
 */
function glover_user_screens_sections($screen, $user = null)
{
    if ($screen === 'new') {
        return array(
            array(
                'id' => 'glover-user-profile',
                'title' => 'Profile',
                'intro' => function_exists('glover_google_request_code') ? 'The email address is where sign-in codes and the welcome email go.' : '',
                'rows' => array('first-name', 'last-name', 'email', 'user-login', 'role', 'language', 'locale'),
            ),
            array(
                'id' => 'glover-user-security',
                'title' => 'Sign-In',
                'intro' => function_exists('glover_google_request_code') ? 'A password is only for the WordPress login screen.' : '',
                'rows' => array('pass1', 'pass2', 'pw-weak', 'send-user-notification', 'noconfirmation'),
            ),
        );
    }

    $method = $user ? glover_user_screens_sign_in_method($user) : '';
    $sign_in = '';

    if ($method === 'google') {
        $sign_in = 'Signs in with Google as ' . $user->user_email . '.';
    } elseif ($method === 'code') {
        $sign_in = 'Signs in with a code sent to ' . $user->user_email . '.';
    }

    return array(
        array(
            'id' => 'glover-user-profile',
            'title' => 'Profile',
            'intro' => 'How this person appears on the site and where the town can reach them.',
            'rows' => array('first-name', 'last-name', 'nickname', 'display-name', 'email', 'user-login', 'role', 'super-admin'),
        ),
        array(
            'id' => 'glover-user-security',
            'title' => 'Sign-In and Security',
            'intro' => $sign_in,
            'rows' => array('pass1', 'pass2', 'pw-weak', 'generate-reset-link', 'send-sign-in', 'sessions'),
            'actions' => array('pass1', 'generate-reset-link', 'send-sign-in', 'sessions'),
            'blocks' => array('#application-passwords-section'),
        ),
        array(
            'id' => 'glover-user-preferences',
            'title' => 'Preferences',
            'intro' => 'Only changes how the site and dashboard look for this person.',
            'rows' => array('language', 'rich-editing', 'syntax-highlighting', 'admin-bar-front', 'infinite-scrolling'),
        ),
    );
}

/**
 * Styles and the layout script on the three user screens.
 */
function glover_user_screens_assets()
{
    $screen = glover_user_screens_screen();

    if ($screen === '') {
        return;
    }

    $user = $screen === 'edit' ? glover_user_screens_user() : null;

    wp_enqueue_style('glover-user-screens', get_template_directory_uri() . '/includes/features/user-screens/assets/user-screens.css', array(), (string) filemtime(__DIR__ . '/assets/user-screens.css'));
    wp_enqueue_script('glover-user-screens', get_template_directory_uri() . '/includes/features/user-screens/assets/user-screens.js', array(), (string) filemtime(__DIR__ . '/assets/user-screens.js'), true);
    wp_localize_script('glover-user-screens', 'gloverUserScreens', array(
        'screen' => $screen,
        'sections' => glover_user_screens_sections($screen, $user ?: null),
        // Rows the town doesn't use. They are kept in the form, hidden, so
        // their saved values still post.
        'hidden' => array('comment-shortcuts', 'description', 'profile-picture', 'admin-color', 'url'),
    ));
}
add_action('admin_enqueue_scripts', 'glover_user_screens_assets');

/**
 * The header, printed as a template for user-screens.js to place at the top
 * of the screen. Without JavaScript it is never shown and the screen keeps
 * WordPress's own layout.
 */
function glover_user_screens_header()
{
    global $pagenow;

    $screen = glover_user_screens_screen();

    if ($screen === '') {
        return;
    }

    $dialog = glover_user_screens_in_dialog();
    $user = $screen === 'edit' ? glover_user_screens_user() : false;

    if ($screen === 'edit' && !$user) {
        return;
    }

    if ($user) {
        // Nickname, else first and last name, else username.
        $full_name = trim($user->first_name . ' ' . $user->last_name);
        $name = trim($user->nickname) !== '' ? $user->nickname : ($full_name !== '' ? $full_name : $user->user_login);
        $initials = $user->first_name !== '' || $user->last_name !== ''
            ? mb_substr($user->first_name, 0, 1) . mb_substr($user->last_name, 0, 1)
            : mb_substr($name, 0, 1);
        $roles = wp_roles()->get_names();
        $role = $user->roles ? translate_user_role($roles[reset($user->roles)] ?? reset($user->roles)) : 'No Role';
        $method = glover_user_screens_sign_in_method($user);
        $is_self = $user->ID === get_current_user_id();
        $can_delete = !$is_self && !is_multisite() && current_user_can('delete_user', $user->ID);
        $crumb = $pagenow === 'profile.php' ? 'Profile' : 'Edit User';
    }
    ?>
    <template id="glover-user-header">
        <div class="glover-user-header">
            <?php if (!$dialog && current_user_can('list_users')) : ?>
                <nav class="glover-user-header__crumbs" aria-label="Breadcrumb"><a href="<?php echo esc_url(admin_url('users.php')); ?>">Users</a> / <?php echo esc_html($user ? $crumb : 'Add New User'); ?></nav>
            <?php endif; ?>
            <div class="glover-user-header__main">
                <?php if ($user) : ?>
                    <span class="glover-user-header__avatar" aria-hidden="true"><?php echo esc_html(mb_strtoupper($initials)); ?></span>
                    <div class="glover-user-header__text">
                        <h1><?php echo esc_html($name); ?></h1>
                        <p class="glover-user-header__meta"><?php echo esc_html($user->user_email); ?> · Username <?php echo esc_html($user->user_login); ?></p>
                        <ul class="glover-user-header__tags">
                            <li data-glover-role-label><?php echo esc_html($role); ?></li>
                            <?php if ($method !== '') : ?>
                                <li><?php echo esc_html($method === 'google' ? 'Signs In With Google' : 'Signs In With Emailed Code'); ?></li>
                            <?php endif; ?>
                            <li>Registered <?php echo esc_html(wp_date(get_option('date_format'), strtotime($user->user_registered . ' UTC'))); ?></li>
                        </ul>
                    </div>
                <?php else : ?>
                    <span class="glover-user-header__avatar glover-user-header__avatar--new" aria-hidden="true"><span class="dashicons dashicons-admin-users"></span></span>
                    <div class="glover-user-header__text">
                        <h1>Add New User</h1>
                        <?php if (function_exists('glover_google_request_code')) : ?>
                            <p class="glover-user-header__meta">People sign in with Google or an emailed code, so a password is optional.</p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <div class="glover-user-header__actions">
                    <?php if ($user && $can_delete) : ?>
                        <a class="button glover-user-header__delete" href="<?php echo esc_url(wp_nonce_url(admin_url('users.php?action=delete&user=' . $user->ID), 'bulk-users')); ?>">Delete User</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($user) : ?>
                <nav class="glover-user-header__sections" aria-label="Sections"><ul></ul></nav>
            <?php endif; ?>
        </div>
    </template>
    <?php
}
add_action('admin_footer', 'glover_user_screens_header', 5);

/**
 * Without JavaScript, hide the rows WordPress has no hook to remove.
 * user-screens.js moves them into a hidden holder instead. Hidden fields
 * still post their saved values, so nothing stored is lost.
 */
function glover_user_screens_styles()
{
    if (glover_user_screens_screen() !== 'edit') {
        return;
    }
    ?>
    <style>
        .user-comment-shortcuts-wrap,
        .user-url-wrap,
        h2:has(+ .form-table .user-description-wrap),
        .form-table:has(.user-description-wrap) {
            display: none;
        }
    </style>
    <?php
}
add_action('admin_head', 'glover_user_screens_styles');

/*
 * Role menus: only the town's roles. Subscriber, Contributor and Author still
 * exist (WordPress and plugins expect them); they are just not offered.
 */

const GLOVER_USER_SCREENS_HIDDEN_ROLES = array('subscriber', 'contributor', 'author');

/**
 * Leave the hidden roles out of every role menu: Add New User, Edit User,
 * Users > Change role to, and Settings > General > New User Default Role.
 * When editing someone who still has a hidden role, theirs stays in their
 * own menu, so saving their profile doesn't quietly change it.
 *
 * @param array $roles Roles the current user may assign.
 * @return array
 */
function glover_user_screens_editable_roles($roles)
{
    $user_id = isset($_REQUEST['user_id']) ? absint($_REQUEST['user_id']) : 0;
    $user = $user_id ? get_userdata($user_id) : false;
    $keep = $user ? (array) $user->roles : array();

    foreach (GLOVER_USER_SCREENS_HIDDEN_ROLES as $role) {
        if (!in_array($role, $keep, true)) {
            unset($roles[$role]);
        }
    }

    return $roles;
}
add_filter('editable_roles', 'glover_user_screens_editable_roles');

/**
 * New accounts default to Document Submitter while the site's New User
 * Default Role is still a hidden role (WordPress's default is Subscriber).
 *
 * @param mixed $role The saved default role.
 * @return mixed
 */
function glover_user_screens_default_role($role)
{
    return in_array($role, GLOVER_USER_SCREENS_HIDDEN_ROLES, true) && defined('GLOVER_DOCUMENT_LIBRARY_ROLE') ? GLOVER_DOCUMENT_LIBRARY_ROLE : $role;
}
add_filter('option_default_role', 'glover_user_screens_default_role');

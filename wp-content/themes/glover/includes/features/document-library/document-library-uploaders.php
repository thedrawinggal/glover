<?php

if (!defined('ABSPATH')) {
    exit;
}

/*
 * "Add a new person" on Document Library > Settings > Uploaders.
 *
 * One dialog creates the person's account (Document Submitter role, no
 * password: they sign in with Google or an emailed code), adds their row to
 * the Uploaders repeater with ACF's add_row(), and can send a welcome email.
 * The dialog has its own form, outside ACF's settings form, and comes back
 * to the settings page with a notice.
 */

const GLOVER_DOCUMENT_LIBRARY_ADD_UPLOADER_FIELD = 'field_glover_document_settings_add_uploader';

/**
 * Whether the current screen is the Document Library settings page.
 */
function glover_document_library_is_settings_screen()
{
    return is_admin() && isset($_GET['page']) && $_GET['page'] === GLOVER_DOCUMENT_LIBRARY_SETTINGS_SLUG;
}

/**
 * Put the "Add a new person" button in the Uploaders tab's message field.
 *
 * @param array $field ACF message field.
 * @return array
 */
function glover_document_library_add_uploader_message($field)
{
    if (!glover_document_library_is_settings_screen()) {
        return $field;
    }

    $field['message'] = '<p>' . esc_html($field['message']) . '</p>'
        . '<p><button type="button" class="button" data-glover-add-uploader>Add a new person</button></p>';
    $field['esc_html'] = 0;

    return $field;
}
add_filter('acf/load_field/key=' . GLOVER_DOCUMENT_LIBRARY_ADD_UPLOADER_FIELD, 'glover_document_library_add_uploader_message');

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
 * Print the Add a new person dialog at the end of the settings page.
 */
function glover_document_library_render_add_uploader_dialog()
{
    if (!glover_document_library_is_settings_screen() || !current_user_can('manage_options')) {
        return;
    }

    $boards = glover_document_library_all_boards();
    ?>
    <dialog id="glover-add-uploader" class="glover-compare-dialog glover-return-dialog glover-add-uploader" aria-labelledby="glover-add-uploader-title">
        <form class="glover-compare" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="glover_document_add_uploader">
            <?php wp_nonce_field('glover_document_add_uploader'); ?>
            <div class="glover-compare__header">
                <div class="glover-compare__heading">
                    <span class="glover-compare__label">Uploaders</span>
                    <h2 id="glover-add-uploader-title" tabindex="-1">Add a new person</h2>
                </div>
                <button type="button" class="glover-compare__close" data-glover-close aria-label="Close">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18"/></svg>
                </button>
            </div>
            <div class="glover-compare__body glover-return-dialog__body">
                <p class="glover-compare__field">
                    <label for="glover-uploader-name">Name</label>
                    <input type="text" id="glover-uploader-name" name="uploader_name" required autocomplete="off">
                </p>
                <p class="glover-compare__field">
                    <label for="glover-uploader-email">Email</label>
                    <input type="email" id="glover-uploader-email" name="uploader_email" required autocomplete="off" aria-describedby="glover-uploader-email-help">
                    <span id="glover-uploader-email-help" class="glover-compare__muted">They sign in with this address: with Google if it's a <?php echo esc_html(glover_document_library_town_domain()); ?> account, otherwise with a code emailed to it.</span>
                </p>
                <fieldset class="glover-add-uploader__boards">
                    <legend>Boards they can post for</legend>
                    <label class="glover-add-uploader__all"><input type="checkbox" name="uploader_all_boards" value="1" data-glover-all-boards> All boards</label>
                    <div class="glover-add-uploader__list" data-glover-board-list>
                        <?php foreach ($boards as $board_id => $board_name) : ?>
                            <label><input type="checkbox" name="uploader_boards[]" value="<?php echo (int) $board_id; ?>"> <?php echo esc_html($board_name); ?></label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
                <p class="glover-add-uploader__check">
                    <label><input type="checkbox" name="uploader_auto_approve" value="1"> Auto-approve: their documents publish without review</label>
                </p>
                <p class="glover-add-uploader__check">
                    <label><input type="checkbox" name="uploader_welcome" value="1" checked> Send a welcome email with how to sign in and submit</label>
                </p>
            </div>
            <div class="glover-compare__footer">
                <div class="glover-compare__actions">
                    <button type="button" class="button" data-glover-close>Cancel</button>
                    <button type="submit" class="button button-primary">Add uploader</button>
                </div>
            </div>
        </form>
    </dialog>
    <?php
}
add_action('admin_footer', 'glover_document_library_render_add_uploader_dialog');

/**
 * The town's Google Workspace domain (sign-in with Google works only for it).
 */
function glover_document_library_town_domain()
{
    return defined('GLOVER_GOOGLE_DOMAIN') ? (string) GLOVER_GOOGLE_DOMAIN : 'townofglover.com';
}

/**
 * A unique login name made from an email address.
 *
 * @param string $email Email address.
 */
function glover_document_library_login_from_email($email)
{
    $base = sanitize_user(strstr($email, '@', true), true);
    $base = $base !== '' ? $base : 'uploader';
    $login = $base;
    $suffix = 2;

    while (username_exists($login)) {
        $login = $base . $suffix++;
    }

    return $login;
}

/**
 * Send the welcome email to a new uploader.
 *
 * @param WP_User $user         The uploader.
 * @param bool    $all_boards   Whether they post for every board.
 * @param int[]   $boards       Board IDs otherwise.
 * @param bool    $auto_approve Whether their documents skip review.
 */
function glover_document_library_send_uploader_welcome($user, $all_boards, $boards, $auto_approve)
{
    $town = strtolower((string) substr(strrchr($user->user_email, '@'), 1)) === strtolower(glover_document_library_town_domain());
    $board_names = array_map('get_the_title', $boards);

    return glover_document_library_mail($user->user_email, 'Welcome, You\'re Approved for Document Submission', array_merge(array(
        'heading' => 'Submit documents to the Town of Glover website',
        'intro' => 'You\'ve been added as an approved user to submit documents for ' . ($all_boards ? 'every board' : glover_document_library_join_names($board_names)) . '.'
            . ($auto_approve ? ' Your documents go on the website as soon as you submit them.' : ' Your documents go on the website once they are reviewed.'),
        'card' => array(
            'title' => 'How to sign in',
            'rows' => array(
                array('label' => 'Email', 'value' => $user->user_email),
                array('label' => 'Sign in with', 'value' => $town ? 'Google, using this ' . glover_document_library_town_domain() . ' account' : 'A code emailed to this address'),
            ),
        ),
        'button' => array('label' => 'Submit a document', 'url' => glover_document_library_submission_url()),
    ), glover_document_library_email_clerk_footer()));
}

/**
 * Back to the settings page with a notice key.
 *
 * @param string $notice Notice key.
 * @param string $name   Person's name for the notice.
 */
function glover_document_library_uploader_redirect($notice, $name = '')
{
    $args = array('page' => GLOVER_DOCUMENT_LIBRARY_SETTINGS_SLUG, 'glover_uploader' => $notice);

    if ($name !== '') {
        $args['glover_uploader_name'] = rawurlencode($name);
    }

    wp_safe_redirect(add_query_arg($args, admin_url('edit.php?post_type=' . GLOVER_DOCUMENT_LIBRARY_POST_TYPE)));
    exit;
}

/**
 * Create the person (or use their existing account), add their Uploaders
 * row, and send the welcome email.
 */
function glover_document_library_handle_add_uploader()
{
    check_admin_referer('glover_document_add_uploader');

    if (!current_user_can('manage_options')) {
        wp_die('You do not have permission to add uploaders.', 'Forbidden', array('response' => 403));
    }

    $name = isset($_POST['uploader_name']) ? sanitize_text_field(wp_unslash($_POST['uploader_name'])) : '';
    $email = isset($_POST['uploader_email']) ? sanitize_email(wp_unslash($_POST['uploader_email'])) : '';
    $all_boards = !empty($_POST['uploader_all_boards']);
    $auto_approve = !empty($_POST['uploader_auto_approve']);
    $boards = isset($_POST['uploader_boards']) ? array_map('absint', (array) $_POST['uploader_boards']) : array();
    $boards = array_values(array_intersect($boards, array_keys(glover_document_library_all_boards())));

    if ($name === '' || !is_email($email)) {
        glover_document_library_uploader_redirect('invalid');
    }

    if (!$all_boards && !$boards) {
        glover_document_library_uploader_redirect('no_boards');
    }

    $user = get_user_by('email', $email);

    if ($user && glover_document_library_uploader($user->ID)) {
        glover_document_library_uploader_redirect('exists', $user->display_name);
    }

    if (!$user) {
        $parts = preg_split('/\s+/', $name, 2);
        $user_id = wp_insert_user(array(
            'user_login' => glover_document_library_login_from_email($email),
            'user_email' => $email,
            'user_pass' => wp_generate_password(32, true, true),
            'display_name' => $name,
            'nickname' => $name,
            'first_name' => $parts[0],
            'last_name' => isset($parts[1]) ? $parts[1] : '',
            'role' => GLOVER_DOCUMENT_LIBRARY_ROLE,
        ));

        if (is_wp_error($user_id)) {
            glover_document_library_uploader_redirect('failed');
        }

        $user = get_userdata($user_id);
    } elseif (empty($user->roles)) {
        // An account with no role can't sign in; give it the submitter role.
        $user->set_role(GLOVER_DOCUMENT_LIBRARY_ROLE);
    }

    add_row('field_glover_document_settings_uploaders', array(
        'field_glover_document_settings_uploader_user' => $user->ID,
        'field_glover_document_settings_uploader_all_boards' => $all_boards ? 1 : 0,
        'field_glover_document_settings_uploader_boards' => $all_boards ? array() : $boards,
        'field_glover_document_settings_uploader_auto_approve' => $auto_approve ? 1 : 0,
    ), 'option');

    $notice = 'added';

    if (!empty($_POST['uploader_welcome'])) {
        $notice = glover_document_library_send_uploader_welcome($user, $all_boards, $boards, $auto_approve) ? 'added_emailed' : 'added_email_failed';
    }

    glover_document_library_uploader_redirect($notice, $user->display_name);
}
add_action('admin_post_glover_document_add_uploader', 'glover_document_library_handle_add_uploader');

/**
 * A notice after Add a new person, only when something needs attention.
 * Success shows as the new row on the Uploaders tab.
 */
function glover_document_library_uploader_notice()
{
    if (!glover_document_library_is_settings_screen() || empty($_GET['glover_uploader'])) {
        return;
    }

    $name = isset($_GET['glover_uploader_name']) ? sanitize_text_field(rawurldecode(wp_unslash($_GET['glover_uploader_name']))) : '';
    $notices = array(
        'added_email_failed' => array('warning', sprintf('%s is an uploader, but the welcome email could not be sent.', $name)),
        'exists' => array('warning', sprintf('%s is already an uploader. Change their row below.', $name)),
        'invalid' => array('error', 'Enter a name and a valid email address.'),
        'no_boards' => array('error', 'Choose at least one board, or All boards.'),
        'failed' => array('error', 'The account could not be created. Check the email address and try again.'),
    );
    $key = sanitize_key($_GET['glover_uploader']);

    if (isset($notices[$key])) {
        printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($notices[$key][0]), esc_html($notices[$key][1]));
    }
}
add_action('admin_notices', 'glover_document_library_uploader_notice');

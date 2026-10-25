<?php
/**
 * Send Sign-In Instructions: a button on Edit User, for administrators, that
 * emails the person how to sign in. Document Submitters with boards get the
 * Document Library's welcome email again; everyone else gets the account
 * email's sign-in steps (mu-plugins/glover-google/account-email.php). The
 * sign-in page itself is unchanged: the person still asks for their own
 * code there.
 *
 * The row sits in Sign-In and Security, beside Send Reset Link, through
 * glover_user_screens_sections(). Without JavaScript it is its own small
 * section and the button is a link that sends and comes back with a notice.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Whether the current administrator may send this person instructions.
 *
 * @param WP_User|false $user The person.
 */
function glover_user_screens_can_send_sign_in($user)
{
    return $user instanceof WP_User
        && !empty($user->roles)
        && function_exists('glover_account_email_send_sign_in')
        && current_user_can('manage_options')
        && current_user_can('edit_user', $user->ID);
}

/**
 * Send the instructions, at most once a minute per person.
 *
 * @param WP_User $user The person.
 * @return string 'sent', 'failed' or 'wait'.
 */
function glover_user_screens_send_sign_in($user)
{
    $throttle = 'glover_sign_in_instructions_' . $user->ID;

    if (get_transient($throttle)) {
        return 'wait';
    }

    $sent = function_exists('glover_document_library_send_sign_in_instructions') ? glover_document_library_send_sign_in_instructions($user) : null;

    if ($sent === null) {
        $sent = glover_account_email_send_sign_in($user);
    }

    if ($sent) {
        set_transient($throttle, 1, MINUTE_IN_SECONDS);
    }

    return $sent ? 'sent' : 'failed';
}

/**
 * The message for a send result.
 *
 * @param string  $result 'sent', 'failed' or 'wait'.
 * @param WP_User $user   The person.
 */
function glover_user_screens_sign_in_message($result, $user)
{
    $messages = array(
        'sent' => sprintf('Sign-in instructions sent to %s.', $user->user_email),
        'failed' => 'The email could not be sent. Try again in a few minutes.',
        'wait' => 'Instructions were just sent. Wait a minute before sending again.',
    );

    return $messages[$result] ?? $messages['failed'];
}

/**
 * The row, in its own table so the layout can move it.
 *
 * @param WP_User $user The person being edited.
 */
function glover_user_screens_sign_in_row($user)
{
    if (!glover_user_screens_can_send_sign_in($user)) {
        return;
    }

    $submitter = function_exists('glover_document_library_is_submitter_role') && glover_document_library_is_submitter_role($user->ID);
    $url = wp_nonce_url(add_query_arg(array('action' => 'glover_send_sign_in', 'user_id' => $user->ID), admin_url('admin-post.php')), 'glover_send_sign_in_' . $user->ID);
    ?>
    <h2>Sign-In Instructions</h2>
    <table class="form-table" role="presentation">
        <tr class="user-send-sign-in-wrap">
            <th>Sign-In Instructions</th>
            <td>
                <div><a class="button button-secondary" href="<?php echo esc_url($url); ?>" data-glover-send-sign-in="<?php echo (int) $user->ID; ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('glover_send_sign_in_' . $user->ID)); ?>">Send Sign-In Instructions</a></div>
                <p class="description"><?php echo esc_html(sprintf(
                    $submitter
                        ? 'Email %s the welcome email again: their boards and how to sign in. Use this when someone can\'t remember how to sign in.'
                        : 'Email %s how to sign in with their email address. Use this when someone can\'t remember how to sign in.',
                    $user->display_name
                )); ?></p>
                <p class="glover-send-sign-in__status" role="status" aria-live="polite"></p>
            </td>
        </tr>
    </table>
    <?php
}
add_action('edit_user_profile', 'glover_user_screens_sign_in_row');

/**
 * The button's request, sent with admin-ajax.
 */
function glover_user_screens_ajax_send_sign_in()
{
    $user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
    $user = get_userdata($user_id);

    if (!$user_id || !check_ajax_referer('glover_send_sign_in_' . $user_id, 'nonce', false) || !glover_user_screens_can_send_sign_in($user)) {
        wp_send_json_error(array('message' => 'You can\'t send sign-in instructions to this person.'), 403);
    }

    $result = glover_user_screens_send_sign_in($user);
    $data = array('message' => glover_user_screens_sign_in_message($result, $user));

    $result === 'sent' ? wp_send_json_success($data) : wp_send_json_error($data);
}
add_action('wp_ajax_glover_send_sign_in', 'glover_user_screens_ajax_send_sign_in');

/**
 * Without JavaScript the button is a link: send, then back to Edit User.
 */
function glover_user_screens_post_send_sign_in()
{
    $user_id = isset($_GET['user_id']) ? absint($_GET['user_id']) : 0;
    check_admin_referer('glover_send_sign_in_' . $user_id);
    $user = get_userdata($user_id);

    if (!glover_user_screens_can_send_sign_in($user)) {
        wp_die('You can\'t send sign-in instructions to this person.', 'Forbidden', array('response' => 403));
    }

    wp_safe_redirect(add_query_arg(array('user_id' => $user_id, 'glover_sign_in' => glover_user_screens_send_sign_in($user)), admin_url('user-edit.php')));
    exit;
}
add_action('admin_post_glover_send_sign_in', 'glover_user_screens_post_send_sign_in');

/**
 * The notice after the link, without JavaScript.
 */
function glover_user_screens_sign_in_notice()
{
    global $pagenow;

    if ($pagenow !== 'user-edit.php' || empty($_GET['glover_sign_in']) || empty($_GET['user_id'])) {
        return;
    }

    $user = get_userdata(absint($_GET['user_id']));
    $result = sanitize_key($_GET['glover_sign_in']);

    if ($user && glover_user_screens_can_send_sign_in($user)) {
        printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', $result === 'sent' ? 'success' : 'error', esc_html(glover_user_screens_sign_in_message($result, $user)));
    }
}
add_action('admin_notices', 'glover_user_screens_sign_in_notice');

/**
 * The button's script: send without leaving the screen, and say what
 * happened in the row's status line.
 */
function glover_user_screens_sign_in_script()
{
    global $pagenow;

    if ($pagenow !== 'user-edit.php' || !current_user_can('manage_options')) {
        return;
    }
    ?>
    <script>
        document.addEventListener('click', function (event) {
            var button = event.target.closest('[data-glover-send-sign-in]');

            if (!button || button.getAttribute('aria-disabled') === 'true') {
                return;
            }

            event.preventDefault();

            var status = button.closest('.user-send-sign-in-wrap').querySelector('.glover-send-sign-in__status');
            var body = new FormData();

            body.append('action', 'glover_send_sign_in');
            body.append('user_id', button.getAttribute('data-glover-send-sign-in'));
            body.append('nonce', button.getAttribute('data-nonce'));
            button.setAttribute('aria-disabled', 'true');
            status.textContent = 'Sending...';

            fetch(window.ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function (response) {
                    return response.json();
                })
                .then(function (reply) {
                    status.textContent = reply && reply.data && reply.data.message ? reply.data.message : 'The email could not be sent. Try again in a few minutes.';
                })
                .catch(function () {
                    status.textContent = 'The email could not be sent. Try again in a few minutes.';
                })
                .then(function () {
                    button.removeAttribute('aria-disabled');
                });
        });
    </script>
    <?php
}
add_action('admin_footer', 'glover_user_screens_sign_in_script');

<?php
/**
 * WordPress's own account emails in the site's email layout (email.php):
 * the new account email and its notice to the site admin, password reset,
 * password changed (to the person and to the admin), and email address
 * changed. Each keeps WordPress's recipient and links; only the wording and
 * layout change. Document Submitters don't get the new account email, since
 * the Document Library's welcome email covers how they sign in.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Headers that make wp_mail() send the layout as HTML (mail.php adds the
 * plain-text copy).
 *
 * @param string|string[] $headers WordPress's headers.
 * @return string[]
 */
function glover_account_email_headers($headers)
{
    $headers = is_array($headers) ? $headers : array_filter(explode("\n", str_replace("\r\n", "\n", (string) $headers)));
    $headers = array_filter($headers, function ($header) {
        return stripos($header, 'content-type:') !== 0;
    });
    $headers[] = 'Content-Type: text/html; charset=UTF-8';

    return array_values($headers);
}

/**
 * "Town of Glover | Subject". The new account and password changed subjects
 * go through sprintf() with the site name afterwards, so they keep a %s.
 *
 * @param string $subject Subject.
 * @param bool   $sprintf Whether WordPress fills in %s afterwards.
 */
function glover_account_email_subject($subject, $sprintf = false)
{
    $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);

    return ($sprintf ? '%s' : $site) . ' | ' . $subject;
}

/**
 * Who people should contact about their account: the Webmaster chosen in
 * Users > Site Contacts, else the site's admin email.
 *
 * @return array{footer:string,footer_link:array}
 */
function glover_account_email_footer()
{
    $email = function_exists('glover_site_webmaster') && glover_site_webmaster() ? glover_site_webmaster()->user_email : (string) get_option('admin_email');

    return $email !== '' ? array('footer' => 'Questions about your account? Contact the webmaster:', 'footer_link' => array('label' => $email, 'url' => 'mailto:' . $email)) : array();
}

/**
 * Numbered sign-in steps for /sign-in/, in that page's own words: Google for
 * a town address, an emailed code otherwise.
 *
 * @param string $email The person's email address.
 * @return array[] Card rows.
 */
function glover_account_email_sign_in_steps($email)
{
    $domain = defined('GLOVER_GOOGLE_DOMAIN') ? (string) GLOVER_GOOGLE_DOMAIN : '';
    $town = $domain !== '' && strtolower((string) substr(strrchr($email, '@'), 1)) === strtolower($domain);
    $minutes = (int) round(GLOVER_GOOGLE_CODE_LIFETIME / 60);
    $steps = $town
        ? array(
            'Choose Sign In below.',
            'Choose Sign in with Google.',
            'Pick your ' . $email . ' account. If Google asks, sign in with your ' . $domain . ' password.',
        )
        : array(
            'Choose Sign In below.',
            'Enter ' . $email . ' under Email address and choose Email me a code.',
            'We email you a 6-digit code. Enter it on the sign-in page within ' . $minutes . ' minutes. There is no password to remember.',
        );
    $rows = array();

    foreach ($steps as $index => $step) {
        $rows[] = array('label' => 'Step ' . ($index + 1), 'value' => $step);
    }

    return $rows;
}

/**
 * The person's role names, for the admin's notice.
 *
 * @param WP_User $user User.
 */
function glover_account_email_roles($user)
{
    $names = wp_roles()->get_names();
    $roles = array_map(function ($role) use ($names) {
        return isset($names[$role]) ? translate_user_role($names[$role]) : $role;
    }, (array) $user->roles);

    return $roles ? implode(', ', $roles) : 'No Role';
}

/**
 * Document Submitters get the Document Library's welcome email instead.
 *
 * @param bool    $send Whether to send the new account email.
 * @param WP_User $user The new user.
 * @return bool
 */
function glover_account_email_skip_submitters($send, $user)
{
    return $user instanceof WP_User && in_array('document_submitter', (array) $user->roles, true) ? false : $send;
}
add_filter('wp_send_new_user_notification_to_user', 'glover_account_email_skip_submitters', 10, 2);

/**
 * The sign-in email's layout: numbered steps for /sign-in/, a Sign In
 * button, and an optional set-password link. Used by the new account email
 * and by Send Sign-In Instructions on Edit User.
 *
 * @param WP_User $user         The person.
 * @param string  $set_password WordPress's set-password link, or ''.
 * @param bool    $new          Whether the account was just made.
 * @return string HTML.
 */
function glover_account_email_sign_in_message($user, $set_password = '', $new = true)
{
    return glover_email_render(array(
        'heading' => $new ? 'Your Town of Glover website account' : 'How to sign in to the Town of Glover website',
        'subtitle' => 'Website Account',
        'intro' => ($new ? 'An account has been set up for you on the Town of Glover website. ' : '') . 'You sign in with your email address, ' . $user->user_email . ', and no password.',
        'card' => array('title' => 'How to Sign In', 'rows' => glover_account_email_sign_in_steps($user->user_email)),
        'button' => array('label' => 'Sign In', 'url' => wp_login_url(admin_url())),
        'secondary' => $set_password !== '' ? array('before' => 'Prefer a password? ', 'label' => 'Set one here', 'url' => $set_password, 'after' => '.') : array(),
    ) + glover_account_email_footer());
}

/**
 * New account, to the person.
 *
 * @param array   $email WordPress's email: to, subject, message, headers.
 * @param WP_User $user  The new user.
 * @return array
 */
function glover_account_email_new_user($email, $user)
{
    // WordPress puts the set-password link in the message; keep it.
    $set_password = preg_match('#https?://\S+action=rp\S*#', (string) $email['message'], $matches) ? $matches[0] : '';

    $email['subject'] = glover_account_email_subject('Your Website Account', true);
    $email['headers'] = glover_account_email_headers($email['headers']);
    $email['message'] = glover_account_email_sign_in_message($user, $set_password);

    return $email;
}
add_filter('wp_new_user_notification_email', 'glover_account_email_new_user', 10, 2);

/**
 * Send someone how to sign in, for an administrator's Send Sign-In
 * Instructions button. No set-password link, so any reset link they already
 * have keeps working.
 *
 * @param WP_User $user The person.
 * @return bool Whether the email was sent.
 */
function glover_account_email_send_sign_in($user)
{
    return wp_mail($user->user_email, glover_account_email_subject('How to Sign In'), glover_account_email_sign_in_message($user, '', false), glover_account_email_headers(array()));
}

/**
 * New account, to the site admin.
 *
 * @param array   $email WordPress's email.
 * @param WP_User $user  The new user.
 * @return array
 */
function glover_account_email_new_user_admin($email, $user)
{
    $email['subject'] = glover_account_email_subject('New Account: ' . $user->display_name, true);
    $email['headers'] = glover_account_email_headers($email['headers']);
    $email['message'] = glover_email_render(array(
        'heading' => 'A new account was added',
        'subtitle' => 'Website Account',
        'card' => array(
            'title' => $user->display_name,
            'rows' => array(
                array('label' => 'Email', 'value' => $user->user_email, 'url' => 'mailto:' . $user->user_email),
                array('label' => 'Username', 'value' => $user->user_login),
                array('label' => 'Role', 'value' => glover_account_email_roles($user), 'strong' => true),
            ),
        ),
        'button' => array('label' => 'Open Their Account', 'url' => add_query_arg('user_id', $user->ID, admin_url('user-edit.php'))),
    ));

    return $email;
}
add_filter('wp_new_user_notification_email_admin', 'glover_account_email_new_user_admin', 10, 2);

/**
 * Password reset.
 *
 * @param array   $email      WordPress's email.
 * @param string  $key        Reset key.
 * @param string  $user_login Username.
 * @param WP_User $user       The user.
 * @return array
 */
function glover_account_email_password_reset($email, $key, $user_login, $user)
{
    $email['subject'] = glover_account_email_subject('Reset Your Password');
    $email['headers'] = glover_account_email_headers($email['headers']);
    $email['message'] = glover_email_render(array(
        'heading' => 'Reset your password',
        'subtitle' => 'Website Account',
        'intro' => 'Someone asked to reset the password for the account ' . $user->user_email . '. If that wasn\'t you, ignore this email and nothing changes.',
        'button' => array('label' => 'Set a New Password', 'url' => network_site_url('wp-login.php?login=' . rawurlencode($user_login) . '&key=' . $key . '&action=rp', 'login')),
        'secondary' => array('before' => 'You can also sign in without a password on the ', 'label' => 'sign-in page', 'url' => wp_login_url(), 'after' => '.'),
    ) + glover_account_email_footer());

    return $email;
}
add_filter('retrieve_password_notification_email', 'glover_account_email_password_reset', 10, 4);

/**
 * Password changed, to the person.
 *
 * @param array $email WordPress's email.
 * @param array $user  The user before the change, as an array.
 * @return array
 */
function glover_account_email_password_changed($email, $user)
{
    $email['subject'] = glover_account_email_subject('Your Password Was Changed', true);
    $email['headers'] = glover_account_email_headers($email['headers']);
    $email['message'] = glover_email_render(array(
        'heading' => 'Your password was changed',
        'subtitle' => 'Website Account',
        'intro' => 'The password for your Town of Glover website account, ' . $user['user_email'] . ', was just changed. If you made this change, there is nothing else to do. If you didn\'t, contact the webmaster right away.',
    ) + glover_account_email_footer());

    return $email;
}
add_filter('password_change_email', 'glover_account_email_password_changed', 10, 2);

/**
 * Email address changed, sent to the old address.
 *
 * @param array $email    WordPress's email.
 * @param array $user     The user before the change, as an array.
 * @param array $userdata The user after the change, as an array.
 * @return array
 */
function glover_account_email_address_changed($email, $user, $userdata)
{
    $email['subject'] = glover_account_email_subject('Your Email Address Was Changed', true);
    $email['headers'] = glover_account_email_headers($email['headers']);
    $email['message'] = glover_email_render(array(
        'heading' => 'Your email address was changed',
        'subtitle' => 'Website Account',
        'intro' => 'The email address for your Town of Glover website account was changed. If you made this change, there is nothing else to do. If you didn\'t, contact the webmaster right away.',
        'card' => array(
            'title' => 'Account email',
            'rows' => array(
                array('label' => 'Was', 'value' => $user['user_email']),
                array('label' => 'Now', 'value' => $userdata['user_email'], 'strong' => true),
            ),
        ),
    ) + glover_account_email_footer());

    return $email;
}
add_filter('email_change_email', 'glover_account_email_address_changed', 10, 3);

/**
 * Password changed, to the site admin.
 *
 * @param array   $email WordPress's email.
 * @param WP_User $user  The user.
 * @return array
 */
function glover_account_email_password_changed_admin($email, $user)
{
    $email['subject'] = glover_account_email_subject('Password Changed: ' . $user->display_name, true);
    $email['headers'] = glover_account_email_headers($email['headers']);
    $email['message'] = glover_email_render(array(
        'heading' => 'A password was changed',
        'subtitle' => 'Website Account',
        'intro' => $user->display_name . ' (' . $user->user_email . ') changed their website password.',
        'button' => array('label' => 'Open Their Account', 'url' => add_query_arg('user_id', $user->ID, admin_url('user-edit.php'))),
    ));

    return $email;
}
add_filter('wp_password_change_notification_email', 'glover_account_email_password_changed_admin', 10, 2);

<?php
/**
 * Settings > Google Workspace: shows which parts are configured and offers
 * test buttons. Settings themselves live in wp-config.php, never here.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register the status screen.
 */
function glover_google_register_status_page()
{
    add_options_page('Google Workspace', 'Google Workspace', 'manage_options', 'glover-google', 'glover_google_render_status_page');
}
add_action('admin_menu', 'glover_google_register_status_page');

/**
 * Show a secret's presence without revealing it.
 *
 * @param string $value Secret.
 */
function glover_google_mask($value)
{
    return $value === '' ? 'Not set' : 'Set (ends in ' . substr($value, -4) . ')';
}

/**
 * Print the status screen.
 */
function glover_google_render_status_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $account = glover_google_service_account();
    $result = get_transient('glover_google_test_result_' . get_current_user_id());
    delete_transient('glover_google_test_result_' . get_current_user_id());

    $rows = array(
        'Site email' => array(
            glover_google_is_configured('mail'),
            array(
                'Sender mailbox' => glover_google_setting('mail_sender') ?: 'Not set',
                'From address' => glover_google_setting('mail_from') ?: 'Not set',
                'From name' => glover_google_setting('mail_from_name') ?: 'Not set',
            ),
        ),
        'Drive backup' => array(
            glover_google_is_configured('drive'),
            array('Shared Drive ID' => glover_google_setting('drive_id') ?: 'Not set'),
        ),
        'Sign in with Google' => array(
            glover_google_is_configured('sign_in'),
            array(
                'Client ID' => glover_google_mask(glover_google_setting('oauth_client_id')),
                'Client secret' => glover_google_mask(glover_google_setting('oauth_client_secret')),
                'Workspace domain' => glover_google_setting('domain') ?: 'Not set (any Google account allowed)',
                'Redirect URI to register' => glover_google_oauth_redirect_uri(),
            ),
        ),
    );
    ?>
    <div class="wrap">
        <h1>Google Workspace</h1>
        <p>Settings come from <code>GLOVER_GOOGLE_*</code> constants in <code>wp-config.php</code>. See <code>docs/features/google-workspace.md</code> in the theme for the setup checklist.</p>

        <?php if ($result) : ?>
            <div class="notice notice-<?php echo esc_attr($result['ok'] ? 'success' : 'error'); ?>"><p><?php echo esc_html($result['message']); ?></p></div>
        <?php endif; ?>

        <h2>Service account</h2>
        <p><?php echo is_wp_error($account) ? esc_html($account->get_error_message()) : esc_html('Key loaded for ' . $account['client_email']); ?></p>

        <table class="widefat striped" style="max-width: 60rem">
            <thead><tr><th scope="col">Part</th><th scope="col">Status</th><th scope="col">Details</th></tr></thead>
            <tbody>
                <?php foreach ($rows as $label => $row) : ?>
                    <tr>
                        <th scope="row"><?php echo esc_html($label); ?></th>
                        <td><?php echo $row[0] ? 'Configured' : 'Not configured'; ?></td>
                        <td>
                            <?php foreach ($row[1] as $key => $value) : ?>
                                <div><strong><?php echo esc_html($key); ?>:</strong> <code><?php echo esc_html($value); ?></code></div>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h2>Tests</h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:1rem">
            <input type="hidden" name="action" value="glover_google_test_mail">
            <?php wp_nonce_field('glover_google_test_mail'); ?>
            <?php submit_button('Send a test email to me', 'secondary', 'submit', false, glover_google_is_configured('mail') ? array() : array('disabled' => 'disabled')); ?>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:1rem">
            <input type="hidden" name="action" value="glover_google_test_drive">
            <?php wp_nonce_field('glover_google_test_drive'); ?>
            <?php submit_button('Test Drive access', 'secondary', 'submit', false, glover_google_is_configured('drive') ? array() : array('disabled' => 'disabled')); ?>
        </form>
        <?php if (glover_google_is_configured('sign_in')) : ?>
            <a class="button" href="<?php echo esc_url(glover_google_sign_in_url(admin_url('options-general.php?page=glover-google'))); ?>">Open the sign-in page</a>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Store a test result for the next page view and return to the screen.
 *
 * @param bool   $ok      Whether the test passed.
 * @param string $message What happened.
 */
function glover_google_finish_test($ok, $message)
{
    set_transient('glover_google_test_result_' . get_current_user_id(), array('ok' => $ok, 'message' => $message), 300);
    wp_safe_redirect(admin_url('options-general.php?page=glover-google'));
    exit;
}

/**
 * Send a test email to the current admin.
 */
function glover_google_handle_test_mail()
{
    check_admin_referer('glover_google_test_mail');

    if (!current_user_can('manage_options')) {
        wp_die('Not allowed.');
    }

    $user = wp_get_current_user();
    $result = glover_google_send_mail(array(
        'to' => $user->user_email,
        'subject' => 'Test email from the Town of Glover website',
        'message' => "This is a test from Settings > Google Workspace.\n\nIf you're reading it, site email works.",
        'headers' => '',
        'attachments' => array(),
    ));

    glover_google_finish_test(!is_wp_error($result), is_wp_error($result) ? 'Test email failed: ' . $result->get_error_message() : 'Test email sent to ' . $user->user_email . '.');
}
add_action('admin_post_glover_google_test_mail', 'glover_google_handle_test_mail');

/**
 * Check the service account can see the Shared Drive.
 */
function glover_google_handle_test_drive()
{
    check_admin_referer('glover_google_test_drive');

    if (!current_user_can('manage_options')) {
        wp_die('Not allowed.');
    }

    $token = glover_google_access_token(GLOVER_GOOGLE_DRIVE_SCOPE);

    if (is_wp_error($token)) {
        glover_google_finish_test(false, 'Drive test failed: ' . $token->get_error_message());
    }

    $drive = glover_google_request('GET', 'https://www.googleapis.com/drive/v3/drives/' . rawurlencode(glover_google_setting('drive_id')) . '?fields=name', $token);

    glover_google_finish_test(!is_wp_error($drive), is_wp_error($drive)
        ? 'Drive test failed: ' . $drive->get_error_message() . ' Check the drive ID and that the service account is a Content manager.'
        : 'Connected to the Shared Drive "' . $drive['name'] . '".');
}
add_action('admin_post_glover_google_test_drive', 'glover_google_handle_test_drive');

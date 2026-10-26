<?php
/**
 * Plugin Name: Glover Document Library Test Guard
 * Description: Present only while tools/test-document-library.php runs. Sends every email to the test inbox and nowhere else, labels it with the test step, records it, and stands in for the Google Drive backup.
 *
 * tools/test-document-library.php copies this file into wp-content/mu-plugins/
 * at the start of a run and deletes it at the end, so the web requests the
 * suite makes (the submission form, Approve, Return, Compare, Undo) are
 * guarded as well as the suite itself. It does nothing unless the run's
 * state option is set and unexpired, so a copy left behind by a crash goes
 * quiet within the hour.
 */

if (!defined('ABSPATH')) {
    exit;
}

const GLOVER_E2E_STATE = 'glover_e2e_state';
const GLOVER_E2E_MAIL_PREFIX = 'glover_e2e_mail_';

/**
 * The running suite's state, or null when no run is active: inbox, hold
 * (capture without sending), expires, and the current step's label.
 *
 * @return array|null
 */
function glover_e2e_state()
{
    $state = get_option(GLOVER_E2E_STATE);

    return (is_array($state) && !empty($state['inbox']) && (int) $state['expires'] > time()) ? $state : null;
}

/**
 * Header lines of a wp_mail() call as an array.
 *
 * @param string|string[] $headers Headers.
 * @return string[]
 */
function glover_e2e_header_lines($headers)
{
    $lines = is_array($headers) ? $headers : explode("\n", str_replace("\r\n", "\n", (string) $headers));

    return array_values(array_filter(array_map('trim', $lines)));
}

/**
 * Record each email as it was addressed, then send it only to the test
 * inbox, without Cc or Bcc, marked with the step that sent it.
 *
 * @param array $atts wp_mail() arguments.
 * @return array
 */
function glover_e2e_route_mail($atts)
{
    $state = glover_e2e_state();

    if (!$state) {
        return $atts;
    }

    $to = is_array($atts['to']) ? $atts['to'] : explode(',', (string) $atts['to']);
    $to = array_values(array_filter(array_map('trim', $to)));
    $headers = glover_e2e_header_lines($atts['headers'] ?? array());
    $copied = array_values(array_filter($headers, function ($line) {
        return preg_match('/^(cc|bcc)\s*:/i', $line);
    }));
    $key = GLOVER_E2E_MAIL_PREFIX . str_replace('.', '', uniqid('', true));

    add_option($key, array(
        'time' => microtime(true),
        'step' => (string) ($state['step'] ?? ''),
        'to' => $to,
        'copied' => $copied,
        'subject' => (string) $atts['subject'],
        'message' => (string) $atts['message'],
        'sent_to' => array($state['inbox']),
        'result' => 'pending',
    ), '', 'no');
    $GLOBALS['glover_e2e_mail_key'] = $key;

    $banner = sprintf(
        '<div style="margin:0 0 16px;padding:12px 16px;border:2px dashed #b45309;background:#fff7ed;color:#1c1917;font:14px/1.5 Arial,sans-serif;"><strong>Document Library test run.</strong> %s<br>Originally to: %s%s</div>',
        esc_html((string) ($state['step_long'] ?? $state['step'] ?? '')),
        esc_html(implode(', ', $to)),
        $copied ? esc_html(' (' . implode('; ', $copied) . ')') : ''
    );
    $message = (string) $atts['message'];
    $count = 0;
    $message = preg_replace('/(<body\b[^>]*>)/i', '$1' . $banner, $message, 1, $count);

    $atts['message'] = $count ? $message : $banner . $message;
    $atts['to'] = array($state['inbox']);
    $atts['headers'] = array_values(array_filter($headers, function ($line) {
        return !preg_match('/^(cc|bcc)\s*:/i', $line);
    }));
    $atts['subject'] = '[Test ' . ($state['step'] ?? '') . '] ' . $atts['subject'];

    return $atts;
}
add_filter('wp_mail', 'glover_e2e_route_mail', PHP_INT_MAX);

/**
 * Before anything sends: refuse any address but the test inbox, and in hold
 * mode report success without sending.
 *
 * @param null|bool $return Null to let the senders run.
 * @param array     $atts   wp_mail() arguments, already routed.
 * @return null|bool
 */
function glover_e2e_check_mail($return, $atts)
{
    $state = glover_e2e_state();

    if (!$state) {
        return $return;
    }

    $to = array_map('strtolower', array_map('trim', (array) $atts['to']));

    if ($to !== array(strtolower($state['inbox']))) {
        glover_e2e_mail_result('blocked');

        return false;
    }

    if (!empty($state['hold'])) {
        glover_e2e_mail_result('held');

        return true;
    }

    return $return;
}
add_filter('pre_wp_mail', 'glover_e2e_check_mail', 1, 2);

/**
 * After the Gmail sender (priority 10) has run, record what happened.
 *
 * @param null|bool $return The sender's result.
 * @return null|bool
 */
function glover_e2e_after_send($return)
{
    if (glover_e2e_state() && $return !== null) {
        glover_e2e_mail_result($return ? 'sent' : 'failed', true);
    }

    return $return;
}
add_filter('pre_wp_mail', 'glover_e2e_after_send', 11);

// Mail that fell through to WordPress's own sender (Gmail not configured).
add_action('wp_mail_succeeded', function () {
    glover_e2e_mail_result('sent', true);
});
add_action('wp_mail_failed', function () {
    glover_e2e_mail_result('failed', true);
});

/**
 * Record the outcome on the email just captured.
 *
 * @param string $result  sent, held, failed or blocked.
 * @param bool   $pending Only when no outcome is recorded yet.
 */
function glover_e2e_mail_result($result, $pending = false)
{
    $key = $GLOBALS['glover_e2e_mail_key'] ?? '';
    $entry = $key ? get_option($key) : null;

    if (!is_array($entry) || ($pending && $entry['result'] !== 'pending')) {
        return;
    }

    $entry['result'] = $result;
    update_option($key, $entry, 'no');
}

/**
 * Stand in for the Google Drive backup: unhook the real Drive jobs and
 * register a stub handler that records what Drive would have been sent,
 * under the name Drive would have used, the way a successful upload does.
 */
function glover_e2e_guard_drive()
{
    global $wp_filter;

    if (!glover_e2e_state() || !function_exists('glover_document_library_drive_location')) {
        return;
    }

    remove_action('glover_document_library_drive_backup', 'glover_document_library_drive_run', 10);

    if (isset($wp_filter['glover_document_library/backup_requested'])) {
        foreach ($wp_filter['glover_document_library/backup_requested']->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                if ($callback['function'] instanceof Closure
                    && basename((new ReflectionFunction($callback['function']))->getFileName()) === 'document-library-drive.php') {
                    remove_action('glover_document_library/backup_requested', $callback['function'], $priority);
                }
            }
        }
    }

    add_filter('glover_document_library/backup_handlers', function () {
        return array('glover_e2e' => 'Test stub');
    }, 99);
    add_action('glover_document_library/backup_requested', 'glover_e2e_drive_stub', 10, 2);
}
add_action('after_setup_theme', 'glover_e2e_guard_drive', 99);

/**
 * The stub backup: what glover_document_library_drive_run() records after a
 * successful upload, with a made-up file ID and nothing sent to Google.
 *
 * @param int $post_id       Document ID.
 * @param int $attachment_id File.
 */
function glover_e2e_drive_stub($post_id, $attachment_id)
{
    if (!$attachment_id) {
        return;
    }

    $location = glover_document_library_drive_location($post_id, $attachment_id);
    $path = implode(' / ', $location['folders']) . ' / ' . $location['name'];
    $drive_id = 'e2e-drive-' . (int) $attachment_id;
    $files = glover_document_library_meta_list($post_id, '_glover_drive_files');
    $files[$attachment_id] = $drive_id;
    update_post_meta($post_id, '_glover_drive_files', $files);
    update_post_meta($post_id, '_glover_drive_file_id', $drive_id);
    glover_document_library_set_backup_status($post_id, 'backed_up', $path);
    glover_document_library_log($post_id, 'backed up to Drive', 'Test stub: ' . $path);

    $calls = glover_document_library_meta_list($post_id, '_glover_e2e_drive_calls');
    $calls[] = array('attachment' => (int) $attachment_id, 'name' => $location['name'], 'folders' => $location['folders']);
    update_post_meta($post_id, '_glover_e2e_drive_calls', $calls);
}

// Approval and waiting-for-review emails are batched for 10 quiet minutes.
// The suite sets the quiet period to 0 (send at once) except in its batching
// scenario, which leaves it out of the state to test the real timing.
add_filter('glover_document_library/notify_quiet_seconds', function ($seconds) {
    $state = glover_e2e_state();

    return ($state && isset($state['quiet'])) ? (int) $state['quiet'] : $seconds;
}, 99);

// The suite runs scheduled jobs itself, so its own web requests never start WP-Cron.
if (isset($_SERVER['HTTP_X_GLOVER_E2E'])) {
    remove_action('init', 'wp_cron');
}

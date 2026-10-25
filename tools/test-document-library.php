<?php
/**
 * End-to-end test of the Document Library's submission and review flow,
 * against the local site. Not for production.
 *
 *   wp eval-file tools/test-document-library.php            run, emailing the test inbox
 *   wp eval-file tools/test-document-library.php hold       run, capturing email without sending
 *   wp eval-file tools/test-document-library.php keep       run, leaving the test content in place
 *
 * With MAMP, `wp` needs MAMP's database socket (see tools/README.md).
 *
 * It creates throwaway accounts (two submitters, an Auto-approve submitter
 * and an approver, all at example.com), makes the approver the only approver
 * for the length of the run, and then works through the flow the way people
 * do: the Submit a Document form, Approve, Return, the replacement Compare
 * form and the undo buttons are all real HTTP requests to the local site,
 * signed in as those accounts. After each step it checks the post status,
 * fields, file location, privacy and name, History, search indexing, the
 * Drive backup and every email sent, and prints PASS or FAIL for each check.
 *
 * Email: tools/test-document-library-guard.php is copied into
 * wp-content/mu-plugins/ for the length of the run, so the site's own web
 * requests are guarded too. Every email goes to GLOVER_E2E_INBOX and to no
 * one else (no Cc or Bcc), its subject starts "[Test <step>]", and a box at
 * the top says which scenario and step sent it and who it was addressed to.
 *
 * Batched emails: approvals and waiting-for-review emails normally wait for
 * a quiet period. The guard sets it to 0 (send at once) for scenarios A to G;
 * scenario H uses the real timing, checks what is scheduled and when, and
 * runs the scheduled send itself.
 *
 * Drive: the guard unhooks the real Google Drive jobs and records what would
 * have been backed up, and under which name, in their place.
 *
 * Afterwards every test document, file, search row, scheduled job and
 * account is removed, the Approvers and Uploaders settings are put back
 * exactly as they were, and the guard is deleted.
 */

if (!defined('ABSPATH')) {
    exit;
}

const GLOVER_E2E_INBOX = 'jenny@townofglover.com';
const GLOVER_E2E_BOARD = 'Select Board';
const GLOVER_E2E_GUARD = 'glover-e2e-guard.php';

$e2e_args = isset($args) ? (array) $args : array();
$e2e_hold = in_array('hold', $e2e_args, true);
$e2e_keep = in_array('keep', $e2e_args, true);

require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-admin/includes/post.php';

// ----------------------------------------------------------- the guard

$e2e_guard_path = WPMU_PLUGIN_DIR . '/' . GLOVER_E2E_GUARD;

if (!copy(__DIR__ . '/test-document-library-guard.php', $e2e_guard_path)) {
    WP_CLI::error('Could not copy the test guard into ' . WPMU_PLUGIN_DIR . '.');
}

if (!function_exists('glover_e2e_state')) {
    require $e2e_guard_path;
}

update_option(GLOVER_E2E_STATE, array(
    'inbox' => GLOVER_E2E_INBOX,
    'hold' => $e2e_hold,
    'expires' => time() + HOUR_IN_SECONDS,
    'step' => 'setup',
    'step_long' => 'Setup',
    // Batched emails send at once, except in scenario H (e2e_quiet()).
    'quiet' => 0,
), 'no');
glover_e2e_guard_drive();

// ------------------------------------------------------------ reporting

$GLOBALS['e2e'] = array(
    'pass' => 0,
    'fail' => 0,
    'failures' => array(),
    'step' => '',
    'users' => array(),
    'sessions' => array(),
    'docs' => array(),
    'files' => array(),
    'settings' => array(),
    'cleaned' => false,
);

/**
 * Record and print one check.
 *
 * @param bool   $ok     Whether it passed.
 * @param string $label  What was checked.
 * @param mixed  $detail What was found, printed when it fails.
 */
function e2e_check($ok, $label, $detail = null)
{
    $step = $GLOBALS['e2e']['step'];

    if ($ok) {
        $GLOBALS['e2e']['pass']++;
        WP_CLI::log(sprintf('PASS  %-4s %s', $step, $label));
        return true;
    }

    $GLOBALS['e2e']['fail']++;
    $found = $detail === null ? '' : (is_scalar($detail) ? (string) $detail : wp_json_encode($detail, JSON_UNESCAPED_SLASHES));
    $GLOBALS['e2e']['failures'][] = $step . ' ' . $label . ($found !== '' ? ' (found: ' . $found . ')' : '');
    WP_CLI::log(sprintf('FAIL  %-4s %s%s', $step, $label, $found !== '' ? "\n            found: " . $found : ''));

    return false;
}

/**
 * Check two values are equal.
 */
function e2e_same($expected, $actual, $label)
{
    return e2e_check($expected === $actual, $label, $expected === $actual ? null : $actual);
}

/**
 * Start a step: print its heading and label the emails it sends.
 *
 * @param string $step     Short label, e.g. "A2".
 * @param string $scenario Scenario name.
 * @param string $title    Step name.
 */
function e2e_step($step, $scenario, $title)
{
    $GLOBALS['e2e']['step'] = $step;
    $state = get_option(GLOVER_E2E_STATE);
    $state['step'] = $step;
    $state['step_long'] = sprintf('Scenario %s (%s), step %s: %s.', substr($step, 0, 1), $scenario, $step, $title);
    $state['expires'] = time() + HOUR_IN_SECONDS;
    update_option(GLOVER_E2E_STATE, $state, 'no');
    WP_CLI::log('');
    WP_CLI::log(sprintf('%s  %s: %s', $step, $scenario, $title));
}

/**
 * Set the batched emails' quiet period for the steps that follow: 0 sends
 * at once, null uses the site's real timing.
 *
 * @param int|null $seconds Quiet period.
 */
function e2e_quiet($seconds)
{
    $state = get_option(GLOVER_E2E_STATE);

    if ($seconds === null) {
        unset($state['quiet']);
    } else {
        $state['quiet'] = (int) $seconds;
    }

    update_option(GLOVER_E2E_STATE, $state, 'no');
}

/**
 * When a scheduled job is due, for every copy of it with these arguments.
 *
 * @param string $hook Cron hook.
 * @param array  $args Its arguments.
 * @return int[]
 */
function e2e_events($hook, $args = array())
{
    e2e_refresh();
    $found = array();

    foreach ((array) _get_cron_array() as $time => $events) {
        foreach ((array) ($events[$hook] ?? array()) as $event) {
            if (array_map('intval', (array) $event['args']) === array_map('intval', $args)) {
                $found[] = (int) $time;
            }
        }
    }

    return $found;
}

/**
 * Run a scheduled job now, as WP-Cron would once it is due.
 *
 * @param string $hook Cron hook.
 * @param array  $args Its arguments.
 */
function e2e_run_event($hook, $args = array())
{
    foreach (e2e_events($hook, $args) as $time) {
        wp_unschedule_event($time, $hook, $args);
    }

    do_action_ref_array($hook, $args);
    e2e_refresh();
}

// ---------------------------------------------------- site and HTTP helpers

/**
 * Forget everything cached in this process, so reads see what the web
 * requests just wrote.
 */
function e2e_refresh()
{
    wp_cache_flush();

    if (function_exists('acf_get_store') && acf_get_store('values')) {
        acf_get_store('values')->reset();
    }
}

/**
 * Sign a test account in: a real session token and the cookies a browser
 * would send.
 *
 * @param int $user_id User ID.
 * @return array{id:int,cookie:string,logged_in:string}
 */
function e2e_session($user_id)
{
    if (isset($GLOBALS['e2e']['sessions'][$user_id])) {
        return $GLOBALS['e2e']['sessions'][$user_id];
    }

    $expiration = time() + 2 * HOUR_IN_SECONDS;
    $token = WP_Session_Tokens::get_instance($user_id)->create($expiration);
    $auth = wp_generate_auth_cookie($user_id, $expiration, 'auth', $token);
    $logged_in = wp_generate_auth_cookie($user_id, $expiration, 'logged_in', $token);

    return $GLOBALS['e2e']['sessions'][$user_id] = array(
        'id' => (int) $user_id,
        'cookie' => AUTH_COOKIE . '=' . rawurlencode($auth) . '; ' . LOGGED_IN_COOKIE . '=' . rawurlencode($logged_in),
        'logged_in' => $logged_in,
    );
}

/**
 * A nonce as the signed-in account would get it on the page.
 *
 * @param array  $session From e2e_session().
 * @param string $action  Nonce action.
 */
function e2e_nonce($session, $action)
{
    $_COOKIE[LOGGED_IN_COOKIE] = $session['logged_in'];
    wp_set_current_user($session['id']);
    $nonce = wp_create_nonce($action);
    unset($_COOKIE[LOGGED_IN_COOKIE]);

    return $nonce;
}

/**
 * Make a request to the local site. Redirects are not followed.
 *
 * @param string     $method  GET or POST.
 * @param string     $url     Full URL.
 * @param array|null $session From e2e_session(), or null when signed out.
 * @param array|null $fields  Form fields as [name, value] pairs.
 * @param array      $files   Uploads as [name, filename, type, data].
 * @return array{status:int,location:string,body:string,type:string}
 */
function e2e_http($method, $url, $session = null, $fields = null, $files = array())
{
    $headers = array('X-Glover-E2E: 1');

    if ($session) {
        $headers[] = 'Cookie: ' . $session['cookie'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_CUSTOMREQUEST => $method,
    ));

    if ($fields !== null || $files) {
        if ($files) {
            $boundary = '----GloverE2E' . wp_generate_password(20, false);
            $body = '';

            foreach ((array) $fields as $pair) {
                $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$pair[0]}\"\r\n\r\n{$pair[1]}\r\n";
            }

            foreach ($files as $file) {
                $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$file[0]}\"; filename=\"{$file[1]}\"\r\nContent-Type: {$file[2]}\r\n\r\n{$file[3]}\r\n";
            }

            $body .= "--{$boundary}--\r\n";
            $headers[] = 'Content-Type: multipart/form-data; boundary=' . $boundary;
        } else {
            $body = implode('&', array_map(function ($pair) {
                return rawurlencode($pair[0]) . '=' . rawurlencode((string) $pair[1]);
            }, (array) $fields));
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }

        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $header_size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    $head = $raw === false ? '' : substr($raw, 0, $header_size);
    $location = preg_match('/^Location:\s*(\S+)/mi', $head, $m) ? $m[1] : '';
    e2e_refresh();

    return array(
        'status' => $status,
        'location' => $location,
        'body' => $raw === false ? '' : (string) substr($raw, $header_size),
        'type' => $type,
    );
}

/**
 * The text of a wp_die() page, for failure output.
 *
 * @param array $response From e2e_http().
 */
function e2e_page_text($response)
{
    $text = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags(preg_replace('#<(script|style)\b.*?</\1>#is', '', $response['body']))));

    return 'HTTP ' . $response['status'] . ' ' . ($response['location'] ? '-> ' . $response['location'] . ' ' : '') . substr($text, 0, 300);
}

// ------------------------------------------------------------ test data

/**
 * A one-page PDF whose text the search reader can extract.
 *
 * @param string[] $lines Lines of text.
 */
function e2e_pdf($lines)
{
    $esc = function ($s) {
        return str_replace(array('\\', '(', ')'), array('\\\\', '\\(', '\\)'), $s);
    };
    $stream = "BT /F1 12 Tf 72 720 Td 16 TL\n";

    foreach ($lines as $line) {
        $stream .= '(' . $esc($line) . ") Tj T*\n";
    }

    $stream .= 'ET';
    $objects = array(
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
        "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream",
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
    );
    $pdf = "%PDF-1.4\n";
    $offsets = array();

    foreach ($objects as $i => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n" . $object . "\nendobj\n";
    }

    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";

    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }

    return $pdf . "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
}

/**
 * Create a throwaway account.
 *
 * @param string $slug  Login and address part, e.g. "submitter-a".
 * @param string $name  Display name.
 * @param string $role  Role.
 * @return int User ID.
 */
function e2e_user($slug, $name, $role)
{
    $login = 'glover-e2e-' . $slug;
    $existing = get_user_by('login', $login);

    if ($existing) {
        wp_delete_user($existing->ID);
    }

    $user_id = wp_insert_user(array(
        'user_login' => $login,
        'user_email' => 'glover-e2e-' . $slug . '@example.com',
        'user_pass' => wp_generate_password(32),
        'display_name' => $name,
        'first_name' => $name,
        'role' => $role,
    ));

    if (is_wp_error($user_id)) {
        WP_CLI::error($user_id->get_error_message());
    }

    // Nothing here should send the welcome email; mark it sent regardless.
    update_user_meta($user_id, 'glover_document_library_welcomed', time());
    $GLOBALS['e2e']['users'][] = (int) $user_id;

    return (int) $user_id;
}

/**
 * Submit a document through the real Submit a Document page, signed in as
 * the submitter: load the page, keep its hidden fields and nonces, fill in
 * the fields and upload the file.
 *
 * @param int   $user_id Submitter.
 * @param array $doc     title, minutes, category, topics, date (Y-m-d), replaces, note, lines.
 * @return array{id:int,response:array}
 */
function e2e_submit($user_id, $doc)
{
    $session = e2e_session($user_id);
    $page = e2e_http('GET', glover_document_library_submission_url(), $session);
    $pairs = array();

    if ($page['status'] === 200) {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($page['body']);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        foreach ($xpath->query('//form[@id="' . GLOVER_DOCUMENT_LIBRARY_FORM_ID . '"]//input[@type="hidden"]') as $input) {
            $pairs[] = array($input->getAttribute('name'), $input->getAttribute('value'));
        }
    }

    list($year, $month, $day) = explode('-', $doc['date']);
    $filename = $doc['filename'] ?? 'scan from the copier.pdf';
    $pdf = e2e_pdf($doc['lines']);

    // What the browser does: ACF's script puts the chosen file's name, size
    // and type in the field's hidden input (its Required and type checks
    // read it), and ACF's conditional logic leaves Minutes Status out
    // unless the category is Minutes.
    $pairs = array_values(array_filter($pairs, function ($pair) use ($doc) {
        return empty($doc['minutes']) ? $pair[0] !== 'acf[field_glover_document_minutes_status]' : true;
    }));
    $pairs[] = array('acf[field_glover_document_file]', http_build_query(array('url' => 'C:\\fakepath\\' . $filename, 'size' => strlen($pdf), 'type' => 'application/pdf')));
    $pairs[] = array('acf[_post_title]', $doc['title']);
    $pairs[] = array('acf[field_glover_document_boards][]', (string) $GLOBALS['e2e']['board']);
    $pairs[] = array('acf[field_glover_document_category]', (string) $GLOBALS['e2e']['terms'][$doc['category']]);

    if (!empty($doc['minutes'])) {
        $pairs[] = array('acf[field_glover_document_minutes_status]', $doc['minutes']);
    }

    $pairs[] = array('acf[field_glover_document_date_parts][field_glover_document_date_month]', $month);
    $pairs[] = array('acf[field_glover_document_date_parts][field_glover_document_date_day]', (string) (int) $day);
    $pairs[] = array('acf[field_glover_document_date_parts][field_glover_document_date_year]', $year);

    foreach ((array) ($doc['topics'] ?? array()) as $topic) {
        $pairs[] = array('acf[field_glover_document_topics][]', (string) $GLOBALS['e2e']['topics'][$topic]);
    }

    if (!empty($doc['replaces'])) {
        $pairs[] = array('acf[field_glover_document_replaces]', (string) $doc['replaces']);
    }

    $pairs[] = array('acf[field_glover_document_accessibility_confirmed]', '1');
    $pairs[] = array('acf[field_glover_document_legal_confirmed]', '1');
    $pairs[] = array('acf[field_glover_document_reviewer_note]', (string) ($doc['note'] ?? ''));

    $response = e2e_http('POST', glover_document_library_submission_url(), $session, $pairs, array(
        array('acf[field_glover_document_file]', $filename, 'application/pdf', $pdf),
    ));
    $id = preg_match('/[?&]submitted=(\d+)/', $response['location'], $m) ? (int) $m[1] : 0;

    if ($id) {
        $GLOBALS['e2e']['docs'][] = $id;
        e2e_track_files($id);
    }

    return array('id' => $id, 'response' => $response, 'page' => $page);
}

/**
 * Remember a document's files so cleanup can confirm they are gone.
 *
 * @param int $post_id Document ID.
 */
function e2e_track_files($post_id)
{
    $attachment = glover_document_library_file_id($post_id);

    if ($attachment) {
        $GLOBALS['e2e']['files'][$attachment] = get_attached_file($attachment);
    }
}

// ------------------------------------------------------ what to check

/**
 * Emails captured since the step started.
 *
 * @param string $step Step label.
 * @return array[]
 */
function e2e_mail($step)
{
    global $wpdb;

    $rows = $wpdb->get_col($wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id",
        $wpdb->esc_like(GLOVER_E2E_MAIL_PREFIX) . '%'
    ));
    $mail = array();

    foreach ($rows as $row) {
        $entry = maybe_unserialize($row);

        if (is_array($entry) && $entry['step'] === $step) {
            $mail[] = $entry;
        }
    }

    return $mail;
}

/**
 * Check a step sent exactly these emails, in order, each only to the test
 * inbox, and that each one went out.
 *
 * @param array[] $expected Each: to (original address), subject (exact, without the site prefix), contains (strings in the body).
 */
function e2e_expect_mail($expected)
{
    $step = $GLOBALS['e2e']['step'];
    $mail = e2e_mail($step);
    $summary = array_map(function ($m) {
        return implode(',', $m['to']) . ': ' . $m['subject'];
    }, $mail);

    e2e_check(count($mail) === count($expected), sprintf('sends %d email%s', count($expected), count($expected) === 1 ? '' : 's'), count($mail) === count($expected) ? null : $summary);

    foreach (array_values($expected) as $i => $want) {
        $got = $mail[$i] ?? null;
        $which = 'email ' . ($i + 1) . ' ("' . $want['subject'] . '")';

        if (!$got) {
            continue;
        }

        e2e_same(array_map('strtolower', (array) $want['to']), array_map('strtolower', $got['to']), $which . ' is addressed to ' . implode(', ', (array) $want['to']));
        e2e_same('Town of Glover | ' . $want['subject'], $got['subject'], $which . ' has that subject');

        foreach ((array) ($want['contains'] ?? array()) as $text) {
            e2e_check(strpos(html_entity_decode($got['message'], ENT_QUOTES), $text) !== false, $which . ' says "' . $text . '"');
        }

        foreach ((array) ($want['excludes'] ?? array()) as $text) {
            e2e_check(strpos(html_entity_decode($got['message'], ENT_QUOTES), $text) === false, $which . ' does not mention "' . $text . '"');
        }

        e2e_check($got['sent_to'] === array(GLOVER_E2E_INBOX) && !$got['copied'], $which . ' went only to ' . GLOVER_E2E_INBOX, array('sent_to' => $got['sent_to'], 'copied' => $got['copied']));
        e2e_check(in_array($got['result'], array('sent', 'held'), true), $which . ($GLOBALS['e2e']['hold'] ? ' was captured (hold)' : ' was delivered to Gmail'), $got['result']);
    }
}

/**
 * The relative path of a document's current file under uploads.
 *
 * @param int $attachment_id Attachment ID.
 */
function e2e_upload_path($attachment_id)
{
    $uploads = wp_get_upload_dir();

    return ltrim(str_replace(wp_normalize_path($uploads['basedir']), '', wp_normalize_path((string) get_attached_file($attachment_id))), '/');
}

/**
 * The public URL of an attachment's file, whatever folder it is in.
 *
 * @param int $attachment_id Attachment ID.
 */
function e2e_direct_url($attachment_id)
{
    $uploads = wp_get_upload_dir();

    return trailingslashit($uploads['baseurl']) . e2e_upload_path($attachment_id);
}

/**
 * Check where a file is, its name, and whether the web can fetch it.
 *
 * @param int    $attachment_id Attachment ID.
 * @param bool   $private       Whether it should be in the private folder.
 * @param string $name          Expected file name.
 * @param string $label         What the file is, for the check names.
 */
function e2e_expect_file($attachment_id, $private, $name, $label, $numbered = false)
{
    $path = e2e_upload_path($attachment_id);
    $folder = $private ? 'glover-private-documents/' . gmdate('Y') . '/' : 'documents/' . gmdate('Y') . '/';

    e2e_check($attachment_id && file_exists(get_attached_file($attachment_id)), $label . ' is on disk', $path);
    e2e_check(strpos($path, $folder) === 0, $label . ' is in ' . $folder, $path);

    if ($numbered) {
        // Files with the same name can share the private folder (a returned
        // copy and its resubmission, or an earlier version), so the later
        // one gets "-1" (allowed by glover_document_library_rename_file()).
        $base = pathinfo($name, PATHINFO_FILENAME);
        e2e_check((bool) preg_match('/^' . preg_quote($base, '/') . '(-\d+)?\.pdf$/', basename($path)), $label . ' is named ' . $name . ', or with -N while another private file has that name', basename($path));
    } else {
        e2e_same($name, basename($path), $label . ' is named ' . $name);
    }

    $response = e2e_http('GET', e2e_direct_url($attachment_id));
    e2e_same($private ? 403 : 200, $response['status'], $label . ($private ? "'s direct address is refused (403)" : ' downloads from its direct address (200)'));
}

/**
 * Check the audit log holds these actions, in this order (other entries
 * may come between).
 *
 * @param int      $post_id Document ID.
 * @param string[] $actions Expected actions.
 * @param string   $label   What to call the check.
 */
function e2e_expect_log($post_id, $actions, $label = '')
{
    $log = array_column(glover_document_library_meta_list($post_id, '_glover_audit_log'), 'action');
    $at = 0;

    foreach ($log as $action) {
        if ($at < count($actions) && $action === $actions[$at]) {
            $at++;
        }
    }

    e2e_check($at === count($actions), ($label !== '' ? $label : 'History') . ' records ' . implode(', ', $actions), $log);
}

/**
 * The History box as an approver sees it on the edit screen.
 *
 * @param int $post_id Document ID.
 */
function e2e_history_html($post_id)
{
    ob_start();
    glover_document_library_render_history_box(get_post($post_id));

    return ob_get_clean();
}

/**
 * The search index rows for a document: attachment IDs and parts.
 *
 * @param int $post_id Document ID.
 * @return array[]
 */
function e2e_index_rows($post_id)
{
    global $wpdb;

    return $wpdb->get_results($wpdb->prepare('SELECT attachment_id, part FROM ' . glover_document_search_table() . ' WHERE post_id = %d ORDER BY part', $post_id), ARRAY_A);
}

/**
 * Run the queued search job for a document, as WP-Cron would, after
 * checking it was queued.
 *
 * @param int $post_id Document ID.
 */
function e2e_run_index($post_id)
{
    e2e_refresh();
    $queued = wp_next_scheduled(GLOVER_DOCUMENT_SEARCH_HOOK, array((int) $post_id));
    e2e_check((bool) $queued, 'search indexing is queued (status ' . get_post_meta($post_id, '_glover_text_index_status', true) . ')');

    if ($queued) {
        wp_unschedule_event($queued, GLOVER_DOCUMENT_SEARCH_HOOK, array((int) $post_id));
    }

    do_action(GLOVER_DOCUMENT_SEARCH_HOOK, (int) $post_id);
    e2e_refresh();
}

/**
 * Check the public library search finds (or doesn't find) a document for a
 * word, through the same REST endpoint the Documents page uses.
 *
 * @param string $word    Search word.
 * @param int    $post_id Document ID.
 * @param bool   $found   Whether it should be found.
 */
function e2e_expect_search($word, $post_id, $found)
{
    $response = e2e_http('GET', add_query_arg('q', $word, rest_url('glover/v1/documents')));
    $data = json_decode($response['body'], true);
    $html = is_array($data) ? (string) $data['results'] : '';
    $link = untrailingslashit(wp_make_link_relative(get_permalink($post_id)));
    $is_found = $link !== '' && strpos($html, $link) !== false;

    e2e_check($is_found === $found, sprintf('the Documents search %s it for "%s"', $found ? 'finds' : 'no longer finds', $word), is_array($data) ? 'total ' . $data['total'] : e2e_page_text($response));
}

/**
 * Check what the Drive stub was asked to back up.
 *
 * @param int    $post_id       Document ID.
 * @param int    $attachment_id File it should have backed up last.
 * @param string $name          File name Drive would have used.
 * @param int    $calls         How many backups there should have been in all.
 */
function e2e_expect_drive($post_id, $attachment_id, $name, $calls, $category = 'Minutes')
{
    $log = glover_document_library_meta_list($post_id, '_glover_e2e_drive_calls');
    $last = $log ? end($log) : array();

    e2e_same($calls, count($log), sprintf('Drive backup was requested %d time%s', $calls, $calls === 1 ? '' : 's'));
    e2e_same($attachment_id, (int) ($last['attachment'] ?? 0), 'Drive backed up the current file');
    e2e_same($name, $last['name'] ?? '', 'Drive file is named ' . $name);
    e2e_same(basename((string) get_attached_file($attachment_id)), $last['name'] ?? '', 'Drive and the site use the same file name');
    e2e_same(array(GLOVER_E2E_BOARD, '2026', $category), $last['folders'] ?? array(), 'Drive folder is ' . GLOVER_E2E_BOARD . ' / 2026 / ' . $category);
    e2e_same('backed_up', get_post_meta($post_id, '_glover_drive_backup_status', true), 'Drive backup status is Backed up');
    e2e_same('e2e-drive-' . $attachment_id, get_post_meta($post_id, '_glover_drive_file_id', true), 'Drive file ID points at the current file');
    e2e_check(!e2e_drive_jobs($post_id), 'no real Drive upload was scheduled');
}

/**
 * Real Drive jobs scheduled for a document.
 *
 * @param int $post_id Document ID.
 */
function e2e_drive_jobs($post_id)
{
    $found = 0;

    foreach ((array) _get_cron_array() as $events) {
        foreach ((array) ($events[GLOVER_DOCUMENT_LIBRARY_DRIVE_HOOK] ?? array()) as $event) {
            if ((int) ($event['args'][0] ?? 0) === (int) $post_id) {
                $found++;
            }
        }
    }

    return $found;
}

/**
 * Check a document's fields.
 *
 * @param int   $post_id Document ID.
 * @param array $want    title, date (Ymd), minutes, category, topics (slugs), note.
 */
function e2e_expect_fields($post_id, $want)
{
    $categories = wp_get_object_terms($post_id, GLOVER_DOCUMENT_LIBRARY_CATEGORY_TAXONOMY, array('fields' => 'slugs'));
    $topics = wp_get_object_terms($post_id, GLOVER_DOCUMENT_LIBRARY_TOPIC_TAXONOMY, array('fields' => 'slugs'));
    $board_terms = wp_get_object_terms($post_id, GLOVER_DOCUMENT_LIBRARY_BOARD_TAXONOMY, array('fields' => 'slugs'));
    sort($topics);
    $want_topics = $want['topics'];
    sort($want_topics);

    e2e_same($want['title'], get_post_field('post_title', $post_id, 'raw'), 'title is "' . $want['title'] . '"');
    e2e_same($want['date'], (string) get_field('document_date', $post_id, false), 'document date is ' . $want['date']);
    e2e_same($want['minutes'], (string) get_field('document_minutes_status', $post_id, false), 'minutes status is ' . ($want['minutes'] !== '' ? $want['minutes'] : 'empty'));
    e2e_same(array((string) $GLOBALS['e2e']['board']), array_map('strval', (array) get_field('document_boards', $post_id, false)), 'board is ' . GLOVER_E2E_BOARD);
    e2e_same(array(glover_document_library_board_term_slug($GLOBALS['e2e']['board'])), $board_terms, 'board is mirrored into the hidden board taxonomy');
    e2e_same(array($want['category']), $categories, 'category is ' . $want['category']);
    e2e_same($want_topics, $topics, 'topics are ' . ($want_topics ? implode(', ', $want_topics) : 'none'));

    if (array_key_exists('note', $want)) {
        e2e_same($want['note'], (string) get_post_meta($post_id, 'document_reviewer_note', true), 'note to the reviewer is saved');
    }
}

/**
 * Check a fresh submission: who sent it, its status, the confirmations, and
 * that it is not public or searchable.
 *
 * @param array $submitted From e2e_submit().
 * @param int   $user_id   Submitter.
 */
function e2e_expect_submitted($submitted, $user_id)
{
    $id = $submitted['id'];

    e2e_check($submitted['page']['status'] === 200 && strpos($submitted['page']['body'], GLOVER_DOCUMENT_LIBRARY_FORM_ID) !== false, 'Submit a Document shows the form to the submitter', 'HTTP ' . $submitted['page']['status']);
    e2e_check($id > 0, 'the form saves and returns to the thank-you page', e2e_page_text($submitted['response']));

    if (!$id) {
        return false;
    }

    e2e_same('pending', get_post_status($id), 'status is Pending');
    e2e_same($user_id, (int) get_post_field('post_author', $id), 'the submitter is the author');
    e2e_same($user_id, (int) get_post_meta($id, '_glover_submitted_by', true), 'submitted-by is recorded');
    e2e_check((int) get_field('document_accessibility_confirmed', $id, false) === 1 && (int) get_field('document_legal_confirmed', $id, false) === 1, 'both confirmations are saved');
    e2e_check(get_post_meta($id, 'document_date_parts_month', true) === '' && get_post_meta($id, 'document_date_parts', true) === '', 'Month, Day and Year are not saved themselves');
    e2e_same('', get_post_field('post_name', $id), 'no address (slug) yet');
    e2e_same(array(), e2e_index_rows($id), 'nothing is in the search index');
    e2e_same('', get_post_meta($id, '_glover_drive_backup_status', true), 'no Drive backup');

    return true;
}

// ------------------------------------------------------------- cleanup

/**
 * The option rows behind the Approvers and Uploaders settings.
 */
function e2e_settings_rows()
{
    global $wpdb;

    $likes = array();

    foreach (array('options_document_library_uploaders', '_options_document_library_uploaders', 'options_document_library_approver', '_options_document_library_approver') as $name) {
        $likes[] = $wpdb->prepare('option_name LIKE %s', $wpdb->esc_like($name) . '%');
    }

    return $wpdb->get_results("SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE " . implode(' OR ', $likes) . ' ORDER BY option_name', ARRAY_A);
}

/**
 * Put the Approvers and Uploaders option rows back exactly.
 *
 * @param array[] $rows From e2e_settings_rows().
 */
function e2e_restore_settings($rows)
{
    global $wpdb;

    foreach (e2e_settings_rows() as $row) {
        $wpdb->delete($wpdb->options, array('option_name' => $row['option_name']));
    }

    foreach ($rows as $row) {
        $wpdb->insert($wpdb->options, $row);
    }

    e2e_refresh();
}

/**
 * Remove everything the run made, and the guard. Runs at the end, and on
 * shutdown if the run stopped early.
 */
function e2e_cleanup()
{
    global $wpdb;

    if ($GLOBALS['e2e']['cleaned']) {
        return;
    }

    $GLOBALS['e2e']['cleaned'] = true;
    e2e_refresh();
    wp_set_current_user(0);
    $users = $GLOBALS['e2e']['users'];
    $docs = $users ? get_posts(array(
        'post_type' => GLOVER_DOCUMENT_LIBRARY_POST_TYPE,
        'post_status' => 'any',
        'author__in' => $users,
        'posts_per_page' => -1,
        'fields' => 'ids',
    )) : array();
    $docs = array_unique(array_merge($docs, get_posts(array(
        'post_type' => GLOVER_DOCUMENT_LIBRARY_POST_TYPE,
        'post_status' => 'trash',
        'author__in' => $users ? $users : array(-1),
        'posts_per_page' => -1,
        'fields' => 'ids',
    )), $GLOBALS['e2e']['docs']));

    foreach ($docs as $id) {
        $files = array(glover_document_library_file_id($id));

        foreach (glover_document_library_meta_list($id, '_glover_previous_files') as $version) {
            $files[] = (int) ($version['attachment'] ?? 0);
            $files[] = (int) ($version['new_attachment'] ?? 0);
        }

        $files = array_merge($files, get_posts(array('post_type' => 'attachment', 'post_parent' => $id, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids')));

        foreach (array_unique(array_filter($files)) as $file) {
            wp_delete_attachment($file, true);
        }

        glover_document_search_delete_rows($id);
        wp_delete_post($id, true);
    }

    foreach (array_keys($GLOBALS['e2e']['files']) as $attachment) {
        if (get_post($attachment)) {
            wp_delete_attachment($attachment, true);
        }
    }

    $crons = _get_cron_array();

    foreach ((array) $crons as $time => $events) {
        foreach (array(GLOVER_DOCUMENT_SEARCH_HOOK, GLOVER_DOCUMENT_LIBRARY_DRIVE_HOOK) as $hook) {
            foreach ((array) ($events[$hook] ?? array()) as $event) {
                if (in_array((int) ($event['args'][0] ?? 0), array_map('intval', $docs), true)) {
                    wp_unschedule_event($time, $hook, $event['args']);
                }
            }
        }
    }

    // Batched emails still scheduled for the test accounts, and the
    // waiting-for-review batch if the run started it.
    foreach ($users as $user_id) {
        wp_clear_scheduled_hook(GLOVER_DOCUMENT_LIBRARY_APPROVED_HOOK, array((int) $user_id));
    }

    if (empty($GLOBALS['e2e']['pending_batch_before'])) {
        wp_clear_scheduled_hook(GLOVER_DOCUMENT_LIBRARY_PENDING_HOOK, array());
        delete_option(GLOVER_DOCUMENT_LIBRARY_PENDING_FIRST);
    }

    foreach ($users as $user_id) {
        wp_delete_user($user_id);
    }

    if ($GLOBALS['e2e']['settings']) {
        e2e_restore_settings($GLOBALS['e2e']['settings']);
    }

    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like(GLOVER_E2E_MAIL_PREFIX) . '%'));
    delete_option(GLOVER_E2E_STATE);
    @unlink(WPMU_PLUGIN_DIR . '/' . GLOVER_E2E_GUARD);
    e2e_refresh();

    return $docs;
}

register_shutdown_function(function () use ($e2e_keep) {
    if (!$GLOBALS['e2e']['cleaned'] && !$e2e_keep) {
        e2e_cleanup();
    }
});

// =================================================================== setup

$GLOBALS['e2e']['hold'] = $e2e_hold;
$GLOBALS['e2e']['pending_batch_before'] = (bool) wp_next_scheduled(GLOVER_DOCUMENT_LIBRARY_PENDING_HOOK, array());
$GLOBALS['e2e']['settings'] = e2e_settings_rows();
$settings_before = array(
    'approvers' => glover_document_library_approver_ids(),
    'uploaders' => glover_document_library_setting('uploaders', array()),
);

foreach (glover_document_library_all_boards() as $board_id => $board_name) {
    if (html_entity_decode($board_name, ENT_QUOTES) === GLOVER_E2E_BOARD) {
        $GLOBALS['e2e']['board'] = (int) $board_id;
    }
}

if (empty($GLOBALS['e2e']['board'])) {
    WP_CLI::error('No published board named ' . GLOVER_E2E_BOARD . '.');
}

foreach (array(GLOVER_DOCUMENT_LIBRARY_CATEGORY_TAXONOMY => 'terms', GLOVER_DOCUMENT_LIBRARY_TOPIC_TAXONOMY => 'topics') as $taxonomy => $key) {
    foreach (get_terms(array('taxonomy' => $taxonomy, 'hide_empty' => false)) as $term) {
        $GLOBALS['e2e'][$key][$term->slug] = (int) $term->term_id;
    }
}

foreach (array('minutes', 'agendas') as $slug) {
    if (empty($GLOBALS['e2e']['terms'][$slug])) {
        WP_CLI::error('No document category "' . $slug . '".');
    }
}

$submitter_a = e2e_user('submitter-a', 'Test Submitter A', GLOVER_DOCUMENT_LIBRARY_ROLE);
$submitter_b = e2e_user('submitter-b', 'Test Submitter B', GLOVER_DOCUMENT_LIBRARY_ROLE);
$auto = e2e_user('auto-approve', 'Test Auto-Approve Submitter', GLOVER_DOCUMENT_LIBRARY_ROLE);
$approver = e2e_user('approver', 'Test Approver', 'editor');
$email = function ($user_id) {
    return get_userdata($user_id)->user_email;
};

glover_document_library_save_uploader($submitter_a, false, array($GLOBALS['e2e']['board']), false);
glover_document_library_save_uploader($submitter_b, false, array($GLOBALS['e2e']['board']), false);
glover_document_library_save_uploader($auto, false, array($GLOBALS['e2e']['board']), true);
update_field('field_glover_document_settings_approver', array($approver), 'option');
e2e_refresh();

$approver_session = e2e_session($approver);
$year = gmdate('Y');
WP_CLI::log(sprintf('Document Library end-to-end test, %s. Email %s %s.', wp_date('M j, Y g:i a'), $e2e_hold ? 'is captured, not sent, for' : 'goes only to', GLOVER_E2E_INBOX));

/**
 * Approve a new document with the Approve button.
 */
$approve = function ($post_id) use ($approver_session) {
    $nonce = e2e_nonce($approver_session, 'glover_document_approve_' . $post_id);

    return e2e_http('GET', add_query_arg(array('_wpnonce' => $nonce, 'back' => 'review'), admin_url('admin-post.php?action=glover_document_approve&post=' . (int) $post_id)), $approver_session);
};

/**
 * Return a new document with a reason, from the Return dialog.
 */
$return = function ($post_id, $reason) use ($approver_session) {
    return e2e_http('POST', admin_url('admin-post.php'), $approver_session, array(
        array('action', 'glover_document_return'),
        array('post', $post_id),
        array('back', 'review'),
        array('_wpnonce', e2e_nonce($approver_session, 'glover_document_return_' . $post_id)),
        array('reason', $reason),
    ));
};

/**
 * Post the replacement Compare form.
 *
 * @param array $fields decision, replacement, keep (detail => current|proposed), title, others_reason, reason.
 */
$compare = function ($original_id, $fields) use ($approver_session) {
    $pairs = array(
        array('action', 'glover_document_review_replacement'),
        array('original', $original_id),
        array('_wpnonce', e2e_nonce($approver_session, 'glover_document_replacement_' . $original_id)),
        array('decision', $fields['decision']),
    );

    if (!empty($fields['replacement'])) {
        $pairs[] = array('replacement', $fields['replacement']);

        foreach (glover_document_library_compare_keys() as $key => $label) {
            $pairs[] = array('keep[' . $fields['replacement'] . '][' . $key . ']', $fields['keep'][$key] ?? 'proposed');
        }

        $pairs[] = array('title[' . $fields['replacement'] . ']', $fields['title'] ?? get_post_field('post_title', $fields['replacement'], 'raw'));
    }

    foreach (array('others_reason', 'reason') as $key) {
        if (isset($fields[$key])) {
            $pairs[] = array($key, $fields[$key]);
        }
    }

    return e2e_http('POST', admin_url('admin-post.php'), $approver_session, $pairs);
};

/**
 * Press Undo replacement or Move back to Pending.
 */
$undo = function ($post_id, $mode) use ($approver_session) {
    return e2e_http('POST', admin_url('admin-post.php'), $approver_session, array(
        array('action', 'glover_document_undo'),
        array('undo', $mode),
        array('post', $post_id),
        array('_wpnonce', e2e_nonce($approver_session, 'glover_document_undo_' . $post_id)),
    ));
};

$redirected_with = function ($response, $notice) {
    return $response['status'] === 302 && strpos($response['location'], 'glover_notice=' . $notice) !== false;
};

$stream = function ($post_id, $user_id, $attachment = 0) {
    $url = add_query_arg(array_filter(array('action' => 'glover_document_file', 'document' => $post_id, 'attachment' => $attachment)), admin_url('admin-post.php'));

    return e2e_http('GET', $url, e2e_session($user_id));
};

// Names the stored files should have (board_category_date_title).
$names = array(
    'draft' => 'select-board_draft-minutes_2026-09-17_e2e-test-meeting-minutes.pdf',
    'approved' => 'select-board_minutes_2026-09-17_e2e-test-meeting-minutes.pdf',
    'v2' => 'select-board_minutes_2026-09-17_e2e-test-regular-meeting-minutes_v2.pdf',
    'v3' => 'select-board_minutes_2026-09-17_e2e-test-regular-meeting-minutes_v3.pdf',
    'agenda' => 'select-board_agendas_2026-09-30_e2e-test-special-meeting-agenda.pdf',
    'auto' => 'select-board_agendas_2026-10-05_e2e-test-budget-hearing-agenda.pdf',
);

// ======================================================= A. New document

$scenario = 'New document';
e2e_step('A1', $scenario, 'Submit draft minutes');

$a1 = e2e_submit($submitter_a, array(
    'title' => 'Select Board E2E Test Meeting Minutes - September 17, 2026',
    'minutes' => 'draft',
    'category' => 'minutes',
    'topics' => array('roads-bridges'),
    'date' => '2026-09-17',
    'note' => 'Test note: first draft.',
    'lines' => array('E2E Test Meeting Minutes', 'Town of Glover, Vermont', 'Larkspurtest culvert discussion, first draft.'),
));
$returned_id = $a1['id'];

if (!e2e_expect_submitted($a1, $submitter_a)) {
    WP_CLI::error('The submission form did not save, so the rest of the run would mean nothing. ' . e2e_page_text($a1['response']));
}

e2e_expect_fields($returned_id, array('title' => 'E2E Test Meeting Minutes', 'date' => '20260917', 'minutes' => 'draft', 'category' => 'minutes', 'topics' => array('roads-bridges'), 'note' => 'Test note: first draft.'));
$a1_file = glover_document_library_file_id($returned_id);
e2e_expect_file($a1_file, true, $names['draft'], 'the file');
e2e_expect_log($returned_id, array('title tidied', 'submitted'));
e2e_check(strpos(e2e_history_html($returned_id), 'Title tidied from') !== false, 'the History box shows the title was tidied');
e2e_same(200, $stream($returned_id, $submitter_a)['status'], 'the submitter can preview their own pending file');
e2e_same(403, $stream($returned_id, $submitter_b)['status'], 'another submitter cannot preview it (403)');
e2e_same(200, $stream($returned_id, $approver)['status'], 'the approver can preview it');
e2e_same(404, e2e_http('GET', get_permalink($returned_id))['status'], 'its address is a 404 to the public');

// The publishing guard: a submitter can't publish their own document.
wp_set_current_user($submitter_a);
wp_update_post(array('ID' => $returned_id, 'post_status' => 'publish'));
wp_set_current_user(0);
e2e_refresh();
e2e_same('pending', get_post_status($returned_id), 'a submitter trying to publish it leaves it Pending');

e2e_expect_mail(array(
    array('to' => $email($approver), 'subject' => count(get_posts(array('post_type' => GLOVER_DOCUMENT_LIBRARY_POST_TYPE, 'post_status' => 'pending', 'fields' => 'ids', 'posts_per_page' => -1))) . ' documents waiting for review', 'contains' => array('E2E Test Meeting Minutes', 'Draft minutes', 'Note from submitter', 'Test Submitter A')),
));

e2e_step('A2', $scenario, 'Return with a reason');
$reason = 'Test reason: the file is the wrong meeting. Please upload the September 17 minutes.';
$response = $return($returned_id, $reason);
e2e_check($redirected_with($response, 'returned'), 'Return goes back to the Documents screen with "returned"', e2e_page_text($response));
e2e_same('draft', get_post_status($returned_id), 'status is Draft (Returned)');
e2e_same($reason, get_post_meta($returned_id, '_glover_returned_reason', true), 'the reason is saved');
e2e_check(in_array($returned_id, glover_document_library_returned_ids(), true), 'it is listed on the Returned tab');
e2e_expect_log($returned_id, array('submitted', 'returned'));
e2e_same($reason, (string) (array_values(array_filter(glover_document_library_meta_list($returned_id, '_glover_audit_log'), function ($e) {
    return $e['action'] === 'returned';
}))[0]['note'] ?? ''), 'History keeps the reason with the Returned entry');
e2e_expect_file($a1_file, true, $names['draft'], 'the returned file');
e2e_same(array(), e2e_index_rows($returned_id), 'nothing is in the search index');
e2e_expect_mail(array(
    array('to' => $email($submitter_a), 'subject' => 'Needs changes: E2E Test Meeting Minutes', 'contains' => array('Your document needs changes', $reason, 'Submit a corrected version')),
));

e2e_step('A3', $scenario, 'Submitter uploads a corrected version');
$a3 = e2e_submit($submitter_a, array(
    'title' => 'E2E Test Meeting Minutes',
    'minutes' => 'draft',
    'category' => 'minutes',
    'topics' => array('roads-bridges'),
    'date' => '2026-09-17',
    'note' => 'Test note: corrected file.',
    'lines' => array('E2E Test Meeting Minutes', 'Town of Glover, Vermont', 'Marigoldtest culvert discussion, corrected draft.'),
));
$doc_a = $a3['id'];
e2e_expect_submitted($a3, $submitter_a);
e2e_check($doc_a !== $returned_id, 'the corrected version is a new submission');
e2e_same('draft', get_post_status($returned_id), 'the returned submission stays Returned');
e2e_same(array($returned_id), array_map('intval', (array) get_post_meta($doc_a, '_glover_possible_duplicates', true)), 'it is flagged as a possible duplicate of the returned one (same board, category and date)');
e2e_expect_fields($doc_a, array('title' => 'E2E Test Meeting Minutes', 'date' => '20260917', 'minutes' => 'draft', 'category' => 'minutes', 'topics' => array('roads-bridges'), 'note' => 'Test note: corrected file.'));
$a_file = glover_document_library_file_id($doc_a);
e2e_expect_file($a_file, true, $names['draft'], 'the corrected file', true);
e2e_expect_log($doc_a, array('submitted'));
e2e_expect_mail(array(
    array('to' => $email($approver), 'subject' => count(get_posts(array('post_type' => GLOVER_DOCUMENT_LIBRARY_POST_TYPE, 'post_status' => 'pending', 'fields' => 'ids', 'posts_per_page' => -1))) . ' documents waiting for review', 'contains' => array('E2E Test Meeting Minutes', 'Possible duplicate', 'Draft minutes')),
));

e2e_step('A4', $scenario, 'Approve and publish');
$response = $approve($doc_a);
e2e_check($redirected_with($response, 'approved'), 'Approve goes back to the Documents screen with "approved"', e2e_page_text($response));
e2e_same('publish', get_post_status($doc_a), 'status is Published');
$slug_a = get_post_field('post_name', $doc_a);
e2e_check(strpos($slug_a, 'e2e-test-meeting-minutes') === 0, 'it gets an address from its title', $slug_a);
e2e_check(abs(time() - (int) get_post_meta($doc_a, '_glover_published_at', true)) < 300, 'the approval time is recorded');
e2e_expect_fields($doc_a, array('title' => 'E2E Test Meeting Minutes', 'date' => '20260917', 'minutes' => 'draft', 'category' => 'minutes', 'topics' => array('roads-bridges')));
e2e_expect_file($a_file, false, $names['draft'], 'the file');
$a_file_name = basename((string) get_attached_file($a_file));
$response = e2e_http('GET', get_permalink($doc_a));
e2e_check($response['status'] === 302 && $response['location'] === wp_get_attachment_url($a_file), 'its address redirects to the file', $response['status'] . ' ' . $response['location']);
e2e_expect_log($doc_a, array('submitted', 'approved', 'published', 'backed up to Drive'));
e2e_check(strpos(e2e_history_html($doc_a), 'Approved') !== false, 'the History box shows Approved');
e2e_expect_drive($doc_a, $a_file, $names['draft'], 1);
e2e_run_index($doc_a);
e2e_same('indexed', get_post_meta($doc_a, '_glover_text_index_status', true), 'search status is Indexed');
e2e_check(array_unique(array_column(e2e_index_rows($doc_a), 'attachment_id')) === array((string) $a_file), 'search rows come from the current file', e2e_index_rows($doc_a));
e2e_expect_search('Marigoldtest', $doc_a, true);
e2e_expect_search('Larkspurtest', $doc_a, false);
e2e_expect_mail(array(
    array('to' => $email($submitter_a), 'subject' => 'Published: E2E Test Meeting Minutes', 'contains' => array('Your document is on the website', 'Draft minutes', 'submit the approved version', get_permalink($doc_a))),
));

// ======================================================= B. Replacement

$scenario = 'Replacement';
e2e_step('B1', $scenario, 'Another submitter submits the approved minutes as a replacement');
$b1 = e2e_submit($submitter_b, array(
    'title' => 'E2E Test Meeting Minutes',
    'minutes' => 'approved',
    'category' => 'minutes',
    'topics' => array('roads-bridges', 'property-taxes'),
    'date' => '2026-09-17',
    'replaces' => $doc_a,
    'lines' => array('E2E Test Meeting Minutes', 'Town of Glover, Vermont', 'Peonytest culvert discussion, approved by the board.'),
));
$rep_b = $b1['id'];
e2e_expect_submitted($b1, $submitter_b);
e2e_same($doc_a, (int) get_field('document_replaces', $rep_b, false), 'it records the document it replaces');
e2e_same(array($rep_b), glover_document_library_pending_groups()['groups'][$doc_a] ?? array(), 'the Pending tab groups it under the published document');
$v2_file = glover_document_library_file_id($rep_b);
e2e_expect_file($v2_file, true, $names['approved'], 'the replacement file');
$response = e2e_http('GET', glover_document_library_review_screen_url('pending'), $approver_session);
e2e_check($response['status'] === 200 && strpos($response['body'], 'compare=' . $doc_a) !== false, 'the Documents screen offers Compare for it', e2e_page_text($response));
e2e_same('publish', get_post_status($doc_a), 'the published document is unchanged until approval');
e2e_expect_mail(array(
    array('to' => $email($approver), 'subject' => count(get_posts(array('post_type' => GLOVER_DOCUMENT_LIBRARY_POST_TYPE, 'post_status' => 'pending', 'fields' => 'ids', 'posts_per_page' => -1))) . ' documents waiting for review', 'contains' => array('Updated version', 'Test Submitter B')),
));

e2e_step('B2', $scenario, 'Compare: keep the current topics, edit the title, approve');
$response = $compare($doc_a, array(
    'decision' => 'approve',
    'replacement' => $rep_b,
    'keep' => array('topics' => 'current'),
    'title' => 'E2E Test Regular Meeting Minutes',
));
e2e_check($redirected_with($response, 'replacement_approved'), 'approving goes back with "replacement_approved"', e2e_page_text($response));
e2e_same('publish', get_post_status($doc_a), 'the document is still Published');
e2e_same($slug_a, get_post_field('post_name', $doc_a), 'its address is unchanged');
e2e_expect_fields($doc_a, array('title' => 'E2E Test Regular Meeting Minutes', 'date' => '20260917', 'minutes' => 'approved', 'category' => 'minutes', 'topics' => array('roads-bridges')));
e2e_same($v2_file, glover_document_library_file_id($doc_a), 'it now holds the new file');
e2e_expect_file($v2_file, false, $names['v2'], 'the new file');
// Back in the private folder the returned copy from A2 has the same name, so
// the previous version may take "-1" there.
e2e_expect_file($a_file, true, $a_file_name, 'the previous file (keeping the name it had)', true);
$response = e2e_http('GET', get_permalink($doc_a));
e2e_check($response['status'] === 302 && $response['location'] === wp_get_attachment_url($v2_file), 'its address now redirects to the new file', $response['status'] . ' ' . $response['location']);
$history = glover_document_library_meta_list($doc_a, '_glover_previous_files');
e2e_same(1, count($history), 'Previous versions has one entry');
e2e_check(($history[0]['attachment'] ?? 0) === $a_file && ($history[0]['replacement'] ?? 0) === $rep_b && ($history[0]['new_attachment'] ?? 0) === $v2_file && ($history[0]['approved_by'] ?? 0) === $approver, 'it records the old file, the replacement, the new file and the approver', $history[0] ?? null);
e2e_same('E2E Test Meeting Minutes', $history[0]['snapshot']['title'] ?? '', 'it keeps a snapshot of the old details for undo');
e2e_same(200, $stream($doc_a, $approver, $a_file)['status'], 'the approver can still open the previous version');
e2e_same('trash', get_post_status($rep_b), 'the replacement submission is retired to the Trash');
e2e_same($doc_a, (int) get_post_meta($rep_b, '_glover_merged_into', true), 'it records the document it merged into');
e2e_same(0, glover_document_library_file_id($rep_b), 'it no longer owns the file');
e2e_expect_log($rep_b, array('submitted', 'approved', 'merged'), 'The replacement\'s History');
e2e_check(strpos(implode(' ', array_column(glover_document_library_meta_list($rep_b, '_glover_audit_log'), 'note')), 'Kept current: topics') !== false, 'the approval notes the kept topics');
e2e_expect_log($doc_a, array('approved', 'published', 'replaced', 'backed up to Drive'));
$html = e2e_history_html($doc_a);
e2e_check(strpos($html, 'Previous versions') !== false && strpos($html, 'New version approved') !== false, 'the History box lists the previous version and "New version approved"');
e2e_expect_drive($doc_a, $v2_file, $names['v2'], 2);
e2e_run_index($doc_a);
e2e_same('indexed', get_post_meta($doc_a, '_glover_text_index_status', true), 'search status is Indexed');
e2e_check(array_unique(array_column(e2e_index_rows($doc_a), 'attachment_id')) === array((string) $v2_file), 'search rows come from the new file', e2e_index_rows($doc_a));
e2e_same(array(), e2e_index_rows($rep_b), 'the retired submission has no search rows');
e2e_expect_search('Peonytest', $doc_a, true);
e2e_expect_search('Marigoldtest', $doc_a, false);
e2e_expect_mail(array(
    array('to' => $email($submitter_b), 'subject' => 'Update published: E2E Test Regular Meeting Minutes', 'contains' => array('Your updated version is on the website', 'Approved by the board', get_permalink($doc_a))),
));

// ============================================ C. Several replacements

$scenario = 'Several replacements';
e2e_step('C1', $scenario, 'Two submitters each submit a replacement');
$c_a = e2e_submit($submitter_a, array(
    'title' => 'E2E Test Regular Meeting Minutes',
    'minutes' => 'approved',
    'category' => 'minutes',
    'topics' => array('roads-bridges'),
    'date' => '2026-09-17',
    'replaces' => $doc_a,
    'lines' => array('E2E Test Regular Meeting Minutes', 'Town of Glover, Vermont', 'Tulipwordtest culvert discussion, corrected page numbers.'),
));
$c_b = e2e_submit($submitter_b, array(
    'title' => 'E2E Test Regular Meeting Minutes',
    'minutes' => 'approved',
    'category' => 'minutes',
    'topics' => array('roads-bridges'),
    'date' => '2026-09-17',
    'replaces' => $doc_a,
    'lines' => array('E2E Test Regular Meeting Minutes', 'Town of Glover, Vermont', 'Asterwordtest culvert discussion, a second correction.'),
));
$rep_ca = $c_a['id'];
$rep_cb = $c_b['id'];
e2e_expect_submitted($c_a, $submitter_a);
e2e_expect_submitted($c_b, $submitter_b);
e2e_same(array($rep_ca, $rep_cb), glover_document_library_pending_groups()['groups'][$doc_a] ?? array(), 'both are grouped under the published document, oldest first');
$response = e2e_http('GET', glover_document_library_review_screen_url('pending'), $approver_session);
e2e_check(strpos($response['body'], '2 replacements') !== false, 'the Documents screen says "2 replacements"', e2e_page_text($response));
$v3_file = glover_document_library_file_id($rep_ca);
$cb_file = glover_document_library_file_id($rep_cb);
$pending_count = count(get_posts(array('post_type' => GLOVER_DOCUMENT_LIBRARY_POST_TYPE, 'post_status' => 'pending', 'fields' => 'ids', 'posts_per_page' => -1)));
e2e_expect_mail(array(
    array('to' => $email($approver), 'subject' => ($pending_count - 1) . ' documents waiting for review', 'contains' => array('Test Submitter A')),
    array('to' => $email($approver), 'subject' => $pending_count . ' documents waiting for review', 'contains' => array('Test Submitter B')),
));

e2e_step('C2', $scenario, 'Approving one without a reason for the other is refused');
$response = $compare($doc_a, array('decision' => 'approve', 'replacement' => $rep_ca));
e2e_check($response['status'] >= 400 && strpos($response['body'], 'reason for returning the other replacements') !== false, 'it is refused and asks for a reason', e2e_page_text($response));
e2e_same(array('pending', 'pending'), array(get_post_status($rep_ca), get_post_status($rep_cb)), 'both are still Pending');
e2e_same($v2_file, glover_document_library_file_id($doc_a), 'the published file is unchanged');
e2e_expect_mail(array());

e2e_step('C3', $scenario, 'Approve one, returning the other with a reason');
$others_reason = 'Test reason: Test Submitter A\'s correction was approved instead.';
$response = $compare($doc_a, array('decision' => 'approve', 'replacement' => $rep_ca, 'others_reason' => $others_reason));
e2e_check($redirected_with($response, 'replacement_approved'), 'approving goes back with "replacement_approved"', e2e_page_text($response));
e2e_same($v3_file, glover_document_library_file_id($doc_a), 'the document holds the approved replacement\'s file');
e2e_expect_file($v3_file, false, $names['v3'], 'the new file');
e2e_expect_file($v2_file, true, $names['v2'], 'the previous file');
e2e_same(2, count(glover_document_library_meta_list($doc_a, '_glover_previous_files')), 'Previous versions has two entries');
e2e_same('trash', get_post_status($rep_ca), 'the approved replacement is retired');
e2e_same('draft', get_post_status($rep_cb), 'the other replacement is Returned');
e2e_same($others_reason, get_post_meta($rep_cb, '_glover_returned_reason', true), 'it carries the reason');
e2e_expect_log($rep_cb, array('submitted', 'returned'), 'The returned replacement\'s History');
e2e_expect_file($cb_file, true, 'select-board_minutes_2026-09-17_e2e-test-regular-meeting-minutes.pdf', 'the returned replacement\'s file', true);
e2e_same(array(), glover_document_library_pending_groups()['groups'][$doc_a] ?? array(), 'nothing is left pending for the document');
e2e_expect_drive($doc_a, $v3_file, $names['v3'], 3);
e2e_run_index($doc_a);
e2e_expect_search('Tulipwordtest', $doc_a, true);
e2e_expect_search('Asterwordtest', $doc_a, false);
e2e_expect_search('Peonytest', $doc_a, false);
e2e_expect_mail(array(
    array('to' => $email($submitter_b), 'subject' => 'Needs changes: E2E Test Regular Meeting Minutes', 'contains' => array($others_reason)),
    array('to' => $email($submitter_a), 'subject' => 'Update published: E2E Test Regular Meeting Minutes', 'contains' => array('Your updated version is on the website')),
));

// ================================================ D. Undo replacement

$scenario = 'Undo replacement';
e2e_step('D1', $scenario, 'Undo the last replacement');
e2e_check((bool) glover_document_library_undoable_replacement($doc_a), 'Undo replacement is offered');
$response = $undo($doc_a, 'replacement');
e2e_check($redirected_with($response, 'replacement_undone'), 'Undo goes back with "replacement_undone"', e2e_page_text($response));
e2e_same('publish', get_post_status($doc_a), 'the document stays Published');
e2e_same($slug_a, get_post_field('post_name', $doc_a), 'its address is unchanged');
e2e_same($v2_file, glover_document_library_file_id($doc_a), 'it has the previous file back');
e2e_expect_fields($doc_a, array('title' => 'E2E Test Regular Meeting Minutes', 'date' => '20260917', 'minutes' => 'approved', 'category' => 'minutes', 'topics' => array('roads-bridges')));
e2e_expect_file($v2_file, false, $names['v2'], 'the restored file');
e2e_expect_file($v3_file, true, $names['v3'], 'the undone file');
e2e_same(1, count(glover_document_library_meta_list($doc_a, '_glover_previous_files')), 'Previous versions is back to one entry');
e2e_same('e2e-drive-' . $v2_file, get_post_meta($doc_a, '_glover_drive_file_id', true), 'the Drive file ID points back at the restored file');
e2e_same('pending', get_post_status($rep_ca), 'the replacement is back in Pending');
e2e_same($v3_file, glover_document_library_file_id($rep_ca), 'with its own file');
e2e_same('', get_post_meta($rep_ca, '_glover_merged_into', true), 'and no longer marked as merged');
e2e_same(array($rep_ca), glover_document_library_pending_groups()['groups'][$doc_a] ?? array(), 'grouped under the document again');
e2e_expect_log($doc_a, array('replaced', 'replaced', 'replacement undone'));
e2e_expect_log($rep_ca, array('merged', 'back in pending'), 'The replacement\'s History');
e2e_run_index($doc_a);
e2e_check(array_unique(array_column(e2e_index_rows($doc_a), 'attachment_id')) === array((string) $v2_file), 'search rows come from the restored file', e2e_index_rows($doc_a));
e2e_expect_search('Peonytest', $doc_a, true);
e2e_expect_search('Tulipwordtest', $doc_a, false);
e2e_expect_mail(array());

e2e_step('D2', $scenario, 'Approve the replacement again');
$response = $compare($doc_a, array('decision' => 'approve', 'replacement' => $rep_ca));
e2e_check($redirected_with($response, 'replacement_approved'), 'approving goes back with "replacement_approved"', e2e_page_text($response));
e2e_same($v3_file, glover_document_library_file_id($doc_a), 'the document holds the replacement\'s file again');
e2e_expect_file($v3_file, false, $names['v3'], 'the new file');
e2e_same(2, count(glover_document_library_meta_list($doc_a, '_glover_previous_files')), 'Previous versions has two entries');
e2e_expect_drive($doc_a, $v3_file, $names['v3'], 4);
e2e_run_index($doc_a);
e2e_expect_search('Tulipwordtest', $doc_a, true);
e2e_expect_mail(array(
    array('to' => $email($submitter_a), 'subject' => 'Update published: E2E Test Regular Meeting Minutes'),
));

// ============================================ E. Return all replacements

$scenario = 'Return replacements';
e2e_step('E1', $scenario, 'Submit a replacement');
$e1 = e2e_submit($submitter_b, array(
    'title' => 'E2E Test Regular Meeting Minutes',
    'minutes' => 'approved',
    'category' => 'minutes',
    'topics' => array('roads-bridges'),
    'date' => '2026-09-17',
    'replaces' => $doc_a,
    'lines' => array('E2E Test Regular Meeting Minutes', 'Town of Glover, Vermont', 'Lilacwordtest an unneeded correction.'),
));
$rep_e = $e1['id'];
e2e_expect_submitted($e1, $submitter_b);
e2e_expect_mail(array(
    array('to' => $email($approver), 'subject' => count(get_posts(array('post_type' => GLOVER_DOCUMENT_LIBRARY_POST_TYPE, 'post_status' => 'pending', 'fields' => 'ids', 'posts_per_page' => -1))) . ' documents waiting for review', 'contains' => array('Updated version')),
));

e2e_step('E2', $scenario, 'Compare: Return to submitter');
$response = $compare($doc_a, array('decision' => 'return'));
e2e_check($response['status'] >= 400 && strpos($response['body'], 'reason for returning') !== false, 'returning without a reason is refused', e2e_page_text($response));
e2e_same('pending', get_post_status($rep_e), 'it stays Pending');
$e_reason = 'Test reason: the published version is already correct.';
$response = $compare($doc_a, array('decision' => 'return', 'reason' => $e_reason));
e2e_check($redirected_with($response, 'returned'), 'returning goes back with "returned"', e2e_page_text($response));
e2e_same('draft', get_post_status($rep_e), 'the replacement is Returned');
e2e_same($e_reason, get_post_meta($rep_e, '_glover_returned_reason', true), 'it carries the reason');
e2e_same($v3_file, glover_document_library_file_id($doc_a), 'the published document is unchanged');
e2e_expect_file(glover_document_library_file_id($rep_e), true, 'select-board_minutes_2026-09-17_e2e-test-regular-meeting-minutes.pdf', 'the returned file', true);
e2e_expect_search('Lilacwordtest', $doc_a, false);
e2e_expect_mail(array(
    array('to' => $email($submitter_b), 'subject' => 'Needs changes: E2E Test Regular Meeting Minutes', 'contains' => array($e_reason)),
));

// ============================================ F. Move back to Pending

$scenario = 'Move back to Pending';
e2e_step('F1', $scenario, 'Submit an agenda');
$f1 = e2e_submit($submitter_a, array(
    'title' => 'E2E Test Special Meeting Agenda',
    'category' => 'agendas',
    'topics' => array(),
    'date' => '2026-09-30',
    'lines' => array('E2E Test Special Meeting Agenda', 'Town of Glover, Vermont', 'Snapdragontest call to order and public comment.'),
));
$doc_f = $f1['id'];
e2e_expect_submitted($f1, $submitter_a);
$f_file = glover_document_library_file_id($doc_f);
e2e_expect_fields($doc_f, array('title' => 'E2E Test Special Meeting Agenda', 'date' => '20260930', 'minutes' => '', 'category' => 'agendas', 'topics' => array()));
e2e_expect_file($f_file, true, $names['agenda'], 'the file');
e2e_expect_mail(array(
    array('to' => $email($approver), 'subject' => count(get_posts(array('post_type' => GLOVER_DOCUMENT_LIBRARY_POST_TYPE, 'post_status' => 'pending', 'fields' => 'ids', 'posts_per_page' => -1))) . ' documents waiting for review', 'contains' => array('E2E Test Special Meeting Agenda')),
));

e2e_step('F2', $scenario, 'Approve');
$response = $approve($doc_f);
e2e_check($redirected_with($response, 'approved'), 'Approve goes back with "approved"', e2e_page_text($response));
e2e_same('publish', get_post_status($doc_f), 'status is Published');
$slug_f = get_post_field('post_name', $doc_f);
e2e_expect_file($f_file, false, $names['agenda'], 'the file');
e2e_expect_drive($doc_f, $f_file, $names['agenda'], 1, 'Agendas');
e2e_run_index($doc_f);
e2e_expect_search('Snapdragontest', $doc_f, true);
e2e_expect_mail(array(
    array('to' => $email($submitter_a), 'subject' => 'Published: E2E Test Special Meeting Agenda', 'contains' => array('Your document is on the website')),
));

e2e_step('F3', $scenario, 'Move back to Pending');
e2e_check(glover_document_library_can_move_back($doc_f), 'Move back to Pending is offered');
$response = $undo($doc_f, 'pending');
e2e_check($redirected_with($response, 'moved_back'), 'it goes back with "moved_back"', e2e_page_text($response));
e2e_same('pending', get_post_status($doc_f), 'status is Pending');
e2e_same($slug_f, get_post_field('post_name', $doc_f), 'it keeps its address');
e2e_same('', get_post_meta($doc_f, '_glover_published_at', true), 'the approval time is cleared');
e2e_expect_file($f_file, true, $names['agenda'], 'the file');
e2e_same(array(), e2e_index_rows($doc_f), 'its search rows are removed');
e2e_same('', get_post_meta($doc_f, '_glover_text_index_status', true), 'its search status is cleared');
e2e_expect_search('Snapdragontest', $doc_f, false);
e2e_same(404, e2e_http('GET', get_permalink($doc_f))['status'], 'its address is a 404 to the public');
e2e_expect_log($doc_f, array('approved', 'published', 'unpublished', 'moved back to pending'));
e2e_check(in_array($doc_f, glover_document_library_pending_groups()['new'], true), 'it is back on the Pending tab');
e2e_expect_mail(array());

e2e_step('F4', $scenario, 'Approve again');
$response = $approve($doc_f);
e2e_check($redirected_with($response, 'approved'), 'Approve goes back with "approved"', e2e_page_text($response));
e2e_same('publish', get_post_status($doc_f), 'status is Published');
e2e_same($slug_f, get_post_field('post_name', $doc_f), 'with the same address');
e2e_check(abs(time() - (int) get_post_meta($doc_f, '_glover_published_at', true)) < 300, 'the approval time is recorded again');
e2e_expect_file($f_file, false, $names['agenda'], 'the file');
e2e_expect_drive($doc_f, $f_file, $names['agenda'], 2, 'Agendas');
e2e_run_index($doc_f);
e2e_expect_search('Snapdragontest', $doc_f, true);
e2e_expect_mail(array(
    array('to' => $email($submitter_a), 'subject' => 'Published: E2E Test Special Meeting Agenda'),
));

// ================================================ G. Auto-approve

$scenario = 'Auto-approve';
e2e_step('G1', $scenario, 'An Auto-approve submitter submits');
$g1 = e2e_submit($auto, array(
    'title' => 'E2E Test Budget Hearing Agenda',
    'category' => 'agendas',
    'topics' => array('property-taxes'),
    'date' => '2026-10-05',
    'lines' => array('E2E Test Budget Hearing Agenda', 'Town of Glover, Vermont', 'Zinniatest budget hearing.'),
));
$doc_g = $g1['id'];
e2e_check($doc_g > 0, 'the form saves and returns to the thank-you page', e2e_page_text($g1['response']));
e2e_same('publish', get_post_status($doc_g), 'it publishes at once');
e2e_same('1', get_post_meta($doc_g, '_glover_auto_published', true), 'it is marked Published without review');
e2e_expect_log($doc_g, array('submitted', 'auto-approved', 'published'));
$g_file = glover_document_library_file_id($doc_g);
e2e_expect_file($g_file, false, $names['auto'], 'the file');
e2e_expect_drive($doc_g, $g_file, $names['auto'], 1, 'Agendas');
e2e_run_index($doc_g);
e2e_expect_search('Zinniatest', $doc_g, true);
e2e_expect_mail(array());

// ================================================ H. Batched emails

// Approvals and waiting-for-review emails wait for 10 quiet minutes (never
// more than 30 after the first). The suite doesn't wait: it checks what is
// scheduled and when, then runs the job as WP-Cron would when it is due.
$scenario = 'Batched emails';
e2e_quiet(null);
$approved_args = array($submitter_a);
$approved_queue = function () use ($submitter_a) {
    e2e_refresh();
    $queue = get_user_meta($submitter_a, GLOVER_DOCUMENT_LIBRARY_APPROVED_QUEUE, true);

    return is_array($queue) ? array_map('intval', array_keys((array) ($queue['items'] ?? array()))) : array();
};
$batch_doc = function ($user_id, $title, $date, $word, $replaces = 0) {
    return e2e_submit($user_id, array_filter(array(
        'title' => $title,
        'category' => 'agendas',
        'topics' => array(),
        'date' => $date,
        'replaces' => $replaces,
        'lines' => array($title, 'Town of Glover, Vermont', $word . ' batch test.'),
    )));
};

e2e_step('H1', $scenario, 'Five uploads in a row');
e2e_same(10 * MINUTE_IN_SECONDS, glover_document_library_notify_quiet_seconds(), 'the quiet period is 10 minutes');
e2e_same(30 * MINUTE_IN_SECONDS, glover_document_library_notify_max_seconds(), 'the longest wait is 30 minutes');
$h_start = time();
$h_a = $batch_doc($submitter_a, 'E2E Test Batch Agenda One', '2026-10-06', 'Primrosetest')['id'];
$h_b = $batch_doc($submitter_a, 'E2E Test Batch Agenda Two', '2026-10-07', 'Foxglovetest')['id'];
$h_d = $batch_doc($submitter_a, 'E2E Test Batch Agenda Three', '2026-10-08', 'Hollyhocktest')['id'];
$h_r = $batch_doc($submitter_a, 'E2E Test Special Meeting Agenda', '2026-09-30', 'Verbenatest', $doc_f)['id'];
$h_c = $batch_doc($submitter_b, 'E2E Test Batch Agenda Four', '2026-10-09', 'Yarrowtest')['id'];
$h_last = time();
e2e_check($h_a && $h_b && $h_d && $h_r && $h_c, 'all five save', array($h_a, $h_b, $h_d, $h_r, $h_c));
e2e_same(array('pending'), array_values(array_unique(array_map('get_post_status', array($h_a, $h_b, $h_d, $h_r, $h_c)))), 'all five are Pending');
$events = e2e_events(GLOVER_DOCUMENT_LIBRARY_PENDING_HOOK);
e2e_same(1, count($events), 'one waiting-for-review email is scheduled');
e2e_check($events && $events[0] >= $h_last + 600 - 15 && $events[0] <= $h_last + 600, 'for 10 minutes after the last upload', $events ? ($events[0] - $h_last) . ' seconds after it' : null);
$first = (int) get_option(GLOVER_DOCUMENT_LIBRARY_PENDING_FIRST);
e2e_check($first >= $h_start - 1 && $first <= $h_last, 'the batch remembers when the first upload came in', $first - $h_start);
e2e_expect_mail(array());

e2e_step('H2', $scenario, 'The quiet period ends: one waiting-for-review email');
e2e_run_event(GLOVER_DOCUMENT_LIBRARY_PENDING_HOOK);
e2e_expect_mail(array(
    array('to' => $email($approver), 'subject' => count(get_posts(array('post_type' => GLOVER_DOCUMENT_LIBRARY_POST_TYPE, 'post_status' => 'pending', 'fields' => 'ids', 'posts_per_page' => -1))) . ' documents waiting for review', 'contains' => array('E2E Test Batch Agenda One', 'E2E Test Batch Agenda Two', 'E2E Test Batch Agenda Three', 'E2E Test Batch Agenda Four', 'E2E Test Special Meeting Agenda', 'Updated version')),
));
e2e_same(0, (int) get_option(GLOVER_DOCUMENT_LIBRARY_PENDING_FIRST), 'the batch is cleared');
e2e_same(array(), e2e_events(GLOVER_DOCUMENT_LIBRARY_PENDING_HOOK), 'nothing more is scheduled');

e2e_step('H3', $scenario, 'A return still goes at once');
$h_reason = 'Test reason: this agenda belongs to another meeting.';
$response = $return($h_c, $h_reason);
e2e_check($redirected_with($response, 'returned'), 'Return goes back with "returned"', e2e_page_text($response));
e2e_same(array(), e2e_events(GLOVER_DOCUMENT_LIBRARY_APPROVED_HOOK, array($submitter_b)), 'nothing is batched for it');
e2e_expect_mail(array(
    array('to' => $email($submitter_b), 'subject' => 'Needs changes: E2E Test Batch Agenda Four', 'contains' => array($h_reason)),
));

e2e_step('H4', $scenario, 'Four approvals for one submitter');
$previous = 0;

foreach (array($h_a, $h_b) as $id) {
    $response = $approve($id);
    e2e_check($redirected_with($response, 'approved'), 'Approve goes back with "approved"', e2e_page_text($response));
    $events = e2e_events(GLOVER_DOCUMENT_LIBRARY_APPROVED_HOOK, $approved_args);
    e2e_same(1, count($events), 'one approval email is scheduled for the submitter');
    e2e_check($events && $events[0] >= time() + 600 - 15 && $events[0] <= time() + 600 && $events[0] >= $previous, 'for 10 minutes after this approval', $events ? $events[0] - time() : null);
    $previous = $events ? $events[0] : 0;
}

$response = $compare($doc_f, array('decision' => 'approve', 'replacement' => $h_r));
e2e_check($redirected_with($response, 'replacement_approved'), 'approving the replacement goes back with "replacement_approved"', e2e_page_text($response));
e2e_same(1, count(e2e_events(GLOVER_DOCUMENT_LIBRARY_APPROVED_HOOK, $approved_args)), 'still one approval email scheduled');

// Pretend the batch began 29 minutes ago, so the next approval meets the cap.
$queue = get_user_meta($submitter_a, GLOVER_DOCUMENT_LIBRARY_APPROVED_QUEUE, true);
$queue['first'] = time() - 29 * MINUTE_IN_SECONDS;
update_user_meta($submitter_a, GLOVER_DOCUMENT_LIBRARY_APPROVED_QUEUE, $queue);
$response = $approve($h_d);
e2e_check($redirected_with($response, 'approved'), 'Approve goes back with "approved"', e2e_page_text($response));
$events = e2e_events(GLOVER_DOCUMENT_LIBRARY_APPROVED_HOOK, $approved_args);
e2e_same(1, count($events), 'still one approval email scheduled');
e2e_check($events && $events[0] <= $queue['first'] + 30 * MINUTE_IN_SECONDS && $events[0] < time() + 600, 'never later than 30 minutes after the first approval', $events ? $events[0] - $queue['first'] : null);
e2e_same(array($h_a, $h_b, $doc_f, $h_d), $approved_queue(), 'the batch holds the three new documents and the replaced one');
e2e_same(array('publish', 'publish', 'publish'), array_map('get_post_status', array($h_a, $h_b, $h_d)), 'all three new documents are Published');
e2e_same($doc_f, (int) get_post_meta($h_r, '_glover_merged_into', true), 'the replacement merged into its document');
e2e_expect_mail(array());

e2e_step('H5', $scenario, 'Undo replacement and Move back to Pending inside the window');
$response = $undo($doc_f, 'replacement');
e2e_check($redirected_with($response, 'replacement_undone'), 'Undo replacement goes back with "replacement_undone"', e2e_page_text($response));
e2e_same(array($h_a, $h_b, $h_d), $approved_queue(), 'Undo replacement takes that document out of the batch');
$response = $undo($h_b, 'pending');
e2e_check($redirected_with($response, 'moved_back'), 'Move back to Pending goes back with "moved_back"', e2e_page_text($response));
e2e_same('pending', get_post_status($h_b), 'the moved-back document is Pending');
e2e_same(1, count(e2e_events(GLOVER_DOCUMENT_LIBRARY_APPROVED_HOOK, $approved_args)), 'one approval email is still scheduled');
e2e_expect_mail(array());

e2e_step('H6', $scenario, 'The quiet period ends: one summary email');
e2e_run_event(GLOVER_DOCUMENT_LIBRARY_APPROVED_HOOK, $approved_args);
e2e_expect_mail(array(
    array(
        'to' => $email($submitter_a),
        'subject' => 'Published: 2 documents',
        'contains' => array('2 of your documents are on the website', 'E2E Test Batch Agenda One', 'E2E Test Batch Agenda Three', 'Open the Document Library'),
        'excludes' => array('E2E Test Batch Agenda Two', 'E2E Test Special Meeting Agenda'),
    ),
));
e2e_same(array(), $approved_queue(), 'the batch is cleared');
e2e_same(array(), e2e_events(GLOVER_DOCUMENT_LIBRARY_APPROVED_HOOK, $approved_args), 'nothing more is scheduled');

// ================================================================ cleanup

$GLOBALS['e2e']['step'] = 'Z';

if ($e2e_keep) {
    WP_CLI::log('');
    WP_CLI::warning('keep: the test content, accounts and guard were left in place. Run again without keep to clean up.');
} else {
    WP_CLI::log('');
    WP_CLI::log('Z     Cleanup');
    $mail = e2e_mail_all();
    $files = $GLOBALS['e2e']['files'];
    $users = $GLOBALS['e2e']['users'];
    $docs = e2e_cleanup();

    e2e_same(0, count(get_posts(array('post_type' => GLOVER_DOCUMENT_LIBRARY_POST_TYPE, 'post_status' => 'any', 'post__in' => $docs ? $docs : array(-1), 'fields' => 'ids'))) + count(get_posts(array('post_type' => GLOVER_DOCUMENT_LIBRARY_POST_TYPE, 'post_status' => 'trash', 'post__in' => $docs ? $docs : array(-1), 'fields' => 'ids'))), sprintf('all %d test documents are deleted', count($docs)));
    e2e_same(array(), array_values(array_filter($files, 'file_exists')), sprintf('all %d test files are deleted', count($files)));
    e2e_same(array(), array_values(array_filter(array_keys($files), 'get_post')), 'their attachments are deleted');
    e2e_same(array(), array_values(array_filter($users, 'get_userdata')), sprintf('all %d test accounts are deleted', count($users)));
    e2e_same(array(), array_values(array_filter(array_map('e2e_index_rows', $docs))), 'no search rows are left');
    e2e_same(0, array_sum(array_map('e2e_drive_jobs', $docs)), 'no Drive jobs are left');
    e2e_check(e2e_settings_rows() == $GLOBALS['e2e']['settings'], 'the Approvers and Uploaders settings are exactly as before');
    e2e_check($settings_before === array('approvers' => glover_document_library_approver_ids(), 'uploaders' => glover_document_library_setting('uploaders', array())), 'and read back the same');
    e2e_check(!file_exists(WPMU_PLUGIN_DIR . '/' . GLOVER_E2E_GUARD), 'the test guard is removed');

    WP_CLI::log('');
    WP_CLI::log(sprintf('Emails %s (%d), each only to %s:', $e2e_hold ? 'captured' : 'sent', count($mail), GLOVER_E2E_INBOX));

    foreach ($mail as $entry) {
        WP_CLI::log(sprintf('  %-4s %-7s to %s: %s', $entry['step'], $entry['result'], implode(', ', $entry['to']), $entry['subject']));
    }
}

/**
 * Every email captured in the run.
 *
 * @return array[]
 */
function e2e_mail_all()
{
    global $wpdb;

    return array_map('maybe_unserialize', $wpdb->get_col($wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id",
        $wpdb->esc_like(GLOVER_E2E_MAIL_PREFIX) . '%'
    )));
}

WP_CLI::log('');

if ($GLOBALS['e2e']['fail']) {
    WP_CLI::log(sprintf('%d of %d checks passed. Failed:', $GLOBALS['e2e']['pass'], $GLOBALS['e2e']['pass'] + $GLOBALS['e2e']['fail']));

    foreach ($GLOBALS['e2e']['failures'] as $failure) {
        WP_CLI::log('  ' . $failure);
    }

    WP_CLI::halt(1);
}

WP_CLI::success(sprintf('%d of %d checks passed.', $GLOBALS['e2e']['pass'], $GLOBALS['e2e']['pass']));

<?php
/**
 * Send all site email through the Gmail API, from webmaster@ (a group alias
 * on the configured sender mailbox). Takes over wp_mail() only when the mail
 * settings exist; otherwise WordPress sends mail its usual way.
 */

if (!defined('ABSPATH')) {
    exit;
}

const GLOVER_GOOGLE_GMAIL_SCOPE = 'https://www.googleapis.com/auth/gmail.send';

/**
 * Normalize a wp_mail address list (string or array) to clean addresses.
 *
 * @param string|string[] $list Addresses, possibly "Name <a@b.c>".
 * @return string[]
 */
function glover_google_mail_addresses($list)
{
    $items = is_array($list) ? $list : explode(',', (string) $list);
    $clean = array();

    foreach ($items as $item) {
        $item = trim($item);

        if ($item === '') {
            continue;
        }

        $address = preg_match('/<([^>]+)>$/', $item, $found) ? trim($found[1]) : $item;

        if (glover_google_mail_is_reserved($address)) {
            continue;
        }

        if (preg_match('/^(.*)<([^>]+)>$/', $item, $matches) && is_email(trim($matches[2]))) {
            $name = trim(trim($matches[1]), '"');
            $clean[] = $name !== '' ? glover_google_mail_encode_header($name) . ' <' . trim($matches[2]) . '>' : trim($matches[2]);
        } elseif (is_email($item)) {
            $clean[] = $item;
        }
    }

    return $clean;
}

/**
 * Whether an address is at a domain reserved for examples and testing
 * (RFC 2606 and RFC 6761), which can never receive mail. Test and sample
 * accounts use them; sending there only brings a bounce back to the sender
 * mailbox, so these addresses are dropped from every email.
 *
 * @param string $address Email address.
 * @return bool
 */
function glover_google_mail_is_reserved($address)
{
    $domain = strtolower((string) substr(strrchr(trim($address), '@'), 1));

    if ($domain === '') {
        return false;
    }

    return in_array($domain, array('example.com', 'example.net', 'example.org'), true)
        || (bool) preg_match('/(^|\.)(example\.(com|net|org)|example|test|invalid|localhost|local)$/', $domain);
}

/**
 * RFC 2047 encode a header value when it contains non-ASCII text.
 *
 * @param string $value Header text.
 */
function glover_google_mail_encode_header($value)
{
    $value = str_replace(array("\r", "\n"), ' ', $value);

    return preg_match('/[^\x20-\x7E]/', $value) ? '=?UTF-8?B?' . base64_encode($value) . '?=' : $value;
}

/**
 * A readable plain-text version of an HTML email: one block per line,
 * links as "text (address)", no markup.
 *
 * @param string $html Email HTML.
 */
function glover_google_mail_html_to_text($html)
{
    $html = preg_replace('#<(head|style|script)\b[^>]*>.*?</\1>#is', '', $html);
    // Source line breaks mean nothing in HTML; the tags below decide the lines.
    $html = preg_replace('/\s+/', ' ', $html);
    // Side-by-side cells (a label and its value) read as "Label: value".
    $html = preg_replace('#</td>\s*<td\b#i', '</td>: <td', $html);
    $html = preg_replace_callback('#<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', function ($match) {
        $text = trim(wp_strip_all_tags($match[2]));
        $url = html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');

        $url = preg_replace('#^mailto:#i', '', $url);

        return ($text === '' || $text === $url) ? $url : $text . ' (' . $url . ')';
    }, $html);
    $html = preg_replace('#<br\s*/?>#i', "\n", $html);
    $html = preg_replace('#</(p|div|h[1-6]|li|tr|table)>#i', "\n\n", $html);
    $text = html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES, 'UTF-8');
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/ *\n */', "\n", $text);

    return trim(preg_replace('/\n{3,}/', "\n\n", $text)) . "\n";
}

/**
 * Build the raw RFC 5322 message wp_mail() was asked to send.
 *
 * @param array $atts wp_mail() arguments: to, subject, message, headers, attachments.
 * @return string|WP_Error
 */
function glover_google_mail_build_message($atts)
{
    $to = glover_google_mail_addresses($atts['to']);

    if (!$to) {
        return new WP_Error('glover_google_mail_no_recipient', 'The email has no valid recipient.');
    }

    $content_type = 'text/plain';
    $cc = array();
    $bcc = array();
    $reply_to = array();
    $headers = $atts['headers'];
    $headers = is_array($headers) ? $headers : explode("\n", str_replace("\r\n", "\n", (string) $headers));

    foreach ($headers as $header) {
        if (strpos($header, ':') === false) {
            continue;
        }

        list($name, $value) = array_map('trim', explode(':', $header, 2));

        switch (strtolower($name)) {
            case 'content-type':
                $content_type = strtolower(trim(explode(';', $value)[0]));
                break;
            case 'cc':
                $cc = array_merge($cc, glover_google_mail_addresses($value));
                break;
            case 'bcc':
                $bcc = array_merge($bcc, glover_google_mail_addresses($value));
                break;
            case 'reply-to':
                $reply_to = array_merge($reply_to, glover_google_mail_addresses($value));
                break;
        }
    }

    // Respect the same filter WordPress applies to wp_mail().
    $content_type = apply_filters('wp_mail_content_type', $content_type);
    $content_type = in_array($content_type, array('text/plain', 'text/html'), true) ? $content_type : 'text/plain';

    $from_name = glover_google_setting('mail_from_name');
    $from = glover_google_setting('mail_from');
    $lines = array(
        'From: ' . ($from_name !== '' ? glover_google_mail_encode_header($from_name) . ' <' . $from . '>' : $from),
        'To: ' . implode(', ', $to),
        'Subject: ' . glover_google_mail_encode_header((string) $atts['subject']),
        'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
        'MIME-Version: 1.0',
    );

    if ($cc) {
        $lines[] = 'Cc: ' . implode(', ', $cc);
    }

    if ($bcc) {
        $lines[] = 'Bcc: ' . implode(', ', $bcc);
    }

    // Replies go to the group unless the sender asked otherwise.
    $lines[] = 'Reply-To: ' . ($reply_to ? implode(', ', $reply_to) : $from);

    $body_part = "Content-Type: {$content_type}; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode((string) $atts['message']));

    // HTML email also carries a plain-text copy for mail apps that show text only.
    if ($content_type === 'text/html') {
        $alternative = 'glover-alt-' . wp_generate_password(24, false);
        $body_part = "Content-Type: multipart/alternative; boundary=\"{$alternative}\"\r\n\r\n"
            . "--{$alternative}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode(glover_google_mail_html_to_text((string) $atts['message'])))
            . "\r\n--{$alternative}\r\n" . $body_part
            . "\r\n--{$alternative}--\r\n";
    }
    $attachments = array_filter((array) $atts['attachments'], 'is_readable');

    if (!$attachments) {
        return implode("\r\n", $lines) . "\r\n" . $body_part;
    }

    $boundary = 'glover-' . wp_generate_password(24, false);
    $lines[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
    $message = implode("\r\n", $lines) . "\r\n\r\n--{$boundary}\r\n" . $body_part;

    foreach ($attachments as $path) {
        $type = wp_check_filetype($path);
        $name = glover_google_mail_encode_header(basename($path));
        $message .= "\r\n--{$boundary}\r\n"
            . 'Content-Type: ' . ($type['type'] ? $type['type'] : 'application/octet-stream') . '; name="' . $name . "\"\r\n"
            . 'Content-Disposition: attachment; filename="' . $name . "\"\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode((string) file_get_contents($path)));
    }

    return $message . "\r\n--{$boundary}--\r\n";
}

/**
 * Send one email through the Gmail API.
 *
 * @param array $atts wp_mail() arguments.
 * @return true|WP_Error
 */
function glover_google_send_mail($atts)
{
    $raw = glover_google_mail_build_message($atts);

    if (is_wp_error($raw)) {
        return $raw;
    }

    $token = glover_google_access_token(GLOVER_GOOGLE_GMAIL_SCOPE, glover_google_setting('mail_sender'));

    if (is_wp_error($token)) {
        return $token;
    }

    $result = glover_google_request(
        'POST',
        'https://gmail.googleapis.com/gmail/v1/users/me/messages/send',
        $token,
        array('json' => array('raw' => glover_google_base64url($raw)))
    );

    return is_wp_error($result) ? $result : true;
}

/**
 * Short-circuit wp_mail() when Gmail is configured.
 *
 * @param null|bool $return Null to let WordPress send.
 * @param array     $atts   wp_mail() arguments.
 * @return null|bool
 */
function glover_google_pre_wp_mail($return, $atts)
{
    if ($return !== null || !glover_google_is_configured('mail')) {
        return $return;
    }

    // Nothing to send when every recipient is at a reserved test domain.
    if (!glover_google_mail_addresses($atts['to'])) {
        error_log('Glover Google mail skipped: no deliverable recipient (reserved test domains are never sent to). Subject: ' . $atts['subject']);

        return false;
    }

    $result = glover_google_send_mail($atts);

    if (is_wp_error($result)) {
        error_log('Glover Google mail failed: ' . $result->get_error_message());
        do_action('wp_mail_failed', new WP_Error('wp_mail_failed', $result->get_error_message(), $atts));

        return false;
    }

    do_action('wp_mail_succeeded', $atts);

    return true;
}
add_filter('pre_wp_mail', 'glover_google_pre_wp_mail', 10, 2);

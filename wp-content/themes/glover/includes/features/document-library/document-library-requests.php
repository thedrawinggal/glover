<?php

if (!defined('ABSPATH')) {
    exit;
}

const GLOVER_DOCUMENT_LIBRARY_REQUEST_FORMATS = array(
    'accessible_pdf' => 'An accessible PDF',
    'word' => 'A Word document',
    'plain_text' => 'Plain text or an email',
    'large_print' => 'Large print on paper',
    'other' => 'Something else (describe below)',
);

/**
 * Check a Cloudflare Turnstile token when a secret key is configured.
 *
 * @param string $token Token posted by the widget.
 */
function glover_document_library_turnstile_passes($token)
{
    $secret = glover_document_library_setting('turnstile_secret_key');

    if (!$secret) {
        return true;
    }

    $response = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', array(
        'timeout' => 10,
        'body' => array(
            'secret' => $secret,
            'response' => $token,
            'remoteip' => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '',
        ),
    ));

    if (is_wp_error($response)) {
        return false;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    return !empty($body['success']);
}

/**
 * Handle an accessible-copy request from the public form. The request is
 * saved in wp-admin first, so it is never lost if email fails, then emailed.
 */
function glover_document_library_handle_request()
{
    $back = isset($_POST['return_to']) ? esc_url_raw(wp_unslash($_POST['return_to'])) : home_url('/');
    $back = wp_validate_redirect($back, home_url('/'));
    $fail = function ($code) use ($back) {
        wp_safe_redirect(add_query_arg('request_error', $code, $back) . '#glover-request-form');
        exit;
    };

    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'glover_document_request')) {
        $fail('expired');
    }

    // Honeypot: people never see or fill this field.
    if (!empty($_POST['website'])) {
        wp_safe_redirect(add_query_arg('request_sent', 1, $back));
        exit;
    }

    $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
    $rate_key = 'glover_doc_request_' . md5($ip);
    $count = (int) get_transient($rate_key);

    if ($count >= 5) {
        $fail('too_many');
    }

    $token = isset($_POST['cf-turnstile-response']) ? sanitize_text_field(wp_unslash($_POST['cf-turnstile-response'])) : '';

    if (!glover_document_library_turnstile_passes($token)) {
        $fail('check');
    }

    $name = isset($_POST['request_name']) ? sanitize_text_field(wp_unslash($_POST['request_name'])) : '';
    $email = isset($_POST['request_email']) ? sanitize_email(wp_unslash($_POST['request_email'])) : '';
    $phone = isset($_POST['request_phone']) ? sanitize_text_field(wp_unslash($_POST['request_phone'])) : '';
    $document_id = isset($_POST['request_document']) ? absint($_POST['request_document']) : 0;
    $format = isset($_POST['request_format']) ? sanitize_key($_POST['request_format']) : '';
    $details = isset($_POST['request_details']) ? sanitize_textarea_field(wp_unslash($_POST['request_details'])) : '';

    if ($name === '' || !is_email($email) || !isset(GLOVER_DOCUMENT_LIBRARY_REQUEST_FORMATS[$format])) {
        $fail('missing');
    }

    if ($document_id && (get_post_type($document_id) !== GLOVER_DOCUMENT_LIBRARY_POST_TYPE || get_post_status($document_id) !== 'publish')) {
        $document_id = 0;
    }

    $typed_title = isset($_POST['request_document_title']) ? sanitize_text_field(wp_unslash($_POST['request_document_title'])) : '';
    $document_title = $document_id ? get_the_title($document_id) : ($typed_title !== '' ? $typed_title : 'Not specified');
    $lines = array(
        'Name: ' . $name,
        'Email: ' . $email,
        'Phone: ' . ($phone !== '' ? $phone : 'Not given'),
        'Document: ' . $document_title . ($document_id ? ' (' . get_permalink($document_id) . ')' : ''),
        'Format wanted: ' . GLOVER_DOCUMENT_LIBRARY_REQUEST_FORMATS[$format],
        'Details: ' . ($details !== '' ? $details : 'None'),
    );

    $request_id = wp_insert_post(array(
        'post_type' => GLOVER_DOCUMENT_LIBRARY_REQUEST_POST_TYPE,
        'post_status' => 'private',
        'post_title' => sprintf('%s: %s', $name, $document_title),
        'post_content' => implode("\n\n", $lines),
    ));

    if (is_wp_error($request_id) || !$request_id) {
        $fail('save');
    }

    update_post_meta($request_id, '_glover_request_document', $document_id);
    set_transient($rate_key, $count + 1, HOUR_IN_SECONDS);

    $contact = glover_document_library_help_contact();
    $to = $contact['email'] ? array($contact['email']) : glover_document_library_approver_emails();
    glover_document_library_mail($to, 'Accessible copy requested: ' . $document_title, array(
        'heading' => 'Someone needs an accessible copy',
        'intro' => sprintf('Reply to this email to reach %s directly.', $name),
        'card' => array(
            'title' => $document_title,
            'url' => $document_id ? get_permalink($document_id) : '',
            'rows' => array(
                array('label' => 'Format wanted', 'value' => GLOVER_DOCUMENT_LIBRARY_REQUEST_FORMATS[$format], 'strong' => true),
                array('label' => 'Name', 'value' => $name),
                array('label' => 'Email', 'value' => $email, 'url' => 'mailto:' . $email),
                array('label' => 'Phone', 'value' => $phone !== '' ? $phone : 'Not given'),
                array('label' => 'Requested', 'value' => wp_date('M j, Y, g:i a')),
            ),
        ),
        'note' => array('label' => 'Details', 'text' => $details),
        'button' => array('label' => 'Open the request', 'url' => admin_url('post.php?post=' . (int) $request_id . '&action=edit')),
        'footer' => $contact['email'] ? "You're the help contact for the Town of Glover Document Library." : "You're an approver for the Town of Glover Document Library.",
    ), array('Reply-To: ' . $name . ' <' . $email . '>'));

    wp_safe_redirect(add_query_arg('request_sent', 1, remove_query_arg('request_error', $back)) . '#glover-request-status');
    exit;
}
add_action('admin_post_nopriv_glover_document_request', 'glover_document_library_handle_request');
add_action('admin_post_glover_document_request', 'glover_document_library_handle_request');

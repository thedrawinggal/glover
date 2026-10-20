<?php
/**
 * Shared Google client: settings, service-account access tokens, and a small
 * request helper. Plain HTTPS through the WordPress HTTP API; no libraries.
 */

if (!defined('ABSPATH')) {
    exit;
}

const GLOVER_GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';
const GLOVER_GOOGLE_DRIVE_SCOPE = 'https://www.googleapis.com/auth/drive';

/**
 * A GLOVER_GOOGLE_* constant from wp-config.php, or ''.
 *
 * @param string $name Constant suffix, e.g. 'mail_sender'.
 */
function glover_google_setting($name)
{
    $constant = 'GLOVER_GOOGLE_' . strtoupper($name);

    return defined($constant) ? (string) constant($constant) : '';
}

/**
 * The service account key, read once per request.
 *
 * @return array|WP_Error Decoded key with client_email and private_key.
 */
function glover_google_service_account()
{
    static $account = null;

    if ($account !== null) {
        return $account;
    }

    $file = glover_google_setting('service_account_key_file');

    if ($file === '') {
        return $account = new WP_Error('glover_google_no_key', 'GLOVER_GOOGLE_SERVICE_ACCOUNT_KEY_FILE is not set.');
    }

    if (!is_readable($file)) {
        return $account = new WP_Error('glover_google_key_unreadable', 'The service account key file can\'t be read: ' . $file);
    }

    $data = json_decode((string) file_get_contents($file), true);

    if (!is_array($data) || empty($data['client_email']) || empty($data['private_key']) || ($data['type'] ?? '') !== 'service_account') {
        return $account = new WP_Error('glover_google_key_invalid', 'The service account key file is not a valid JSON key.');
    }

    return $account = $data;
}

/**
 * Whether a part of the integration has everything it needs.
 *
 * @param string $part mail, drive or sign_in.
 */
function glover_google_is_configured($part)
{
    switch ($part) {
        case 'mail':
            return !is_wp_error(glover_google_service_account())
                && is_email(glover_google_setting('mail_sender'))
                && is_email(glover_google_setting('mail_from'));
        case 'drive':
            return !is_wp_error(glover_google_service_account()) && glover_google_setting('drive_id') !== '';
        case 'sign_in':
            return glover_google_setting('oauth_client_id') !== '' && glover_google_setting('oauth_client_secret') !== '';
    }

    return false;
}

/**
 * URL-safe base64 without padding, as JWTs and the Gmail API expect.
 *
 * @param string $data Raw bytes.
 */
function glover_google_base64url($data)
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * An OAuth access token for the service account. With $subject the token acts
 * as that Workspace user (domain-wide delegation, used for Gmail); without it
 * the service account acts as itself (used for Drive). Cached until shortly
 * before it expires.
 *
 * @param string $scope   Space-separated OAuth scopes.
 * @param string $subject Optional Workspace user to act as.
 * @return string|WP_Error
 */
function glover_google_access_token($scope, $subject = '')
{
    $account = glover_google_service_account();

    if (is_wp_error($account)) {
        return $account;
    }

    $cache_key = 'glover_google_token_' . md5($account['client_email'] . '|' . $scope . '|' . $subject);
    $cached = get_transient($cache_key);

    if (is_string($cached) && $cached !== '') {
        return $cached;
    }

    $now = time();
    $claims = array(
        'iss' => $account['client_email'],
        'scope' => $scope,
        'aud' => GLOVER_GOOGLE_TOKEN_URL,
        'iat' => $now,
        'exp' => $now + 3600,
    );

    if ($subject !== '') {
        $claims['sub'] = $subject;
    }

    $unsigned = glover_google_base64url(wp_json_encode(array('alg' => 'RS256', 'typ' => 'JWT')))
        . '.' . glover_google_base64url(wp_json_encode($claims));
    $signature = '';

    if (!openssl_sign($unsigned, $signature, $account['private_key'], OPENSSL_ALGO_SHA256)) {
        return new WP_Error('glover_google_sign_failed', 'Could not sign the token request with the service account key.');
    }

    $response = wp_remote_post(GLOVER_GOOGLE_TOKEN_URL, array(
        'timeout' => 15,
        'body' => array(
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $unsigned . '.' . glover_google_base64url($signature),
        ),
    ));
    $body = glover_google_decode_response($response);

    if (is_wp_error($body)) {
        return $body;
    }

    if (empty($body['access_token'])) {
        return new WP_Error('glover_google_no_token', 'Google did not return an access token.');
    }

    $lifetime = isset($body['expires_in']) ? (int) $body['expires_in'] : 3600;
    set_transient($cache_key, $body['access_token'], max(60, $lifetime - 120));

    return $body['access_token'];
}

/**
 * Turn an HTTP response into decoded JSON, or a WP_Error carrying Google's
 * own error message.
 *
 * @param array|WP_Error $response wp_remote_* result.
 * @return array|WP_Error
 */
function glover_google_decode_response($response)
{
    if (is_wp_error($response)) {
        return $response;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $body = json_decode((string) wp_remote_retrieve_body($response), true);

    if ($code >= 200 && $code < 300) {
        return is_array($body) ? $body : array();
    }

    $message = 'Google returned HTTP ' . $code;

    if (is_array($body)) {
        if (!empty($body['error']['message'])) {
            $message .= ': ' . $body['error']['message'];
        } elseif (!empty($body['error_description'])) {
            $message .= ': ' . $body['error_description'];
        } elseif (!empty($body['error']) && is_string($body['error'])) {
            $message .= ': ' . $body['error'];
        }
    }

    return new WP_Error('glover_google_http_' . $code, $message, array('status' => $code));
}

/**
 * Call a Google JSON API with a bearer token.
 *
 * @param string $method HTTP method.
 * @param string $url    Full URL.
 * @param string $token  Access token.
 * @param array  $args   Extra wp_remote_request() args; 'json' is encoded as the body.
 * @return array|WP_Error
 */
function glover_google_request($method, $url, $token, $args = array())
{
    $headers = array('Authorization' => 'Bearer ' . $token);

    if (array_key_exists('json', $args)) {
        $headers['Content-Type'] = 'application/json; charset=UTF-8';
        $args['body'] = wp_json_encode($args['json']);
        unset($args['json']);
    }

    $args['headers'] = array_merge($headers, isset($args['headers']) ? $args['headers'] : array());
    $args['method'] = $method;
    $args['timeout'] = isset($args['timeout']) ? $args['timeout'] : 30;

    return glover_google_decode_response(wp_remote_request($url, $args));
}

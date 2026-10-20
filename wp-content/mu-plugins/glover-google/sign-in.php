<?php
/**
 * Passwordless sign-in at /sign-in/: "Sign in with Google" for Workspace
 * accounts, and a 6-digit code emailed to anyone else. Both sign in only
 * existing WordPress users who have a role; removing someone's role locks
 * them out of both. wp-login.php stays available as the admins' way in.
 */

if (!defined('ABSPATH')) {
    exit;
}

const GLOVER_GOOGLE_SIGN_IN_VERSION = '1';
const GLOVER_GOOGLE_CODE_LIFETIME = 600;          // 10 minutes
const GLOVER_GOOGLE_CODE_MAX_ATTEMPTS = 5;
const GLOVER_GOOGLE_CODE_LOCKOUT = 900;           // 15 minutes
const GLOVER_GOOGLE_SESSION_DAYS = 30;
const GLOVER_GOOGLE_SIGN_IN_COOKIE = 'glover_sign_in';

/* -------------------------------------------------------------------------
 * Routes
 * ---------------------------------------------------------------------- */

/**
 * /sign-in/, /sign-in/google/ and /sign-in/google/callback/.
 */
function glover_google_sign_in_rewrites()
{
    add_rewrite_rule('^sign-in/?$', 'index.php?glover_sign_in=form', 'top');
    add_rewrite_rule('^sign-in/google/?$', 'index.php?glover_sign_in=google', 'top');
    add_rewrite_rule('^sign-in/google/callback/?$', 'index.php?glover_sign_in=callback', 'top');

    if (get_option('glover_google_sign_in_version') !== GLOVER_GOOGLE_SIGN_IN_VERSION) {
        flush_rewrite_rules(false);
        update_option('glover_google_sign_in_version', GLOVER_GOOGLE_SIGN_IN_VERSION);
    }
}
add_action('init', 'glover_google_sign_in_rewrites');

add_filter('query_vars', function ($vars) {
    $vars[] = 'glover_sign_in';
    return $vars;
});

/**
 * The sign-in page, returning to $redirect afterwards.
 *
 * @param string $redirect Where to go after signing in.
 */
function glover_google_sign_in_url($redirect = '')
{
    $url = home_url('/sign-in/');

    return $redirect !== '' ? add_query_arg('redirect_to', rawurlencode($redirect), $url) : $url;
}

/**
 * The OAuth redirect URI to register on the Google client.
 */
function glover_google_oauth_redirect_uri()
{
    return home_url('/sign-in/google/callback/');
}

// The Document Library's "Sign in" links come here instead of wp-login.php.
add_filter('glover_document_library/sign_in_url', function ($url, $redirect) {
    return glover_google_sign_in_url($redirect);
}, 10, 2);

/* -------------------------------------------------------------------------
 * Shared helpers
 * ---------------------------------------------------------------------- */

/**
 * Whether a user may sign in this way: an existing account with a role.
 *
 * @param WP_User|false $user User.
 */
function glover_google_user_may_sign_in($user)
{
    return $user instanceof WP_User && !empty($user->roles) && apply_filters('glover_google/user_may_sign_in', true, $user);
}

/**
 * A safe place to return to after signing in.
 *
 * @param string $url Requested URL.
 */
function glover_google_safe_redirect_target($url)
{
    return wp_validate_redirect($url !== '' ? $url : home_url('/'), home_url('/'));
}

/**
 * Sign a user in for 30 days and send them on.
 *
 * @param WP_User $user     User.
 * @param string  $redirect Where to go.
 * @param string  $method   google or code, for the action hook.
 */
function glover_google_complete_sign_in($user, $redirect, $method)
{
    wp_set_current_user($user->ID);
    wp_set_auth_cookie($user->ID, true);
    do_action('wp_login', $user->user_login, $user);
    do_action('glover_google/signed_in', $user, $method);

    wp_safe_redirect(glover_google_safe_redirect_target($redirect));
    exit;
}

/**
 * Remembered sign-ins last 30 days.
 *
 * @param int  $length   Seconds.
 * @param int  $user_id  User ID.
 * @param bool $remember Whether "remember me" was used.
 */
add_filter('auth_cookie_expiration', function ($length, $user_id, $remember) {
    return $remember ? GLOVER_GOOGLE_SESSION_DAYS * DAY_IN_SECONDS : $length;
}, 10, 3);

/**
 * The client IP, for rate limiting only.
 */
function glover_google_client_ip()
{
    return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
}

/**
 * Count an event in a time window; returns false once over the limit.
 *
 * @param string $key    Bucket key.
 * @param int    $limit  Allowed events.
 * @param int    $window Seconds.
 */
function glover_google_rate_limit($key, $limit, $window)
{
    $key = 'glover_rate_' . md5($key);
    $count = (int) get_transient($key);

    if ($count >= $limit) {
        return false;
    }

    set_transient($key, $count + 1, $window);

    return true;
}

/**
 * Redirect back to the sign-in page with a status code (never personal data).
 *
 * @param string $status   Message key.
 * @param string $redirect Where to go after signing in.
 * @param array  $extra    Extra query args.
 */
function glover_google_back_to_form($status, $redirect = '', $extra = array())
{
    $url = add_query_arg(array_merge(array('status' => $status), $extra), glover_google_sign_in_url($redirect));
    wp_safe_redirect($url);
    exit;
}

/**
 * A browser-bound sign-in session: random ID in a cookie, data in a transient.
 *
 * @param array|null $data Data to store, or null to read.
 * @return array|null
 */
function glover_google_sign_in_session($data = null)
{
    $id = isset($_COOKIE[GLOVER_GOOGLE_SIGN_IN_COOKIE]) ? preg_replace('/[^A-Za-z0-9]/', '', (string) $_COOKIE[GLOVER_GOOGLE_SIGN_IN_COOKIE]) : '';

    if ($data === null) {
        $stored = $id !== '' ? get_transient('glover_sign_in_session_' . $id) : false;
        return is_array($stored) ? $stored : null;
    }

    if ($id === '') {
        $id = wp_generate_password(32, false);
        setcookie(GLOVER_GOOGLE_SIGN_IN_COOKIE, $id, array(
            'expires' => time() + 1800,
            'path' => COOKIEPATH ? COOKIEPATH : '/',
            'domain' => COOKIE_DOMAIN,
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ));
        $_COOKIE[GLOVER_GOOGLE_SIGN_IN_COOKIE] = $id;
    }

    set_transient('glover_sign_in_session_' . $id, $data, 1800);

    return $data;
}

/* -------------------------------------------------------------------------
 * Emailed codes
 * ---------------------------------------------------------------------- */

/**
 * Transient key for an email's pending code.
 *
 * @param string $email Address.
 */
function glover_google_code_key($email)
{
    return 'glover_sign_in_code_' . md5(strtolower(trim($email)));
}

/**
 * Hash a code so it is never stored in plain text.
 *
 * @param string $code Six digits.
 */
function glover_google_hash_code($code)
{
    return hash_hmac('sha256', $code, wp_salt('auth'));
}

/**
 * Create and email a code if the address belongs to someone allowed in.
 * Always behaves the same from the outside, so it never reveals who has an account.
 *
 * @param string $email Address typed by the visitor.
 * @return string Status key: code_sent, too_many or invalid_email.
 */
function glover_google_request_code($email)
{
    if (!is_email($email)) {
        return 'invalid_email';
    }

    if (!glover_google_rate_limit('ip|' . glover_google_client_ip(), 10, HOUR_IN_SECONDS)
        || !glover_google_rate_limit('email|' . strtolower($email), 3, 15 * MINUTE_IN_SECONDS)) {
        return 'too_many';
    }

    $user = get_user_by('email', $email);

    if (glover_google_user_may_sign_in($user) && !get_transient('glover_sign_in_lock_' . md5(strtolower($email)))) {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        set_transient(glover_google_code_key($email), array(
            'hash' => glover_google_hash_code($code),
            'expires' => time() + GLOVER_GOOGLE_CODE_LIFETIME,
            'attempts' => 0,
        ), GLOVER_GOOGLE_CODE_LIFETIME);

        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        wp_mail($user->user_email, 'Your sign-in code: ' . $code, implode("\n\n", array(
            'Your code to sign in to the ' . $site . ' website is:',
            $code,
            'It works once and expires in 10 minutes.',
            "If you didn't ask to sign in, you can ignore this email.",
        )));
    }

    return 'code_sent';
}

/**
 * Check a typed code.
 *
 * @param string $email Address the code was sent to.
 * @param string $code  Code typed by the visitor.
 * @return WP_User|string The user on success, otherwise a status key.
 */
function glover_google_verify_code($email, $code)
{
    $code = preg_replace('/\D/', '', (string) $code);
    $lock_key = 'glover_sign_in_lock_' . md5(strtolower($email));

    if (get_transient($lock_key)) {
        return 'locked';
    }

    $stored = get_transient(glover_google_code_key($email));

    if (!is_array($stored) || $stored['expires'] < time()) {
        return 'code_expired';
    }

    if (!hash_equals($stored['hash'], glover_google_hash_code($code))) {
        $stored['attempts']++;

        if ($stored['attempts'] >= GLOVER_GOOGLE_CODE_MAX_ATTEMPTS) {
            delete_transient(glover_google_code_key($email));
            set_transient($lock_key, 1, GLOVER_GOOGLE_CODE_LOCKOUT);
            return 'locked';
        }

        set_transient(glover_google_code_key($email), $stored, max(1, $stored['expires'] - time()));
        return 'code_wrong';
    }

    delete_transient(glover_google_code_key($email));
    $user = get_user_by('email', $email);

    return glover_google_user_may_sign_in($user) ? $user : 'not_allowed';
}

/* -------------------------------------------------------------------------
 * Google OAuth
 * ---------------------------------------------------------------------- */

/**
 * Send the visitor to Google's account chooser.
 *
 * @param string $redirect Where to go after signing in.
 */
function glover_google_start_oauth($redirect)
{
    if (!glover_google_is_configured('sign_in')) {
        glover_google_back_to_form('google_unavailable', $redirect);
    }

    $state = wp_generate_password(32, false);
    glover_google_sign_in_session(array_merge((array) glover_google_sign_in_session(), array('state' => $state, 'redirect' => $redirect)));

    $params = array(
        'client_id' => glover_google_setting('oauth_client_id'),
        'redirect_uri' => glover_google_oauth_redirect_uri(),
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $state,
        'prompt' => 'select_account',
    );

    if (glover_google_setting('domain') !== '') {
        $params['hd'] = glover_google_setting('domain');
    }

    wp_redirect('https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
    exit;
}

/**
 * Decode the claims of an ID token received directly from Google's token
 * endpoint over HTTPS (Google's guidance: no signature check needed in that case).
 *
 * @param string $id_token JWT.
 * @return array
 */
function glover_google_id_token_claims($id_token)
{
    $parts = explode('.', (string) $id_token);

    if (count($parts) !== 3) {
        return array();
    }

    $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true);

    return is_array($payload) ? $payload : array();
}

/**
 * Validate an ID token's claims for this site.
 *
 * @param array $claims Decoded claims.
 * @return true|string True, or a status key.
 */
function glover_google_validate_claims($claims)
{
    if (($claims['aud'] ?? '') !== glover_google_setting('oauth_client_id')
        || !in_array($claims['iss'] ?? '', array('accounts.google.com', 'https://accounts.google.com'), true)
        || (int) ($claims['exp'] ?? 0) < time()
        || empty($claims['email']) || empty($claims['email_verified'])) {
        return 'google_failed';
    }

    $domain = glover_google_setting('domain');

    if ($domain !== '' && strtolower($claims['hd'] ?? '') !== strtolower($domain)) {
        return 'google_wrong_domain';
    }

    return true;
}

/**
 * Handle Google's redirect back to the site.
 */
function glover_google_finish_oauth()
{
    $session = glover_google_sign_in_session();
    $redirect = $session['redirect'] ?? '';
    $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';

    if (!$session || empty($session['state']) || !hash_equals($session['state'], $state)) {
        glover_google_back_to_form('google_failed', $redirect);
    }

    unset($session['state']);
    glover_google_sign_in_session($session);

    if (!empty($_GET['error']) || empty($_GET['code'])) {
        glover_google_back_to_form('google_cancelled', $redirect);
    }

    $response = wp_remote_post(GLOVER_GOOGLE_TOKEN_URL, array(
        'timeout' => 15,
        'body' => array(
            'code' => sanitize_text_field(wp_unslash($_GET['code'])),
            'client_id' => glover_google_setting('oauth_client_id'),
            'client_secret' => glover_google_setting('oauth_client_secret'),
            'redirect_uri' => glover_google_oauth_redirect_uri(),
            'grant_type' => 'authorization_code',
        ),
    ));
    $tokens = glover_google_decode_response($response);

    if (is_wp_error($tokens) || empty($tokens['id_token'])) {
        error_log('Glover Google sign-in token exchange failed: ' . (is_wp_error($tokens) ? $tokens->get_error_message() : 'no id_token'));
        glover_google_back_to_form('google_failed', $redirect);
    }

    $claims = glover_google_id_token_claims($tokens['id_token']);
    $valid = glover_google_validate_claims($claims);

    if ($valid !== true) {
        glover_google_back_to_form($valid, $redirect);
    }

    $user = get_user_by('email', $claims['email']);

    if (!glover_google_user_may_sign_in($user)) {
        glover_google_back_to_form('not_allowed', $redirect);
    }

    glover_google_complete_sign_in($user, $redirect, 'google');
}

/* -------------------------------------------------------------------------
 * Page
 * ---------------------------------------------------------------------- */

/**
 * Route sign-in requests.
 */
function glover_google_sign_in_router()
{
    $route = get_query_var('glover_sign_in');

    if (!$route) {
        return;
    }

    $redirect = isset($_REQUEST['redirect_to']) ? esc_url_raw(wp_unslash($_REQUEST['redirect_to'])) : '';

    if ($route === 'google') {
        glover_google_start_oauth($redirect);
    }

    if ($route === 'callback') {
        glover_google_finish_oauth();
    }

    if (is_user_logged_in() && !isset($_POST['glover_sign_in_step'])) {
        wp_safe_redirect(glover_google_safe_redirect_target($redirect));
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        glover_google_handle_sign_in_post($redirect);
    }

    glover_google_render_sign_in_page($redirect);
    exit;
}
add_action('template_redirect', 'glover_google_sign_in_router');

/**
 * Handle the email and code forms (post, redirect, get).
 *
 * @param string $redirect Where to go after signing in.
 */
function glover_google_handle_sign_in_post($redirect)
{
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'glover_sign_in')) {
        glover_google_back_to_form('expired', $redirect);
    }

    $step = sanitize_key($_POST['glover_sign_in_step'] ?? '');

    if ($step === 'request') {
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $status = glover_google_request_code($email);

        if ($status === 'code_sent') {
            glover_google_sign_in_session(array_merge((array) glover_google_sign_in_session(), array('email' => $email, 'redirect' => $redirect)));
            glover_google_back_to_form('code_sent', $redirect, array('step' => 'code'));
        }

        glover_google_back_to_form($status, $redirect);
    }

    if ($step === 'verify') {
        $session = glover_google_sign_in_session();
        $email = $session['email'] ?? '';

        if ($email === '') {
            glover_google_back_to_form('code_expired', $redirect);
        }

        $result = glover_google_verify_code($email, wp_unslash($_POST['code'] ?? ''));

        if ($result instanceof WP_User) {
            unset($session['email']);
            glover_google_sign_in_session($session);
            glover_google_complete_sign_in($result, $redirect !== '' ? $redirect : ($session['redirect'] ?? ''), 'code');
        }

        glover_google_back_to_form($result, $redirect, in_array($result, array('code_wrong'), true) ? array('step' => 'code') : array());
    }

    glover_google_back_to_form('expired', $redirect);
}

/**
 * Status messages shown on the sign-in page.
 *
 * @return array<string, array{0:string,1:bool}> Key => [message, is_error].
 */
function glover_google_sign_in_messages()
{
    return array(
        'code_sent' => array('If that address belongs to an account here, we just emailed it a 6-digit code. Check your inbox (and spam folder), then enter the code below.', false),
        'code_wrong' => array("That code isn't right. Check the email and try again.", true),
        'code_expired' => array('That code has expired or was already used. Please request a new one.', true),
        'locked' => array('Too many wrong codes. Please wait 15 minutes, then request a new code.', true),
        'too_many' => array('Too many code requests. Please wait a few minutes and try again.', true),
        'invalid_email' => array('Please enter a valid email address.', true),
        'expired' => array('The form expired. Please try again.', true),
        'not_allowed' => array("This account isn't set up to sign in here. Please contact the Town Clerk's office.", true),
        'google_failed' => array("Google sign-in didn't work. Please try again, or use an emailed code.", true),
        'google_cancelled' => array('Google sign-in was cancelled.', true),
        'google_wrong_domain' => array('Please choose your townofglover.com Google account, or use an emailed code.', true),
        'google_unavailable' => array("Google sign-in isn't available right now. Please use an emailed code.", true),
    );
}

/**
 * Print the sign-in page inside the active theme.
 *
 * @param string $redirect Where to go after signing in.
 */
function glover_google_render_sign_in_page($redirect)
{
    global $wp_query;

    $wp_query->is_404 = false;
    status_header(200);
    nocache_headers();

    add_filter('wp_robots', 'wp_robots_no_robots');
    add_filter('document_title_parts', function ($parts) {
        $parts['title'] = 'Sign in';
        return $parts;
    });

    $messages = glover_google_sign_in_messages();
    $status = isset($_GET['status']) ? sanitize_key($_GET['status']) : '';
    $session = glover_google_sign_in_session();
    $code_step = isset($_GET['step']) && $_GET['step'] === 'code' && !empty($session['email']);
    $action = glover_google_sign_in_url($redirect);
    $google_url = add_query_arg('redirect_to', rawurlencode($redirect), home_url('/sign-in/google/'));

    get_header();
    ?>
    <main id="main-content" class="sign-in" tabindex="-1">
        <?php
        if (locate_template('components/common/page-banner.php')) {
            get_template_part('components/common/page-banner', null, array(
                'title' => 'Sign in',
                'breadcrumbs' => array(
                    array('label' => 'Home', 'url' => home_url('/')),
                    array('label' => 'Sign in', 'url' => home_url('/sign-in/')),
                ),
            ));
        } else {
            echo '<h1>Sign in</h1>';
        }
        ?>
        <div class="content-narrow">
            <?php if (isset($messages[$status])) : ?>
                <div class="notice<?php echo $messages[$status][1] ? ' notice--error' : ''; ?>" role="<?php echo $messages[$status][1] ? 'alert' : 'status'; ?>" tabindex="-1" data-focus-on-load>
                    <p><?php echo esc_html($messages[$status][0]); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($code_step) : ?>
                <form class="sign-in__form" method="post" action="<?php echo esc_url($action); ?>">
                    <?php wp_nonce_field('glover_sign_in'); ?>
                    <input type="hidden" name="glover_sign_in_step" value="verify">
                    <div class="form-field">
                        <label class="form-label" for="sign-in-code">6-digit code</label>
                        <input type="text" id="sign-in-code" name="code" class="form-input sign-in__code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required aria-describedby="sign-in-code-help">
                        <p id="sign-in-code-help" class="sign-in__help">Codes expire after 10 minutes and work once.</p>
                    </div>
                    <p><button type="submit" class="button button--primary">Sign in</button></p>
                </form>

                <form method="post" action="<?php echo esc_url($action); ?>">
                    <?php wp_nonce_field('glover_sign_in'); ?>
                    <input type="hidden" name="glover_sign_in_step" value="request">
                    <input type="hidden" name="email" value="<?php echo esc_attr($session['email']); ?>">
                    <p><button type="submit" class="link-button">Send a new code</button> or <a href="<?php echo esc_url($action); ?>">use a different email address</a>.</p>
                </form>
            <?php else : ?>
                <?php if (glover_google_is_configured('sign_in')) : ?>
                    <h2 class="sign-in__heading">Town staff with a townofglover.com account</h2>
                    <p><a class="button button--primary button--large" href="<?php echo esc_url($google_url); ?>">Sign in with Google</a></p>
                    <h2 class="sign-in__heading">Everyone else</h2>
                <?php endif; ?>

                <form class="sign-in__form" method="post" action="<?php echo esc_url($action); ?>">
                    <?php wp_nonce_field('glover_sign_in'); ?>
                    <input type="hidden" name="glover_sign_in_step" value="request">
                    <div class="form-field">
                        <label class="form-label" for="sign-in-email">Email address</label>
                        <input type="email" id="sign-in-email" name="email" class="form-input" autocomplete="email" required aria-describedby="sign-in-email-help">
                        <p id="sign-in-email-help" class="sign-in__help">We'll email you a 6-digit code. No password needed.</p>
                    </div>
                    <p><button type="submit" class="button button--secondary">Email me a code</button></p>
                </form>
            <?php endif; ?>
        </div>
    </main>
    <?php
    get_footer();
}

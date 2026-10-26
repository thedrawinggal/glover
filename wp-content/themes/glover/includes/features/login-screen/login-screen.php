<?php
/**
 * The WordPress login (wp-login.php) in the Glover style, for anyone signing
 * in to work in the site: admins, editors, and submitters following a link
 * from an email. Submitters starting from Submit a Document sign in there
 * instead and never come here.
 *
 * - The default screen is the shared sign-in panel (components/common/
 *   sign-in-panel.php): emailed code and Google, from the Glover Google
 *   Workspace must-use plugin, with Sign In With Your Password Instead.
 * - The password screen and WordPress's other login screens (lost password,
 *   reset, errors, the session-expired box, re-authentication) keep
 *   WordPress's own forms and behavior, restyled to match. Every screen has
 *   the same left side (glover_login_intro_args()).
 *
 * Design: the "Glover Submit a Document" canvas, boards 8 and 9.
 * Styles: src/styles/pages/_login.scss.
 */

if (!defined('ABSPATH')) {
    exit;
}

const GLOVER_LOGIN_PASSWORD_ARG = 'glover_password';
const GLOVER_LOGIN_CODE_ARG = 'glover_method';
const GLOVER_LOGIN_METHOD_COOKIE = 'glover_login_method';

/**
 * Where a finished sign-in goes: the requested address on this site, or
 * the dashboard.
 *
 * @return string
 */
function glover_login_redirect_target()
{
    $requested = isset($_REQUEST['redirect_to']) ? esc_url_raw(wp_unslash($_REQUEST['redirect_to'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- a destination, validated below.

    return $requested !== '' ? wp_validate_redirect($requested, admin_url()) : admin_url();
}

/**
 * The password screen, keeping where the sign-in leads.
 *
 * @param string $redirect Where a finished sign-in goes.
 * @return string
 */
function glover_login_password_url($redirect)
{
    return add_query_arg(GLOVER_LOGIN_PASSWORD_ARG, '1', wp_login_url($redirect));
}

/**
 * The default screen chosen on purpose, from the password screen's "Sign In
 * With an Emailed Code" link. It overrides a remembered password choice.
 *
 * @param string $redirect Where a finished sign-in goes.
 * @return string
 */
function glover_login_code_url($redirect)
{
    return add_query_arg(GLOVER_LOGIN_CODE_ARG, 'code', wp_login_url($redirect));
}

/**
 * The sign-in method this browser last chose: 'password', or 'code' (the
 * default screen, with an emailed code and Google).
 *
 * @return string
 */
function glover_login_remembered_method()
{
    return isset($_COOKIE[GLOVER_LOGIN_METHOD_COOKIE]) && $_COOKIE[GLOVER_LOGIN_METHOD_COOKIE] === 'password' ? 'password' : 'code';
}

/**
 * Remember the method chosen from a login screen's links, for a year, so the
 * next visit to the login (from /wp-admin/ or a link) opens on it.
 */
function glover_login_remember_method()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        return;
    }

    $method = '';

    if (!empty($_GET[GLOVER_LOGIN_PASSWORD_ARG])) { // phpcs:ignore WordPress.Security.NonceVerification -- a display preference.
        $method = 'password';
    } elseif (isset($_GET[GLOVER_LOGIN_CODE_ARG]) && $_GET[GLOVER_LOGIN_CODE_ARG] === 'code') { // phpcs:ignore WordPress.Security.NonceVerification -- a display preference.
        $method = 'code';
    }

    if ($method === '' || glover_login_remembered_method() === $method && isset($_COOKIE[GLOVER_LOGIN_METHOD_COOKIE])) {
        return;
    }

    setcookie(GLOVER_LOGIN_METHOD_COOKIE, $method, array(
        'expires' => time() + YEAR_IN_SECONDS,
        'path' => SITECOOKIEPATH,
        'domain' => COOKIE_DOMAIN,
        'secure' => is_ssl(),
        'httponly' => true,
        'samesite' => 'Lax',
    ));
    $_COOKIE[GLOVER_LOGIN_METHOD_COOKIE] = $method;
}
add_action('login_init', 'glover_login_remember_method', 5);

/**
 * The Need access line, naming the Town Clerk when the Document Library
 * knows who that is.
 *
 * @return string HTML.
 */
function glover_login_access_line()
{
    $clerk = function_exists('glover_document_library_help_contact_links') ? glover_document_library_help_contact_links() : '';

    return 'Need access? Email the Town Clerk' . ($clerk !== '' ? ' at ' . $clerk : '') . '.';
}

/**
 * The left side of every login screen, the same on all of them: the logo's
 * place, the label, "Sign In" as the page heading and the Need access line.
 *
 * @return array Arguments for components/common/sign-in-intro.php.
 */
function glover_login_intro_args()
{
    return array(
        'label' => 'Town of Glover, Vermont',
        'heading' => 'Sign In',
        'heading_tag' => 'h1',
        'access' => glover_login_access_line(),
        'icon' => 'logo',
    );
}

/**
 * Whether this request is the default login screen, which shows the panel.
 *
 * @return bool
 */
function glover_login_is_default_screen()
{
    if (!function_exists('glover_google_sign_in_url') || $_SERVER['REQUEST_METHOD'] !== 'GET') {
        return false;
    }

    $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : 'login'; // phpcs:ignore WordPress.Security.NonceVerification -- routing only.

    return in_array($action, array('login', ''), true)
        && empty($_GET[GLOVER_LOGIN_PASSWORD_ARG]) // phpcs:ignore WordPress.Security.NonceVerification -- routing only.
        && empty($_REQUEST['interim-login']); // phpcs:ignore WordPress.Security.NonceVerification -- routing only.
}

/**
 * Show the default login screen: the sign-in panel on the cream page, with
 * a link back to the site below it.
 */
function glover_login_render_default_screen()
{
    if (!glover_login_is_default_screen()) {
        return;
    }

    $redirect = glover_login_redirect_target();

    // /wp-admin/ sends signed-out visitors here with reauth=1, which asks for
    // a fresh sign-in: clear any old sign-in, as WordPress's own screen does.
    if (!empty($_GET['reauth'])) { // phpcs:ignore WordPress.Security.NonceVerification -- routing only.
        wp_clear_auth_cookie();
    } elseif (is_user_logged_in()) {
        wp_safe_redirect($redirect);
        exit;
    }

    // Someone who last chose Sign In With Your Password Instead gets the
    // password screen, unless they just chose the emailed code.
    if (glover_login_remembered_method() === 'password' && empty($_GET[GLOVER_LOGIN_CODE_ARG])) { // phpcs:ignore WordPress.Security.NonceVerification -- routing only.
        wp_safe_redirect(glover_login_password_url($redirect));
        exit;
    }

    nocache_headers();
    add_filter('wp_robots', 'wp_robots_no_robots');
    add_filter('pre_get_document_title', function () {
        return 'Sign In | ' . wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
    });
    ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body class="glover-login">
<main id="main-content" class="glover-login__main">
    <?php
    $intro = glover_login_intro_args();
    get_template_part('components/common/sign-in-panel', null, array(
        'label' => $intro['label'],
        'heading' => $intro['heading'],
        'heading_level' => 1,
        'access' => $intro['access'],
        'icon' => $intro['icon'],
        'return' => $redirect,
        'back' => wp_login_url($redirect),
        'start_links' => array(
            array('label' => 'Sign In With Your Password Instead', 'url' => glover_login_password_url($redirect)),
        ),
    ));
    ?>
    <p class="glover-login__back"><a href="<?php echo esc_url(home_url('/')); ?>"><span aria-hidden="true">&larr; </span>Go to <?php echo esc_html(get_bloginfo('name')); ?></a></p>
</main>
<?php wp_footer(); ?>
</body>
</html>
    <?php
    exit;
}
add_action('login_init', 'glover_login_render_default_screen');

/* -------------------------------------------------------------------------
 * WordPress's own login screens, restyled
 * ---------------------------------------------------------------------- */

/**
 * Glover fonts and styles on every login screen.
 */
function glover_login_enqueue_styles()
{
    wp_enqueue_style('glover-fonts', GLOVER_FONTS_URL, array(), null);
    wp_enqueue_style('glover-app-style', get_template_directory_uri() . '/dist/styles/app.css', array('glover-fonts', 'login'), (string) filemtime(get_template_directory() . '/dist/styles/app.css'));
}
add_action('login_enqueue_scripts', 'glover_login_enqueue_styles');

/**
 * The "session expired" sign-in window (WordPress's auth check) at twice its
 * core width, so the restyled login fits. Core sizes it at 380px and goes full
 * width below that; this keeps the same pattern at 760px.
 */
function glover_login_widen_auth_check()
{
    wp_add_inline_style('wp-auth-check', '
        #wp-auth-check-wrap #wp-auth-check { width: 760px; margin-left: -380px; }
        @media screen and (max-width: 760px) {
            #wp-auth-check-wrap #wp-auth-check { left: 0; width: 100%; margin: 0; }
        }
    ');
}
add_action('admin_enqueue_scripts', 'glover_login_widen_auth_check');
add_action('wp_enqueue_scripts', 'glover_login_widen_auth_check');

add_filter('login_body_class', function ($classes) {
    $classes[] = 'glover-login-native';
    return $classes;
});

add_filter('login_headerurl', function () {
    return home_url('/');
});

add_filter('login_headertext', function () {
    return 'Town of Glover, Vermont';
});

add_filter('login_display_language_dropdown', '__return_false');

/**
 * The left side of the panel, before WordPress's message: the same intro as
 * the default screen (glover_login_intro_args()). Its "Sign In" is the page's
 * h1; WordPress's own h1 (the logo link) is hidden in the styles.
 *
 * @param string $message WordPress's message for this screen.
 * @return string
 */
function glover_login_message($message)
{
    ob_start();
    get_template_part('components/common/sign-in-intro', null, glover_login_intro_args());

    return ob_get_clean() . $message;
}
add_filter('login_message', 'glover_login_message');

/**
 * Links under the password form: Lost Your Password?, and the ways back to
 * an emailed code or Google.
 *
 * @return string
 */
function glover_login_password_links()
{
    $redirect = glover_login_redirect_target();
    $links = sprintf('<a class="wp-login-lost-password" href="%s">Lost Your Password?</a>', esc_url(wp_lostpassword_url($redirect)));

    if (function_exists('glover_google_sign_in_url')) {
        $links .= sprintf(' <a href="%s">Sign In With an Emailed Code</a>', esc_url(glover_login_code_url($redirect)));

        if (glover_google_is_configured('sign_in')) {
            $google = add_query_arg(array('redirect_to' => rawurlencode($redirect), 'glover_sign_in_back' => rawurlencode(wp_login_url($redirect))), home_url('/sign-in/google/'));
            $links .= sprintf(' <a href="%s">Sign In With Google</a>', esc_url($google));
        }
    }

    return $links;
}
add_filter('lost_password_html_link', 'glover_login_password_links');

// The link back to the site sits under the panel (login_footer), not inside it.
add_filter('login_site_html_link', '__return_empty_string');

add_action('login_footer', function () {
    printf(
        '<p class="glover-login__back"><a href="%s"><span aria-hidden="true">&larr; </span>Go to %s</a></p>',
        esc_url(home_url('/')),
        esc_html(get_bloginfo('name'))
    );
});

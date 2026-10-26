<?php
/**
 * Sign-in panel: the Forest Dark panel with an intro on the left and a card
 * on the right that walks through emailed-code sign-in and Google. The panel
 * stays the same in every state; only the card changes, and every step comes
 * back to the page the panel is on.
 * Styles: src/styles/components/_sign-in-panel.scss and the on-forest form
 * styles in components/_form.scss.
 *
 * Works with the Glover Google Workspace must-use plugin: the forms post to
 * its /sign-in/ address and Google starts at /sign-in/google/; each step
 * returns to 'back' with glover_status (and glover_step=code), and a
 * finished sign-in goes to 'return'. Without the plugin the card shows a
 * single Sign In link.
 *
 * Usage:
 * get_template_part('components/common/sign-in-panel', null, array(
 *     'label' => 'Town of Glover, Vermont', // optional small label above the heading
 *     'heading' => 'Sign In to Submit',
 *     'heading_level' => 2,                 // 1 when the panel is the page's main heading
 *     'intro' => 'For town staff and board members posting public records.',
 *     'access' => 'Need access? Email the Town Clerk at <a href="mailto:...">...</a>.',
 *     'icon' => 'document',                 // or 'lock', or 'logo' for the town logo's place
 *     'return' => get_permalink(),          // where a finished sign-in goes
 *     'back' => get_permalink(),            // the page the panel is on (defaults to 'return')
 *     'start_links' => array(array('label' => 'Sign In With Your Password Instead', 'url' => '...')),
 * ));
 *
 * The step, status and code address come from the request (glover_step,
 * glover_status and the plugin's sign-in session) unless 'step', 'status'
 * and 'email' are passed.
 *
 * Card states: start (email box, then Google); code sent or resent (code box,
 * then Send a New Code, Use a Different Email Address, Sign In With Google
 * Instead); wrong code; code expired; too many wrong codes; email not valid;
 * too many requests; account can't sign in; Google problem; page timed out.
 *
 * @var array $args
 */

if (!defined('ABSPATH')) {
    exit;
}

$label = isset($args['label']) ? $args['label'] : '';
$heading = isset($args['heading']) ? $args['heading'] : 'Sign In';
$heading_tag = isset($args['heading_level']) && (int) $args['heading_level'] === 1 ? 'h1' : 'h2';
$intro = isset($args['intro']) ? $args['intro'] : '';
$access = isset($args['access']) ? $args['access'] : '';
$icon = isset($args['icon']) ? $args['icon'] : 'document';
$return = isset($args['return']) ? $args['return'] : '';
$back = isset($args['back']) ? $args['back'] : $return;
$back = remove_query_arg(array('glover_status', 'glover_step'), $back);
$start_links = isset($args['start_links']) ? (array) $args['start_links'] : array();

$has_plugin = function_exists('glover_google_sign_in_url');
$session = $has_plugin ? (array) glover_google_sign_in_session() : array();
$status = isset($args['status']) ? $args['status'] : (isset($_GET['glover_status']) ? sanitize_key(wp_unslash($_GET['glover_status'])) : ''); // phpcs:ignore WordPress.Security.NonceVerification -- display only.
$step = isset($args['step']) ? $args['step'] : (isset($_GET['glover_step']) && $_GET['glover_step'] === 'code' ? 'code' : 'email'); // phpcs:ignore WordPress.Security.NonceVerification -- display only.
$email = isset($args['email']) ? $args['email'] : (isset($session['email']) ? (string) $session['email'] : '');

$action = $has_plugin ? glover_google_sign_in_url($return) : '';
$google_url = $has_plugin && glover_google_is_configured('sign_in')
    ? add_query_arg(array('redirect_to' => rawurlencode($return), 'glover_sign_in_back' => rawurlencode($back)), home_url('/sign-in/google/'))
    : '';
$heading_id = wp_unique_id('sign-in-panel-heading-');

// Which card to show, and its notice (title, HTML text, kind: info or warning).
// The visitor's address is escaped here, since notice text allows links.
$notice = null;
$view = 'start';
$safe_email = esc_html($email);

if ($step === 'code' && $email !== '' && in_array($status, array('code_sent', 'code_resent', 'code_wrong'), true)) {
    $view = 'code';

    if ($status === 'code_sent') {
        $notice = array('Check Your Email', sprintf("If %s has an account here, a 6-digit code is on its way. Look in spam if it isn't in your inbox.", $safe_email), 'info');
    } elseif ($status === 'code_resent') {
        $notice = array('New Code Sent', sprintf('Use the newest code for %s. Earlier codes no longer work.', $safe_email), 'info');
    }
} elseif ($status === 'code_expired' && $email !== '') {
    $view = 'expired';
    $notice = array('Code Expired', sprintf('That code has expired or was already used. Send a new one to %s.', $safe_email), 'warning');
} elseif ($status === 'locked') {
    $view = 'links';
    $notice = array('Too Many Tries', 'Wait 15 minutes, then send a new code.', 'warning');
} elseif ($status === 'not_allowed') {
    $view = 'links';
    $notice = array('Account Not Set Up', "This account can't sign in here. " . $access, 'warning');
} elseif ($status === 'too_many') {
    $notice = array('Too Many Requests', 'Wait a few minutes, then ask for a code again.', 'warning');
} elseif ($status === 'expired') {
    $notice = array('Page Timed Out', 'Enter your email address again.', 'warning');
} elseif ($status === 'code_expired') {
    $notice = array('Code Expired', 'Enter your email address to get a new code.', 'warning');
} elseif (in_array($status, array('google_failed', 'google_cancelled', 'google_wrong_domain', 'google_unavailable'), true)) {
    $notice = array("Google Sign-In Didn't Finish", 'Try Google again, or get a code by email.', 'warning');
}

$email_error = $status === 'invalid_email' ? 'Enter an email address, like name@example.com.' : '';
$code_error = $status === 'code_wrong' ? "That code isn't right. Check the email and enter it again." : '';

// The hidden fields every form carries: the plugin's nonce and step, and the page to come back to.
$hidden = function ($step_value) use ($back) {
    wp_nonce_field('glover_sign_in');
    printf('<input type="hidden" name="glover_sign_in_step" value="%s">', esc_attr($step_value));
    printf('<input type="hidden" name="glover_sign_in_back" value="%s">', esc_url($back));
};
?>
<section class="sign-in-panel on-forest" aria-labelledby="<?php echo esc_attr($heading_id); ?>">
    <?php
    get_template_part('components/common/sign-in-intro', null, array(
        'label' => $label,
        'heading' => $heading,
        'heading_tag' => $heading_tag,
        'heading_id' => $heading_id,
        'intro' => $intro,
        'access' => $access,
        'icon' => $icon,
    ));
    ?>

    <div class="sign-in-panel__card">
        <?php if (!$has_plugin) : ?>
            <a class="button button--secondary button--large sign-in-panel__button" href="<?php echo esc_url(wp_login_url($return)); ?>">Sign In</a>
        <?php else : ?>

            <?php if ($notice) : ?>
                <div class="notice<?php echo $notice[2] === 'warning' ? ' notice--warning' : ''; ?> sign-in-panel__notice" role="<?php echo $notice[2] === 'warning' ? 'alert' : 'status'; ?>">
                    <p><strong><?php echo esc_html($notice[0]); ?></strong></p>
                    <p><?php echo wp_kses_post($notice[1]); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($view === 'code') : ?>
                <form class="sign-in-panel__form" method="post" action="<?php echo esc_url($action); ?>">
                    <?php $hidden('verify'); ?>
                    <label class="form-label" for="sign-in-panel-code">6-Digit Code</label>
                    <?php if ($code_error !== '') : ?>
                        <p id="sign-in-panel-code-error" class="form-error"><?php echo esc_html($code_error); ?></p>
                    <?php else : ?>
                        <p id="sign-in-panel-code-help" class="form-help">Codes expire after 10 minutes and work once.</p>
                    <?php endif; ?>
                    <input type="text" id="sign-in-panel-code" name="code" class="form-input sign-in-panel__code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required aria-describedby="<?php echo $code_error !== '' ? 'sign-in-panel-code-error' : 'sign-in-panel-code-help'; ?>"<?php echo $code_error !== '' ? ' aria-invalid="true"' : ''; ?>>
                    <button type="submit" class="button button--secondary button--large sign-in-panel__button">Sign In</button>
                </form>
            <?php elseif ($view === 'expired') : ?>
                <form class="sign-in-panel__form" method="post" action="<?php echo esc_url($action); ?>">
                    <?php $hidden('request'); ?>
                    <input type="hidden" name="glover_sign_in_resend" value="1">
                    <input type="hidden" name="email" value="<?php echo esc_attr($email); ?>">
                    <button type="submit" class="button button--secondary button--large sign-in-panel__button">Send a New Code</button>
                </form>
            <?php elseif ($view === 'start') : ?>
                <form class="sign-in-panel__form" method="post" action="<?php echo esc_url($action); ?>">
                    <?php $hidden('request'); ?>
                    <label class="form-label" for="sign-in-panel-email">Email Address</label>
                    <?php if ($email_error !== '') : ?>
                        <p id="sign-in-panel-email-error" class="form-error"><?php echo esc_html($email_error); ?></p>
                    <?php else : ?>
                        <p id="sign-in-panel-email-help" class="form-help">We'll email you a 6-digit code. No password needed.</p>
                    <?php endif; ?>
                    <input type="email" id="sign-in-panel-email" name="email" class="form-input" autocomplete="email" required aria-describedby="<?php echo $email_error !== '' ? 'sign-in-panel-email-error' : 'sign-in-panel-email-help'; ?>"<?php echo $email_error !== '' ? ' aria-invalid="true"' : ''; ?>>
                    <button type="submit" class="button button--secondary button--large sign-in-panel__button">Email Me a Code</button>
                </form>

                <?php if ($google_url) : ?>
                    <p class="sign-in-panel__or" aria-hidden="true">or</p>
                    <a class="button button--secondary button--large sign-in-panel__button" href="<?php echo esc_url($google_url); ?>">Sign In With Google</a>
                    <p class="form-help sign-in-panel__center">Use the Google account the Town Clerk has on file for you.</p>
                <?php endif; ?>

                <?php if ($start_links) : ?>
                    <div class="sign-in-panel__links">
                        <?php foreach ($start_links as $link) : ?>
                            <a href="<?php echo esc_url($link['url']); ?>"><?php echo esc_html($link['label']); ?></a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($view !== 'start') : ?>
                <div class="sign-in-panel__links">
                    <?php if ($view === 'code') : ?>
                        <form method="post" action="<?php echo esc_url($action); ?>">
                            <?php $hidden('request'); ?>
                            <input type="hidden" name="glover_sign_in_resend" value="1">
                            <input type="hidden" name="email" value="<?php echo esc_attr($email); ?>">
                            <button type="submit" class="link-button">Send a New Code</button>
                        </form>
                    <?php endif; ?>
                    <a href="<?php echo esc_url($back); ?>">Use a Different Email Address</a>
                    <?php if ($google_url) : ?>
                        <a href="<?php echo esc_url($google_url); ?>">Sign In With Google Instead</a>
                    <?php endif; ?>
                    <?php foreach ($start_links as $link) : ?>
                        <a href="<?php echo esc_url($link['url']); ?>"><?php echo esc_html($link['label']); ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

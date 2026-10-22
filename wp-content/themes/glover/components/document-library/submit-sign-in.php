<?php
/**
 * Submit a Document, signed out: the sign-in panel and How It Works.
 * The email form posts to the Glover Google Workspace must-use plugin's
 * /sign-in/ page, which sends the code and asks for it there; Google goes
 * straight to /sign-in/google/. Both come back to this page. Without that
 * plugin, the panel shows a single Sign In link instead.
 * Styles: src/styles/pages/_submit-document.scss and components/_steps.scss.
 *
 * @var array $args 'return' (URL to come back to after signing in).
 */

if (!defined('ABSPATH')) {
    exit;
}

$return = isset($args['return']) ? $args['return'] : get_permalink();
$has_plugin = function_exists('glover_google_sign_in_url');
$code_action = $has_plugin ? glover_google_sign_in_url($return) : '';
$google_url = $has_plugin && glover_google_is_configured('sign_in') ? add_query_arg('redirect_to', rawurlencode($return), home_url('/sign-in/google/')) : '';
$clerk_email = glover_document_library_help_contact()['email'];
$clerk_link = $clerk_email !== '' ? '<a href="mailto:' . esc_attr($clerk_email) . '">' . esc_html($clerk_email) . '</a>' : 'the Town Clerk\'s office';
$requirements_id = (int) glover_document_library_setting('requirements_page', 0);
$requirements_url = $requirements_id && get_post_status($requirements_id) === 'publish' ? get_permalink($requirements_id) : '';

$note = $requirements_url
    ? sprintf('Before uploading, <a href="%s">review the requirements</a> for accessible, public documents. ', esc_url($requirements_url))
    : '';
$note .= sprintf('Looking for a document? Browse the <a href="%s">Document Library</a>.', esc_url(glover_document_library_url()));
?>
<section class="submit-sign-in" aria-labelledby="submit-sign-in-heading">
    <div class="submit-sign-in__intro">
        <span class="submit-sign-in__icon" aria-hidden="true">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"></path><path d="M14 3v6h6M8 13h8M8 17h5"></path></svg>
        </span>
        <h2 id="submit-sign-in-heading" class="submit-sign-in__heading">Sign In to Submit</h2>
        <p>For town staff and board members posting agendas, minutes and other public records to the Document Library.</p>
        <p class="submit-sign-in__access">Need access? Email the Town Clerk at <?php echo $clerk_link; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above. ?>.</p>
    </div>

    <div class="submit-sign-in__card">
        <?php if ($has_plugin) : ?>
            <form class="submit-sign-in__form" method="post" action="<?php echo esc_url($code_action); ?>">
                <?php wp_nonce_field('glover_sign_in'); ?>
                <input type="hidden" name="glover_sign_in_step" value="request">
                <label class="form-label" for="submit-sign-in-email">Email Address</label>
                <p id="submit-sign-in-email-help" class="submit-sign-in__help">We'll email you a 6-digit code. No password needed.</p>
                <input type="email" id="submit-sign-in-email" name="email" class="form-input" autocomplete="email" required aria-describedby="submit-sign-in-email-help">
                <button type="submit" class="button button--secondary button--large submit-sign-in__button">Email Me a Code</button>
            </form>

            <?php if ($google_url) : ?>
                <p class="submit-sign-in__or" aria-hidden="true">or</p>
                <a class="button button--secondary button--large submit-sign-in__button" href="<?php echo esc_url($google_url); ?>">Sign In With Google</a>
                <p class="submit-sign-in__help submit-sign-in__help--center">Use the Google account the Town Clerk has on file for you.</p>
            <?php endif; ?>
        <?php else : ?>
            <a class="button button--secondary button--large submit-sign-in__button" href="<?php echo esc_url(glover_document_library_sign_in_url($return)); ?>">Sign In</a>
        <?php endif; ?>
    </div>
</section>

<?php
get_template_part('components/common/steps', null, array(
    'heading' => 'How It Works',
    'steps' => array(
        array('title' => 'Request Access', 'text' => 'Email the Town Clerk at ' . $clerk_link . ' with your name and board.'),
        array('title' => 'Sign In', 'text' => 'With an emailed code or Google.'),
        array('title' => 'Upload the Document', 'text' => 'Add the file, its board, category and date.'),
        array('title' => 'Review and Publishing', 'text' => "The Town Clerk reviews it, and you get an email once it's live."),
    ),
    'note' => $note,
));

<?php
/**
 * Template Name: Request an Accessible Copy
 *
 * Anyone can ask for a document in a format they can use. Requests are
 * saved in wp-admin and emailed to the help contact.
 */

$document_id = isset($_GET['document']) ? absint($_GET['document']) : 0;

if ($document_id && (get_post_type($document_id) !== GLOVER_DOCUMENT_LIBRARY_POST_TYPE || get_post_status($document_id) !== 'publish')) {
    $document_id = 0;
}

$errors = array(
    'missing' => 'Please fill in your name, a valid email address, and the format you need.',
    'expired' => 'The form expired. Please try again.',
    'too_many' => 'Too many requests were sent from this connection. Please wait an hour, or call us instead.',
    'check' => 'The automatic spam check didn\'t pass. Please try again.',
    'save' => 'Something went wrong saving your request. Please try again, or call us instead.',
);
$error = isset($_GET['request_error']) ? sanitize_key($_GET['request_error']) : '';
$sent = !empty($_GET['request_sent']);
$site_key = glover_document_library_setting('turnstile_site_key');
$contact = glover_document_library_help_contact();

get_header();
?>
<main id="main-content" class="request-copy" tabindex="-1">
    <?php
    get_template_part('components/common/page-banner', null, array(
        'title' => get_the_title(),
        'breadcrumbs' => array(
            array('label' => 'Home', 'url' => home_url('/')),
            array('label' => 'Documents', 'url' => glover_document_library_url()),
            array('label' => get_the_title(), 'url' => get_permalink()),
        ),
    ));
    ?>

    <div class="content-narrow">
        <div id="glover-request-status" role="status" aria-live="polite">
            <?php if ($sent) : ?>
                <div class="notice" tabindex="-1" data-focus-on-load>
                    <p><strong>Thank you.</strong> We received your request and will contact you soon.</p>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!$sent) : ?>
            <?php while (have_posts()) : the_post(); ?>
                <div class="rich-text"><?php the_content(); ?></div>
            <?php endwhile; ?>

            <?php if ($error && isset($errors[$error])) : ?>
                <div class="notice notice--error" role="alert" tabindex="-1" data-focus-on-load>
                    <p><?php echo esc_html($errors[$error]); ?></p>
                </div>
            <?php endif; ?>

            <form id="glover-request-form" class="form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="glover_document_request">
                <input type="hidden" name="return_to" value="<?php echo esc_url(get_permalink() . ($document_id ? '?document=' . $document_id : '')); ?>">
                <?php wp_nonce_field('glover_document_request'); ?>

                <div class="form-field">
                    <?php if ($document_id) : ?>
                        <p class="form-label">Document</p>
                        <p><a href="<?php echo esc_url(get_permalink($document_id)); ?>"><?php echo esc_html(get_the_title($document_id)); ?></a></p>
                        <input type="hidden" name="request_document" value="<?php echo (int) $document_id; ?>">
                    <?php else : ?>
                        <label class="form-label" for="request-document-title">Which document do you need?</label>
                        <input type="text" id="request-document-title" name="request_document_title" class="form-input">
                    <?php endif; ?>
                </div>

                <div class="form-field">
                    <label class="form-label" for="request-name">Your name <span class="form-required">(required)</span></label>
                    <input type="text" id="request-name" name="request_name" class="form-input" autocomplete="name" required>
                </div>

                <div class="form-field">
                    <label class="form-label" for="request-email">Email address <span class="form-required">(required)</span></label>
                    <input type="email" id="request-email" name="request_email" class="form-input" autocomplete="email" required>
                </div>

                <div class="form-field">
                    <label class="form-label" for="request-phone">Phone number</label>
                    <input type="tel" id="request-phone" name="request_phone" class="form-input" autocomplete="tel">
                </div>

                <fieldset class="form-field">
                    <legend class="form-label">Format you need <span class="form-required">(required)</span></legend>
                    <?php foreach (GLOVER_DOCUMENT_LIBRARY_REQUEST_FORMATS as $value => $label) : ?>
                        <div class="form-choice">
                            <input type="radio" id="request-format-<?php echo esc_attr($value); ?>" name="request_format" value="<?php echo esc_attr($value); ?>" required>
                            <label for="request-format-<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></label>
                        </div>
                    <?php endforeach; ?>
                </fieldset>

                <div class="form-field">
                    <label class="form-label" for="request-details">Anything else we should know?</label>
                    <textarea id="request-details" name="request_details" class="form-input" rows="5"></textarea>
                </div>

                <div class="visually-hidden" aria-hidden="true">
                    <label for="request-website">Leave this field empty</label>
                    <input type="text" id="request-website" name="website" tabindex="-1" autocomplete="off">
                </div>

                <?php if ($site_key) : ?>
                    <div class="cf-turnstile" data-sitekey="<?php echo esc_attr($site_key); ?>"></div>
                <?php endif; ?>

                <p><button type="submit" class="button button--primary">Send request</button></p>
            </form>

            <p>
                You can also contact <?php echo esc_html($contact['name']); ?><?php
                if ($contact['phone']) {
                    printf(' at <a href="tel:%s">%s</a>', esc_attr(preg_replace('/[^0-9+]/', '', $contact['phone'])), esc_html($contact['phone']));
                }
                ?>.
            </p>
        <?php endif; ?>
    </div>
</main>
<?php
get_footer();

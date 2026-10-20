<?php
/**
 * Template Name: Submit a Document
 *
 * Signed-in submitters add documents to the library here. Submissions wait
 * for the approver unless the submitter has Auto-approve.
 */

glover_document_library_submission_form_head();

$submitted_id = isset($_GET['submitted']) ? absint($_GET['submitted']) : 0;
$submitted = $submitted_id ? get_post($submitted_id) : null;

if ($submitted && ((int) $submitted->post_author !== get_current_user_id() || $submitted->post_type !== GLOVER_DOCUMENT_LIBRARY_POST_TYPE)) {
    $submitted = null;
}

get_header();
?>
<main id="main-content" class="submit-document" tabindex="-1">
    <?php
    get_template_part('components/common/page-banner', null, array(
        'title' => get_the_title(),
        'breadcrumbs' => array(
            array('label' => 'Home', 'url' => home_url('/')),
            array('label' => get_the_title(), 'url' => get_permalink()),
        ),
    ));
    ?>

    <div class="content-narrow">
        <?php if (!is_user_logged_in()) : ?>
            <p>Please sign in to submit a document.</p>
            <p><a class="button button--primary" href="<?php echo esc_url(glover_document_library_sign_in_url(get_permalink())); ?>">Sign in</a></p>

        <?php elseif (!glover_document_library_user_can_submit()) : ?>
            <p>Your account isn't set up to submit documents. Please contact the Town Clerk's office if you need access.</p>

        <?php elseif (!glover_document_library_allowed_boards()) : ?>
            <p>Your account isn't assigned to a board yet, so you can't submit documents. Please contact the Town Clerk's office.</p>

        <?php else : ?>
            <?php if ($submitted) : ?>
                <div class="notice" role="status" tabindex="-1" data-focus-on-load>
                    <?php if ($submitted->post_status === 'publish') : ?>
                        <p><strong>Published.</strong> “<?php echo esc_html(get_the_title($submitted)); ?>” is now on the website. <a href="<?php echo esc_url(get_permalink($submitted)); ?>">View it</a>.</p>
                    <?php elseif ($submitted->post_status === 'trash' && get_post_meta($submitted->ID, '_glover_merged_into', true)) : ?>
                        <?php $original = (int) get_post_meta($submitted->ID, '_glover_merged_into', true); ?>
                        <p><strong>Published.</strong> The new version replaced the earlier one. <a href="<?php echo esc_url(get_permalink($original)); ?>">View it</a>.</p>
                    <?php else : ?>
                        <p><strong>Thank you.</strong> “<?php echo esc_html(get_the_title($submitted)); ?>” was sent for review. You'll get an email when it's published or if it needs changes.</p>
                    <?php endif; ?>
                    <p>You can submit another document below.</p>
                </div>
            <?php endif; ?>

            <p>
                <a class="button button--primary button--large" href="#glover-requirements" data-dialog-open="glover-requirements">
                    Review accessibility and legal requirements for uploaded documents
                </a>
            </p>

            <?php while (have_posts()) : the_post(); ?>
                <div class="rich-text"><?php the_content(); ?></div>
            <?php endwhile; ?>

            <?php glover_document_library_render_submission_form(); ?>

            <?php get_template_part('components/document-library/requirements'); ?>
        <?php endif; ?>
    </div>
</main>
<?php
get_footer();

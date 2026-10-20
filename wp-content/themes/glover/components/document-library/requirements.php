<?php
/**
 * Accessibility and legal requirements for uploaded documents. The text
 * comes from the page chosen in Document Library settings, which the clerk
 * keeps current. dialogs.js shows it as a modal.
 */

if (!defined('ABSPATH')) {
    exit;
}

$page_id = (int) glover_document_library_setting('requirements_page', 0);
$page = $page_id ? get_post($page_id) : null;
?>
<section id="glover-requirements" class="dialog-content rich-text" data-glover-dialog aria-labelledby="glover-requirements-title">
    <h2 id="glover-requirements-title" tabindex="-1">Accessibility and legal requirements for uploaded documents</h2>
    <?php if ($page && $page->post_status === 'publish') : ?>
        <?php echo apply_filters('the_content', $page->post_content); // phpcs:ignore WordPress.Security.EscapeOutput -- page content. ?>
        <p><a href="<?php echo esc_url(get_permalink($page)); ?>">Open these requirements as a page</a> to print or bookmark them.</p>
    <?php else : ?>
        <p>The requirements guide hasn't been set up yet. Ask the approver before uploading.</p>
    <?php endif; ?>
</section>

<?php
/**
 * Search help. Rendered as a normal section so it works without
 * JavaScript; dialogs.js turns it into a modal opened by the Help link.
 * Draft copy: Jenny is writing the final text.
 */

if (!defined('ABSPATH')) {
    exit;
}

$contact = glover_document_library_help_contact();
?>
<section id="glover-library-help" class="dialog-content rich-text" data-glover-dialog aria-labelledby="glover-library-help-title">
    <h2 id="glover-library-help-title" tabindex="-1">How to find a document</h2>

    <h3>Start with a word or two</h3>
    <p>Type something from the title, like “budget” or “minutes.” You don't need the exact title.</p>

    <h3>Narrow it down</h3>
    <p>Use the choices beside the results: pick a board, set the years, or tick a category such as Minutes. The list updates as you go. On a phone, select Filters to see these choices.</p>

    <h3>Topics are shortcuts</h3>
    <p>Select a topic like Roads to see everything about roads, from any board.</p>

    <h3>Start over any time</h3>
    <p>Remove one filter with its ×, or use Clear all.</p>

    <h3>Opening a document</h3>
    <p>Select a title to see its details, or Download to save a copy. Most documents are PDFs, which open in your web browser.</p>

    <h3>Need it in another format?</h3>
    <p>If a document doesn't work with your screen reader or device, ask us and we'll send you an accessible copy.<?php
        $request_url = glover_document_library_request_url();
        if ($request_url) {
            printf(' <a href="%s">Request an accessible copy</a>.', esc_url($request_url));
        }
        ?></p>

    <h3>Can't find what you need?</h3>
    <p>
        Contact <?php echo esc_html($contact['name']); ?><?php
        if ($contact['phone']) {
            printf(' at <a href="tel:%s">%s</a>', esc_attr(preg_replace('/[^0-9+]/', '', $contact['phone'])), esc_html($contact['phone']));
        }
        if ($contact['email']) {
            printf(' or <a href="mailto:%1$s">%1$s</a>', esc_attr($contact['email']));
        }
        ?>. We're glad to look for you.
    </p>
</section>

<?php
/**
 * Submit a Document, signed out: the shared sign-in panel
 * (components/common/sign-in-panel.php), then How It Works (the steps
 * component). The whole sign-in happens on this page: every step and the
 * finished sign-in come back here.
 * Styles: components/_sign-in-panel.scss and components/_steps.scss.
 *
 * @var array $args 'return' (URL to come back to after signing in).
 */

if (!defined('ABSPATH')) {
    exit;
}

$return = isset($args['return']) ? $args['return'] : get_permalink();
$clerk_link = glover_document_library_help_contact_links();
$clerk_link = $clerk_link !== '' ? $clerk_link : 'the Town Clerk\'s office';
$requirements_id = (int) glover_document_library_setting('requirements_page', 0);
$requirements_url = $requirements_id && get_post_status($requirements_id) === 'publish' ? get_permalink($requirements_id) : '';

$note = $requirements_url
    ? sprintf('Before uploading, <a href="%s">review the requirements</a> for accessible, public documents. ', esc_url($requirements_url))
    : '';
$note .= sprintf('Looking for a document? Browse the <a href="%s">Document Library</a>.', esc_url(glover_document_library_url()));

get_template_part('components/common/sign-in-panel', null, array_merge(
    glover_document_library_sign_in_panel_args(),
    array('return' => $return, 'back' => $return)
));

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

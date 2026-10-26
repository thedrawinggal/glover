<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Whether the current front-end view belongs to the Document Library.
 */
function glover_document_library_is_feature_view()
{
    return is_post_type_archive(GLOVER_DOCUMENT_LIBRARY_POST_TYPE)
        || is_singular(array(GLOVER_DOCUMENT_LIBRARY_POST_TYPE, GLOVER_DOCUMENT_LIBRARY_BOARD_POST_TYPE))
        || is_page_template(GLOVER_DOCUMENT_LIBRARY_SUBMIT_TEMPLATE);
}

/**
 * Load the library's own stylesheet and scripts only where they are used.
 */
function glover_document_library_enqueue_public_assets()
{
    if (!glover_document_library_is_feature_view()) {
        return;
    }

    $dir = __DIR__ . '/assets';
    $url = get_template_directory_uri() . '/includes/features/document-library/assets';

    // Styles live in the theme's Sass build (src/styles/pages/_documents.scss and
    // the shared components), compiled into dist/styles/app.css.

    if (is_post_type_archive(GLOVER_DOCUMENT_LIBRARY_POST_TYPE)) {
        wp_enqueue_script('glover-document-library', $url . '/library.js', array(), (string) filemtime($dir . '/library.js'), array('in_footer' => true, 'strategy' => 'defer'));
        wp_localize_script('glover-document-library', 'gloverDocumentLibrary', array(
            'endpoint' => esc_url_raw(rest_url('glover/v1/documents')),
        ));
    }
}
add_action('wp_enqueue_scripts', 'glover_document_library_enqueue_public_assets', 20);

/**
 * The shared dialog script also runs on single documents and the
 * submission page, where the help and requirements dialogs live.
 */
function glover_document_library_enqueue_dialog_script()
{
    if (!glover_document_library_is_feature_view()) {
        return;
    }

    $dir = __DIR__ . '/assets';
    $url = get_template_directory_uri() . '/includes/features/document-library/assets';
    wp_enqueue_script('glover-document-dialogs', $url . '/dialogs.js', array(), (string) filemtime($dir . '/dialogs.js'), array('in_footer' => true, 'strategy' => 'defer'));
}
add_action('wp_enqueue_scripts', 'glover_document_library_enqueue_dialog_script', 20);

/**
 * Documents have no page of their own. A document's permanent address
 * (/documents/<slug>/) goes straight to its current file, so links keep
 * working when a replacement brings a new file. Unpublished documents go to
 * the permission-checked preview link; a document with no file is a 404.
 */
function glover_document_library_redirect_to_file()
{
    if (!is_singular(GLOVER_DOCUMENT_LIBRARY_POST_TYPE)) {
        return;
    }

    $url = glover_document_library_file_url(get_queried_object_id());

    if (!$url) {
        global $wp_query;
        $wp_query->set_404();
        status_header(404);
        nocache_headers();
        return;
    }

    nocache_headers();
    wp_safe_redirect($url, 302);
    exit;
}
add_action('template_redirect', 'glover_document_library_redirect_to_file', 1);

/**
 * A Download link whose accessible name says which document, its format and size.
 *
 * @param int    $post_id Document ID.
 * @param string $class   CSS class.
 * @param string $text    Visible text.
 * @param bool   $icon    Show a download icon instead of the text, which
 *                        stays for screen readers and as the tooltip.
 */
function glover_document_library_download_link($post_id, $class = 'button button--secondary', $text = 'Download', $icon = false)
{
    $url = glover_document_library_file_url($post_id);

    if (!$url) {
        return '';
    }

    $details = glover_document_library_file_details($post_id);
    $meta = trim($details['label'] . ($details['size'] ? ', ' . $details['size'] : ''));

    // Titles leave out the board and date, which have their own fields, so
    // the spoken name adds them back: "Download Regular Meeting Minutes,
    // Select Board, October 14, 2026 (PDF, 790 B)".
    $name = implode(', ', array_filter(array(wp_specialchars_decode(get_the_title($post_id), ENT_QUOTES), glover_document_library_board_date_text($post_id))));
    $label = $icon
        ? '<svg aria-hidden="true" focusable="false" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v11M7 10l5 5 5-5M5 20h14"></path></svg><span class="visually-hidden">' . esc_html($text) . '</span>'
        : esc_html($text);

    return sprintf(
        '<a class="%s" href="%s" download%s>%s<span class="visually-hidden"> %s%s</span></a>',
        esc_attr($class),
        esc_url($url),
        $icon ? ' title="' . esc_attr($text) . '"' : '',
        $label,
        esc_html($name),
        $meta ? esc_html(' (' . $meta . ')') : ''
    );
}

/**
 * Hidden text naming a document link's format and size, e.g. " (PDF, 240 KB)".
 *
 * @param int $post_id Document ID.
 */
function glover_document_library_link_format_text($post_id)
{
    $details = glover_document_library_file_details($post_id);
    $meta = trim($details['label'] . ($details['size'] ? ', ' . $details['size'] : ''));
    $context = glover_document_library_board_date_text($post_id);
    $text = ($context !== '' ? ', ' . $context : '') . ($meta ? ' (' . $meta . ')' : '');

    return $text !== '' ? '<span class="visually-hidden">' . esc_html($text) . '</span>' : '';
}

/**
 * A document's boards and date as plain text, "Select Board, October 14,
 * 2026", for spoken link names: titles leave both out, since they are
 * their own fields.
 *
 * @param int $post_id Document ID.
 * @return string
 */
function glover_document_library_board_date_text($post_id)
{
    $parts = array_map(function ($board_id) {
        return wp_specialchars_decode(get_the_title($board_id), ENT_QUOTES);
    }, glover_document_library_document_board_ids($post_id));
    $parts[] = glover_document_library_display_date($post_id);

    return implode(', ', array_filter($parts));
}

/**
 * A document's date formatted for people, or ''.
 *
 * @param int    $post_id Document ID.
 * @param string $format  Date format.
 */
function glover_document_library_display_date($post_id, $format = 'M j, Y')
{
    return glover_document_library_format_date(get_field('document_date', $post_id, false), $format);
}

/**
 * Format a stored Ymd date in the site's time zone. Reading it as UTC
 * midnight would show the previous day in Vermont.
 *
 * @param string $raw    Date as saved by ACF (Ymd).
 * @param string $format Date format.
 */
function glover_document_library_format_date($raw, $format = 'M j, Y')
{
    $date = $raw ? DateTimeImmutable::createFromFormat('!Ymd', (string) $raw, wp_timezone()) : false;

    return $date ? wp_date($format, $date->getTimestamp()) : '';
}

/**
 * Whether a document is draft minutes, which the site labels as Draft.
 *
 * @param int $post_id Document ID.
 */
function glover_document_library_is_draft_minutes($post_id)
{
    return get_field('document_minutes_status', $post_id, false) === 'draft';
}

/**
 * The most recent published documents tagged with a board.
 *
 * @param int $board_id Board post ID.
 * @param int $limit    How many.
 * @return WP_Post[]
 */
function glover_document_library_latest_for_board($board_id, $limit = 5)
{
    return get_posts(array(
        'post_type' => GLOVER_DOCUMENT_LIBRARY_POST_TYPE,
        'post_status' => 'publish',
        'posts_per_page' => $limit,
        'no_found_rows' => true,
        'meta_query' => array('document_date' => array('key' => 'document_date', 'compare' => 'EXISTS')),
        'orderby' => array('document_date' => 'DESC', 'date' => 'DESC'),
        'tax_query' => array(array(
            'taxonomy' => GLOVER_DOCUMENT_LIBRARY_BOARD_TAXONOMY,
            'field' => 'slug',
            'terms' => glover_document_library_board_term_slug($board_id),
        )),
    ));
}

/**
 * "Email the Town Clerk at clerk@townofglover.com", the way to ask for a
 * document in an accessible format.
 *
 * @return string HTML.
 */
function glover_document_library_accessible_copy_link()
{
    $email = GLOVER_DOCUMENT_LIBRARY_CLERK_EMAIL;

    return sprintf('Email the Town Clerk at <a href="mailto:%1$s">%1$s</a>', esc_attr($email));
}

/**
 * Help contact details from settings.
 *
 * The emails are those of the users chosen as Help Contacts.
 *
 * @return array{name:string,phone:string,emails:string[]}
 */
function glover_document_library_help_contact()
{
    $emails = array();

    foreach ((array) glover_document_library_setting('help_contacts', array()) as $user_id) {
        $user = get_userdata((int) $user_id);

        if ($user && $user->user_email !== '') {
            $emails[] = $user->user_email;
        }
    }

    return array(
        'name' => (string) glover_document_library_setting('help_contact_name', "Town Clerk's Office"),
        'phone' => (string) glover_document_library_setting('help_contact_phone', ''),
        'emails' => array_values(array_unique($emails)),
    );
}

/**
 * The help contacts' email addresses as escaped mailto links, joined with
 * " or ". Empty when no Help Contacts are chosen.
 */
function glover_document_library_help_contact_links()
{
    $links = array();

    foreach (glover_document_library_help_contact()['emails'] as $email) {
        $links[] = sprintf('<a href="mailto:%1$s">%2$s</a>', esc_attr($email), esc_html($email));
    }

    return implode(' or ', $links);
}

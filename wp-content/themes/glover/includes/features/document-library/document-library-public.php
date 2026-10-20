<?php

if (!defined('ABSPATH')) {
    exit;
}

const GLOVER_DOCUMENT_LIBRARY_REQUEST_TEMPLATE = 'template-accessible-copy-request.php';

/**
 * Whether the current front-end view belongs to the Document Library.
 */
function glover_document_library_is_feature_view()
{
    return is_post_type_archive(GLOVER_DOCUMENT_LIBRARY_POST_TYPE)
        || is_singular(array(GLOVER_DOCUMENT_LIBRARY_POST_TYPE, GLOVER_DOCUMENT_LIBRARY_BOARD_POST_TYPE))
        || is_page_template(array(GLOVER_DOCUMENT_LIBRARY_SUBMIT_TEMPLATE, GLOVER_DOCUMENT_LIBRARY_REQUEST_TEMPLATE));
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

    if (is_page_template(GLOVER_DOCUMENT_LIBRARY_SUBMIT_TEMPLATE)) {
        $minutes = get_term_by('slug', 'minutes', GLOVER_DOCUMENT_LIBRARY_CATEGORY_TAXONOMY);
        wp_enqueue_script('glover-document-submission', $url . '/submission.js', array('acf-input'), (string) filemtime($dir . '/submission.js'), true);
        wp_localize_script('glover-document-submission', 'gloverDocumentSubmission', array(
            'minutesTermId' => $minutes ? (int) $minutes->term_id : 0,
        ));
    }

    if (is_page_template(GLOVER_DOCUMENT_LIBRARY_REQUEST_TEMPLATE) && glover_document_library_setting('turnstile_site_key')) {
        wp_enqueue_script('cloudflare-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, array('strategy' => 'defer'));
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
 * A Download link whose accessible name says which document, its format and size.
 *
 * @param int    $post_id Document ID.
 * @param string $class   CSS class.
 * @param string $text    Visible text.
 */
function glover_document_library_download_link($post_id, $class = 'button button--secondary', $text = 'Download')
{
    $url = glover_document_library_file_url($post_id);

    if (!$url) {
        return '';
    }

    $details = glover_document_library_file_details($post_id);
    $meta = trim($details['label'] . ($details['size'] ? ', ' . $details['size'] : ''));

    return sprintf(
        '<a class="%s" href="%s">%s<span class="visually-hidden"> %s%s</span></a>',
        esc_attr($class),
        esc_url($url),
        esc_html($text),
        esc_html(get_the_title($post_id)),
        $meta ? esc_html(' (' . $meta . ')') : ''
    );
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
 * The page that holds the accessible-copy request form, with a document pre-selected.
 *
 * @param int $post_id Optional document ID.
 */
function glover_document_library_request_url($post_id = 0)
{
    $pages = get_posts(array(
        'post_type' => 'page',
        'post_status' => 'publish',
        'posts_per_page' => 1,
        'fields' => 'ids',
        'meta_key' => '_wp_page_template',
        'meta_value' => GLOVER_DOCUMENT_LIBRARY_REQUEST_TEMPLATE,
        'no_found_rows' => true,
    ));

    if (!$pages) {
        return '';
    }

    return $post_id ? add_query_arg('document', (int) $post_id, get_permalink($pages[0])) : get_permalink($pages[0]);
}

/**
 * Help contact details from settings.
 *
 * @return array{name:string,phone:string,email:string}
 */
function glover_document_library_help_contact()
{
    return array(
        'name' => (string) glover_document_library_setting('help_contact_name', "Town Clerk's Office"),
        'phone' => (string) glover_document_library_setting('help_contact_phone', ''),
        'email' => (string) glover_document_library_setting('help_contact_email', ''),
    );
}

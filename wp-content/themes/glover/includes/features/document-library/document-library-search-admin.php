<?php

if (!defined('ABSPATH')) {
    exit;
}

/*
 * Staff tools for full-text search: index status and Re-index in Review
 * details, the approver's Check text page, the status counts and Re-index
 * all on the settings screen, list views by status, the approver-only
 * exclusion switch, and the `wp glover documents` WP-CLI command.
 */

const GLOVER_DOCUMENT_SEARCH_CHECK_PAGE = 'glover-document-text-check';

/**
 * @param int $post_id Document ID.
 */
function glover_document_search_reindex_url($post_id)
{
    return wp_nonce_url(admin_url('admin-post.php?action=glover_document_reindex&post=' . (int) $post_id), 'glover_document_reindex_' . (int) $post_id);
}

/**
 * @param int $post_id Document ID.
 */
function glover_document_search_check_url($post_id)
{
    return admin_url('admin.php?page=' . GLOVER_DOCUMENT_SEARCH_CHECK_PAGE . '&post=' . (int) $post_id);
}

/**
 * Index status lines for the Review details box.
 *
 * @param WP_Post $post Document.
 */
function glover_document_search_render_review_details($post)
{
    if (!glover_document_library_file_id($post->ID)) {
        return;
    }

    if (glover_document_library_user_can_review()) {
        printf(
            '<p><a class="button" href="%s">Check text</a></p><p class="description">See the text inside the file, with email addresses and excluded words flagged, before you approve.</p>',
            esc_url(glover_document_search_check_url($post->ID))
        );
    }

    if ($post->post_status !== 'publish') {
        return;
    }

    $status = get_post_meta($post->ID, '_glover_text_index_status', true);
    $note = get_post_meta($post->ID, '_glover_text_index_note', true);

    echo '<p><strong>Text search:</strong> ' . esc_html(isset(GLOVER_DOCUMENT_SEARCH_STATUSES[$status]) ? GLOVER_DOCUMENT_SEARCH_STATUSES[$status] : 'Not yet indexed') . '</p>';

    if ($note) {
        echo '<p class="description">' . esc_html($note) . '</p>';
    }

    if (glover_document_library_user_can_review() && $status !== 'excluded') {
        printf('<p><a class="button" href="%s">Re-index text</a></p>', esc_url(glover_document_search_reindex_url($post->ID)));
    }
}

/**
 * Re-index one document now.
 */
function glover_document_search_handle_reindex()
{
    $post_id = isset($_GET['post']) ? absint($_GET['post']) : 0;
    check_admin_referer('glover_document_reindex_' . $post_id);

    if (!glover_document_library_user_can_review() || get_post_type($post_id) !== GLOVER_DOCUMENT_LIBRARY_POST_TYPE) {
        wp_die('You do not have permission to re-index documents.', 'Forbidden', array('response' => 403));
    }

    $status = glover_document_search_index_document($post_id, true);
    glover_document_library_log($post_id, 'reindexed', $status);

    wp_safe_redirect(add_query_arg('glover_notice', 'reindexed', get_edit_post_link($post_id, 'url')));
    exit;
}
add_action('admin_post_glover_document_reindex', 'glover_document_search_handle_reindex');

/**
 * Re-index every published document in the background.
 */
function glover_document_search_handle_reindex_all()
{
    check_admin_referer('glover_document_reindex_all');

    if (!current_user_can('manage_options')) {
        wp_die('You do not have permission to re-index documents.', 'Forbidden', array('response' => 403));
    }

    $count = glover_document_search_queue_all(false);

    wp_safe_redirect(add_query_arg(array('glover_notice' => 'reindex_all', 'glover_count' => $count), admin_url('edit.php?post_type=' . GLOVER_DOCUMENT_LIBRARY_POST_TYPE . '&page=' . GLOVER_DOCUMENT_LIBRARY_SETTINGS_SLUG)));
    exit;
}
add_action('admin_post_glover_document_reindex_all', 'glover_document_search_handle_reindex_all');

/**
 * Notices after re-indexing.
 */
function glover_document_search_admin_notice()
{
    if (empty($_GET['glover_notice'])) {
        return;
    }

    $key = sanitize_key($_GET['glover_notice']);

    if ($key === 'reindexed') {
        echo '<div class="notice notice-success is-dismissible"><p>Text search updated for this document.</p></div>';
    } elseif ($key === 'reindex_all') {
        $count = isset($_GET['glover_count']) ? absint($_GET['glover_count']) : 0;
        printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html(sprintf(
            _n('%s document queued for text search. It runs in the background a few at a time.', '%s documents queued for text search. They run in the background a few at a time.', $count),
            number_format_i18n($count)
        )));
    }
}
add_action('admin_notices', 'glover_document_search_admin_notice');

/**
 * Status counts and Re-index all, shown on the settings screen's Search tab.
 *
 * @param array $field ACF message field.
 * @return array
 */
function glover_document_search_status_message($field)
{
    if (!is_admin() || !function_exists('get_current_screen')) {
        return $field;
    }

    $counts = glover_document_search_status_counts();
    $labels = array_merge(GLOVER_DOCUMENT_SEARCH_STATUSES, array('none' => 'Not yet indexed'));
    $items = array();

    foreach ($labels as $status => $label) {
        if (empty($counts[$status])) {
            continue;
        }

        $url = admin_url('edit.php?post_type=' . GLOVER_DOCUMENT_LIBRARY_POST_TYPE . '&post_status=publish&glover_view=search_' . $status);
        $items[] = sprintf('<li><a href="%s">%s: %s</a></li>', esc_url($url), esc_html($label), esc_html(number_format_i18n($counts[$status])));
    }

    $method = glover_document_search_pdftotext_path()
        ? 'PDFs are read with pdftotext.'
        : 'PDFs are read with the built-in PDF reader (pdftotext is not available on this server).';

    $field['message'] = '<p><strong>Published documents by text search status</strong></p>'
        . ($items ? '<ul>' . implode('', $items) . '</ul>' : '<p>No published documents yet.</p>')
        . '<p>' . esc_html($method) . '</p>'
        . sprintf('<p><a class="button" href="%s">Re-index all documents</a></p>', esc_url(wp_nonce_url(admin_url('admin-post.php?action=glover_document_reindex_all'), 'glover_document_reindex_all')));

    return $field;
}
add_filter('acf/load_field/key=field_glover_document_settings_search_status', 'glover_document_search_status_message');

/**
 * List views by text search status, linked from the settings screen.
 *
 * @param WP_Query $query Admin list query.
 */
function glover_document_search_filter_admin_list($query)
{
    if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== GLOVER_DOCUMENT_LIBRARY_POST_TYPE || empty($_GET['glover_view'])) {
        return;
    }

    $view = sanitize_key($_GET['glover_view']);

    if (strpos($view, 'search_') !== 0) {
        return;
    }

    $status = substr($view, 7);

    if ($status === 'none') {
        $query->set('meta_query', array(array('key' => '_glover_text_index_status', 'compare' => 'NOT EXISTS')));
    } elseif (isset(GLOVER_DOCUMENT_SEARCH_STATUSES[$status])) {
        $query->set('meta_key', '_glover_text_index_status');
        $query->set('meta_value', $status);
    }
}
add_action('pre_get_posts', 'glover_document_search_filter_admin_list');

/**
 * Only approvers and administrators see or change "Exclude from text search".
 *
 * @param array $field Field.
 * @return array|false
 */
function glover_document_search_prepare_exclude_field($field)
{
    return glover_document_library_user_can_review() ? $field : false;
}
add_filter('acf/prepare_field/key=field_glover_document_exclude_from_text_search', 'glover_document_search_prepare_exclude_field');

/**
 * @param mixed      $value   New value.
 * @param int|string $post_id ACF post ID.
 * @param array      $field   Field.
 * @return mixed
 */
function glover_document_search_protect_exclude_field($value, $post_id, $field)
{
    return glover_document_library_user_can_review() ? $value : get_field($field['name'], $post_id, false);
}
add_filter('acf/update_value/key=field_glover_document_exclude_from_text_search', 'glover_document_search_protect_exclude_field', 10, 3);

/**
 * Hidden admin page for the approver's text check.
 */
function glover_document_search_register_check_page()
{
    add_submenu_page('', 'Check text', 'Check text', 'read', GLOVER_DOCUMENT_SEARCH_CHECK_PAGE, 'glover_document_search_render_check_page');
}
add_action('admin_menu', 'glover_document_search_register_check_page');

/**
 * Hidden pages have no menu entry, so give the Check text page its own title.
 *
 * @param string $admin_title Full title.
 * @param string $title       Page title.
 * @return string
 */
function glover_document_search_check_page_title($admin_title, $title)
{
    if (isset($_GET['page']) && $_GET['page'] === GLOVER_DOCUMENT_SEARCH_CHECK_PAGE) {
        return 'Check text' . $admin_title;
    }

    return $admin_title;
}
add_filter('admin_title', 'glover_document_search_check_page_title', 10, 2);

/**
 * Escape a part's text, marking flagged items with their kind in words.
 *
 * @param string $text    Clean text.
 * @param array  $matches From glover_document_search_sensitive_matches().
 */
function glover_document_search_check_html($text, $matches)
{
    $html = '';
    $position = 0;
    $labels = array('email' => 'email address', 'term' => 'excluded word');

    foreach ($matches as $item) {
        $html .= esc_html(substr($text, $position, $item['offset'] - $position));
        $html .= sprintf(
            '<mark class="glover-text-check__flag">%s <span class="glover-text-check__kind">(%s)</span></mark>',
            esc_html($item['text']),
            esc_html($labels[$item['kind']])
        );
        $position = $item['offset'] + $item['length'];
    }

    return $html . esc_html(substr($text, $position));
}

/**
 * Print the Check text page: the file's text page by page, as read now,
 * with what search would leave out flagged. Nothing here is stored.
 */
function glover_document_search_render_check_page()
{
    $post_id = isset($_GET['post']) ? absint($_GET['post']) : 0;

    if (!glover_document_library_user_can_review() || get_post_type($post_id) !== GLOVER_DOCUMENT_LIBRARY_POST_TYPE) {
        wp_die('You do not have permission to check documents.');
    }

    $attachment_id = glover_document_library_file_id($post_id);
    $file = $attachment_id ? get_attached_file($attachment_id) : '';
    $result = glover_document_search_extract($file);
    $redactions = glover_document_search_has_unapplied_redactions($file);
    ?>
    <div class="wrap glover-text-check">
        <h1>Check text: <?php echo esc_html(get_the_title($post_id)); ?></h1>
        <p>
            This is all the text inside the file, read just now. Look for anything that should have been blacked out:
            text hidden under black boxes still shows here, and anyone who downloads the file can copy it.
            <a href="<?php echo esc_url(get_edit_post_link($post_id)); ?>">Back to the document</a>
        </p>

        <?php if ($redactions) : ?>
            <div class="notice notice-error inline"><p><strong>This PDF has redaction marks that were never applied.</strong> The text under them is still in the file. Return it and ask for a properly redacted copy.</p></div>
        <?php endif; ?>

        <?php if (is_wp_error($result)) : ?>
            <div class="notice notice-warning inline"><p><?php echo esc_html($result->get_error_message()); ?></p></div>
        <?php else :
            $flags = array();
            $characters = 0;

            foreach ($result['parts'] as $part) {
                $characters += mb_strlen($part['text']);

                foreach (glover_document_search_sensitive_matches($part['text']) as $item) {
                    $flags[] = array('label' => $part['label'] !== '' ? $part['label'] : 'Document', 'item' => $item);
                }
            }
            ?>
            <?php if ($characters < GLOVER_DOCUMENT_SEARCH_MIN_CHARS_PER_PAGE * max(1, count($result['parts']))) : ?>
                <div class="notice notice-warning inline"><p>This file has almost no text. It is probably a scan, so it can't be read by screen readers or searched. Consider asking for a text-based version.</p></div>
            <?php endif; ?>

            <h2>Flagged</h2>
            <?php if ($flags) : ?>
                <p>These are left out of search, but they stay in the downloadable file:</p>
                <ul class="ul-disc">
                    <?php foreach ($flags as $flag) : ?>
                        <li><?php echo esc_html(sprintf('%s: %s (%s)', $flag['label'], $flag['item']['text'], $flag['item']['kind'] === 'email' ? 'email address' : 'excluded word')); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php else : ?>
                <p>No email addresses or excluded words found.</p>
            <?php endif; ?>

            <h2>Text</h2>
            <?php foreach ($result['parts'] as $index => $part) : ?>
                <h3><?php echo esc_html($part['label'] !== '' ? $part['label'] : 'Document text'); ?></h3>
                <div class="glover-text-check__text">
                    <?php if ($part['text'] === '') : ?>
                        <p><em>No text on this page.</em></p>
                    <?php else : ?>
                        <p><?php echo glover_document_search_check_html($part['text'], glover_document_search_sensitive_matches($part['text'])); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the helper. ?></p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if (get_post_status($post_id) === 'pending') : ?>
            <p>
                <a class="button button-primary" href="<?php echo esc_url(glover_document_library_approve_url($post_id)); ?>">Approve and publish</a>
                <a class="button" href="<?php echo esc_url(glover_document_library_return_url($post_id)); ?>">Return</a>
            </p>
        <?php endif; ?>
    </div>
    <style>
        .glover-text-check__text { max-width: 60rem; background: #fff; border: 1px solid #c3c4c7; padding: 0.25rem 1rem; }
        .glover-text-check__text p { font-size: 14px; line-height: 1.6; }
        .glover-text-check__flag { background: #fcf0a1; font-weight: 600; padding: 0 0.15em; }
        .glover-text-check__kind { font-weight: 400; }
    </style>
    <?php
}

if (defined('WP_CLI') && WP_CLI) {
    /**
     * Full-text search tools for the Document Library.
     */
    class Glover_Document_Search_CLI
    {
        /**
         * Index published documents' text.
         *
         * ## OPTIONS
         *
         * [--all]
         * : Re-read every published document, even unchanged ones.
         *
         * [--post=<id>]
         * : Index one document.
         *
         * ## EXAMPLES
         *
         *     wp glover documents index
         *     wp glover documents index --all
         *     wp glover documents index --post=123
         *
         * @param array $args       Positional arguments.
         * @param array $assoc_args Options.
         */
        public function index($args, $assoc_args)
        {
            if (!empty($assoc_args['post'])) {
                $post_id = absint($assoc_args['post']);
                WP_CLI::log(sprintf('#%d %s: %s', $post_id, get_the_title($post_id), glover_document_search_index_document($post_id, true) ?: 'not indexable'));
                return;
            }

            $force = !empty($assoc_args['all']);
            $ids = get_posts(array(
                'post_type' => GLOVER_DOCUMENT_LIBRARY_POST_TYPE,
                'post_status' => 'publish',
                'fields' => 'ids',
                'posts_per_page' => -1,
                'no_found_rows' => true,
                'orderby' => 'ID',
                'order' => 'DESC',
            ));
            $totals = array();
            $progress = \WP_CLI\Utils\make_progress_bar('Indexing documents', count($ids));

            foreach ($ids as $post_id) {
                $status = glover_document_search_index_document($post_id, $force);
                $totals[$status ?: 'skipped'] = isset($totals[$status ?: 'skipped']) ? $totals[$status ?: 'skipped'] + 1 : 1;
                $progress->tick();
            }

            $progress->finish();

            foreach ($totals as $status => $count) {
                WP_CLI::log(sprintf('%s: %d', $status, $count));
            }

            WP_CLI::success('Done.');
        }

        /**
         * Show counts of published documents by text search status.
         *
         * @subcommand status
         */
        public function status()
        {
            foreach (glover_document_search_status_counts() as $status => $count) {
                WP_CLI::log(sprintf('%s: %d', $status, $count));
            }

            WP_CLI::log('Reader: ' . (glover_document_search_pdftotext_path() ?: 'built-in PDF reader (smalot/pdfparser)'));
        }
    }

    WP_CLI::add_command('glover documents', 'Glover_Document_Search_CLI');
}

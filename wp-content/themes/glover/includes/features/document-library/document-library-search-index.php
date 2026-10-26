<?php

if (!defined('ABSPATH')) {
    exit;
}

/*
 * Full-text search index. Each published document's current file is read in
 * the background and stored in {prefix}glover_document_text: one row per page
 * or sheet, plus a part 0 row (the title and all the text) used for matching.
 *
 * Only published documents are ever indexed. Pending, returned, private and
 * previous-version files never reach the table. Email addresses outside the
 * town's domain and the excluded words and phrases from the settings screen
 * are removed before anything is stored.
 */

const GLOVER_DOCUMENT_SEARCH_DB_VERSION = '1';
const GLOVER_DOCUMENT_SEARCH_HOOK = 'glover_document_search_index';
const GLOVER_DOCUMENT_SEARCH_BATCH_HOOK = 'glover_document_search_batch';
const GLOVER_DOCUMENT_SEARCH_BATCH_SIZE = 20;
const GLOVER_DOCUMENT_SEARCH_MIN_CHARS_PER_PAGE = 25;

const GLOVER_DOCUMENT_SEARCH_STATUSES = array(
    'queued' => 'Queued',
    'indexed' => 'Searchable',
    'no_text' => 'No text (probably a scan)',
    'failed' => 'Failed',
    'excluded' => 'Excluded from text search',
);

/**
 * A status note in words, e.g. "Read with the built-in PDF reader: 12 pages."
 *
 * @param string $method Extraction method.
 * @param int    $count  Pages or sheets.
 * @param string $extra  Optional trailing sentence.
 */
function glover_document_search_note($method, $count, $extra = '')
{
    $readers = array(
        'pdftotext' => 'Read with pdftotext',
        'pdfparser' => 'Read with the built-in PDF reader',
        'docx' => 'Read as a Word file',
        'xlsx' => 'Read as a spreadsheet',
    );
    $unit = $method === 'xlsx' ? _n('sheet', 'sheets', $count) : ($method === 'docx' ? '' : _n('page', 'pages', $count));
    $note = (isset($readers[$method]) ? $readers[$method] : $method) . ($unit ? sprintf(': %d %s.', $count, $unit) : '.');

    return trim($note . ' ' . $extra);
}

/**
 * The index table's name.
 */
function glover_document_search_table()
{
    global $wpdb;

    return $wpdb->prefix . 'glover_document_text';
}

/**
 * Create or update the index table once per schema version.
 */
function glover_document_search_install()
{
    global $wpdb;

    if (get_option('glover_document_search_db_version') === GLOVER_DOCUMENT_SEARCH_DB_VERSION) {
        return;
    }

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // MySQL's built-in stopword list drops words such as "will" and "about".
    // Turning it off for this session means the index keeps every word.
    $wpdb->query('SET SESSION innodb_ft_enable_stopword = 0');

    $table = glover_document_search_table();
    $charset = $wpdb->get_charset_collate();

    dbDelta("CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  post_id bigint(20) unsigned NOT NULL,
  attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
  part smallint(5) unsigned NOT NULL DEFAULT 0,
  part_label varchar(190) NOT NULL DEFAULT '',
  source varchar(20) NOT NULL DEFAULT '',
  content mediumtext NOT NULL,
  PRIMARY KEY  (id),
  KEY post_part (post_id,part),
  FULLTEXT KEY content (content)
) ENGINE=InnoDB {$charset};");

    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
        update_option('glover_document_search_db_version', GLOVER_DOCUMENT_SEARCH_DB_VERSION);
    }
}
add_action('init', 'glover_document_search_install', 5);

/**
 * The town's own email domain, whose addresses stay searchable.
 */
function glover_document_search_town_domain()
{
    $domain = defined('GLOVER_GOOGLE_DOMAIN') && GLOVER_GOOGLE_DOMAIN ? GLOVER_GOOGLE_DOMAIN : 'townofglover.com';

    return strtolower((string) apply_filters('glover_document_search/town_domain', $domain));
}

/**
 * Whether email addresses outside the town are removed (on by default).
 */
function glover_document_search_removes_emails()
{
    if (!function_exists('get_field')) {
        return true;
    }

    $value = get_field('document_library_search_remove_emails', 'option');

    return $value === null || $value === '' || (bool) $value;
}

/**
 * The excluded words and phrases from the settings screen, cleaned:
 * split on commas and new lines, spaces collapsed, entries under three
 * characters dropped.
 *
 * @return string[]
 */
function glover_document_search_excluded_terms()
{
    $raw = function_exists('get_field') ? (string) get_field('document_library_search_excluded_terms', 'option') : '';
    $terms = array();

    foreach (preg_split('/[,\r\n]+/', $raw) as $term) {
        $term = trim(preg_replace('/\s+/u', ' ', $term));

        if (mb_strlen($term) >= 3) {
            $terms[mb_strtolower($term)] = $term;
        }
    }

    return array_values($terms);
}

/**
 * Regex for one excluded term: whole words, any capitals, any spacing.
 *
 * @param string $term Term.
 */
function glover_document_search_term_pattern($term)
{
    $words = array_map(function ($word) {
        return preg_quote($word, '/');
    }, preg_split('/\s+/u', trim($term)));

    return '/(?<![\p{L}\p{N}])' . implode('\s+', $words) . '(?![\p{L}\p{N}])/iu';
}

/**
 * Regex for email addresses.
 */
function glover_document_search_email_pattern()
{
    return '/[\p{L}\p{N}._%+\-]+@(?:[\p{L}\p{N}\-]+\.)+[\p{L}]{2,}/u';
}

/**
 * Whether an email address is the town's own (kept searchable).
 *
 * @param string $email Address.
 */
function glover_document_search_is_town_email($email)
{
    $domain = glover_document_search_town_domain();
    $host = strtolower(substr(strrchr($email, '@'), 1));

    return $host === $domain || substr($host, -strlen('.' . $domain)) === '.' . $domain;
}

/**
 * Find what scrubbing would remove from a text, with byte offsets. Used by
 * the scrubber and by the approver's text check.
 *
 * @param string $text Text.
 * @return array<int, array{offset:int,length:int,kind:string,text:string}>
 */
function glover_document_search_sensitive_matches($text)
{
    $found = array();

    if (glover_document_search_removes_emails() && preg_match_all(glover_document_search_email_pattern(), $text, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $match) {
            $email = rtrim($match[0], '.');

            if (!glover_document_search_is_town_email($email)) {
                $found[] = array('offset' => $match[1], 'length' => strlen($email), 'kind' => 'email', 'text' => $email);
            }
        }
    }

    foreach (glover_document_search_excluded_terms() as $term) {
        if (preg_match_all(glover_document_search_term_pattern($term), $text, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $found[] = array('offset' => $match[1], 'length' => strlen($match[0]), 'kind' => 'term', 'text' => $match[0]);
            }
        }
    }

    usort($found, function ($a, $b) {
        return $a['offset'] - $b['offset'];
    });

    // Drop overlaps, keeping the earliest.
    $kept = array();
    $end = -1;

    foreach ($found as $item) {
        if ($item['offset'] >= $end) {
            $kept[] = $item;
            $end = $item['offset'] + $item['length'];
        }
    }

    return $kept;
}

/**
 * Remove non-town email addresses and excluded terms from text.
 *
 * @param string $text Clean text.
 */
function glover_document_search_scrub($text)
{
    foreach (array_reverse(glover_document_search_sensitive_matches($text)) as $item) {
        $text = substr($text, 0, $item['offset']) . ' ' . substr($text, $item['offset'] + $item['length']);
    }

    return trim(preg_replace('/\s+/u', ' ', $text));
}

/**
 * A fingerprint of everything that changes what gets stored, so a settings
 * change re-indexes documents and an unchanged file is skipped.
 */
function glover_document_search_settings_signature()
{
    return md5(wp_json_encode(array(
        GLOVER_DOCUMENT_SEARCH_EXTRACTOR_VERSION,
        glover_document_search_removes_emails(),
        glover_document_search_town_domain(),
        glover_document_search_excluded_terms(),
        (bool) glover_document_search_pdftotext_path(),
    )));
}

/**
 * Whether a document is set to stay out of text search.
 *
 * @param int $post_id Document ID.
 */
function glover_document_search_is_excluded($post_id)
{
    return (bool) get_post_meta($post_id, 'document_exclude_from_text_search', true);
}

/**
 * Set a document's index status and note.
 *
 * @param int    $post_id Document ID.
 * @param string $status  Key of GLOVER_DOCUMENT_SEARCH_STATUSES, or '' to clear.
 * @param string $note    Detail.
 */
function glover_document_search_set_status($post_id, $status, $note = '')
{
    if ($status === '') {
        delete_post_meta($post_id, '_glover_text_index_status');
        delete_post_meta($post_id, '_glover_text_index_note');
        delete_post_meta($post_id, '_glover_text_index_hash');
        return;
    }

    update_post_meta($post_id, '_glover_text_index_status', $status);
    update_post_meta($post_id, '_glover_text_index_note', $note);
}

/**
 * Remove a document's rows from the index.
 *
 * @param int $post_id Document ID.
 */
function glover_document_search_delete_rows($post_id)
{
    global $wpdb;

    $wpdb->delete(glover_document_search_table(), array('post_id' => (int) $post_id), array('%d'));
}

/**
 * Whether a document may be in the index right now.
 *
 * @param int $post_id Document ID.
 */
function glover_document_search_is_indexable($post_id)
{
    $attachment_id = glover_document_library_file_id($post_id);

    return get_post_type($post_id) === GLOVER_DOCUMENT_LIBRARY_POST_TYPE
        && get_post_status($post_id) === 'publish'
        && !get_post_meta($post_id, '_glover_merged_into', true)
        && $attachment_id
        && !glover_document_library_attachment_is_private($attachment_id);
}

/**
 * Read a document's current file and store its text. Runs in the background
 * job, the batch runner and WP-CLI.
 *
 * @param int  $post_id Document ID.
 * @param bool $force   Re-read even if the file and settings are unchanged.
 * @return string The resulting status.
 */
function glover_document_search_index_document($post_id, $force = false)
{
    global $wpdb;

    $post_id = (int) $post_id;

    if (!glover_document_search_is_indexable($post_id)) {
        glover_document_search_delete_rows($post_id);
        glover_document_search_set_status($post_id, '');
        return '';
    }

    if (glover_document_search_is_excluded($post_id)) {
        glover_document_search_delete_rows($post_id);
        glover_document_search_set_status($post_id, 'excluded');
        return 'excluded';
    }

    $attachment_id = glover_document_library_file_id($post_id);
    $file = get_attached_file($attachment_id);
    $hash = ($file && is_readable($file)) ? sha1_file($file) . ':' . glover_document_search_settings_signature() : '';
    $status = get_post_meta($post_id, '_glover_text_index_status', true);

    if (!$force && $hash && $hash === get_post_meta($post_id, '_glover_text_index_hash', true) && in_array($status, array('indexed', 'no_text'), true)) {
        return $status;
    }

    if (in_array(strtolower((string) pathinfo((string) $file, PATHINFO_EXTENSION)), array('jpg', 'jpeg'), true)) {
        glover_document_search_delete_rows($post_id);
        glover_document_search_set_status($post_id, 'no_text', 'This is an image, so it has no text to search. It can still be found by its title.');
        update_post_meta($post_id, '_glover_text_index_hash', $hash);
        return 'no_text';
    }

    $result = glover_document_search_extract($file);

    if (is_wp_error($result)) {
        glover_document_search_delete_rows($post_id);
        glover_document_search_set_status($post_id, 'failed', $result->get_error_message());
        update_post_meta($post_id, '_glover_text_index_hash', $hash);
        return 'failed';
    }

    $parts = array();
    $characters = 0;

    foreach ($result['parts'] as $part) {
        $text = glover_document_search_scrub($part['text']);
        $characters += mb_strlen($text);
        $parts[] = array('label' => $part['label'], 'text' => $text);
    }

    $pages = max(1, count($parts));
    $is_pdf = $result['method'] === 'pdftotext' || $result['method'] === 'pdfparser';

    if (($is_pdf && $characters / $pages < GLOVER_DOCUMENT_SEARCH_MIN_CHARS_PER_PAGE) || (!$is_pdf && $characters < GLOVER_DOCUMENT_SEARCH_MIN_CHARS_PER_PAGE)) {
        glover_document_search_delete_rows($post_id);
        glover_document_search_set_status($post_id, 'no_text', glover_document_search_note($result['method'], $pages, 'There is almost no text, so it is probably a scan. It can still be found by its title.'));
        update_post_meta($post_id, '_glover_text_index_hash', $hash);
        return 'no_text';
    }

    $table = glover_document_search_table();
    $title = glover_document_search_scrub(glover_document_search_clean_text(get_post_field('post_title', $post_id, 'raw')));
    $whole = $title . ' ' . implode(' ', wp_list_pluck($parts, 'text'));

    $wpdb->query('START TRANSACTION');
    $wpdb->delete($table, array('post_id' => $post_id), array('%d'));
    $ok = $wpdb->insert($table, array(
        'post_id' => $post_id,
        'attachment_id' => $attachment_id,
        'part' => 0,
        'part_label' => '',
        'source' => $result['method'],
        'content' => $whole,
    ), array('%d', '%d', '%d', '%s', '%s', '%s'));

    foreach ($parts as $index => $part) {
        if ($part['text'] === '') {
            continue;
        }

        $ok = $ok && $wpdb->insert($table, array(
            'post_id' => $post_id,
            'attachment_id' => $attachment_id,
            'part' => $index + 1,
            'part_label' => mb_substr($part['label'], 0, 190),
            'source' => $result['method'],
            'content' => $part['text'],
        ), array('%d', '%d', '%d', '%s', '%s', '%s'));
    }

    if (!$ok) {
        $wpdb->query('ROLLBACK');
        glover_document_search_set_status($post_id, 'failed', 'The text could not be saved: ' . $wpdb->last_error);
        return 'failed';
    }

    $wpdb->query('COMMIT');
    glover_document_search_set_status($post_id, 'indexed', glover_document_search_note($result['method'], $pages));
    update_post_meta($post_id, '_glover_text_index_hash', $hash);

    return 'indexed';
}

/**
 * Queue one document for indexing in the background.
 *
 * @param int $post_id Document ID.
 */
function glover_document_search_queue($post_id)
{
    $post_id = (int) $post_id;

    if (!glover_document_search_is_indexable($post_id)) {
        return;
    }

    glover_document_search_set_status($post_id, glover_document_search_is_excluded($post_id) ? 'excluded' : 'queued');

    if (!wp_next_scheduled(GLOVER_DOCUMENT_SEARCH_HOOK, array($post_id))) {
        wp_schedule_single_event(time(), GLOVER_DOCUMENT_SEARCH_HOOK, array($post_id));
    }
}

add_action(GLOVER_DOCUMENT_SEARCH_HOOK, function ($post_id) {
    glover_document_search_index_document((int) $post_id);
});

/**
 * Mark every published document for (re)indexing and start the batch runner.
 *
 * @param bool $only_missing Only documents never indexed, or whose file or settings changed.
 * @return int Documents queued.
 */
function glover_document_search_queue_all($only_missing = false)
{
    $ids = get_posts(array(
        'post_type' => GLOVER_DOCUMENT_LIBRARY_POST_TYPE,
        'post_status' => 'publish',
        'fields' => 'ids',
        'posts_per_page' => -1,
        'no_found_rows' => true,
        'orderby' => 'ID',
        'order' => 'DESC',
    ));
    $count = 0;
    $signature = glover_document_search_settings_signature();

    foreach ($ids as $post_id) {
        $hash = (string) get_post_meta($post_id, '_glover_text_index_hash', true);

        if ($only_missing && $hash !== '' && substr($hash, -strlen($signature)) === $signature) {
            continue;
        }

        update_post_meta($post_id, '_glover_text_index_status', 'queued');
        $count++;
    }

    if ($count) {
        glover_document_search_schedule_batch();
    }

    return $count;
}

/**
 * Schedule the next batch run.
 *
 * @param int $delay Seconds.
 */
function glover_document_search_schedule_batch($delay = 0)
{
    if (!wp_next_scheduled(GLOVER_DOCUMENT_SEARCH_BATCH_HOOK)) {
        wp_schedule_single_event(time() + $delay, GLOVER_DOCUMENT_SEARCH_BATCH_HOOK);
    }
}

/**
 * Index the next few queued documents, then reschedule while any remain.
 * Small batches keep each cron request short on shared hosting.
 *
 * @param int $limit Documents per run.
 * @return int Documents processed.
 */
function glover_document_search_run_batch($limit = GLOVER_DOCUMENT_SEARCH_BATCH_SIZE)
{
    $ids = glover_document_search_queued_ids($limit);

    foreach ($ids as $post_id) {
        glover_document_search_index_document($post_id);
    }

    if (glover_document_search_queued_ids(1)) {
        glover_document_search_schedule_batch(30);
    }

    return count($ids);
}
add_action(GLOVER_DOCUMENT_SEARCH_BATCH_HOOK, function () {
    glover_document_search_run_batch();
});

/**
 * Published documents waiting to be indexed, newest first.
 *
 * @param int $limit How many.
 * @return int[]
 */
function glover_document_search_queued_ids($limit)
{
    return array_map('intval', get_posts(array(
        'post_type' => GLOVER_DOCUMENT_LIBRARY_POST_TYPE,
        'post_status' => 'publish',
        'fields' => 'ids',
        'posts_per_page' => $limit,
        'no_found_rows' => true,
        'meta_key' => '_glover_text_index_status',
        'meta_value' => 'queued',
        'orderby' => 'ID',
        'order' => 'DESC',
    )));
}

/**
 * Counts of published documents by index status, for the settings screen.
 *
 * @return array<string, int> Status key (or 'none') => count.
 */
function glover_document_search_status_counts()
{
    global $wpdb;

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT COALESCE(m.meta_value, 'none') AS status, COUNT(*) AS total
         FROM {$wpdb->posts} p
         LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_glover_text_index_status'
         WHERE p.post_type = %s AND p.post_status = 'publish'
         GROUP BY status",
        GLOVER_DOCUMENT_LIBRARY_POST_TYPE
    ));
    $counts = array();

    foreach ($rows as $row) {
        $counts[$row->status] = (int) $row->total;
    }

    return $counts;
}

// Lifecycle: publish, replace, unpublish, trash and delete.

add_action('glover_document_library/document_published', 'glover_document_search_queue');

add_action('glover_document_library/document_replaced', function ($post_id) {
    // The old file's text goes now; previous versions are never indexed.
    glover_document_search_delete_rows($post_id);
    glover_document_search_queue($post_id);
});

add_action('glover_document_library/document_unpublished', function ($post_id) {
    glover_document_search_delete_rows($post_id);
    glover_document_search_set_status($post_id, '');
});

/**
 * Remove a document's text when it is trashed or deleted.
 *
 * @param int $post_id Post ID.
 */
function glover_document_search_forget_post($post_id)
{
    if (get_post_type($post_id) === GLOVER_DOCUMENT_LIBRARY_POST_TYPE) {
        glover_document_search_delete_rows($post_id);
        glover_document_search_set_status($post_id, '');
    }
}
add_action('wp_trash_post', 'glover_document_search_forget_post');
add_action('before_delete_post', 'glover_document_search_forget_post');

/**
 * After a document's fields save in wp-admin: apply the exclusion switch
 * and pick up a file swapped directly on a published document.
 *
 * @param int|string $post_id ACF post ID.
 */
function glover_document_search_after_fields_saved($post_id)
{
    if ($post_id === 'options') {
        glover_document_search_maybe_reindex_for_settings();
        return;
    }

    if (!is_numeric($post_id) || get_post_type((int) $post_id) !== GLOVER_DOCUMENT_LIBRARY_POST_TYPE || get_post_status((int) $post_id) !== 'publish') {
        return;
    }

    $post_id = (int) $post_id;
    $status = get_post_meta($post_id, '_glover_text_index_status', true);
    $indexed_attachment = $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare(
        'SELECT attachment_id FROM ' . glover_document_search_table() . ' WHERE post_id = %d AND part = 0',
        $post_id
    ));

    if (glover_document_search_is_excluded($post_id)) {
        if ($status !== 'excluded') {
            glover_document_search_delete_rows($post_id);
            glover_document_search_set_status($post_id, 'excluded');
        }
    } elseif ($status === 'excluded' || ($indexed_attachment && (int) $indexed_attachment !== glover_document_library_file_id($post_id))) {
        glover_document_search_queue($post_id);
    }
}
add_action('acf/save_post', 'glover_document_search_after_fields_saved', 20);

/**
 * Re-index everything when the scrubbing settings change.
 */
function glover_document_search_maybe_reindex_for_settings()
{
    $signature = glover_document_search_settings_signature();

    if (get_option('glover_document_search_signature') === $signature) {
        return;
    }

    update_option('glover_document_search_signature', $signature, false);
    glover_document_search_queue_all(true);
}

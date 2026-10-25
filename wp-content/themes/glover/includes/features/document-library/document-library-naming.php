<?php

if (!defined('ABSPATH')) {
    exit;
}

/*
 * How documents are titled and their files named.
 *
 * Title: what the document is, with no board or date, since both are their
 * own fields and show beside it. On every save (the submission form or
 * wp-admin) the title is tidied by these rules, in order:
 *
 * 1. Remove the name of each board chosen for the document, and "Town of
 *    Glover" (also "Glover" at the very start).
 * 2. Remove the document's own date, written any common way: "December 11,
 *    2022", "Dec. 11th, 2022", "11 December 2022", "12/11/2022", "12/11/22",
 *    "12-11-2022", "2022-12-11". Other dates and lone years stay, so
 *    "Fiscal Year 2026 Budget" and "2025 Annual Town Report" keep theirs.
 * 3. Keep the category's words: "Regular Meeting Minutes" stays as it is.
 * 4. Tidy what's left: drop separators left at either end (",", "-", ":",
 *    "|"), connecting words left at the start ("of", "for", "on", "from",
 *    "dated", "by") or the end (the same, plus "the", "a", "an"), and empty
 *    brackets; close up spaces; capitalize the first letter.
 * 5. If nothing is left, use the category, for example "Minutes".
 *
 * A changed title is recorded in the document's history with the original.
 *
 * File name: board_category_date_title, lower case with hyphens inside each
 * part, for example "select-board_minutes_2026-10-14_regular-meeting-minutes.pdf".
 * Draft minutes have "Draft Minutes" as their category part
 * ("select-board_draft-minutes_..."), here and in the Replace an Earlier
 * Version label; approving them renames the file on the next save.
 * Later versions add "_v2", "_v3" and so on (the first version has none).
 * The stored file is renamed to it on every save and when a new version
 * replaces it, so downloads and the Google Drive backup share one name;
 * earlier versions keep the names they had. The first board is used when
 * there are several.
 */

/**
 * Tidy a title by the rules above.
 *
 * @param string   $title       Title as entered.
 * @param string[] $board_names Names of the document's boards.
 * @param string   $date        The document's date, Ymd.
 * @param string   $category    Category name, the fallback when nothing is left.
 * @return string
 */
function glover_document_library_tidy_title($title, $board_names, $date, $category = '')
{
    $title = html_entity_decode((string) $title, ENT_QUOTES);

    // 1. Board names, longest first so "Select Board" goes before "Board".
    $names = array_filter(array_map(function ($name) {
        return trim(html_entity_decode($name, ENT_QUOTES));
    }, (array) $board_names));
    usort($names, function ($a, $b) {
        return strlen($b) - strlen($a);
    });

    foreach ($names as $name) {
        // "&" and "and" count as the same, and spaces may vary.
        $words = array_map(function ($word) {
            return in_array(strtolower($word), array('&', 'and'), true) ? '(?:&|and)' : preg_quote($word, '/');
        }, preg_split('/\s+/', $name));
        $title = preg_replace('/(?<!\w)' . implode('\s+', $words) . '(?:\'s)?(?!\w)/iu', ' ', $title);
    }

    $title = preg_replace('/\bTown of Glover\b/iu', ' ', $title);
    $title = preg_replace('/^\s*Glover\b/iu', ' ', $title);

    // 2. The document's own date.
    if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', (string) $date, $m)) {
        $year = $m[1];
        $month = (int) $m[2];
        $day = (int) $m[3];
        $month_name = gmdate('F', gmmktime(0, 0, 0, $month, 1, 2000));
        $month_short = substr($month_name, 0, 3);
        $months = '(?:' . $month_name . '|' . $month_short . '\.?' . ($month_name === 'September' ? '|Sept\.?' : '') . ')';
        $day_pattern = '0?' . $day . '(?:st|nd|rd|th)?';
        $year_pattern = '(?:' . $year . '|\'?' . substr($year, 2) . ')';
        $patterns = array(
            '/\b' . $months . '\s+' . $day_pattern . ',?\s+' . $year . '\b/iu',
            '/\b' . $day_pattern . '\s+' . $months . ',?\s+' . $year . '\b/iu',
            '/\b0?' . $month . '[\/.\-]0?' . $day . '[\/.\-]' . $year_pattern . '\b/u',
            '/\b' . $year . '[\/.\-]0?' . $month . '[\/.\-]0?' . $day . '\b/u',
        );

        foreach ($patterns as $pattern) {
            $title = preg_replace($pattern, ' ', $title);
        }
    }

    // 4. Tidy the edges and spacing.
    $title = preg_replace('/\(\s*\)|\[\s*\]/u', ' ', $title);
    $title = preg_replace('/\s+/u', ' ', $title);
    $marks = '[\s,;:|\/\-\x{2013}\x{2014}.]+';
    $start = '(?:' . $marks . '|\b(?:of|for|on|from|dated|by)\b)';
    $end = '(?:' . $marks . '|\b(?:of|for|on|from|dated|by|the|a|an)\b)';
    $previous = null;

    while ($previous !== $title) {
        $previous = $title;
        $title = preg_replace('/^' . $start . '+|' . $end . '+$/iu', '', trim($title));
    }

    $title = preg_replace('/\s+([,:;])/u', '$1', $title);
    $title = preg_replace('/([,:;])\1+/u', '$1', $title);
    $title = trim($title);

    // 5. Nothing left: the category.
    if ($title === '') {
        $title = html_entity_decode((string) $category, ENT_QUOTES);
    }

    return $title === '' ? '' : mb_strtoupper(mb_substr($title, 0, 1)) . mb_substr($title, 1);
}

/**
 * A document's board names, category name ("Draft Minutes" for draft
 * minutes) and raw date, for the rules.
 *
 * @param int $post_id Document ID.
 * @return array{boards:string[],category:string,date:string}
 */
function glover_document_library_naming_parts($post_id)
{
    $categories = get_the_terms($post_id, GLOVER_DOCUMENT_LIBRARY_CATEGORY_TAXONOMY);
    $category = ($categories && !is_wp_error($categories)) ? $categories[0] : null;
    $category_name = $category ? $category->name : '';

    // Draft minutes say so wherever the category is named.
    if ($category && $category->slug === 'minutes' && glover_document_library_is_draft_minutes($post_id)) {
        $category_name = 'Draft ' . $category_name;
    }

    return array(
        'boards' => array_map(function ($board_id) {
            return (string) get_post_field('post_title', $board_id, 'raw');
        }, glover_document_library_document_board_ids($post_id)),
        'category' => $category_name,
        'date' => (string) get_field('document_date', $post_id, false),
    );
}

/**
 * The file name for a document, without extension:
 * board_category_date_title.
 *
 * @param int $post_id Document ID.
 * @return string
 */
function glover_document_library_file_basename($post_id)
{
    $parts = glover_document_library_naming_parts($post_id);
    $board = $parts['boards'] ? $parts['boards'][0] : 'no-board';
    $date = $parts['date'] ? glover_document_library_format_date($parts['date'], 'Y-m-d') : wp_date('Y-m-d');
    $title = substr(sanitize_title(get_post_field('post_title', $post_id, 'raw')), 0, 80);
    $versions = count(glover_document_library_meta_list($post_id, '_glover_previous_files'));

    return implode('_', array_filter(array(
        sanitize_title(html_entity_decode($board, ENT_QUOTES)),
        sanitize_title(html_entity_decode($parts['category'] !== '' ? $parts['category'] : 'uncategorized', ENT_QUOTES)),
        $date,
        rtrim($title, '-'),
        $versions ? 'v' . ($versions + 1) : '',
    )));
}

/**
 * Rename a document's stored file to its file name, keeping it in the same
 * folder (public or private).
 *
 * @param int $post_id Document ID.
 */
function glover_document_library_rename_file($post_id)
{
    $attachment_id = glover_document_library_file_id($post_id);
    $current = $attachment_id ? get_attached_file($attachment_id) : '';

    if (!$current || !file_exists($current)) {
        return;
    }

    $ext = strtolower(pathinfo($current, PATHINFO_EXTENSION));
    $wanted = glover_document_library_file_basename($post_id) . ($ext ? '.' . $ext : '');

    $dir = dirname($current);

    // "-1" and up is only kept while another file in the folder has the
    // name, such as a returned copy of the same document in the private
    // folder; once the name is free (on publishing, say) the file takes it.
    if (basename($current) === $wanted
        || (preg_match('/^' . preg_quote(pathinfo($wanted, PATHINFO_FILENAME), '/') . '-\d+\.' . preg_quote($ext, '/') . '$/', basename($current)) && file_exists($dir . '/' . $wanted))) {
        return;
    }

    $target = $dir . '/' . wp_unique_filename($dir, $wanted);

    if (!@rename($current, $target)) {
        return;
    }

    $metadata = wp_get_attachment_metadata($attachment_id);

    if (is_array($metadata)) {
        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size => $info) {
                if (empty($info['file']) || !file_exists($dir . '/' . $info['file'])) {
                    continue;
                }

                $size_name = wp_unique_filename($dir, pathinfo($target, PATHINFO_FILENAME) . '-' . $size . '.' . $ext);
                @rename($dir . '/' . $info['file'], $dir . '/' . $size_name);
                $metadata['sizes'][$size]['file'] = $size_name;
            }
        }

        $metadata['file'] = _wp_relative_upload_path($target);
        wp_update_attachment_metadata($attachment_id, $metadata);
    }

    update_attached_file($attachment_id, $target);
}

/**
 * On every save: tidy the title, then name the file after it.
 *
 * @param int|string $post_id ACF post ID.
 */
function glover_document_library_apply_naming($post_id)
{
    if (!is_numeric($post_id) || get_post_type((int) $post_id) !== GLOVER_DOCUMENT_LIBRARY_POST_TYPE) {
        return;
    }

    $post_id = (int) $post_id;
    $title = get_post_field('post_title', $post_id, 'raw');
    $parts = glover_document_library_naming_parts($post_id);
    $tidy = glover_document_library_tidy_title($title, $parts['boards'], $parts['date'], $parts['category']);

    if ($tidy !== '' && $tidy !== html_entity_decode($title, ENT_QUOTES)) {
        remove_action('acf/save_post', 'glover_document_library_apply_naming', 16);
        wp_update_post(array('ID' => $post_id, 'post_title' => $tidy));
        add_action('acf/save_post', 'glover_document_library_apply_naming', 16);
        glover_document_library_log($post_id, 'title tidied', 'from "' . $title . '"');
    }

    glover_document_library_rename_file($post_id);
}
add_action('acf/save_post', 'glover_document_library_apply_naming', 16);

// A new version replaced the file: name it, with its version number, before
// the Drive backup copies it.
add_action('glover_document_library/document_replaced', function ($post_id) {
    glover_document_library_rename_file((int) $post_id);
}, 5);

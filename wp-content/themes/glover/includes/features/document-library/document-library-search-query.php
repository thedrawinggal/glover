<?php

if (!defined('ABSPATH')) {
    exit;
}

/*
 * Full-text matching, ranking and snippets for the library's search box.
 *
 * A document matches when every search word is in its title (as before) or
 * its indexed text, which includes the title. "Best match" ranks documents
 * with every word in the title first, then some words in the title, then
 * by how strongly the text matches, then newest first.
 */

const GLOVER_DOCUMENT_SEARCH_SNIPPETS = 2;
const GLOVER_DOCUMENT_SEARCH_SNIPPET_BEFORE = 110;
const GLOVER_DOCUMENT_SEARCH_SNIPPET_AFTER = 150;

// MySQL ignores shorter words in a full-text index; they still match titles.
const GLOVER_DOCUMENT_SEARCH_MIN_WORD = 3;

/**
 * Split each search term into words, dropping full-text operator characters.
 *
 * @param string[] $terms Search terms from WP_Query (quoted phrases stay whole).
 * @return array<int, string[]> One list of words per term.
 */
function glover_document_search_term_words($terms)
{
    $words = array();

    foreach ((array) $terms as $term) {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower((string) $term), -1, PREG_SPLIT_NO_EMPTY);

        if ($parts) {
            $words[] = $parts;
        }
    }

    return $words;
}

/**
 * A MySQL Boolean-mode query. Single words become +word* (required, prefix
 * match); terms with several words become a required phrase. Words too
 * short for the index are left out.
 *
 * @param string[] $terms    Search terms.
 * @param bool     $required Whether every term is required (false gives an OR query for snippets).
 */
function glover_document_search_boolean_query($terms, $required = true)
{
    $clauses = array();
    $prefix = $required ? '+' : '';

    foreach (glover_document_search_term_words($terms) as $words) {
        $words = array_values(array_filter($words, function ($word) {
            return mb_strlen($word) >= GLOVER_DOCUMENT_SEARCH_MIN_WORD;
        }));

        if (count($words) === 1) {
            $clauses[] = $prefix . $words[0] . '*';
        } elseif ($words) {
            $clauses[] = $prefix . '"' . implode(' ', $words) . '"';
        }
    }

    return implode(' ', $clauses);
}

/**
 * Regex that finds the search words in text for highlighting: each word at
 * the start of a word, with any ending ("zoning" also marks "zonings").
 *
 * @param string[] $terms Search terms.
 */
function glover_document_search_highlight_pattern($terms)
{
    $alternatives = array();

    foreach (glover_document_search_term_words($terms) as $words) {
        $alternatives[] = implode('[^\p{L}\p{N}]+', array_map(function ($word) {
            return preg_quote($word, '/');
        }, $words));
    }

    if (!$alternatives) {
        return '';
    }

    usort($alternatives, function ($a, $b) {
        return strlen($b) - strlen($a);
    });

    return '/(?<![\p{L}\p{N}])(?:' . implode('|', $alternatives) . ')[\p{L}\p{N}]*/iu';
}

/**
 * Escape text and wrap the search words in <mark>.
 *
 * @param string   $text  Plain text.
 * @param string[] $terms Search terms.
 * @return string Safe HTML.
 */
function glover_document_search_highlight($text, $terms)
{
    $pattern = $terms ? glover_document_search_highlight_pattern($terms) : '';

    if ($pattern === '') {
        return esc_html($text);
    }

    $pieces = preg_split($pattern, $text, -1, PREG_SPLIT_OFFSET_CAPTURE);
    preg_match_all($pattern, $text, $matches);
    $html = '';

    foreach ($pieces as $index => $piece) {
        $html .= esc_html($piece[0]);

        if (isset($matches[0][$index])) {
            $html .= '<mark>' . esc_html($matches[0][$index]) . '</mark>';
        }
    }

    return $html;
}

/**
 * The current request's search terms, as WP_Query parsed them.
 *
 * @param WP_Query $query Query.
 * @return string[]
 */
function glover_document_search_query_terms($query)
{
    $terms = $query->get('search_terms');

    return is_array($terms) ? array_values(array_filter($terms, 'strlen')) : array();
}

/**
 * SQL for "every term in the title" or "some term in the title".
 *
 * @param string[] $terms Search terms.
 * @param string   $glue  AND or OR.
 */
function glover_document_search_title_sql($terms, $glue)
{
    global $wpdb;

    $clauses = array();

    foreach ($terms as $term) {
        $clauses[] = $wpdb->prepare("{$wpdb->posts}.post_title LIKE %s", '%' . $wpdb->esc_like($term) . '%');
    }

    return $clauses ? '(' . implode(' ' . $glue . ' ', $clauses) . ')' : '0';
}

/**
 * Match titles or indexed text. Replaces WordPress's own search clause for
 * library queries.
 *
 * @param string   $search SQL search clause.
 * @param WP_Query $query  Query.
 * @return string
 */
function glover_document_search_posts_search($search, $query)
{
    if (!$query->get('glover_title_search') || $query->get('s') === '') {
        return $search;
    }

    $terms = glover_document_search_query_terms($query);

    if (!$terms) {
        return $search;
    }

    $clause = glover_document_search_title_sql($terms, 'AND');

    if (glover_document_search_boolean_query($terms) !== '') {
        $clause = '(' . $clause . ' OR glover_text.post_id IS NOT NULL)';
    }

    return ' AND ' . $clause . ' ';
}
add_filter('posts_search', 'glover_document_search_posts_search', 10, 2);

/**
 * Join each document's text score (part 0 only, so one row per document).
 *
 * @param string   $join  SQL joins.
 * @param WP_Query $query Query.
 * @return string
 */
function glover_document_search_posts_join($join, $query)
{
    global $wpdb;

    if (!$query->get('glover_title_search') || $query->get('s') === '') {
        return $join;
    }

    $boolean = glover_document_search_boolean_query(glover_document_search_query_terms($query));

    if ($boolean === '') {
        return $join;
    }

    $table = glover_document_search_table();

    return $join . $wpdb->prepare(
        " LEFT JOIN (SELECT post_id, MATCH(content) AGAINST(%s IN BOOLEAN MODE) AS score
                     FROM {$table}
                     WHERE part = 0 AND MATCH(content) AGAINST(%s IN BOOLEAN MODE)) AS glover_text
          ON glover_text.post_id = {$wpdb->posts}.ID ",
        $boolean,
        $boolean
    );
}
add_filter('posts_join', 'glover_document_search_posts_join', 10, 2);

/**
 * Best match: title matches first, then text relevance, then the date order
 * WP_Query already built.
 *
 * @param string   $orderby SQL ORDER BY.
 * @param WP_Query $query   Query.
 * @return string
 */
function glover_document_search_posts_orderby($orderby, $query)
{
    if (!$query->get('glover_title_search') || $query->get('s') === '' || $query->get('glover_sort') !== 'relevance') {
        return $orderby;
    }

    $terms = glover_document_search_query_terms($query);

    if (!$terms) {
        return $orderby;
    }

    $rank = sprintf(
        '(CASE WHEN %s THEN 2 WHEN %s THEN 1 ELSE 0 END) DESC',
        glover_document_search_title_sql($terms, 'AND'),
        glover_document_search_title_sql($terms, 'OR')
    );

    if (glover_document_search_boolean_query($terms) !== '') {
        $rank .= ', COALESCE(glover_text.score, 0) DESC';
    }

    return $rank . ($orderby ? ', ' . $orderby : '');
}
add_filter('posts_orderby', 'glover_document_search_posts_orderby', 10, 2);

/**
 * Up to two snippets for a document: the best-matching pages, in page order.
 *
 * @param int      $post_id Document ID.
 * @param string[] $terms   Search terms.
 * @return array<int, array{label:string,part:int,kind:string,html:string}> kind is page, sheet or document.
 */
function glover_document_search_snippets($post_id, $terms)
{
    global $wpdb;

    $boolean = glover_document_search_boolean_query($terms, false);

    if ($boolean === '' || !$terms) {
        return array();
    }

    $table = glover_document_search_table();
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT part, part_label, source, content, MATCH(content) AGAINST(%s IN BOOLEAN MODE) AS score
         FROM {$table}
         WHERE post_id = %d AND part > 0 AND MATCH(content) AGAINST(%s IN BOOLEAN MODE)
         ORDER BY score DESC, part ASC
         LIMIT %d",
        $boolean,
        $post_id,
        $boolean,
        GLOVER_DOCUMENT_SEARCH_SNIPPETS
    ));

    usort($rows, function ($a, $b) {
        return (int) $a->part - (int) $b->part;
    });

    $pattern = glover_document_search_highlight_pattern($terms);
    $snippets = array();

    foreach ($rows as $row) {
        $excerpt = glover_document_search_excerpt($row->content, $pattern);

        if ($excerpt === '') {
            continue;
        }

        $is_pdf = in_array($row->source, array('pdftotext', 'pdfparser'), true);

        $snippets[] = array(
            'label' => $row->part_label,
            'part' => (int) $row->part,
            'kind' => $is_pdf ? 'page' : ($row->source === 'xlsx' ? 'sheet' : 'document'),
            'html' => glover_document_search_highlight($excerpt, $terms),
        );
    }

    return $snippets;
}

/**
 * About 15 words either side of the first match, cut at word boundaries,
 * with an ellipsis where text was left out.
 *
 * @param string $text    Page text.
 * @param string $pattern Highlight regex.
 */
function glover_document_search_excerpt($text, $pattern)
{
    if ($pattern === '' || !preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE)) {
        return '';
    }

    $offset = $match[0][1];
    $start = max(0, $offset - GLOVER_DOCUMENT_SEARCH_SNIPPET_BEFORE);
    $end = min(strlen($text), $offset + strlen($match[0][0]) + GLOVER_DOCUMENT_SEARCH_SNIPPET_AFTER);

    if ($start > 0) {
        $space = strpos($text, ' ', $start);
        $start = ($space !== false && $space < $offset) ? $space + 1 : $start;
    }

    if ($end < strlen($text)) {
        $space = strrpos(substr($text, 0, $end), ' ');
        $end = ($space !== false && $space > $offset) ? $space : $end;
    }

    $excerpt = trim(mb_strcut($text, $start, $end - $start, 'UTF-8'));
    $excerpt = rtrim($excerpt, " ,;:");

    return ($start > 0 ? '…' : '') . $excerpt . ($end < strlen($text) ? '…' : '');
}

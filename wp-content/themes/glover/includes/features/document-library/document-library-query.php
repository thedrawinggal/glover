<?php

if (!defined('ABSPATH')) {
    exit;
}

const GLOVER_DOCUMENT_LIBRARY_PER_PAGE = 20;

/**
 * Read and clean the library's filters from a request array (usually $_GET).
 *
 * @param array $source Raw parameters.
 * @return array{q:string,board:int,from:int,to:int,category:string[],topic:string[],sort:string,pg:int}
 */
function glover_document_library_parse_filters($source)
{
    $list = function ($key) use ($source) {
        $value = isset($source[$key]) ? (array) $source[$key] : array();

        return array_values(array_filter(array_map('sanitize_title', array_map('wp_unslash', $value))));
    };

    $sort = isset($source['sort']) ? sanitize_key($source['sort']) : 'recent';

    return array(
        'q' => isset($source['q']) ? sanitize_text_field(wp_unslash($source['q'])) : '',
        'board' => isset($source['board']) ? absint($source['board']) : 0,
        'from' => isset($source['from']) ? absint($source['from']) : 0,
        'to' => isset($source['to']) ? absint($source['to']) : 0,
        'category' => $list('category'),
        'topic' => $list('topic'),
        'sort' => in_array($sort, array('recent', 'oldest', 'title'), true) ? $sort : 'recent',
        'pg' => isset($source['pg']) ? max(1, absint($source['pg'])) : 1,
    );
}

/**
 * Earliest and latest years among published documents.
 *
 * @return array{min:int,max:int}
 */
function glover_document_library_year_range()
{
    global $wpdb;

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT MIN(LEFT(m.meta_value, 4)) AS min_year, MAX(LEFT(m.meta_value, 4)) AS max_year
         FROM {$wpdb->postmeta} m
         INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
         WHERE m.meta_key = 'document_date' AND m.meta_value <> '' AND p.post_type = %s AND p.post_status = 'publish'",
        GLOVER_DOCUMENT_LIBRARY_POST_TYPE
    ));

    $current = (int) wp_date('Y');

    return array(
        'min' => ($row && $row->min_year) ? (int) $row->min_year : $current,
        'max' => ($row && $row->max_year) ? max((int) $row->max_year, (int) $row->min_year) : $current,
    );
}

/**
 * WP_Query arguments for a set of filters.
 *
 * @param array $filters From glover_document_library_parse_filters().
 * @param array $skip    Filter names to leave out (used for facet counts).
 * @return array
 */
function glover_document_library_query_args($filters, $skip = array())
{
    $args = array(
        'post_type' => GLOVER_DOCUMENT_LIBRARY_POST_TYPE,
        'post_status' => 'publish',
        'posts_per_page' => GLOVER_DOCUMENT_LIBRARY_PER_PAGE,
        'paged' => $filters['pg'],
        'tax_query' => array('relation' => 'AND'),
        'meta_query' => array('relation' => 'AND', 'document_date' => array('key' => 'document_date', 'compare' => 'EXISTS')),
        'glover_title_search' => true,
    );

    if ($filters['q'] !== '' && !in_array('q', $skip, true)) {
        $args['s'] = $filters['q'];
    }

    if ($filters['board'] && !in_array('board', $skip, true)) {
        $args['tax_query'][] = array(
            'taxonomy' => GLOVER_DOCUMENT_LIBRARY_BOARD_TAXONOMY,
            'field' => 'slug',
            'terms' => glover_document_library_board_term_slug($filters['board']),
        );
    }

    foreach (array('category' => GLOVER_DOCUMENT_LIBRARY_CATEGORY_TAXONOMY, 'topic' => GLOVER_DOCUMENT_LIBRARY_TOPIC_TAXONOMY) as $key => $taxonomy) {
        if ($filters[$key] && !in_array($key, $skip, true)) {
            $args['tax_query'][] = array('taxonomy' => $taxonomy, 'field' => 'slug', 'terms' => $filters[$key]);
        }
    }

    if (!in_array('years', $skip, true)) {
        if ($filters['from']) {
            $args['meta_query'][] = array('key' => 'document_date', 'value' => $filters['from'] . '0101', 'compare' => '>=');
        }

        if ($filters['to']) {
            $args['meta_query'][] = array('key' => 'document_date', 'value' => $filters['to'] . '1231', 'compare' => '<=');
        }
    }

    if ($filters['sort'] === 'title') {
        $args['orderby'] = 'title';
        $args['order'] = 'ASC';
    } else {
        $args['orderby'] = array('document_date' => $filters['sort'] === 'oldest' ? 'ASC' : 'DESC', 'title' => 'ASC');
    }

    return $args;
}

/**
 * Limit library searches to document titles, so a word buried in another
 * field never produces a confusing match.
 *
 * @param string   $search SQL search clause.
 * @param WP_Query $query  Query.
 * @return string
 */
function glover_document_library_title_search($search, $query)
{
    global $wpdb;

    if (!$query->get('glover_title_search') || $query->get('s') === '') {
        return $search;
    }

    $clauses = array();

    foreach ((array) $query->get('search_terms') as $term) {
        $clauses[] = $wpdb->prepare("{$wpdb->posts}.post_title LIKE %s", '%' . $wpdb->esc_like($term) . '%');
    }

    return $clauses ? ' AND (' . implode(' AND ', $clauses) . ') ' : $search;
}
add_filter('posts_search', 'glover_document_library_title_search', 10, 2);

/**
 * Count matching documents per term in one facet, applying every other filter.
 *
 * @param array  $filters  Current filters.
 * @param string $facet    category, topic or board.
 * @return array<string, int> Term slug => count.
 */
function glover_document_library_facet_counts($filters, $facet)
{
    $taxonomies = array(
        'category' => GLOVER_DOCUMENT_LIBRARY_CATEGORY_TAXONOMY,
        'topic' => GLOVER_DOCUMENT_LIBRARY_TOPIC_TAXONOMY,
        'board' => GLOVER_DOCUMENT_LIBRARY_BOARD_TAXONOMY,
    );
    $args = glover_document_library_query_args($filters, array($facet));
    $args['fields'] = 'ids';
    $args['posts_per_page'] = -1;
    $args['paged'] = 1;
    $args['no_found_rows'] = true;
    $ids = (new WP_Query($args))->posts;
    $counts = array();

    if (!$ids) {
        return $counts;
    }

    foreach (wp_get_object_terms($ids, $taxonomies[$facet], array('fields' => 'all_with_object_id')) as $term) {
        $counts[$term->slug] = isset($counts[$term->slug]) ? $counts[$term->slug] + 1 : 1;
    }

    return $counts;
}

/**
 * Library URL for a set of filters, leaving out defaults.
 *
 * @param array $filters Filters.
 */
function glover_document_library_url($filters = array())
{
    $params = array();

    foreach ($filters as $key => $value) {
        if ($value === '' || $value === 0 || $value === array() || ($key === 'sort' && $value === 'recent') || ($key === 'pg' && (int) $value === 1)) {
            continue;
        }
        $params[$key] = $value;
    }

    return add_query_arg($params, get_post_type_archive_link(GLOVER_DOCUMENT_LIBRARY_POST_TYPE));
}

/**
 * Everything the library page needs for one set of filters.
 *
 * @param array $filters Filters.
 * @return array
 */
function glover_document_library_results($filters)
{
    $years = glover_document_library_year_range();

    // The full range of years is the same as no year filter.
    if ($filters['from'] && $filters['from'] <= $years['min']) {
        $filters['from'] = 0;
    }

    if ($filters['to'] && $filters['to'] >= $years['max']) {
        $filters['to'] = 0;
    }

    if ($filters['from'] && $filters['to'] && $filters['from'] > $filters['to']) {
        list($filters['from'], $filters['to']) = array($filters['to'], $filters['from']);
    }

    $query = new WP_Query(glover_document_library_query_args($filters));

    return array(
        'filters' => $filters,
        'years' => $years,
        'query' => $query,
        'total' => (int) $query->found_posts,
        'pages' => (int) $query->max_num_pages,
        'counts' => array(
            'category' => glover_document_library_facet_counts($filters, 'category'),
            'topic' => glover_document_library_facet_counts($filters, 'topic'),
        ),
    );
}

/**
 * Render a component file from components/document-library/ into a string.
 *
 * @param string $name Component name without .php.
 * @param array  $args Variables for the component.
 */
function glover_document_library_component($name, $args = array())
{
    ob_start();
    get_template_part('components/document-library/' . $name, null, $args);

    return ob_get_clean();
}

/**
 * Live results endpoint: the same server-rendered markup as the page, so a
 * shared link and a live update always look identical.
 */
function glover_document_library_register_rest()
{
    register_rest_route('glover/v1', '/documents', array(
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function (WP_REST_Request $request) {
            $results = glover_document_library_results(glover_document_library_parse_filters($request->get_query_params()));

            return rest_ensure_response(array(
                'total' => $results['total'],
                'summary' => wp_strip_all_tags(glover_document_library_component('summary', $results)),
                'results' => glover_document_library_component('results', $results),
                'chips' => glover_document_library_component('active-filters', $results),
                'counts' => $results['counts'],
                'url' => glover_document_library_url($results['filters']),
            ));
        },
    ));
}
add_action('rest_api_init', 'glover_document_library_register_rest');

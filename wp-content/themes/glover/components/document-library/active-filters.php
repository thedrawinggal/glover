<?php
/**
 * Active-filter rail: one removable chip per applied filter, plus Clear all.
 * Chips are links, so they work without JavaScript.
 *
 * @var array $args Results from glover_document_library_results().
 */

if (!defined('ABSPATH')) {
    exit;
}

$filters = $args['filters'];
$base = array_merge($filters, array('pg' => 1));
$chips = array();

if ($filters['q'] !== '') {
    $chips[] = array('label' => '“' . $filters['q'] . '”', 'filters' => array_merge($base, array('q' => '')));
}

if ($filters['board']) {
    $chips[] = array('label' => get_the_title($filters['board']), 'filters' => array_merge($base, array('board' => 0)));
}

if ($filters['from'] || $filters['to']) {
    $label = $filters['from'] && $filters['to'] ? $filters['from'] . ' to ' . $filters['to'] : ($filters['from'] ? 'From ' . $filters['from'] : 'Through ' . $filters['to']);
    $chips[] = array('label' => $label, 'filters' => array_merge($base, array('from' => 0, 'to' => 0)));
}

foreach (array('category' => GLOVER_DOCUMENT_LIBRARY_CATEGORY_TAXONOMY, 'topic' => GLOVER_DOCUMENT_LIBRARY_TOPIC_TAXONOMY) as $key => $taxonomy) {
    foreach ($filters[$key] as $slug) {
        $term = get_term_by('slug', $slug, $taxonomy);

        if ($term) {
            $chips[] = array(
                'label' => $term->name,
                'filters' => array_merge($base, array($key => array_values(array_diff($filters[$key], array($slug))))),
            );
        }
    }
}

if (!$chips) {
    return;
}
?>
<div class="documents__chips">
    <h2 class="visually-hidden">Active filters</h2>
    <ul class="chip-list" role="list">
        <?php foreach ($chips as $chip) : ?>
            <li>
                <a class="chip chip--filter" href="<?php echo esc_url(glover_document_library_url($chip['filters'])); ?>" data-library-link>
                    <span class="visually-hidden">Remove filter: </span><?php echo esc_html($chip['label']); ?><span class="chip__remove" aria-hidden="true">×</span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
    <a href="<?php echo esc_url(glover_document_library_url(array('sort' => $filters['sort']))); ?>" data-library-link>Clear all</a>
</div>

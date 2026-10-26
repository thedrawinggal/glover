<?php
/**
 * Results count sentence, e.g. "62 results for "zoning" in Minutes".
 *
 * @var array $args Results from glover_document_library_results().
 */

if (!defined('ABSPATH')) {
    exit;
}

$filters = $args['filters'];
$total = (int) $args['total'];
$parts = array();

if ($filters['q'] !== '') {
    $parts[] = sprintf('for “%s”', $filters['q']);
}

$names = array();

foreach ($filters['category'] as $slug) {
    $term = get_term_by('slug', $slug, GLOVER_DOCUMENT_LIBRARY_CATEGORY_TAXONOMY);

    if ($term) {
        $names[] = $term->name;
    }
}

if ($names) {
    $parts[] = 'in ' . implode(' or ', $names);
}
?>
<strong><?php echo esc_html(sprintf(_n('%s result', '%s results', $total), number_format_i18n($total))); ?></strong>
<?php echo esc_html(implode(' ', $parts)); ?>

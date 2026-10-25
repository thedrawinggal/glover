<?php
/**
 * The Filters panel. A plain GET form: it works without JavaScript, and
 * library.js upgrades it to live results.
 *
 * @var array $args Results from glover_document_library_results(), plus 'years'.
 */

if (!defined('ABSPATH')) {
    exit;
}

$filters = $args['filters'];
$years = $args['years'];
$from = $filters['from'] ? $filters['from'] : $years['min'];
$to = $filters['to'] ? $filters['to'] : $years['max'];
// Only categories and topics with at least one published document; empty
// ones stay in WordPress for the submission form, just not here.
$categories = get_terms(array('taxonomy' => GLOVER_DOCUMENT_LIBRARY_CATEGORY_TAXONOMY, 'hide_empty' => true));
$topics = get_terms(array('taxonomy' => GLOVER_DOCUMENT_LIBRARY_TOPIC_TAXONOMY, 'hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC'));
$visible_topics = 9;
?>
<form id="glover-library-filters" class="filters" method="get" action="<?php echo esc_url(get_post_type_archive_link(GLOVER_DOCUMENT_LIBRARY_POST_TYPE)); ?>" aria-labelledby="glover-filters-heading">
    <div class="filters__header">
        <h2 id="glover-filters-heading" class="filters__title">Filters</h2>
        <a class="filters__clear" href="<?php echo esc_url(glover_document_library_url()); ?>" data-library-link>Clear all</a>
        <?php // Shown only when the filters open as a full-screen panel on narrow screens. ?>
        <button type="button" class="button button--outline button--icon filters__close" data-filters-close hidden>
            <svg aria-hidden="true" focusable="false" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
            <span class="visually-hidden">Close Filters</span>
        </button>
    </div>

    <div class="filters__group">
        <label class="filters__label" for="glover-filter-board">Department or board</label>
        <select id="glover-filter-board" name="board" class="form-select">
            <option value="">All boards</option>
            <?php foreach (glover_document_library_all_boards() as $board_id => $title) : ?>
                <option value="<?php echo (int) $board_id; ?>" <?php selected($filters['board'], $board_id); ?>><?php echo esc_html($title); ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <fieldset class="filters__group year-range" data-min="<?php echo (int) $years['min']; ?>" data-max="<?php echo (int) $years['max']; ?>">
        <legend class="filters__label">Year</legend>
        <div class="year-range__fields">
            <label class="visually-hidden" for="glover-filter-from">From year</label>
            <select id="glover-filter-from" name="from" class="form-select">
                <?php for ($year = $years['min']; $year <= $years['max']; $year++) : ?>
                    <option value="<?php echo (int) $year; ?>" <?php selected($from, $year); ?>><?php echo (int) $year; ?></option>
                <?php endfor; ?>
            </select>
            <span aria-hidden="true">to</span>
            <label class="visually-hidden" for="glover-filter-to">To year</label>
            <select id="glover-filter-to" name="to" class="form-select">
                <?php for ($year = $years['min']; $year <= $years['max']; $year++) : ?>
                    <option value="<?php echo (int) $year; ?>" <?php selected($to, $year); ?>><?php echo (int) $year; ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="year-range__slider" hidden>
            <input type="range" min="<?php echo (int) $years['min']; ?>" max="<?php echo (int) $years['max']; ?>" step="1" value="<?php echo (int) $from; ?>" aria-label="From year" data-year-range="from">
            <input type="range" min="<?php echo (int) $years['min']; ?>" max="<?php echo (int) $years['max']; ?>" step="1" value="<?php echo (int) $to; ?>" aria-label="To year" data-year-range="to">
        </div>
    </fieldset>

    <?php if ($categories && !is_wp_error($categories)) : ?>
        <fieldset class="filters__group">
            <legend class="filters__label">Category</legend>
            <ul class="check-list" role="list">
                <?php foreach ($categories as $term) : ?>
                    <?php $count = isset($args['counts']['category'][$term->slug]) ? $args['counts']['category'][$term->slug] : 0; ?>
                    <li>
                        <input type="checkbox" id="glover-category-<?php echo esc_attr($term->slug); ?>" name="category[]" value="<?php echo esc_attr($term->slug); ?>" <?php checked(in_array($term->slug, $filters['category'], true)); ?>>
                        <label for="glover-category-<?php echo esc_attr($term->slug); ?>">
                            <?php echo esc_html($term->name); ?>
                            <span class="count" data-count-category="<?php echo esc_attr($term->slug); ?>"><?php echo (int) $count; ?><span class="visually-hidden"> documents</span></span>
                        </label>
                    </li>
                <?php endforeach; ?>
            </ul>
        </fieldset>
    <?php endif; ?>

    <?php if ($topics && !is_wp_error($topics)) : ?>
        <fieldset class="filters__group">
            <legend class="filters__label">Topic</legend>
            <ul class="topic-list" role="list" id="topic-list">
                <?php foreach ($topics as $index => $term) : ?>
                    <?php $count = isset($args['counts']['topic'][$term->slug]) ? $args['counts']['topic'][$term->slug] : 0; ?>
                    <li<?php echo $index >= $visible_topics ? ' data-extra-topic' : ''; ?>>
                        <input type="checkbox" class="chip-toggle" id="glover-topic-<?php echo esc_attr($term->slug); ?>" name="topic[]" value="<?php echo esc_attr($term->slug); ?>" <?php checked(in_array($term->slug, $filters['topic'], true)); ?>>
                        <label class="chip" for="glover-topic-<?php echo esc_attr($term->slug); ?>">
                            <?php echo esc_html($term->name); ?>
                            <span class="count" data-count-topic="<?php echo esc_attr($term->slug); ?>"><?php echo (int) $count; ?><span class="visually-hidden"> documents</span></span>
                        </label>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if (count($topics) > $visible_topics) : ?>
                <button type="button" class="link-button" data-topics-toggle aria-controls="topic-list" aria-expanded="true" hidden>
                    Show all <?php echo (int) count($topics); ?> topics
                </button>
            <?php endif; ?>
        </fieldset>
    <?php endif; ?>

    <div class="filters__submit">
        <button type="submit" class="button button--primary">Apply filters</button>
    </div>

    <?php // The full-screen panel's footer. Results update as filters change, so Show closes the panel. ?>
    <div class="filters__footer" hidden>
        <a class="button button--outline button--large" href="<?php echo esc_url(glover_document_library_url(array('q' => $filters['q']))); ?>" data-library-link data-filters-clear>Clear All</a>
        <button type="button" class="button button--primary button--large" data-filters-close data-filters-show><?php echo esc_html(sprintf(_n('Show %s Result', 'Show %s Results', (int) $args['total']), number_format_i18n((int) $args['total']))); ?></button>
    </div>
</form>

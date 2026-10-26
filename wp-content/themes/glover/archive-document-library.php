<?php
/**
 * Public Document Library at /documents/.
 * See docs/features/document-library.md.
 */

$results = glover_document_library_results(glover_document_library_parse_filters($_GET));
$filters = $results['filters'];

get_header();
?>
<main id="main-content" class="documents" tabindex="-1">
    <?php
    get_template_part('components/common/page-banner', null, array(
        'title' => 'Document library',
        'breadcrumbs' => array(
            array('label' => 'Home', 'url' => home_url('/')),
            array('label' => 'Documents', 'url' => glover_document_library_url()),
        ),
        'actions' => array(
            array(
                'label' => 'Help',
                'url' => '#glover-library-help',
                'attributes' => array('data-dialog-open' => 'glover-library-help'),
            ),
        ),
    ));
    ?>

    <div class="documents__layout">
        <div id="glover-library-filters-panel" class="documents__sidebar">
            <?php get_template_part('components/document-library/filters', null, $results); ?>
        </div>

        <div class="documents__main">
            <div class="search-bar" role="search">
                <label class="visually-hidden" for="glover-library-search">Search document titles and text</label>
                <input type="search" id="glover-library-search" class="form-input" name="q" form="glover-library-filters" value="<?php echo esc_attr($filters['q']); ?>" placeholder="Search titles and the text inside documents" autocomplete="off">
                <button type="submit" form="glover-library-filters" class="button button--primary">Search</button>
            </div>

            <div class="documents__bar">
                <p class="documents__summary" id="glover-library-summary" role="status" aria-live="polite" aria-atomic="true">
                    <?php get_template_part('components/document-library/summary', null, $results); ?>
                </p>
                <div class="documents__tools">
                    <?php // Narrow screens only, once library.js has loaded: opens the filters as a full-screen panel. ?>
                    <button type="button" class="button button--secondary documents__filters-toggle" aria-expanded="false" aria-controls="glover-library-filters-panel" hidden>
                        <svg aria-hidden="true" focusable="false" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M4 6h16M7 12h10M10 18h4"/></svg>
                        Filters<span data-filters-count></span>
                    </button>
                    <div class="documents__sort">
                        <label for="glover-library-sort">Sort</label>
                        <select id="glover-library-sort" name="sort" form="glover-library-filters" class="form-select">
                            <option value="relevance" <?php selected($filters['sort'], 'relevance'); ?>>Best match</option>
                            <option value="recent" <?php selected($filters['sort'], 'recent'); ?>>Most recent</option>
                            <option value="oldest" <?php selected($filters['sort'], 'oldest'); ?>>Oldest</option>
                            <option value="title" <?php selected($filters['sort'], 'title'); ?>>Title, A to Z</option>
                        </select>
                    </div>
                </div>
            </div>

            <div id="glover-library-chips">
                <?php get_template_part('components/document-library/active-filters', null, $results); ?>
            </div>

            <div id="glover-library-results" class="documents__results">
                <?php get_template_part('components/document-library/results', null, $results); ?>
            </div>
        </div>
    </div>

    <p class="documents__request container">
        Need a document in another format, such as large print or an accessible PDF?
        <?php echo glover_document_library_accessible_copy_link(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the helper. ?>.
    </p>

    <?php get_template_part('components/document-library/help'); ?>
</main>
<?php
get_footer();

<?php
/**
 * Page banner: the dark green band at the top of interior pages.
 * Styles: src/styles/components/_page-banner.scss and _breadcrumb.scss.
 *
 * Usage:
 * get_template_part('components/common/page-banner', null, array(
 *     'title' => 'Document library',
 *     'breadcrumbs' => array(array('label' => 'Home', 'url' => home_url('/')), array('label' => 'Documents', 'url' => '...')),
 *     'action' => array('label' => 'Help', 'url' => '#help', 'attributes' => array('data-dialog-open' => 'help')),
 *     'note' => 'Draft minutes. Not yet approved by the board.',
 * ));
 *
 * The last breadcrumb is marked as the current page. The title is the page's only h1.
 *
 * @var array $args
 */

if (!defined('ABSPATH')) {
    exit;
}

$title = isset($args['title']) ? $args['title'] : get_the_title();
$breadcrumbs = isset($args['breadcrumbs']) ? (array) $args['breadcrumbs'] : array();
$action = isset($args['action']) ? $args['action'] : null;
$note = isset($args['note']) ? $args['note'] : '';
?>
<header class="page-banner">
    <div class="page-banner__inner">
        <?php if ($breadcrumbs) : ?>
            <nav class="breadcrumb page-banner__breadcrumb" aria-label="Breadcrumb">
                <ol role="list">
                    <?php foreach (array_values($breadcrumbs) as $index => $crumb) : ?>
                        <li>
                            <a href="<?php echo esc_url($crumb['url']); ?>"<?php echo $index === count($breadcrumbs) - 1 ? ' aria-current="page"' : ''; ?>><?php echo esc_html($crumb['label']); ?></a>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </nav>
        <?php endif; ?>

        <h1 class="page-banner__title"><?php echo esc_html($title); ?></h1>

        <?php if ($action && !empty($action['label']) && !empty($action['url'])) : ?>
            <a class="button button--ghost page-banner__action" href="<?php echo esc_url($action['url']); ?>"<?php
            foreach ((array) (isset($action['attributes']) ? $action['attributes'] : array()) as $name => $value) {
                printf(' %s="%s"', esc_attr($name), esc_attr($value));
            }
            ?>><?php echo esc_html($action['label']); ?></a>
        <?php endif; ?>

        <?php if ($note !== '') : ?>
            <p class="page-banner__note"><?php echo esc_html($note); ?></p>
        <?php endif; ?>
    </div>
</header>

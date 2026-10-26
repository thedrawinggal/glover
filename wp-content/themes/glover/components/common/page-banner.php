<?php
/**
 * Page banner: the Forest band at the top of every interior page. Holds the
 * breadcrumb, the page title (the page's only h1), an optional intro line,
 * an optional note, and a right-hand area for actions and other content.
 * Styles: src/styles/components/_page-banner.scss and _breadcrumb.scss.
 *
 * Usage:
 * get_template_part('components/common/page-banner', null, array(
 *     'title' => 'Document Library',
 *     'breadcrumbs' => array(
 *         array('label' => 'Home', 'url' => home_url('/')),
 *         array('label' => 'Documents', 'url' => '...'),
 *     ),
 *     'intro' => 'Agendas, minutes and other public records.',
 *     'actions' => array(
 *         array('label' => 'Help', 'url' => '#help', 'attributes' => array('data-dialog-open' => 'help')),
 *         array('label' => 'Subscribe', 'url' => '...'),
 *     ),
 *     'aside' => function () { get_template_part('components/...'); },
 *     'note' => 'Draft minutes. Not yet approved by the board.',
 * ));
 *
 * The last breadcrumb is marked as the current page.
 *
 * Actions are buttons on the right. The first is Teal (Secondary) and the rest
 * are Cream outlines (Ghost), per Buttons on Forest in the spec plate; set
 * 'variant' => 'secondary' or 'ghost' on an action to choose. 'aside' is any
 * other content for the right-hand area: a callable that prints it, or an
 * HTML string (filtered with wp_kses_post). It sits beside the actions.
 *
 * @var array $args
 */

if (!defined('ABSPATH')) {
    exit;
}

$title = isset($args['title']) ? $args['title'] : get_the_title();
$breadcrumbs = isset($args['breadcrumbs']) ? array_values((array) $args['breadcrumbs']) : array();
$intro = isset($args['intro']) ? $args['intro'] : '';
$note = isset($args['note']) ? $args['note'] : '';
$aside = isset($args['aside']) ? $args['aside'] : null;
$actions = array_values(array_filter((array) (isset($args['actions']) ? $args['actions'] : array()), function ($action) {
    return !empty($action['label']) && !empty($action['url']);
}));
?>
<header class="page-banner">
    <div class="page-banner__inner">
        <?php if ($breadcrumbs) : ?>
            <nav class="breadcrumb page-banner__breadcrumb" aria-label="Breadcrumb">
                <ol role="list">
                    <?php foreach ($breadcrumbs as $index => $crumb) : ?>
                        <li>
                            <a href="<?php echo esc_url($crumb['url']); ?>"<?php echo $index === count($breadcrumbs) - 1 ? ' aria-current="page"' : ''; ?>><?php echo esc_html($crumb['label']); ?></a>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </nav>
        <?php endif; ?>

        <div class="page-banner__heading">
            <h1 class="page-banner__title"><?php echo esc_html($title); ?></h1>
            <?php if ($intro !== '') : ?>
                <p class="page-banner__intro"><?php echo esc_html($intro); ?></p>
            <?php endif; ?>
        </div>

        <?php if ($actions || $aside) : ?>
            <div class="page-banner__aside">
                <?php if ($aside) : ?>
                    <div class="page-banner__extra">
                        <?php
                        if (is_callable($aside)) {
                            call_user_func($aside);
                        } else {
                            echo wp_kses_post($aside);
                        }
                        ?>
                    </div>
                <?php endif; ?>

                <?php if ($actions) : ?>
                    <div class="page-banner__actions">
                        <?php foreach ($actions as $index => $action) : ?>
                            <?php $variant = isset($action['variant']) && in_array($action['variant'], array('secondary', 'ghost'), true) ? $action['variant'] : ($index === 0 ? 'secondary' : 'ghost'); ?>
                            <a class="button button--<?php echo esc_attr($variant); ?>" href="<?php echo esc_url($action['url']); ?>"<?php
                            foreach ((array) (isset($action['attributes']) ? $action['attributes'] : array()) as $name => $value) {
                                printf(' %s="%s"', esc_attr($name), esc_attr($value));
                            }
                            ?>><?php echo esc_html($action['label']); ?></a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($note !== '') : ?>
            <p class="page-banner__note"><?php echo esc_html($note); ?></p>
        <?php endif; ?>
    </div>
</header>

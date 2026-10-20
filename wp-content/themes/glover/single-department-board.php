<?php
/**
 * A Departments & Boards page. Minimal for now: title, description,
 * members, and the Document Library's "Latest documents" feed.
 */

get_header();
?>
<main id="main-content" class="department-board" tabindex="-1">
    <?php while (have_posts()) : the_post(); ?>
        <?php
        get_template_part('components/common/page-banner', null, array(
            'title' => get_the_title(),
            'breadcrumbs' => array(
                array('label' => 'Home', 'url' => home_url('/')),
                array('label' => get_the_title(), 'url' => get_permalink()),
            ),
        ));
        ?>

        <div class="content-narrow">
            <div class="rich-text"><?php the_content(); ?></div>

            <?php $people = function_exists('get_field') ? get_field('personel') : array(); ?>
            <?php if ($people) : ?>
                <section aria-labelledby="glover-board-members">
                    <h2 id="glover-board-members">Members</h2>
                    <ul role="list" class="department-board__members">
                        <?php foreach ($people as $person) : ?>
                            <li><?php echo esc_html($person['name'] . (!empty($person['title']) ? ', ' . $person['title'] : '')); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endif; ?>

            <?php get_template_part('components/document-library/latest-documents', null, array('board_id' => get_the_ID())); ?>
        </div>
    <?php endwhile; ?>
</main>
<?php
get_footer();

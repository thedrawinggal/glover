<?php
/**
 * Steps: a numbered row of short steps under a small label heading, with an
 * optional note below. Reusable on any page that explains a process.
 * Styles: src/styles/components/_steps.scss.
 *
 * Usage:
 * get_template_part('components/common/steps', null, array(
 *     'heading' => 'How It Works',
 *     'steps' => array(
 *         array('title' => 'Request Access', 'text' => 'Email the Town Clerk with your name and board.'),
 *         array('title' => 'Sign In', 'text' => 'With an emailed code or Google.'),
 *     ),
 *     'note' => 'Looking for a document? Browse the <a href="...">Document Library</a>.',
 * ));
 *
 * 'text' and 'note' allow links and emphasis (wp_kses_post). The list is an
 * ordered list, so screen readers announce each step's position; the drawn
 * numbers are hidden from them.
 *
 * @var array $args
 */

if (!defined('ABSPATH')) {
    exit;
}

$heading = isset($args['heading']) ? $args['heading'] : '';
$note = isset($args['note']) ? $args['note'] : '';
$steps = array_values(array_filter((array) (isset($args['steps']) ? $args['steps'] : array()), function ($step) {
    return !empty($step['title']);
}));

if (!$steps) {
    return;
}

$heading_id = wp_unique_id('steps-heading-');
?>
<section class="steps"<?php echo $heading !== '' ? ' aria-labelledby="' . esc_attr($heading_id) . '"' : ''; ?>>
    <?php if ($heading !== '') : ?>
        <h2 class="steps__heading" id="<?php echo esc_attr($heading_id); ?>"><?php echo esc_html($heading); ?></h2>
    <?php endif; ?>

    <ol class="steps__list">
        <?php foreach ($steps as $index => $step) : ?>
            <li class="steps__item">
                <span class="steps__number" aria-hidden="true"><?php echo (int) $index + 1; ?></span>
                <div class="steps__body">
                    <h3 class="steps__title"><?php echo esc_html($step['title']); ?></h3>
                    <?php if (!empty($step['text'])) : ?>
                        <p class="steps__text"><?php echo wp_kses_post($step['text']); ?></p>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ol>

    <?php if ($note !== '') : ?>
        <p class="steps__note"><?php echo wp_kses_post($note); ?></p>
    <?php endif; ?>
</section>

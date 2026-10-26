<?php
/**
 * The left side of the sign-in panel: icon or logo, optional label, heading,
 * intro and Need access line. The panel prints it (components/common/
 * sign-in-panel.php), and so do WordPress's own login screens
 * (includes/features/login-screen/), so every sign-in screen reads the same.
 * Styles: src/styles/components/_sign-in-panel.scss.
 *
 * Usage:
 * get_template_part('components/common/sign-in-intro', null, array(
 *     'label' => 'Town of Glover, Vermont',
 *     'heading' => 'Sign In',
 *     'heading_tag' => 'h1',      // h1 or h2
 *     'heading_id' => 'my-id',    // optional
 *     'intro' => '',
 *     'access' => 'Need access? ...', // HTML, may hold links
 *     'icon' => 'logo',           // 'document', 'lock', or 'logo' (a placeholder until the town logo is added)
 * ));
 *
 * @var array $args
 */

if (!defined('ABSPATH')) {
    exit;
}

$label = isset($args['label']) ? $args['label'] : '';
$heading = isset($args['heading']) ? $args['heading'] : 'Sign In';
$heading_tag = isset($args['heading_tag']) && $args['heading_tag'] === 'h1' ? 'h1' : 'h2';
$heading_id = isset($args['heading_id']) ? $args['heading_id'] : '';
$intro = isset($args['intro']) ? $args['intro'] : '';
$access = isset($args['access']) ? $args['access'] : '';
$icon = isset($args['icon']) && in_array($args['icon'], array('lock', 'logo'), true) ? $args['icon'] : 'document';
?>
<div class="sign-in-panel__intro">
    <?php if ($icon === 'logo') : ?>
        <span class="sign-in-panel__logo" aria-hidden="true">Logo</span>
    <?php else : ?>
        <span class="sign-in-panel__icon" aria-hidden="true">
            <?php if ($icon === 'lock') : ?>
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="10" width="16" height="11" rx="2"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3"></path></svg>
            <?php else : ?>
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"></path><path d="M14 3v6h6M8 13h8M8 17h5"></path></svg>
            <?php endif; ?>
        </span>
    <?php endif; ?>
    <div class="sign-in-panel__title">
        <?php if ($label !== '') : ?>
            <p class="sign-in-panel__label"><?php echo esc_html($label); ?></p>
        <?php endif; ?>
        <<?php echo $heading_tag; ?><?php echo $heading_id !== '' ? ' id="' . esc_attr($heading_id) . '"' : ''; ?> class="sign-in-panel__heading"><?php echo esc_html($heading); ?></<?php echo $heading_tag; ?>>
    </div>
    <?php if ($intro !== '') : ?>
        <p><?php echo esc_html($intro); ?></p>
    <?php endif; ?>
    <?php if ($access !== '') : ?>
        <p class="sign-in-panel__access"><?php echo wp_kses_post($access); ?></p>
    <?php endif; ?>
</div>

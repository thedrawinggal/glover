<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Load the shared schedule editor behavior and summary styling.
 *
 * @param string $hook_suffix Current wp-admin page.
 */
function glover_event_calendar_enqueue_admin_assets($hook_suffix)
{
    if (!in_array($hook_suffix, array('post.php', 'post-new.php'), true)) {
        return;
    }

    $screen = get_current_screen();

    if (!$screen || !in_array($screen->post_type, array('community-events', 'meeting'), true)) {
        return;
    }

    $stylesheet_path = __DIR__ . '/event-calendar-admin.css';
    $script_path = __DIR__ . '/event-calendar-admin.js';
    $feature_url = get_template_directory_uri() . '/includes/features/event-calendar';

    // ACF's time-picker JavaScript can load without its addon stylesheet on
    // these Local JSON post types. Enqueue ACF's own CSS so its native picker
    // keeps unused units hidden and the time controls on one line.
    if (function_exists('acf_get_url')) {
        wp_enqueue_style(
            'acf-timepicker',
            acf_get_url('assets/inc/timepicker/jquery-ui-timepicker-addon.min.css'),
            array('acf-input'),
            '1.6.1'
        );
    }

    wp_enqueue_style(
        'glover-event-calendar-summary',
        $feature_url . '/event-calendar-admin.css',
        array(),
        (string) filemtime($stylesheet_path)
    );

    wp_enqueue_script(
        'glover-event-calendar-admin',
        $feature_url . '/event-calendar-admin.js',
        array('acf-input'),
        (string) filemtime($script_path),
        true
    );

}
add_action('admin_enqueue_scripts', 'glover_event_calendar_enqueue_admin_assets');

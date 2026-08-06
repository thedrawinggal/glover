<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Load the schedule editor presentation only on Event editing screens.
 *
 * @param string $hook_suffix Current wp-admin page.
 */
function glover_event_calendar_enqueue_admin_assets($hook_suffix)
{
    if (!in_array($hook_suffix, array('post.php', 'post-new.php'), true)) {
        return;
    }

    $screen = get_current_screen();

    if (!$screen || 'event' !== $screen->post_type) {
        return;
    }

    $stylesheet_path = __DIR__ . '/event-calendar-admin.css';
    $script_path = __DIR__ . '/event-calendar-admin.js';
    $feature_url = get_template_directory_uri() . '/includes/features/event-calendar';

    wp_enqueue_style(
        'glover-event-calendar-admin',
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

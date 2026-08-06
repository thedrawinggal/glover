<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Return the calendar destination for an event that does not have a landing page.
 */
function glover_event_calendar_get_archive_event_url($event_id)
{
    $calendar_url = get_post_type_archive_link('event');
    if (!$calendar_url) {
        $calendar_url = home_url('/calendar/');
    }

    return add_query_arg('event_id', absint($event_id), $calendar_url);
}

/**
 * Replace non-landing-page event permalinks with a calendar URL.
 */
function glover_event_calendar_filter_event_permalink($permalink, $post)
{
    if ('event' !== $post->post_type || !function_exists('get_field')) {
        return $permalink;
    }

    if (!get_field('has_landing_page', $post->ID)) {
        return glover_event_calendar_get_archive_event_url($post->ID);
    }

    return $permalink;
}

add_filter('post_type_link', 'glover_event_calendar_filter_event_permalink', 10, 2);

/**
 * Redirect a manually entered non-landing-page permalink to the calendar.
 */
function glover_event_calendar_redirect_non_landing_event()
{
    if (!is_singular('event') || !function_exists('get_field')) {
        return;
    }

    $event_id = get_queried_object_id();
    if (!$event_id || get_field('has_landing_page', $event_id)) {
        return;
    }

    wp_safe_redirect(glover_event_calendar_get_archive_event_url($event_id), 302, 'Glover Theme');
    exit;
}

add_action('template_redirect', 'glover_event_calendar_redirect_non_landing_event');

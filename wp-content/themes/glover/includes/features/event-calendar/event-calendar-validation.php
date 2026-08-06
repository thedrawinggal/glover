<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Normalize an ACF date value for chronological comparisons.
 */
function glover_event_calendar_normalize_date_value($value)
{
    $digits = preg_replace('/[^0-9]/', '', (string) $value);

    return 8 === strlen($digits) ? $digits : '';
}

/**
 * Validate cross-field rules for a submitted Event schedule.
 */
function glover_event_calendar_validate_schedule()
{
    if (!function_exists('acf_add_validation_error') || empty($_POST['acf'])) {
        return;
    }

    $post_type = '';
    if (isset($_POST['post_type'])) {
        $post_type = sanitize_key(wp_unslash($_POST['post_type']));
    } elseif (isset($_POST['post_ID'])) {
        $post_type = get_post_type(absint($_POST['post_ID']));
    }

    if ('event' !== $post_type) {
        return;
    }

    $acf_values = wp_unslash($_POST['acf']);
    $start_date = glover_event_calendar_normalize_date_value(
        $acf_values['field_glover_start_date'] ?? ''
    );
    $pattern = sanitize_key($acf_values['field_glover_recurrence_pattern'] ?? 'none');

    if ('custom' === $pattern) {
        $end_type = sanitize_key($acf_values['field_glover_recurrence_end_type'] ?? 'never');
        $end_date = glover_event_calendar_normalize_date_value(
            $acf_values['field_glover_recurrence_end_date'] ?? ''
        );

        if ('on_date' === $end_type && $start_date && $end_date && $end_date < $start_date) {
            acf_add_validation_error(
                'acf[field_glover_recurrence_end_date]',
                __('End date must be on or after the event Start Date.', 'cltv8')
            );
        }
    }

    if ('selected_dates' !== $pattern || !$start_date) {
        return;
    }

    $seen_dates = [];
    $selected_dates = $acf_values['field_glover_selected_dates'] ?? [];

    foreach ((array) $selected_dates as $row) {
        if (!is_array($row)) {
            continue;
        }

        $selected_date = glover_event_calendar_normalize_date_value(
            $row['field_glover_selected_date'] ?? ''
        );

        if (!$selected_date) {
            continue;
        }

        if ($selected_date <= $start_date) {
            acf_add_validation_error(
                'acf[field_glover_selected_dates]',
                __('Each additional selected date must be after the event Start Date.', 'cltv8')
            );
            break;
        }

        if (isset($seen_dates[$selected_date])) {
            acf_add_validation_error(
                'acf[field_glover_selected_dates]',
                __('Each additional selected date may be entered only once.', 'cltv8')
            );
            break;
        }

        $seen_dates[$selected_date] = true;
    }
}

add_action('acf/validate_save_post', 'glover_event_calendar_validate_schedule');

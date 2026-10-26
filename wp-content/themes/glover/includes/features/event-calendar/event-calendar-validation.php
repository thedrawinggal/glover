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
 * Normalize an ACF time value to minutes after midnight.
 */
function glover_event_calendar_normalize_time_value($value)
{
    $value = strtolower(trim((string) $value));

    if (!preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?\s*([ap]m)?$/', $value, $matches)) {
        return null;
    }

    $hour = (int) $matches[1];
    $minute = (int) $matches[2];
    $suffix = $matches[3] ?? '';

    if ($minute > 59 || ($suffix && ($hour < 1 || $hour > 12)) || (!$suffix && $hour > 23)) {
        return null;
    }

    if ('pm' === $suffix && $hour < 12) {
        $hour += 12;
    } elseif ('am' === $suffix && 12 === $hour) {
        $hour = 0;
    }

    return ($hour * 60) + $minute;
}

/**
 * Add an ACF error when an end time precedes its corresponding start time.
 */
function glover_event_calendar_validate_time_pair($start_value, $end_value, $end_input)
{
    $start_time = glover_event_calendar_normalize_time_value($start_value);
    $end_time = glover_event_calendar_normalize_time_value($end_value);

    if (null !== $start_time && null !== $end_time && $end_time < $start_time) {
        acf_add_validation_error(
            $end_input,
            __('End Time must be the same as or later than Start Time.', 'glover')
        );
    }
}

/**
 * Validate cross-field rules for a submitted Event or Meeting schedule.
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

    if (!in_array($post_type, array('community-events', 'meeting'), true)) {
        return;
    }

    $acf_values = wp_unslash($_POST['acf']);
    $key_prefix = 'meeting' === $post_type ? 'field_glover_meeting_' : 'field_glover_';
    $start_date = glover_event_calendar_normalize_date_value(
        $acf_values[$key_prefix . 'start_date'] ?? ''
    );
    $schedule_type = sanitize_key($acf_values[$key_prefix . 'recurrence_pattern'] ?? 'specific_dates');

    if ('recurring' === $schedule_type) {
        glover_event_calendar_validate_time_pair(
            $acf_values[$key_prefix . 'start_time'] ?? '',
            $acf_values[$key_prefix . 'end_time'] ?? '',
            'acf[' . $key_prefix . 'end_time]'
        );

        $occurrence_exceptions = $acf_values[$key_prefix . 'occurrence_exceptions'] ?? array();
        if (is_array($occurrence_exceptions)) {
            foreach ($occurrence_exceptions as $row_key => $exception) {
                if (!is_array($exception) || 'rescheduled' !== ($exception[$key_prefix . 'exception_status'] ?? '')) {
                    continue;
                }

                glover_event_calendar_validate_time_pair(
                    $exception[$key_prefix . 'exception_new_start_time'] ?? '',
                    $exception[$key_prefix . 'exception_new_end_time'] ?? '',
                    'acf[' . $key_prefix . 'occurrence_exceptions][' . $row_key . '][' . $key_prefix . 'exception_new_end_time]'
                );
            }
        }

        $end_type = sanitize_key($acf_values[$key_prefix . 'recurrence_end_type'] ?? 'never');
        $recurrence_end_date = glover_event_calendar_normalize_date_value(
            $acf_values[$key_prefix . 'recurrence_end_date'] ?? ''
        );

        if ('on_date' === $end_type && $start_date && $recurrence_end_date && $recurrence_end_date < $start_date) {
            acf_add_validation_error(
                'acf[' . $key_prefix . 'recurrence_end_date]',
                __('End date must be on or after the Start Date.', 'glover')
            );
        }
    } elseif ('specific_dates' === $schedule_type) {
        $specific_occurrences = $acf_values[$key_prefix . 'specific_occurrences'] ?? array();

        if (is_array($specific_occurrences)) {
            foreach ($specific_occurrences as $row_key => $occurrence) {
                if (!is_array($occurrence) || !empty($occurrence[$key_prefix . 'specific_occurrence_all_day'])) {
                    continue;
                }

                glover_event_calendar_validate_time_pair(
                    $occurrence[$key_prefix . 'specific_occurrence_start_time'] ?? '',
                    $occurrence[$key_prefix . 'specific_occurrence_end_time'] ?? '',
                    'acf[' . $key_prefix . 'specific_occurrences][' . $row_key . '][' . $key_prefix . 'specific_occurrence_end_time]'
                );
            }
        }
    }
}

add_action('acf/validate_save_post', 'glover_event_calendar_validate_schedule');

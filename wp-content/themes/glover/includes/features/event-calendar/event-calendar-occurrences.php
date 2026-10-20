<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Hard ceiling on schedule periods walked for one series. A daily series
 * walked for 50 years stays below this, so it only stops corrupt data.
 */
const GLOVER_EVENT_CALENDAR_MAX_PERIODS = 20000;

/**
 * Parse a Y-m-d or Ymd date into a UTC midnight date, or null.
 *
 * Schedule dates carry no time zone, so all date math runs in UTC to avoid
 * daylight-saving shifts.
 */
function glover_event_calendar_parse_date($value)
{
    if (!is_string($value) || '' === $value) {
        return null;
    }

    $value = str_replace('-', '', $value);
    $date = DateTimeImmutable::createFromFormat('!Ymd', $value, new DateTimeZone('UTC'));

    if (!$date || $date->format('Ymd') !== $value) {
        return null;
    }

    return $date;
}

/**
 * Return the community events that have an occurrence between two dates.
 *
 * @param string $range_start First date, Y-m-d, inclusive.
 * @param string $range_end   Last date, Y-m-d, inclusive.
 * @param array  $post_status Post statuses to include.
 * @return array Occurrences sorted by date and start time.
 */
function glover_event_calendar_get_occurrences_in_range($range_start, $range_end, $post_status = array('publish'))
{
    $start = glover_event_calendar_parse_date($range_start);
    $end = glover_event_calendar_parse_date($range_end);

    if (!$start || !$end || $end < $start) {
        return array();
    }

    $post_ids = get_posts(
        array(
            'post_type' => 'community-events',
            'post_status' => $post_status,
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
        )
    );

    if (!$post_ids) {
        return array();
    }

    update_meta_cache('post', $post_ids);

    $occurrences = array();
    foreach ($post_ids as $post_id) {
        $occurrences = array_merge($occurrences, glover_event_calendar_get_occurrences($post_id, $start, $end));
    }

    usort(
        $occurrences,
        function ($a, $b) {
            return strcmp($a['date'] . $a['start_time'], $b['date'] . $b['start_time']);
        }
    );

    return $occurrences;
}

/**
 * Expand one event's schedule into dated occurrences within a range.
 *
 * @param int               $post_id Event post ID.
 * @param DateTimeImmutable $start   First date, inclusive.
 * @param DateTimeImmutable $end     Last date, inclusive.
 * @return array[] Each occurrence has post_id, date, start_time, end_time,
 *                 time_tbd, status (scheduled, cancelled, or rescheduled),
 *                 original_date, location_id, and note.
 */
function glover_event_calendar_get_occurrences($post_id, DateTimeImmutable $start, DateTimeImmutable $end)
{
    if (!function_exists('get_field')) {
        return array();
    }

    $location_id = (int) get_field('event_location', $post_id);

    if ('recurring' === get_field('schedule_type', $post_id)) {
        return glover_event_calendar_get_recurring_occurrences($post_id, $start, $end, $location_id);
    }

    $occurrences = array();
    $rows = get_field('specific_occurrences', $post_id);

    foreach (is_array($rows) ? $rows : array() as $row) {
        $date = glover_event_calendar_parse_date($row['date'] ?? '');
        if (!$date || $date < $start || $date > $end) {
            continue;
        }

        $time_tbd = !empty($row['time_tbd']);
        $occurrences[] = glover_event_calendar_build_occurrence(
            $post_id,
            $date,
            $time_tbd ? '' : (string) ($row['start_time'] ?? ''),
            $time_tbd ? '' : (string) ($row['end_time'] ?? ''),
            'cancelled' === ($row['status'] ?? '') ? 'cancelled' : 'scheduled',
            $location_id
        );
    }

    return $occurrences;
}

/**
 * Expand a repeating schedule and apply its changed-date rows.
 */
function glover_event_calendar_get_recurring_occurrences($post_id, DateTimeImmutable $start, DateTimeImmutable $end, $location_id)
{
    $schedule = array(
        'start_date' => glover_event_calendar_parse_date((string) get_field('start_date', $post_id)),
        'interval' => max(1, (int) get_field('recurrence_interval', $post_id)),
        'unit' => (string) get_field('recurrence_unit', $post_id),
        'days' => (array) get_field('recurrence_days', $post_id),
        'monthly_basis' => (string) get_field('recurrence_monthly_basis', $post_id),
        'end_type' => (string) get_field('recurrence_end_type', $post_id),
        'end_date' => glover_event_calendar_parse_date((string) get_field('recurrence_end_date', $post_id)),
        'end_count' => (int) get_field('recurrence_end_count', $post_id),
    );

    if (!$schedule['start_date']) {
        return array();
    }

    $start_time = (string) get_field('start_time', $post_id);
    $end_time = (string) get_field('end_time', $post_id);

    $exceptions = array();
    $rows = get_field('occurrence_exceptions', $post_id);
    foreach (is_array($rows) ? $rows : array() as $row) {
        $date = glover_event_calendar_parse_date($row['date'] ?? '');
        if ($date) {
            $exceptions[$date->format('Y-m-d')] = $row;
        }
    }

    $dates = glover_event_calendar_get_recurring_dates($schedule, $start, $end);

    // A date rescheduled into this range may come from a date outside it.
    foreach ($exceptions as $original => $row) {
        $new_date = glover_event_calendar_parse_date($row['new_date'] ?? '');
        if ('rescheduled' !== ($row['status'] ?? '') || !$new_date || $new_date < $start || $new_date > $end) {
            continue;
        }

        $original_date = glover_event_calendar_parse_date($original);
        if (($original_date < $start || $original_date > $end)
            && glover_event_calendar_get_recurring_dates($schedule, $original_date, $original_date)) {
            $dates[] = $original_date;
        }
    }

    $occurrences = array();
    foreach ($dates as $date) {
        $occurrence = glover_event_calendar_build_occurrence($post_id, $date, $start_time, $end_time, 'scheduled', $location_id);
        $row = $exceptions[$occurrence['date']] ?? null;

        if ($row) {
            $occurrence['note'] = (string) ($row['note'] ?? '');

            switch ($row['status'] ?? '') {
                case 'cancelled':
                    $occurrence['status'] = 'cancelled';
                    break;
                case 'moved':
                    if (!empty($row['new_location'])) {
                        $occurrence['location_id'] = (int) $row['new_location'];
                    }
                    break;
                case 'rescheduled':
                    $new_date = glover_event_calendar_parse_date($row['new_date'] ?? '');
                    if ($new_date) {
                        $occurrence['status'] = 'rescheduled';
                        $occurrence['date'] = $new_date->format('Y-m-d');
                        $occurrence['start_time'] = !empty($row['new_start_time']) ? (string) $row['new_start_time'] : $start_time;
                        $occurrence['end_time'] = !empty($row['new_end_time'])
                            ? (string) $row['new_end_time']
                            : glover_event_calendar_shift_end_time($start_time, $end_time, $occurrence['start_time']);
                    }
                    break;
            }
        }

        if ($occurrence['date'] >= $start->format('Y-m-d') && $occurrence['date'] <= $end->format('Y-m-d')) {
            $occurrences[] = $occurrence;
        }
    }

    return $occurrences;
}

/**
 * Generate the dates a repeating schedule produces within a range.
 *
 * Mirrors the editor's Schedule summary: the Start Date is the first
 * occurrence counted by "after_count", weekly periods begin on the site's
 * start-of-week day, a numbered day missing from a month (such as the 31st)
 * is skipped, and a Start Date in the last week of its month repeats on the
 * last matching weekday.
 *
 * @param array             $schedule Normalized recurrence fields.
 * @param DateTimeImmutable $start    First date, inclusive.
 * @param DateTimeImmutable $end      Last date, inclusive.
 * @return DateTimeImmutable[]
 */
function glover_event_calendar_get_recurring_dates(array $schedule, DateTimeImmutable $start, DateTimeImmutable $end)
{
    $first = $schedule['start_date'];
    $interval = $schedule['interval'];
    $bound = $end;

    if ('on_date' === $schedule['end_type'] && $schedule['end_date'] && $schedule['end_date'] < $bound) {
        $bound = $schedule['end_date'];
    }

    $limit = 'after_count' === $schedule['end_type'] ? max(1, $schedule['end_count']) : 0;
    $count = 0;
    $dates = array();

    for ($period = 0; $period < GLOVER_EVENT_CALENDAR_MAX_PERIODS; $period++) {
        $candidates = glover_event_calendar_get_period_dates($schedule, $period * $interval);

        if (null === $candidates) {
            break;
        }

        foreach ($candidates as $candidate) {
            if ($candidate > $bound) {
                return $dates;
            }

            if ($candidate < $first) {
                continue;
            }

            $count++;
            if ($candidate >= $start) {
                $dates[] = $candidate;
            }

            if ($limit && $count >= $limit) {
                return $dates;
            }
        }
    }

    return $dates;
}

/**
 * Return the candidate dates for one schedule period, in order.
 *
 * @param array $schedule Normalized recurrence fields.
 * @param int   $offset   Units elapsed since the Start Date's period.
 * @return DateTimeImmutable[]|null Null for an unknown unit.
 */
function glover_event_calendar_get_period_dates(array $schedule, $offset)
{
    $first = $schedule['start_date'];

    switch ($schedule['unit']) {
        case 'day':
            return array($first->modify('+' . $offset . ' days'));

        case 'week':
            $weekdays = array('sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday');
            $week_start_day = (int) get_option('start_of_week', 0);
            $days_since_week_start = ((int) $first->format('w') - $week_start_day + 7) % 7;
            $week_start = $first->modify('-' . $days_since_week_start . ' days')->modify('+' . $offset . ' weeks');
            $selected = array_intersect($weekdays, $schedule['days']) ?: array($weekdays[(int) $first->format('w')]);

            $dates = array();
            for ($i = 0; $i < 7; $i++) {
                $day = $week_start->modify('+' . $i . ' days');
                if (in_array($weekdays[(int) $day->format('w')], $selected, true)) {
                    $dates[] = $day;
                }
            }
            return $dates;

        case 'month':
            $month = $first->modify('first day of this month')->modify('+' . $offset . ' months');

            if ('weekday_position' === $schedule['monthly_basis']) {
                $weekday = $first->format('l');
                $is_last = $first->modify('+7 days')->format('m') !== $first->format('m');
                $position = (int) ceil((int) $first->format('j') / 7);
                $ordinals = array(1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth');
                $phrase = $is_last ? 'last' : $ordinals[$position];

                return array($month->modify($phrase . ' ' . $weekday . ' of this month'));
            }

            $day = (int) $first->format('j');
            if ($day > (int) $month->format('t')) {
                return array();
            }
            return array($month->setDate((int) $month->format('Y'), (int) $month->format('n'), $day));

        case 'year':
            $year = (int) $first->format('Y') + $offset;
            if (!checkdate((int) $first->format('n'), (int) $first->format('j'), $year)) {
                return array();
            }
            return array($first->setDate($year, (int) $first->format('n'), (int) $first->format('j')));
    }

    return null;
}

/**
 * Keep a series' duration when a changed date moves only the start time.
 *
 * Clamps to 23:59:59 so a late new start never wraps past midnight.
 */
function glover_event_calendar_shift_end_time($start_time, $end_time, $new_start_time)
{
    $start = strtotime('1970-01-01 ' . $start_time . ' UTC');
    $end = strtotime('1970-01-01 ' . $end_time . ' UTC');
    $new_start = strtotime('1970-01-01 ' . $new_start_time . ' UTC');

    if (false === $start || false === $end || false === $new_start) {
        return $end_time;
    }

    return gmdate('H:i:s', min($new_start + max(0, $end - $start), 86399));
}

/**
 * Build one normalized occurrence.
 */
function glover_event_calendar_build_occurrence($post_id, DateTimeImmutable $date, $start_time, $end_time, $status, $location_id)
{
    return array(
        'post_id' => (int) $post_id,
        'date' => $date->format('Y-m-d'),
        'start_time' => $start_time,
        'end_time' => $end_time,
        'time_tbd' => '' === $start_time,
        'status' => $status,
        'original_date' => $date->format('Y-m-d'),
        'location_id' => (int) $location_id,
        'note' => '',
    );
}

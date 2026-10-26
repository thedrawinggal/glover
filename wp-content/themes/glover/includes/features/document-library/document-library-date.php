<?php

if (!defined('ABSPATH')) {
    exit;
}

/*
 * Date of the Document is entered as three fields, Month (a select), Day
 * (1 to 31) and Year (1984 to next year), in the `document_date_parts` group.
 * They are an entry form only: they are never saved, and on save they are
 * combined into `document_date` (Ymd), which every query, sort and filter
 * reads. When a document is edited they are filled in from `document_date`.
 * `document_date` itself is never shown.
 */

const GLOVER_DOCUMENT_DATE_PARTS_FIELD = 'field_glover_document_date_parts';
const GLOVER_DOCUMENT_DATE_PARTS = array(
    'month' => 'field_glover_document_date_month',
    'day' => 'field_glover_document_date_day',
    'year' => 'field_glover_document_date_year',
);
const GLOVER_DOCUMENT_DATE_FIRST_YEAR = 1984;

/**
 * Month, day and year submitted for the date, or null when any is missing.
 *
 * @return array|null `month`, `day` and `year` as integers.
 */
function glover_document_date_submitted_parts()
{
    $submitted = isset($_POST['acf'][GLOVER_DOCUMENT_DATE_PARTS_FIELD]) ? wp_unslash($_POST['acf'][GLOVER_DOCUMENT_DATE_PARTS_FIELD]) : null; // phpcs:ignore WordPress.Security.NonceVerification -- ACF verifies the form nonce.

    if (!is_array($submitted)) {
        return null;
    }

    $parts = array();

    foreach (GLOVER_DOCUMENT_DATE_PARTS as $part => $key) {
        if (!isset($submitted[$key]) || !is_numeric($submitted[$key])) {
            return null;
        }

        $parts[$part] = (int) $submitted[$key];
    }

    return $parts;
}

/**
 * Never show the stored date field; Month, Day and Year stand in for it.
 *
 * @return false
 */
function glover_document_date_hide_stored_field()
{
    return false;
}
add_filter('acf/prepare_field/key=field_glover_document_date', 'glover_document_date_hide_stored_field');

/**
 * Month opens on "Choose a Month" rather than January, so nobody saves
 * January by accident; Month is required.
 *
 * @param array $field Field definition.
 * @return array
 */
function glover_document_date_month_prompt($field)
{
    $field['choices'] = array('' => 'Choose a Month') + $field['choices'];

    return $field;
}
add_filter('acf/prepare_field/key=field_glover_document_date_month', 'glover_document_date_month_prompt');

/**
 * Year runs from 1984 to next year, for agendas posted ahead of a meeting.
 *
 * @param array $field Field definition.
 * @return array
 */
function glover_document_date_year_range($field)
{
    $field['min'] = GLOVER_DOCUMENT_DATE_FIRST_YEAR;
    $field['max'] = (int) wp_date('Y') + 1;

    return $field;
}
add_filter('acf/load_field/key=field_glover_document_date_year', 'glover_document_date_year_range');

/**
 * Fill Month, Day and Year from the stored date when a document is edited.
 *
 * @param mixed      $value   Loaded value (unused; the parts are never saved).
 * @param int|string $post_id Post ID.
 * @param array      $field   Field definition.
 * @return string
 */
function glover_document_date_load_part($value, $post_id, $field)
{
    $stored = is_numeric($post_id) ? (string) get_post_meta((int) $post_id, 'document_date', true) : '';

    if (!preg_match('/^(\d{4})(\d{2})(\d{2})$/', $stored, $matches)) {
        return '';
    }

    switch ($field['key']) {
        case GLOVER_DOCUMENT_DATE_PARTS['year']:
            return $matches[1];
        case GLOVER_DOCUMENT_DATE_PARTS['month']:
            return $matches[2];
        default:
            return (string) (int) $matches[3];
    }
}

foreach (GLOVER_DOCUMENT_DATE_PARTS as $glover_document_date_key) {
    add_filter('acf/load_value/key=' . $glover_document_date_key, 'glover_document_date_load_part', 10, 3);
}
unset($glover_document_date_key);

/**
 * Don't save the group or its parts; `document_date` holds the date. ACF has
 * no per-field version of this filter, so it checks the key.
 *
 * @param mixed $check   Null to let ACF save.
 * @param mixed $value   Value being saved.
 * @param mixed $post_id Post ID.
 * @param array $field   Field definition.
 * @return mixed
 */
function glover_document_date_skip_parts_save($check, $value, $post_id, $field)
{
    $keys = array_merge(array(GLOVER_DOCUMENT_DATE_PARTS_FIELD), array_values(GLOVER_DOCUMENT_DATE_PARTS));

    return isset($field['key']) && in_array($field['key'], $keys, true) ? true : $check;
}
add_filter('acf/pre_update_value', 'glover_document_date_skip_parts_save', 10, 4);

/**
 * The day must exist in the chosen month and year (no February 30).
 *
 * @param bool|string $valid Current validity.
 * @param mixed       $value Submitted day.
 * @return bool|string
 */
function glover_document_date_validate_day($valid, $value)
{
    $parts = glover_document_date_submitted_parts();

    if ($valid !== true || !$parts || $parts['month'] < 1 || $parts['month'] > 12) {
        return $valid;
    }

    $first = gmmktime(0, 0, 0, $parts['month'], 1, max(1, $parts['year']));
    $days = (int) gmdate('t', $first);

    if ($parts['day'] > $days) {
        return sprintf('%s %d has %d days. Enter a day from 1 to %d.', gmdate('F', $first), $parts['year'], $days, $days);
    }

    return $valid;
}
add_filter('acf/validate_value/key=field_glover_document_date_day', 'glover_document_date_validate_day', 10, 2);

/**
 * Before ACF saves, turn the submitted Month, Day and Year into
 * `document_date` (Ymd), so ACF saves it with the other fields. Runs on the
 * public form and the edit screen alike.
 */
function glover_document_date_compose()
{
    $parts = glover_document_date_submitted_parts();

    if (!$parts || !checkdate($parts['month'], $parts['day'], $parts['year'])) {
        return;
    }

    $_POST['acf']['field_glover_document_date'] = sprintf('%04d%02d%02d', $parts['year'], $parts['month'], $parts['day']);
}
add_action('acf/save_post', 'glover_document_date_compose', 5);

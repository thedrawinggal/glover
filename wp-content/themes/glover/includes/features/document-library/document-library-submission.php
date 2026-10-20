<?php

if (!defined('ABSPATH')) {
    exit;
}

const GLOVER_DOCUMENT_LIBRARY_FORM_ID = 'glover-document-submission';
const GLOVER_DOCUMENT_LIBRARY_SUBMIT_TEMPLATE = 'template-document-submission.php';

/**
 * The submission page: the first published page using the submission template.
 */
function glover_document_library_submission_url()
{
    $pages = get_posts(array(
        'post_type' => 'page',
        'post_status' => 'publish',
        'posts_per_page' => 1,
        'fields' => 'ids',
        'meta_key' => '_wp_page_template',
        'meta_value' => GLOVER_DOCUMENT_LIBRARY_SUBMIT_TEMPLATE,
        'no_found_rows' => true,
    ));

    return $pages ? get_permalink($pages[0]) : home_url('/');
}

/**
 * Whether the current request is a submission from the public form.
 */
function glover_document_library_is_form_submission()
{
    return !empty($_POST['glover_document_submission']) && !empty($_POST['_acf_form']);
}

/**
 * Run before any output on the submission page. Sends uploads to the
 * private folder, then lets ACF save the submission.
 */
function glover_document_library_submission_form_head()
{
    if (!function_exists('acf_form_head') || !glover_document_library_user_can_submit()) {
        return;
    }

    if (glover_document_library_is_form_submission()) {
        add_filter('upload_dir', 'glover_document_library_private_upload_dir');
    }

    acf_form_head();
    remove_filter('upload_dir', 'glover_document_library_private_upload_dir');
}

/**
 * Field keys shown on the public submission form, in order.
 *
 * @return string[]
 */
function glover_document_library_form_field_keys()
{
    return array(
        'field_glover_document_file',
        'field_glover_document_boards',
        'field_glover_document_category',
        'field_glover_document_topics',
        'field_glover_document_suggested_topic',
        'field_glover_document_date',
        'field_glover_document_minutes_status',
        'field_glover_document_replaces',
        'field_glover_document_accessibility_confirmed',
        'field_glover_document_legal_confirmed',
        'field_glover_document_reviewer_note',
    );
}

/**
 * Print the submission form.
 */
function glover_document_library_render_submission_form()
{
    acf_form(array(
        'id' => GLOVER_DOCUMENT_LIBRARY_FORM_ID,
        'post_id' => 'new_post',
        'new_post' => array(
            'post_type' => GLOVER_DOCUMENT_LIBRARY_POST_TYPE,
            'post_status' => 'pending',
        ),
        'post_title' => true,
        'post_content' => false,
        'fields' => glover_document_library_form_field_keys(),
        'form_attributes' => array('class' => 'document-form'),
        'uploader' => 'basic',
        'honeypot' => true,
        'label_placement' => 'top',
        'instruction_placement' => 'label',
        'return' => add_query_arg('submitted', '%post_id%', get_permalink()),
        'html_after_fields' => '<input type="hidden" name="glover_document_submission" value="1">',
        'submit_value' => 'Submit document',
        'html_submit_button' => '<button type="submit" class="button button--primary">%s</button>',
        'html_updated_message' => '',
    ));
}

/**
 * Plain-language label and help for the title field on the public form.
 *
 * @param array $field Field definition.
 * @return array
 */
function glover_document_library_title_field($field)
{
    if (is_admin() && !wp_doing_ajax()) {
        return $field;
    }

    $field['label'] = 'Document title';
    $field['instructions'] = 'Use the board, the type of document and its date, for example "Select Board Minutes, October 14, 2026".';

    return $field;
}
add_filter('acf/load_field/name=_post_title', 'glover_document_library_title_field');

/**
 * Apply the upload size cap from Document Library settings.
 *
 * @param array $field Field definition.
 * @return array
 */
function glover_document_library_file_field($field)
{
    $cap = (int) glover_document_library_setting('upload_cap', 0);

    if ($cap > 0) {
        $field['max_size'] = $cap;
    }

    return $field;
}
add_filter('acf/load_field/key=field_glover_document_file', 'glover_document_library_file_field');

/**
 * Fill "This replaces an earlier version" with recent published documents.
 * The public form lists only the submitter's own boards; wp-admin lists all.
 *
 * @param array $field Field definition.
 * @return array
 */
function glover_document_library_replaces_choices($field)
{
    $args = array(
        'post_type' => GLOVER_DOCUMENT_LIBRARY_POST_TYPE,
        'post_status' => 'publish',
        'posts_per_page' => 50,
        'orderby' => 'date',
        'order' => 'DESC',
        'no_found_rows' => true,
    );

    if (!is_admin() || wp_doing_ajax()) {
        $slugs = array_map('glover_document_library_board_term_slug', array_keys(glover_document_library_allowed_boards()));
        $args['tax_query'] = array(array(
            'taxonomy' => GLOVER_DOCUMENT_LIBRARY_BOARD_TAXONOMY,
            'field' => 'slug',
            'terms' => $slugs ? $slugs : array('none'),
        ));
    }

    $field['choices'] = array();

    foreach (get_posts($args) as $document) {
        $date = glover_document_library_display_date($document->ID);
        $field['choices'][(string) $document->ID] = get_the_title($document) . ($date ? ' (' . $date . ')' : '');
    }

    // Keep a saved value selectable in wp-admin even if it is older than the list.
    $saved = isset($field['value']) ? (string) $field['value'] : '';

    if ($saved !== '' && !isset($field['choices'][$saved]) && get_post($saved)) {
        $field['choices'][$saved] = get_the_title((int) $saved);
    }

    return $field;
}
add_filter('acf/load_field/key=field_glover_document_replaces', 'glover_document_library_replaces_choices');

/**
 * Only published documents the submitter could choose may be replaced.
 *
 * @param bool|string $valid Current validity.
 * @param mixed       $value Submitted value.
 * @return bool|string
 */
function glover_document_library_validate_replaces($valid, $value)
{
    if ($valid !== true || $value === '' || $value === null) {
        return $valid;
    }

    $field = glover_document_library_replaces_choices(array('choices' => array()));

    return isset($field['choices'][(string) $value]) ? $valid : 'Choose a document from the list, or leave this empty.';
}
add_filter('acf/validate_value/key=field_glover_document_replaces', 'glover_document_library_validate_replaces', 10, 2);

/**
 * Cross-field rules ACF can't express: minutes need a status.
 */
function glover_document_library_validate_submission()
{
    if (empty($_POST['acf']) || !is_array($_POST['acf'])) {
        return;
    }

    $values = wp_unslash($_POST['acf']);

    if (!array_key_exists('field_glover_document_category', $values)) {
        return;
    }

    $term = get_term((int) $values['field_glover_document_category'], GLOVER_DOCUMENT_LIBRARY_CATEGORY_TAXONOMY);
    $status = isset($values['field_glover_document_minutes_status']) ? $values['field_glover_document_minutes_status'] : '';

    if ($term && !is_wp_error($term) && $term->slug === 'minutes' && !in_array($status, array('draft', 'approved'), true)) {
        acf_add_validation_error('acf[field_glover_document_minutes_status]', 'Choose whether these minutes are a draft or approved by the board.');
    }
}
add_action('acf/validate_save_post', 'glover_document_library_validate_submission', 5);

/**
 * Refuse public-form saves from anyone who isn't a submitter.
 *
 * @param int|string $post_id ACF post ID being created.
 * @param array      $form    Form settings.
 * @return int|string
 */
function glover_document_library_check_submitter($post_id, $form)
{
    if (!empty($form['id']) && $form['id'] === GLOVER_DOCUMENT_LIBRARY_FORM_ID && !glover_document_library_user_can_submit()) {
        wp_die('You do not have permission to submit documents.', 'Forbidden', array('response' => 403));
    }

    return $post_id;
}
add_filter('acf/pre_save_post', 'glover_document_library_check_submitter', 5, 2);

/**
 * Finish a public-form submission: record who sent it, flag possible
 * duplicates, then publish (Auto-approve) or notify the approvers.
 *
 * @param int|string $post_id ACF post ID.
 */
function glover_document_library_complete_submission($post_id)
{
    if (is_admin() || !glover_document_library_is_form_submission() || !is_numeric($post_id)) {
        return;
    }

    $post_id = (int) $post_id;

    if (get_post_type($post_id) !== GLOVER_DOCUMENT_LIBRARY_POST_TYPE || get_post_meta($post_id, '_glover_submitted_by', true)) {
        return;
    }

    $user_id = get_current_user_id();
    update_post_meta($post_id, '_glover_submitted_by', $user_id);
    glover_document_library_log($post_id, 'submitted');

    $duplicates = glover_document_library_find_duplicates($post_id);

    if ($duplicates) {
        update_post_meta($post_id, '_glover_possible_duplicates', $duplicates);
    }

    if (glover_document_library_user_auto_approves($user_id)) {
        update_post_meta($post_id, '_glover_auto_published', 1);
        glover_document_library_log($post_id, 'auto-approved');
        $previous = glover_document_library_allow_publish(true);
        wp_publish_post($post_id);
        glover_document_library_allow_publish($previous);
        glover_document_library_notify_topic_suggestion($post_id);
        return;
    }

    glover_document_library_notify_pending($post_id);
}
add_action('acf/save_post', 'glover_document_library_complete_submission', 20);

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
 * The left side of the sign-in panel for submitters: heading, intro, the
 * Need access line and the document icon, for Submit a Document's
 * signed-out view.
 *
 * @return array Arguments for components/common/sign-in-panel.php.
 */
function glover_document_library_sign_in_panel_args()
{
    $clerk_link = glover_document_library_help_contact_links();

    return array(
        'heading' => 'Sign In to Submit',
        'intro' => 'For town staff and board members posting agendas, minutes and other public records to the Document Library.',
        'access' => 'Need access? Email the Town Clerk at ' . ($clerk_link !== '' ? $clerk_link : "the Town Clerk's office") . '.',
        'icon' => 'document',
    );
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
 * The public submission form's numbered sections and the fields in each, in
 * order. The document title is ACF's own `_post_title` field, so section 1
 * turns it on with `post_title` instead of listing a key.
 *
 * @return array[] Each a `title`, `optional` flag and `fields` (ACF field keys).
 */
function glover_document_library_form_sections()
{
    return array(
        array(
            'title' => 'The Document',
            'optional' => false,
            'post_title' => true,
            'fields' => array('field_glover_document_file'),
        ),
        array(
            'title' => 'Document Details',
            'optional' => false,
            'fields' => array(
                'field_glover_document_boards',
                'field_glover_document_category',
                'field_glover_document_minutes_status',
                GLOVER_DOCUMENT_DATE_PARTS_FIELD,
                'field_glover_document_topics',
            ),
        ),
        array(
            'title' => 'Replacing an Earlier Version',
            'optional' => true,
            'fields' => array('field_glover_document_replaces'),
        ),
        array(
            'title' => 'Before You Submit',
            'optional' => false,
            'fields' => array(
                'field_glover_document_accessibility_confirmed',
                'field_glover_document_legal_confirmed',
                'field_glover_document_reviewer_note',
            ),
        ),
    );
}

/**
 * Field keys shown on the public submission form, in order.
 *
 * @return string[]
 */
function glover_document_library_form_field_keys()
{
    return array_merge(...array_column(glover_document_library_form_sections(), 'fields'));
}

/**
 * Print the submission form: one outer form holding a card per section.
 * Each card is its own acf_form() with 'form' => false, which ACF supports
 * inside a shared form; it merges every card's fields into one save.
 */
function glover_document_library_render_submission_form()
{
    $sections = glover_document_library_form_sections();
    $last = count($sections) - 1;
    ?>
    <form id="<?php echo esc_attr(GLOVER_DOCUMENT_LIBRARY_FORM_ID); ?>" class="document-form acf-form" action="" method="post" enctype="multipart/form-data">
        <p class="document-form__required">Fields marked <span class="acf-required" aria-hidden="true">*</span> are required.</p>

        <?php foreach ($sections as $index => $section) : ?>
            <?php $heading_id = GLOVER_DOCUMENT_LIBRARY_FORM_ID . '-section-' . ($index + 1); ?>
            <section class="form-section" aria-labelledby="<?php echo esc_attr($heading_id); ?>">
                <div class="form-section__header">
                    <span class="form-section__number" aria-hidden="true"><?php echo (int) ($index + 1); ?></span>
                    <h2 id="<?php echo esc_attr($heading_id); ?>" class="form-section__title">
                        <?php echo esc_html($section['title']); ?>
                        <?php if ($section['optional']) : ?>
                            <span class="form-section__optional">(Optional)</span>
                        <?php endif; ?>
                    </h2>
                </div>
                <?php
                acf_form(array(
                    'id' => GLOVER_DOCUMENT_LIBRARY_FORM_ID,
                    'form' => false,
                    'post_id' => 'new_post',
                    'new_post' => array(
                        'post_type' => GLOVER_DOCUMENT_LIBRARY_POST_TYPE,
                        'post_status' => 'pending',
                    ),
                    'post_title' => !empty($section['post_title']),
                    'post_content' => false,
                    'fields' => $section['fields'],
                    'uploader' => 'basic',
                    'honeypot' => $index === $last,
                    'label_placement' => 'top',
                    'instruction_placement' => 'label',
                    'return' => add_query_arg('submitted', '%post_id%', get_permalink()),
                    'html_after_fields' => $index === $last ? '<input type="hidden" name="glover_document_submission" value="1">' : '',
                    'html_updated_message' => '',
                ));
                ?>
                <?php if ($index === $last) : ?>
                    <div class="acf-form-submit document-form__submit">
                        <button type="submit" class="button button--primary button--large">Submit Document</button>
                        <span class="acf-spinner"></span>
                    </div>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    </form>
    <?php
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

    $field['label'] = 'Document Title';
    // The board and date are their own fields and show beside the title
    // everywhere, so the title says only what the document is.
    $field['instructions'] = '<strong>Titling Instructions:</strong> Name what the document is. Leave out the board and the date; they come from the fields below. For example: "Regular Meeting Minutes", "Public Hearing Notice: Zoning Amendments", "Dog Control Ordinance". Add a year only when it is part of the name, as in "2025 Annual Town Report".';

    return $field;
}
add_filter('acf/load_field/name=_post_title', 'glover_document_library_title_field');

/**
 * The accessibility checkbox links to the requirements guide. On the public
 * form that link (set in the ACF field) opens the guide's dialog; wp-admin has
 * no dialog, so there it opens the requirements page in a new tab instead,
 * or is left out when no page is chosen.
 *
 * @param array $field Field definition.
 * @return array
 */
function glover_document_library_accessibility_link($field)
{
    if (!is_admin() || wp_doing_ajax()) {
        return $field;
    }

    $page_id = (int) glover_document_library_setting('requirements_page', 0);
    $url = $page_id && get_post_status($page_id) === 'publish' ? get_permalink($page_id) : '';

    $field['message'] = $url
        ? str_replace('href="#glover-requirements" data-dialog-open="glover-requirements"', 'href="' . esc_url($url) . '" target="_blank" rel="noopener"', $field['message'])
        : preg_replace('#\s*<a [^>]*>.*?</a>\.#', '', $field['message']);

    return $field;
}
add_filter('acf/load_field/key=field_glover_document_accessibility_confirmed', 'glover_document_library_accessibility_link');

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
 * Boards whose documents "Replace an earlier version of a document" may list: the
 * boards chosen in the Board field, limited to the ones this person may post
 * for (approvers may post for all).
 *
 * @param mixed $chosen Board IDs chosen on the form.
 * @return int[]
 */
function glover_document_library_replaces_boards($chosen)
{
    $allowed = array_keys(glover_document_library_allowed_boards());
    $chosen = array_filter(array_map('intval', (array) $chosen));

    return array_values(array_intersect($chosen ? $chosen : $allowed, $allowed));
}

/**
 * "Replace an earlier version of a document" is an ACF Post Object field: it searches
 * published documents as you type, and ACF conditional logic shows it only
 * once a board is chosen. ACF has no setting to filter by another field's
 * value, so assets/replaces-boards.js adds the chosen boards to each search
 * (ACF's select2_ajax_data hook) and this keeps results to those boards.
 *
 * @param array $args WP_Query arguments.
 * @return array
 */
function glover_document_library_replaces_query($args)
{
    $chosen = isset($_POST['glover_boards']) ? wp_unslash($_POST['glover_boards']) : array(); // phpcs:ignore WordPress.Security.NonceVerification -- ACF verifies the field nonce.
    $slugs = array_map('glover_document_library_board_term_slug', glover_document_library_replaces_boards($chosen));
    $args['tax_query'] = array(array(
        'taxonomy' => GLOVER_DOCUMENT_LIBRARY_BOARD_TAXONOMY,
        'field' => 'slug',
        'terms' => $slugs ? $slugs : array('none'),
    ));
    // Newest first: the document being replaced is usually a recent one.
    $args['meta_key'] = 'document_date';
    $args['orderby'] = array('meta_value' => 'DESC', 'title' => 'ASC');

    // Search the whole choice label (board, category, date, title), not only
    // the title: every word typed must appear somewhere in it, so "May",
    // "plans studies" or "select board 2025" all find documents.
    if (isset($args['s']) && trim($args['s']) !== '') {
        $words = preg_split('/\s+/', glover_document_library_replaces_search_text($args['s']), -1, PREG_SPLIT_NO_EMPTY);
        $candidates = get_posts(array_merge($args, array('s' => '', 'fields' => 'ids', 'posts_per_page' => -1, 'paged' => 1, 'no_found_rows' => true)));
        $matches = array_values(array_filter($candidates, function ($id) use ($words) {
            $haystack = glover_document_library_replaces_search_text(
                glover_document_library_replaces_label('', get_post($id)) . ' ' . glover_document_library_display_date($id, 'F j, Y')
            );

            foreach ($words as $word) {
                if (strpos($haystack, $word) === false) {
                    return false;
                }
            }

            return true;
        }));

        unset($args['s']);
        $args['post__in'] = $matches ? $matches : array(0);
    }

    return $args;
}

/**
 * Lowercase text for the replace search, with "&" read as "and" and
 * punctuation as spaces, so "Plans & Studies" matches "plans and studies".
 *
 * @param string $text Label or search words.
 * @return string
 */
function glover_document_library_replaces_search_text($text)
{
    $text = strtolower(html_entity_decode((string) $text, ENT_QUOTES));
    $text = str_replace('&', ' and ', $text);

    return trim(preg_replace('/[^a-z0-9]+/', ' ', $text));
}
add_filter('acf/fields/post_object/query/key=field_glover_document_replaces', 'glover_document_library_replaces_query');

/**
 * On the public form, Replace sits in its own section card, and ACF looks for
 * a condition's field only within the same card, so its "once a board is
 * chosen" condition can never find Board there. The public form shows it all
 * the time instead; until a board is chosen, its search lists the
 * submitter's own boards (glover_document_library_replaces_boards()).
 * wp-admin keeps the condition.
 *
 * @param array $field Field definition.
 * @return array
 */
function glover_document_library_replaces_field($field)
{
    if (!is_admin() || wp_doing_ajax()) {
        $field['conditional_logic'] = 0;
    }

    return $field;
}
add_filter('acf/load_field/key=field_glover_document_replaces', 'glover_document_library_replaces_field');

/**
 * Titles repeat ("Regular Meeting Minutes"), so each choice in Replace an
 * Earlier Version reads "Select Board | Minutes | Oct 14, 2026 | Regular
 * Meeting Minutes": board, category, date, title. Used in the search list
 * and for the chosen document, on the public form and wp-admin.
 *
 * @param string  $text  Choice label.
 * @param WP_Post $post  Document.
 * @return string
 */
function glover_document_library_replaces_label($text, $post)
{
    $parts = glover_document_library_naming_parts($post->ID);

    return implode(' | ', array_filter(array(
        html_entity_decode(implode(', ', $parts['boards']), ENT_QUOTES),
        html_entity_decode($parts['category'], ENT_QUOTES),
        glover_document_library_display_date($post->ID),
        html_entity_decode(get_the_title($post), ENT_QUOTES),
    )));
}
add_filter('acf/fields/post_object/result/key=field_glover_document_replaces', 'glover_document_library_replaces_label', 10, 2);

/**
 * The search list is only a convenience, so check the submitted choice too:
 * a published document from one of the boards chosen on the form.
 *
 * @param bool|string $valid Current validity.
 * @param mixed       $value Submitted value.
 * @return bool|string
 */
function glover_document_library_validate_replaces($valid, $value)
{
    if ($valid !== true || empty($value)) {
        return $valid;
    }

    $chosen = isset($_POST['acf']['field_glover_document_boards']) ? wp_unslash($_POST['acf']['field_glover_document_boards']) : array(); // phpcs:ignore WordPress.Security.NonceVerification -- ACF verifies the form nonce.
    $slugs = array_map('glover_document_library_board_term_slug', glover_document_library_replaces_boards($chosen));
    $found = get_posts(array(
        'post_type' => GLOVER_DOCUMENT_LIBRARY_POST_TYPE,
        'post_status' => 'publish',
        'post__in' => array((int) $value),
        'fields' => 'ids',
        'no_found_rows' => true,
        'tax_query' => array(array(
            'taxonomy' => GLOVER_DOCUMENT_LIBRARY_BOARD_TAXONOMY,
            'field' => 'slug',
            'terms' => $slugs ? $slugs : array('none'),
        )),
    ));

    return $found ? $valid : 'Choose a document from one of the boards selected above, or leave this empty.';
}
add_filter('acf/validate_value/key=field_glover_document_replaces', 'glover_document_library_validate_replaces', 10, 2);

/**
 * The small script that tells the replaces search which boards are chosen.
 * ACF loads its own scripts first, on both the public form and wp-admin.
 */
function glover_document_library_replaces_script()
{
    $file = __DIR__ . '/assets/replaces-boards.js';
    wp_enqueue_script('glover-document-replaces', get_template_directory_uri() . '/includes/features/document-library/assets/replaces-boards.js', array('acf-input'), (string) filemtime($file), true);
}
add_action('acf/input/admin_enqueue_scripts', 'glover_document_library_replaces_script');

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
        return;
    }

    glover_document_library_queue_pending_notice();
}
add_action('acf/save_post', 'glover_document_library_complete_submission', 20);

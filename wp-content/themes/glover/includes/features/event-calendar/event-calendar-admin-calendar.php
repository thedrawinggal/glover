<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Query argument that switches the Events list screen to the calendar.
 */
const GLOVER_EVENT_CALENDAR_VIEW_ARG = 'view';

/**
 * Query argument carrying a date into a new Event from the calendar.
 */
const GLOVER_EVENT_CALENDAR_DATE_ARG = 'glover_event_date';

/**
 * Whether the current request is the admin Events calendar view.
 */
function glover_event_calendar_is_calendar_view()
{
    global $typenow;

    return 'community-events' === $typenow
        && isset($_GET[GLOVER_EVENT_CALENDAR_VIEW_ARG])
        && 'calendar' === sanitize_key(wp_unslash($_GET[GLOVER_EVENT_CALENDAR_VIEW_ARG]));
}

/**
 * Render the List / Calendar tabs.
 *
 * @param string $current Either 'list' or 'calendar'.
 */
function glover_event_calendar_render_view_tabs($current)
{
    $tabs = array(
        'list' => array(
            'label' => __('List', 'glover'),
            'url' => admin_url('edit.php?post_type=community-events'),
        ),
        'calendar' => array(
            'label' => __('Calendar', 'glover'),
            'url' => admin_url('edit.php?post_type=community-events&' . GLOVER_EVENT_CALENDAR_VIEW_ARG . '=calendar'),
        ),
    );

    echo '<nav class="nav-tab-wrapper wp-clearfix" aria-label="' . esc_attr__('Event views', 'glover') . '">';
    foreach ($tabs as $key => $tab) {
        $is_current = $key === $current;
        printf(
            '<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s</a>',
            esc_url($tab['url']),
            $is_current ? ' nav-tab-active' : '',
            $is_current ? ' aria-current="page"' : '',
            esc_html($tab['label'])
        );
    }
    echo '</nav>';
}

/**
 * Print the tabs above the native Events list table.
 *
 * The views filter runs just before the list's status links, below the page
 * heading, which is where WordPress places its own screen tabs.
 */
function glover_event_calendar_list_view_tabs($views)
{
    glover_event_calendar_render_view_tabs('list');

    return $views;
}
add_filter('views_edit-community-events', 'glover_event_calendar_list_view_tabs');

/**
 * Replace the Events list with the calendar when the calendar tab is chosen.
 */
function glover_event_calendar_maybe_render_calendar_view()
{
    global $title, $parent_file, $submenu_file;

    if (!glover_event_calendar_is_calendar_view()) {
        return;
    }

    $post_type_object = get_post_type_object('community-events');
    if (!$post_type_object || !current_user_can($post_type_object->cap->edit_posts)) {
        wp_die(esc_html__('Sorry, you are not allowed to view events.', 'glover'), 403);
    }

    $title = $post_type_object->labels->name;
    $parent_file = 'edit.php?post_type=community-events';
    $submenu_file = 'edit.php?post_type=community-events';

    require_once ABSPATH . 'wp-admin/admin-header.php';
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline"><?php echo esc_html($post_type_object->labels->name); ?></h1>
        <?php if (current_user_can($post_type_object->cap->create_posts)) : ?>
            <a href="<?php echo esc_url(admin_url('post-new.php?post_type=community-events')); ?>" class="page-title-action"><?php echo esc_html($post_type_object->labels->add_new_item); ?></a>
        <?php endif; ?>
        <hr class="wp-header-end">
        <?php glover_event_calendar_render_view_tabs('calendar'); ?>
        <div id="glover-event-calendar" class="glover-event-calendar" aria-busy="true">
            <noscript><p><?php esc_html_e('The calendar requires JavaScript. Use the List tab to manage events.', 'glover'); ?></p></noscript>
        </div>
    </div>
    <?php
    require_once ABSPATH . 'wp-admin/admin-footer.php';
    exit;
}
add_action('load-edit.php', 'glover_event_calendar_maybe_render_calendar_view');

/**
 * Load FullCalendar and the calendar behavior on the calendar view only.
 */
function glover_event_calendar_enqueue_calendar_assets($hook_suffix)
{
    if ('edit.php' !== $hook_suffix || !glover_event_calendar_is_calendar_view()) {
        return;
    }

    $feature_path = __DIR__;
    $feature_url = get_template_directory_uri() . '/includes/features/event-calendar';

    wp_enqueue_script(
        'fullcalendar',
        $feature_url . '/vendor/fullcalendar/index.global.min.js',
        array(),
        '6.1.20',
        true
    );

    wp_enqueue_script(
        'glover-event-calendar-admin-calendar',
        $feature_url . '/event-calendar-admin-calendar.js',
        array('fullcalendar'),
        (string) filemtime($feature_path . '/event-calendar-admin-calendar.js'),
        true
    );

    wp_enqueue_style(
        'glover-event-calendar-admin-calendar',
        $feature_url . '/event-calendar-admin-calendar.css',
        array(),
        (string) filemtime($feature_path . '/event-calendar-admin-calendar.css')
    );

    $initial_month = '';
    if (isset($_GET['month'])) {
        $month = sanitize_text_field(wp_unslash($_GET['month']));
        if (glover_event_calendar_parse_date($month . '-01')) {
            $initial_month = $month . '-01';
        }
    }

    $settings = array(
        'restUrl' => rest_url('glover/v1/admin-calendar'),
        'nonce' => wp_create_nonce('wp_rest'),
        'newEventUrl' => admin_url('post-new.php?post_type=community-events'),
        'dateArg' => GLOVER_EVENT_CALENDAR_DATE_ARG,
        'firstDay' => (int) get_option('start_of_week', 0),
        'initialDate' => $initial_month,
        'canCreate' => current_user_can(get_post_type_object('community-events')->cap->create_posts),
        'i18n' => array(
            'add' => __('Add', 'glover'),
            'addOn' => __('Add event on %s', 'glover'),
            'cancelled' => __('Canceled', 'glover'),
            'rescheduled' => __('Rescheduled', 'glover'),
            'draft' => __('Draft', 'glover'),
            'timeTbd' => __('Time TBD', 'glover'),
            'loadError' => __('Events could not be loaded. Reload the page to try again.', 'glover'),
        ),
    );

    wp_add_inline_script(
        'glover-event-calendar-admin-calendar',
        'window.gloverEventCalendar = ' . wp_json_encode($settings) . ';',
        'before'
    );
}
add_action('admin_enqueue_scripts', 'glover_event_calendar_enqueue_calendar_assets');

/**
 * Register the calendar's event feed.
 */
function glover_event_calendar_register_admin_calendar_route()
{
    register_rest_route(
        'glover/v1',
        '/admin-calendar',
        array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => 'glover_event_calendar_admin_calendar_feed',
            'permission_callback' => function () {
                $post_type_object = get_post_type_object('community-events');

                return $post_type_object && current_user_can($post_type_object->cap->edit_posts);
            },
            'args' => array(
                'start' => array(
                    'required' => true,
                    'validate_callback' => function ($value) {
                        return (bool) glover_event_calendar_parse_date((string) $value);
                    },
                ),
                'end' => array(
                    'required' => true,
                    'validate_callback' => function ($value) {
                        return (bool) glover_event_calendar_parse_date((string) $value);
                    },
                ),
            ),
        )
    );
}
add_action('rest_api_init', 'glover_event_calendar_register_admin_calendar_route');

/**
 * Return occurrences in FullCalendar's event format.
 *
 * FullCalendar sends an exclusive end date, so the last requested day is
 * the day before it.
 */
function glover_event_calendar_admin_calendar_feed(WP_REST_Request $request)
{
    $start = glover_event_calendar_parse_date($request['start']);
    $end = glover_event_calendar_parse_date($request['end'])->modify('-1 day');

    if ($end < $start || $start->diff($end)->days > 62) {
        return new WP_Error('glover_invalid_range', __('Request at most one calendar month at a time.', 'glover'), array('status' => 400));
    }

    $occurrences = glover_event_calendar_get_occurrences_in_range(
        $start->format('Y-m-d'),
        $end->format('Y-m-d'),
        array('publish', 'future', 'draft', 'pending', 'private')
    );

    $events = array();
    foreach ($occurrences as $occurrence) {
        $post = get_post($occurrence['post_id']);
        $timed = !$occurrence['time_tbd'];

        $events[] = array(
            'id' => $occurrence['post_id'] . '-' . $occurrence['original_date'],
            'title' => '' !== $post->post_title ? $post->post_title : __('(no title)', 'glover'),
            'start' => $timed ? $occurrence['date'] . 'T' . $occurrence['start_time'] : $occurrence['date'],
            'end' => $timed && $occurrence['end_time'] ? $occurrence['date'] . 'T' . $occurrence['end_time'] : null,
            'allDay' => !$timed,
            'url' => get_edit_post_link($post->ID, 'raw'),
            'extendedProps' => array(
                'status' => $occurrence['status'],
                'postStatus' => $post->post_status,
                'timeTbd' => $occurrence['time_tbd'],
            ),
        );
    }

    return rest_ensure_response($events);
}

/**
 * Return the date passed from the calendar's Add button, as Ymd, or ''.
 */
function glover_event_calendar_get_prefill_date()
{
    global $pagenow, $typenow;

    if ('post-new.php' !== $pagenow || 'community-events' !== $typenow || empty($_GET[GLOVER_EVENT_CALENDAR_DATE_ARG])) {
        return '';
    }

    $date = glover_event_calendar_parse_date(sanitize_text_field(wp_unslash($_GET[GLOVER_EVENT_CALENDAR_DATE_ARG])));

    return $date ? $date->format('Ymd') : '';
}

/**
 * Start a new Event's specific dates with the day chosen on the calendar.
 */
function glover_event_calendar_prefill_specific_occurrences($value)
{
    $date = glover_event_calendar_get_prefill_date();

    if (!$date || !empty($value)) {
        return $value;
    }

    return array(
        array(
            'field_glover_specific_occurrence_date' => $date,
            'field_glover_specific_occurrence_status' => 'scheduled',
        ),
    );
}
add_filter('acf/load_value/key=field_glover_specific_occurrences', 'glover_event_calendar_prefill_specific_occurrences');

/**
 * Use the same day as the Start Date if the editor switches to repeating.
 */
function glover_event_calendar_prefill_start_date($value)
{
    $date = glover_event_calendar_get_prefill_date();

    return $date && empty($value) ? $date : $value;
}
add_filter('acf/load_value/key=field_glover_start_date', 'glover_event_calendar_prefill_start_date');

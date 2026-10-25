<?php
// Young Serif for headings, Figtree for everything else (docs/design/spec-plate.html).
const GLOVER_FONTS_URL = 'https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700&family=Young+Serif&display=swap';

// theme setup
function glover_theme_setup()
{
    add_theme_support('post-thumbnails');
    add_theme_support('title-tag');

    // One content stylesheet for every editor: the block editor (Posts, Pages)
    // and TinyMCE (custom post types, ACF WYSIWYG fields). Built from
    // src/styles/admin-editor.scss; WordPress skips it until the file exists.
    add_theme_support('editor-styles');
    add_editor_style(array(str_replace(',', '%2C', GLOVER_FONTS_URL), 'dist/styles/admin-editor.css'));
    load_theme_textdomain('glover', get_template_directory() . '/languages');

    // Register menus
    function register_menus()
    {
        register_nav_menu('main-menu', __('Main Menu'));
        register_nav_menu('footer-menu', __('Footer Menu'));
    }

    add_action('init', 'register_menus');
}

add_action('after_setup_theme', 'glover_theme_setup');

// enqueue project-specific scripts/CSS
function glover_enqueue_scripts()
{
    // TODO: if this project uses google maps, uncomment this and change the conditional logic below so that it only loads on pages that use it. You may need to update how it fetches the API key from ACF as well.
    //    if (is_single() && ('news' == get_post_type() || 'project' == get_post_type())) {
    //        wp_enqueue_script(
    //            'google-maps',
    //            'https://maps.googleapis.com/maps/api/js?key=' . get_field('google_maps_api_key', 'option') . '&libraries=places&ver=3&callback=Function.prototype&language=en_US',
    //            array(),
    //            null,
    //            array() // load Google Maps eagerly in order to avoid race conditions
    //        );
    //    }

    // if this project will need jQuery, deregister and re-enqueue jQuery
    wp_deregister_script('jquery');
    wp_enqueue_script(
        'jquery',
        'https://code.jquery.com/jquery-3.7.1.min.js',
        array(),
        null,
        array(
            'in_footer' => true,
            'strategy' => 'async',
        )
    );

    // Enqueue site CSS and JS bundles.
    // Use current timestamp for JS/CSS bundle version for dev environments only.
    // Before doing a production deployment that involves CSS or JS bundle changes, change the below bundle version
    // numbers (to make browsers fetch the latest versions).
    $site_domain = str_replace(array('http://', 'https://'), '', get_site_url());
    $is_dev_environment = str_starts_with($site_domain, 'localhost');
    wp_enqueue_script(
        'glover-app-script',
        get_template_directory_uri() . '/dist/js/app.js',
        array(),
        $is_dev_environment ? time() : '1.0.1',
        array(
            'in_footer' => true,
            'strategy' => 'async',
        )
    );
    wp_enqueue_style('glover-fonts', GLOVER_FONTS_URL, array(), null);
    wp_enqueue_style(
        'glover-app-style',
        get_template_directory_uri() . '/dist/styles/app.css',
        array('glover-fonts'),
        $is_dev_environment ? time() : '1.0.1',
        'all'
    );
}

add_action('wp_enqueue_scripts', 'glover_enqueue_scripts');

// Enqueue admin area styles
add_action('admin_enqueue_scripts', 'enqueue_admin_styles');
function enqueue_admin_styles()
{
    $admin_style = get_stylesheet_directory() . '/style-admin.css';

    wp_enqueue_style('admin-style', get_stylesheet_directory_uri() . '/style-admin.css', array(), filemtime($admin_style));
}

// TinyMCE caches editor stylesheets by WordPress version only, so version the
// theme's by file date to show changes as soon as it is rebuilt.
add_filter('editor_stylesheets', 'glover_version_editor_stylesheets');
function glover_version_editor_stylesheets($stylesheets)
{
    $path = get_stylesheet_directory() . '/dist/styles/admin-editor.css';
    $url = get_stylesheet_directory_uri() . '/dist/styles/admin-editor.css';

    if (!file_exists($path)) {
        return $stylesheets;
    }

    return array_map(function ($stylesheet) use ($url, $path) {
        return $stylesheet === $url ? add_query_arg('v', filemtime($path), $url) : $stylesheet;
    }, $stylesheets);
}

// add support for acf options page
if (function_exists('acf_add_options_page')) {
    acf_add_options_page(array(
        'page_title' => 'Theme General Settings',
        'menu_title' => 'Theme Settings',
        'menu_slug' => 'theme-general-settings',
        'capability' => 'edit_posts',
        'redirect' => false
    ));

    acf_add_options_sub_page(array(
        'page_title' => 'Theme Header Settings',
        'menu_title' => 'Header',
        'parent_slug' => 'theme-general-settings',
    ));

    acf_add_options_sub_page(array(
        'page_title' => 'Theme Footer Settings',
        'menu_title' => 'Footer',
        'parent_slug' => 'theme-general-settings',
    ));
}

// force yoast to bottom
function force_yoast_to_bottom()
{
    return 'low';
}

add_filter('wpseo_metabox_prio', 'force_yoast_to_bottom');

// fake og image url to use as the default
function get_default_og_image_url()
{
    return 'default';
}

// This adds a fake default og image to every post. This ensures that the wpseo_opengraph_image filter runs for each
// post. We need wpseo_opengraph_image to run so that we can programmatically override the opengraph image for each
// post. wpseo_opengraph_image doesn't run for a post unless there's at least one opengraph image specified for it. This
// seems to require setting an image in each individual post's Yoast Social Media settings. Setting up this fake entry
// here instead means we don't have to do that.
function default_og_image($image)
{
    if (!$image->has_images()) {
        $image->add_image(get_default_og_image_url());
    }
}

add_action('wpseo_add_opengraph_additional_images', 'default_og_image');

function get_single_image_url($single_image, $image_version)
{
    if (
        isset($single_image[$image_version]) &&
        $single_image[$image_version] &&
        $single_image[$image_version]['url']
    ) {
        return $single_image[$image_version]['url'];
    } else {
        return null;
    }
}

// set opengraph image - overrides the default set by default_og_image().
function override_og_image($image_url)
{
    global $post;
    $default_og_image_url = get_default_og_image_url();

    // if the $image_url is not the default, that means someone set a Social Media image for the post in its Yoast settings, and we should use that. Otherwise, override the default here.
    if ($image_url == $default_og_image_url) {
        // if the post has a "featured image" defined, use that. (We may not have this field enabled for every project.)
        if (has_post_thumbnail($post->ID)) {
            $thumbnail_id = get_post_thumbnail_id($post->ID);
            $thumbnail_url = wp_get_attachment_image_src($thumbnail_id, 'full');
            return $thumbnail_url[0];
        }

        // TODO in each project: customize this logic to find and return the ACF image we want to use as the opengraph image.
        $acf_fields = get_field_objects($post->ID);
        foreach ($acf_fields as $acf_field_label => $acf_field) {
            if ($acf_field_label == 'featured_image') {
                // assume this is a Single Image. prefer the mobile image if it exists.
                if ($acf_field['value']) {
                    $mobile_image_url = get_single_image_url($acf_field['value'], 'mobile_image');
                    $desktop_image_url = get_single_image_url($acf_field['value'], 'desktop_image');
                    if ($mobile_image_url) {
                        return $mobile_image_url;
                    } else if ($desktop_image_url) {
                        return $desktop_image_url;
                    }
                }
            }

            if ($acf_field_label == 'banner_image') {
                // assume this is a Single Image. prefer the mobile image if it exists.
                if ($acf_field['value']) {
                    $mobile_image_url = get_single_image_url($acf_field['value'], 'mobile_image');
                    $desktop_image_url = get_single_image_url($acf_field['value'], 'desktop_image');
                    if ($mobile_image_url) {
                        return $mobile_image_url;
                    } else if ($desktop_image_url) {
                        return $desktop_image_url;
                    }
                }
            }
        }
    }

    // TODO in each project: fall back to a default if still using the invalid "default" image url.
    if ($image_url == $default_og_image_url) {
        // $footer_logo_single_image = get_field('footer_logo', 'option');
        // if ($footer_logo_single_image) {
        //     return $footer_logo_single_image['desktop_image']['url'];
        // }
    }

    return $image_url;
}

add_filter('wpseo_opengraph_image', 'override_og_image');

// Convenience version of get_template_part() where we don't have to pass a name argument.
function get_generic_template_part($slug, $args = array())
{
    get_template_part($slug, null, $args);
}

// Returns a template part as a string instead of echoing it immediately like get_template_part().
// Leaves get_template_part() name argument null. Useful if you want to fetch a template as a string inside a
// functions.php function or when otherwise outside the context of a PHP template file.
function load_template_part($template_name, $args = array())
{
    ob_start();
    get_template_part($template_name, null, $args);
    $template_as_string = ob_get_contents();
    ob_end_clean();
    return $template_as_string;
}

// get url of an ACF image at a specific size
function get_acf_image_url_for_size($image_id, $size)
{
    $image_url = null;
    if ($image_id) {
        $image_src = wp_get_attachment_image_src($image_id, $size);
        if ($image_src && count($image_src) > 0) {
            $image_url = $image_src[0];
        }
    }

    return $image_url;
}

function get_current_query_string_as_array($multivalue_keys)
{
    $url_parts = parse_url($_SERVER['REQUEST_URI']);
    if ($url_parts && $url_parts['query']) {
        $query_string = $url_parts['query'];
    } else {
        $query_string = '';
    }
    return query_string_to_array($query_string, $multivalue_keys);
}

function query_string_to_array($query_string, $multivalue_keys)
{
    $query_string_array = array();
    $query_string_parts = explode('&', $query_string);
    foreach ($query_string_parts as $query_string_part) {
        $query_string_kv_pair = explode('=', $query_string_part, 2);
        $key = $query_string_kv_pair[0];
        $value = $query_string_kv_pair[1];
        if ($key != '') {
            if (!array_key_exists($key, $query_string_array)) {
                $query_string_array[$key] = array();
            }
            if (in_array($key, $multivalue_keys)) {
                $query_string_array[$key][] = $value;
            } else {
                $query_string_array[$key] = $value;
            }
        }
    }
    return $query_string_array;
}

function get_address_from_map_pin($map_pin): array
{
    $address1 = '';
    $address2 = '';
    $address3 = '';
    $address4 = '';

    if (isset($map_pin['name'])) {
        $address1 = $map_pin['name'];
    }

    if (isset($map_pin['street_number'])) {
        $address2 .= $map_pin['street_number'];
    }
    if (isset($map_pin['street_name'])) {
        if (isset($map_pin['street_number'])) {
            $address2 .= ' ';
        }
        $address2 .= $map_pin['street_name'];
    }

    if (isset($map_pin['city'])) {
        $address3 .= $map_pin['city'];
    }
    if (isset($map_pin['state'])) {
        if (isset($map_pin['city'])) {
            $address3 .= ', ';
        }
        $address3 .= $map_pin['state'];
    }
    if (isset($map_pin['post_code'])) {
        if (isset($map_pin['city']) || isset($map_pin['state'])) {
            $address3 .= ' ';
        }
        $address3 .= $map_pin['post_code'];
    }

    if (isset($map_pin['country'])) {
        $address4 = $map_pin['country'];
    }
    return array(
        'address1' => $address1,
        'address2' => $address2,
        'address3' => $address3,
        'address4' => $address4,
    );
}

//////////////////////////////////////////
/// Add Icon Preview Size
//////////////////////////////////////////

// Register a custom image size for icons
add_action('after_setup_theme', function() {
    add_image_size('icon', 50, 50, true); 
});


//////////////////////////////////////////
/// ACF WYSIWYG toolbar presets
//////////////////////////////////////////

// Define shared ACF WYSIWYG toolbar presets for field-level editor control.
add_filter('acf/fields/wysiwyg/toolbars', function ($toolbars) {
    unset($toolbars['Basic']);

    $toolbars['Style & Link'] = array();
    $toolbars['Style & Link'][1] = array('bold', 'italic', 'link', 'unlink', 'removeformat');

    $toolbars['Style, List & Link'] = array();
    $toolbars['Style, List & Link'][1] = array('bold', 'italic', 'bullist', 'numlist', 'link', 'unlink', 'removeformat');

    $toolbars['Format, Style, List & Link'] = array();
    $toolbars['Format, Style, List & Link'][1] = array('formatselect', 'bold', 'italic', 'bullist', 'numlist', 'link', 'unlink', 'removeformat');

    return $toolbars;
});

// Default new ACF WYSIWYG fields to the structured-content toolbar.
add_filter('acf/load_field/type=wysiwyg', function ($field) {
    if (empty($field['toolbar']) || $field['toolbar'] === 'basic') {
        $field['toolbar'] = 'Format, Style, List & Link';
    }

    return $field;
});


//////////////////////////////////////////
/// Classic editor for custom post types
//////////////////////////////////////////

// Use the classic editor for every custom post type; posts and pages keep the block editor.
add_filter('use_block_editor_for_post_type', function ($use_block_editor, $post_type) {
    $post_type_object = get_post_type_object($post_type);

    if ($post_type_object && !$post_type_object->_builtin) {
        return false;
    }

    return $use_block_editor;
}, 10, 2);

// Major features are loaded through isolated coordinator includes.
require_once get_template_directory() . '/includes/features/event-calendar/event-calendar.php';
require_once get_template_directory() . '/includes/features/document-library/document-library.php';
require_once get_template_directory() . '/includes/features/site-contacts/site-contacts.php';
require_once get_template_directory() . '/includes/features/user-screens/user-screens.php';
require_once get_template_directory() . '/includes/features/login-screen/login-screen.php';

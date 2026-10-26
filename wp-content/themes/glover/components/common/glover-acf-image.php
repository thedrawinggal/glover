<?php
// This template renders a passed ACF image object - it renders an img tag using glover-image-element.
//
// Accepts an image object and an optional fallback image object. I only set that up to support the "single image"
// requirements (where the mobile image uses desktop image settings as a fallback); you shouldn't need to use that very
// often.
//
// API:
$acf_image_args = wp_parse_args(
    $args ?? array(),
    array(
        // (Required) ACF image object
        'acf_image' => '',

        // (Optional) fallback ACF image object to use if no image url is defined
        'fallback_acf_image' => '',

        // (Optional) WordPress image size to fetch
        'image_size' => 'large',

        // (Optional) fallback alt text to use if none is defined on the image or fallback image
        'fallback_alt_text' => '',

        // (Optional) classes to apply to the img element
        'image_classes' => '',

        // (Optional) classes to apply to the img element container div (if left blank, there will be no container div).
        // If you're building un-styled PHP templates, you don't need to pass anything for this.
        'container_classes' => '',

        // (Optional) array of overrides to img tag attributes passed to the template that renders an img
        // (glover-image-element). If you're building un-styled PHP templates, please do not pass these.
        'image_attribute_overrides' => array(),

        // (Optional) whether or not to attach the full-sized image URL as an attribute to the image element
        'include_full_sized_url_attribute' => false
    )
);

$acf_image = $acf_image_args['acf_image'];
$image_id = $acf_image ? $acf_image['ID'] : '';
$default_scaled_image_url = $acf_image ? $acf_image['url'] : '';
$alt_text = $acf_image ? $acf_image['alt'] : '';

// use fallback image settings if no image url is defined
$fallback_image = $acf_image_args['fallback_acf_image'];
if ($fallback_image && $image_id == '' && $fallback_image['url'] != '') {
    $image_id = $fallback_image['ID'];
    $default_scaled_image_url = $fallback_image['url'];
    $alt_text = $fallback_image['alt'];
}

if ($acf_image_args['image_size'] == 'scaled') {
    $image_url = $default_scaled_image_url;
} else {
    $image_src = wp_get_attachment_image_src($image_id, $acf_image_args['image_size']);
    $image_url = $image_src[0];
}

// fallback alt text
if ($alt_text == '') {
    $alt_text = $acf_image_args['fallback_alt_text'];
}

$image_tag_args = array_merge(
    array(
        'src' => $image_url,
        'class' => $acf_image_args['image_classes'],
        'alt' => $alt_text,
        'container_classes' => $acf_image_args['container_classes']
    ),
    $acf_image_args['image_attribute_overrides']
);

if ($acf_image_args['include_full_sized_url_attribute']) {
    $full_sized_image_url = get_acf_image_url_for_size($image_id, 'full');
    if ($full_sized_image_url) {
        $image_tag_args['custom_attributes'] = "data-full-sized-url=\"$full_sized_image_url\"";
    }
}

get_generic_template_part(
    'components/common/glover-image-element',
    $image_tag_args
);
?>

<?php
// This template renders a standard Cultivate "Single Image" - passed as a data structure containing two subfields:
// - desktop_image (required) - Image
// - mobile_image - Image
//
// This template expects a desktop image and an optional mobile image in the passed data structure. If no mobile image is defined, the template will render the mobile image using the settings of the desktop image (the src and alt).
//
// This template always renders the desktop and mobile images in a container div.
//
// This template calls a cltv8-acf-image template to render the individual ACF images.
//
// API:
$single_image_args = wp_parse_args(
    $args ?? array(),
    array(
        // (Required) single image ACF object to be displayed
        'single_image' => '',

        // (Optional) WordPress image size to fetch (used for both desktop and mobile image)
        'image_size' => 'large',

        // (Optional) fallback alt text for each image
        'fallback_desktop_alt_text' => 'placeholder',
        'fallback_mobile_alt_text' => 'placeholder',

        // (Optional) use this to add additional CSS classes to the container div around the images. If you're building
        // un-styled PHP templates, you don't need to pass anything for this.
        'container_classes' => '',

        // (Optional) these are passed down through the cltv8-acf-image template to the cltv8-image-element template if
        // you want to override attributes of the final img tags themselves. These accept arguments with the names
        // listed in the cltv8-image-element template API. If you're building un-styled PHP templates, please do not
        // pass these.
        'desktop_image_attribute_overrides' => array(),
        'mobile_image_attribute_overrides' => array(),

        // (Optional) Set to 'true' to have this always display the mobile image if it's available, with desktop image
        // as a fallback.
        'prefer_mobile_image' => 'false',

        // (Optional) whether or not to attach the full-sized image URLs as attributes to the image elements (can be used for the image displayed when opening these in a lightbox, for example).
        'include_full_sized_url_attributes' => false
    )
);
$single_image = $single_image_args['single_image'];
$desktop_image = $single_image['desktop_image'];
$mobile_image = $single_image['mobile_image'];
if ($single_image_args['prefer_mobile_image'] == 'true' && $single_image['mobile_image']) {
    $desktop_image = $mobile_image;
}
?>
<div class="single-image-container <?= $single_image_args['container_classes'] ?>">
    <?php
    get_generic_template_part(
        'components/common/cltv8-acf-image',
        array(
            'acf_image' => $desktop_image,
            'image_classes' => 'single-image single-image-desktop',
            'image_size' => $single_image_args['image_size'],
            'fallback_alt_text' => $single_image_args['fallback_desktop_alt_text'],
            'image_attribute_overrides' => $single_image_args['desktop_image_attribute_overrides'],
            'include_full_sized_url_attribute' => $single_image_args['include_full_sized_url_attributes']
        )
    );
    get_generic_template_part(
        'components/common/cltv8-acf-image',
        array(
            'acf_image' => $mobile_image,
            'image_classes' => 'single-image single-image-mobile',
            'image_size' => $single_image_args['image_size'],
            'fallback_acf_image' => $desktop_image,
            'fallback_alt_text' => $single_image_args['fallback_mobile_alt_text'],
            'image_attribute_overrides' => $single_image_args['mobile_image_attribute_overrides'],
            'include_full_sized_url_attribute' => $single_image_args['include_full_sized_url_attributes']
        )
    );
    ?>
</div>
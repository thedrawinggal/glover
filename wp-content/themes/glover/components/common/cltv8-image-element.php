<?php
// This template renders an img tag. This is the only way we should ultimately output HTML img tags in order to enforce
// default attribute settings across the site (especially decoding, loading, fetchpriority). Those defaults can be
// overridden in the API.
//
// API:
$root_image_args = wp_parse_args(
    $args ?? array(),
    array(
        // (Optional, but you will probably want to pass a src) These values are assigned directly to the rendered img
        // tag as attributes, with the below defaults.
        'src' => '',
        'class' => '',
        'alt' => 'placeholder',
        'decoding' => "async",
        'loading' => "lazy",
        'fetchpriority' => "low",

        // (Optional) If you pass "container_classes" with a string that isn't empty, the img element gets rendered in a
        // container div with this value as its class - this is for cases where we want the image to be displayed
        // in a container, like if we want to use object-fit styles. If "container_classes" is an empty string (the
        // default), there will be no container.
        'container_classes' => '',

        // (Optional) custom attributes to render for img tag
        'custom_attributes' => ''
    )
);
$use_container = $root_image_args['container_classes'] != '';

if ($use_container) { ?>
    <div class="<?= $root_image_args['container_classes'] ?>">
<?php } ?>
    <img
            src="<?= esc_url($root_image_args['src']) ?>"
            class="<?= $root_image_args['class'] ?>"
            alt="<?= esc_html(esc_attr($root_image_args['alt'])) ?>"
            decoding="<?= $root_image_args['decoding'] ?>"
            loading="<?= $root_image_args['loading'] ?>"
            fetchpriority="<?= $root_image_args['fetchpriority'] ?>"
            <?= $root_image_args['custom_attributes'] ?>
    >
<?php if ($use_container) { ?>
    </div>
<?php } ?>
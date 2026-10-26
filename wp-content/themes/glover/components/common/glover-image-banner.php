<?php
// This template renders a standard "Image Banner".
//
// An Image Banner is a section that goes at the top of a page - it has an image background (single image), an overlay,
// and content above that overlay. The content shown over the overlay is likely to be different between projects.
// Supports passing custom HTML to show above the overlay.
//
// TODO when you use this in a new project:
// This component tends to have very different requirements between projects, so you'll probably need to make a lot of project-specific edits to this after copying it to a new project. You may not ever need a banner video, for example. If you do need one, it's likely to involve a fallback image (not included here, but handled in other projects). You may need to update the API to accept additional pieces of text or other data as arguments.
//
// API:
$image_banner_args = wp_parse_args(
    $args ?? array(),
    array(
        // (Required) "single image" ACF data structure to use as banner image
        'banner_single_image' => '',

        // (Required) title
        'title' => '',

        // (Optional) headline
        'headline' => '',

        // (Optional) overlay opacity
        'overlay_opacity' => '0',

        // (Optional) banner video - shown over image if set.
        'banner_video' => '',

        // (Optional) HTML to show in overlay. Pass a load_template_part() call to this to send it HTML to render.
        'custom_html_in_overlay' => ''
    )
);
$single_image_template_args = array(
    'single_image' => $image_banner_args['banner_single_image'],
    'fallback_desktop_alt_text' => 'image banner',
    'fallback_mobile_alt_text' => 'image banner',
    'container_classes' => 'fitted-image-container image-banner-image-container',
    'image_size' => 'full',
    'desktop_image_attribute_overrides' => array(
        'class' => "image-banner-image fitted-image single-image single-image-desktop",
        'fetchpriority' => 'auto'
    ),
    'mobile_image_attribute_overrides' => array(
        'class' => "image-banner-image fitted-image single-image single-image-mobile",
        'decoding' => "auto",
        'loading' => "eager",
        'fetchpriority' => "high",
    ),
);
$banner_video = $image_banner_args['banner_video'];
if ($banner_video) {
    $single_image_template_args['container_classes'] .= ' for-video';
}
?>
<div class="image-banner">
    <?php if ($banner_video) { ?>
        <div class="image-banner-video-section">
            <video
                    id="imageBannerVideo"
                    class="image-banner-video"
                    preload="auto"
                    playsinline
                    disablePictureInPicture
                    webkit-playsinline
                    defaultMuted
                    muted
                    autoplay
                    loop
                    data-video-url="<?= esc_url($banner_video) ?>"
            >
                Sorry, your browser doesn't support embedded videos.
            </video>
        </div>
    <?php } else { ?>
        <?php get_generic_template_part(
            'components/common/glover-single-image',
            $single_image_template_args
        ); ?>
    <?php } ?>
    <div
            class="image-banner-overlay"
            style="background: rgba(0, 0, 0, 0.<?= $image_banner_args['overlay_opacity'] ?>)"
    >
        <?php
        // The content inside this image-banner-overlay element is likely to be different between projects and can
        // be changed for individual projects. The custom_html_in_overlay part may need to move or may be unnecessary.
        $headline = $image_banner_args['headline'];
        ?>
        <div class="image-banner-title-container">
            <h1 class="image-banner-title">
                <?= $image_banner_args['title'] ?>
            </h1>
        </div>
        <?php if ($headline) { ?>
            <div class="image-banner-headline">
                <div class="image-banner-headline-text">
                    <?= $headline ?>
                </div>
            </div>
        <?php } ?>

        <?php if ($image_banner_args['custom_html_in_overlay']) { ?>
            <?= $image_banner_args['custom_html_in_overlay'] ?>
        <?php } ?>
    </div>
</div>

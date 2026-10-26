<?php
/*
Use this component to display a Google Map. Map displays a set of marked locations.
Related files: _glover-google-map.scss, glover-google-map.js

TODO when you use this in a new project:
- Template builder: uncomment the code enabling enqueueing of Google's Google Maps support JS file in functions.php (glover_enqueue_scripts()). This requires us to be able to fetch an API key from ACF.
- Styler: add a PNG map marker icon as <theme>/dist/images/map-marker.png, then update markerImageRelativePath in glover-google-map.js to point to that file.
- Styler: update the markerIcon settings in glover-google-map.js so that the marker is positioned correctly (so that it points at the marked address regardless of zoom distance).
- Styler: set appropriate Snazzy Maps map style in the mapStyles variable in glover-google-map.js
- Styler: add appropriate styles for the marker pop-outs
- Styler: update variables storing max map heights for device sizes (_glover-google-map.scss)

ACF Structure:
- Locations Map - locations - Group
    - Location - location - Repeater
        - Location Title - location_title - Text
        - Map Pin - map_pin - Google Map
        - Location Phone - location_phone - Text

Map Styling:
Styles for maps can be created using Snazzy Maps. The resulting list of JSON objects should be assigned to the mapStyles variable in glover-google-map.js
*/
$locations_map_args = wp_parse_args(
    $args ?? array(),
    array(
        // (Required) locations ACF object - array of locations
        'locations' => array(),
    )
);
$locations = $locations_map_args['locations'];
?>
<div class="glover-google-map-container">
    <div class="acf-map">
        <div id="mapCanvas"></div>
        <?php foreach ($locations as $location) {
            $location_title = $location['location_title'];
            $map_pin = $location['map_pin'];
            $address = '';
            $longitude = '';
            $latitude = '';
            if ($map_pin) {
                $address = $map_pin['address'];
                $longitude = $map_pin['lng'];
                $latitude = $map_pin['lat'];
            }

            $location_phone = $location['location_phone'];
            $location_phone_numbers_only = preg_replace("/[^0-9]/", "", $location_phone);

            $address_parts = get_address_from_map_pin($map_pin);

            $address1 = $address_parts['address1'];
            $address2 = $address_parts['address2'];
            $address3 = $address_parts['address3'];
            $address4 = $address_parts['address4'];
            ?>
            <div
                    class="marker"
                    data-lat="<?= esc_attr($latitude) ?>"
                    data-lng="<?= esc_attr($longitude) ?>"
            >
                <div class="google-maps-marker-content">
                    <?php
                    // this marker content may be different between projects.
                    ?>
                    <div class="location-title"><?= esc_html($location_title) ?></div>
                    <?php if ($address2 || $address3) { ?>
                        <div class="location-address">
                            <?php if ($address2) {
                                echo '<div>' . esc_html($address2) . '</div>';
                            } ?>
                            <?php if ($address3) {
                                echo '<div>' . esc_html($address3) . '</div>';
                            } ?>
                            <?php if ($address4) {
                                echo '<div>' . esc_html($address4) . '</div>';
                            } ?>
                        </div>
                    <?php } ?>
                    <div class="location-phone">
                        <?= $location_phone ?>
                    </div>
                </div>
            </div>
        <?php } ?>
    </div>
</div>

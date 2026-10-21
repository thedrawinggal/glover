<?php
// This template renders a set of checkboxes that can filter a list. This is an optional feature of the AJAX lists
// supported by glover-ajax-list.js and its supporting files, but it's likely we'll need a version of this in each
// project that uses AJAX lists.
//
// glover-ajax-list.js sets which boxes are checked based on the page URL query string. It also sets which filter sets
// and individual filter options are visible and/or disabled.
//
// API:
$filter_set_args = wp_parse_args(
    $args ?? array(),
    array(
        // (Required) filter parameter displayed label (i.e. "Color")
        'filter_set_label' => '',

        // (Required) filter parameter name - to be used in URLs (i.e. "color")
        'filter_parameter_name' => '',

        // (Required) array of value-label pairs for possible filter values (i.e. array('red'=>'Red', 'blue'=>'Blue'))
        'filter_parameter_options' => array()
    )
);
?>
<div
        class="list-filter-set glover-list-filter-set"
        data-parameter-name="<?= $filter_set_args['filter_parameter_name'] ?>"
>
    <div class="list-filter-set-label"><?= $filter_set_args['filter_set_label'] ?></div>
    <div class="list-filter-options">
        <?php foreach ($filter_set_args['filter_parameter_options'] as $value => $label) { ?>
            <div
                    class="list-filter glover-ajax-list-filter showing"
                    data-parameter-name="<?= $filter_set_args['filter_parameter_name'] ?>"
                    data-parameter-value="<?= $value ?>"
            >
                <div class="list-filter-content">
                    <div class="list-filter-checkbox">
                        <div class="list-filter-checkbox-mark"></div>
                    </div>
                    <div class="list-filter-label"><?= $label ?></div>
                </div>
            </div>
        <?php } ?>
    </div>
</div>
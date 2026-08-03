<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <!-- Google Manager | ACF Field Set Up: Field Label = Google Tag Manager ID, Field Name = google_tag_manager_id -->
    <?php $google_tag_id = get_field('google_tag_manager_id', 'option');
    if ($google_tag_id) :
        ?>
        <!-- Google Tag Manager -->
        <script>(function (w, d, s, l, i) {
                w[l] = w[l] || [];
                w[l].push({
                    'gtm.start':
                        new Date().getTime(), event: 'gtm.js'
                });
                var f = d.getElementsByTagName(s)[0],
                    j = d.createElement(s), dl = l != 'dataLayer' ? '&l=' + l : '';
                j.async = true;
                j.src =
                    'https://www.googletagmanager.com/gtm.js?id=' + i + dl;
                f.parentNode.insertBefore(j, f);
            })(window, document, 'script', 'dataLayer', '<?php echo $google_tag_id; ?>');</script>
    <?php endif; ?>
    <!-- End Google Tag Manager -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body class="app">
<!-- Google Tag Manager (noscript) -->
<?php if ($google_tag_id) : ?>
    <noscript>
        <iframe
                src="https://www.googletagmanager.com/ns.html?id=<?php echo $google_tag_id; ?>"
                height="0"
                width="0"
                style="display:none;visibility:hidden"
        >
        </iframe>
    </noscript>
<?php endif; ?>
<a href="<?php echo esc_url(home_url('/')); ?>" title="<?php echo esc_html(get_bloginfo('name')); ?>" rel="home">
    logo
</a>
<nav>
    <?php wp_nav_menu(array('theme_location' => 'main-menu')); ?>
</nav>

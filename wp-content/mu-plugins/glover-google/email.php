<?php
/**
 * The site's formatted email: one layout (templates/email.php) for every
 * email the site sends, matching the "Glover Review Email" design canvas.
 * Sent as HTML with a plain-text copy (see mail.php).
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Render an email in the shared layout. Every key is optional except heading.
 *
 * @param array $args {
 *     @type string  $heading   Main heading.
 *     @type string  $subtitle  Line under "Town of Glover" in the header. Default "Document Library"; '' hides it.
 *     @type string  $code      A one-time code, shown large and centered under the heading.
 *     @type string  $intro     Sentence under the heading (under the code when there is one).
 *     @type array   $card      {title, url, tags: [{label, changed}], rows: [{label, value, url, strong}]}.
 *     @type array[] $items     A list of documents: [{title, url, tags, meta}].
 *     @type array   $note      {label, text}: a shaded box for a person's own words.
 *     @type array   $button    {label, url}.
 *     @type array   $secondary {before, label, url, after}: a small line under the button.
 *     @type string  $footer    Footer text.
 *     @type array   $footer_link {label, url}: a link after the footer text.
 * }
 * @return string HTML.
 */
function glover_email_render(array $args)
{
    $args = wp_parse_args($args, array(
        'heading' => '',
        'subtitle' => 'Document Library',
        'code' => '',
        'intro' => '',
        'card' => array(),
        'items' => array(),
        'note' => array(),
        'button' => array(),
        'secondary' => array(),
        'footer' => '',
        'footer_link' => array(),
    ));

    ob_start();
    load_template(__DIR__ . '/templates/email.php', false, $args);

    return ob_get_clean();
}

/**
 * Send an email in the shared layout.
 *
 * @param string|string[] $to      Recipient address(es).
 * @param string          $subject Subject line (the site name is added in front).
 * @param array           $args    glover_email_render() arguments.
 * @param string[]        $headers Extra headers, e.g. Reply-To.
 * @return bool
 */
function glover_email_send($to, $subject, array $args, array $headers = array())
{
    $to = array_filter((array) $to, 'is_email');

    if (!$to) {
        return false;
    }

    $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
    $headers[] = 'Content-Type: text/html; charset=UTF-8';

    return wp_mail($to, '[' . $site . '] ' . $subject, glover_email_render($args), $headers);
}

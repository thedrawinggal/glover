<?php
/**
 * Shared email layout. Tables and inline styles, because many mail apps
 * ignore stylesheets. Colors are the site palette
 * (themes/glover/src/styles/base/_colors.scss). Arguments: glover_email_render().
 *
 * @var array $args
 */

if (!defined('ABSPATH')) {
    exit;
}

$sans = "system-ui, -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
$serif = "Georgia, 'Times New Roman', serif";
$mono = "ui-monospace, 'SF Mono', Menlo, Consolas, monospace";
$link = 'color: #1f5a3d;';
$label = 'font-size: 12px; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; color: #4e5a52;';
$centered = $args['code'] !== '';

$tags = function ($list) {
    foreach ((array) $list as $tag) {
        $style = !empty($tag['changed'])
            ? 'border: 1px solid #dba617; background: #fcf3c4; color: #614a00;'
            : 'border: 1px solid #e6dfcd; background: #e6dfcd; color: #1d2a23;';
        printf(
            '<span style="display: inline-block; margin: 0 4px 4px 0; padding: 1px 8px; border-radius: 3px; font-size: 12px; font-weight: 600; %s">%s</span>',
            esc_attr($style),
            esc_html($tag['label'])
        );
    }
};
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html($args['heading']); ?></title>
</head>
<body style="margin: 0; padding: 0; background: #f3eee2;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background: #f3eee2;">
    <tr>
        <td align="center" style="padding: 32px 16px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width: 600px; background: #fbf8f1; border: 1px solid #cfc6b2; border-radius: 6px; font-family: <?php echo esc_attr($sans); ?>; color: #1d2a23;">
                <tr>
                    <td style="background: #163a2c; color: #f6f1e4; padding: 22px 32px; border-radius: 6px 6px 0 0;">
                        <div style="font-family: <?php echo esc_attr($serif); ?>; font-size: 24px; line-height: 1.2;">Town of Glover</div>
                        <?php if ($args['subtitle'] !== '') : ?>
                            <div style="font-size: 12px; letter-spacing: 0.08em; text-transform: uppercase;"><?php echo esc_html($args['subtitle']); ?></div>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td style="padding: <?php echo $centered ? '40px 32px' : '32px 32px 8px'; ?>;<?php echo $centered ? ' text-align: center;' : ''; ?>">
                        <h1 style="margin: 0 0 <?php echo ($args['intro'] !== '' || $centered) ? '12px' : '24px'; ?>; font-family: <?php echo esc_attr($serif); ?>; font-size: 28px; line-height: 1.2; font-weight: 400; color: #1d2a23;"><?php echo esc_html($args['heading']); ?></h1>

                        <?php if ($centered) : ?>
                            <div style="display: inline-block; margin: 8px 0 20px; padding: 18px 36px; background: #ffffff; border: 1px solid #cfc6b2; border-radius: 6px; font-family: <?php echo esc_attr($mono); ?>; font-size: 40px; font-weight: 700; letter-spacing: 0.25em; color: #1d2a23;"><?php echo esc_html($args['code']); ?></div>
                        <?php endif; ?>

                        <?php if ($args['intro'] !== '') : ?>
                            <p style="margin: 0 0 24px; font-size: 16px; line-height: 1.5;"><?php echo esc_html($args['intro']); ?></p>
                        <?php endif; ?>

                        <?php if ($args['card']) : ?>
                            <?php $card = wp_parse_args($args['card'], array('title' => '', 'url' => '', 'tags' => array(), 'rows' => array())); ?>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom: 24px; background: #ffffff; border: 1px solid #cfc6b2; border-radius: 6px;">
                                <tr>
                                    <td style="padding: 20px 22px;">
                                        <?php if ($card['url']) : ?>
                                            <a href="<?php echo esc_url($card['url']); ?>" style="font-size: 18px; font-weight: 700; line-height: 1.3; <?php echo esc_attr($link); ?>"><?php echo esc_html($card['title']); ?></a>
                                        <?php else : ?>
                                            <div style="font-size: 18px; font-weight: 700; line-height: 1.3;"><?php echo esc_html($card['title']); ?></div>
                                        <?php endif; ?>
                                        <?php if ($card['tags']) : ?>
                                            <div style="margin-top: 8px;"><?php $tags($card['tags']); ?></div>
                                        <?php endif; ?>
                                        <?php if ($card['rows']) : ?>
                                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-top: 12px; font-size: 14px; line-height: 1.4;">
                                                <?php foreach ($card['rows'] as $row) : ?>
                                                    <tr>
                                                        <td style="padding: 4px 12px 4px 0; width: 140px; vertical-align: top; color: #4e5a52;"><?php echo esc_html($row['label']); ?></td>
                                                        <td style="padding: 4px 0; vertical-align: top;<?php echo !empty($row['strong']) ? ' font-weight: 600;' : ''; ?>">
                                                            <?php if (!empty($row['url'])) : ?>
                                                                <a href="<?php echo esc_url($row['url']); ?>" style="<?php echo esc_attr($link); ?>"><?php echo esc_html($row['value']); ?></a>
                                                            <?php else : ?>
                                                                <?php echo esc_html($row['value']); ?>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </table>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </table>
                        <?php endif; ?>

                        <?php if ($args['items']) : ?>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom: 24px; background: #ffffff; border: 1px solid #cfc6b2; border-radius: 6px;">
                                <?php foreach (array_values($args['items']) as $index => $item) : ?>
                                    <tr>
                                        <td style="padding: 16px 20px;<?php echo $index ? ' border-top: 1px solid #e4ddcc;' : ''; ?>">
                                            <a href="<?php echo esc_url($item['url']); ?>" style="font-size: 16px; font-weight: 700; line-height: 1.3; <?php echo esc_attr($link); ?>"><?php echo esc_html($item['title']); ?></a>
                                            <?php if (!empty($item['tags'])) : ?>
                                                <div style="margin-top: 6px;"><?php $tags($item['tags']); ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($item['meta'])) : ?>
                                                <div style="margin-top: 4px; font-size: 14px; line-height: 1.4; color: #4e5a52;"><?php echo esc_html($item['meta']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        <?php endif; ?>

                        <?php if ($args['note'] && $args['note']['text'] !== '') : ?>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom: 24px; background: #f4efe3; border-radius: 6px;">
                                <tr>
                                    <td style="padding: 16px 20px;">
                                        <div style="<?php echo esc_attr($label); ?>"><?php echo esc_html($args['note']['label']); ?></div>
                                        <p style="margin: 6px 0 0; font-size: 15px; line-height: 1.5;"><?php echo nl2br(esc_html($args['note']['text'])); ?></p>
                                    </td>
                                </tr>
                            </table>
                        <?php endif; ?>

                        <?php if ($args['button']) : ?>
                            <a href="<?php echo esc_url($args['button']['url']); ?>" style="display: inline-block; margin-bottom: 12px; background: #163a2c; color: #f6f1e4; text-decoration: none; font-size: 16px; font-weight: 600; padding: 14px 28px; border-radius: 4px;"><?php echo esc_html($args['button']['label']); ?></a>
                        <?php endif; ?>

                        <?php if ($args['secondary']) : ?>
                            <?php $secondary = wp_parse_args($args['secondary'], array('before' => '', 'label' => '', 'url' => '', 'after' => '')); ?>
                            <p style="margin: 0 0 12px; font-size: 14px; line-height: 1.5; color: #4e5a52;"><?php echo esc_html($secondary['before']); ?><a href="<?php echo esc_url($secondary['url']); ?>" style="<?php echo esc_attr($link); ?>"><?php echo esc_html($secondary['label']); ?></a><?php echo esc_html($secondary['after']); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ($args['footer'] !== '') : ?>
                    <tr>
                        <td style="border-top: 1px solid #e4ddcc; padding: 20px 32px; font-size: 13px; line-height: 1.5; color: #4e5a52;">
                            <?php echo esc_html($args['footer']); ?>
                            <?php if ($args['footer_link']) : ?>
                                <a href="<?php echo esc_url($args['footer_link']['url']); ?>" style="<?php echo esc_attr($link); ?>"><?php echo esc_html($args['footer_link']['label']); ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </table>
        </td>
    </tr>
</table>
</body>
</html>

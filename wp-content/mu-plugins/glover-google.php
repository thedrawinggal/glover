<?php
/**
 * Plugin Name: Glover Google Workspace
 * Description: Site email through the Gmail API, Google and emailed-code sign-in, and the Google client used by the Document Library's Drive backup. Configured with GLOVER_GOOGLE_* constants in wp-config.php.
 * Version: 1.0.0
 *
 * A must-use plugin so a theme change can never break sign-in or email.
 * Spec and setup: wp-content/themes/glover/docs/features/google-workspace.md.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/glover-google/client.php';
require_once __DIR__ . '/glover-google/mail.php';
require_once __DIR__ . '/glover-google/sign-in.php';
require_once __DIR__ . '/glover-google/status.php';

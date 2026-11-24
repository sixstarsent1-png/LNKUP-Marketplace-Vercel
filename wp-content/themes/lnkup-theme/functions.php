<?php
/**
 * LNKup Theme functions and definitions.
 *
 * @package LNKup_Theme
 */

defined( 'ABSPATH' ) || exit;

define( 'LNKUP_THEME_VERSION', '1.8.0' );
define( 'LNKUP_THEME_DIR', get_template_directory() );
define( 'LNKUP_THEME_URI', get_template_directory_uri() );

require_once LNKUP_THEME_DIR . '/inc/setup.php';
require_once LNKUP_THEME_DIR . '/inc/enqueue.php';
require_once LNKUP_THEME_DIR . '/inc/template-tags.php';
require_once LNKUP_THEME_DIR . '/inc/public-site.php';
require_once LNKUP_THEME_DIR . '/inc/landing-icons.php';
require_once LNKUP_THEME_DIR . '/inc/landing-settings.php';
require_once LNKUP_THEME_DIR . '/inc/dashboard.php';
require_once LNKUP_THEME_DIR . '/inc/legal-pages.php';
require_once LNKUP_THEME_DIR . '/inc/login-branding.php';

LNKup_Theme_Legal_Pages::init();

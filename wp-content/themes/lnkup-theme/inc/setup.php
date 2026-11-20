<?php
/**
 * Theme setup.
 *
 * @package LNKup_Theme
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'after_setup_theme',
	function () {
		load_theme_textdomain( 'lnkup-theme', LNKUP_THEME_DIR . '/languages' );

		add_theme_support( 'title-tag' );
		add_theme_support( 'post-thumbnails' );
		add_theme_support(
			'html5',
			array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script' )
		);

		register_nav_menus(
			array(
				'primary' => __( 'Primary Menu', 'lnkup-theme' ),
				'footer'  => __( 'Footer Menu', 'lnkup-theme' ),
			)
		);
	}
);

add_filter(
	'theme_page_templates',
	function ( $templates ) {
		$templates['page-templates/vendor-dashboard.php']    = __( 'Vendor Dashboard', 'lnkup-theme' );
		$templates['page-templates/organizer-dashboard.php'] = __( 'Organizer Dashboard', 'lnkup-theme' );
		$templates['page-templates/full-width.php']            = __( 'Full Width', 'lnkup-theme' );
		$templates['page-templates/sign-up.php']                 = __( 'Sign Up', 'lnkup-theme' );
		$templates['page-templates/vendor-shell.php']            = __( 'Vendor Shell', 'lnkup-theme' );
		$templates['page-templates/market-landing.php']          = __( 'City Market Landing', 'lnkup-theme' );
		$templates['page-templates/public-storefront.php']       = __( 'Public Storefront', 'lnkup-theme' );
		return $templates;
	}
);

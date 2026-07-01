<?php
/**
 * Enqueue scripts and styles.
 *
 * @package LNKup_Theme
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'wp_enqueue_scripts',
	function () {
		wp_enqueue_style(
			'lnkup-fonts',
			'https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap',
			array(),
			null
		);

		wp_enqueue_style(
			'lnkup-theme',
			LNKUP_THEME_URI . '/assets/dist/lnkup.css',
			array( 'lnkup-fonts' ),
			LNKUP_THEME_VERSION
		);

		wp_enqueue_style(
			'lnkup-components',
			LNKUP_THEME_URI . '/assets/dist/components.css',
			array( 'lnkup-theme' ),
			LNKUP_THEME_VERSION
		);

		wp_enqueue_script(
			'lnkup-theme',
			LNKUP_THEME_URI . '/assets/js/theme.js',
			array(),
			LNKUP_THEME_VERSION,
			true
		);

		if ( lnkup_theme_is_dashboard_page() ) {
			wp_enqueue_script(
				'lnkup-dashboard',
				LNKUP_THEME_URI . '/assets/js/dashboard.js',
				array( 'lnkup-theme' ),
				LNKUP_THEME_VERSION,
				true
			);
		}

		if ( is_front_page() || is_page_template( 'page-templates/sign-up.php' ) || get_query_var( 'lnkup_market_slug' ) ) {
			wp_enqueue_script(
				'lnkup-landing',
				LNKUP_THEME_URI . '/assets/js/landing.js',
				array( 'lnkup-theme' ),
				LNKUP_THEME_VERSION,
				true
			);
			wp_localize_script(
				'lnkup-landing',
				'lnkupLaunch',
				array(
					'launchIso'      => function_exists( 'lnkup_get_launch_datetime_iso' ) ? lnkup_get_launch_datetime_iso() : '',
					'marketplaceOpen' => function_exists( 'lnkup_is_marketplace_open' ) ? lnkup_is_marketplace_open() : true,
				)
			);
		}
	}
);

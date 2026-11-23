<?php
/**
 * Enqueue scripts and styles.
 *
 * @package LNKup_Theme
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'wp_resource_hints',
	function ( $urls, $relation_type ) {
		if ( 'preconnect' !== $relation_type ) {
			return $urls;
		}
		$urls[] = 'https://fonts.googleapis.com';
		$urls[] = array(
			'href'        => 'https://fonts.gstatic.com',
			'crossorigin' => 'anonymous',
		);
		return $urls;
	},
	10,
	2
);

add_action(
	'wp_enqueue_scripts',
	function () {
		$font = 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Sora:wght@600;700&display=swap';
		if ( lnkup_theme_is_dashboard_page() ) {
			$font = 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Sora:wght@600;700&display=swap';
		}
		wp_enqueue_style(
			'lnkup-fonts',
			$font,
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

		if ( is_front_page() || is_page_template( 'page-templates/sign-up.php' ) || is_page_template( 'page-templates/connector-interest.php' ) || get_query_var( 'lnkup_market_slug' ) ) {
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

/*
 * Experience layer: shared public look (header, homepage, cards, focus, motion). Registered late
 * and made to depend on every LNKup stylesheet already queued, so it always prints after them.
 */
add_action(
	'wp_enqueue_scripts',
	function () {
		$deps = array( 'lnkup-components' );
		foreach ( wp_styles()->queue as $handle ) {
			if ( 0 === strpos( $handle, 'lnkup-' ) && ! in_array( $handle, array( 'lnkup-fonts', 'lnkup-experience' ), true ) ) {
				$deps[] = $handle;
			}
		}
		wp_enqueue_style(
			'lnkup-experience',
			LNKUP_THEME_URI . '/assets/css/experience.css',
			array_values( array_unique( $deps ) ),
			LNKUP_THEME_VERSION . '.' . (string) filemtime( LNKUP_THEME_DIR . '/assets/css/experience.css' )
		);
	},
	99
);

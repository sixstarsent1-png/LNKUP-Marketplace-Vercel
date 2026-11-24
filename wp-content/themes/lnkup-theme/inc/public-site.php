<?php
/**
 * Public website 5.0 helpers: navigation data, icons, responsive images, live discovery access.
 *
 * Presentation only. Counts, cards, pricing and validation come from the LNKup Marketplace plugin
 * (LNKup_Public_Discovery, LNKup_Campaign_Landings); when the plugin is inactive every helper
 * returns empty data and sections hide themselves.
 *
 * @package LNKup_Theme
 */

defined( 'ABSPATH' ) || exit;

/**
 * Public marketing/discovery pages get Design System 5.0 (dashboards keep their own shell).
 */
function lnkup_public_is_public_page() {
	if ( is_admin() ) {
		return false;
	}
	if ( function_exists( 'lnkup_theme_is_dashboard_page' ) && lnkup_theme_is_dashboard_page() ) {
		return function_exists( 'lnkup_theme_public_browse_page' ) && lnkup_theme_public_browse_page();
	}
	return true;
}

add_filter(
	'body_class',
	function ( $classes ) {
		if ( lnkup_public_is_public_page() ) {
			$classes[] = 'lp-body';
		}
		return $classes;
	}
);

add_action(
	'wp_enqueue_scripts',
	function () {
		if ( ! lnkup_public_is_public_page() ) {
			return;
		}
		$css = LNKUP_THEME_DIR . '/assets/dist/public.css';
		wp_enqueue_style(
			'lnkup-public',
			LNKUP_THEME_URI . '/assets/dist/public.css',
			array( 'lnkup-components' ),
			file_exists( $css ) ? LNKUP_THEME_VERSION . '.' . filemtime( $css ) : LNKUP_THEME_VERSION
		);
		wp_enqueue_script(
			'lnkup-public',
			LNKUP_THEME_URI . '/assets/js/public.js',
			array(),
			LNKUP_THEME_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_localize_script(
			'lnkup-public',
			'lnkupPublic',
			array(
				'restUrl'     => esc_url_raw( rest_url( 'lnkup/v1/' ) ),
				'feedUrl'     => esc_url_raw( lnkup_theme_opportunities_url() ),
				'vendorsUrl'  => esc_url_raw( lnkup_page_url( 'vendors' ) ),
				'i18n'        => array(
					'events'     => __( 'Events', 'lnkup-theme' ),
					'cities'     => __( 'Cities', 'lnkup-theme' ),
					'categories' => __( 'Vendor categories', 'lnkup-theme' ),
					'popular'    => __( 'Popular searches', 'lnkup-theme' ),
					'noResults'  => __( 'No suggestions. Press Enter to search.', 'lnkup-theme' ),
					'results'    => __( '%d suggestions available. Use the arrow keys to browse.', 'lnkup-theme' ),
				),
			)
		);
	},
	30
);

add_action(
	'wp_body_open',
	function () {
		if ( lnkup_public_is_public_page() ) {
			echo '<a class="lp-skip" href="#lp-main">' . esc_html__( 'Skip to content', 'lnkup-theme' ) . '</a>';
		}
	},
	1
);

/**
 * Inline SVG icon (decorative; pair with visible text or an aria-label on the control).
 *
 * @param string $name Icon key.
 */
function lnkup_public_icon( $name ) {
	$paths = array(
		'chevron'  => '<path d="M6 9l6 6 6-6"/>',
		'arrow'    => '<path d="M5 12h14M13 6l6 6-6 6"/>',
		'pin'      => '<path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
		'calendar' => '<rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
		'check'    => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
		'verified' => '<path d="M12 3l2.4 1.8 3-.2.9 2.9 2.4 1.8-1 2.8 1 2.8-2.4 1.8-.9 2.9-3-.2L12 21l-2.4-1.8-3 .2-.9-2.9-2.4-1.8 1-2.8-1-2.8 2.4-1.8.9-2.9 3 .2z"/><path d="M8.5 12l2.5 2.5 4.5-5"/>',
		'menu'     => '<path d="M4 7h16M4 12h16M4 17h16"/>',
		'close'    => '<path d="M6 6l12 12M18 6L6 18"/>',
		'search'   => '<circle cx="11" cy="11" r="6.5"/><path d="M20 20l-4.2-4.2"/>',
		'store'    => '<path d="M4 9.5l1.5-5h13L20 9.5M4 9.5h16M4 9.5v10h16v-10M9 19.5v-5h6v5"/>',
		'users'    => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.6-3.6 3.3-5.5 6.5-5.5s5.9 1.9 6.5 5.5M16 4.8a3.5 3.5 0 0 1 0 6.4M18.5 14.8c1.7.8 2.7 2.5 3 5.2"/>',
		'card'     => '<rect x="3" y="5.5" width="18" height="13" rx="2.5"/><path d="M3 10h18M7 15h3"/>',
		'qr'       => '<path d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h2v2h-2zM18 14h2v2h-2zM14 18h2v2h-2zM18 18h2v2h-2z"/>',
		'map'      => '<path d="M9 4L3 6.5v13.5L9 17.5l6 2.5 6-2.5V4l-6 2.5z"/><path d="M9 4v13.5M15 6.5V20"/>',
		'bell'     => '<path d="M6 16.5V11a6 6 0 0 1 12 0v5.5l1.5 2h-15z"/><path d="M10 21h4"/>',
		'shield'   => '<path d="M12 3l7.5 3v5.5c0 4.6-3.2 8.3-7.5 9.5-4.3-1.2-7.5-4.9-7.5-9.5V6z"/><path d="M9 12l2.2 2.2L15.5 10"/>',
		'chart'    => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
		'doc'      => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h4"/>',
		'spark'    => '<path d="M12 3v4M12 17v4M3 12h4M17 12h4M6 6l2.5 2.5M15.5 15.5L18 18M6 18l2.5-2.5M15.5 8.5L18 6"/>',
		'send'     => '<path d="M21 3L10 14M21 3l-7 18-4-7-7-4z"/>',
		'phone'    => '<rect x="6.5" y="2.5" width="11" height="19" rx="2.5"/><path d="M11 18.5h2"/>',
		'route'    => '<circle cx="6" cy="18" r="2.5"/><circle cx="18" cy="6" r="2.5"/><path d="M8.5 18H15a3 3 0 0 0 0-6H9a3 3 0 0 1 0-6h6.5"/>',
	);
	if ( ! isset( $paths[ $name ] ) ) {
		return '';
	}
	return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[ $name ] . '</svg>';
}

/**
 * Responsive image from a Media Library ID or a theme asset path (with optional -640w/-1280w/-1920w
 * WebP siblings generated by scripts/optimize-images).
 *
 * @param int|string           $source Attachment ID or asset path under assets/.
 * @param array<string, mixed> $args   alt, sizes, class, eager, width, height.
 */
function lnkup_public_image( $source, $args = array() ) {
	$args  = wp_parse_args(
		$args,
		array(
			'alt'    => '',
			'sizes'  => '100vw',
			'class'  => '',
			'eager'  => false,
			'width'  => 1600,
			'height' => 900,
			'size'   => 'large',
		)
	);
	$extra = $args['eager']
		? array( 'loading' => 'eager', 'fetchpriority' => 'high', 'decoding' => 'async' )
		: array( 'loading' => 'lazy', 'decoding' => 'async' );

	if ( is_numeric( $source ) && (int) $source > 0 && wp_attachment_is_image( (int) $source ) ) {
		return wp_get_attachment_image(
			(int) $source,
			$args['size'],
			false,
			array_merge(
				$extra,
				array(
					'alt'   => (string) $args['alt'],
					'sizes' => (string) $args['sizes'],
					'class' => (string) $args['class'],
				)
			)
		);
	}
	$path = ltrim( (string) $source, '/' );
	if ( '' === $path || ! file_exists( LNKUP_THEME_DIR . '/assets/' . $path ) ) {
		return '';
	}
	$base   = preg_replace( '/\.(jpe?g|png)$/i', '', $path );
	$srcset = array();
	foreach ( array( 640, 1280, 1920 ) as $w ) {
		$variant = $base . '-' . $w . 'w.webp';
		if ( file_exists( LNKUP_THEME_DIR . '/assets/' . $variant ) ) {
			$srcset[] = esc_url( lnkup_theme_asset( $variant ) ) . ' ' . $w . 'w';
		}
	}
	$attrs = '';
	foreach ( $extra as $key => $value ) {
		$attrs .= sprintf( ' %s="%s"', esc_attr( $key ), esc_attr( $value ) );
	}
	$img = sprintf(
		'<img src="%1$s" alt="%2$s" width="%3$d" height="%4$d" class="%5$s"%6$s>',
		esc_url( lnkup_theme_asset( $path ) ),
		esc_attr( (string) $args['alt'] ),
		(int) $args['width'],
		(int) $args['height'],
		esc_attr( (string) $args['class'] ),
		$attrs
	);
	if ( ! $srcset ) {
		return $img;
	}
	return sprintf(
		'<picture><source type="image/webp" srcset="%1$s" sizes="%2$s">%3$s</picture>',
		esc_attr( implode( ', ', $srcset ) ),
		esc_attr( (string) $args['sizes'] ),
		$img
	);
}

/**
 * Live discovery snapshot (categories, cities, tours, counts). Empty shape without the plugin.
 *
 * @return array<string, mixed>
 */
function lnkup_public_discovery() {
	static $snapshot = null;
	if ( null !== $snapshot ) {
		return $snapshot;
	}
	$snapshot = class_exists( 'LNKup_Public_Discovery' )
		? LNKup_Public_Discovery::snapshot()
		: array( 'categories' => array(), 'cities' => array(), 'city_index' => array(), 'tours' => array(), 'stats' => array() );
	return $snapshot;
}

/**
 * Event card view-models for a named set.
 *
 * @param string               $set     upcoming|weekend|closing|newest.
 * @param int                  $limit   Max cards.
 * @param array<string, mixed> $context Validated city/state/categories.
 * @return array<int, array<string, mixed>>
 */
function lnkup_public_event_cards( $set = 'upcoming', $limit = 6, $context = array() ) {
	if ( ! class_exists( 'LNKup_Public_Discovery' ) || ! lnkup_theme_marketplace_open() ) {
		return array();
	}
	return LNKup_Public_Discovery::event_cards( (string) $set, (int) $limit, (array) $context );
}

/**
 * Live pricing (Fee Engine settings). Null without the plugin — pricing blocks then hide.
 *
 * @return array<string, float>|null
 */
function lnkup_public_pricing() {
	$pricing = class_exists( 'LNKup_Public_Discovery' ) ? LNKup_Public_Discovery::pricing() : array();
	return $pricing ? $pricing : null;
}

/**
 * Plain-language pricing sentences from live Fee Engine settings.
 *
 * @return array{vendor:string,organizer:string}|null
 */
function lnkup_public_pricing_copy() {
	$p = lnkup_public_pricing();
	if ( ! $p ) {
		return null;
	}
	$processing = sprintf(
		/* translators: 1: percent, 2: flat amount */
		__( 'card processing (%1$s + %2$s)', 'lnkup-theme' ),
		lnkup_public_percent( $p['processing_percent'] ),
		lnkup_theme_money( $p['processing_flat'] )
	);
	if ( 'vendor' === $p['fee_payer'] && $p['service_fee'] > 0 ) {
		/* translators: 1: service fee, 2: processing description */
		$vendor = sprintf( __( 'Applying is free. When you are approved you pay the organizer’s booth price, a %1$s LNKup service fee and %2$s. Free booths have no service fee.', 'lnkup-theme' ), lnkup_theme_money( $p['service_fee'] ), $processing );
		/* translators: %s: commission percent */
		$organizer = sprintf( __( 'Listing events and reviewing vendors is free. When a vendor pays a booth fee through LNKup, %s of the booth price is deducted before your payout.', 'lnkup-theme' ), lnkup_public_percent( $p['organizer_commission_percent'] ) );
	} else {
		/* translators: %s: processing description */
		$vendor = sprintf( __( 'Applying is free. When you are approved you pay the organizer’s booth price plus %s.', 'lnkup-theme' ), $processing );
		$organizer = $p['service_fee'] > 0
			/* translators: 1: commission percent, 2: service fee */
			? sprintf( __( 'Listing events and reviewing vendors is free. For each paid booth, %1$s of the booth price plus a %2$s service fee is deducted before your payout.', 'lnkup-theme' ), lnkup_public_percent( $p['organizer_commission_percent'] ), lnkup_theme_money( $p['service_fee'] ) )
			/* translators: %s: commission percent */
			: sprintf( __( 'Listing events and reviewing vendors is free. When a vendor pays a booth fee through LNKup, %s of the booth price is deducted before your payout.', 'lnkup-theme' ), lnkup_public_percent( $p['organizer_commission_percent'] ) );
	}
	return array( 'vendor' => $vendor, 'organizer' => $organizer );
}

/**
 * Money label without trailing zero cents.
 *
 * @param float $amount Amount.
 */
function lnkup_theme_money( $amount ) {
	$amount = (float) $amount;
	return abs( $amount - round( $amount ) ) < 0.001 ? '$' . number_format_i18n( $amount, 0 ) : '$' . number_format_i18n( $amount, 2 );
}

/**
 * Percent label (6 → "6%", 6.5 → "6.5%").
 *
 * @param float $value Percent.
 */
function lnkup_public_percent( $value ) {
	$value = round( (float) $value, 2 );
	return ( abs( $value - round( $value ) ) < 0.001 ? number_format_i18n( $value, 0 ) : number_format_i18n( $value, 1 ) ) . '%';
}

/**
 * Category artwork shipped with the theme.
 *
 * @param string $label Canonical category label.
 */
function lnkup_public_category_image( $label ) {
	$map  = array(
		'food'         => 'cat-food',
		'food trucks'  => 'cat-food-truck',
		'desserts'     => 'cat-desserts',
		'drinks'       => 'cat-drinks',
		'retail'       => 'cat-retail',
		'clothing'     => 'cat-clothing',
		'beauty'       => 'cat-beauty',
		'jewelry'      => 'cat-jewelry',
		'art'          => 'cat-art',
		'photo booth'  => 'cat-photo-booth',
		'services'     => 'cat-services',
		'wellness'     => 'cat-wellness',
		'merchandise'  => 'cat-merchandise',
	);
	$key  = strtolower( trim( (string) $label ) );
	$file = $map[ $key ] ?? 'cat-booth';
	return 'images/events/' . $file . '.jpg';
}

/**
 * Sign-up URL for a role, optionally returning to a public page after the account exists.
 *
 * @param string $role     vendor|organizer.
 * @param string $redirect Same-site URL to return to.
 */
function lnkup_public_signup_url( $role = '', $redirect = '' ) {
	$url  = lnkup_theme_registration_url();
	$args = array();
	if ( in_array( $role, array( 'vendor', 'organizer' ), true ) ) {
		$args['role'] = $role;
	}
	$redirect = (string) $redirect;
	if ( '' !== $redirect && wp_validate_redirect( $redirect, '' ) ) {
		$args['redirect'] = rawurlencode( $redirect );
	}
	return $args ? add_query_arg( $args, $url ) : $url;
}

/**
 * Dashboard link for the signed-in user (empty when signed out).
 *
 * @return array{url:string,role:string}
 */
function lnkup_public_account() {
	if ( ! is_user_logged_in() ) {
		return array( 'url' => '', 'role' => '' );
	}
	$roles = (array) wp_get_current_user()->roles;
	if ( in_array( 'lnkup_organizer', $roles, true ) && ! array_intersect( array( 'lnkup_vendor', 'administrator' ), $roles ) ) {
		return array( 'url' => lnkup_page_url( 'organizer-dashboard' ), 'role' => 'organizer' );
	}
	if ( array_intersect( array( 'lnkup_vendor', 'lnkup_admin', 'administrator' ), $roles ) || current_user_can( 'manage_options' ) ) {
		return array( 'url' => lnkup_page_url( 'vendor-dashboard' ), 'role' => in_array( 'lnkup_vendor', $roles, true ) ? 'vendor' : 'admin' );
	}
	if ( in_array( 'lnkup_organizer', $roles, true ) ) {
		return array( 'url' => lnkup_page_url( 'organizer-dashboard' ), 'role' => 'organizer' );
	}
	return array( 'url' => '', 'role' => '' );
}

/**
 * Primary navigation. Every link points at a page that exists today; groups that would be empty
 * are dropped instead of linking to placeholders.
 *
 * @return array<int, array<string, mixed>>
 */
function lnkup_public_nav() {
	$open      = lnkup_theme_marketplace_open();
	$feed      = lnkup_theme_opportunities_url();
	$discovery = lnkup_public_discovery();
	$nav       = array();

	if ( $open ) {
		$events = array(
			array( 'label' => __( 'All open events', 'lnkup-theme' ), 'desc' => __( 'Festivals, markets and pop-ups booking vendors', 'lnkup-theme' ), 'url' => $feed ),
			array( 'label' => __( 'This weekend', 'lnkup-theme' ), 'desc' => __( 'Events happening in the next few days', 'lnkup-theme' ), 'url' => add_query_arg( 'date_preset', 'weekend', $feed ) ),
			array( 'label' => __( 'Closing soon', 'lnkup-theme' ), 'desc' => __( 'Applications that close shortly', 'lnkup-theme' ), 'url' => add_query_arg( 'closing_soon', '1', $feed ) ),
		);
		$cities = array();
		foreach ( array_slice( (array) ( $discovery['cities'] ?? array() ), 0, 4 ) as $city ) {
			$cities[] = array(
				'label' => (string) $city['label'],
				/* translators: %d: open events */
				'count' => sprintf( _n( '%d open event', '%d open events', (int) $city['count'], 'lnkup-theme' ), (int) $city['count'] ),
				'url'   => (string) ( $city['market_url'] ? $city['market_url'] : $city['url'] ),
			);
		}
		if ( ! empty( $discovery['tours'] ) ) {
			$events[] = array( 'label' => __( 'Tours & series', 'lnkup-theme' ), 'desc' => __( 'Multi-city tours and recurring markets', 'lnkup-theme' ), 'url' => home_url( '/#lp-tours' ) );
		}
		$nav[] = array(
			'id'     => 'events',
			'label'  => __( 'Find events', 'lnkup-theme' ),
			'groups' => array_filter(
				array(
					array( 'title' => __( 'Browse', 'lnkup-theme' ), 'links' => $events ),
					$cities ? array( 'title' => __( 'Popular cities', 'lnkup-theme' ), 'links' => $cities ) : null,
				)
			),
		);
	}

	$nav[] = array(
		'id'     => 'vendors',
		'label'  => __( 'For vendors', 'lnkup-theme' ),
		'groups' => array(
			array(
				'title' => '',
				'links' => array_values(
					array_filter(
						array(
							array( 'label' => __( 'How LNKup works for vendors', 'lnkup-theme' ), 'desc' => __( 'Apply free, pay after approval', 'lnkup-theme' ), 'url' => home_url( '/for-vendors/' ) ),
							array( 'label' => __( 'Vendor pricing', 'lnkup-theme' ), 'desc' => __( 'What you pay and when', 'lnkup-theme' ), 'url' => home_url( '/for-vendors/#pricing' ) ),
							$open ? array( 'label' => __( 'Browse events', 'lnkup-theme' ), 'desc' => __( 'See who is booking vendors now', 'lnkup-theme' ), 'url' => $feed ) : null,
							array( 'label' => __( 'Create a vendor profile', 'lnkup-theme' ), 'desc' => __( 'Free account, ready in minutes', 'lnkup-theme' ), 'url' => lnkup_public_signup_url( 'vendor' ) ),
						)
					)
				),
			),
		),
	);

	$nav[] = array(
		'id'     => 'organizers',
		'label'  => __( 'For organizers', 'lnkup-theme' ),
		'groups' => array(
			array(
				'title' => '',
				'links' => array(
					array( 'label' => __( 'How LNKup works for organizers', 'lnkup-theme' ), 'desc' => __( 'Fill booths, collect fees, run load-in', 'lnkup-theme' ), 'url' => home_url( '/for-organizers/' ) ),
					array( 'label' => __( 'Organizer pricing', 'lnkup-theme' ), 'desc' => __( 'Free to list; a small share of paid bookings', 'lnkup-theme' ), 'url' => home_url( '/for-organizers/#pricing' ) ),
					array( 'label' => __( 'Vendor directory', 'lnkup-theme' ), 'desc' => __( 'Public vendor profiles', 'lnkup-theme' ), 'url' => lnkup_page_url( 'vendors' ) ),
					array( 'label' => __( 'List your event', 'lnkup-theme' ), 'desc' => __( 'Create a free organizer account', 'lnkup-theme' ), 'url' => lnkup_public_signup_url( 'organizer' ) ),
				),
			),
		),
	);

	$nav[] = array(
		'id'    => 'organizers-directory',
		'label' => __( 'Organizers', 'lnkup-theme' ),
		'url'   => lnkup_page_url( 'organizers' ),
	);

	return $nav;
}

/**
 * Landing setting with an explicit fallback (draft-aware via LNKup_Theme_Landing_Settings).
 *
 * @param string $key      Key.
 * @param mixed  $fallback Fallback.
 * @return mixed
 */
function lnkup_public_setting( $key, $fallback = '' ) {
	$value = lnkup_landing_get( $key, $fallback );
	if ( is_string( $value ) && '' === trim( $value ) ) {
		return $fallback;
	}
	return $value;
}

/**
 * How-it-works steps. Each step describes a feature that exists in the product today.
 *
 * @return array{vendor:array<int,array{0:string,1:string}>,organizer:array<int,array{0:string,1:string}>}
 */
function lnkup_public_steps() {
	return array(
		'vendor'    => array(
			array( __( 'Create your free profile', 'lnkup-theme' ), __( 'Add your business, photos, categories and documents once. Organizers see a complete vendor Passport.', 'lnkup-theme' ) ),
			array( __( 'Find events and apply', 'lnkup-theme' ), __( 'Filter by city, date, category and booth price. Applying is always free.', 'lnkup-theme' ) ),
			array( __( 'Get approved, then pay', 'lnkup-theme' ), __( 'You pay the booth fee only after an organizer approves you, with secure card checkout.', 'lnkup-theme' ) ),
			array( __( 'Show up ready', 'lnkup-theme' ), __( 'Your event packet unlocks with load-in details, location and check-in.', 'lnkup-theme' ) ),
		),
		'organizer' => array(
			array( __( 'Publish your vendor opportunities', 'lnkup-theme' ), __( 'Set booth types, prices, spot counts, deadlines and required documents.', 'lnkup-theme' ) ),
			array( __( 'Reach and invite vendors', 'lnkup-theme' ), __( 'Vendors find you in search, and you can invite vendors who match your event.', 'lnkup-theme' ) ),
			array( __( 'Review, approve, get paid', 'lnkup-theme' ), __( 'Compare applications side by side. Approved vendors pay online and payouts go to your bank.', 'lnkup-theme' ) ),
			array( __( 'Run event day', 'lnkup-theme' ), __( 'Vendor lists, booth maps and check-in in one place — on the web or the iPhone app.', 'lnkup-theme' ) ),
		),
	);
}

/**
 * Organizer tools that exist in the dashboard today.
 *
 * @return array<int, array{icon:string,title:string,body:string}>
 */
function lnkup_public_organizer_features() {
	return array(
		array( 'icon' => 'doc', 'title' => __( 'Applications in one queue', 'lnkup-theme' ), 'body' => __( 'Every vendor’s profile, photos, categories and documents in one place. Approve, waitlist or decline.', 'lnkup-theme' ) ),
		array( 'icon' => 'card', 'title' => __( 'Online booth payments', 'lnkup-theme' ), 'body' => __( 'Approved vendors pay by card. No chasing payments by text; payouts go to your bank.', 'lnkup-theme' ) ),
		array( 'icon' => 'shield', 'title' => __( 'Required documents', 'lnkup-theme' ), 'body' => __( 'Ask for permits, insurance or menus up front and see who is ready before load-in.', 'lnkup-theme' ) ),
		array( 'icon' => 'users', 'title' => __( 'Find and invite vendors', 'lnkup-theme' ), 'body' => __( 'Search vendors by category and city, save lists and invite them to apply.', 'lnkup-theme' ) ),
		array( 'icon' => 'map', 'title' => __( 'Booth maps and check-in', 'lnkup-theme' ), 'body' => __( 'Assign booths and check vendors in on event day from the web or the iPhone app.', 'lnkup-theme' ) ),
		array( 'icon' => 'bell', 'title' => __( 'Automatic updates', 'lnkup-theme' ), 'body' => __( 'Vendors get approval, payment and event-day notices without you sending each one.', 'lnkup-theme' ) ),
	);
}

/**
 * Vendor tools that exist today.
 *
 * @return array<int, array{icon:string,title:string,body:string}>
 */
function lnkup_public_vendor_features() {
	$features = array(
		array( 'icon' => 'search', 'title' => __( 'Search that fits your business', 'lnkup-theme' ), 'body' => __( 'Filter by city, date, category, attendance and booth price. Save events for later.', 'lnkup-theme' ) ),
		array( 'icon' => 'store', 'title' => __( 'Your vendor Passport', 'lnkup-theme' ), 'body' => __( 'One profile with your photos, products, setup needs and documents — reused for every application.', 'lnkup-theme' ) ),
		array( 'icon' => 'card', 'title' => __( 'Pay only when approved', 'lnkup-theme' ), 'body' => __( 'No upfront fees. Secure checkout after the organizer says yes.', 'lnkup-theme' ) ),
		array( 'icon' => 'qr', 'title' => __( 'Event packet and check-in', 'lnkup-theme' ), 'body' => __( 'Load-in details, location and your check-in pass unlock after you book.', 'lnkup-theme' ) ),
	);
	if ( class_exists( 'LNKup_Comms_Settings' ) && LNKup_Comms_Settings::enabled( 'flow_discovery' ) ) {
		$features[] = array( 'icon' => 'bell', 'title' => __( 'Alerts for new events', 'lnkup-theme' ), 'body' => __( 'Save a search and get notified when matching events open.', 'lnkup-theme' ) );
	}
	return $features;
}

/**
 * Feature grid markup.
 *
 * @param array<int, array{icon:string,title:string,body:string}> $features Features.
 */
function lnkup_public_feature_grid( $features ) {
	?>
	<div class="lp-grid lp-grid--3">
		<?php foreach ( $features as $feature ) : ?>
			<article class="lp-feature">
				<span class="lp-feature__icon"><?php echo lnkup_public_icon( $feature['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<h3><?php echo esc_html( $feature['title'] ); ?></h3>
				<p><?php echo esc_html( $feature['body'] ); ?></p>
			</article>
		<?php endforeach; ?>
	</div>
	<?php
}

/**
 * Product preview: an admin-uploaded screenshot when set, otherwise a schematic illustration
 * (no numbers, names or metrics, so nothing reads as real activity).
 *
 * @param string $kind organizer|vendor.
 */
function lnkup_public_preview( $kind = 'organizer' ) {
	$shot = (int) lnkup_landing_get( 'shot_' . $kind, 0 );
	if ( $shot && wp_attachment_is_image( $shot ) ) {
		?>
		<figure class="lp-preview" style="margin:0">
			<?php echo lnkup_public_image( $shot, array( 'alt' => 'organizer' === $kind ? __( 'LNKup organizer dashboard', 'lnkup-theme' ) : __( 'LNKup vendor dashboard', 'lnkup-theme' ), 'class' => 'lp-preview__shot', 'sizes' => '(max-width: 960px) 92vw, 560px' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</figure>
		<?php
		return;
	}
	$rows = 'organizer' === $kind
		? array( array( __( 'Approved', 'lnkup-theme' ), true ), array( __( 'Paid', 'lnkup-theme' ), true ), array( __( 'Review', 'lnkup-theme' ), false ), array( __( 'Review', 'lnkup-theme' ), false ) )
		: array( array( __( 'Approved', 'lnkup-theme' ), true ), array( __( 'Applied', 'lnkup-theme' ), false ), array( __( 'Applied', 'lnkup-theme' ), false ) );
	?>
	<figure class="lp-preview" style="margin:0" aria-hidden="true">
		<div class="lp-preview__window">
			<div class="lp-preview__bar"><span></span><span></span><span></span><strong><?php echo 'organizer' === $kind ? esc_html__( 'Vendor applications', 'lnkup-theme' ) : esc_html__( 'My applications', 'lnkup-theme' ); ?></strong></div>
			<div class="lp-preview__body">
				<?php foreach ( $rows as $row ) : ?>
					<div class="lp-preview__row">
						<span class="lp-preview__avatar"></span>
						<span class="lp-preview__lines"><span class="lp-preview__line"></span><span class="lp-preview__line lp-preview__line--short"></span></span>
						<span class="lp-preview__tag<?php echo $row[1] ? ' lp-preview__tag--ok' : ''; ?>"><?php echo esc_html( $row[0] ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<figcaption class="lp-preview__caption"><?php esc_html_e( 'Illustration', 'lnkup-theme' ); ?></figcaption>
	</figure>
	<?php
}

/**
 * FAQs for a page: admin-edited text (Appearance → Landing Page) or defaults built from live pricing.
 * Admin format: question on its first line, answer below, blank line between pairs.
 *
 * @param string $page home|organizers|vendors.
 * @return array<int, array{q:string,a:string}>
 */
function lnkup_public_faqs( $page ) {
	$raw = trim( (string) lnkup_landing_get( 'faq_' . $page, '' ) );
	if ( '' !== $raw ) {
		$out = array();
		foreach ( preg_split( "/\R\s*\R/", $raw ) as $block ) {
			$lines = preg_split( '/\R/', trim( (string) $block ), 2 );
			if ( count( $lines ) === 2 && '' !== trim( $lines[0] ) && '' !== trim( $lines[1] ) ) {
				$out[] = array( 'q' => trim( $lines[0] ), 'a' => trim( $lines[1] ) );
			}
		}
		if ( $out ) {
			return $out;
		}
	}
	$copy = lnkup_public_pricing_copy();

	$vendor = array(
		array(
			'q' => __( 'Does it cost anything to apply?', 'lnkup-theme' ),
			'a' => __( 'No. Creating a profile and applying to events is free. You only pay when an organizer approves your application.', 'lnkup-theme' ),
		),
		array(
			'q' => __( 'What do I pay when I am approved?', 'lnkup-theme' ),
			'a' => $copy ? $copy['vendor'] : __( 'The organizer’s booth price plus the fees shown at checkout. Booth prices are listed before you apply.', 'lnkup-theme' ),
		),
		array(
			'q' => __( 'What kinds of events are on LNKup?', 'lnkup-theme' ),
			'a' => __( 'Festivals, night markets, pop-ups, block parties, rodeos, cultural events and more — anywhere organizers need food, retail, art, beauty and service vendors.', 'lnkup-theme' ),
		),
	);
	$organizer = array(
		array(
			'q' => __( 'Does LNKup sell tickets?', 'lnkup-theme' ),
			'a' => __( 'No. LNKup handles the vendor side of your event. Keep selling tickets wherever you already do.', 'lnkup-theme' ),
		),
		array(
			'q' => __( 'How much does LNKup cost organizers?', 'lnkup-theme' ),
			'a' => $copy ? $copy['organizer'] : __( 'Listing your event and reviewing vendors is free. A small share of each paid booth fee is deducted before your payout.', 'lnkup-theme' ),
		),
		array(
			'q' => __( 'How do I get paid?', 'lnkup-theme' ),
			'a' => __( 'Finish payout setup once in your dashboard. Vendor payments for your event are paid out to your bank account.', 'lnkup-theme' ),
		),
		array(
			'q' => __( 'Can I invite vendors I already work with?', 'lnkup-theme' ),
			'a' => __( 'Yes. Invite vendors to your event, and they apply and pay through the same flow as everyone else.', 'lnkup-theme' ),
		),
	);
	switch ( $page ) {
		case 'vendors':
			return $vendor;
		case 'organizers':
			return $organizer;
		default:
			return array( $vendor[0], $vendor[1], $organizer[0], $organizer[1] );
	}
}

/**
 * FAQ block markup plus FAQPage JSON-LD (only for questions rendered on the page).
 *
 * @param array<int, array{q:string,a:string}> $items  Questions.
 * @param string                               $testid data-testid.
 */
function lnkup_public_faq( $items, $testid = 'lp-faq' ) {
	$items = array_values(
		array_filter(
			(array) $items,
			static function ( $item ) {
				return is_array( $item ) && '' !== trim( (string) ( $item['q'] ?? '' ) ) && '' !== trim( (string) ( $item['a'] ?? '' ) );
			}
		)
	);
	if ( ! $items ) {
		return;
	}
	$schema = array(
		'@context'   => 'https://schema.org',
		'@type'      => 'FAQPage',
		'mainEntity' => array(),
	);
	?>
	<div class="lp-faq" data-testid="<?php echo esc_attr( $testid ); ?>">
		<?php foreach ( $items as $item ) : ?>
			<details>
				<summary><?php echo esc_html( $item['q'] ); ?></summary>
				<div class="lp-faq__answer"><?php echo wp_kses_post( wpautop( esc_html( $item['a'] ) ) ); ?></div>
			</details>
			<?php
			$schema['mainEntity'][] = array(
				'@type'          => 'Question',
				'name'           => wp_strip_all_tags( $item['q'] ),
				'acceptedAnswer' => array( '@type' => 'Answer', 'text' => wp_strip_all_tags( $item['a'] ) ),
			);
			?>
		<?php endforeach; ?>
	</div>
	<script type="application/ld+json"><?php echo wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP ); ?></script>
	<?php
}

/**
 * Section heading block.
 *
 * @param array<string, string> $args eyebrow, title, lead, id, align (center), action_label, action_url.
 */
function lnkup_public_section_head( $args ) {
	$args = wp_parse_args( $args, array( 'eyebrow' => '', 'title' => '', 'lead' => '', 'id' => '', 'align' => '', 'action_label' => '', 'action_url' => '', 'title_html' => '' ) );
	?>
	<div class="lp-section-head<?php echo 'center' === $args['align'] ? ' lp-section-head--center' : ''; ?>">
		<div class="lp-section-head__text">
			<?php if ( $args['eyebrow'] ) : ?>
				<p class="lp-eyebrow"><?php echo esc_html( $args['eyebrow'] ); ?></p>
			<?php endif; ?>
			<h2 class="lp-h2"<?php echo $args['id'] ? ' id="' . esc_attr( $args['id'] ) . '"' : ''; ?>><?php echo $args['title_html'] ? wp_kses( $args['title_html'], array( 'em' => array() ) ) : esc_html( $args['title'] ); ?></h2>
			<?php if ( $args['lead'] ) : ?>
				<p class="lp-lead"><?php echo esc_html( $args['lead'] ); ?></p>
			<?php endif; ?>
		</div>
		<?php if ( $args['action_label'] && $args['action_url'] ) : ?>
			<a class="lp-btn lp-btn--outline" href="<?php echo esc_url( $args['action_url'] ); ?>"><?php echo esc_html( $args['action_label'] ); ?> <?php echo lnkup_public_icon( 'arrow' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
		<?php endif; ?>
	</div>
	<?php
}

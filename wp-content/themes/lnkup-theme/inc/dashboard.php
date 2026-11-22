<?php

/**

 * Dashboard access control and shell helpers.

 *

 * @package LNKup_Theme

 */



defined( 'ABSPATH' ) || exit;



/**

 * Require login for dashboard pages.

 */

function lnkup_require_login() {

	if ( ! is_user_logged_in() ) {

		$host        = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by esc_url_raw + wp_validate_redirect below.
		$return      = ( $host && $request_uri ) ? set_url_scheme( 'http://' . $host . $request_uri ) : get_permalink();
		$return      = wp_validate_redirect( esc_url_raw( $return ), (string) get_permalink() );
		$login       = function_exists( 'lnkup_login_url_for' ) ? lnkup_login_url_for( $return ) : wp_login_url( $return );
		wp_safe_redirect( $login );

		exit;

	}

}



/**

 * Require one of the given roles.

 *

 * @param array $roles Role slugs.

 */

function lnkup_require_roles( array $roles ) {

	lnkup_require_login();

	$user = wp_get_current_user();

	$allowed = array_intersect( $roles, (array) $user->roles ) || current_user_can( 'manage_options' );

	/**
	 * Let the plugin admit users who hold access another way (e.g. organization team members).
	 *
	 * @param bool     $allowed Whether the user passes the role check.
	 * @param string[] $roles   Roles the template requires.
	 * @param WP_User  $user    Current user.
	 */
	if ( ! apply_filters( 'lnkup_require_roles_allowed', $allowed, $roles, $user ) ) {

		wp_die( esc_html__( 'You do not have permission to access this page.', 'lnkup-theme' ), 403 );

	}

}



/**

 * Vendor dashboard page title from current route.

 */

function lnkup_dashboard_vendor_title() {

	$slug = get_post_field( 'post_name', get_queried_object_id() );

	$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended



	if ( 'opportunities' === $slug ) {

		return __( 'Find Events', 'lnkup-theme' );

	}

	if ( 'vendor-profile' === $slug ) {

		return function_exists( 'lnkup_copy' ) ? lnkup_copy( 'nav_profile' ) : __( 'Profile', 'lnkup-theme' );

	}

	if ( 'favorites' === $slug ) {

		return __( 'Favorites', 'lnkup-theme' );

	}



	$titles = array(

		'home'         => __( 'Overview', 'lnkup-theme' ),

		'applications' => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'nav_requests' ) : __( 'Requests', 'lnkup-theme' ),

		'documents'    => __( 'Documents', 'lnkup-theme' ),

		'payments'     => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'nav_wallet' ) : __( 'Wallet', 'lnkup-theme' ),

		'growth'       => __( 'Growth', 'lnkup-theme' ),

		'referrals'    => __( 'Referral Invites', 'lnkup-theme' ),

		'reviews'      => __( 'Reviews', 'lnkup-theme' ),

		'passport'     => __( 'Vendor Passport', 'lnkup-theme' ),

		'operations'   => __( 'Event Operations', 'lnkup-theme' ),

		'help'         => __( 'Help', 'lnkup-theme' ),

	);



	if ( $view && isset( $titles[ $view ] ) ) {

		return $titles[ $view ];

	}



	return __( 'Overview', 'lnkup-theme' );

}



/**
 * Organizer shell title (hash-aware via JS; server default is Home).
 *
 * @return string
 */
function lnkup_dashboard_organizer_title() {
	$view   = function_exists( 'lnkup_organizer_dashboard_view' ) ? lnkup_organizer_dashboard_view() : 'home';
	$titles = array(
		'home'         => __( 'Overview', 'lnkup-theme' ),
		'start'        => __( 'Get started', 'lnkup-theme' ),
		'events'       => __( 'Events', 'lnkup-theme' ),
		'applications' => __( 'Requests', 'lnkup-theme' ),
		'financial'    => __( 'Money', 'lnkup-theme' ),
		'vendors'      => __( 'Vendors', 'lnkup-theme' ),
		'lists'        => __( 'Vendor Lists', 'lnkup-theme' ),
		'invites'      => __( 'Invites', 'lnkup-theme' ),
		'referrals'    => __( 'Referral Invites', 'lnkup-theme' ),
		'operations'   => __( 'Operations', 'lnkup-theme' ),
		'intelligence' => __( 'Intelligence', 'lnkup-theme' ),
		'create'       => __( 'Launch event', 'lnkup-theme' ),
		'help'         => __( 'Help', 'lnkup-theme' ),
		'profile'      => __( 'Profile', 'lnkup-theme' ),
		'team'         => __( 'Team & Trust', 'lnkup-theme' ),
	);
	return $titles[ $view ] ?? __( 'Overview', 'lnkup-theme' );
}

/**

 * Nav icon SVG by key.

 *

 * @param string $icon Icon key.

 */

function lnkup_dashboard_nav_icon( $icon ) {

	$icons = array(

		'home'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/></svg>',

		'search'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>',

		'apps'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/></svg>',

		'store'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9h18l-1.5-5h-15z"/><path d="M4 9v11h16V9"/><path d="M9 20v-6h6v6"/><path d="M3 9a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"/></svg>',

		'folder'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>',

		'payment' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><path d="M1 10h22"/></svg>',

		'star'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m12 2 3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>',

		'calendar' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>',

		'users'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>',

		'inbox'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-6l-2 3H10l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg>',

		'download' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5M12 15V3"/></svg>',

		'list'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>',

		'mail'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>',

		'operations' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>',

		'heart'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>',

		'chart'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v18h18"/><path d="M18 17V9M13 17V5M8 17v-3"/></svg>',

		'help'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/></svg>',

		'user'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',

		'share'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.59 13.51l6.83 3.98M15.41 6.51l-6.82 3.98"/></svg>',

		'plus'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v8M8 12h8"/></svg>',

	);

	$svg = $icons[ $icon ] ?? $icons['home'];

	if ( function_exists( 'lnkup_inline_icon' ) ) {
		return lnkup_inline_icon( $svg, 18 );
	}

	return preg_replace( '/<svg\s/', '<svg width="18" height="18" ', $svg, 1 );

}



/**

 * Get dashboard nav items for a role.

 *

 * @param string $role vendor|organizer.

 */

/**
 * Hash fragment from a dashboard nav href.
 *
 * @param string $href Nav href.
 * @return string
 */
function lnkup_dashboard_nav_hash_from_href( $href ) {
	if ( empty( $href ) || false === strpos( $href, '#' ) ) {
		return '';
	}
	$parts = wp_parse_url( $href );
	return isset( $parts['fragment'] ) ? (string) $parts['fragment'] : '';
}

/**
 * Whether a dashboard nav item is active on first paint.
 *
 * @param array  $item         Nav item.
 * @param string $role         vendor|organizer.
 * @param string $active_slug  Current page slug.
 * @param string $active_view  Current ?view= for vendor dashboard.
 * @return bool
 */
function lnkup_dashboard_is_nav_item_active( $item, $role, $active_slug, $active_view ) {
	if ( 'vendor' === $role ) {
		$item_view = $item['view'] ?? '';
		if ( $item_view ) {
			return 'vendor-dashboard' === $active_slug && $active_view === $item_view;
		}
		if ( 'vendor-dashboard' === ( $item['slug'] ?? '' ) ) {
			return 'vendor-dashboard' === $active_slug && ! $active_view;
		}
		return $active_slug === ( $item['slug'] ?? '' );
	}

	if ( 'connector' === $role ) {
		$item_view = $item['view'] ?? '';
		$aliases   = array(
			'guide'      => 'resources',
			'links'      => 'start',
			'rewards'    => 'earnings',
			'vendors'    => 'referrals',
			'organizers' => 'referrals',
			'activity'   => 'referrals',
		);
		$current = $active_view ? $active_view : 'home';
		if ( isset( $aliases[ $current ] ) ) {
			$current = $aliases[ $current ];
		}
		if ( $item_view ) {
			return 'connector-dashboard' === $active_slug && $current === $item_view;
		}
		return 'connector-dashboard' === $active_slug && 'home' === $current;
	}

	if ( 'organizer' === $role ) {
		$current = function_exists( 'lnkup_organizer_dashboard_view' ) ? lnkup_organizer_dashboard_view( $active_view ) : ( $active_view ? $active_view : 'home' );
		$event_id = isset( $_GET['event'] ) ? absint( wp_unslash( $_GET['event'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $event_id && ( ! $active_view || 'home' === $current ) ) {
			$current = 'events';
		}
		$item_view = $item['view'] ?? '';
		return $item_view === $current;
	}

	return $active_slug === ( $item['slug'] ?? '' );
}

/**
 * Render one sidebar nav link.
 *
 * @param array  $item         Nav item.
 * @param string $role         vendor|organizer.
 * @param string $active_slug  Current page slug.
 * @param string $active_view  Current ?view=.
 */
function lnkup_dashboard_render_nav_link( $item, $role, $active_slug, $active_view ) {
	$hash_slug = lnkup_dashboard_nav_hash_from_href( $item['href'] ?? '' );
	$item_view = $item['view'] ?? '';
	$active    = lnkup_dashboard_is_nav_item_active( $item, $role, $active_slug, $active_view );
	$locked    = ! empty( $item['locked'] );
	$classes   = 'lnkup-dashboard__nav-link';
	if ( $active ) {
		$classes .= ' is-active';
	}
	if ( $locked ) {
		$classes .= ' is-locked';
	}
	$href  = $locked ? '#' : ( $item['href'] ?? '#' );
	$attrs = ' class="' . esc_attr( $classes ) . '"';
	if ( $hash_slug && ! $locked ) {
		$attrs .= ' data-hash="' . esc_attr( $hash_slug ) . '"';
	}
	if ( ! empty( $item['also_active_hashes'] ) && is_array( $item['also_active_hashes'] ) ) {
		$attrs .= ' data-also-active="' . esc_attr( implode( ',', $item['also_active_hashes'] ) ) . '"';
	}
	if ( $item_view ) {
		$attrs .= ' data-view="' . esc_attr( $item_view ) . '"';
	}
	if ( ! empty( $item['locked_hint'] ) ) {
		$attrs .= ' title="' . esc_attr( $item['locked_hint'] ) . '"';
	}
	if ( $locked ) {
		$attrs .= ' aria-disabled="true" tabindex="-1"';
	}
	if ( $active ) {
		$attrs .= ' aria-current="page"';
	}
	?>
	<a href="<?php echo esc_url( $href ); ?>"<?php echo $attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
		<?php if ( ! empty( $item['icon'] ) ) : ?>
			<span class="lnkup-dashboard__nav-icon" aria-hidden="true"><?php echo lnkup_dashboard_nav_icon( $item['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
		<?php endif; ?>
		<span class="lnkup-dashboard__nav-text-wrap">
			<span class="lnkup-dashboard__nav-text"><?php echo esc_html( $item['label'] ); ?></span>
			<?php if ( $locked && ! empty( $item['locked_hint'] ) ) : ?>
				<span class="lnkup-dashboard__nav-hint"><?php echo esc_html( $item['locked_hint'] ); ?></span>
			<?php endif; ?>
		</span>
		<?php if ( $locked ) : ?>
			<span class="lnkup-dashboard__nav-lock" aria-hidden="true"><?php esc_html_e( 'Soon', 'lnkup-theme' ); ?></span>
		<?php endif; ?>
		<?php if ( ! empty( $item['badge'] ) ) : ?>
			<span class="lnkup-dashboard__nav-badge"><?php echo esc_html( (string) $item['badge'] ); ?></span>
		<?php endif; ?>
	</a>
	<?php
}

function lnkup_get_dashboard_nav( $role ) {

	if ( 'connector' === $role ) {
		$dashboard = lnkup_page_url( 'connector-dashboard' );
		$items     = array(
			array( 'label' => __( 'Overview', 'lnkup-theme' ), 'href' => add_query_arg( 'view', 'home', $dashboard ), 'slug' => 'connector-dashboard', 'icon' => 'home', 'view' => 'home' ),
			array( 'label' => __( 'Get Started', 'lnkup-theme' ), 'href' => add_query_arg( 'view', 'start', $dashboard ), 'slug' => 'connector-dashboard', 'icon' => 'help', 'view' => 'start' ),
			array( 'label' => __( 'My Referrals', 'lnkup-theme' ), 'href' => add_query_arg( 'view', 'referrals', $dashboard ), 'slug' => 'connector-dashboard', 'icon' => 'users', 'view' => 'referrals' ),
			array( 'label' => __( 'Organizer Leads', 'lnkup-theme' ), 'href' => add_query_arg( 'view', 'leads', $dashboard ), 'slug' => 'connector-dashboard', 'icon' => 'list', 'view' => 'leads' ),
			array( 'label' => __( 'My Cities', 'lnkup-theme' ), 'href' => add_query_arg( 'view', 'cities', $dashboard ), 'slug' => 'connector-dashboard', 'icon' => 'search', 'view' => 'cities' ),
			array( 'label' => __( 'Earnings', 'lnkup-theme' ), 'href' => add_query_arg( 'view', 'earnings', $dashboard ), 'slug' => 'connector-dashboard', 'icon' => 'payment', 'view' => 'earnings' ),
			array( 'label' => __( 'Ranks', 'lnkup-theme' ), 'href' => add_query_arg( 'view', 'ranks', $dashboard ), 'slug' => 'connector-dashboard', 'icon' => 'star', 'view' => 'ranks' ),
			array( 'label' => __( 'Resources', 'lnkup-theme' ), 'href' => add_query_arg( 'view', 'resources', $dashboard ), 'slug' => 'connector-dashboard', 'icon' => 'mail', 'view' => 'resources' ),
			array( 'label' => __( 'Content Studio', 'lnkup-theme' ), 'href' => add_query_arg( 'view', 'studio', $dashboard ), 'slug' => 'connector-dashboard', 'icon' => 'plus', 'view' => 'studio' ),
			array( 'label' => __( 'Profile', 'lnkup-theme' ), 'href' => add_query_arg( 'view', 'profile', $dashboard ), 'slug' => 'connector-dashboard', 'icon' => 'user', 'view' => 'profile' ),
		);
		if ( class_exists( 'LNKup_Connector' ) ) {
			$account = LNKup_Connector::get_by_user_id( get_current_user_id() );
			$allowed = array_keys( LNKup_Connector::dashboard_views( $account ) );
			$items   = array_values( array_filter( $items, static function ( $item ) use ( $allowed ) {
				return in_array( $item['view'], $allowed, true );
			} ) );
		}
		return $items;
	}

	if ( 'vendor' === $role ) {

		$dashboard = lnkup_page_url( 'vendor-dashboard' );

		$badges    = class_exists( 'LNKup_Vendor_Dashboard_Data' ) ? LNKup_Vendor_Dashboard_Data::nav_badges() : array();

		$marketplace_open = function_exists( 'lnkup_is_marketplace_open' ) && lnkup_is_marketplace_open();

		$profile_url = lnkup_page_url( 'vendor-profile' );

		$passport_on = function_exists( 'lnkup_passport_enabled' ) && lnkup_passport_enabled();

		$profile_item = $passport_on

			? array( 'label' => __( 'Passport', 'lnkup-theme' ), 'href' => add_query_arg( 'view', 'passport', $dashboard ), 'slug' => 'vendor-dashboard', 'icon' => 'store', 'view' => 'passport', 'tier' => 'primary' )

			: array( 'label' => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'nav_profile' ) : __( 'Profile', 'lnkup-theme' ), 'href' => $profile_url, 'slug' => 'vendor-profile', 'icon' => 'store', 'view' => '', 'tier' => 'primary' );



		return lnkup_dashboard_nav_with_app_item( array(

			array( 'label' => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'nav_home' ) : __( 'Home', 'lnkup-theme' ), 'href' => $dashboard, 'slug' => 'vendor-dashboard', 'icon' => 'home', 'view' => '', 'tier' => 'primary' ),

			array( 'label' => __( 'Find events', 'lnkup-theme' ), 'href' => lnkup_page_url( 'opportunities' ), 'slug' => 'opportunities', 'icon' => 'search', 'view' => '', 'locked' => ! $marketplace_open, 'locked_hint' => __( 'Opens on launch day', 'lnkup-theme' ), 'tier' => 'primary' ),

			array( 'label' => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'nav_requests' ) : __( 'Requests', 'lnkup-theme' ), 'href' => add_query_arg( 'view', 'applications', $dashboard ), 'slug' => 'vendor-dashboard', 'icon' => 'inbox', 'view' => 'applications', 'badge' => $marketplace_open ? ( $badges['applications'] ?? 0 ) : 0, 'locked' => ! $marketplace_open, 'locked_hint' => __( 'Opens on launch day', 'lnkup-theme' ), 'tier' => 'primary' ),

			array( 'label' => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'nav_wallet' ) : __( 'Wallet', 'lnkup-theme' ), 'href' => add_query_arg( 'view', 'payments', $dashboard ), 'slug' => 'vendor-dashboard', 'icon' => 'payment', 'view' => 'payments', 'tier' => 'primary' ),

			$profile_item,

			array( 'label' => __( 'Help', 'lnkup-theme' ), 'href' => add_query_arg( 'view', 'help', $dashboard ), 'slug' => 'vendor-dashboard', 'icon' => 'help', 'view' => 'help', 'tier' => 'secondary' ),

			array( 'label' => __( 'Documents', 'lnkup-theme' ), 'href' => add_query_arg( 'view', 'documents', $dashboard ), 'slug' => 'vendor-dashboard', 'icon' => 'folder', 'view' => 'documents', 'badge' => $badges['documents'] ?? 0, 'tier' => 'more' ),

			array( 'label' => __( 'Event Operations', 'lnkup-theme' ), 'href' => add_query_arg( 'view', 'operations', $dashboard ), 'slug' => 'vendor-dashboard', 'icon' => 'operations', 'view' => 'operations', 'tier' => 'more' ),

			array( 'label' => __( 'Growth', 'lnkup-theme' ), 'href' => add_query_arg( 'view', 'growth', $dashboard ), 'slug' => 'vendor-dashboard', 'icon' => 'chart', 'view' => 'growth', 'tier' => 'more' ),

			array( 'label' => __( 'Referral Invites', 'lnkup-theme' ), 'href' => add_query_arg( 'view', 'referrals', $dashboard ), 'slug' => 'vendor-dashboard', 'icon' => 'mail', 'view' => 'referrals', 'tier' => 'more' ),

			array( 'label' => __( 'Reviews', 'lnkup-theme' ), 'href' => add_query_arg( 'view', 'reviews', $dashboard ), 'slug' => 'vendor-dashboard', 'icon' => 'star', 'view' => 'reviews', 'tier' => 'more' ),

			array( 'label' => __( 'Favorites', 'lnkup-theme' ), 'href' => lnkup_page_url( 'favorites' ), 'slug' => 'favorites', 'icon' => 'heart', 'view' => '', 'locked' => ! $marketplace_open, 'locked_hint' => __( 'Opens on launch day', 'lnkup-theme' ), 'tier' => 'more' ),

		), 'vendor' );

	}



	$organizer_dash = lnkup_page_url( 'organizer-dashboard' );
	$org_view_url   = function ( $view ) use ( $organizer_dash ) {
		if ( function_exists( 'lnkup_organizer_view_url' ) ) {
			return lnkup_organizer_view_url( $view );
		}
		return add_query_arg( 'view', $view, $organizer_dash );
	};
	$org_badges = class_exists( 'LNKup_Organizer_Dashboard_Views' ) ? LNKup_Organizer_Dashboard_Views::nav_badges() : array();

	return lnkup_dashboard_nav_with_app_item( array(

		array( 'label' => __( 'Overview', 'lnkup-theme' ), 'href' => $org_view_url( 'home' ), 'slug' => 'organizer-dashboard', 'icon' => 'home', 'view' => 'home', 'tier' => 'primary' ),

		array( 'label' => __( 'Events', 'lnkup-theme' ), 'href' => $org_view_url( 'events' ), 'slug' => 'organizer-dashboard', 'icon' => 'calendar', 'view' => 'events', 'tier' => 'primary' ),

		array( 'label' => __( 'Requests', 'lnkup-theme' ), 'href' => $org_view_url( 'applications' ), 'slug' => 'organizer-dashboard', 'icon' => 'users', 'view' => 'applications', 'badge' => $org_badges['applications'] ?? 0, 'tier' => 'primary' ),

		array( 'label' => __( 'Money', 'lnkup-theme' ), 'href' => $org_view_url( 'financial' ), 'slug' => 'organizer-dashboard', 'icon' => 'payment', 'view' => 'financial', 'tier' => 'primary' ),

		array( 'label' => __( 'Vendors', 'lnkup-theme' ), 'href' => $org_view_url( 'vendors' ), 'slug' => 'organizer-dashboard', 'icon' => 'search', 'view' => 'vendors', 'tier' => 'primary' ),

		array( 'label' => __( 'Help', 'lnkup-theme' ), 'href' => $org_view_url( 'help' ), 'slug' => 'organizer-dashboard', 'icon' => 'help', 'view' => 'help', 'tier' => 'more' ),

		array( 'label' => __( 'Get started', 'lnkup-theme' ), 'href' => $org_view_url( 'start' ), 'slug' => 'organizer-dashboard', 'icon' => 'help', 'view' => 'start', 'tier' => 'secondary' ),

		array( 'label' => __( 'Profile', 'lnkup-theme' ), 'href' => $org_view_url( 'profile' ), 'slug' => 'organizer-dashboard', 'icon' => 'user', 'view' => 'profile', 'tier' => 'secondary' ),

		array( 'label' => __( 'Team & Trust', 'lnkup-theme' ), 'href' => $org_view_url( 'team' ), 'slug' => 'organizer-dashboard', 'icon' => 'users', 'view' => 'team', 'tier' => 'secondary' ),

		array( 'label' => __( 'Vendor Lists', 'lnkup-theme' ), 'href' => $org_view_url( 'lists' ), 'slug' => 'organizer-dashboard', 'icon' => 'list', 'view' => 'lists', 'tier' => 'secondary' ),

		array( 'label' => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'launch_event' ) : __( 'Launch event', 'lnkup-theme' ), 'href' => $org_view_url( 'create' ), 'slug' => 'organizer-dashboard', 'icon' => 'calendar', 'view' => 'create', 'tier' => 'more' ),

		array( 'label' => __( 'Invites', 'lnkup-theme' ), 'href' => $org_view_url( 'invites' ), 'slug' => 'organizer-dashboard', 'icon' => 'mail', 'view' => 'invites', 'tier' => 'more' ),

		array( 'label' => __( 'Referral Invites', 'lnkup-theme' ), 'href' => $org_view_url( 'referrals' ), 'slug' => 'organizer-dashboard', 'icon' => 'users', 'view' => 'referrals', 'tier' => 'more' ),

		array( 'label' => __( 'Operations', 'lnkup-theme' ), 'href' => $org_view_url( 'operations' ), 'slug' => 'organizer-dashboard', 'icon' => 'operations', 'view' => 'operations', 'tier' => 'more' ),

		array( 'label' => __( 'Intelligence', 'lnkup-theme' ), 'href' => $org_view_url( 'intelligence' ), 'slug' => 'organizer-dashboard', 'icon' => 'chart', 'view' => 'intelligence', 'tier' => 'more' ),

	), 'organizer' );

}

/**
 * Append an optional "Get the iPhone app" item to the More tier.
 *
 * @param array  $items Nav items.
 * @param string $role  vendor|organizer.
 * @return array
 */
function lnkup_dashboard_nav_with_app_item( array $items, $role ) {
	/**
	 * Plugins may append dashboard nav items (e.g. Notification settings).
	 *
	 * @param array  $items Nav items.
	 * @param string $role  vendor|organizer.
	 */
	$items = (array) apply_filters( 'lnkup_dashboard_nav_items', $items, $role );
	if ( ! function_exists( 'lnkup_theme_show_app_download' ) || ! lnkup_theme_show_app_download() ) {
		return $items;
	}
	$items[] = array(
		'label' => __( 'Get the iPhone app', 'lnkup-theme' ),
		'href'  => lnkup_theme_app_store_url( 'nav-' . $role ),
		'slug'  => 'app-store',
		'icon'  => 'download',
		'view'  => 'app-store',
		'tier'  => 'more',
	);
	return $items;
}



/**

 * Vendor shell user card data.

 */

function lnkup_dashboard_vendor_user_card() {

	$user       = wp_get_current_user();

	$profile_id = function_exists( 'lnkup_get_vendor_profile_id' ) ? lnkup_get_vendor_profile_id( $user->ID ) : 0;

	$business   = $profile_id ? (string) get_post_meta( $profile_id, '_lnkup_business_name', true ) : $user->display_name;

	$city       = $profile_id ? (string) get_post_meta( $profile_id, '_lnkup_city', true ) : '';

	$state      = $profile_id ? (string) get_post_meta( $profile_id, '_lnkup_state', true ) : '';

	$initials   = '';

	foreach ( preg_split( '/\s+/', trim( $business ) ) as $part ) {

		$initials .= strtoupper( substr( $part, 0, 1 ) );

		if ( strlen( $initials ) >= 2 ) {

			break;

		}

	}

	if ( ! $initials ) {

		$initials = strtoupper( substr( $user->display_name, 0, 2 ) );

	}

	$logo_id = $profile_id ? (int) get_post_meta( $profile_id, '_lnkup_logo_attachment_id', true ) : 0;
	$logo    = $logo_id ? (string) wp_get_attachment_image_url( $logo_id, 'thumbnail' ) : '';



	return array(

		'business' => $business,

		'location' => trim( $city . ( $state ? ', ' . $state : '' ) ),

		'initials' => $initials,

		'logo'     => $logo,

		'role'     => __( 'Vendor', 'lnkup-theme' ),

	);

}



/**

 * Organizer shell user card data.

 */

function lnkup_dashboard_organizer_user_card() {

	$user     = wp_get_current_user();

	$business = (string) get_user_meta( $user->ID, '_lnkup_organizer_public_name', true );

	if ( ! $business ) {

		$business = (string) get_user_meta( $user->ID, '_lnkup_organization_name', true );

	}

	if ( ! $business ) {

		$business = $user->display_name;

	}

	$city  = (string) get_user_meta( $user->ID, '_lnkup_organizer_city', true );
	$state = (string) get_user_meta( $user->ID, '_lnkup_organizer_state', true );

	$initials = '';

	foreach ( preg_split( '/\s+/', trim( $business ) ) as $part ) {

		$initials .= strtoupper( substr( $part, 0, 1 ) );

		if ( strlen( $initials ) >= 2 ) {

			break;

		}

	}

	if ( ! $initials ) {

		$initials = strtoupper( substr( $user->display_name, 0, 2 ) );

	}

	$logo_id = (int) get_user_meta( $user->ID, '_lnkup_organizer_logo_id', true );
	$logo    = $logo_id ? (string) wp_get_attachment_image_url( $logo_id, 'thumbnail' ) : '';
	$place   = trim( $city . ( $state ? ', ' . $state : '' ) );



	return array(

		'business' => $business,

		'location' => $place,

		'initials' => $initials,

		'logo'     => $logo,

		'role'     => __( 'Organizer', 'lnkup-theme' ),

	);

}



/**

 * Connector shell user card data.

 */

function lnkup_dashboard_connector_user_card() {

	$user = wp_get_current_user();

	$connector = class_exists( 'LNKup_Connector' ) ? LNKup_Connector::get_by_user_id( $user->ID ) : null;

	$business = $connector['display_name'] ?? $user->display_name;

	$location = '';

	if ( ! empty( $connector['city'] ) ) {

		$location = $connector['city'];

		if ( ! empty( $connector['state'] ) ) {

			$location .= ', ' . $connector['state'];

		}

	}

	$initials = strtoupper( substr( $business, 0, 1 ) );

	return array(

		'business' => $business,

		'location' => $location,

		'initials' => $initials,

		'role'     => __( 'City Connector', 'lnkup-theme' ),

	);

}



/**

 * Workspace chevron icon for sidebar selector.

 */

function lnkup_dashboard_chevron_icon() {

	$svg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m7 15 5 5 5-5M7 9l5-5 5 5"/></svg>';

	return function_exists( 'lnkup_inline_icon' ) ? lnkup_inline_icon( $svg, 16 ) : $svg;

}



/**

 * Render dashboard shell opening markup.

 *

 * @param string $role    vendor|organizer|connector.

 * @param string $title   Page title.

 */

function lnkup_dashboard_shell_open( $role, $title ) {

	$GLOBALS['lnkup_dashboard_shell_role'] = $role;

	$nav         = lnkup_get_dashboard_nav( $role );

	$user        = wp_get_current_user();

	$active_slug = get_post_field( 'post_name', get_queried_object_id() );

	$active_view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	$opp_url     = lnkup_page_url( 'opportunities' );

	$card        = 'vendor' === $role ? lnkup_dashboard_vendor_user_card() : ( 'organizer' === $role ? lnkup_dashboard_organizer_user_card() : ( 'connector' === $role ? lnkup_dashboard_connector_user_card() : null ) );

	$workspace_label = 'vendor' === $role ? __( 'Vendor', 'lnkup-theme' ) : ( 'organizer' === $role ? __( 'Organizer', 'lnkup-theme' ) : ( 'connector' === $role ? __( 'Connector', 'lnkup-theme' ) : '' ) );

	$switch_url      = '';

	if ( 'vendor' === $role && ( in_array( 'lnkup_organizer', (array) $user->roles, true ) || current_user_can( 'manage_options' ) ) ) {

		$switch_url = lnkup_page_url( 'organizer-dashboard' );

	} elseif ( 'organizer' === $role && ( in_array( 'lnkup_vendor', (array) $user->roles, true ) || current_user_can( 'manage_options' ) ) ) {

		$switch_url = lnkup_page_url( 'vendor-dashboard' );

	} elseif ( 'connector' === $role && ( array_intersect( array( 'lnkup_vendor', 'lnkup_organizer' ), (array) $user->roles ) || current_user_can( 'manage_options' ) ) ) {

		$switch_url = in_array( 'lnkup_organizer', (array) $user->roles, true ) ? lnkup_page_url( 'organizer-dashboard' ) : lnkup_page_url( 'vendor-dashboard' );

	}

	$switch_label = '';
	if ( $switch_url ) {
		$switch_label = false !== strpos( $switch_url, 'organizer-dashboard' )
			? __( 'Switch to Organizer', 'lnkup-theme' )
			: __( 'Switch to Vendor', 'lnkup-theme' );
	}

	$profile_url = 'vendor' === $role ? lnkup_page_url( 'vendor-profile' ) : ( 'connector' === $role ? lnkup_page_url( 'connector-dashboard' ) : lnkup_page_url( 'organizer-dashboard' ) );
	$public_profile_url = '';
	$edit_profile_url   = '';
	if ( 'organizer' === $role && class_exists( 'LNKup_Organizer_Onboarding' ) ) {
		$public_profile_url = LNKup_Organizer_Onboarding::organizer_public_url( (int) $user->ID );
		$edit_profile_url   = function_exists( 'lnkup_organizer_view_url' ) ? lnkup_organizer_view_url( 'profile' ) : add_query_arg( 'view', 'profile', lnkup_page_url( 'organizer-dashboard' ) );
	} elseif ( 'vendor' === $role && function_exists( 'lnkup_get_vendor_profile_id' ) ) {
		$vendor_profile_id = (int) lnkup_get_vendor_profile_id( (int) $user->ID );
		$vendor_slug       = $vendor_profile_id ? (string) get_post_meta( $vendor_profile_id, '_lnkup_public_slug', true ) : '';
		if ( $vendor_slug ) {
			$public_profile_url = add_query_arg( 'vendor', $vendor_slug, lnkup_page_url( 'vendors' ) );
		}
		$edit_profile_url = lnkup_page_url( 'vendor-profile' );
	}

	$event_id    = isset( $_GET['event'] ) ? absint( wp_unslash( $_GET['event'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$event_title = '';
	if ( $event_id ) {
		$event_post = get_post( $event_id );
		$owns_event = current_user_can( 'manage_options' );
		if ( ! $owns_event && class_exists( 'LNKup_Capabilities' ) && method_exists( 'LNKup_Capabilities', 'organizer_owns_event' ) ) {
			$owns_event = LNKup_Capabilities::organizer_owns_event( $event_id );
		}
		if ( $event_post && 'lnkup_event' === $event_post->post_type && $owns_event ) {
			$event_title = $event_post->post_title;
		}
	}
	$workspace_name = ( $card && ! empty( $card['business'] ) ) ? $card['business'] : $workspace_label;

	$nav_primary   = array();
	$nav_secondary = array();
	$nav_more      = array();
	foreach ( $nav as $nav_item ) {
		if ( ! empty( $nav_item['section'] ) ) {
			continue;
		}
		$tier = $nav_item['tier'] ?? 'primary';
		if ( 'more' === $tier ) {
			$nav_more[] = $nav_item;
		} elseif ( 'secondary' === $tier ) {
			$nav_secondary[] = $nav_item;
		} else {
			$nav_primary[] = $nav_item;
		}
	}

	?>

	<div class="lnkup-dashboard" data-lnkup-role="<?php echo esc_attr( $role ); ?>">

		<header class="lnkup-workspace-bar">
			<button type="button" class="lnkup-dashboard__menu-toggle" id="lnkup-menu-toggle" aria-label="<?php esc_attr_e( 'Toggle menu', 'lnkup-theme' ); ?>" aria-controls="lnkup-sidebar" aria-expanded="false">
				<span></span><span></span><span></span>
			</button>
			<div class="lnkup-workspace-bar__identity">
				<a class="lnkup-workspace-bar__brand" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php lnkup_logo(); ?></a>
				<?php if ( $workspace_name ) : ?>
					<span class="lnkup-workspace-bar__slash" aria-hidden="true">/</span>
					<span class="lnkup-workspace-bar__name"><?php echo esc_html( $workspace_name ); ?></span>
				<?php endif; ?>
				<?php if ( $event_title ) : ?>
					<span class="lnkup-workspace-bar__slash" aria-hidden="true">/</span>
					<span class="lnkup-workspace-bar__event"><?php echo esc_html( $event_title ); ?></span>
				<?php endif; ?>
			</div>
			<?php if ( 'vendor' === $role ) : ?>
				<form class="lnkup-dashboard__search-form" id="lnkup-dashboard-search-form" action="<?php echo esc_url( $opp_url ); ?>" method="get" role="search">
					<input type="search" name="q" class="lnkup-dashboard__search" placeholder="<?php esc_attr_e( 'Search events…', 'lnkup-theme' ); ?>" aria-label="<?php esc_attr_e( 'Search events', 'lnkup-theme' ); ?>" />
				</form>
			<?php elseif ( 'organizer' === $role ) : ?>
				<form class="lnkup-dashboard__search-form" id="lnkup-dashboard-search-form" action="<?php echo esc_url( lnkup_page_url( 'organizer-dashboard' ) ); ?>" method="get" role="search">
					<input type="search" name="crm_q" id="lnkup-dashboard-crm-search" class="lnkup-dashboard__search" placeholder="<?php esc_attr_e( 'Search vendors…', 'lnkup-theme' ); ?>" aria-label="<?php esc_attr_e( 'Search vendors', 'lnkup-theme' ); ?>" />
				</form>
			<?php endif; ?>
			<div class="lnkup-workspace-bar__tools">
				<?php if ( 'vendor' === $role || 'organizer' === $role ) : ?>
					<button type="button" class="lnkup-dashboard__search-toggle" id="lnkup-search-toggle" aria-label="<?php esc_attr_e( 'Open search', 'lnkup-theme' ); ?>" aria-controls="lnkup-dashboard-search-form" aria-expanded="false">
						<span class="lnkup-dashboard__search-toggle-icon" aria-hidden="true"><?php echo lnkup_dashboard_nav_icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					</button>
				<?php endif; ?>
				<div class="lnkup-workspace-profile">
					<button type="button" class="lnkup-workspace-bar__more lnkup-workspace-profile__toggle" id="lnkup-workspace-profile" aria-expanded="false" aria-controls="lnkup-workspace-profile-menu">
						<?php echo esc_html( $workspace_name ? $workspace_name : __( 'Account', 'lnkup-theme' ) ); ?>
					</button>
					<div class="lnkup-workspace-profile__menu" id="lnkup-workspace-profile-menu" hidden>
						<?php if ( $edit_profile_url ) : ?>
							<a href="<?php echo esc_url( $edit_profile_url ); ?>"><?php esc_html_e( 'Edit profile', 'lnkup-theme' ); ?></a>
						<?php endif; ?>
						<?php if ( $public_profile_url ) : ?>
							<button type="button" data-copy-url="<?php echo esc_url( $public_profile_url ); ?>"><?php esc_html_e( 'Copy link', 'lnkup-theme' ); ?></button>
							<a href="<?php echo esc_url( $public_profile_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View public page', 'lnkup-theme' ); ?></a>
						<?php endif; ?>
						<?php if ( lnkup_theme_show_app_download() ) : ?>
							<a class="lnkup-app-download" href="<?php echo esc_url( lnkup_theme_app_store_url() ); ?>"><?php esc_html_e( 'Get the iPhone app', 'lnkup-theme' ); ?></a>
						<?php endif; ?>
						<a class="lnkup-workspace-profile__logout" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Log out', 'lnkup-theme' ); ?></a>
					</div>
				</div>
				<button type="button" class="lnkup-workspace-bar__more" id="lnkup-workspace-more" aria-label="<?php esc_attr_e( 'More menu', 'lnkup-theme' ); ?>" aria-controls="lnkup-sidebar" aria-expanded="false">
					<?php esc_html_e( 'More', 'lnkup-theme' ); ?>
				</button>
			</div>
		</header>

		<div class="lnkup-dashboard__overlay" id="lnkup-sidebar-overlay" hidden></div>

		<aside class="lnkup-dashboard__sidebar" id="lnkup-sidebar">

			<div class="lnkup-dashboard__brand">

				<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php lnkup_logo(); ?></a>

			</div>



			<div class="lnkup-dashboard__sidebar-body">

				<?php if ( $workspace_label ) : ?>

					<?php if ( $switch_url ) : ?>

						<details class="lnkup-dashboard__workspace">

							<summary class="lnkup-dashboard__workspace-trigger">

								<span class="lnkup-dashboard__workspace-meta">

									<span class="lnkup-dashboard__workspace-label"><?php esc_html_e( 'Workspace', 'lnkup-theme' ); ?></span>

									<span class="lnkup-dashboard__workspace-current"><?php echo esc_html( $workspace_label ); ?></span>

								</span>

								<span class="lnkup-dashboard__workspace-chevron" aria-hidden="true"><?php echo lnkup_dashboard_chevron_icon(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>

							</summary>

							<a class="lnkup-dashboard__workspace-link" href="<?php echo esc_url( $switch_url ); ?>">

								<?php echo esc_html( $switch_label ); ?>

							</a>

						</details>

					<?php else : ?>

						<div class="lnkup-dashboard__workspace lnkup-dashboard__workspace--static">

							<span class="lnkup-dashboard__workspace-meta">

								<span class="lnkup-dashboard__workspace-label"><?php esc_html_e( 'Workspace', 'lnkup-theme' ); ?></span>

								<span class="lnkup-dashboard__workspace-current"><?php echo esc_html( $workspace_label ); ?></span>

							</span>

							<span class="lnkup-dashboard__workspace-chevron" aria-hidden="true"><?php echo lnkup_dashboard_chevron_icon(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>

						</div>

					<?php endif; ?>

				<?php endif; ?>



				<nav class="lnkup-dashboard__nav">

				<?php
				$nav_primary   = array();
				$nav_secondary = array();
				$nav_more      = array();
				foreach ( $nav as $nav_item ) {
					if ( ! empty( $nav_item['section'] ) ) {
						continue;
					}
					$tier = $nav_item['tier'] ?? 'primary';
					if ( 'more' === $tier ) {
						$nav_more[] = $nav_item;
					} elseif ( 'secondary' === $tier ) {
						$nav_secondary[] = $nav_item;
					} else {
						$nav_primary[] = $nav_item;
					}
				}
				foreach ( $nav_primary as $item ) {
					lnkup_dashboard_render_nav_link( $item, $role, $active_slug, $active_view );
				}
				if ( $nav_secondary ) :
					?>
					<div class="lnkup-dashboard__nav-secondary" aria-label="<?php esc_attr_e( 'Help', 'lnkup-theme' ); ?>">
						<?php foreach ( $nav_secondary as $item ) : ?>
							<?php lnkup_dashboard_render_nav_link( $item, $role, $active_slug, $active_view ); ?>
						<?php endforeach; ?>
					</div>
					<?php
				endif;
				if ( $nav_more ) :
					$nav_more_open = false;
					foreach ( $nav_more as $more_item ) {
						if ( lnkup_dashboard_is_nav_item_active( $more_item, $role, $active_slug, $active_view ) ) {
							$nav_more_open = true;
							break;
						}
					}
					?>
					<details class="lnkup-dashboard__nav-more"<?php echo $nav_more_open ? ' open' : ''; ?>>
						<summary class="lnkup-dashboard__nav-more-summary">
							<span class="lnkup-dashboard__nav-icon" aria-hidden="true"><?php echo lnkup_dashboard_nav_icon( 'list' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
							<span class="lnkup-dashboard__nav-text"><?php esc_html_e( 'More', 'lnkup-theme' ); ?></span>
							<span class="lnkup-dashboard__nav-more-chevron" aria-hidden="true"><?php echo lnkup_dashboard_chevron_icon(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						</summary>
						<div class="lnkup-dashboard__nav-more-items">
							<?php foreach ( $nav_more as $item ) : ?>
								<?php lnkup_dashboard_render_nav_link( $item, $role, $active_slug, $active_view ); ?>
							<?php endforeach; ?>
						</div>
					</details>
				<?php endif; ?>

				</nav>

			</div>



			<?php if ( $card ) : ?>

				<div class="lnkup-dashboard__sidebar-footer">

					<a href="<?php echo esc_url( $profile_url ); ?>" class="lnkup-dashboard__profile">

						<span class="lnkup-dashboard__user-avatar"><?php if ( ! empty( $card['logo'] ) ) : ?><img src="<?php echo esc_url( $card['logo'] ); ?>" alt="" /><?php else : ?><?php echo esc_html( $card['initials'] ); ?><?php endif; ?></span>

						<span class="lnkup-dashboard__user-info">

							<span class="lnkup-dashboard__user-name"><?php echo esc_html( $card['business'] ); ?></span>

							<?php if ( $card['location'] ) : ?>

								<span class="lnkup-dashboard__user-role"><?php echo esc_html( $card['location'] ); ?></span>

							<?php else : ?>

								<span class="lnkup-dashboard__user-role"><?php echo esc_html( $card['role'] ); ?></span>

							<?php endif; ?>

						</span>

					</a>

				</div>

			<?php endif; ?>

		</aside>

		<div class="lnkup-dashboard__main">

			<div class="lnkup-dashboard__content">

				<div class="lnkup-dashboard__content-inner">

				<p class="lnkup-dashboard__title" id="lnkup-dashboard-shell-title" data-lnkup-role="<?php echo esc_attr( $role ); ?>"><?php echo esc_html( $title ); ?></p>

	<?php

}



/**

 * Render dashboard shell closing markup.

 */

function lnkup_dashboard_shell_close() {

	$role = $GLOBALS['lnkup_dashboard_shell_role'] ?? '';

	?>

				</div>

			</div>

		</div>

		<?php if ( 'vendor' === $role || 'organizer' === $role || 'connector' === $role ) : ?>

			<nav class="lnkup-dashboard__bottom-nav" id="lnkup-bottom-nav" aria-label="<?php esc_attr_e( 'Quick navigation', 'lnkup-theme' ); ?>">

				<?php

				if ( 'vendor' === $role ) {

					$dashboard        = lnkup_page_url( 'vendor-dashboard' );
					$marketplace_open = function_exists( 'lnkup_is_marketplace_open' ) && lnkup_is_marketplace_open();
					$lock_hint        = __( 'Opens on launch day', 'lnkup-theme' );

					$bottom = array(

						array( 'label' => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'nav_home' ) : __( 'Home', 'lnkup-theme' ), 'href' => $dashboard, 'icon' => 'home', 'view' => '' ),

						array(
							'label'       => __( 'Find', 'lnkup-theme' ),
							'href'        => lnkup_page_url( 'opportunities' ),
							'icon'        => 'search',
							'slug'        => 'opportunities',
							'locked'      => ! $marketplace_open,
							'locked_hint' => $lock_hint,
						),

						array(
							'label'       => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'nav_requests' ) : __( 'Requests', 'lnkup-theme' ),
							'href'        => add_query_arg( 'view', 'applications', $dashboard ),
							'icon'        => 'inbox',
							'view'        => 'applications',
							'locked'      => ! $marketplace_open,
							'locked_hint' => $lock_hint,
						),

						array( 'label' => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'nav_wallet' ) : __( 'Wallet', 'lnkup-theme' ), 'href' => add_query_arg( 'view', 'payments', $dashboard ), 'icon' => 'payment', 'view' => 'payments' ),

						array( 'label' => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'nav_profile' ) : __( 'Profile', 'lnkup-theme' ), 'href' => lnkup_page_url( 'vendor-profile' ), 'icon' => 'store', 'slug' => 'vendor-profile' ),

					);

				} elseif ( 'connector' === $role ) {

					$dashboard = lnkup_page_url( 'connector-dashboard' );

					$bottom = array(

						array( 'label' => __( 'Home', 'lnkup-theme' ), 'href' => add_query_arg( 'view', 'home', $dashboard ), 'icon' => 'home', 'view' => 'home' ),

						array( 'label' => __( 'Referrals', 'lnkup-theme' ), 'href' => add_query_arg( 'view', 'referrals', $dashboard ), 'icon' => 'users', 'view' => 'referrals' ),

						array( 'label' => __( 'Create', 'lnkup-theme' ), 'href' => add_query_arg( 'view', 'studio', $dashboard ), 'icon' => 'plus', 'view' => 'studio' ),

						array( 'label' => __( 'Leads', 'lnkup-theme' ), 'href' => add_query_arg( 'view', 'leads', $dashboard ), 'icon' => 'list', 'view' => 'leads' ),

						array( 'label' => __( 'Earnings', 'lnkup-theme' ), 'href' => add_query_arg( 'view', 'earnings', $dashboard ), 'icon' => 'payment', 'view' => 'earnings' ),

					);

					if ( class_exists( 'LNKup_Connector' ) ) {
						$allowed = array_keys( LNKup_Connector::dashboard_views( LNKup_Connector::get_by_user_id( get_current_user_id() ) ) );
						$bottom  = array_values( array_filter( $bottom, static function ( $item ) use ( $allowed ) {
							return in_array( $item['view'], $allowed, true );
						} ) );
						if ( in_array( 'studio', $allowed, true ) ) {
							$bottom = array_values( array_filter( $bottom, static function ( $item ) {
								return 'leads' !== $item['view'];
							} ) );
						}
					}

				} else {

					$dashboard = lnkup_page_url( 'organizer-dashboard' );

					$bottom = array(

						array( 'label' => __( 'Overview', 'lnkup-theme' ), 'href' => function_exists( 'lnkup_organizer_view_url' ) ? lnkup_organizer_view_url( 'home' ) : add_query_arg( 'view', 'home', $dashboard ), 'icon' => 'home', 'view' => 'home' ),

						array( 'label' => __( 'Events', 'lnkup-theme' ), 'href' => function_exists( 'lnkup_organizer_view_url' ) ? lnkup_organizer_view_url( 'events' ) : add_query_arg( 'view', 'events', $dashboard ), 'icon' => 'calendar', 'view' => 'events' ),

						array( 'label' => __( 'Requests', 'lnkup-theme' ), 'href' => function_exists( 'lnkup_organizer_view_url' ) ? lnkup_organizer_view_url( 'applications' ) : add_query_arg( 'view', 'applications', $dashboard ), 'icon' => 'users', 'view' => 'applications' ),

						array( 'label' => __( 'Money', 'lnkup-theme' ), 'href' => function_exists( 'lnkup_organizer_view_url' ) ? lnkup_organizer_view_url( 'financial' ) : add_query_arg( 'view', 'financial', $dashboard ), 'icon' => 'payment', 'view' => 'financial' ),

						array( 'label' => __( 'Vendors', 'lnkup-theme' ), 'href' => function_exists( 'lnkup_organizer_view_url' ) ? lnkup_organizer_view_url( 'vendors' ) : add_query_arg( 'view', 'vendors', $dashboard ), 'icon' => 'search', 'view' => 'vendors' ),

					);

				}

				$bottom_view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$bottom_slug = get_post_field( 'post_name', get_queried_object_id() );
				foreach ( $bottom as $item ) :
					$locked     = ! empty( $item['locked'] );
					$item_view  = $item['view'] ?? '';
					$is_current = false;
					if ( 'organizer' === $role ) {
						$current_view = function_exists( 'lnkup_organizer_dashboard_view' ) ? lnkup_organizer_dashboard_view( $bottom_view ) : ( $bottom_view ? $bottom_view : 'home' );
						$is_current   = $item_view === $current_view;
					} elseif ( 'vendor' === $role ) {
						if ( ! empty( $item['slug'] ) ) {
							$is_current = $bottom_slug === $item['slug'];
						} elseif ( $item_view ) {
							$is_current = 'vendor-dashboard' === $bottom_slug && $bottom_view === $item_view;
						} else {
							$is_current = 'vendor-dashboard' === $bottom_slug && ! $bottom_view;
						}
					} elseif ( $item_view ) {
						$is_current = $bottom_view === $item_view || ( ! $bottom_view && 'home' === $item_view );
					}
					$link_class = 'lnkup-dashboard__bottom-link' . ( $locked ? ' is-locked' : '' ) . ( $is_current ? ' is-active' : '' );
					$href       = $locked ? '#' : ( $item['href'] ?? '#' );
					if ( ! empty( $item['share'] ) ) :
						?>

					<button type="button" class="<?php echo esc_attr( $link_class ); ?>" data-connector-share data-testid="lnkup-connector-share-nav">

						<span class="lnkup-dashboard__bottom-icon" aria-hidden="true"><?php echo lnkup_dashboard_nav_icon( $item['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>

						<span class="lnkup-dashboard__bottom-text"><?php echo esc_html( $item['label'] ); ?></span>

					</button>

						<?php
						continue;
					endif;
					?>

					<a href="<?php echo esc_url( $href ); ?>" class="<?php echo esc_attr( $link_class ); ?>"<?php echo ! empty( $item['view'] ) ? ' data-view="' . esc_attr( $item['view'] ) . '"' : ''; ?><?php echo ! empty( $item['slug'] ) ? ' data-slug="' . esc_attr( $item['slug'] ) . '"' : ''; ?><?php echo ! empty( $item['hash'] ) && ! $locked ? ' data-hash="' . esc_attr( $item['hash'] ) . '"' : ''; ?><?php echo $locked ? ' aria-disabled="true" tabindex="-1"' : ''; ?><?php echo $locked && ! empty( $item['locked_hint'] ) ? ' title="' . esc_attr( $item['locked_hint'] ) . '"' : ''; ?>>

						<span class="lnkup-dashboard__bottom-icon" aria-hidden="true"><?php echo lnkup_dashboard_nav_icon( $item['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>

						<span class="lnkup-dashboard__bottom-text"><?php echo esc_html( $item['label'] ); ?></span>

					</a>

				<?php endforeach; ?>

			</nav>

		<?php endif; ?>

	</div>

	<?php

}


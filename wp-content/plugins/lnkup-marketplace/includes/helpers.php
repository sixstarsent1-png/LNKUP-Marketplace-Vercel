<?php
/**
 * Helper functions.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether the public marketplace (browse/apply) is open.
 */
function lnkup_is_marketplace_open() {
	$settings = lnkup_get_settings();
	if ( empty( $settings['platform_prelaunch_mode'] ) ) {
		return true;
	}
	$launch = isset( $settings['platform_launch_date'] ) ? (string) $settings['platform_launch_date'] : '';
	if ( '' === $launch ) {
		return true;
	}
	$tz_string = function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'UTC';
	try {
		$launch_dt = new DateTimeImmutable( $launch . ' 00:00:00', new DateTimeZone( $tz_string ) );
	} catch ( Exception $e ) {
		return true;
	}
	return time() >= $launch_dt->getTimestamp();
}

/**
 * Whether LNKup admins may preview the marketplace before public launch.
 */
function lnkup_prelaunch_admin_preview_enabled() {
	$settings = lnkup_get_settings();
	return ! empty( $settings['platform_prelaunch_admin_preview'] );
}

/**
 * Whether the current user may browse/apply (includes admin pre-launch preview).
 *
 * @param int $user_id User ID; defaults to current user.
 */
function lnkup_can_browse_marketplace( $user_id = 0 ) {
	if ( lnkup_is_marketplace_open() ) {
		return true;
	}
	if ( ! lnkup_prelaunch_admin_preview_enabled() ) {
		return false;
	}
	$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
	if ( ! $user_id ) {
		return false;
	}
	return LNKup_Capabilities::user_has_manage_all( $user_id );
}

/**
 * Whether the viewer is using admin pre-launch preview (not public launch).
 *
 * @param int $user_id User ID; defaults to current user.
 */
function lnkup_is_prelaunch_admin_preview( $user_id = 0 ) {
	return ! lnkup_is_marketplace_open() && lnkup_can_browse_marketplace( $user_id );
}

/**
 * Whether a vendor can discover an opportunity in search/alerts.
 *
 * @param int $user_id        Vendor user ID.
 * @param int $opportunity_id Opportunity post ID.
 */
function lnkup_vendor_can_discover_opportunity( $user_id, $opportunity_id ) {
	$user_id        = absint( $user_id );
	$opportunity_id = absint( $opportunity_id );
	if ( ! $user_id || ! $opportunity_id ) {
		return false;
	}

	if ( ! lnkup_can_browse_marketplace( $user_id ) ) {
		return false;
	}

	$opp = get_post( $opportunity_id );
	if ( ! $opp || 'lnkup_opportunity' !== $opp->post_type || 'publish' !== $opp->post_status ) {
		return false;
	}

	if ( get_post_meta( $opportunity_id, '_lnkup_invite_only', true ) ) {
		if ( ! class_exists( 'LNKup_Vendor_Invites' ) || ! LNKup_Vendor_Invites::vendor_has_invite_for_opportunity( $user_id, $opportunity_id ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Whether a date-only application deadline is in the past, using the site timezone.
 * A deadline day stays open until the end of that day.
 *
 * @param string $deadline Deadline (Y-m-d or any strtotime-parsable date).
 */
function lnkup_deadline_passed( $deadline ) {
	$deadline = trim( (string) $deadline );
	if ( '' === $deadline ) {
		return false;
	}
	$ts = strtotime( $deadline );
	if ( false === $ts ) {
		return false;
	}
	return gmdate( 'Y-m-d', $ts ) < current_time( 'Y-m-d' );
}

/**
 * Branded sign-up page URL.
 */
function lnkup_registration_url() {
	return lnkup_page_url_helper( 'sign-up' );
}

/**
 * Current Find Events, event, or directory URL, with search args kept.
 */
function lnkup_current_public_return_url() {
	if ( is_admin() ) {
		return '';
	}
	$on_feed  = is_page( array( 'opportunities', 'find-events', 'vendors', 'organizers' ) );
	$on_event = is_singular( 'lnkup_event' );
	if ( ! $on_feed && ! $on_event ) {
		return '';
	}
	$url = get_permalink();
	if ( ! $url ) {
		return '';
	}
	if ( $on_event ) {
		return $url;
	}
	$allowed = array( 'apply', 'city', 'categories', 'date_from', 'date_to', 'q', 'state', 'organizer' );
	$args    = array();
	foreach ( $allowed as $key ) {
		if ( 'organizer' === $key && ! is_page( 'organizers' ) ) {
			continue;
		}
		if ( ! isset( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			continue;
		}
		$raw   = wp_unslash( (string) $_GET[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$value = 'organizer' === $key ? sanitize_title( $raw ) : sanitize_text_field( $raw );
		if ( '' === $value ) {
			continue;
		}
		$args[ $key ] = $value;
	}
	if ( $args ) {
		$url = add_query_arg( $args, $url );
	}
	return $url;
}

/**
 * Sign-up URL that returns to the current public page when that page is allowed.
 */
function lnkup_registration_url_with_return() {
	$base   = lnkup_registration_url();
	$return = lnkup_current_public_return_url();
	if ( ! $return ) {
		return $base;
	}
	return add_query_arg( 'redirect', rawurlencode( $return ), $base );
}

/**
 * Branded public login page.
 */
function lnkup_login_page_url() {
	return lnkup_page_url_helper( 'log-in' );
}

/**
 * Login URL that returns to the current public page when that page is allowed.
 */
function lnkup_login_url_with_return() {
	return lnkup_login_url_for( lnkup_current_public_return_url() );
}

/**
 * Branded login URL that returns to a specific page after sign-in.
 *
 * @param string $return Absolute or relative return URL.
 */
function lnkup_login_url_for( $return ) {
	$base = lnkup_login_page_url();
	$return = trim( (string) $return );
	if ( '' === $return ) {
		return $base;
	}
	return add_query_arg( 'redirect', rawurlencode( $return ), $base );
}

/**
 * Canonical organizer dashboard section from a view slug or legacy alias.
 *
 * @param string $view Raw view slug.
 * @return string
 */
function lnkup_organizer_dashboard_view( $view = null ) {
	if ( null === $view ) {
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'home'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}
	$view     = sanitize_key( (string) $view );
	$aliases  = array(
		''                   => 'home',
		'overview'           => 'home',
		'payments'           => 'financial',
		'import'             => 'create',
		'create-event'       => 'create',
		'create-opportunity' => 'events',
	);
	if ( isset( $aliases[ $view ] ) ) {
		$view = $aliases[ $view ];
	}
	$allowed = array( 'home', 'start', 'events', 'applications', 'financial', 'vendors', 'lists', 'invites', 'referrals', 'operations', 'intelligence', 'create', 'help', 'profile', 'team', 'tours', 'tasks' );
	return in_array( $view, $allowed, true ) ? $view : 'home';
}

/**
 * Organizer dashboard URL for one section.
 *
 * @param string               $view Section slug or legacy alias.
 * @param array<string, mixed> $args Extra query args.
 * @return string
 */
function lnkup_organizer_view_url( $view = 'home', $args = array() ) {
	$url  = add_query_arg( 'view', lnkup_organizer_dashboard_view( $view ), lnkup_page_url_helper( 'organizer-dashboard' ) );
	$args = is_array( $args ) ? $args : array();
	foreach ( $args as $key => $value ) {
		if ( null === $value || '' === $value ) {
			unset( $args[ $key ] );
		}
	}
	if ( $args ) {
		$url = add_query_arg( $args, $url );
	}
	return $url;
}

/**
 * Current organizer list page from lnkup_page.
 *
 * @return int
 */
function lnkup_organizer_dashboard_page() {
	$page = isset( $_GET['lnkup_page'] ) ? absint( wp_unslash( $_GET['lnkup_page'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	return max( 1, $page );
}

/**
 * Count posts owned by an organizer. Totals are not limited to the current page.
 *
 * @param string               $post_type  Post type.
 * @param int                  $user_id    Organizer user ID.
 * @param array<int, array>    $meta_query Extra meta query clauses.
 * @return int
 */
function lnkup_count_posts_for_organizer( $post_type, $user_id, $meta_query = array() ) {
	$meta_query   = is_array( $meta_query ) ? $meta_query : array();
	$meta_query[] = array(
		'key'   => '_lnkup_organizer_user_id',
		'value' => (string) (int) $user_id,
	);
	$query = new WP_Query(
		array(
			'post_type'              => $post_type,
			'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page'         => 1,
			'fields'                 => 'ids',
			'no_found_rows'          => false,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'meta_query'             => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		)
	);
	$found = (int) $query->found_posts;
	wp_reset_postdata();
	return $found;
}

/**
 * Sum paid booth fees for every payment the organizer owns.
 *
 * @param int $user_id Organizer user ID.
 * @return float
 */
function lnkup_organizer_collected_booth_total( $user_id ) {
	$ids = get_posts(
		array(
			'post_type'              => 'lnkup_payment',
			'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => '_lnkup_organizer_user_id',
					'value' => (string) (int) $user_id,
				),
				array(
					'key'   => '_lnkup_payment_status',
					'value' => 'paid',
				),
			),
		)
	);
	$sum = 0.0;
	foreach ( $ids as $payment_id ) {
		$sum += class_exists( 'LNKup_Financial' ) ? (float) LNKup_Financial::payment_booth_amount( (int) $payment_id ) : 0.0;
	}
	return $sum;
}

/**
 * Previous / next links for an organizer dashboard list.
 *
 * @param int                  $total    Total records.
 * @param int                  $per_page Page size.
 * @param string               $view     Section slug.
 * @param array<string, mixed> $args     Query args to keep.
 * @return string
 */
function lnkup_organizer_pagination_nav( $total, $per_page, $view, $args = array() ) {
	$per_page = max( 1, (int) $per_page );
	$pages    = (int) ceil( max( 0, (int) $total ) / $per_page );
	$page     = lnkup_organizer_dashboard_page();
	if ( $pages <= 1 ) {
		return '';
	}
	$args = is_array( $args ) ? $args : array();
	unset( $args['lnkup_page'] );
	$html = '<nav class="lnkup-dash-pagination" aria-label="' . esc_attr__( 'Pages', 'lnkup-marketplace' ) . '">';
	if ( $page > 1 ) {
		$html .= '<a class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" href="' . esc_url( lnkup_organizer_view_url( $view, array_merge( $args, array( 'lnkup_page' => $page - 1 ) ) ) ) . '">' . esc_html__( 'Previous', 'lnkup-marketplace' ) . '</a>';
	}
	$html .= '<span class="lnkup-muted">' . esc_html( sprintf( /* translators: 1: current page, 2: total pages */ __( 'Page %1$d of %2$d', 'lnkup-marketplace' ), $page, $pages ) ) . '</span>';
	if ( $page < $pages ) {
		$html .= '<a class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" href="' . esc_url( lnkup_organizer_view_url( $view, array_merge( $args, array( 'lnkup_page' => $page + 1 ) ) ) ) . '">' . esc_html__( 'Next', 'lnkup-marketplace' ) . '</a>';
	}
	$html .= '</nav>';
	return $html;
}

/**
 * Human-readable platform launch date.
 */
function lnkup_get_launch_date_label() {
	$settings = lnkup_get_settings();
	$launch   = isset( $settings['platform_launch_date'] ) ? (string) $settings['platform_launch_date'] : '';
	if ( '' === $launch ) {
		return '';
	}
	$ts = strtotime( $launch . ' 12:00:00' );
	if ( ! $ts ) {
		return $launch;
	}
	return date_i18n( 'F j, Y', $ts );
}

/**
 * ISO launch datetime for countdown scripts.
 */
function lnkup_get_launch_datetime_iso() {
	$settings = lnkup_get_settings();
	$launch   = isset( $settings['platform_launch_date'] ) ? (string) $settings['platform_launch_date'] : '';
	if ( '' === $launch ) {
		return '';
	}
	$tz_string = function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'UTC';
	try {
		$launch_dt = new DateTimeImmutable( $launch . ' 00:00:00', new DateTimeZone( $tz_string ) );
	} catch ( Exception $e ) {
		return '';
	}
	return $launch_dt->format( DATE_ATOM );
}

/**
 * App store release timezone (default America/New_York).
 *
 * @return string
 */
function lnkup_get_app_release_timezone() {
	$settings = lnkup_get_settings();
	$tz       = isset( $settings['app_release_tz'] ) ? trim( (string) $settings['app_release_tz'] ) : '';
	if ( '' === $tz ) {
		$tz = 'America/New_York';
	}
	try {
		new DateTimeZone( $tz );
	} catch ( Exception $e ) {
		return 'America/New_York';
	}
	return $tz;
}

/**
 * Mobile app public release datetime.
 *
 * @return DateTimeImmutable|null
 */
function lnkup_get_app_release_datetime() {
	$settings = lnkup_get_settings();
	$raw      = isset( $settings['app_release_datetime'] ) ? trim( (string) $settings['app_release_datetime'] ) : '';
	if ( '' === $raw ) {
		$raw = '2026-08-16 12:00:00';
	}
	// Accept HTML datetime-local (T separator) or "Y-m-d H:i:s".
	$raw = str_replace( 'T', ' ', $raw );
	if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) {
		$raw .= ' 12:00:00';
	}
	try {
		return new DateTimeImmutable( $raw, new DateTimeZone( lnkup_get_app_release_timezone() ) );
	} catch ( Exception $e ) {
		try {
			return new DateTimeImmutable( '2026-08-16 12:00:00', new DateTimeZone( 'America/New_York' ) );
		} catch ( Exception $e2 ) {
			return null;
		}
	}
}

/**
 * Whether the mobile app public release moment has passed.
 */
function lnkup_is_app_released() {
	$dt = lnkup_get_app_release_datetime();
	if ( ! $dt ) {
		return true;
	}
	return time() >= $dt->getTimestamp();
}

/**
 * Human-readable app release label (e.g. August 16, 2026 · 12:00 PM EST).
 *
 * @return string
 */
function lnkup_get_app_release_label() {
	$dt = lnkup_get_app_release_datetime();
	if ( ! $dt ) {
		return '';
	}
	$tz_abbr = $dt->format( 'T' );
	$label   = $dt->format( 'F j, Y' ) . ' · ' . $dt->format( 'g:i A' ) . ' ' . $tz_abbr;
	/**
	 * Filter the public app release label.
	 *
	 * @param string            $label Formatted label.
	 * @param DateTimeImmutable $dt    Release datetime.
	 */
	return (string) apply_filters( 'lnkup_app_release_label', $label, $dt );
}

/**
 * Whether an opportunity row passes homepage curation rules.
 *
 * @param array $opp Opportunity row.
 */
function lnkup_passes_homepage_curation( $opp ) {
	if ( ! is_array( $opp ) ) {
		return false;
	}
	$city = trim( (string) ( $opp['city'] ?? '' ) );
	$state = trim( (string) ( $opp['state'] ?? '' ) );
	if ( '' === $city || '' === $state ) {
		return false;
	}
	if ( 'Location TBA' === $city ) {
		return false;
	}
	$fee = (float) ( $opp['vendor_fee'] ?? 0 );
	if ( $fee > 0 && $fee < 20 ) {
		return false;
	}
	return true;
}

/**
 * Configured community goals for pre-launch marketing.
 *
 * @return array{vendors:int,organizers:int}
 */
function lnkup_get_launch_goals() {
	$settings = lnkup_get_settings();
	return array(
		'vendors'    => max( 0, (int) ( $settings['launch_goal_vendors'] ?? 4000 ) ),
		'organizers' => max( 0, (int) ( $settings['launch_goal_organizers'] ?? 500 ) ),
	);
}

/**
 * Live marketplace totals for post-launch public display.
 *
 * @return array{vendors:int,organizers:int,cities:int,gmv:float}
 */
function lnkup_get_marketplace_public_stats() {
	global $wpdb;

	$vendor_counts = count_users( array( 'role' => 'lnkup_vendor' ) );
	$org_counts    = count_users( array( 'role' => 'lnkup_organizer' ) );

	$vendors    = isset( $vendor_counts['avail_roles']['lnkup_vendor'] ) ? (int) $vendor_counts['avail_roles']['lnkup_vendor'] : 0;
	$organizers = isset( $org_counts['avail_roles']['lnkup_organizer'] ) ? (int) $org_counts['avail_roles']['lnkup_organizer'] : 0;

	$cities = 0;
	if ( class_exists( 'LNKup_Marketplace_Index' ) ) {
		$table  = $wpdb->prefix . 'lnkup_marketplace_index';
		$cities = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT city) FROM {$table} WHERE city IS NOT NULL AND city != '' AND city != 'Location TBA'" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	$gmv = 0.0;
	if ( class_exists( 'LNKup_Financial_Tables' ) ) {
		$tx_table = LNKup_Financial_Tables::transactions_table();
		$gmv      = (float) $wpdb->get_var(
			"SELECT COALESCE(SUM(gross_amount), 0) FROM {$tx_table} WHERE status IN ('completed','paid','succeeded')" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}
	if ( $gmv <= 0 ) {
		$paid_payments = get_posts(
			array(
				'post_type'      => 'lnkup_payment',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => '_lnkup_payment_status',
						'value' => 'paid',
					),
				),
			)
		);
		foreach ( $paid_payments as $payment_id ) {
			$gmv += (float) get_post_meta( $payment_id, '_lnkup_amount', true );
		}
	}

	return array(
		'vendors'    => $vendors,
		'organizers' => $organizers,
		'cities'     => $cities,
		'gmv'        => $gmv,
	);
}

/**
 * Format currency.
 *
 * @param float $amount Amount.
 */
function lnkup_currency( $amount ) {
	return '$' . number_format( (float) $amount, 2 );
}

/**
 * Public price without trailing cents on a whole dollar amount.
 *
 * @param float $amount Amount.
 */
function lnkup_public_money( $amount ) {
	$amount = (float) $amount;
	if ( abs( $amount - round( $amount ) ) < 0.001 ) {
		return '$' . number_format( $amount, 0 );
	}
	return '$' . number_format( $amount, 2 );
}

/**
 * Asset version for cache-busting (filemtime, fallback LNKUP_VERSION).
 *
 * @param string $relative_path Path under plugin dir (e.g. public/js/financial.js).
 * @return string
 */
function lnkup_asset_version( $relative_path ) {
	$path = LNKUP_PLUGIN_DIR . ltrim( (string) $relative_path, '/\\' );
	if ( is_readable( $path ) ) {
		$mtime = filemtime( $path );
		if ( false !== $mtime ) {
			return (string) $mtime;
		}
	}
	return defined( 'LNKUP_VERSION' ) ? LNKUP_VERSION : '0';
}

/**
 * Get vendor profile for user.
 *
 * @param int $user_id User ID.
 */
function lnkup_get_vendor_profile_id( $user_id = 0 ) {
	$user_id = $user_id ?: get_current_user_id();
	$posts = get_posts(
		array(
			'post_type'      => 'lnkup_vendor_profile',
			'posts_per_page' => 1,
			'meta_key'       => '_lnkup_owner_user_id',
			'meta_value'     => $user_id,
			'fields'         => 'ids',
		)
	);
	return $posts[0] ?? 0;
}

/**
 * Get featured opportunities.
 *
 * @param int   $limit Limit.
 * @param array $args  Optional: curate_homepage (bool).
 */
function lnkup_get_featured_opportunities( $limit = 6, $args = array() ) {
	$curate = ! empty( $args['curate_homepage'] );
	$fetch  = max( $limit, $curate ? $limit * 4 : $limit );

	if ( lnkup_marketplace_phase_at_least( '1b' ) && class_exists( 'LNKup_Trending' ) ) {
		$items = LNKup_Trending::get_section( 'trending', $fetch );
		if ( $items ) {
			if ( $curate ) {
				$items = array_values( array_filter( $items, 'lnkup_passes_homepage_curation' ) );
			}
			return array_slice( $items, 0, $limit );
		}
	}
	$posts = get_posts(
		array(
			'post_type'      => 'lnkup_opportunity',
			'posts_per_page' => $fetch,
			'post_status'    => 'publish',
			'meta_query'     => array(
				array(
					'key'   => '_lnkup_opportunity_status',
					'value' => 'open',
				),
			),
		)
	);
	$result = array();
	foreach ( $posts as $post ) {
		$row = lnkup_format_opportunity( $post );
		if ( $curate && ! lnkup_passes_homepage_curation( $row ) ) {
			continue;
		}
		$result[] = $row;
		if ( count( $result ) >= $limit ) {
			break;
		}
	}
	return $result;
}

/**
 * Filter trending section items for homepage display.
 *
 * @param array $items Items.
 * @param int   $limit Limit.
 */
function lnkup_curate_trending_section( $items, $limit = 4 ) {
	if ( ! is_array( $items ) ) {
		return array();
	}
	$filtered = array_values( array_filter( $items, 'lnkup_passes_homepage_curation' ) );
	return array_slice( $filtered, 0, $limit );
}

/**
 * Format opportunity for display.
 *
 * @param WP_Post $opp Opportunity post.
 */
function lnkup_format_opportunity( $opp ) {
	$event_id = (int) get_post_meta( $opp->ID, '_lnkup_event_id', true );
	$event    = $event_id ? get_post( $event_id ) : null;
	$terms    = wp_get_post_terms( $opp->ID, 'lnkup_vendor_category', array( 'fields' => 'names' ) );

	return array(
		'id'           => $opp->ID,
		'name'         => $event ? $event->post_title : $opp->post_title,
		'city'         => $event ? get_post_meta( $event_id, '_lnkup_city', true ) : '',
		'state'        => $event ? get_post_meta( $event_id, '_lnkup_state', true ) : '',
		'date'         => $event ? get_post_meta( $event_id, '_lnkup_event_date', true ) : '',
		'attendance'   => $event ? (int) get_post_meta( $event_id, '_lnkup_expected_attendance', true ) : 0,
		'vendor_fee'   => (float) get_post_meta( $opp->ID, '_lnkup_vendor_fee', true ),
		'spots_remaining' => (int) get_post_meta( $opp->ID, '_lnkup_spots_remaining', true ),
		'total_spots'  => (int) get_post_meta( $opp->ID, '_lnkup_spots_total', true ),
		'categories'   => is_array( $terms ) ? $terms : array(),
		'deadline'     => get_post_meta( $opp->ID, '_lnkup_application_deadline', true ),
		'opportunity_id' => $opp->ID,
		'event_id'     => $event_id,
	);
}

/**
 * Parse gallery attachment IDs from meta (JSON array or comma list).
 *
 * @param mixed $raw Meta value.
 * @return int[]
 */
function lnkup_parse_gallery_ids( $raw ) {
	if ( is_array( $raw ) ) {
		return array_values( array_filter( array_map( 'absint', $raw ) ) );
	}
	if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
		return array();
	}
	$trimmed = trim( $raw );
	if ( '[' === $trimmed[0] ) {
		$decoded = json_decode( $trimmed, true );
		if ( is_array( $decoded ) ) {
			return array_values( array_filter( array_map( 'absint', $decoded ) ) );
		}
	}
	$parts = preg_split( '/[\s,]+/', $trimmed );
	return array_values( array_filter( array_map( 'absint', (array) $parts ) ) );
}

/**
 * Format gallery items for API/UI.
 *
 * @param int[] $ids Attachment IDs.
 * @return array<int, array{id:int,url:string,mime:string,type:string,thumb:string}>
 */
function lnkup_format_gallery_items( array $ids ) {
	$items = array();
	foreach ( array_slice( $ids, 0, 24 ) as $id ) {
		$id = absint( $id );
		if ( ! $id ) {
			continue;
		}
		$url = wp_get_attachment_url( $id );
		if ( ! $url ) {
			continue;
		}
		$mime  = (string) get_post_mime_type( $id );
		$type  = 0 === strpos( $mime, 'video/' ) ? 'video' : 'image';
		$thumb = 'image' === $type
			? (string) ( wp_get_attachment_image_url( $id, 'medium' ) ?: $url )
			: (string) ( wp_get_attachment_image_url( $id, 'medium' ) ?: $url );
		$items[] = array(
			'id'    => $id,
			'url'   => $url,
			'mime'  => $mime,
			'type'  => $type,
			'thumb' => $thumb,
		);
	}
	return $items;
}

/**
 * Image upload control used for logos, covers, and galleries. Uploads go through
 * lnkup_upload_profile_image (marketplace.js), not the WordPress media library.
 *
 * @param array $args {
 *     @type string $mode         single | gallery.
 *     @type string $label        Visible field label.
 *     @type string $button_label Upload button text.
 *     @type string $input_name   Hidden input name (single, or comma list for gallery).
 *     @type string $input_id     Hidden input ID.
 *     @type string $item_name    Gallery only: per-image hidden input name (e.g. gallery_ids[]) instead of a comma list.
 *     @type int[]  $ids          Current attachment IDs.
 *     @type string $alt          Alt text prefix for previews.
 *     @type int    $max          Gallery only: maximum images.
 *     @type string $testid       Optional data-testid.
 * }
 * @return string
 */
function lnkup_image_upload_field( array $args ) {
	$args = wp_parse_args(
		$args,
		array(
			'mode'         => 'single',
			'label'        => '',
			'button_label' => __( 'Upload image', 'lnkup-marketplace' ),
			'input_name'   => '',
			'input_id'     => '',
			'item_name'    => '',
			'ids'          => array(),
			'alt'          => '',
			'max'          => 12,
			'testid'       => '',
		)
	);
	$gallery  = 'gallery' === $args['mode'];
	$ids      = array_values( array_filter( array_map( 'absint', (array) $args['ids'] ) ) );
	$ids      = $gallery ? $ids : array_slice( $ids, 0, 1 );
	$label_id = 'lnkup-image-label-' . wp_unique_id();
	ob_start();
	?>
	<div class="lnkup-form-row lnkup-image-upload lnkup-image-upload--<?php echo esc_attr( $gallery ? 'gallery' : 'single' ); ?>" data-image-upload="<?php echo esc_attr( $gallery ? 'gallery' : 'single' ); ?>" data-image-alt="<?php echo esc_attr( (string) $args['alt'] ); ?>"<?php echo $args['item_name'] ? ' data-image-item-name="' . esc_attr( (string) $args['item_name'] ) . '"' : ''; ?> data-image-max="<?php echo esc_attr( (string) (int) $args['max'] ); ?>"<?php echo $args['testid'] ? ' data-testid="' . esc_attr( (string) $args['testid'] ) . '"' : ''; ?>>
		<p class="lnkup-image-upload__label" id="<?php echo esc_attr( $label_id ); ?>"><?php echo esc_html( (string) $args['label'] ); ?></p>
		<?php if ( $args['input_name'] ) : ?>
			<input type="hidden" name="<?php echo esc_attr( (string) $args['input_name'] ); ?>"<?php echo $args['input_id'] ? ' id="' . esc_attr( (string) $args['input_id'] ) . '"' : ''; ?> value="<?php echo esc_attr( $gallery ? implode( ',', $ids ) : (string) ( $ids[0] ?? 0 ) ); ?>" data-image-value />
		<?php elseif ( $args['item_name'] ) : ?>
			<input type="hidden" name="<?php echo esc_attr( (string) $args['item_name'] ); ?>" value="" />
		<?php endif; ?>
		<div class="lnkup-image-upload__preview" data-image-preview>
			<?php foreach ( $ids as $index => $id ) : ?>
				<?php echo lnkup_image_upload_item( $id, (string) $args['alt'], $index + 1, (string) $args['item_name'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php endforeach; ?>
		</div>
		<div class="lnkup-image-upload__actions">
			<button type="button" class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" data-image-upload-button aria-describedby="<?php echo esc_attr( $label_id ); ?>"><?php echo esc_html( (string) $args['button_label'] ); ?></button>
		</div>
		<p class="lnkup-image-upload__hint lnkup-type-caption"><?php echo esc_html( $gallery ? sprintf( /* translators: %d: max images */ __( 'JPG, PNG, WebP, or GIF, up to 5 MB each. Up to %d images.', 'lnkup-marketplace' ), (int) $args['max'] ) : __( 'JPG, PNG, WebP, or GIF, up to 5 MB.', 'lnkup-marketplace' ) ); ?></p>
		<p class="lnkup-image-upload__status" data-image-status role="status" aria-live="polite"></p>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * One preview tile inside lnkup_image_upload_field().
 *
 * @param int    $id        Attachment ID.
 * @param string $alt       Alt text prefix.
 * @param int    $position  1-based position for alt and remove labels.
 * @param string $item_name Optional per-item hidden input name.
 * @return string
 */
function lnkup_image_upload_item( $id, $alt, $position, $item_name = '' ) {
	$id       = absint( $id );
	$is_video = 0 === strpos( (string) get_post_mime_type( $id ), 'video/' );
	$url      = $is_video ? '' : (string) wp_get_attachment_image_url( $id, 'medium' );
	if ( ! $is_video && '' === $url ) {
		return '';
	}
	$alt_text = trim( $alt . ' ' . $position );
	ob_start();
	?>
	<figure class="lnkup-image-upload__item" data-image-id="<?php echo esc_attr( (string) $id ); ?>">
		<?php if ( $is_video ) : ?>
			<span class="lnkup-image-upload__video"><?php esc_html_e( 'Video', 'lnkup-marketplace' ); ?></span>
		<?php else : ?>
			<img src="<?php echo esc_url( $url ); ?>" alt="<?php echo esc_attr( $alt_text ); ?>" loading="lazy" />
		<?php endif; ?>
		<button type="button" class="lnkup-image-upload__remove" data-image-remove aria-label="<?php echo esc_attr( sprintf( /* translators: %s: image description */ __( 'Remove %s', 'lnkup-marketplace' ), $alt_text ) ); ?>">&times;</button>
		<?php if ( $item_name ) : ?>
			<input type="hidden" name="<?php echo esc_attr( $item_name ); ?>" value="<?php echo esc_attr( (string) $id ); ?>" />
		<?php endif; ?>
	</figure>
	<?php
	return (string) ob_get_clean();
}

/**
 * Save event/opportunity gallery IDs; set featured image from first image when missing.
 *
 * @param int   $post_id Post ID.
 * @param mixed $raw_ids IDs from request.
 * @return int[] Saved IDs.
 */
function lnkup_save_post_gallery( $post_id, $raw_ids ) {
	$post_id = absint( $post_id );
	if ( ! $post_id ) {
		return array();
	}
	if ( is_string( $raw_ids ) && '' !== $raw_ids && '[' !== $raw_ids[0] ) {
		$raw_ids = preg_split( '/[\s,]+/', $raw_ids );
	}
	$ids      = lnkup_parse_gallery_ids( $raw_ids );
	$existing = lnkup_parse_gallery_ids( get_post_meta( $post_id, '_lnkup_gallery_ids', true ) );
	$actor    = get_current_user_id();
	$scoped   = $actor && ! LNKup_Capabilities::user_has_manage_all( $actor );
	$ids      = array_values(
		array_filter(
			$ids,
			function ( $id ) use ( $existing, $actor, $scoped ) {
				$mime = (string) get_post_mime_type( $id );
				if ( ! $id || ( 0 !== strpos( $mime, 'image/' ) && 0 !== strpos( $mime, 'video/' ) ) ) {
					return false;
				}
				return ! $scoped || in_array( $id, $existing, true ) || (int) get_post_field( 'post_author', $id ) === $actor;
			}
		)
	);
	update_post_meta( $post_id, '_lnkup_gallery_ids', wp_json_encode( $ids ) );
	$featured = (int) get_post_meta( $post_id, '_lnkup_featured_image_id', true );
	if ( ! $featured && $ids ) {
		foreach ( $ids as $id ) {
			if ( 0 === strpos( (string) get_post_mime_type( $id ), 'image/' ) ) {
				update_post_meta( $post_id, '_lnkup_featured_image_id', $id );
				break;
			}
		}
	}
	return $ids;
}

/**
 * Gallery for an opportunity (falls back to linked event).
 *
 * @param int $opportunity_id Opportunity ID.
 * @param int $event_id       Event ID.
 * @return array<int, array{id:int,url:string,mime:string,type:string,thumb:string}>
 */
function lnkup_get_opportunity_gallery( $opportunity_id, $event_id = 0 ) {
	$ids = lnkup_parse_gallery_ids( get_post_meta( absint( $opportunity_id ), '_lnkup_gallery_ids', true ) );
	if ( ! $ids && $event_id ) {
		$ids = lnkup_parse_gallery_ids( get_post_meta( absint( $event_id ), '_lnkup_gallery_ids', true ) );
	}
	return lnkup_format_gallery_items( $ids );
}

/**
 * Map a vendor category label to a theme illustration filename.
 *
 * @param string $label Category name.
 * @return string Empty when unmatched.
 */
function lnkup_opportunity_illustration_from_category( $label ) {
	$key = strtolower( trim( (string) $label ) );
	$key = str_replace( '-', ' ', $key );
	$key = preg_replace( '/\s+vendor$/', '', $key );
	$key = preg_replace( '/\s+/', ' ', $key );
	$map = array(
		'food'            => 'cat-food.jpg',
		'food truck'      => 'cat-food-truck.jpg',
		'dessert'         => 'cat-desserts.jpg',
		'desserts'        => 'cat-desserts.jpg',
		'drink'           => 'cat-drinks.jpg',
		'drinks'          => 'cat-drinks.jpg',
		'beverage'        => 'cat-drinks.jpg',
		'retail'          => 'cat-retail.jpg',
		'clothing'        => 'cat-clothing.jpg',
		'clothing brand'  => 'cat-clothing.jpg',
		'beauty'          => 'cat-beauty.jpg',
		'jewelry'         => 'cat-jewelry.jpg',
		'art'             => 'cat-art.jpg',
		'photo booth'     => 'cat-photo-booth.jpg',
		'service'         => 'cat-services.jpg',
		'services'        => 'cat-services.jpg',
		'wellness'        => 'cat-wellness.jpg',
		'merchandise'     => 'cat-merchandise.jpg',
		'merch'           => 'cat-merchandise.jpg',
		'mobile business' => 'cat-booth.jpg',
	);
	return isset( $map[ $key ] ) ? $map[ $key ] : '';
}

/**
 * Pick theme illustration filename for an opportunity row.
 *
 * @param array $opp Opportunity data.
 */
function lnkup_opportunity_illustration_filename( $opp ) {
	$categories = isset( $opp['categories'] ) && is_array( $opp['categories'] ) ? $opp['categories'] : array();
	foreach ( $categories as $category ) {
		$file = lnkup_opportunity_illustration_from_category( $category );
		if ( '' !== $file ) {
			return $file;
		}
	}

	return 'cat-booth.jpg';
}

/**
 * Human alt for a Find Events card image. Never empty, never a filename.
 *
 * @param array $opp Opportunity or external-event data.
 */
function lnkup_opportunity_card_alt( $opp ) {
	$opp   = is_array( $opp ) ? $opp : array();
	$title = trim( (string) ( $opp['event_name'] ?? $opp['name'] ?? $opp['title'] ?? '' ) );
	if ( '' === $title ) {
		$title = __( 'Vendor event', 'lnkup-marketplace' );
	}
	$city = trim( (string) ( $opp['city'] ?? '' ) );
	if ( $city ) {
		return $title . ' in ' . $city;
	}
	return $title;
}

/**
 * Accept only real image URLs for cards; reject event page / Next.js image proxies.
 *
 * @param string $url Candidate image URL.
 */
function lnkup_sanitize_card_image_url( $url ) {
	$url = trim( (string) $url );
	if ( '' === $url ) {
		return '';
	}
	if ( class_exists( 'LNKup_External_Source_Eventbrite' ) ) {
		$remote = LNKup_External_Source_Eventbrite::sanitize_image_url( $url );
		if ( '' !== $remote ) {
			return $remote;
		}
	}
	$home = untrailingslashit( home_url( '/' ) );
	$is_local = ( 0 === strpos( $url, $home ) )
		|| ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) );
	if ( ! $is_local ) {
		return '';
	}
	if ( preg_match( '#\.(jpg|jpeg|png|gif|webp|avif)(\?|$)#i', $url ) ) {
		return $url;
	}
	if ( preg_match( '#/(uploads|images|media|assets)/#i', $url ) ) {
		return $url;
	}
	return '';
}

/**
 * Card image URL: uploaded/remote photo or category fallback JPG.
 *
 * @param array $opp Opportunity data.
 */
function lnkup_opportunity_illustration_url( $opp ) {
	$opp = is_array( $opp ) ? $opp : array();
	foreach ( array( 'featured_image', 'image_url', 'image' ) as $key ) {
		if ( empty( $opp[ $key ] ) ) {
			continue;
		}
		$clean = lnkup_sanitize_card_image_url( (string) $opp[ $key ] );
		if ( '' !== $clean ) {
			return $clean;
		}
	}
	return '';
}

/**
 * Theme category JPG used when a featured/remote image 404s.
 *
 * @param array $opp Opportunity or category-bearing row.
 */
function lnkup_opportunity_category_fallback_url( $opp = array() ) {
	$file = lnkup_opportunity_illustration_filename( is_array( $opp ) ? $opp : array() );
	$ver  = defined( 'LNKUP_THEME_VERSION' ) ? LNKUP_THEME_VERSION : LNKUP_VERSION;
	$url  = get_theme_file_uri( 'assets/images/events/' . $file );
	return add_query_arg( 'ver', $ver, $url );
}

/**
 * Card media img with category fallback on load error.
 *
 * @param string $src      Primary image URL.
 * @param string $alt      Alt text.
 * @param string $fallback Fallback URL (defaults to category JPG).
 */
function lnkup_event_card_img_html( $src, $alt, $fallback = '', $attachment_id = 0 ) {
	$src      = (string) $src;
	$fallback = (string) $fallback;
	if ( '' === $src ) {
		return '<div class="lnkup-event-card__placeholder" role="img" aria-label="' . esc_attr( $alt ) . '"><span>LNKup</span></div>';
	}
	$srcset = '';
	if ( $attachment_id && false !== strpos( $src, (string) wp_basename( (string) get_attached_file( (int) $attachment_id ) ) ) ) {
		$set = wp_get_attachment_image_srcset( (int) $attachment_id, 'medium_large' );
		if ( $set ) {
			$srcset = sprintf(
				' srcset="%1$s" sizes="%2$s"',
				esc_attr( $set ),
				esc_attr( '(max-width: 640px) 100vw, (max-width: 1100px) 50vw, 360px' )
			);
		}
	}
	lnkup_enqueue_card_image_fallback();
	return sprintf(
		'<img src="%1$s" alt="%2$s" width="1280" height="800" loading="lazy" decoding="async" data-fallback-src="%3$s"%4$s />',
		esc_url( $src ),
		esc_attr( $alt ),
		esc_url( $fallback ),
		$srcset
	);
}

/**
 * Delegated card image fallback (category JPG, then placeholder). Printed once per page
 * in the footer so cards work on pages that do not load the dashboard scripts.
 */
function lnkup_enqueue_card_image_fallback() {
	static $queued = false;
	if ( $queued ) {
		return;
	}
	$queued = true;
	add_action( 'wp_footer', 'lnkup_print_card_image_fallback', 5 );
}

/**
 * Print the delegated image fallback script.
 */
function lnkup_print_card_image_fallback() {
	$js = <<<'JS'
(function(){if(window.lnkupCardImageFallback){return;}window.lnkupCardImageFallback=function(img){if(!img||img.tagName!=='IMG'||!img.hasAttribute('data-fallback-src')){return;}var f=img.getAttribute('data-fallback-src');if(!img.dataset.tried&&f&&f!==img.currentSrc&&f!==img.src){img.dataset.tried='1';img.removeAttribute('srcset');img.src=f;return;}var p=document.createElement('div');p.className='lnkup-event-card__placeholder';p.setAttribute('role','img');p.setAttribute('aria-label',img.alt||'LNKup');p.innerHTML='<span>LNKup</span>';if(img.parentNode){img.parentNode.replaceChild(p,img);}};document.addEventListener('error',function(e){window.lnkupCardImageFallback(e.target);},true);document.querySelectorAll('img[data-fallback-src]').forEach(function(img){if(img.complete&&img.naturalWidth===0){window.lnkupCardImageFallback(img);}});})();
JS;
	wp_print_inline_script_tag( $js, array( 'id' => 'lnkup-card-image-fallback' ) );
}

/**
 * Vendor-facing label for an application status. Backend enums are unchanged;
 * this is the single place cards, dashboards and REST DTOs read display copy from.
 *
 * @param string $status Application status meta value.
 */
function lnkup_application_status_label( $status ) {
	$labels = array(
		'submitted'        => __( 'Submitted', 'lnkup-marketplace' ),
		'under_review'     => __( 'Under review', 'lnkup-marketplace' ),
		'info_requested'   => __( 'More info needed', 'lnkup-marketplace' ),
		'waitlisted'       => __( 'Waitlisted', 'lnkup-marketplace' ),
		'approved'         => __( 'Approved', 'lnkup-marketplace' ),
		'payment_required' => __( 'Payment required', 'lnkup-marketplace' ),
		'paid'             => __( 'Confirmed', 'lnkup-marketplace' ),
		'completed'        => __( 'Completed', 'lnkup-marketplace' ),
		'rejected'         => __( 'Declined', 'lnkup-marketplace' ),
		'cancelled'        => __( 'Withdrawn', 'lnkup-marketplace' ),
	);
	return $labels[ (string) $status ] ?? '';
}

/**
 * Card image for external (unclaimed) events: remote URL or themed illustration.
 *
 * @param array<string, mixed> $row External event DTO.
 */
function lnkup_external_event_illustration_url( $row ) {
	if ( ! empty( $row['image_url'] ) ) {
		$url = (string) $row['image_url'];
		if ( class_exists( 'LNKup_External_Source_Eventbrite' ) ) {
			$sanitized = LNKup_External_Source_Eventbrite::sanitize_image_url( $url );
			if ( '' !== $sanitized ) {
				return $sanitized;
			}
		} elseif ( str_starts_with( strtolower( $url ), 'https://' ) ) {
			return $url;
		}
	}
	return lnkup_opportunity_illustration_url(
		array(
			'name'             => $row['title'] ?? '',
			'event_name'       => $row['title'] ?? '',
			'city'             => $row['city'] ?? '',
			'categories'       => $row['recommended_categories'] ?? array(),
			'event_type_label' => $row['event_type_label'] ?? $row['event_type'] ?? '',
		)
	);
}

/**
 * Configured Next.js app base URL (no trailing slash).
 */
function lnkup_get_app_url() {
	$url = defined( 'LNKUP_APP_URL' ) ? LNKUP_APP_URL : '';
	if ( ! $url ) {
		$settings = function_exists( 'lnkup_get_settings' ) ? lnkup_get_settings() : array();
		$url      = isset( $settings['app_url'] ) ? (string) $settings['app_url'] : '';
	}
	return untrailingslashit( esc_url_raw( $url ) );
}

/**
 * Allowed hostnames for app return URLs.
 *
 * @return string[]
 */
function lnkup_app_url_allowed_hosts() {
	$hosts  = array();
	$suffix = lnkup_get_app_url();
	if ( $suffix ) {
		$host = wp_parse_url( $suffix, PHP_URL_HOST );
		if ( $host ) {
			$hosts[] = strtolower( (string) $host );
		}
	}
	/**
	 * Filter extra allowed app hosts (e.g. Vercel preview).
	 *
	 * @param string[] $hosts Hostnames.
	 */
	$extra = apply_filters( 'lnkup_app_url_allowed_hosts', array() );
	foreach ( (array) $extra as $host ) {
		$host = strtolower( sanitize_text_field( (string) $host ) );
		if ( $host ) {
			$hosts[] = $host;
		}
	}
	return array_values( array_unique( array_filter( $hosts ) ) );
}

/**
 * Resolve app base URL for checkout return (allowlist enforced).
 *
 * @param string $requested URL from BFF.
 */
function lnkup_resolve_app_base_url( $requested = '' ) {
	$requested = untrailingslashit( esc_url_raw( (string) $requested ) );
	$allowed   = lnkup_app_url_allowed_hosts();
	if ( $requested ) {
		$host = wp_parse_url( $requested, PHP_URL_HOST );
		if ( $host && in_array( strtolower( (string) $host ), $allowed, true ) ) {
			return $requested;
		}
	}
	$configured = lnkup_get_app_url();
	return $configured ?: '';
}

/**
 * Build vendor payment return URL for the Next.js app.
 *
 * @param int    $payment_id     Payment post ID.
 * @param string $payment_state  success|cancelled|processing.
 * @param array  $args           return_to, app_base_url, application_id.
 */
function lnkup_vendor_app_payment_return_url( $payment_id, $payment_state, $args = array() ) {
	$app_base = lnkup_resolve_app_base_url( $args['app_base_url'] ?? '' );
	if ( ! $app_base ) {
		return '';
	}
	$application_id = absint( $args['application_id'] ?? get_post_meta( $payment_id, '_lnkup_application_id', true ) );
	$query          = array(
		'payment' => sanitize_key( $payment_state ),
	);
	if ( $application_id ) {
		$query['applicationId'] = $application_id;
	}
	return $app_base . '/vendor/requests?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
}

/**
 * Stripe return URL for the native app: an https bounce that 302s to lnkup://stripe-redirect.
 *
 * @param int    $payment_id    Payment post ID.
 * @param string $payment_state success|cancelled.
 * @param array  $args          application_id.
 */
function lnkup_native_payment_return_url( $payment_id, $payment_state, $args = array() ) {
	$application_id = absint( $args['application_id'] ?? get_post_meta( $payment_id, '_lnkup_application_id', true ) );
	return add_query_arg(
		array_filter(
			array(
				'payment'        => sanitize_key( $payment_state ),
				'application_id' => $application_id,
			)
		),
		rest_url( 'lnkup/v1/financial/checkout/native-return' )
	);
}

/**
 * Page URL helper (works without theme).
 *
 * @param string $slug Page slug.
 */
function lnkup_page_url_helper( $slug ) {
	$pages = get_option( 'lnkup_pages_created', array() );
	if ( ! empty( $pages[ $slug ] ) ) {
		return get_permalink( $pages[ $slug ] );
	}
	$page = get_page_by_path( $slug );
	return $page ? get_permalink( $page ) : home_url( '/' . $slug . '/' );
}

/**
 * Always 301 /find-events/ to the published opportunities page.
 * A leftover find-events page (draft, trash, or broken template) must not win.
 */
function lnkup_redirect_find_events_alias() {
	if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}
	if ( 'find-events' !== lnkup_current_request_slug() ) {
		return;
	}
	$target_page = get_page_by_path( 'opportunities' );
	if ( ! $target_page || 'publish' !== $target_page->post_status ) {
		$target = lnkup_page_url_helper( 'opportunities' );
	} else {
		$target = get_permalink( $target_page );
	}
	if ( ! $target ) {
		return;
	}
	if ( ! empty( $_GET ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$target = add_query_arg( wp_unslash( $_GET ), $target ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}
	wp_safe_redirect( $target, 301 );
	exit;
}

/**
 * Current front-end path slug, even when $wp->request is empty.
 */
function lnkup_current_request_slug() {
	global $wp;
	$candidates = array();
	if ( isset( $wp->request ) ) {
		$candidates[] = trim( (string) $wp->request, '/' );
	}
	if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
		$path = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );
		$home = untrailingslashit( (string) ( wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ?: '' ) );
		if ( $home && 0 === strpos( $path, $home ) ) {
			$path = substr( $path, strlen( $home ) );
		}
		$candidates[] = trim( (string) $path, '/' );
	}
	foreach ( $candidates as $candidate ) {
		if ( '' === $candidate ) {
			continue;
		}
		$last = basename( $candidate );
		if ( 'find-events' === $candidate || 'find-events' === $last ) {
			return 'find-events';
		}
	}
	foreach ( $candidates as $candidate ) {
		if ( '' !== $candidate ) {
			return $candidate;
		}
	}
	return '';
}

/**
 * Dashboard breadcrumb trail.
 *
 * @param array $items Each item: label (required), href (optional for current page).
 */
function lnkup_dash_breadcrumb( array $items ) {
	if ( ! $items ) {
		return '';
	}
	ob_start();
	?>
	<nav class="lnkup-dash-breadcrumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'lnkup-marketplace' ); ?>">
		<ol class="lnkup-dash-breadcrumb__list">
			<?php foreach ( $items as $index => $item ) : ?>
				<?php
				$is_last = ( $index === count( $items ) - 1 );
				$label   = $item['label'] ?? '';
				$href    = $item['href'] ?? '';
				?>
				<li class="lnkup-dash-breadcrumb__item">
					<?php if ( ! $is_last && $href ) : ?>
						<a href="<?php echo esc_url( $href ); ?>"><?php echo esc_html( $label ); ?></a>
					<?php else : ?>
						<span aria-current="page"><?php echo esc_html( $label ); ?></span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ol>
	</nav>
	<?php
	return ob_get_clean();
}

/**
 * Dashboard sub-navigation tabs.
 *
 * @param array  $tabs    Tab definitions: id, label, href (optional).
 * @param string $current Active tab id.
 */
function lnkup_dash_subnav( array $tabs, $current = '' ) {
	if ( ! $tabs ) {
		return '';
	}
	ob_start();
	?>
	<nav class="lnkup-dash-subnav" role="tablist" aria-label="<?php esc_attr_e( 'Sections', 'lnkup-marketplace' ); ?>">
		<?php foreach ( $tabs as $tab ) : ?>
			<?php
			$id    = $tab['id'] ?? '';
			$label = $tab['label'] ?? '';
			$href  = $tab['href'] ?? '#' . $id;
			$active = $id === $current;
			?>
			<a href="<?php echo esc_url( $href ); ?>" class="lnkup-dash-subnav__link<?php echo $active ? ' is-active' : ''; ?>" role="tab"<?php echo $active ? ' aria-current="page"' : ''; ?> data-subnav="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></a>
		<?php endforeach; ?>
	</nav>
	<?php
	return ob_get_clean();
}

/**
 * Reusable empty state block.
 *
 * @param string $title       Heading.
 * @param string $description Body copy.
 * @param string $cta_label   Primary button label.
 * @param string $cta_href    Primary button URL.
 */
function lnkup_empty_state( $title, $description = '', $cta_label = '', $cta_href = '', $secondary_label = '', $secondary_href = '' ) {
	$compact = false;
	if ( is_array( $title ) ) {
		$args            = $title;
		$title           = (string) ( $args['title'] ?? '' );
		$description     = (string) ( $args['desc'] ?? $args['description'] ?? '' );
		$cta_label       = (string) ( $args['cta_label'] ?? $args['ctaLabel'] ?? '' );
		$cta_href        = (string) ( $args['cta_href'] ?? $args['ctaHref'] ?? '' );
		$secondary_label = (string) ( $args['secondary_cta_label'] ?? $args['secondaryCtaLabel'] ?? '' );
		$secondary_href  = (string) ( $args['secondary_cta_href'] ?? $args['secondaryCtaHref'] ?? '' );
		$compact         = ! empty( $args['compact'] );
	}
	ob_start();
	?>
	<div class="lnkup-empty-state<?php echo $compact ? ' lnkup-empty-state--compact' : ''; ?>">
		<div class="lnkup-empty-state__icon" aria-hidden="true"></div>
		<h3 class="lnkup-empty-state__title"><?php echo esc_html( $title ); ?></h3>
		<?php if ( $description ) : ?>
			<p class="lnkup-empty-state__desc"><?php echo esc_html( $description ); ?></p>
		<?php endif; ?>
		<?php if ( $cta_label && $cta_href ) : ?>
			<div class="lnkup-empty-state__actions">
				<a href="<?php echo esc_url( $cta_href ); ?>" class="lnkup-btn lnkup-btn--primary"><?php echo esc_html( $cta_label ); ?></a>
				<?php if ( $secondary_label && $secondary_href ) : ?>
					<a href="<?php echo esc_url( $secondary_href ); ?>" class="lnkup-btn lnkup-btn--ghost"><?php echo esc_html( $secondary_label ); ?></a>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * Dashboard page header. Every dashboard tab and detail view renders exactly one.
 *
 * HTML keys (actions, meta, title_after) must already be escaped by the caller.
 *
 * @param array $args {
 *     @type string $title       Page title (required). Rendered as the page's only h1.
 *     @type string $subtitle    One-line description under the title.
 *     @type string $eyebrow     Small label above the title.
 *     @type string $back_url    Optional back link URL.
 *     @type string $back_label  Back link text.
 *     @type string $title_after Escaped HTML placed inside the h1 after the text (status pill, switcher).
 *     @type string $meta        Escaped HTML for the meta row under the subtitle.
 *     @type string $actions     Escaped HTML for header buttons.
 *     @type string $class       Extra classes.
 *     @type string $testid      data-testid value.
 * }
 * @return string
 */
function lnkup_page_header( array $args ) {
	$title = (string) ( $args['title'] ?? '' );
	if ( '' === $title ) {
		return '';
	}
	$classes = trim( 'lnkup-page-header ' . (string) ( $args['class'] ?? '' ) );
	$back    = (string) ( $args['back_url'] ?? '' );
	ob_start();
	?>
	<header class="<?php echo esc_attr( $classes ); ?>"<?php echo ! empty( $args['testid'] ) ? ' data-testid="' . esc_attr( (string) $args['testid'] ) . '"' : ''; ?>>
		<div class="lnkup-page-header__main">
			<?php if ( $back ) : ?>
				<a class="lnkup-page-header__back" href="<?php echo esc_url( $back ); ?>"><span aria-hidden="true">&larr;</span> <?php echo esc_html( (string) ( $args['back_label'] ?? __( 'Back', 'lnkup-marketplace' ) ) ); ?></a>
			<?php endif; ?>
			<?php if ( ! empty( $args['eyebrow'] ) ) : ?>
				<p class="lnkup-page-header__eyebrow"><?php echo esc_html( (string) $args['eyebrow'] ); ?></p>
			<?php endif; ?>
			<h1 class="lnkup-page-header__title">
				<?php echo esc_html( $title ); ?>
				<?php echo (string) ( $args['title_after'] ?? '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</h1>
			<?php if ( ! empty( $args['subtitle'] ) ) : ?>
				<p class="lnkup-page-header__subtitle"><?php echo esc_html( (string) $args['subtitle'] ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $args['meta'] ) ) : ?>
				<div class="lnkup-page-header__meta"><?php echo (string) $args['meta']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<?php endif; ?>
		</div>
		<?php if ( ! empty( $args['actions'] ) ) : ?>
			<div class="lnkup-page-header__actions"><?php echo (string) $args['actions']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
		<?php endif; ?>
	</header>
	<?php
	return (string) ob_get_clean();
}

/**
 * Stat strip: a row of labelled numbers.
 *
 * @param array $items Each item: label, value. Optional key for data attributes.
 * @param array $args  Optional class, id, testid.
 * @return string
 */
function lnkup_stat_strip( array $items, array $args = array() ) {
	$items = array_values( array_filter( $items, 'is_array' ) );
	if ( ! $items ) {
		return '';
	}
	$count   = count( $items );
	$classes = 'lnkup-home-stats';
	if ( $count < 4 ) {
		$classes .= ' lnkup-home-stats--cols-' . $count;
	}
	if ( ! empty( $args['class'] ) ) {
		$classes .= ' ' . (string) $args['class'];
	}
	ob_start();
	?>
	<dl class="<?php echo esc_attr( $classes ); ?>"<?php echo ! empty( $args['id'] ) ? ' id="' . esc_attr( (string) $args['id'] ) . '"' : ''; ?><?php echo ! empty( $args['testid'] ) ? ' data-testid="' . esc_attr( (string) $args['testid'] ) . '"' : ''; ?>>
		<?php foreach ( $items as $item ) : ?>
			<div class="lnkup-home-stat<?php echo ! empty( $item['href'] ) ? ' lnkup-home-stat--link' : ''; ?>"<?php echo ! empty( $item['key'] ) ? ' data-stat="' . esc_attr( (string) $item['key'] ) . '"' : ''; ?>>
				<dt>
					<?php if ( ! empty( $item['href'] ) ) : ?>
						<a class="lnkup-home-stat__link" href="<?php echo esc_url( (string) $item['href'] ); ?>"><?php echo esc_html( (string) ( $item['label'] ?? '' ) ); ?></a>
					<?php else : ?>
						<?php echo esc_html( (string) ( $item['label'] ?? '' ) ); ?>
					<?php endif; ?>
				</dt>
				<dd><?php echo esc_html( (string) ( $item['value'] ?? '' ) ); ?></dd>
			</div>
		<?php endforeach; ?>
	</dl>
	<?php
	return (string) ob_get_clean();
}

/**
 * Tab bar for dashboard pages and in-page sections.
 *
 * Tabs with an href render as links (aria-current on the active one); tabs without render as
 * buttons so existing JS can switch panels.
 *
 * @param array  $tabs    Each tab: id, label, href (optional), badge (int, optional), badge_class, attrs (array of extra attributes).
 * @param string $current Active tab id.
 * @param array  $args    variant ('' | pills | underline), label (aria-label), class, id, testid.
 * @return string
 */
function lnkup_dash_tabs( array $tabs, $current = '', array $args = array() ) {
	if ( ! $tabs ) {
		return '';
	}
	$classes = 'lnkup-dash-tabs';
	if ( ! empty( $args['variant'] ) ) {
		$classes .= ' lnkup-dash-tabs--' . sanitize_html_class( (string) $args['variant'] );
	}
	if ( ! empty( $args['class'] ) ) {
		$classes .= ' ' . (string) $args['class'];
	}
	$label = (string) ( $args['label'] ?? __( 'Sections', 'lnkup-marketplace' ) );
	ob_start();
	?>
	<nav class="<?php echo esc_attr( $classes ); ?>" aria-label="<?php echo esc_attr( $label ); ?>"<?php echo ! empty( $args['id'] ) ? ' id="' . esc_attr( (string) $args['id'] ) . '"' : ''; ?><?php echo ! empty( $args['testid'] ) ? ' data-testid="' . esc_attr( (string) $args['testid'] ) . '"' : ''; ?>>
		<?php foreach ( $tabs as $tab ) : ?>
			<?php
			$id     = (string) ( $tab['id'] ?? '' );
			$active = '' !== $id && $id === (string) $current;
			$attrs  = '';
			foreach ( (array) ( $tab['attrs'] ?? array() ) as $attr => $value ) {
				$attrs .= ' ' . esc_attr( (string) $attr ) . '="' . esc_attr( (string) $value ) . '"';
			}
			$badge = isset( $tab['badge'] ) ? (int) $tab['badge'] : null;
			$inner = esc_html( (string) ( $tab['label'] ?? '' ) );
			if ( null !== $badge && $badge > 0 ) {
				$inner .= ' <span class="lnkup-dash-tabs__badge' . ( ! empty( $tab['badge_class'] ) ? ' ' . esc_attr( (string) $tab['badge_class'] ) : '' ) . '">' . esc_html( (string) $badge ) . '</span>';
			}
			$btn_class = 'lnkup-dash-tabs__btn' . ( $active ? ' is-active' : '' );
			?>
			<?php if ( ! empty( $tab['href'] ) ) : ?>
				<a class="<?php echo esc_attr( $btn_class ); ?>" href="<?php echo esc_url( (string) $tab['href'] ); ?>"<?php echo $active ? ' aria-current="page"' : ''; ?><?php echo $attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo $inner; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
			<?php else : ?>
				<button type="button" class="<?php echo esc_attr( $btn_class ); ?>" aria-pressed="<?php echo $active ? 'true' : 'false'; ?>"<?php echo $attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo $inner; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
			<?php endif; ?>
		<?php endforeach; ?>
	</nav>
	<?php
	return (string) ob_get_clean();
}

/**
 * Skeleton placeholder shown while a panel loads. JS replaces it with content, an empty state, or an error card.
 *
 * @param string $variant panel | list | stats | line.
 * @param array  $args    Optional id, class.
 * @return string
 */
function lnkup_loading_state( $variant = 'panel', array $args = array() ) {
	$variant = in_array( $variant, array( 'panel', 'list', 'stats', 'line' ), true ) ? $variant : 'panel';
	$classes = 'lnkup-loading-state lnkup-loading-state--' . $variant;
	if ( ! empty( $args['class'] ) ) {
		$classes .= ' ' . (string) $args['class'];
	}
	ob_start();
	?>
	<div class="<?php echo esc_attr( $classes ); ?>" role="status" aria-busy="true" aria-label="<?php esc_attr_e( 'Loading', 'lnkup-marketplace' ); ?>"<?php echo ! empty( $args['id'] ) ? ' id="' . esc_attr( (string) $args['id'] ) . '"' : ''; ?>>
		<?php if ( 'stats' === $variant ) : ?>
			<?php for ( $i = 0; $i < 4; $i++ ) : ?>
				<div class="lnkup-skeleton"></div>
			<?php endfor; ?>
		<?php elseif ( 'list' === $variant ) : ?>
			<?php for ( $i = 0; $i < 3; $i++ ) : ?>
				<div class="lnkup-skeleton lnkup-skeleton--card"></div>
			<?php endfor; ?>
		<?php elseif ( 'line' === $variant ) : ?>
			<div class="lnkup-skeleton lnkup-skeleton--line"></div>
		<?php else : ?>
			<div class="lnkup-skeleton lnkup-skeleton--title"></div>
			<div class="lnkup-skeleton lnkup-skeleton--line"></div>
			<div class="lnkup-skeleton lnkup-skeleton--line"></div>
			<div class="lnkup-skeleton lnkup-skeleton--short"></div>
		<?php endif; ?>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * Query applications for user.
 *
 * @param int    $user_id User ID.
 * @param string $role    vendor|organizer.
 */
function lnkup_get_applications_for_user( $user_id, $role = 'vendor' ) {
	$key = 'vendor' === $role ? '_lnkup_vendor_user_id' : '_lnkup_organizer_user_id';
	return get_posts(
		array(
			'post_type'      => 'lnkup_application',
			'posts_per_page' => -1,
			'meta_key'       => $key,
			'meta_value'     => $user_id,
			'orderby'        => 'date',
			'order'          => 'DESC',
		)
	);
}

/**
 * Get payments for user.
 *
 * @param int    $user_id User ID.
 * @param string $role    vendor|organizer.
 */
function lnkup_get_payments_for_user( $user_id, $role = 'vendor' ) {
	$key = 'vendor' === $role ? '_lnkup_vendor_user_id' : '_lnkup_organizer_user_id';
	return get_posts(
		array(
			'post_type'      => 'lnkup_payment',
			'posts_per_page' => -1,
			'meta_key'       => $key,
			'meta_value'     => $user_id,
		)
	);
}

/**
 * When money moved for a payment: paid date, else creation date (MySQL datetime).
 *
 * @param WP_Post $payment Payment post.
 */
function lnkup_payment_activity_date( $payment ) {
	$paid_at = (string) get_post_meta( $payment->ID, '_lnkup_paid_at', true );
	return '' !== $paid_at ? $paid_at : (string) $payment->post_date;
}

/**
 * Sort display rows newest money first (paid date, then creation date).
 *
 * @param array $rows Rows from lnkup_payment_display_rows().
 */
function lnkup_sort_payment_rows_by_activity( $rows ) {
	usort(
		$rows,
		static function ( $a, $b ) {
			return strcmp( lnkup_payment_activity_date( $b['payment'] ), lnkup_payment_activity_date( $a['payment'] ) );
		}
	);
	return $rows;
}

/**
 * One display row per booking (application). Installments add up. A fee line and a full charge
 * for the same booking keep the larger total. Separate bookings at the same event stay separate.
 *
 * @param array $payments Payment posts.
 * @return array<int, array{payment: WP_Post, amount: float}>
 */
function lnkup_payment_display_rows( $payments ) {
	$groups = array();
	foreach ( (array) $payments as $payment ) {
		if ( ! $payment instanceof WP_Post ) {
			continue;
		}
		$app_id         = (int) get_post_meta( $payment->ID, '_lnkup_application_id', true );
		$key            = $app_id > 0 ? 'app-' . $app_id : 'pay-' . $payment->ID;
		$amount         = (float) get_post_meta( $payment->ID, '_lnkup_total_amount', true );
		$is_installment = (int) get_post_meta( $payment->ID, '_lnkup_installment_id', true ) > 0;
		if ( ! isset( $groups[ $key ] ) ) {
			$groups[ $key ] = array(
				'payment'          => $payment,
				'installment_sum'  => 0.0,
				'standalone_total' => 0.0,
				'has_installment'  => false,
				'has_standalone'   => false,
			);
		}
		if ( strtotime( (string) $payment->post_date ) >= strtotime( (string) $groups[ $key ]['payment']->post_date ) ) {
			$groups[ $key ]['payment'] = $payment;
		}
		if ( $is_installment ) {
			$groups[ $key ]['has_installment']  = true;
			$groups[ $key ]['installment_sum'] += $amount;
		} else {
			$groups[ $key ]['has_standalone']   = true;
			$groups[ $key ]['standalone_total'] = max( $groups[ $key ]['standalone_total'], $amount );
		}
	}
	$rows = array();
	foreach ( $groups as $group ) {
		if ( $group['has_installment'] && ! $group['has_standalone'] ) {
			$amount = $group['installment_sum'];
		} elseif ( $group['has_installment'] ) {
			$amount = max( $group['installment_sum'], $group['standalone_total'] );
		} else {
			$amount = $group['standalone_total'];
		}
		$rows[] = array(
			'payment' => $group['payment'],
			'amount'  => round( (float) $amount, 2 ),
		);
	}
	return $rows;
}

/**
 * Parse marketplace filter args from request array.
 *
 * @param array $source Request source ($_GET or POST).
 */
function lnkup_parse_marketplace_filters( $source = null ) {
	$source = is_array( $source ) ? $source : $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	foreach ( array( 'market' => 'markets', 'event_type' => 'event_types', 'category' => 'categories' ) as $alias => $canonical ) {
		if ( empty( $source[ $canonical ] ) && ! empty( $source[ $alias ] ) ) {
			$source[ $canonical ] = $source[ $alias ];
		}
	}

	$array_keys = array( 'categories', 'event_types', 'markets', 'doc_required' );
	$args       = array();
	$string_keys = array(
		'q', 'city', 'state', 'date_preset', 'date_from', 'date_to',
		'attendance_bucket', 'fee_bucket', 'environment', 'sort',
	);
	foreach ( $string_keys as $key ) {
		if ( isset( $source[ $key ] ) && '' !== $source[ $key ] ) {
			$args[ $key ] = sanitize_text_field( wp_unslash( $source[ $key ] ) );
		}
	}
	if ( isset( $source['s'] ) && ! isset( $args['q'] ) ) {
		$args['q'] = sanitize_text_field( wp_unslash( $source['s'] ) );
	}
	foreach ( $array_keys as $key ) {
		if ( ! empty( $source[ $key ] ) ) {
			$args[ $key ] = array_filter( array_map( 'sanitize_text_field', explode( ',', wp_unslash( $source[ $key ] ) ) ) );
		}
	}
	$num_keys = array( 'attendance_min', 'attendance_max', 'fee_min', 'fee_max', 'page', 'per_page', 'radius', 'tour_id', 'organizer_id' );
	foreach ( $num_keys as $key ) {
		if ( isset( $source[ $key ] ) && '' !== $source[ $key ] ) {
			$args[ $key ] = in_array( $key, array( 'page', 'per_page', 'attendance_min', 'attendance_max', 'radius', 'tour_id', 'organizer_id' ), true )
				? absint( $source[ $key ] )
				: (float) $source[ $key ];
		}
	}
	$bool_keys = array(
		'fee_free', 'indoor', 'outdoor', 'power_available', 'water_available',
		'food_truck_allowed', 'verified_organizer', 'featured_organizer',
		'instant_approval', 'has_spots', 'show_closed', 'newly_added',
		'closing_soon', 'almost_full', 'applications_open', 'doc_none',
		'high_demand', 'include_external', 'preferred_markets', 'previous_organizers',
	);
	foreach ( $bool_keys as $key ) {
		if ( isset( $source[ $key ] ) && '' !== $source[ $key ] ) {
			$args[ $key ] = rest_sanitize_boolean( $source[ $key ] );
		}
	}
	if ( ! isset( $args['has_spots'] ) && ! isset( $source['show_closed'] ) ) {
		$args['has_spots'] = true;
	}
	if ( ! isset( $args['include_external'] ) ) {
		$args['include_external'] = false;
	}
	if ( ! empty( $args['q'] ) && empty( $args['sort'] ) ) {
		$args['sort'] = 'relevance';
	}
	return $args;
}

/**
 * Build active filter chip labels.
 *
 * @param array $args Filter args.
 */
function lnkup_build_active_filters( $args ) {
	$labels = array();
	$map    = array(
		'q'                 => __( 'Search', 'lnkup-marketplace' ),
		'city'              => __( 'City', 'lnkup-marketplace' ),
		'state'             => __( 'State', 'lnkup-marketplace' ),
		'date_preset'       => __( 'Date', 'lnkup-marketplace' ),
		'attendance_bucket' => __( 'Attendance', 'lnkup-marketplace' ),
		'fee_bucket'        => __( 'Fee', 'lnkup-marketplace' ),
		'environment'       => __( 'Environment', 'lnkup-marketplace' ),
		'sort'              => __( 'Sort', 'lnkup-marketplace' ),
	);
	foreach ( $map as $key => $label ) {
		if ( ! empty( $args[ $key ] ) ) {
			$display = 'q' === $key
				? sprintf( __( 'Search: %s', 'lnkup-marketplace' ), $args[ $key ] )
				: $label . ': ' . $args[ $key ];
			$labels[] = array( 'key' => $key, 'value' => $args[ $key ], 'label' => $display );
		}
	}
	foreach ( array( 'categories', 'event_types', 'markets' ) as $tax_key ) {
		foreach ( array_filter( (array) ( $args[ $tax_key ] ?? array() ) ) as $term ) {
			$labels[] = array( 'key' => $tax_key, 'value' => $term, 'label' => $term );
		}
	}
	$bool_labels = array(
		'fee_free'           => __( 'Free', 'lnkup-marketplace' ),
		'has_spots'          => __( 'Has spots', 'lnkup-marketplace' ),
		'verified_organizer' => __( 'Verified organizer', 'lnkup-marketplace' ),
		'closing_soon'       => __( 'Closing soon', 'lnkup-marketplace' ),
		'newly_added'        => __( 'Newly added', 'lnkup-marketplace' ),
		'show_closed'        => __( 'Show closed', 'lnkup-marketplace' ),
		'high_demand'        => __( 'High demand external', 'lnkup-marketplace' ),
	);
	foreach ( $bool_labels as $key => $label ) {
		if ( ! empty( $args[ $key ] ) ) {
			$labels[] = array( 'key' => $key, 'label' => $label );
		}
	}
	return $labels;
}

/**
 * Render pagination links for marketplace results.
 *
 * @param array $result Search result.
 * @param array $args   Current filters.
 */
function lnkup_render_marketplace_pagination( $result, $args = array() ) {
	$page  = max( 1, (int) ( $result['page'] ?? 1 ) );
	$pages = max( 1, (int) ( $result['pages'] ?? 1 ) );
	if ( $pages <= 1 ) {
		return '';
	}
	$base_args = array_filter( $args );
	unset( $base_args['page'] );
	$base_url = add_query_arg( $base_args, lnkup_page_url_helper( 'opportunities' ) );
	$html     = '<nav class="lnkup-mp-v2__pagination" aria-label="' . esc_attr__( 'Opportunities pagination', 'lnkup-marketplace' ) . '">';
	if ( $page > 1 ) {
		$html .= '<a class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" href="' . esc_url( add_query_arg( 'page', $page - 1, $base_url ) ) . '">' . esc_html__( 'Previous', 'lnkup-marketplace' ) . '</a>';
	}
	$html .= '<span class="lnkup-muted">' . esc_html( sprintf( __( 'Page %1$d of %2$d', 'lnkup-marketplace' ), $page, $pages ) ) . '</span>';
	if ( $page < $pages ) {
		$html .= '<a class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" href="' . esc_url( add_query_arg( 'page', $page + 1, $base_url ) ) . '">' . esc_html__( 'Next', 'lnkup-marketplace' ) . '</a>';
	}
	$html .= '</nav>';
	return $html;
}

/**
 * Default application deadline from event date.
 *
 * @param int $event_id Event post ID.
 */
function lnkup_default_application_deadline( $event_id ) {
	$event_date = get_post_meta( $event_id, '_lnkup_event_date', true );
	if ( $event_date && strtotime( $event_date ) ) {
		$event_ts  = strtotime( $event_date );
		$seven_out = strtotime( '+7 days' );
		if ( $event_ts <= $seven_out ) {
			return gmdate( 'Y-m-d', strtotime( '+48 hours' ) );
		}
		return gmdate( 'Y-m-d', strtotime( $event_date . ' -7 days' ) );
	}
	return gmdate( 'Y-m-d', strtotime( '+90 days' ) );
}

/**
 * Required document slugs for an opportunity.
 *
 * @param int $opportunity_id Opportunity ID.
 * @return array<int, string>
 */
function lnkup_get_opportunity_document_mode( $opportunity_id ) {
	$opportunity_id = absint( $opportunity_id );
	$mode           = (string) get_post_meta( $opportunity_id, '_lnkup_document_mode', true );
	$raw            = (string) get_post_meta( $opportunity_id, '_lnkup_required_documents', true );

	if ( in_array( $mode, array( 'none', 'platform_defaults', 'custom' ), true ) ) {
		return $mode;
	}
	if ( 'none' === $raw ) {
		return 'none';
	}
	if ( $raw ) {
		return 'custom';
	}
	return 'none';
}

/**
 * Required document slugs for an opportunity.
 *
 * @param int $opportunity_id Opportunity ID.
 * @return array<int, string>
 */
function lnkup_get_opportunity_required_documents( $opportunity_id ) {
	$opportunity_id = absint( $opportunity_id );
	$mode           = lnkup_get_opportunity_document_mode( $opportunity_id );
	$raw            = get_post_meta( $opportunity_id, '_lnkup_required_documents', true );
	$docs           = array();

	if ( 'none' === $mode || 'none' === $raw ) {
		return array();
	}

	if ( 'platform_defaults' === $mode ) {
		$settings = lnkup_get_settings();
		return array_values(
			array_unique(
				array_map( 'sanitize_text_field', (array) ( $settings['default_documents'] ?? array() ) )
			)
		);
	}

	if ( $raw ) {
		foreach ( explode( ',', (string) $raw ) as $slug ) {
			$slug = trim( $slug );
			if ( $slug && 'none' !== $slug ) {
				$docs[] = sanitize_key( $slug );
			}
		}
	}

	return array_values( array_unique( $docs ) );
}

/**
 * Render apply-to-opportunity form HTML for modal or REST.
 *
 * @param int $opportunity_id Opportunity ID.
 */
function lnkup_render_apply_form_html( $opportunity_id ) {
	$opportunity_id = absint( $opportunity_id );
	$opp            = get_post( $opportunity_id );
	if ( ! $opp || 'lnkup_opportunity' !== $opp->post_type ) {
		return '';
	}

	if ( class_exists( 'LNKup_Apply_With_Profile_View' ) ) {
		return LNKup_Apply_With_Profile_View::render( $opportunity_id );
	}

	return '';
}

/**
 * Filtered opportunities query wrapper.
 *
 * @param array $args Filter args.
 */
function lnkup_get_filtered_opportunities( $args = array() ) {
	$result = LNKup_Discovery::search( $args );
	$result = lnkup_merge_marketplace_feed( $result, $args );
	$result['active_filters'] = lnkup_build_active_filters( $args );
	$result['empty_state']    = 0 === (int) $result['total'] && empty( $result['has_external'] );
	$result['pagination']     = lnkup_render_marketplace_pagination( $result, $args );
	return $result;
}

/**
 * Merge official opportunities with external unclaimed events (page 1 interleave).
 *
 * @param array<string, mixed> $result Discovery search result.
 * @param array<string, mixed> $args   Filter args.
 * @return array<string, mixed>
 */
function lnkup_merge_marketplace_feed( $result, $args = array() ) {
	$page             = max( 1, (int) ( $args['page'] ?? 1 ) );
	$external_items   = array();
	$external_total   = 0;
	$include_external = class_exists( 'LNKup_External_Events' ) && LNKup_External_Events::should_include_in_feed( $args );

	if ( $include_external ) {
		$ext_args = array_merge( $args, array( 'per_page' => 50, 'page' => 1 ) );
		if ( ! empty( $args['sort'] ) && in_array( $args['sort'], array( 'high_demand', 'recently_requested' ), true ) ) {
			$ext_args['external_sort'] = $args['sort'];
		}
		$ext = LNKup_External_Events::search( $ext_args );
		$external_items = $ext['items'] ?? array();
		$external_total = (int) ( $ext['total'] ?? 0 );
	}

	$result['html']           = lnkup_render_mixed_feed_batch( $result['items'] ?? array(), $external_items, $page );
	$result['external_total'] = $external_total;
	$result['has_external']   = $include_external && $external_total > 0;
	if ( $result['has_external'] && 1 === $page ) {
		$result['total'] = (int) ( $result['total'] ?? 0 ) + min( 6, $external_total );
	}
	return $result;
}

/**
 * Render mixed opportunity + external event cards sorted by date on page 1.
 *
 * @param array<int, array<string, mixed>> $opps      Opportunity items.
 * @param array<int, array<string, mixed>> $externals External event items.
 * @param int                              $page      Current page.
 */
function lnkup_render_mixed_feed_batch( $opps, $externals, $page = 1 ) {
	if ( 1 !== (int) $page || empty( $externals ) ) {
		return lnkup_render_opportunity_cards_batch( $opps );
	}

	$merged = array();
	foreach ( lnkup_group_opportunities_by_event( $opps ) as $slots ) {
		$first    = $slots[0];
		$merged[] = array(
			'item_type' => 'event_group',
			'slots'     => $slots,
			'sort_date' => strtotime( (string) ( $first['date'] ?? $first['event_date'] ?? $first['deadline'] ?? '2999-12-31' ) ),
		);
	}
	foreach ( array_slice( (array) $externals, 0, 6 ) as $item ) {
		$merged[] = $item;
	}
	usort(
		$merged,
		static function ( $a, $b ) {
			return ( (int) ( $a['sort_date'] ?? 0 ) ) <=> ( (int) ( $b['sort_date'] ?? 0 ) );
		}
	);

	$html = '';
	foreach ( $merged as $item ) {
		if ( 'external_event' === ( $item['item_type'] ?? '' ) ) {
			$html .= lnkup_render_external_event_card( $item );
		} elseif ( 'event_group' === ( $item['item_type'] ?? '' ) ) {
			$html .= lnkup_render_event_group_card( $item['slots'] ?? array() );
		} else {
			$html .= lnkup_render_opportunity_card_v2( $item );
		}
	}
	return $html;
}

/**
 * Render a single external (unclaimed) event card.
 *
 * @param array<string, mixed> $row Public external event DTO.
 */
function lnkup_render_external_event_card( $row ) {
	$id = (int) ( $row['id'] ?? 0 );
	if ( ! $id ) {
		return '';
	}

	$user_id    = get_current_user_id();
	$has_interest = false;
	if ( $user_id && class_exists( 'LNKup_External_Events' ) ) {
		$has_interest = (bool) LNKup_External_Events::get_vendor_interest_status( $id, $user_id );
	}

	$img       = lnkup_external_event_illustration_url( $row );
	$img_alt   = lnkup_opportunity_card_alt(
		array(
			'title'            => $row['title'] ?? '',
			'categories'       => $row['recommended_categories'] ?? array(),
			'event_type_label' => $row['event_type_label'] ?? $row['event_type'] ?? '',
			'city'             => $row['city'] ?? '',
		)
	);
	$interest  = (int) ( $row['vendor_interest_count'] ?? 0 );
	$interest_label = $interest > 0
		? sprintf( lnkup_copy( 'external_event_interest_count' ), $interest )
		: '';

	$badge_labels = array(
		'high_demand'          => lnkup_copy( 'external_event_high_demand' ),
		'trending'             => lnkup_copy( 'external_event_trending' ),
		'recently_requested'   => lnkup_copy( 'external_event_recently_requested' ),
	);
	$badges = (array) ( $row['badges'] ?? array() );
	$breakdown = (array) ( $row['interest_breakdown'] ?? array() );
	$recs = (array) ( $row['recommended_categories'] ?? array() );
	$demand_score = (int) ( $row['demand_score'] ?? 0 );

	ob_start();
	?>
	<article class="lnkup-event-card lnkup-event-card--v2 lnkup-event-card--external<?php echo $demand_score >= 50 ? ' lnkup-event-card--external-hot' : ''; ?>" data-listing-type="external" data-external-event-id="<?php echo esc_attr( (string) $id ); ?>" data-has-interest="<?php echo $has_interest ? '1' : '0'; ?>" data-demand-score="<?php echo esc_attr( (string) $demand_score ); ?>" data-testid="lnkup-external-event-card">
		<div class="lnkup-event-card__media">
			<?php
			echo lnkup_event_card_img_html( $img, $img_alt, '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
			<div class="lnkup-event-card__badges-top">
				<span class="lnkup-badge lnkup-badge--accent"><?php echo esc_html( (string) ( $row['event_type_label'] ?? __( 'Event Opportunity', 'lnkup-marketplace' ) ) ); ?></span>
				<span class="lnkup-badge lnkup-badge--external"><?php echo esc_html( lnkup_copy( 'external_event_badge' ) ); ?></span>
				<?php foreach ( $badges as $badge_key ) : ?>
					<?php if ( ! empty( $badge_labels[ $badge_key ] ) ) : ?>
						<span class="lnkup-badge lnkup-badge--demand lnkup-badge--<?php echo esc_attr( $badge_key ); ?>"><?php echo esc_html( $badge_labels[ $badge_key ] ); ?></span>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>
		</div>
		<div class="lnkup-event-card__body">
			<?php if ( ! empty( $row['organizer_name'] ) ) : ?>
				<div class="lnkup-event-card__organizer">
					<span class="lnkup-event-card__avatar"><?php echo esc_html( substr( (string) $row['organizer_name'], 0, 1 ) ); ?></span>
					<span><?php echo esc_html( (string) $row['organizer_name'] ); ?></span>
				</div>
			<?php endif; ?>
			<p class="lnkup-event-group__trust is-external"><?php esc_html_e( 'Not bookable on LNKup', 'lnkup-marketplace' ); ?></p>
			<h3 class="lnkup-event-card__title"><?php echo esc_html( (string) ( $row['title'] ?? '' ) ); ?></h3>
			<div class="lnkup-event-card__meta lnkup-event-card__meta-grid">
				<?php if ( ! empty( $row['date_label'] ) ) : ?>
					<div class="lnkup-event-card__meta-item">
						<span class="lnkup-event-card__meta-label"><?php esc_html_e( 'Date', 'lnkup-marketplace' ); ?></span>
						<span><?php echo esc_html( (string) $row['date_label'] ); ?></span>
					</div>
				<?php endif; ?>
				<?php if ( ! empty( $row['location_label'] ) ) : ?>
					<div class="lnkup-event-card__meta-item">
						<span class="lnkup-event-card__meta-label"><?php esc_html_e( 'Location', 'lnkup-marketplace' ); ?></span>
						<span><?php echo esc_html( ( ! empty( $row['city'] ) || ! empty( $row['state'] ) ) ? lnkup_display_place( $row['city'] ?? '', $row['state'] ?? '' ) : (string) $row['location_label'] ); ?></span>
					</div>
				<?php endif; ?>
				<?php if ( ! empty( $row['venue_name'] ) ) : ?>
					<div class="lnkup-event-card__meta-item">
						<span class="lnkup-event-card__meta-label"><?php esc_html_e( 'Venue', 'lnkup-marketplace' ); ?></span>
						<span><?php echo esc_html( (string) $row['venue_name'] ); ?></span>
					</div>
				<?php endif; ?>
			</div>
			<?php if ( ! empty( $row['description_excerpt'] ) ) : ?>
				<p class="lnkup-event-card__excerpt"><?php echo esc_html( (string) $row['description_excerpt'] ); ?></p>
			<?php endif; ?>
			<p class="lnkup-event-card__disclaimer lnkup-muted"><?php echo esc_html( lnkup_copy( 'external_event_disclaimer' ) ); ?></p>
			<p class="lnkup-event-card__source lnkup-muted"><?php echo esc_html( lnkup_copy( 'external_event_source_label' ) ); ?></p>
			<?php if ( $interest_label ) : ?>
				<p class="lnkup-event-card__interest-count lnkup-muted"><?php echo esc_html( $interest_label ); ?></p>
			<?php endif; ?>
			<?php if ( $demand_score > 0 ) : ?>
				<p class="lnkup-event-card__demand-score" aria-label="<?php echo esc_attr( lnkup_copy( 'demand_score' ) ); ?>">
					<span class="lnkup-demand-ring" data-score="<?php echo esc_attr( (string) $demand_score ); ?>"><?php echo esc_html( (string) $demand_score ); ?></span>
					<span><?php echo esc_html( (string) ( $row['demand_label'] ?? lnkup_copy( 'demand_score' ) ) ); ?></span>
				</p>
			<?php endif; ?>
			<?php if ( ! empty( $breakdown ) ) : ?>
				<ul class="lnkup-event-card__demand-breakdown">
					<?php foreach ( $breakdown as $line ) : ?>
						<li><?php echo esc_html( sprintf( '%s (%d)', (string) ( $line['label'] ?? '' ), (int) ( $line['count'] ?? 0 ) ) ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( ! empty( $row['recent_activity_label'] ) ) : ?>
				<p class="lnkup-event-card__recent-activity lnkup-muted"><?php echo esc_html( (string) $row['recent_activity_label'] ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $recs ) ) : ?>
				<div class="lnkup-event-card__chips">
					<?php foreach ( array_slice( $recs, 0, 3 ) as $cat ) : ?>
						<span class="lnkup-badge lnkup-badge--secondary"><?php echo esc_html( lnkup_display_category_badge( (string) $cat ) ); ?></span>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<div class="lnkup-event-card__actions">
				<button type="button" class="lnkup-btn lnkup-btn--outline lnkup-btn--external-interest" data-external-event-id="<?php echo esc_attr( (string) $id ); ?>"<?php echo $has_interest ? ' disabled' : ''; ?>>
					<?php echo esc_html( $has_interest ? lnkup_copy( 'request_interest_existing' ) : lnkup_copy( 'request_vendor_opportunity' ) ); ?>
				</button>
			</div>
		</div>
	</article>
	<?php
	return ob_get_clean();
}

/**
 * Batch render external event cards.
 *
 * @param array<int, array<string, mixed>> $items External event DTOs.
 */
function lnkup_render_external_event_cards_batch( $items ) {
	$html = '';
	foreach ( (array) $items as $item ) {
		$html .= lnkup_render_external_event_card( $item );
	}
	return $html;
}

/**
 * Normalized opportunity card DTO with fallbacks.
 *
 * @param int        $opportunity_id Opportunity ID.
 * @param array|null $row            Optional index row.
 */
function lnkup_get_opportunity_card_data( $opportunity_id, $row = null ) {
	$opportunity_id = absint( $opportunity_id );
	if ( ! $row ) {
		$row = LNKup_Marketplace_Index::get_row( $opportunity_id );
		if ( ! $row ) {
			LNKup_Marketplace_Index::sync_opportunity( $opportunity_id );
			$row = LNKup_Marketplace_Index::get_row( $opportunity_id );
		}
	}
	if ( ! $row ) {
		return array();
	}

	$item = lnkup_format_index_row( $row, true );
	$opp_post = get_post( $opportunity_id );
	$item['opportunity_title'] = $opp_post ? $opp_post->post_title : '';
	$item['event_name']        = $row['title'] ?? $item['name'];
	$item['event_type']        = ! empty( $item['event_types'][0] ) ? $item['event_types'][0] : __( 'Event Opportunity', 'lnkup-marketplace' );
	$item['event_type_label']  = $item['event_type'];

	if ( empty( $item['city'] ) && empty( $item['state'] ) ) {
		$item['location_label'] = __( 'Location TBA', 'lnkup-marketplace' );
	} else {
		$item['location_label'] = trim( ( $item['city'] ?? '' ) . ( $item['state'] ? ', ' . $item['state'] : '' ) );
	}

	$item['attendance_label'] = ! empty( $item['attendance'] )
		? number_format_i18n( (int) $item['attendance'] )
		: __( 'Attendance TBA', 'lnkup-marketplace' );

	$item['fee_label'] = isset( $item['vendor_fee'] ) && $item['vendor_fee'] > 0
		? lnkup_currency( $item['vendor_fee'] )
		: __( 'Fee TBA', 'lnkup-marketplace' );

	$item['deadline_label'] = ! empty( $item['deadline'] )
		? date_i18n( 'M j, Y', strtotime( $item['deadline'] ) )
		: __( 'Rolling applications', 'lnkup-marketplace' );

	$item['venue_label'] = ! empty( $item['venue_name'] ) ? $item['venue_name'] : '';

	$env = array();
	if ( ! empty( $item['indoor'] ) ) {
		$env[] = __( 'Indoor', 'lnkup-marketplace' );
	}
	if ( ! empty( $item['outdoor'] ) ) {
		$env[] = __( 'Outdoor', 'lnkup-marketplace' );
	}
	if ( ! empty( $item['tent_allowed'] ) ) {
		$env[] = __( 'Tented', 'lnkup-marketplace' );
	}
	$item['environment_labels'] = $env;

	$doc_map = array(
		'certificate_of_insurance' => __( 'COI', 'lnkup-marketplace' ),
		'health_permit'            => __( 'Health Permit', 'lnkup-marketplace' ),
		'business_license'         => __( 'Business License', 'lnkup-marketplace' ),
		'w9'                       => __( 'W9', 'lnkup-marketplace' ),
	);
	$item['document_badges'] = array();
	if ( ! empty( $item['required_documents'] ) ) {
		foreach ( explode( ',', $item['required_documents'] ) as $slug ) {
			$slug = trim( $slug );
			if ( $slug ) {
				$item['document_badges'][] = $doc_map[ $slug ] ?? ucwords( str_replace( '_', ' ', $slug ) );
			}
		}
	}

	$deadline_passed = lnkup_deadline_passed( $item['deadline'] ?? '' );
	// Capacity 0 means unlimited (LNKup_Opportunity_Inventory::capacity()).
	$item['spots_unlimited'] = (int) ( $item['total_spots'] ?? 0 ) <= 0;
	$no_spots        = ! $item['spots_unlimited'] && (int) ( $item['spots_remaining'] ?? 0 ) <= 0;
	$item['is_open'] = ! $deadline_passed && ! $no_spots;

	$item['apply_url']    = add_query_arg( array( 'apply' => $opportunity_id ), lnkup_page_url_helper( 'opportunities' ) );
	$item['details_url']  = $item['apply_url'];
	$item['application_status'] = '';
	$user_id = get_current_user_id();
	if ( $user_id && LNKup_Roles::is_vendor( $user_id ) ) {
		$apps = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'posts_per_page' => 1,
				'meta_query'     => array(
					array( 'key' => '_lnkup_vendor_user_id', 'value' => $user_id ),
					array( 'key' => '_lnkup_opportunity_id', 'value' => $opportunity_id ),
				),
			)
		);
		if ( $apps ) {
			$item['application_status'] = get_post_meta( $apps[0]->ID, '_lnkup_application_status', true );
		}
	}
	$item['can_apply_again'] = lnkup_application_can_apply_again( (string) $item['application_status'], (int) $opportunity_id );

	return $item;
}

/**
 * Format index table row for API/cards.
 *
 * @param array $row     Index row.
 * @param bool  $compare Include comparison fields.
 */
function lnkup_format_index_row( $row, $compare = false ) {
	$categories  = json_decode( $row['categories'] ?? '[]', true ) ?: array();
	$event_types = json_decode( $row['event_types'] ?? '[]', true ) ?: array();
	$organizer   = ! empty( $row['organizer_user_id'] ) ? get_userdata( (int) $row['organizer_user_id'] ) : null;

	$item = array(
		'opportunity_id'    => (int) $row['opportunity_id'],
		'event_id'          => (int) $row['event_id'],
		'id'                => (int) $row['opportunity_id'],
		'name'              => $row['title'],
		'excerpt'           => isset( $row['excerpt'] ) ? (string) $row['excerpt'] : '',
		'description'       => isset( $row['excerpt'] ) ? (string) $row['excerpt'] : '',
		'city'              => $row['city'],
		'state'             => $row['state'],
		'venue_name'        => $row['venue_name'],
		'date'              => $row['event_date'],
		'attendance'        => (int) $row['attendance'],
		'vendor_fee'        => (float) $row['vendor_fee'],
		'spots_remaining'   => (int) $row['spots_remaining'],
		'total_spots'       => (int) $row['spots_total'],
		'categories'        => $categories,
		'event_types'       => $event_types,
		'deadline'          => $row['deadline'],
		'lat'               => $row['lat'] ? (float) $row['lat'] : null,
		'lng'               => $row['lng'] ? (float) $row['lng'] : null,
		'featured_image_id' => (int) ( $row['featured_image_id'] ?? 0 ),
		'featured_image'    => ! empty( $row['featured_image_id'] ) ? wp_get_attachment_image_url( (int) $row['featured_image_id'], 'medium_large' ) : '',
		'gallery'           => lnkup_get_opportunity_gallery( (int) $row['opportunity_id'], (int) ( $row['event_id'] ?? 0 ) ),
		'organizer_id'      => (int) $row['organizer_user_id'],
		'organizer_name'    => $organizer ? $organizer->display_name : '',
		'organizer_verified'=> (bool) ( $row['organizer_verified'] ?? false ),
		'organizer_rating'  => $row['organizer_rating'] ? (float) $row['organizer_rating'] : null,
		'instant_approval'  => (bool) ( $row['instant_approval'] ?? false ),
		'application_count' => (int) ( $row['application_count'] ?? 0 ),
		'view_count'        => (int) ( $row['view_count'] ?? 0 ),
		'trending_score'    => (float) ( $row['trending_score'] ?? 0 ),
		'badges'            => lnkup_get_opportunity_badges( $row ),
		'listing_status'    => class_exists( 'LNKup_Event_Authenticity' ) && ! empty( $row['event_id'] )
			? LNKup_Event_Authenticity::public_label( (int) $row['event_id'] )
			: null,
	);

	if ( $compare ) {
		$item['power_available']     = (bool) ( $row['power_available'] ?? false );
		$item['water_available']     = (bool) ( $row['water_available'] ?? false );
		$item['food_truck_allowed']  = (bool) ( $row['food_truck_allowed'] ?? false );
		$item['tent_allowed']        = (bool) ( $row['tent_allowed'] ?? false );
		$item['generator_allowed']   = (bool) ( $row['generator_allowed'] ?? false );
		$item['indoor']              = (bool) ( $row['indoor'] ?? false );
		$item['outdoor']             = (bool) ( $row['outdoor'] ?? false );
		$item['free_parking']        = (bool) ( $row['free_parking'] ?? false );
		$item['load_in_time']        = $row['load_in_time'] ?? '';
		$item['required_documents']  = $row['required_documents'] ?? '';

		$opp_post   = get_post( (int) $row['opportunity_id'] );
		$event_post = ! empty( $row['event_id'] ) ? get_post( (int) $row['event_id'] ) : null;
		$full_text  = '';
		if ( $opp_post && $opp_post->post_content ) {
			$full_text = (string) $opp_post->post_content;
		} elseif ( $event_post && $event_post->post_content ) {
			$full_text = (string) $event_post->post_content;
		}
		$full_text = trim( wp_strip_all_tags( $full_text ) );
		if ( $full_text ) {
			$item['description'] = $full_text;
			if ( empty( $item['excerpt'] ) ) {
				$item['excerpt'] = wp_trim_words( $full_text, 40 );
			}
		}
	}

	$item['featured_image']         = lnkup_opportunity_illustration_url( $item );
	$item['category_fallback_url']  = lnkup_opportunity_category_fallback_url( $item );
	$item['image_alt']              = lnkup_opportunity_card_alt( $item );
	$statuses                       = lnkup_current_vendor_application_statuses();
	$item['application_status']     = $statuses[ (int) $row['opportunity_id'] ] ?? '';
	$item['can_apply_again']        = lnkup_application_can_apply_again( $item['application_status'], (int) $row['opportunity_id'] );

	return $item;
}

/**
 * Whether the current vendor may apply again after a closed application (withdrawn, or declined and re-opened).
 *
 * @param string $status         Latest application status for the opportunity ('' when none).
 * @param int    $opportunity_id Opportunity ID.
 */
function lnkup_application_can_apply_again( $status, $opportunity_id ) {
	if ( 'cancelled' === $status ) {
		return true;
	}
	if ( 'rejected' !== $status || ! class_exists( 'LNKup_Applications' ) ) {
		return false;
	}
	$state = LNKup_Applications::reapply_state( (int) $opportunity_id, get_current_user_id() );
	return is_array( $state ) && 'allowed' === $state['reapply'];
}

/**
 * Current vendor's application status keyed by opportunity ID (one query per request).
 *
 * @return array<int, string>
 */
function lnkup_current_vendor_application_statuses() {
	static $cache = array();
	$user_id = get_current_user_id();
	if ( ! $user_id || ! class_exists( 'LNKup_Roles' ) || ! LNKup_Roles::is_vendor( $user_id ) ) {
		return array();
	}
	if ( isset( $cache[ $user_id ] ) ) {
		return $cache[ $user_id ];
	}
	$ids = get_posts(
		array(
			'post_type'      => 'lnkup_application',
			'post_status'    => 'any',
			'posts_per_page' => 500,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_key'       => '_lnkup_vendor_user_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => $user_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		)
	);
	update_meta_cache( 'post', $ids );
	$map = array();
	foreach ( $ids as $app_id ) {
		$opp_id = (int) get_post_meta( $app_id, '_lnkup_opportunity_id', true );
		if ( $opp_id && ! isset( $map[ $opp_id ] ) ) {
			$map[ $opp_id ] = (string) get_post_meta( $app_id, '_lnkup_application_status', true );
		}
	}
	$cache[ $user_id ] = $map;
	return $map;
}

/**
 * Compute display badges for opportunity.
 *
 * @param array $row Index row or formatted item.
 */
function lnkup_get_opportunity_badges( $row ) {
	$badges = array();
	$created = isset( $row['created_at'] ) ? strtotime( $row['created_at'] ) : 0;
	if ( $created && $created > strtotime( '-7 days' ) ) {
		$badges[] = 'recently_added';
	}
	if ( ! empty( $row['deadline'] ) ) {
		$days = ( strtotime( $row['deadline'] ) - time() ) / DAY_IN_SECONDS;
		if ( $days >= 0 && $days <= 7 ) {
			$badges[] = 'closing_soon';
		}
	}
	$spots_r = (int) ( $row['spots_remaining'] ?? 0 );
	$spots_t = (int) ( $row['spots_total'] ?? 1 );
	if ( $spots_t > 0 && ( $spots_r / $spots_t ) <= 0.1 && $spots_r > 0 ) {
		$badges[] = 'almost_full';
	}
	if ( ! empty( $row['trending_score'] ) && (float) $row['trending_score'] > 30 && (int) ( $row['application_count'] ?? 0 ) > 0 ) {
		$badges[] = 'trending';
	}
	if ( ! empty( $row['organizer_verified'] ) ) {
		$badges[] = 'verified';
	}
	if ( ! empty( $row['application_count'] ) && (int) $row['application_count'] > 5 ) {
		$badges[] = 'popular';
	}
	return $badges;
}

/**
 * Render v2 opportunity card.
 *
 * @param array $opp Opportunity data.
 */
function lnkup_render_opportunity_card_v2( $opp ) {
	if ( empty( $opp['event_type_label'] ) && ! empty( $opp['opportunity_id'] ) ) {
		$opp = lnkup_get_opportunity_card_data( (int) $opp['opportunity_id'], null );
	}
	if ( ! $opp ) {
		return '';
	}

	$user_id   = get_current_user_id();
	$favorited = $user_id && LNKup_Vendor_Personalization::is_favorite( $user_id, 'opportunities', $opp['opportunity_id'] );
	$apply_url = $opp['apply_url'] ?? add_query_arg( array( 'apply' => $opp['opportunity_id'] ), lnkup_page_url_helper( 'opportunities' ) );
	$event_id  = (int) ( $opp['event_id'] ?? 0 );
	$view_url  = $apply_url;
	if ( $event_id && class_exists( 'LNKup_Organizer_Onboarding' ) ) {
		$view_url = LNKup_Organizer_Onboarding::event_public_url( $event_id );
	}
	$match_chip = '';
	if ( ! empty( $opp['match']['band_label'] ) ) {
		$first_reason = (string) ( $opp['match']['reasons'][0] ?? '' );
		$match_chip   = '<span class="lnkup-growth-match-chip lnkup-match-chip--' . esc_attr( $opp['match']['band'] ) . '" data-testid="lnkup-match-chip">' . esc_html( $opp['match']['band_label'] ) . '</span>'
			. ( '' !== $first_reason ? '<span class="lnkup-match-reason lnkup-muted">' . esc_html( $first_reason ) . '</span>' : '' );
	} elseif ( $user_id && class_exists( 'LNKup_Match_Settings' ) && LNKup_Match_Settings::enabled( 'matching_vendor_discovery_enabled' ) ) {
		$cached = LNKup_Match_Service::cached_match( $user_id, (int) $opp['opportunity_id'] );
		if ( $cached ) {
			$match_chip = '<span class="lnkup-growth-match-chip lnkup-match-chip--' . esc_attr( $cached['band'] ) . '" data-testid="lnkup-match-chip">' . esc_html( $cached['band_label'] ) . '</span>';
		}
	} elseif ( $user_id && class_exists( 'LNKup_Growth_Match' ) && LNKup_Capabilities::can_view_growth( $user_id ) ) {
		$match = LNKup_Growth_Match::for_opportunity( $user_id, $opp['opportunity_id'] );
		if ( ! empty( $match['match_score'] ) ) {
			$match_chip = '<span class="lnkup-growth-match-chip">' . esc_html( $match['match_score'] ) . '% ' . esc_html( $match['match_label'] ) . '</span>';
		}
	}
	$event_name = $opp['event_name'] ?? $opp['name'];
	$name       = $opp['name_html'] ?? $event_name;
	$subtitle   = '';
	if ( ! empty( $opp['opportunity_title'] ) && $opp['opportunity_title'] !== $event_name ) {
		$subtitle = $opp['opportunity_title'];
	}
	$location = ( ! empty( $opp['city'] ) || ! empty( $opp['state'] ) )
		? lnkup_display_place( $opp['city'] ?? '', $opp['state'] ?? '' )
		: ( $opp['location_label'] ?? '' );
	if ( ! empty( $opp['date'] ) ) {
		$event_name = lnkup_display_event_title( $event_name, (string) $opp['date'] );
		if ( empty( $opp['name_html'] ) ) {
			$name = $event_name;
		}
	}
	$bookable = ! ( $event_id && class_exists( 'LNKup_Organizer_Onboarding' ) && LNKup_Organizer_Onboarding::event_is_unclaimed_external( $event_id ) );
	$img       = lnkup_opportunity_illustration_url( $opp );
	$img_alt   = lnkup_opportunity_card_alt( $opp );
	$badge_labels = array(
		'trending'       => __( 'Trending', 'lnkup-marketplace' ),
		'closing_soon'   => __( 'Closing Soon', 'lnkup-marketplace' ),
		'recently_added' => __( 'New', 'lnkup-marketplace' ),
		'verified'       => __( 'Verified', 'lnkup-marketplace' ),
		'popular'        => __( 'Popular', 'lnkup-marketplace' ),
		'almost_full'    => __( 'Almost Full', 'lnkup-marketplace' ),
	);
	$status       = (string) ( $opp['application_status'] ?? '' );
	$status_label = lnkup_application_status_label( $status );
	$is_closed = empty( $opp['is_open'] );
	$spots_left = (int) ( $opp['spots_remaining'] ?? 0 );
	$deadline_raw = (string) ( $opp['deadline'] ?? '' );
	$deadline_passed = '' !== $deadline_raw && $deadline_raw < current_time( 'Y-m-d' );
	$deadline_label = (string) ( $opp['deadline_label'] ?? '' );
	$rolling_deadline = '' === $deadline_label || false !== stripos( $deadline_label, 'rolling' );
	if ( $deadline_passed || ( $is_closed && $spots_left > 0 ) ) {
		$availability_line = __( 'Closed', 'lnkup-marketplace' );
	} elseif ( $spots_left < 1 ) {
		$availability_line = __( 'Full', 'lnkup-marketplace' );
	} elseif ( ! $rolling_deadline ) {
		$availability_line = sprintf(
			/* translators: %s: application deadline */
			__( 'Apply by %s', 'lnkup-marketplace' ),
			$deadline_label
		);
	} else {
		$availability_line = __( 'Open', 'lnkup-marketplace' );
	}

	ob_start();
	?>
	<article class="lnkup-event-card lnkup-event-card--v2<?php echo $is_closed ? ' lnkup-event-card--closed' : ''; ?><?php echo ( ! empty( $opp['card_variant'] ) && 'compact' === $opp['card_variant'] ) ? ' lnkup-event-card--compact' : ''; ?>" data-listing-type="managed" data-opportunity-id="<?php echo esc_attr( (string) $opp['opportunity_id'] ); ?>" data-lat="<?php echo esc_attr( (string) ( $opp['lat'] ?? '' ) ); ?>" data-lng="<?php echo esc_attr( (string) ( $opp['lng'] ?? '' ) ); ?>">
		<div class="lnkup-event-card__media">
			<?php echo lnkup_event_card_img_html( $img, $img_alt, '', (int) ( $opp['featured_image_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<div class="lnkup-event-card__badges-top">
				<span class="lnkup-badge lnkup-badge--accent"><?php echo esc_html( $opp['event_type_label'] ?? __( 'Event Opportunity', 'lnkup-marketplace' ) ); ?></span>
				<span class="lnkup-badge lnkup-badge--managed"><?php echo esc_html( $bookable ? __( 'Book on LNKup', 'lnkup-marketplace' ) : __( 'Not bookable on LNKup', 'lnkup-marketplace' ) ); ?></span>
				<span class="lnkup-badge"><?php echo esc_html( $opp['fee_label'] ?? __( 'Fee unavailable', 'lnkup-marketplace' ) ); ?></span>
			</div>
			<?php if ( ! empty( $opp['badges'] ) ) : ?>
				<div class="lnkup-event-card__badges-float">
					<?php foreach ( array_slice( $opp['badges'], 0, 2 ) as $b ) : ?>
						<span class="lnkup-badge lnkup-badge--secondary"><?php echo esc_html( $badge_labels[ $b ] ?? $b ); ?></span>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<button type="button" class="lnkup-event-card__favorite<?php echo $favorited ? ' is-active' : ''; ?>" data-favorite-type="opportunities" data-favorite-id="<?php echo esc_attr( (string) $opp['opportunity_id'] ); ?>" aria-label="<?php esc_attr_e( 'Favorite', 'lnkup-marketplace' ); ?>">♥</button>
		</div>
		<div class="lnkup-event-card__body">
			<?php $tour_link = class_exists( 'LNKup_Portfolio_Public' ) ? LNKup_Portfolio_Public::card_tour_link( $event_id ) : null; ?>
			<?php if ( $tour_link ) : ?>
				<a class="lnkup-event-card__tour" href="<?php echo esc_url( $tour_link['url'] ); ?>" data-testid="lnkup-card-tour-link"><?php echo esc_html( $tour_link['label'] ); ?></a>
			<?php endif; ?>
			<?php if ( ! empty( $opp['organizer_name'] ) ) : ?>
				<div class="lnkup-event-card__organizer">
					<span class="lnkup-event-card__avatar"><?php echo esc_html( substr( $opp['organizer_name'], 0, 1 ) ); ?></span>
					<span><?php echo esc_html( $opp['organizer_name'] ); ?></span>
					<?php if ( ! empty( $opp['organizer_verified'] ) ) : ?>
						<span class="lnkup-badge lnkup-badge--verified"><?php esc_html_e( 'Verified', 'lnkup-marketplace' ); ?></span>
					<?php endif; ?>
				</div>
				<?php
				if ( ! empty( $opp['organizer_id'] ) && class_exists( 'LNKup_Verification' ) ) {
					$org_badges = LNKup_Verification::get_organizer_badges( (int) $opp['organizer_id'], 'card' );
					if ( $org_badges ) {
						echo '<div class="lnkup-event-card__trust-badges" data-testid="lnkup-opp-organizer-trust">';
						foreach ( $org_badges as $ob ) {
							printf(
								'<span class="lnkup-trust-badge lnkup-trust-badge--accent">%s</span>',
								esc_html( $ob['label'] ?? '' )
							);
						}
						echo '</div>';
					}
				}
				?>
			<?php endif; ?>
			<?php if ( ! empty( $opp['listing_status'] ) && 'organizer_controlled' !== $opp['listing_status']['status'] ) : ?>
				<p class="lnkup-event-card__listing-status lnkup-muted" data-testid="lnkup-opp-listing-status" title="<?php echo esc_attr( $opp['listing_status']['description'] ); ?>"><?php echo esc_html( $opp['listing_status']['label'] ); ?></p>
			<?php endif; ?>
			<h3><a href="<?php echo esc_url( $view_url ); ?>"><?php echo wp_kses_post( $name ); ?></a></h3>
			<p class="lnkup-event-card__summary">
				<span><?php echo esc_html( trim( ( ! empty( $opp['date'] ) ? date_i18n( 'M j, Y', strtotime( $opp['date'] ) ) : '' ) . ( $location ? ' · ' . $location : '' ), ' ·' ) ); ?></span>
				<span class="lnkup-event-card__availability"><?php echo esc_html( $availability_line ); ?></span>
			</p>
			<?php if ( $subtitle ) : ?>
				<p class="lnkup-event-card__subtitle lnkup-muted"><?php echo esc_html( $subtitle ); ?></p>
			<?php endif; ?>
			<?php if ( $match_chip ) : ?>
				<div class="lnkup-event-card__match"><?php echo $match_chip; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<?php endif; ?>
			<div class="lnkup-event-card__meta lnkup-event-card__meta-grid">
				<div class="lnkup-event-card__meta-item">
					<span class="lnkup-event-card__meta-label"><?php esc_html_e( 'Location', 'lnkup-marketplace' ); ?></span>
					<span><?php echo esc_html( $location ?: __( 'TBA', 'lnkup-marketplace' ) ); ?></span>
				</div>
				<?php if ( ! empty( $opp['date'] ) ) : ?>
				<div class="lnkup-event-card__meta-item">
					<span class="lnkup-event-card__meta-label"><?php esc_html_e( 'Date', 'lnkup-marketplace' ); ?></span>
					<span><?php echo esc_html( date_i18n( 'M j, Y', strtotime( $opp['date'] ) ) ); ?></span>
				</div>
				<?php endif; ?>
				<div class="lnkup-event-card__meta-item">
					<span class="lnkup-event-card__meta-label"><?php esc_html_e( 'Booth fee', 'lnkup-marketplace' ); ?></span>
					<span><?php echo esc_html( $opp['fee_label'] ?? lnkup_currency( $opp['vendor_fee'] ?? 0 ) ); ?></span>
				</div>
				<div class="lnkup-event-card__meta-item">
					<span class="lnkup-event-card__meta-label"><?php esc_html_e( 'Capacity', 'lnkup-marketplace' ); ?></span>
					<span><?php
					$filled = max( 0, (int) $opp['total_spots'] - (int) $opp['spots_remaining'] );
					printf(
						esc_html__( '%1$d / %2$d', 'lnkup-marketplace' ),
						$filled,
						(int) $opp['total_spots']
					);
					?></span>
				</div>
				<?php if ( ! empty( $opp['venue_label'] ) ) : ?>
				<div class="lnkup-event-card__meta-item">
					<span class="lnkup-event-card__meta-label"><?php esc_html_e( 'Venue', 'lnkup-marketplace' ); ?></span>
					<span><?php echo esc_html( $opp['venue_label'] ); ?></span>
				</div>
				<?php endif; ?>
				<div class="lnkup-event-card__meta-item">
					<span class="lnkup-event-card__meta-label"><?php esc_html_e( 'Deadline', 'lnkup-marketplace' ); ?></span>
					<span><?php echo esc_html( $opp['deadline_label'] ?? __( 'Rolling', 'lnkup-marketplace' ) ); ?></span>
				</div>
			</div>
			<?php if ( ! empty( $opp['categories'] ) ) : ?>
				<div class="lnkup-event-card__chips">
					<?php foreach ( array_slice( $opp['categories'], 0, 3 ) as $cat ) : ?>
						<span class="lnkup-badge lnkup-badge--secondary"><?php echo esc_html( lnkup_display_category_badge( $cat ) ); ?></span>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<?php if ( ! empty( $opp['environment_labels'] ) || ! empty( $opp['document_badges'] ) ) : ?>
				<div class="lnkup-event-card__chips lnkup-event-card__chips--meta">
					<?php foreach ( (array) $opp['environment_labels'] as $env_label ) : ?>
						<span class="lnkup-badge lnkup-badge--outline"><?php echo esc_html( $env_label ); ?></span>
					<?php endforeach; ?>
					<?php foreach ( (array) $opp['document_badges'] as $doc_label ) : ?>
						<span class="lnkup-badge lnkup-badge--outline"><?php echo esc_html( $doc_label ); ?></span>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<div class="lnkup-event-card__footer">
				<span class="lnkup-badge lnkup-badge--secondary"><?php echo esc_html( $availability_line ); ?></span>
				<div class="lnkup-event-card__actions">
					<?php if ( lnkup_marketplace_phase_at_least( '1c' ) ) : ?>
					<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm lnkup-compare-add" data-opportunity-id="<?php echo esc_attr( (string) $opp['opportunity_id'] ); ?>"><?php esc_html_e( 'Compare', 'lnkup-marketplace' ); ?></button>
					<?php endif; ?>
					<a href="<?php echo esc_url( $view_url ); ?>" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm"><?php esc_html_e( 'View', 'lnkup-marketplace' ); ?></a>
					<?php if ( $status_label ) : ?>
						<a href="<?php echo esc_url( add_query_arg( 'view', 'applications', lnkup_page_url_helper( 'vendor-dashboard' ) ) ); ?>" class="lnkup-badge lnkup-badge--accent lnkup-event-card__status" data-application-status="<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $status_label ); ?></a>
					<?php elseif ( $is_closed ) : ?>
						<span class="lnkup-badge"><?php esc_html_e( 'Closed', 'lnkup-marketplace' ); ?></span>
					<?php elseif ( $bookable ) : ?>
						<a href="<?php echo esc_url( $apply_url ); ?>" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm"><?php esc_html_e( 'Apply', 'lnkup-marketplace' ); ?></a>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</article>
	<?php
	return ob_get_clean();
}

/**
 * Render skeleton card placeholder.
 */
function lnkup_render_opportunity_card_skeleton() {
	return '<article class="lnkup-event-card lnkup-event-card--skeleton"><div class="lnkup-skeleton lnkup-skeleton--media"></div><div class="lnkup-skeleton lnkup-skeleton--title"></div><div class="lnkup-skeleton lnkup-skeleton--line"></div></article>';
}

/**
 * Title-case a display label. Mixed-case values are left as written.
 *
 * @param string $value Raw label.
 */
function lnkup_display_label( $value ) {
	$value = trim( (string) $value );
	if ( '' === $value ) {
		return '';
	}
	if ( function_exists( 'mb_strtolower' ) && function_exists( 'mb_strtoupper' ) ) {
		$lower = mb_strtolower( $value );
		$upper = mb_strtoupper( $value );
	} else {
		$lower = strtolower( $value );
		$upper = strtoupper( $value );
	}
	if ( $value !== $lower && $value !== $upper ) {
		return $value;
	}
	return ucwords( strtolower( $value ) );
}

/**
 * Short public category name. Stored terms are not changed.
 *
 * @param string $value Raw category.
 */
function lnkup_display_category_badge( $value ) {
	$label = lnkup_display_label( $value );
	$key   = strtolower( $label );
	$map   = array(
		'art vendor'         => __( 'Art', 'lnkup-marketplace' ),
		'beauty vendor'      => __( 'Beauty', 'lnkup-marketplace' ),
		'merchandise vendor' => __( 'Merchandise', 'lnkup-marketplace' ),
		'food vendor'        => __( 'Food', 'lnkup-marketplace' ),
		'food truck'         => __( 'Food Trucks', 'lnkup-marketplace' ),
		'service vendor'     => __( 'Services', 'lnkup-marketplace' ),
	);
	return $map[ $key ] ?? $label;
}

/**
 * Public category names. Stored terms and booking meta are not renamed.
 *
 * @return string[]
 */
function lnkup_canonical_vendor_categories() {
	return array(
		'Food',
		'Food Trucks',
		'Desserts',
		'Drinks',
		'Retail',
		'Clothing',
		'Beauty',
		'Jewelry',
		'Art',
		'Photo Booth',
		'Services',
		'Wellness',
		'Merchandise',
	);
}

/**
 * Map a stored or event-specific category onto a canonical label.
 *
 * @param string $value Raw term or chip.
 * @return string Empty when the value should not be shown as its own category.
 */
function lnkup_canonical_category_label( $value ) {
	$raw = trim( (string) $value );
	if ( '' === $raw ) {
		return '';
	}
	$head = $raw;
	if ( preg_match( '/^(.+?)\s+[—–]\s+/u', $raw, $match ) ) {
		$head = trim( $match[1] );
	}
	$label   = lnkup_display_category_badge( $head );
	$aliases = array(
		'beverage'           => __( 'Drinks', 'lnkup-marketplace' ),
		'beverage vendor'    => __( 'Drinks', 'lnkup-marketplace' ),
		'dessert vendor'     => __( 'Desserts', 'lnkup-marketplace' ),
		'retail vendor'      => __( 'Retail', 'lnkup-marketplace' ),
		'clothing brand'     => __( 'Clothing', 'lnkup-marketplace' ),
		'jewelry vendor'     => __( 'Jewelry', 'lnkup-marketplace' ),
		'photo booth vendor' => __( 'Photo Booth', 'lnkup-marketplace' ),
		'wellness vendor'    => __( 'Wellness', 'lnkup-marketplace' ),
		'all vendors'        => '',
		'all vendors welcome'=> '',
	);
	$key = strtolower( $label );
	if ( array_key_exists( $key, $aliases ) ) {
		return $aliases[ $key ];
	}
	foreach ( lnkup_canonical_vendor_categories() as $canonical ) {
		if ( 0 === strcasecmp( $canonical, $label ) ) {
			return $canonical;
		}
	}
	if ( preg_match( '/[—–]/u', $raw ) ) {
		return '';
	}
	return $label;
}

/**
 * One checkbox per canonical category, keeping every stored term name for the filter.
 *
 * @param array $terms Term objects or names.
 * @return array<int, array{label:string,terms:string[]}>
 */
function lnkup_canonical_category_options( $terms ) {
	$grouped = array();
	foreach ( (array) $terms as $term ) {
		$name  = is_object( $term ) ? (string) $term->name : (string) $term;
		$label = lnkup_canonical_category_label( $name );
		if ( '' === $label ) {
			continue;
		}
		if ( ! isset( $grouped[ $label ] ) ) {
			$grouped[ $label ] = array();
		}
		$grouped[ $label ][] = $name;
	}
	$options = array();
	$used    = array();
	foreach ( lnkup_canonical_vendor_categories() as $canonical ) {
		if ( empty( $grouped[ $canonical ] ) ) {
			continue;
		}
		$options[]            = array(
			'label' => $canonical,
			'terms' => array_values( array_unique( $grouped[ $canonical ] ) ),
		);
		$used[ $canonical ] = true;
	}
	foreach ( $grouped as $label => $names ) {
		if ( isset( $used[ $label ] ) ) {
			continue;
		}
		$options[] = array(
			'label' => $label,
			'terms' => array_values( array_unique( $names ) ),
		);
	}
	return $options;
}

/**
 * Display a state: two letters stay uppercase, a full name is title-cased.
 *
 * @param string $state Raw state.
 */
function lnkup_display_state( $state ) {
	$state = trim( (string) $state );
	if ( '' === $state ) {
		return '';
	}
	$aliases = array(
		'ge' => 'GA',
	);
	$key = strtolower( $state );
	if ( isset( $aliases[ $key ] ) ) {
		return $aliases[ $key ];
	}
	if ( 2 === strlen( $state ) ) {
		return strtoupper( $state );
	}
	return lnkup_display_label( $state );
}

/**
 * Display city and state without rewriting stored meta.
 *
 * @param string $city  Raw city.
 * @param string $state Raw state.
 */
function lnkup_display_place( $city, $state ) {
	$city  = lnkup_display_label( $city );
	if ( 'pheonix' === strtolower( $city ) ) {
		$city = 'Phoenix';
	}
	$state = lnkup_display_state( $state );
	if ( $city && $state ) {
		return $city . ', ' . $state;
	}
	return $city ? $city : $state;
}

/**
 * Drop a trailing venue and weekday date from a title when the date is shown separately.
 *
 * @param string $title Event title.
 * @param string $date  Y-m-d event date, or empty.
 */
function lnkup_display_event_title( $title, $date, $venue = '', $organizer = '' ) {
	$title = trim( (string) $title );
	if ( '' === (string) $date || '' === $title ) {
		return $title;
	}
	$title = trim( (string) preg_replace( '/\s*@[^|]+/', ' ', $title ) );
	$parts = preg_split( '/\s*\|\s*/', $title );
	if ( ! is_array( $parts ) ) {
		return $title;
	}
	$months   = 'Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?';
	$days     = 'Mon(?:day)?|Tue(?:sday)?|Wed(?:nesday)?|Thu(?:rsday)?|Fri(?:day)?|Sat(?:urday)?|Sun(?:day)?';
	$date_re  = '/^(?:(?:' . $days . '),?\s+)?(?:' . $months . ')\s+\d{1,2}(?:st|nd|rd|th)?(?:,?\s+\d{4})?$/i';
	$venue    = trim( (string) $venue );
	$organizer = trim( (string) $organizer );
	$kept     = array();
	foreach ( $parts as $part ) {
		$part = trim( (string) $part );
		if ( '' === $part || preg_match( $date_re, $part ) ) {
			continue;
		}
		if ( '' !== $venue && 0 === strcasecmp( $part, $venue ) ) {
			continue;
		}
		if ( '' !== $organizer && 0 === strcasecmp( $part, $organizer ) ) {
			continue;
		}
		$kept[] = $part;
	}
	if ( ! $kept ) {
		return trim( (string) preg_replace( '/\s{2,}/', ' ', $title ) );
	}
	return trim( (string) preg_replace( '/\s{2,}/', ' ', implode( ' | ', $kept ) ), " \t|" );
}

/**
 * Group opportunity rows so one event is one card.
 *
 * @param array $items Opportunity rows.
 * @return array<int, array<int, array>>
 */
function lnkup_group_opportunities_by_event( $items ) {
	$groups = array();
	$order  = array();
	foreach ( (array) $items as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}
		$event_id = (int) ( $item['event_id'] ?? 0 );
		$opp_id   = (int) ( $item['opportunity_id'] ?? $item['id'] ?? 0 );
		$key      = $event_id > 0 ? 'e' . $event_id : 'o' . $opp_id;
		if ( ! isset( $groups[ $key ] ) ) {
			$groups[ $key ] = array();
			$order[]        = $key;
		}
		if ( $opp_id && isset( $groups[ $key ][ $opp_id ] ) ) {
			continue;
		}
		if ( $opp_id ) {
			$groups[ $key ][ $opp_id ] = $item;
		} else {
			$groups[ $key ][] = $item;
		}
	}
	$out = array();
	foreach ( $order as $key ) {
		$out[] = array_values( $groups[ $key ] );
	}
	return $out;
}

/**
 * One public event card for a group of vendor slots.
 *
 * @param array $slots Opportunity rows for a single event.
 */
function lnkup_render_event_group_card( $slots ) {
	return lnkup_render_homepage_event_card( $slots, 'feed' );
}

/**
 * Public discovery card for one event.
 *
 * @param array  $slots   Opportunity rows for a single event.
 * @param string $context home|feed. Apply is only offered on the feed.
 */
function lnkup_render_homepage_event_card( $slots, $context = 'home' ) {
	$slots = array_values( (array) $slots );
	if ( ! $slots ) {
		return '';
	}
	$first    = $slots[0];
	$event_id = (int) ( $first['event_id'] ?? 0 );
	$event    = $event_id ? get_post( $event_id ) : null;
	$date     = (string) ( $first['date'] ?? '' );
	$title    = $event ? $event->post_title : (string) ( $first['event_name'] ?? $first['name'] ?? '' );
	$venue    = '';
	$organizer_name = '';
	if ( $event_id && ! get_post_meta( $event_id, '_lnkup_venue_hidden', true ) ) {
		$venue = (string) get_post_meta( $event_id, '_lnkup_venue_name', true );
	}
	if ( $event_id ) {
		$organizer_user = (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true );
		if ( $organizer_user ) {
			$organizer_name = (string) get_user_meta( $organizer_user, '_lnkup_organizer_public_name', true );
		}
	}
	$title    = lnkup_display_event_title( $title, $date, $venue, $organizer_name );
	$place    = ( ! empty( $first['city'] ) || ! empty( $first['state'] ) )
		? lnkup_display_place( $first['city'] ?? '', $first['state'] ?? '' )
		: '';
	$when     = $date ? date_i18n( 'M j, Y', strtotime( $date ) ) : '';
	$bookable = ! ( $event_id && class_exists( 'LNKup_Organizer_Onboarding' ) && LNKup_Organizer_Onboarding::event_is_unclaimed_external( $event_id ) );
	$view_url = ( $event_id && class_exists( 'LNKup_Organizer_Onboarding' ) ) ? LNKup_Organizer_Onboarding::event_public_url( $event_id ) : '';
	$apply_base = function_exists( 'lnkup_page_url_helper' ) ? lnkup_page_url_helper( 'opportunities' ) : '';

	$fee         = null;
	$fee_max     = null;
	$fee_known   = false;
	$accepting   = false;
	$open_spot_total = 0;
	$spots_left  = false;
	$closing     = false;
	$apply_id    = 0;
	$app_status  = '';
	$cats        = array();
	$slot_rows   = array();
	foreach ( $slots as $slot ) {
		$open = ! isset( $slot['is_open'] ) || ! empty( $slot['is_open'] );
		$left = (int) ( $slot['spots_remaining'] ?? 0 );
		$slot_status = (string) ( $slot['application_status'] ?? '' );
		if ( $left > 0 ) {
			$spots_left = true;
		}
		if ( in_array( 'closing_soon', (array) ( $slot['badges'] ?? array() ), true ) ) {
			$closing = true;
		}
		if ( '' === $app_status && '' !== $slot_status ) {
			$app_status = lnkup_application_status_label( $slot_status );
		}
		if ( $bookable && $open && $left > 0 ) {
			$accepting = true;
			$open_spot_total += $left;
			if ( array_key_exists( 'vendor_fee', $slot ) && '' !== $slot['vendor_fee'] && null !== $slot['vendor_fee'] ) {
				$slot_fee = (float) $slot['vendor_fee'];
				if ( ! $fee_known || $slot_fee < $fee ) {
					$fee       = $slot_fee;
					$fee_known = true;
				}
				if ( null === $fee_max || $slot_fee > $fee_max ) {
					$fee_max = $slot_fee;
				}
			}
			$slot_id = (int) ( $slot['opportunity_id'] ?? $slot['id'] ?? 0 );
			if ( ! $apply_id && $slot_id && '' === $slot_status ) {
				$apply_id = $slot_id;
			}
			$row_label = '';
			$slot_cats = array_values( array_filter( (array) ( $slot['categories'] ?? array() ) ) );
			if ( $slot_cats ) {
				$row_label = lnkup_display_category_badge( (string) $slot_cats[0] );
			}
			if ( '' === $row_label ) {
				$row_label = lnkup_display_category_badge( (string) ( $slot['opportunity_title'] ?? $slot['name'] ?? '' ) );
			}
			if ( '' === $row_label ) {
				$row_label = __( 'Vendor slot', 'lnkup-marketplace' );
			}
			$row_fee = null;
			if ( array_key_exists( 'vendor_fee', $slot ) && '' !== $slot['vendor_fee'] && null !== $slot['vendor_fee'] ) {
				$row_fee = (float) $slot['vendor_fee'];
			}
			$slot_rows[] = array(
				'label' => $row_label,
				'fee'   => $row_fee,
			);
		}
		foreach ( (array) ( $slot['categories'] ?? array() ) as $cat ) {
			$label = lnkup_canonical_category_label( (string) $cat );
			if ( '' !== $label ) {
				$cats[ strtolower( $label ) ] = $label;
			}
		}
	}
	if ( ! $bookable ) {
		$status = __( 'External listing', 'lnkup-marketplace' );
	} elseif ( $accepting && $closing ) {
		$status = __( 'Closing Soon', 'lnkup-marketplace' );
	} elseif ( $accepting ) {
		$status = __( 'Accepting Applications', 'lnkup-marketplace' );
	} elseif ( ! $spots_left ) {
		$status = __( 'Sold Out', 'lnkup-marketplace' );
	} else {
		$status = __( 'Applications Closed', 'lnkup-marketplace' );
	}
	if ( $bookable && $fee_known ) {
		$fee_label = $fee > 0
			? sprintf( __( 'From %s', 'lnkup-marketplace' ), lnkup_public_money( $fee ) )
			: __( 'Free', 'lnkup-marketplace' );
	} elseif ( ! $bookable ) {
		$fee_label = __( 'External listing', 'lnkup-marketplace' );
	} else {
		$fee_label = __( 'Fee unavailable', 'lnkup-marketplace' );
	}
	$visible_cats = array_slice( array_values( $cats ), 0, 3 );
	$extra_cats   = count( $cats ) - count( $visible_cats );
	$img          = lnkup_opportunity_illustration_url( $first );
	$img_alt      = function_exists( 'lnkup_opportunity_card_alt' ) ? lnkup_opportunity_card_alt( $first ) : $title;
	$show_apply   = ( 'feed' === $context && $bookable && $accepting && $apply_id && $apply_base );
	$action_url   = $view_url;
	$action_label = __( 'View event', 'lnkup-marketplace' );
	$action_class = 'lnkup-btn lnkup-btn--outline lnkup-btn--sm';
	if ( 'storefront' === $context && $view_url ) {
		$action_url   = $view_url . '#lnkup-vendor-options';
		$action_label = __( 'View vendor spots', 'lnkup-marketplace' );
		$action_class = 'lnkup-btn lnkup-btn--primary lnkup-btn--sm';
	}
	$apply_url    = $show_apply ? add_query_arg( 'apply', $apply_id, $apply_base ) : '';
	$status_url   = ( '' !== $app_status && ! $apply_url ) ? add_query_arg( 'view', 'applications', lnkup_page_url_helper( 'vendor-dashboard' ) ) : '';
	$can_save     = ( 'feed' === $context && $event_id && is_user_logged_in() && class_exists( 'LNKup_Roles' ) && ( LNKup_Roles::is_vendor() || ( class_exists( 'LNKup_Capabilities' ) && LNKup_Capabilities::user_has_manage_all() ) ) );
	$saved        = $can_save && class_exists( 'LNKup_Vendor_Personalization' ) && LNKup_Vendor_Personalization::is_favorite( get_current_user_id(), 'events', $event_id );
	$organizer    = trim( (string) ( $first['organizer_name'] ?? '' ) );

	ob_start();
	?>
	<article class="lnkup-event-card lnkup-event-card--v2 lnkup-event-card--public" data-event-id="<?php echo esc_attr( (string) $event_id ); ?>">
		<div class="lnkup-event-card__media">
			<?php echo lnkup_event_card_img_html( $img, $img_alt, '', (int) ( $first['featured_image_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<div class="lnkup-event-card__badges-top">
				<span class="lnkup-badge"><?php echo esc_html( $status ); ?></span>
				<?php if ( $app_status ) : ?>
					<span class="lnkup-badge"><?php echo esc_html( $app_status ); ?></span>
				<?php endif; ?>
			</div>
			<?php if ( $can_save ) : ?>
				<button type="button" class="lnkup-event-card__favorite<?php echo $saved ? ' is-active' : ''; ?>" data-favorite-type="events" data-favorite-id="<?php echo esc_attr( (string) $event_id ); ?>" aria-pressed="<?php echo $saved ? 'true' : 'false'; ?>" aria-label="<?php esc_attr_e( 'Save event', 'lnkup-marketplace' ); ?>">♥</button>
			<?php endif; ?>
		</div>
		<div class="lnkup-event-card__body">
			<?php if ( $organizer ) : ?>
				<p class="lnkup-event-card__organizer"><?php echo esc_html( $organizer ); ?></p>
			<?php endif; ?>
			<h3 class="lnkup-event-card__title">
				<?php if ( $view_url ) : ?>
					<a href="<?php echo esc_url( $view_url ); ?>"><?php echo esc_html( $title ); ?></a>
				<?php else : ?>
					<?php echo esc_html( $title ); ?>
				<?php endif; ?>
			</h3>
			<?php if ( $place || $when ) : ?>
				<p class="lnkup-event-card__meta"><?php echo esc_html( trim( $place . ( $place && $when ? ' · ' : '' ) . $when ) ); ?></p>
			<?php endif; ?>
			<p class="lnkup-event-card__fee"><?php echo esc_html( $fee_label ); ?></p>
			<?php if ( 'storefront' === $context && $open_spot_total > 0 ) : ?>
				<p class="lnkup-event-card__slot-count"><?php echo esc_html( sprintf( _n( '%d spot available', '%d spots available', $open_spot_total, 'lnkup-marketplace' ), $open_spot_total ) ); ?></p>
			<?php endif; ?>
			<?php if ( 'feed' === $context && $slot_rows ) : ?>
				<?php
				$slot_labels = array();
				foreach ( $slot_rows as $slot_row ) {
					$slot_labels[ strtolower( $slot_row['label'] ) ] = true;
				}
				$slot_count = count( $slot_labels );
				?>
				<p class="lnkup-event-card__slot-count"><?php echo esc_html( sprintf( _n( '%d vendor category available', '%d vendor categories available', $slot_count, 'lnkup-marketplace' ), $slot_count ) ); ?></p>
				<p class="lnkup-event-card__slots-title"><?php esc_html_e( 'Available vendor slots', 'lnkup-marketplace' ); ?></p>
				<ul class="lnkup-event-card__slot-prices">
					<?php foreach ( array_slice( $slot_rows, 0, 4 ) as $slot_row ) : ?>
						<li>
							<span><?php echo esc_html( $slot_row['label'] ); ?></span>
							<?php if ( null !== $slot_row['fee'] ) : ?>
								<span><?php echo esc_html( $slot_row['fee'] > 0 ? lnkup_public_money( $slot_row['fee'] ) : __( 'Free', 'lnkup-marketplace' ) ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php elseif ( $visible_cats ) : ?>
				<ul class="lnkup-event-card__chips">
					<?php foreach ( $visible_cats as $cat_label ) : ?>
						<li><span class="lnkup-badge lnkup-badge--secondary"><?php echo esc_html( $cat_label ); ?></span></li>
					<?php endforeach; ?>
					<?php if ( $extra_cats > 0 ) : ?>
						<li><span class="lnkup-badge"><?php echo esc_html( sprintf( __( '+%d more', 'lnkup-marketplace' ), $extra_cats ) ); ?></span></li>
					<?php endif; ?>
				</ul>
			<?php endif; ?>
			<div class="lnkup-event-card__footer">
				<?php if ( $action_url ) : ?>
					<a class="<?php echo esc_attr( $action_class ); ?>" href="<?php echo esc_url( $action_url ); ?>"><?php echo esc_html( $action_label ); ?></a>
				<?php endif; ?>
				<?php if ( $apply_url ) : ?>
					<a class="lnkup-btn lnkup-btn--primary lnkup-btn--sm" href="<?php echo esc_url( $apply_url ); ?>"><?php esc_html_e( 'Apply', 'lnkup-marketplace' ); ?></a>
				<?php elseif ( $status_url ) : ?>
					<a class="lnkup-btn lnkup-btn--secondary lnkup-btn--sm lnkup-event-card__status" href="<?php echo esc_url( $status_url ); ?>"><?php echo esc_html( sprintf( /* translators: %s: application status */ __( 'Application: %s', 'lnkup-marketplace' ), $app_status ) ); ?></a>
				<?php endif; ?>
			</div>
		</div>
	</article>
	<?php
	return ob_get_clean();
}

/**
 * Configured cities that currently have open vendor slots.
 *
 * @return array<int, array{label:string,count:int,url:string}>
 */
function lnkup_homepage_city_counts() {
	if ( ! class_exists( 'LNKup_Market_Pages' ) ) {
		return array();
	}
	global $wpdb;
	$rows = $wpdb->get_results(
		"SELECT city.meta_value AS city, COUNT(DISTINCT opp.ID) AS total
		FROM {$wpdb->posts} opp
		INNER JOIN {$wpdb->postmeta} status
			ON status.post_id = opp.ID AND status.meta_key = '_lnkup_opportunity_status' AND status.meta_value = 'open'
		INNER JOIN {$wpdb->postmeta} spots
			ON spots.post_id = opp.ID AND spots.meta_key = '_lnkup_spots_remaining' AND CAST(spots.meta_value AS SIGNED) > 0
		INNER JOIN {$wpdb->postmeta} event_id
			ON event_id.post_id = opp.ID AND event_id.meta_key = '_lnkup_event_id'
		INNER JOIN {$wpdb->posts} event_post
			ON event_post.ID = CAST(event_id.meta_value AS UNSIGNED) AND event_post.post_type = 'lnkup_event' AND event_post.post_status = 'publish'
		INNER JOIN {$wpdb->postmeta} city
			ON city.post_id = event_post.ID AND city.meta_key = '_lnkup_city'
		WHERE opp.post_type = 'lnkup_opportunity' AND opp.post_status = 'publish'
		GROUP BY city.meta_value",
		ARRAY_A
	);
	$by_city = array();
	foreach ( (array) $rows as $row ) {
		$key = strtolower( trim( (string) ( $row['city'] ?? '' ) ) );
		if ( '' === $key ) {
			continue;
		}
		$by_city[ $key ] = ( $by_city[ $key ] ?? 0 ) + (int) $row['total'];
	}
	$feed   = function_exists( 'lnkup_page_url_helper' ) ? lnkup_page_url_helper( 'opportunities' ) : home_url( '/opportunities/' );
	$cities = array();
	foreach ( LNKup_Market_Pages::get_all_markets() as $market ) {
		if ( ! is_array( $market ) ) {
			continue;
		}
		if ( isset( $market['enabled'] ) && empty( $market['enabled'] ) ) {
			continue;
		}
		$city = trim( (string) ( $market['city'] ?? '' ) );
		$key  = strtolower( $city );
		$count = $by_city[ $key ] ?? 0;
		if ( $count < 1 || '' === $city ) {
			continue;
		}
		$label = trim( (string) ( $market['display_name'] ?? '' ) );
		if ( '' === $label ) {
			$label = lnkup_display_place( $city, (string) ( $market['state'] ?? '' ) );
		}
		$cities[] = array(
			'label' => $label,
			'count' => $count,
			'url'   => add_query_arg( 'city', $city, $feed ),
		);
	}
	usort(
		$cities,
		static function ( $a, $b ) {
			return (int) $b['count'] <=> (int) $a['count'];
		}
	);
	return array_slice( $cities, 0, 6 );
}

/**
 * Batch render one card per event.
 *
 * @param array $items Items.
 */
function lnkup_render_opportunity_cards_batch( $items ) {
	$html = '';
	foreach ( lnkup_group_opportunities_by_event( $items ) as $slots ) {
		$html .= lnkup_render_event_group_card( $slots );
	}
	return $html;
}

/**
 * Legacy card wrapper — delegates to v2 when index data available.
 *
 * @param array $opp Opportunity data.
 */
function lnkup_render_event_card( $opp ) {
	if ( ! empty( $opp['opportunity_id'] ) || ! empty( $opp['id'] ) ) {
		$id = $opp['opportunity_id'] ?? $opp['id'];
		$row = LNKup_Marketplace_Index::get_row( (int) $id );
		if ( $row ) {
			return lnkup_render_opportunity_card_v2( lnkup_format_index_row( $row ) );
		}
	}
	return lnkup_render_opportunity_card_v2( $opp );
}

/**
 * Query discoverable vendor profiles for the organizer directory.
 *
 * @param array $args Query arguments.
 */
function lnkup_get_discoverable_vendors( $args = array() ) {
	$args = wp_parse_args(
		$args,
		array(
			'page'        => 1,
			'per_page'    => 24,
			'q'           => '',
			'city'        => '',
			'state'       => '',
			'category'    => '',
			'verified'    => '',
			'post_status' => 'publish',
			'organizer_id' => 0,
		)
	);

	$query_args = array(
		'post_type'              => 'lnkup_vendor_profile',
		'post_status'            => $args['post_status'],
		'posts_per_page'         => min( 48, max( 1, absint( $args['per_page'] ) ) ),
		'paged'                  => max( 1, absint( $args['page'] ) ),
		'orderby'                => 'date',
		'order'                  => 'DESC',
		'no_found_rows'          => false,
		'update_post_meta_cache' => true,
		'meta_query'             => array(
			array(
				'key'     => '_lnkup_owner_user_id',
				'compare' => 'EXISTS',
			),
		),
	);

	if ( '' !== $args['verified'] && $args['verified'] ) {
		$query_args['meta_query'][] = array(
			'key'   => '_lnkup_verification_status',
			'value' => 'approved',
		);
	}

	// Vendors who opted out of discovery stay visible only to organizers they already work with.
	$organizer_id = absint( $args['organizer_id'] );
	if ( ! $organizer_id || ! LNKup_Capabilities::user_has_manage_all( $organizer_id ) ) {
		$discoverable = array(
			'relation' => 'OR',
			array(
				'key'     => '_lnkup_discoverable',
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => '_lnkup_discoverable',
				'value'   => '0',
				'compare' => '!=',
			),
		);
		$related = ( $organizer_id && class_exists( 'LNKup_Match_Service' ) ) ? LNKup_Match_Service::related_vendor_users( $organizer_id ) : array();
		if ( $related ) {
			$discoverable[] = array(
				'key'     => '_lnkup_owner_user_id',
				'value'   => array_map( 'strval', $related ),
				'compare' => 'IN',
			);
		}
		$query_args['meta_query'][] = $discoverable;
	}

	if ( ! empty( $args['city'] ) ) {
		$query_args['meta_query'][] = array(
			'key'     => '_lnkup_city',
			'value'   => sanitize_text_field( $args['city'] ),
			'compare' => 'LIKE',
		);
	}

	if ( ! empty( $args['state'] ) ) {
		$query_args['meta_query'][] = array(
			'key'   => '_lnkup_state',
			'value' => sanitize_text_field( $args['state'] ),
		);
	}

	if ( ! empty( $args['category'] ) ) {
		$query_args['tax_query'] = array(
			array(
				'taxonomy' => 'lnkup_vendor_category',
				'field'    => 'name',
				'terms'    => sanitize_text_field( $args['category'] ),
			),
		);
	}

	if ( ! empty( $args['q'] ) ) {
		// Title OR business name OR city: WP's `s` plus a meta clause would AND them together.
		global $wpdb;
		$like = '%' . $wpdb->esc_like( sanitize_text_field( $args['q'] ) ) . '%';
		$ids  = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key IN ('_lnkup_business_name','_lnkup_city','_lnkup_state')
				WHERE p.post_type = 'lnkup_vendor_profile' AND ( p.post_title LIKE %s OR m.meta_value LIKE %s )
				LIMIT 2000",
				$like,
				$like
			)
		);
		$query_args['post__in'] = $ids ? array_map( 'intval', $ids ) : array( 0 );
	}

	// Global ordering happens in SQL so sort applies across pages, not just within the current page.
	$sort_meta = array(
		'score'    => '_lnkup_reputation_score',
		'reliable' => '_lnkup_reputation_score',
		'rating'   => '_lnkup_review_count',
	);
	$sort         = sanitize_key( (string) ( $args['sort'] ?? '' ) );
	$sort_clauses = null;
	if ( isset( $sort_meta[ $sort ] ) ) {
		// Order by a per-row lookup of the sort key. An EXISTS-OR-NOT-EXISTS meta_query clause combined
		// with the discoverability OR clauses makes MySQL stall for minutes on production-sized data.
		$sort_key     = $sort_meta[ $sort ];
		$sort_clauses = static function ( $clauses, $query ) use ( $sort_key ) {
			global $wpdb;
			if ( 'lnkup_vendor_profile' !== $query->get( 'post_type' ) ) {
				return $clauses;
			}
			$clauses['orderby'] = $wpdb->prepare(
				"CAST( COALESCE( ( SELECT lnkup_sort_pm.meta_value FROM {$wpdb->postmeta} lnkup_sort_pm WHERE lnkup_sort_pm.post_id = {$wpdb->posts}.ID AND lnkup_sort_pm.meta_key = %s LIMIT 1 ), '0' ) AS SIGNED ) DESC, {$wpdb->posts}.post_date DESC",
				$sort_key
			);
			return $clauses;
		};
		add_filter( 'posts_clauses', $sort_clauses, 10, 2 );
	} elseif ( 'new' === $sort ) {
		$query_args['orderby'] = 'date';
	}

	if ( count( $query_args['meta_query'] ) > 1 ) {
		$query_args['meta_query']['relation'] = 'AND';
	}

	try {
		$query = new WP_Query( $query_args );
	} finally {
		if ( $sort_clauses ) {
			remove_filter( 'posts_clauses', $sort_clauses, 10 );
		}
	}

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log(
			sprintf(
				'lnkup_get_discoverable_vendors: found %d (page %d)',
				(int) $query->found_posts,
				(int) $args['page']
			)
		);
	}

	return array(
		'posts' => $query->posts,
		'total' => (int) $query->found_posts,
		'pages' => (int) $query->max_num_pages,
		'page'  => max( 1, absint( $args['page'] ) ),
	);
}

/**
 * Normalize vendor card data with safe fallbacks.
 *
 * @param array $profile Vendor profile DTO.
 */
function lnkup_normalize_vendor_card( $profile ) {
	if ( ! is_array( $profile ) ) {
		return array();
	}
	$profile['business_name'] = $profile['business_name'] ?? __( 'Vendor', 'lnkup-marketplace' );
	$profile['category']      = is_array( $profile['category'] ?? null ) ? $profile['category'] : array();
	$profile['city']          = $profile['city'] ?? '';
	$profile['state']         = $profile['state'] ?? '';
	$profile['vendor_score']  = isset( $profile['vendor_score'] ) ? (int) $profile['vendor_score'] : 0;
	$profile['avg_rating']    = isset( $profile['avg_rating'] ) ? (float) $profile['avg_rating'] : 0;
	$profile['review_count']  = isset( $profile['review_count'] ) ? (int) $profile['review_count'] : 0;
	$profile['completed_events'] = isset( $profile['completed_events'] ) ? (int) $profile['completed_events'] : 0;
	$profile['logo']          = $profile['logo'] ?? '';
	$profile['documents']     = is_array( $profile['documents'] ?? null ) ? $profile['documents'] : array();
	$profile['badges']        = is_array( $profile['badges'] ?? null ) ? $profile['badges'] : array();
	$profile['verified']      = ! empty( $profile['verified'] );
	return $profile;
}

/**
 * Canonical parent => child event type map.
 */
function lnkup_get_event_type_tree_definition() {
	if ( class_exists( 'LNKup_Taxonomies' ) ) {
		return LNKup_Taxonomies::get_event_type_tree_definition();
	}
	return array();
}

/**
 * Top-level event type parents.
 */
function lnkup_get_event_type_parents() {
	$terms = get_terms(
		array(
			'taxonomy'   => 'lnkup_event_type',
			'parent'     => 0,
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		)
	);
	return is_wp_error( $terms ) ? array() : $terms;
}

/**
 * Child event types for a parent term.
 *
 * @param int $parent_id Parent term ID.
 */
function lnkup_get_event_type_children( $parent_id ) {
	$terms = get_terms(
		array(
			'taxonomy'   => 'lnkup_event_type',
			'parent'     => absint( $parent_id ),
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		)
	);
	return is_wp_error( $terms ) ? array() : $terms;
}

/**
 * Nested event type tree for PHP templates and JS localization.
 */
function lnkup_get_event_type_tree() {
	$tree = array();
	foreach ( lnkup_get_event_type_parents() as $parent ) {
		$children = array();
		foreach ( lnkup_get_event_type_children( $parent->term_id ) as $child ) {
			$children[] = array(
				'id'   => (int) $child->term_id,
				'name' => $child->name,
				'slug' => $child->slug,
			);
		}
		$tree[] = array(
			'id'       => (int) $parent->term_id,
			'name'     => $parent->name,
			'slug'     => $parent->slug,
			'children' => $children,
		);
	}
	return $tree;
}

/**
 * Expand assigned event type terms to leaf + parent names for indexing/filtering.
 *
 * @param array $terms WP_Term objects.
 */
function lnkup_expand_event_type_names_for_index( $terms ) {
	$names = array();
	if ( ! is_array( $terms ) ) {
		return $names;
	}
	foreach ( $terms as $term ) {
		if ( ! $term instanceof WP_Term ) {
			continue;
		}
		$names[] = $term->name;
		if ( $term->parent ) {
			$parent = get_term( $term->parent, 'lnkup_event_type' );
			if ( $parent && ! is_wp_error( $parent ) ) {
				$names[] = $parent->name;
			}
		}
	}
	return array_values( array_unique( array_filter( $names ) ) );
}

/**
 * Organizer event intelligence summary for dashboard widgets.
 *
 * @param int|null $organizer_id Organizer user ID.
 */
function lnkup_get_event_intelligence_summary( $organizer_id = null ) {
	$organizer_id = $organizer_id ? absint( $organizer_id ) : get_current_user_id();
	if ( ! $organizer_id ) {
		return array(
			'kpis'      => array(),
			'insights'  => array(),
			'events'    => array(),
			'warnings'  => array(),
		);
	}

	if ( class_exists( 'LNKup_Organizer_Intelligence' ) ) {
		return LNKup_Organizer_Intelligence::event_intelligence_summary( $organizer_id );
	}

	return array(
		'kpis'     => array(),
		'insights' => array( __( 'More data needed.', 'lnkup-marketplace' ) ),
		'events'   => array(),
		'warnings' => array(),
	);
}

/**
 * Semantic status badge modifier map.
 *
 * @return array<string, string>
 */
function lnkup_status_badge_modifier( string $status ): string {
	$map = array(
		'paid'             => 'success',
		'completed'        => 'success',
		'approved'         => 'success',
		'ready'            => 'success',
		'verified'         => 'success',
		'submitted'        => 'warning',
		'under_review'     => 'warning',
		'payment_required' => 'warning',
		'pending'          => 'warning',
		'docs_pending'     => 'warning',
		'balance_due'      => 'warning',
		'rejected'         => 'danger',
		'cancelled'        => 'danger',
		'failed'           => 'danger',
		'overdue'          => 'danger',
		'missing_docs'     => 'danger',
		'invited'          => 'info',
		'recommended'      => 'info',
		'featured'         => 'info',
		'draft'            => 'muted',
		'open'             => 'muted',
		'archived'         => 'muted',
		'waitlisted'       => 'muted',
	);
	return $map[ $status ] ?? 'muted';
}

/**
 * Render a semantic status badge.
 *
 * @param string $status Workflow status slug.
 * @param string $label  Display label.
 */
function lnkup_status_badge( string $status, string $label = '' ): string {
	$modifier = lnkup_status_badge_modifier( $status );
	if ( ! $label ) {
		$label = $status;
	}
	return sprintf(
		'<span class="lnkup-badge lnkup-badge--%s">%s</span>',
		esc_attr( $modifier ),
		esc_html( $label )
	);
}

/**
 * Render standardized KPI card.
 *
 * @param array<string, mixed> $args Card arguments.
 */
function lnkup_kpi_card( array $args ): string {
	$label     = (string) ( $args['label'] ?? '' );
	$value     = (string) ( $args['value'] ?? '' );
	$trend     = (string) ( $args['trend'] ?? '' );
	$icon      = (string) ( $args['icon'] ?? 'apps' );
	$is_zero   = ! empty( $args['is_zero'] );
	$trend_dir = (string) ( $args['trend_dir'] ?? 'neutral' );
	$href      = (string) ( $args['href'] ?? '' );

	$icons = array(
		'apps'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>',
		'star'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m12 2 3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>',
		'cash'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
		'docs'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M12 18v-6M9 15h6"/></svg>',
		'payment'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><path d="M1 10h22"/></svg>',
		'calendar' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>',
		'inbox'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-6l-2 3H10l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg>',
		'users'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
		'shield'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
		'folder'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.93a2 2 0 0 1-1.66-.9l-.82-1.2A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2z"/></svg>',
	);
	$trend_icons = array(
		'up'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m22 7-8.5 8.5-5-5L2 17"/><path d="M16 7h6v6"/></svg>',
		'down'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m22 17-8.5-8.5-5 5L2 7"/><path d="M16 17h6v-6"/></svg>',
		'neutral' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/></svg>',
	);
	$trend_dir = in_array( $trend_dir, array( 'up', 'down', 'neutral' ), true ) ? $trend_dir : 'neutral';
	$has_spark = $trend && in_array( $trend_dir, array( 'up', 'down' ), true );
	$icon_html = function_exists( 'lnkup_inline_icon' )
		? lnkup_inline_icon( $icons[ $icon ] ?? $icons['apps'], 18 )
		: ( $icons[ $icon ] ?? $icons['apps'] );
	$trend_icon = function_exists( 'lnkup_inline_icon' )
		? lnkup_inline_icon( $trend_icons[ $trend_dir ], 12 )
		: $trend_icons[ $trend_dir ];
	$value_class = 'lnkup-stat-card__value' . ( $is_zero ? ' lnkup-stat-card__value--muted' : '' );
	$card_class  = 'lnkup-stat-card' . ( $href ? ' lnkup-stat-card--link' : '' ) . ( $has_spark ? ' lnkup-kpi-card--spark' : '' );
	$accent      = (string) ( $args['accent'] ?? '' );
	if ( $accent ) {
		$card_class .= ' lnkup-stat-card--accent-' . sanitize_html_class( $accent );
	}
	$animate_raw = isset( $args['animate'] ) ? (string) $args['animate'] : '';
	$spark_pct   = 'up' === $trend_dir ? '72%' : '38%';
	ob_start();
	?>
	<<?php echo $href ? 'a' : 'div'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> class="<?php echo esc_attr( $card_class ); ?>"<?php echo $href ? ' href="' . esc_url( $href ) . '"' : ''; ?>>
		<div class="lnkup-stat-card__content">
			<p class="lnkup-stat-card__label"><?php echo esc_html( $label ); ?></p>
			<p class="<?php echo esc_attr( $value_class ); ?>"<?php echo $animate_raw !== '' ? ' data-animate-count="' . esc_attr( $animate_raw ) . '"' . ( ! empty( $args['count_currency'] ) ? ' data-count-currency="1" data-count-prefix="$"' : '' ) : ''; ?>><?php echo esc_html( $value ); ?></p>
			<?php if ( $trend ) : ?>
				<p class="lnkup-stat-card__trend lnkup-stat-card__trend--<?php echo esc_attr( $trend_dir ); ?>">
					<span class="lnkup-stat-card__trend-icon" aria-hidden="true"><?php echo $trend_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<?php echo esc_html( $trend ); ?>
				</p>
			<?php endif; ?>
			<?php if ( $has_spark ) : ?>
				<div class="lnkup-stat-card__spark" aria-hidden="true"><span style="width:<?php echo esc_attr( $spark_pct ); ?>"></span></div>
			<?php endif; ?>
		</div>
		<span class="lnkup-stat-card__icon" aria-hidden="true"><?php echo $icon_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
	</<?php echo $href ? 'a' : 'div'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php
	return ob_get_clean();
}

/**
 * Executive page hero with KPI, metrics, and CTAs.
 *
 * @param array $args title, subtitle, kpi, kpi_label, metrics[], status_html, primary_cta{label,href}, secondary_cta{label,href}, ring_pct.
 */
function lnkup_executive_hero( array $args ): string {
	$title    = (string) ( $args['title'] ?? '' );
	$subtitle = (string) ( $args['subtitle'] ?? '' );
	$kpi      = (string) ( $args['kpi'] ?? '' );
	$kpi_lbl  = (string) ( $args['kpi_label'] ?? '' );
	$metrics  = is_array( $args['metrics'] ?? null ) ? $args['metrics'] : array();
	$status   = (string) ( $args['status_html'] ?? '' );
	$primary  = is_array( $args['primary_cta'] ?? null ) ? $args['primary_cta'] : array();
	$secondary = is_array( $args['secondary_cta'] ?? null ) ? $args['secondary_cta'] : array();
	$ring_pct = isset( $args['ring_pct'] ) ? (int) $args['ring_pct'] : null;

	ob_start();
	?>
	<header class="lnkup-executive-hero lnkup-surface--hero">
		<div class="lnkup-executive-hero__top">
			<div>
				<h1 class="lnkup-page-header__title"><?php echo esc_html( $title ); ?></h1>
				<?php if ( $subtitle ) : ?>
					<p class="lnkup-page-header__subtitle"><?php echo esc_html( $subtitle ); ?></p>
				<?php endif; ?>
				<?php if ( $kpi ) : ?>
					<p class="lnkup-executive-hero__kpi">
						<?php if ( $kpi_lbl ) : ?>
							<span class="lnkup-type-label"><?php echo esc_html( $kpi_lbl ); ?></span><br>
						<?php endif; ?>
						<span class="lnkup-type-display"><?php echo esc_html( $kpi ); ?></span>
					</p>
				<?php endif; ?>
				<?php if ( $status ) : ?>
					<div class="lnkup-executive-hero__status"><?php echo $status; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
				<?php endif; ?>
			</div>
			<?php if ( null !== $ring_pct ) : ?>
				<div class="lnkup-compliance-ring" style="--progress: <?php echo esc_attr( (string) min( 100, max( 0, $ring_pct ) ) ); ?>%" role="img" aria-label="<?php echo esc_attr( sprintf( __( '%d percent', 'lnkup-marketplace' ), $ring_pct ) ); ?>">
					<span class="lnkup-compliance-ring__value"><?php echo esc_html( (string) $ring_pct ); ?>%</span>
				</div>
			<?php endif; ?>
		</div>
		<?php if ( $metrics ) : ?>
			<div class="lnkup-executive-hero__metrics">
				<?php foreach ( $metrics as $metric ) : ?>
					<?php if ( empty( $metric['label'] ) ) { continue; } ?>
					<span class="lnkup-executive-hero__metric">
						<span class="lnkup-type-meta"><?php echo esc_html( (string) $metric['label'] ); ?></span>
						<strong><?php echo esc_html( (string) ( $metric['value'] ?? '' ) ); ?></strong>
					</span>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
		<?php if ( ! empty( $primary['label'] ) || ! empty( $secondary['label'] ) ) : ?>
			<div class="lnkup-executive-hero__ctas">
				<?php if ( ! empty( $primary['label'] ) && ! empty( $primary['href'] ) ) : ?>
					<a href="<?php echo esc_url( $primary['href'] ); ?>" class="lnkup-btn lnkup-btn--primary"><?php echo esc_html( $primary['label'] ); ?></a>
				<?php endif; ?>
				<?php if ( ! empty( $secondary['label'] ) && ! empty( $secondary['href'] ) ) : ?>
					<a href="<?php echo esc_url( $secondary['href'] ); ?>" class="lnkup-btn lnkup-btn--ghost"><?php echo esc_html( $secondary['label'] ); ?></a>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</header>
	<?php
	return ob_get_clean();
}

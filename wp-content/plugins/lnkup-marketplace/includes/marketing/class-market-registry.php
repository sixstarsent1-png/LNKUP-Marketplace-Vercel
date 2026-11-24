<?php
/**
 * Market intelligence layer over the city market option (lnkup_city_markets).
 *
 * Status is computed from live inventory (active, emerging, prelaunch, inactive) with an optional
 * admin override. Health numbers are counted from marketplace records; estimated potential is
 * never shown as activity. Also serves market alerts (saved searches), anonymous category demand
 * signals (aggregate analytics, no personal data), nearby markets and city + category eligibility.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Market_Registry {

	const STATUSES          = array( 'active', 'emerging', 'prelaunch', 'inactive' );
	const ACTIVE_MIN_EVENTS = 3;
	const CATEGORY_MIN      = 3;
	const NEARBY_MILES      = 300;
	const ALERT_COOKIE      = 'lnkup_market_alert';
	const SNAPSHOT_HOOK     = 'lnkup_market_health_daily';
	const DEMAND_RATE       = 20;

	/** @var array<string,mixed> */
	private static array $memo = array();

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'template_redirect', array( __CLASS__, 'remember_alert_intent' ), 2 );
		add_action( 'lnkup_user_registered', array( __CLASS__, 'on_registered' ), 20, 2 );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( self::SNAPSHOT_HOOK, array( __CLASS__, 'daily_snapshots' ) );
	}

	/**
	 * Market snapshots used to run only when the marketplace phase was >= 1c. Run them daily
	 * regardless, unless the marketplace cron already does.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::SNAPSHOT_HOOK ) ) {
			wp_schedule_event( strtotime( 'tomorrow 05:40' ), 'daily', self::SNAPSHOT_HOOK );
		}
	}

	public static function daily_snapshots(): void {
		if ( wp_next_scheduled( 'lnkup_marketplace_daily' ) ) {
			return;
		}
		if ( class_exists( 'LNKup_Match_Market' ) && method_exists( 'LNKup_Match_Market', 'daily' ) ) {
			LNKup_Match_Market::daily();
		}
		if ( class_exists( 'LNKup_Org_Supply_Intel' ) && method_exists( 'LNKup_Org_Supply_Intel', 'build_snapshot' ) ) {
			LNKup_Org_Supply_Intel::build_snapshot();
		}
	}

	/**
	 * Registry fields with defaults (merged over the stored market).
	 *
	 * @param array<string,mixed> $market Market.
	 * @return array<string,mixed>
	 */
	public static function with_defaults( array $market ): array {
		$market['lat']            = isset( $market['lat'] ) && is_numeric( $market['lat'] ) ? (float) $market['lat'] : null;
		$market['lng']            = isset( $market['lng'] ) && is_numeric( $market['lng'] ) ? (float) $market['lng'] : null;
		$market['metro']          = (string) ( $market['metro'] ?? '' );
		$market['nearby']         = array_values( array_filter( array_map( 'sanitize_title', (array) ( $market['nearby'] ?? array() ) ) ) );
		$market['content']        = (string) ( $market['content'] ?? '' );
		$market['category_pages'] = array_values( array_filter( array_map( 'sanitize_title', (array) ( $market['category_pages'] ?? array() ) ) ) );
		$market['status_override'] = in_array( $market['status_override'] ?? '', self::STATUSES, true ) ? (string) $market['status_override'] : '';
		return $market;
	}

	/**
	 * Index rows for open listings in a city.
	 *
	 * @return array<int,array<string,string>>
	 */
	private static function city_rows( string $city, string $state ): array {
		$key = 'rows' . strtolower( $city ) . '|' . strtoupper( $state );
		if ( isset( self::$memo[ $key ] ) ) {
			return self::$memo[ $key ];
		}
		$rows = array();
		if ( '' !== $city && class_exists( 'LNKup_Marketplace_Index' ) ) {
			global $wpdb;
			$table = LNKup_Marketplace_Index::table_name();
			$today = current_time( 'Y-m-d' );
			$sql   = "SELECT idx.opportunity_id, idx.event_id, idx.organizer_user_id, idx.categories, idx.event_date, idx.created_at FROM {$table} idx
				INNER JOIN {$wpdb->posts} ev ON ev.ID = idx.event_id AND ev.post_status = 'publish'
				INNER JOIN {$wpdb->posts} op ON op.ID = idx.opportunity_id AND op.post_status = 'publish'
				WHERE idx.city = %s AND idx.spots_remaining > 0 AND ( idx.event_date IS NULL OR idx.event_date >= %s ) AND ( idx.deadline IS NULL OR idx.deadline >= %s )";
			$args  = array( $city, $today, $today );
			if ( '' !== $state ) {
				$sql   .= ' AND UPPER(idx.state) = %s';
				$args[] = strtoupper( $state );
			}
			$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		}
		self::$memo[ $key ] = $rows;
		return $rows;
	}

	/**
	 * Live health for a market (counted, never estimated).
	 *
	 * @param array<string,mixed> $market Market.
	 * @return array<string,int>
	 */
	public static function health( array $market ): array {
		$rows       = self::city_rows( (string) $market['city'], (string) $market['state'] );
		$events     = array();
		$organizers = array();
		foreach ( $rows as $row ) {
			$events[ (int) $row['event_id'] ] = true;
			if ( (int) $row['organizer_user_id'] > 0 ) {
				$organizers[ (int) $row['organizer_user_id'] ] = true;
			}
		}
		$vendors = (int) ( new WP_Query(
			array(
				'post_type'      => 'lnkup_vendor_profile',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array( array( 'key' => '_lnkup_city', 'value' => (string) $market['city'], 'compare' => '=' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_query
			)
		) )->found_posts;
		return array(
			'open_events'        => count( $events ),
			'open_opportunities' => count( $rows ),
			'active_organizers'  => count( $organizers ),
			'vendors_based'      => $vendors,
		);
	}

	/**
	 * @param array<string,mixed> $market Market.
	 */
	public static function status( array $market ): string {
		if ( empty( $market['enabled'] ) || 'paused' === ( $market['launch_status'] ?? '' ) ) {
			return 'inactive';
		}
		$override = (string) ( $market['status_override'] ?? '' );
		if ( in_array( $override, self::STATUSES, true ) ) {
			return $override;
		}
		$open = count( array_unique( wp_list_pluck( self::city_rows( (string) $market['city'], (string) $market['state'] ), 'event_id' ) ) );
		if ( $open >= self::ACTIVE_MIN_EVENTS ) {
			return 'active';
		}
		if ( $open > 0 || in_array( $market['launch_status'] ?? '', array( 'recruiting_vendors', 'recruiting_organizers', 'beta', 'active' ), true ) ) {
			return 'emerging';
		}
		return 'prelaunch';
	}

	public static function status_label( string $status ): string {
		$labels = array(
			'active'    => __( 'Events booking vendors now', 'lnkup-marketplace' ),
			'emerging'  => __( 'Growing market', 'lnkup-marketplace' ),
			'prelaunch' => __( 'Coming soon', 'lnkup-marketplace' ),
			'inactive'  => __( 'Paused', 'lnkup-marketplace' ),
		);
		return $labels[ $status ] ?? $labels['prelaunch'];
	}

	/**
	 * Categories wanted by open events in the market, with counts and eligibility.
	 *
	 * @param array<string,mixed> $market Market.
	 * @return array<int,array{label:string,slug:string,count:int,terms:string[],eligible:bool,url:string}>
	 */
	public static function categories( array $market ): array {
		$counts = array();
		$terms  = array();
		foreach ( self::city_rows( (string) $market['city'], (string) $market['state'] ) as $row ) {
			$names = json_decode( (string) $row['categories'], true );
			foreach ( is_array( $names ) ? $names : array() as $name ) {
				$label = function_exists( 'lnkup_canonical_category_label' ) ? lnkup_canonical_category_label( (string) $name ) : (string) $name;
				if ( '' === $label ) {
					continue;
				}
				$counts[ $label ][ (int) $row['event_id'] ] = true;
				$terms[ $label ][ (string) $name ]          = true;
			}
		}
		$out = array();
		foreach ( $counts as $label => $events ) {
			$slug     = sanitize_title( (string) $label );
			$eligible = self::category_eligible( $market, $slug, count( $events ) );
			$out[]    = array(
				'label'    => (string) $label,
				'slug'     => $slug,
				'count'    => count( $events ),
				'terms'    => array_keys( $terms[ $label ] ),
				'eligible' => $eligible,
				'url'      => $eligible
					? self::category_url( (string) $market['slug'], $slug )
					: add_query_arg( array( 'city' => $market['city'], 'state' => $market['state'], 'categories' => $label ), function_exists( 'lnkup_page_url_helper' ) ? lnkup_page_url_helper( 'opportunities' ) : home_url( '/opportunities/' ) ),
			);
		}
		usort( $out, static fn( $a, $b ) => $b['count'] <=> $a['count'] ?: strcmp( $a['label'], $b['label'] ) );
		return $out;
	}

	/**
	 * City + category pages are indexable only when the market itself is, the category has at least
	 * CATEGORY_MIN open events in the city, and staff enabled that pair (no mass generation).
	 *
	 * @param array<string,mixed> $market Market.
	 */
	public static function category_eligible( array $market, string $slug, ?int $count = null ): bool {
		if ( ! class_exists( 'LNKup_Marketing_Flags' ) || ! LNKup_Marketing_Flags::enabled( 'market_category_pages' ) ) {
			return false;
		}
		if ( ! in_array( $slug, (array) ( $market['category_pages'] ?? array() ), true ) ) {
			return false;
		}
		if ( ! class_exists( 'LNKup_Index_Policy' ) || ! LNKup_Index_Policy::market( $market )['index'] ) {
			return false;
		}
		if ( null === $count ) {
			foreach ( self::categories_raw( $market ) as $label => $n ) {
				if ( sanitize_title( $label ) === $slug ) {
					$count = $n;
				}
			}
		}
		return (int) $count >= self::CATEGORY_MIN;
	}

	/**
	 * @param array<string,mixed> $market Market.
	 * @return array<string,int>
	 */
	private static function categories_raw( array $market ): array {
		$out = array();
		foreach ( self::city_rows( (string) $market['city'], (string) $market['state'] ) as $row ) {
			$names = json_decode( (string) $row['categories'], true );
			$seen  = array();
			foreach ( is_array( $names ) ? $names : array() as $name ) {
				$label = function_exists( 'lnkup_canonical_category_label' ) ? lnkup_canonical_category_label( (string) $name ) : (string) $name;
				if ( '' !== $label && ! isset( $seen[ $label ] ) ) {
					$seen[ $label ]        = true;
					$out[ $label ][ (int) $row['event_id'] ] = true;
				}
			}
		}
		return array_map( 'count', $out );
	}

	public static function category_url( string $market_slug, string $category_slug ): string {
		return home_url( '/markets/' . sanitize_title( $market_slug ) . '/' . sanitize_title( $category_slug ) . '/' );
	}

	public static function digest_url( string $market_slug ): string {
		return home_url( '/markets/' . sanitize_title( $market_slug ) . '/this-week/' );
	}

	/**
	 * Organizers with open events in the market (public identity only).
	 *
	 * @param array<string,mixed> $market Market.
	 * @return array<int,array{name:string,url:string,logo:string,events:int,verified:bool}>
	 */
	public static function featured_organizers( array $market, int $limit = 6 ): array {
		$count = array();
		foreach ( self::city_rows( (string) $market['city'], (string) $market['state'] ) as $row ) {
			$org = (int) $row['organizer_user_id'];
			if ( $org > 0 ) {
				$count[ $org ][ (int) $row['event_id'] ] = true;
			}
		}
		$out = array();
		foreach ( $count as $user_id => $events ) {
			$name = trim( (string) get_user_meta( $user_id, '_lnkup_organizer_public_name', true ) );
			if ( '' === $name || ! class_exists( 'LNKup_Organizer_Onboarding' ) ) {
				continue;
			}
			$logo  = (int) get_user_meta( $user_id, '_lnkup_organizer_logo_id', true );
			$out[] = array(
				'name'     => $name,
				'url'      => LNKup_Organizer_Onboarding::organizer_public_url( (int) $user_id ),
				'logo'     => $logo ? (string) wp_get_attachment_image_url( $logo, 'thumbnail' ) : '',
				'events'   => count( $events ),
				'verified' => method_exists( 'LNKup_Organizer_Onboarding', 'organizer_is_publicly_verified' ) ? (bool) LNKup_Organizer_Onboarding::organizer_is_publicly_verified( (int) $user_id ) : false,
			);
		}
		usort( $out, static fn( $a, $b ) => $b['events'] <=> $a['events'] );
		return array_slice( $out, 0, $limit );
	}

	/**
	 * Nearby enabled markets: staff-listed first, then within NEARBY_MILES (coordinates), then same
	 * state. Markets with open events sort first.
	 *
	 * @param array<string,mixed> $market Market.
	 * @return array<int,array{slug:string,name:string,url:string,open_events:int}>
	 */
	public static function nearby( array $market, int $limit = 6 ): array {
		$out = array();
		foreach ( LNKup_Market_Pages::get_all_markets() as $slug => $raw ) {
			if ( ! is_array( $raw ) || empty( $raw['enabled'] ) ) {
				continue;
			}
			$other = self::with_defaults( LNKup_Market_Pages::get_market_by_slug( (string) ( $raw['slug'] ?? $slug ) ) ?? array() );
			if ( empty( $other['slug'] ) || $other['slug'] === $market['slug'] ) {
				continue;
			}
			$listed = in_array( $other['slug'], (array) $market['nearby'], true );
			$miles  = ( null !== $market['lat'] && null !== $other['lat'] && class_exists( 'LNKup_Geocoder' ) )
				? LNKup_Geocoder::distance_miles( (float) $market['lat'], (float) $market['lng'], (float) $other['lat'], (float) $other['lng'] )
				: null;
			$close  = $listed || ( null !== $miles && $miles <= self::NEARBY_MILES ) || ( null === $miles && $other['state'] === $market['state'] );
			if ( ! $close ) {
				continue;
			}
			$out[] = array(
				'slug'        => (string) $other['slug'],
				'name'        => (string) $other['display_name'],
				'url'         => LNKup_Market_Pages::market_url( (string) $other['slug'] ),
				'open_events' => LNKup_Index_Policy::open_events_in_city( (string) $other['city'], (string) $other['state'] ),
				'rank'        => ( $listed ? 0 : 1 ) * 100000 + (int) ( $miles ?? 99999 ),
			);
		}
		usort( $out, static fn( $a, $b ) => ( $b['open_events'] > 0 ) <=> ( $a['open_events'] > 0 ) ?: $a['rank'] <=> $b['rank'] );
		return array_map( static function ( $row ) {
			unset( $row['rank'] );
			return $row;
		}, array_slice( $out, 0, $limit ) );
	}

	/**
	 * Event card view-models for a market (optionally one category or a date window).
	 *
	 * @param array<string,mixed> $market Market.
	 * @param array<string,mixed> $args   terms (string[]), date_to (Y-m-d), sort, limit, created_after.
	 * @return array<int,array<string,mixed>>
	 */
	public static function cards( array $market, array $args = array() ): array {
		if ( ! class_exists( 'LNKup_Discovery' ) || ! class_exists( 'LNKup_Public_Discovery' ) || ! function_exists( 'lnkup_group_opportunities_by_event' ) ) {
			return array();
		}
		$limit  = max( 1, min( 24, (int) ( $args['limit'] ?? 6 ) ) );
		$search = array(
			'city'      => (string) $market['city'],
			'state'     => (string) $market['state'],
			'has_spots' => true,
			'sort'      => (string) ( $args['sort'] ?? 'soonest' ),
			'per_page'  => min( 48, $limit * 4 ),
			'page'      => 1,
		);
		if ( ! empty( $args['terms'] ) ) {
			$search['categories'] = (array) $args['terms'];
		}
		if ( ! empty( $args['date_to'] ) ) {
			$search['date_from'] = current_time( 'Y-m-d' );
			$search['date_to']   = (string) $args['date_to'];
		}
		if ( ! empty( $args['created_after'] ) ) {
			$search['created_after'] = (string) $args['created_after'];
		}
		try {
			$result = LNKup_Discovery::search( $search );
		} catch ( Throwable $e ) {
			return array();
		}
		$cards = array();
		foreach ( lnkup_group_opportunities_by_event( (array) ( $result['items'] ?? array() ) ) as $slots ) {
			$card = LNKup_Public_Discovery::event_card( $slots );
			if ( $card ) {
				$cards[] = $card;
			}
			if ( count( $cards ) >= $limit ) {
				break;
			}
		}
		return $cards;
	}

	/* ------------------------------------------------------------ Alerts & demand */

	public static function routes(): void {
		register_rest_route(
			'lnkup/v1',
			'/markets/(?P<slug>[a-z0-9-]+)/alert',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_alert' ),
				'permission_callback' => static fn() => is_user_logged_in(),
				'args'                => array( 'category' => array( 'type' => 'string', 'maxLength' => 60, 'sanitize_callback' => 'sanitize_title' ) ),
			)
		);
		register_rest_route(
			'lnkup/v1',
			'/markets/(?P<slug>[a-z0-9-]+)/demand',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_demand' ),
				'permission_callback' => '__return_true',
				'args'                => array( 'category' => array( 'type' => 'string', 'required' => true, 'maxLength' => 60, 'sanitize_callback' => 'sanitize_title' ) ),
			)
		);
	}

	/**
	 * Create (or reuse) a saved search for this market, optionally one category.
	 *
	 * @return array{created:bool,id:string}|WP_Error
	 */
	public static function create_alert( int $user_id, string $slug, string $category = '' ) {
		$market = LNKup_Market_Pages::get_market_by_slug( $slug );
		if ( ! $market ) {
			return new WP_Error( 'lnkup_market_not_found', __( 'Market not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( ! class_exists( 'LNKup_Saved_Searches' ) || ! method_exists( 'LNKup_Saved_Searches', 'create' ) ) {
			return new WP_Error( 'lnkup_alerts_unavailable', __( 'Alerts are not available right now.', 'lnkup-marketplace' ), array( 'status' => 503 ) );
		}
		$filters = array( 'city' => (string) $market['city'], 'state' => (string) $market['state'] );
		$label   = '';
		if ( '' !== $category ) {
			foreach ( self::categories( $market ) as $cat ) {
				if ( $cat['slug'] === $category ) {
					$label = $cat['label'];
				}
			}
			if ( '' === $label && function_exists( 'lnkup_canonical_vendor_categories' ) ) {
				foreach ( lnkup_canonical_vendor_categories() as $canonical ) {
					if ( sanitize_title( $canonical ) === $category ) {
						$label = $canonical;
					}
				}
			}
			if ( '' !== $label ) {
				$filters['categories'] = array( $label );
			}
		}
		$name   = '' !== $label
			/* translators: 1: category, 2: market */
			? sprintf( __( '%1$s in %2$s', 'lnkup-marketplace' ), $label, $market['display_name'] )
			/* translators: %s: market */
			: sprintf( __( 'Events in %s', 'lnkup-marketplace' ), $market['display_name'] );
		$result = LNKup_Saved_Searches::create( $user_id, $name, $filters, array( 'frequency' => 'weekly' ) );
		if ( is_wp_error( $result ) ) {
			if ( 'duplicate_search' === $result->get_error_code() ) {
				$data = (array) $result->get_error_data();
				return array( 'created' => false, 'id' => (string) ( $data['existing_id'] ?? '' ) );
			}
			return $result;
		}
		if ( class_exists( 'LNKup_Marketplace_Analytics' ) ) {
			LNKup_Marketplace_Analytics::track( 'market_alert_created', array( 'market' => $slug, 'category' => $label ), $user_id );
		}
		return array( 'created' => true, 'id' => is_array( $result ) ? (string) ( $result['id'] ?? '' ) : (string) $result );
	}

	public static function rest_alert( WP_REST_Request $request ) {
		$result = self::create_alert( get_current_user_id(), (string) $request['slug'], (string) $request->get_param( 'category' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	/**
	 * Anonymous "I sell X and want events here" signal: aggregate only, rate limited per IP hash.
	 */
	public static function rest_demand( WP_REST_Request $request ) {
		$market = LNKup_Market_Pages::get_market_by_slug( (string) $request['slug'] );
		if ( ! $market ) {
			return new WP_Error( 'lnkup_market_not_found', __( 'Market not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$category = (string) $request->get_param( 'category' );
		$known    = function_exists( 'lnkup_canonical_vendor_categories' ) ? array_map( 'sanitize_title', lnkup_canonical_vendor_categories() ) : array();
		if ( ! in_array( $category, $known, true ) ) {
			return new WP_Error( 'lnkup_invalid_category', __( 'Unknown category.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key  = 'lnkup_demand_' . substr( hash_hmac( 'sha256', $ip . gmdate( 'Y-m-d' ), wp_salt( 'nonce' ) ), 0, 20 );
		$seen = (array) get_transient( $key );
		if ( count( $seen ) >= self::DEMAND_RATE || in_array( $market['slug'] . '|' . $category, $seen, true ) ) {
			return rest_ensure_response( array( 'recorded' => false ) );
		}
		$seen[] = $market['slug'] . '|' . $category;
		set_transient( $key, $seen, DAY_IN_SECONDS );
		if ( class_exists( 'LNKup_Marketplace_Analytics' ) ) {
			LNKup_Marketplace_Analytics::track( 'market_demand_signal', array( 'market' => $market['slug'], 'city' => $market['city'], 'state' => $market['state'], 'category' => $category ), get_current_user_id() );
		}
		return rest_ensure_response( array( 'recorded' => true ) );
	}

	/**
	 * Demand signals per category for a market over N days (aggregate).
	 *
	 * @return array<string,int>
	 */
	public static function demand( string $market_slug, int $days = 90 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'lnkup_analytics_events';
		$rows  = $wpdb->get_col( $wpdb->prepare( "SELECT event_data FROM {$table} WHERE event_type = 'market_demand_signal' AND created_at >= %s AND event_data LIKE %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ), '%"market":"' . $wpdb->esc_like( $market_slug ) . '"%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$out   = array();
		foreach ( (array) $rows as $json ) {
			$data = json_decode( (string) $json, true );
			$cat  = is_array( $data ) ? (string) ( $data['category'] ?? '' ) : '';
			if ( '' !== $cat ) {
				$out[ $cat ] = ( $out[ $cat ] ?? 0 ) + 1;
			}
		}
		arsort( $out );
		return $out;
	}

	/**
	 * Sign-up with ?alert=1&market=slug[&category=] keeps the intent for after registration.
	 */
	public static function remember_alert_intent(): void {
		if ( ! is_page( 'sign-up' ) || empty( $_GET['alert'] ) || empty( $_GET['market'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$slug     = sanitize_title( wp_unslash( (string) $_GET['market'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$category = isset( $_GET['category'] ) ? sanitize_title( wp_unslash( (string) $_GET['category'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $slug || ! LNKup_Market_Pages::get_market_by_slug( $slug ) ) {
			return;
		}
		setcookie( self::ALERT_COOKIE, $slug . '|' . $category, array( 'expires' => time() + HOUR_IN_SECONDS, 'path' => COOKIEPATH ?: '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
	}

	/**
	 * @param int    $user_id User.
	 * @param string $role    Role.
	 */
	public static function on_registered( $user_id, $role = '' ): void {
		if ( empty( $_COOKIE[ self::ALERT_COOKIE ] ) || ! in_array( (string) $role, array( 'lnkup_vendor', 'vendor', '' ), true ) ) {
			return;
		}
		$parts = explode( '|', sanitize_text_field( wp_unslash( (string) $_COOKIE[ self::ALERT_COOKIE ] ) ), 2 );
		self::create_alert( (int) $user_id, sanitize_title( $parts[0] ), sanitize_title( $parts[1] ?? '' ) );
		setcookie( self::ALERT_COOKIE, '', array( 'expires' => time() - HOUR_IN_SECONDS, 'path' => COOKIEPATH ?: '/' ) );
	}

	public static function flush(): void {
		self::$memo = array();
	}
}

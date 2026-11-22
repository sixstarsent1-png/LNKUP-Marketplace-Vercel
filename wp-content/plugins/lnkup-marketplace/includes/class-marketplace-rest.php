<?php
/**
 * Marketplace REST API.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Marketplace_REST {

	const NS = 'lnkup/v1';

	/**
	 * Init routes.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register REST routes.
	 */
	public static function register_routes() {
		$read = array( 'methods' => 'GET', 'permission_callback' => '__return_true' );

		register_rest_route( self::NS, '/marketplace/search', array_merge( $read, array(
			'callback' => array( __CLASS__, 'search' ),
		) ) );

		register_rest_route( self::NS, '/marketplace/suggestions', array_merge( $read, array(
			'callback' => array( __CLASS__, 'suggestions' ),
		) ) );

		register_rest_route( self::NS, '/marketplace/sections/trending', array_merge( $read, array(
			'callback' => array( __CLASS__, 'trending' ),
		) ) );

		register_rest_route( self::NS, '/marketplace/sections/recommended', array_merge( $read, array(
			'callback' => array( __CLASS__, 'recommended' ),
		) ) );

		register_rest_route( self::NS, '/marketplace/collections', array_merge( $read, array(
			'callback' => array( __CLASS__, 'collections_list' ),
		) ) );

		register_rest_route( self::NS, '/marketplace/collections/(?P<slug>[a-z0-9-]+)', array_merge( $read, array(
			'callback' => array( __CLASS__, 'collection' ),
		) ) );

		register_rest_route( self::NS, '/marketplace/compare', array_merge( $read, array(
			'callback' => array( __CLASS__, 'compare' ),
		) ) );

		register_rest_route( self::NS, '/marketplace/analytics', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'analytics' ),
			'permission_callback' => array( __CLASS__, 'admin_permission' ),
		) );

		register_rest_route( self::NS, '/marketplace/track/view', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'track_view' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( self::NS, '/marketplace/track/event', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'track_event' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( self::NS, '/marketplace/favorites', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_favorites' ), 'permission_callback' => array( __CLASS__, 'vendor_permission' ) ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'toggle_favorite' ), 'permission_callback' => array( __CLASS__, 'vendor_permission' ) ),
		) );

		register_rest_route( self::NS, '/marketplace/saved-searches', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_saved_searches' ), 'permission_callback' => array( __CLASS__, 'saved_search_vendor_permission' ) ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save_search' ), 'permission_callback' => array( __CLASS__, 'saved_search_vendor_permission' ) ),
		) );

		register_rest_route( self::NS, '/marketplace/saved-searches/(?P<id>[a-f0-9-]+)', array(
			array(
				'methods'             => 'PATCH',
				'callback'            => array( __CLASS__, 'update_saved_search' ),
				'permission_callback' => array( __CLASS__, 'saved_search_vendor_permission' ),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'delete_saved_search' ),
				'permission_callback' => array( __CLASS__, 'saved_search_vendor_permission' ),
			),
		) );

		register_rest_route( self::NS, '/marketplace/saved-searches/(?P<id>[a-f0-9-]+)/run', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'run_saved_search' ),
			'permission_callback' => array( __CLASS__, 'saved_search_vendor_permission' ),
		) );

		register_rest_route( self::NS, '/marketplace/map-pins', array_merge( $read, array(
			'callback' => array( __CLASS__, 'map_pins' ),
		) ) );

		register_rest_route( self::NS, '/marketplace/apply/(?P<opportunity_id>\d+)', array_merge( $read, array(
			'callback' => array( __CLASS__, 'apply_fragment' ),
		) ) );
	}

	/**
	 * Parse request into discovery args.
	 */
	private static function parse_search_args( WP_REST_Request $request ) {
		$params = $request->get_params();
		$array_keys = array( 'categories', 'event_types', 'markets', 'doc_required' );
		foreach ( $array_keys as $key ) {
			if ( isset( $params[ $key ] ) && is_string( $params[ $key ] ) ) {
				$params[ $key ] = array_filter( explode( ',', $params[ $key ] ) );
			}
		}
		$bool_keys = array(
			'fee_free', 'indoor', 'outdoor', 'power_available', 'water_available',
			'food_truck_allowed', 'verified_organizer', 'featured_organizer',
			'instant_approval', 'has_spots', 'show_closed', 'newly_added',
			'closing_soon', 'almost_full', 'applications_open', 'doc_none',
			'include_external', 'high_demand', 'preferred_markets', 'previous_organizers',
		);
		foreach ( $bool_keys as $key ) {
			if ( isset( $params[ $key ] ) ) {
				$params[ $key ] = rest_sanitize_boolean( $params[ $key ] );
			}
		}
		if ( ! isset( $params['has_spots'] ) && empty( $params['show_closed'] ) ) {
			$params['has_spots'] = true;
		}
		return $params;
	}

	public static function search( WP_REST_Request $request ) {
		try {
			$args   = self::parse_search_args( $request );
			$result = lnkup_get_filtered_opportunities( $args );
			if ( ! is_array( $result ) ) {
				$result = array(
					'items'    => array(),
					'total'    => 0,
					'page'     => 1,
					'per_page' => 24,
					'pages'    => 0,
				);
			}
			try {
				if ( 0 === (int) ( $result['total'] ?? 0 ) && ! empty( $request->get_param( 'q' ) ) ) {
					LNKup_Marketplace_Analytics::track( 'search_no_results', array( 'q' => $request->get_param( 'q' ) ) );
				} else {
					LNKup_Marketplace_Analytics::track(
						'search',
						array(
							'q'     => $request->get_param( 'q' ) ?? '',
							'total' => $result['total'] ?? 0,
						)
					);
				}
				$filter_keys = array( 'city', 'state', 'date_preset', 'attendance_bucket', 'fee_max', 'categories' );
				foreach ( $filter_keys as $key ) {
					if ( ! empty( $args[ $key ] ) ) {
						LNKup_Marketplace_Analytics::track( 'filter_use', array( 'filter' => $key ) );
					}
				}
				foreach ( array( 'fee_free', 'indoor', 'outdoor', 'power_available', 'verified_organizer', 'instant_approval', 'has_spots' ) as $bool_key ) {
					if ( ! empty( $args[ $bool_key ] ) ) {
						LNKup_Marketplace_Analytics::track( 'filter_use', array( 'filter' => $bool_key ) );
					}
				}
				$user_id = get_current_user_id();
				if ( $user_id && ! empty( $request->get_param( 'q' ) ) ) {
					LNKup_Vendor_Personalization::add_search_history( $user_id, $request->get_param( 'q' ), $args );
				}
			} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Analytics / history must never fail the search response.
			}
			return rest_ensure_response( $result );
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( 'LNKup marketplace search failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return new WP_Error(
				'lnkup_search_failed',
				__( 'Search is temporarily unavailable. Please try again.', 'lnkup-marketplace' ),
				array( 'status' => 500 )
			);
		}
	}

	public static function suggestions( WP_REST_Request $request ) {
		$q = $request->get_param( 'q' ) ?? '';
		return rest_ensure_response( LNKup_Discovery::suggestions( $q, get_current_user_id() ) );
	}

	public static function trending( WP_REST_Request $request ) {
		$section = sanitize_key( $request->get_param( 'section' ) ?: 'trending' );
		$limit   = absint( $request->get_param( 'limit' ) ?: 8 );
		$items   = LNKup_Trending::get_section( $section, $limit );
		return rest_ensure_response( array(
			'section' => $section,
			'items'   => $items,
			'html'    => lnkup_render_opportunity_cards_batch( $items ),
		) );
	}

	public static function recommended( WP_REST_Request $request ) {
		$limit = absint( $request->get_param( 'limit' ) ?: 8 );
		$items = LNKup_Recommendations::get_for_user( get_current_user_id(), $limit );
		return rest_ensure_response( array(
			'items' => $items,
			'html'  => lnkup_render_opportunity_cards_batch( $items ),
		) );
	}

	public static function collections_list() {
		return rest_ensure_response( LNKup_Collections::list_all() );
	}

	public static function collection( WP_REST_Request $request ) {
		$slug  = sanitize_key( $request['slug'] );
		$limit = absint( $request->get_param( 'limit' ) ?: 12 );
		$items = LNKup_Collections::get( $slug, $limit );
		$defs  = LNKup_Collections::get_definitions();
		return rest_ensure_response( array(
			'slug'  => $slug,
			'label' => $defs[ $slug ]['label'] ?? $slug,
			'items' => $items,
			'html'  => lnkup_render_opportunity_cards_batch( $items ),
		) );
	}

	public static function compare( WP_REST_Request $request ) {
		$ids = $request->get_param( 'ids' );
		if ( is_string( $ids ) ) {
			$ids = array_map( 'absint', explode( ',', $ids ) );
		}
		$ids = array_slice( array_filter( (array) $ids ), 0, 4 );
		$items = array();
		foreach ( $ids as $id ) {
			$row = LNKup_Marketplace_Index::get_row( $id );
			if ( $row ) {
				$items[] = lnkup_format_index_row( $row, true );
			}
		}
		return rest_ensure_response( array( 'items' => $items ) );
	}

	public static function track_view( WP_REST_Request $request ) {
		$body   = $request->get_json_params() ?: $request->get_params();
		$opp_id = absint( $body['opportunity_id'] ?? 0 );
		$type   = sanitize_key( $body['type'] ?? 'opportunity' );
		if ( $opp_id ) {
			LNKup_Marketplace_Index::increment_view( $opp_id );
			LNKup_Vendor_Personalization::add_recently_viewed( get_current_user_id(), $type, $opp_id );
			LNKup_Marketplace_Analytics::track( 'view', array(), get_current_user_id(), $opp_id );
		}
		return rest_ensure_response( array( 'success' => true ) );
	}

	public static function track_event( WP_REST_Request $request ) {
		$type = sanitize_key( $request->get_param( 'event_type' ) ?: 'unknown' );
		$data = (array) $request->get_param( 'data' );
		$opp  = absint( $request->get_param( 'opportunity_id' ) );
		LNKup_Marketplace_Analytics::track( $type, $data, get_current_user_id(), $opp );
		return rest_ensure_response( array( 'success' => true ) );
	}

	public static function get_favorites() {
		$user_id = get_current_user_id();
		$favs    = LNKup_Vendor_Personalization::get_favorites( $user_id );
		return rest_ensure_response( array(
			'favorites' => $favs,
			'items'     => LNKup_Vendor_Personalization::get_favorite_opportunities( $user_id ),
		) );
	}

	public static function toggle_favorite( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$body    = $request->get_json_params() ?: $request->get_params();
		$type    = sanitize_key( $body['type'] ?? 'opportunities' );
		$id      = absint( $body['id'] ?? 0 );
		$added   = LNKup_Vendor_Personalization::toggle_favorite( $user_id, $type, $id );
		LNKup_Marketplace_Analytics::track( 'favorite', array( 'type' => $type, 'added' => $added ), $user_id, $id );
		return rest_ensure_response( array( 'favorited' => $added ) );
	}

	public static function get_saved_searches() {
		$user_id = get_current_user_id();
		return rest_ensure_response( array(
			'searches' => LNKup_Saved_Searches::get_for_user( $user_id ),
			'limit'    => LNKup_Saved_Searches::limit_per_vendor(),
		) );
	}

	public static function save_search( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$body    = $request->get_json_params() ?: $request->get_params();
		$name    = $body['name'] ?? __( 'Saved Search', 'lnkup-marketplace' );
		$filters = LNKup_Saved_Searches::sanitize_filters( (array) ( $body['filters'] ?? array() ) );
		$args    = array(
			'alert_enabled' => array_key_exists( 'alert_enabled', $body ) ? (bool) $body['alert_enabled'] : true,
			'frequency'     => sanitize_key( (string) ( $body['frequency'] ?? LNKup_Saved_Searches::default_frequency() ) ),
			'force'         => ! empty( $body['force'] ),
		);
		$search  = LNKup_Saved_Searches::create( $user_id, $name, $filters, $args );
		if ( is_wp_error( $search ) ) {
			$status = (int) ( $search->get_error_data()['status'] ?? 400 );
			return new WP_Error( $search->get_error_code(), $search->get_error_message(), array( 'status' => $status ) );
		}
		LNKup_Marketplace_Analytics::track( 'saved_search_created', array( 'search_id' => $search['id'] ?? '' ), $user_id );
		return rest_ensure_response( $search );
	}

	public static function update_saved_search( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$body    = $request->get_json_params() ?: $request->get_params();
		$data    = array();
		if ( isset( $body['name'] ) ) {
			$data['name'] = $body['name'];
		}
		if ( isset( $body['filters'] ) ) {
			$data['filters'] = LNKup_Saved_Searches::sanitize_filters( (array) $body['filters'] );
		}
		if ( array_key_exists( 'alert_enabled', $body ) ) {
			$data['alert_enabled'] = (bool) $body['alert_enabled'];
		}
		if ( isset( $body['frequency'] ) ) {
			$data['frequency'] = sanitize_key( (string) $body['frequency'] );
		}
		if ( isset( $body['status'] ) ) {
			$data['status'] = sanitize_key( (string) $body['status'] );
		}
		$result = LNKup_Saved_Searches::update( $user_id, $request['id'], $data );
		if ( is_wp_error( $result ) ) {
			$status = (int) ( $result->get_error_data()['status'] ?? 400 );
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => $status ) );
		}
		if ( isset( $data['status'] ) && LNKup_Saved_Searches::STATUS_PAUSED === $data['status'] ) {
			LNKup_Marketplace_Analytics::track( 'saved_search_paused', array( 'search_id' => $request['id'] ), $user_id );
		}
		return rest_ensure_response( $result );
	}

	public static function delete_saved_search( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$result  = LNKup_Saved_Searches::delete( $user_id, $request['id'] );
		if ( is_wp_error( $result ) ) {
			$status = (int) ( $result->get_error_data()['status'] ?? 400 );
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => $status ) );
		}
		LNKup_Marketplace_Analytics::track( 'saved_search_deleted', array( 'search_id' => $request['id'] ), $user_id );
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	public static function run_saved_search( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$body    = $request->get_json_params() ?: $request->get_params();
		$args    = array(
			'page'     => absint( $body['page'] ?? 1 ),
			'per_page' => absint( $body['per_page'] ?? 24 ),
		);
		if ( ! empty( $body['new_only'] ) ) {
			$search = LNKup_Saved_Searches::get_by_id( $user_id, $request['id'] );
			if ( $search ) {
				$args['created_after'] = $search['last_checked_at'] ?? $search['created_at'] ?? null;
			}
		}
		$result = LNKup_Saved_Searches::run( $user_id, $request['id'], $args );
		if ( is_wp_error( $result ) ) {
			$status = (int) ( $result->get_error_data()['status'] ?? 400 );
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => $status ) );
		}
		$result['html'] = lnkup_render_opportunity_cards_batch( $result['items'] ?? array() );
		LNKup_Marketplace_Analytics::track( 'saved_search_run', array( 'search_id' => $request['id'], 'total' => $result['total'] ?? 0 ), $user_id );
		return rest_ensure_response( $result );
	}

	public static function map_pins( WP_REST_Request $request ) {
		if ( ! class_exists( 'LNKup_Map_REST' ) ) {
			return rest_ensure_response( array( 'pins' => array() ) );
		}
		return rest_ensure_response( array( 'pins' => LNKup_Map_REST::build_discovery_pins( $request ) ) );
	}

	/**
	 * Apply modal HTML fragment.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function apply_fragment( WP_REST_Request $request ) {
		if ( ! lnkup_can_browse_marketplace() ) {
			return new WP_Error(
				'marketplace_closed',
				sprintf(
					/* translators: %s: launch date */
					__( 'Applications open on %s.', 'lnkup-marketplace' ),
					lnkup_get_launch_date_label()
				),
				array( 'status' => 403 )
			);
		}
		$opportunity_id = absint( $request['opportunity_id'] );
		$html           = lnkup_render_apply_form_html( $opportunity_id );
		if ( ! $html ) {
			return new WP_Error( 'not_found', __( 'Opportunity not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$context = LNKup_Applications::build_apply_context( $opportunity_id );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$event_id = (int) get_post_meta( $opportunity_id, '_lnkup_event_id', true );
		return rest_ensure_response(
			array(
				'html'           => $html,
				'opportunity_id' => $opportunity_id,
				'event_name'     => $event_id ? get_the_title( $event_id ) : get_the_title( $opportunity_id ),
				'eligibility'    => $context['eligibility'],
				'warnings'       => $context['warnings'],
				'button_state'   => $context['button_state'],
			)
		);
	}

	public static function analytics() {
		return rest_ensure_response( LNKup_Marketplace_Analytics::get_dashboard_metrics() );
	}

	public static function vendor_permission() {
		return is_user_logged_in();
	}

	/**
	 * Vendor-only permission for saved search routes.
	 */
	public static function saved_search_vendor_permission() {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$user_id = get_current_user_id();
		return LNKup_Roles::is_vendor( $user_id ) || LNKup_Capabilities::user_has_manage_all( $user_id );
	}

	public static function admin_permission() {
		return LNKup_Capabilities::user_has_manage_all() || current_user_can( 'lnkup_manage_settings' );
	}
}

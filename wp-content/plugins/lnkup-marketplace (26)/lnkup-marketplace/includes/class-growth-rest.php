<?php
/**
 * Vendor growth platform REST API.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Growth_REST {

	const NS = 'lnkup/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		$vendor = array( 'permission_callback' => array( __CLASS__, 'vendor_permission' ) );
		$admin  = array( 'permission_callback' => array( __CLASS__, 'admin_permission' ) );

		register_rest_route( self::NS, '/growth/dashboard', array_merge( $vendor, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'dashboard' ),
		) ) );

		register_rest_route( self::NS, '/growth/performance', array_merge( $vendor, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'performance' ),
		) ) );

		register_rest_route( self::NS, '/growth/revenue', array_merge( $vendor, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'revenue' ),
		) ) );

		register_rest_route( self::NS, '/growth/revenue/by-(?P<dimension>[a-z]+)', array_merge( $vendor, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'revenue_by' ),
		) ) );

		register_rest_route( self::NS, '/growth/applications', array_merge( $vendor, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'applications' ),
		) ) );

		register_rest_route( self::NS, '/growth/relationships', array_merge( $vendor, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'relationships' ),
		) ) );

		register_rest_route( self::NS, '/growth/journey', array_merge( $vendor, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'journey' ),
		) ) );

		register_rest_route( self::NS, '/growth/benchmarks', array_merge( $vendor, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'benchmarks' ),
		) ) );

		register_rest_route( self::NS, '/growth/profile-strength', array_merge( $vendor, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'profile_strength' ),
		) ) );

		register_rest_route( self::NS, '/vendor/profile-strength', array_merge( $vendor, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'profile_strength' ),
		) ) );

		register_rest_route( self::NS, '/growth/achievements', array_merge( $vendor, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'achievements' ),
		) ) );

		register_rest_route( self::NS, '/growth/goals', array_merge( $vendor, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'goals_list' ),
		) ) );
		register_rest_route( self::NS, '/growth/goals', array_merge( $vendor, array(
			'methods'  => WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'goals_create' ),
		) ) );
		register_rest_route( self::NS, '/growth/goals/(?P<id>\d+)', array_merge( $vendor, array(
			'methods'  => 'PATCH',
			'callback' => array( __CLASS__, 'goals_update' ),
		) ) );

		register_rest_route( self::NS, '/growth/recommendations', array_merge( $vendor, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'recommendations' ),
		) ) );
		register_rest_route( self::NS, '/growth/recommendations/(?P<id>\d+)/dismiss', array_merge( $vendor, array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'recommendations_dismiss' ),
		) ) );

		register_rest_route( self::NS, '/growth/match/(?P<opportunity_id>\d+)', array_merge( $vendor, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'match' ),
		) ) );

		register_rest_route( self::NS, '/growth/roi/(?P<application_id>\d+)', array_merge( $vendor, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'roi_get' ),
		) ) );
		register_rest_route( self::NS, '/growth/roi/(?P<application_id>\d+)', array_merge( $vendor, array(
			'methods'  => WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'roi_save' ),
		) ) );
		register_rest_route( self::NS, '/growth/roi', array_merge( $vendor, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'roi_list' ),
		) ) );

		register_rest_route( self::NS, '/growth/calendar', array_merge( $vendor, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'calendar' ),
		) ) );

		register_rest_route( self::NS, '/growth/market-expansion', array_merge( $vendor, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'market_expansion' ),
		) ) );

		register_rest_route( self::NS, '/growth/market-insights', array_merge( $vendor, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'market_insights' ),
		) ) );

		register_rest_route( self::NS, '/growth/trends', array_merge( $vendor, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'trends' ),
		) ) );

		register_rest_route( self::NS, '/growth/export/(?P<type>[a-z_]+)', array_merge( $vendor, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'export' ),
		) ) );

		register_rest_route( self::NS, '/growth/admin/summary', array_merge( $admin, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'admin_summary' ),
		) ) );

		register_rest_route( self::NS, '/growth/forecast', array_merge( $vendor, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'forecast_placeholder' ),
		) ) );
	}

	public static function vendor_permission() {
		return is_user_logged_in() && LNKup_Capabilities::can_view_growth();
	}

	public static function admin_permission() {
		return LNKup_Capabilities::can_manage_marketplace_growth();
	}

	/**
	 * Wrap REST callbacks to return JSON errors instead of HTML fatals.
	 *
	 * @param callable $callback Endpoint logic.
	 */
	private static function respond( $callback ) {
		try {
			$result = $callback();
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return rest_ensure_response( $result );
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				return new WP_Error( 'growth_error', $e->getMessage(), array( 'status' => 500 ) );
			}
			return new WP_Error( 'growth_error', __( 'Growth data unavailable.', 'lnkup-marketplace' ), array( 'status' => 500 ) );
		}
	}

	public static function dashboard() {
		return self::respond( function () {
			$data = LNKup_Growth::vendor_dashboard();
			if ( is_wp_error( $data ) ) {
				$data = LNKup_Growth::vendor_dashboard_zero();
				$data['empty_state'] = true;
			}
			return array(
				'success' => true,
				'data'    => $data,
			);
		} );
	}

	public static function performance() {
		return self::respond( function () {
			return LNKup_Growth_Kpis::for_vendor();
		} );
	}

	public static function revenue( WP_REST_Request $request ) {
		return self::respond( function () use ( $request ) {
			$user_id = get_current_user_id();
			return array(
				'over_time' => LNKup_Growth_Analytics::revenue_over_time( $user_id, (int) $request->get_param( 'days' ) ?: 90 ),
				'by_month'  => LNKup_Growth_Analytics::revenue_by_month( $user_id ),
				'summary'   => LNKup_Growth_Analytics::revenue_summary( $user_id ),
			);
		} );
	}

	public static function revenue_by( WP_REST_Request $request ) {
		return self::respond( function () use ( $request ) {
			$dim = sanitize_key( $request->get_param( 'dimension' ) );
			return LNKup_Growth_Analytics::revenue_by_dimension( get_current_user_id(), $dim );
		} );
	}

	public static function applications() {
		return self::respond( function () {
			return LNKup_Growth_Analytics::vendor_funnel( get_current_user_id() );
		} );
	}

	public static function relationships() {
		return self::respond( function () {
			return LNKup_Growth_Relationships::for_vendor();
		} );
	}

	public static function journey() {
		return self::respond( function () {
			return LNKup_Growth_Journey::timeline();
		} );
	}

	public static function benchmarks() {
		return self::respond( function () {
			return LNKup_Growth_Benchmarks::for_vendor();
		} );
	}

	public static function profile_strength() {
		return self::respond( function () {
			$user_id    = get_current_user_id();
			$profile_id = lnkup_get_vendor_profile_id( $user_id );
			return LNKup_Growth_Profile_Strength::calculate( (int) $profile_id );
		} );
	}

	public static function achievements() {
		return self::respond( function () {
			return LNKup_Growth_Achievements::for_vendor();
		} );
	}

	public static function goals_list() {
		return self::respond( function () {
			return LNKup_Growth_Goals::list_for_vendor();
		} );
	}

	public static function goals_create( WP_REST_Request $request ) {
		return self::respond( function () use ( $request ) {
			$goal = LNKup_Growth_Goals::create( get_current_user_id(), $request->get_json_params() ?: array() );
			LNKup_Growth_Kpis::invalidate_cache( get_current_user_id() );
			return $goal;
		} );
	}

	public static function goals_update( WP_REST_Request $request ) {
		return self::respond( function () use ( $request ) {
			$goal = LNKup_Growth_Goals::update( (int) $request['id'], get_current_user_id(), $request->get_json_params() ?: array() );
			if ( ! $goal ) {
				return new WP_Error( 'not_found', __( 'Goal not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
			}
			return $goal;
		} );
	}

	public static function recommendations() {
		return self::respond( function () {
			return LNKup_Growth_Recommendations::generate();
		} );
	}

	public static function recommendations_dismiss( WP_REST_Request $request ) {
		return self::respond( function () use ( $request ) {
			$ok = LNKup_Growth_Recommendations::dismiss( (int) $request['id'], get_current_user_id() );
			return array( 'dismissed' => (bool) $ok );
		} );
	}

	public static function match( WP_REST_Request $request ) {
		return self::respond( function () use ( $request ) {
			return LNKup_Growth_Match::for_opportunity( get_current_user_id(), (int) $request['opportunity_id'] );
		} );
	}

	public static function roi_get( WP_REST_Request $request ) {
		return self::respond( function () use ( $request ) {
			$data = LNKup_Growth_Roi::get( (int) $request['application_id'] );
			if ( ! $data ) {
				return new WP_Error( 'not_found', __( 'ROI not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
			}
			return $data;
		} );
	}

	public static function roi_save( WP_REST_Request $request ) {
		return self::respond( function () use ( $request ) {
			$result = LNKup_Growth_Roi::save( (int) $request['application_id'], get_current_user_id(), $request->get_json_params() ?: array() );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return $result;
		} );
	}

	public static function roi_list() {
		return self::respond( function () {
			return LNKup_Growth_Roi::list_completed( get_current_user_id() );
		} );
	}

	public static function calendar( WP_REST_Request $request ) {
		return self::respond( function () use ( $request ) {
			return LNKup_Growth_Calendar::items(
				get_current_user_id(),
				$request->get_param( 'from' ) ?: '',
				$request->get_param( 'to' ) ?: ''
			);
		} );
	}

	public static function market_expansion() {
		return self::respond( function () {
			return LNKup_Growth_Recommendations::market_expansion( get_current_user_id() );
		} );
	}

	public static function market_insights() {
		return self::respond( function () {
			return LNKup_Growth_Recommendations::market_insights();
		} );
	}

	public static function trends() {
		return self::respond( function () {
			return LNKup_Growth_Analytics::growth_trends( get_current_user_id() );
		} );
	}

	public static function export( WP_REST_Request $request ) {
		return self::respond( function () use ( $request ) {
			$csv = LNKup_Growth_Exports::csv( get_current_user_id(), $request['type'] );
			if ( is_wp_error( $csv ) ) {
				return $csv;
			}
			return array( 'csv' => $csv, 'filename' => 'lnkup-' . $request['type'] . '-' . gmdate( 'Y-m-d' ) . '.csv' );
		} );
	}

	public static function admin_summary() {
		return self::respond( function () {
			return LNKup_Growth::admin_summary();
		} );
	}

	public static function forecast_placeholder() {
		return self::respond( function () {
			return array(
				'forecast' => null,
				'message'  => __( 'Revenue forecasting will be available in a future AI release.', 'lnkup-marketplace' ),
				'source'   => 'placeholder',
			);
		} );
	}
}

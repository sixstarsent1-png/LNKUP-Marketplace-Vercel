<?php
/**
 * City Connector REST API.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Connector_REST {

	const NS = 'lnkup/v1';

	/**
	 * Init routes.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register routes.
	 */
	public static function register_routes() {
		$connector = array( 'permission_callback' => array( __CLASS__, 'connector_permission' ) );

		register_rest_route( self::NS, '/connector/dashboard', array_merge( $connector, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'get_dashboard' ),
		) ) );

		register_rest_route( self::NS, '/connector/links', array_merge( $connector, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'get_links' ),
		) ) );

		register_rest_route( self::NS, '/connector/referrals', array_merge( $connector, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'get_referrals' ),
		) ) );

		register_rest_route( self::NS, '/connector/rewards', array_merge( $connector, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'get_rewards' ),
		) ) );

		register_rest_route( self::NS, '/connector/interest', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'submit_interest' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( self::NS, '/referrals/resolve', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'resolve_code' ),
			'permission_callback' => '__return_true',
		) );

		$admin = array( 'permission_callback' => array( __CLASS__, 'admin_permission' ) );

		register_rest_route( self::NS, '/admin/connectors', array_merge( $admin, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'admin_list' ),
		) ) );

		register_rest_route( self::NS, '/admin/connectors/(?P<id>\d+)/approve', array_merge( $admin, array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'admin_approve' ),
		) ) );

		register_rest_route( self::NS, '/admin/connectors/(?P<id>\d+)/pause', array_merge( $admin, array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'admin_pause' ),
		) ) );

		register_rest_route( self::NS, '/admin/connectors/(?P<id>\d+)/reject', array_merge( $admin, array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'admin_reject' ),
		) ) );

		register_rest_route( self::NS, '/admin/connectors/(?P<id>\d+)/reward', array_merge( $admin, array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'admin_reward' ),
		) ) );
	}

	/**
	 * Connector permission.
	 */
	public static function connector_permission() {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		if ( LNKup_Capabilities::user_has_manage_all() ) {
			return true;
		}
		return LNKup_Roles::is_connector();
	}

	/**
	 * Admin permission.
	 */
	public static function admin_permission() {
		return LNKup_Capabilities::user_has_manage_all();
	}

	/**
	 * Get connector for current user.
	 */
	private static function current_connector() {
		$user_id = get_current_user_id();
		if ( LNKup_Capabilities::user_has_manage_all() && ! empty( $_GET['connector_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return LNKup_Connector::get( absint( $_GET['connector_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		return LNKup_Connector::get_by_user_id( $user_id );
	}

	public static function get_dashboard() {
		$connector = self::current_connector();
		if ( ! $connector || 'active' !== ( $connector['status'] ?? '' ) ) {
			return new WP_Error( 'forbidden', __( 'Active connector access required.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		return rest_ensure_response( LNKup_Connector::get_dashboard_summary( (int) $connector['id'] ) );
	}

	public static function get_links() {
		$connector = self::current_connector();
		if ( ! $connector ) {
			return new WP_Error( 'forbidden', __( 'Connector access required.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		return rest_ensure_response( LNKup_Connector::build_referral_links( $connector ) );
	}

	public static function get_referrals( WP_REST_Request $request ) {
		$connector = self::current_connector();
		if ( ! $connector ) {
			return new WP_Error( 'forbidden', __( 'Connector access required.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$role = sanitize_key( (string) $request->get_param( 'role' ) );
		return rest_ensure_response( array(
			'vendors'    => LNKup_Connector::list_referred_users( (int) $connector['id'], 'vendor' ),
			'organizers' => LNKup_Connector::list_referred_users( (int) $connector['id'], 'organizer' ),
			'activity'   => LNKup_Connector::list_activity( (int) $connector['id'] ),
		) );
	}

	public static function get_rewards() {
		$connector = self::current_connector();
		if ( ! $connector ) {
			return new WP_Error( 'forbidden', __( 'Connector access required.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		return rest_ensure_response( LNKup_Connector_Rewards::list_for_connector( (int) $connector['id'] ) );
	}

	public static function submit_interest( WP_REST_Request $request ) {
		$result = LNKup_Connector::submit_interest( array(
			'name'         => $request->get_param( 'name' ),
			'email'        => $request->get_param( 'email' ),
			'phone'        => $request->get_param( 'phone' ),
			'city'         => $request->get_param( 'city' ),
			'market_slug'  => $request->get_param( 'market_slug' ),
			'social_url'   => $request->get_param( 'social_url' ),
			'connected_to' => $request->get_param( 'connected_to' ),
			'why'          => $request->get_param( 'why' ),
		) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	public static function resolve_code( WP_REST_Request $request ) {
		$code = sanitize_text_field( (string) $request->get_param( 'code' ) );
		$data = LNKup_Connector::resolve_code_public( $code );
		if ( ! $data ) {
			return rest_ensure_response( array( 'valid' => false ) );
		}
		return rest_ensure_response( $data );
	}

	public static function admin_list() {
		global $wpdb;
		$table = LNKup_Connector_Tables::connectors_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 200", ARRAY_A );
		return rest_ensure_response( array(
			'connectors' => $rows,
			'metrics'    => LNKup_Connector::admin_metrics(),
		) );
	}

	public static function admin_approve( WP_REST_Request $request ) {
		$id = absint( $request['id'] );
		$result = LNKup_Connector::approve( $id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'success' => true, 'connector' => $result ) );
	}

	public static function admin_pause( WP_REST_Request $request ) {
		global $wpdb;
		$id = absint( $request['id'] );
		$wpdb->update(
			LNKup_Connector_Tables::connectors_table(),
			array( 'status' => 'paused', 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $id )
		);
		return rest_ensure_response( array( 'success' => true ) );
	}

	public static function admin_reject( WP_REST_Request $request ) {
		global $wpdb;
		$id = absint( $request['id'] );
		$wpdb->update(
			LNKup_Connector_Tables::connectors_table(),
			array( 'status' => 'rejected', 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $id )
		);
		return rest_ensure_response( array( 'success' => true ) );
	}

	public static function admin_reward( WP_REST_Request $request ) {
		$reward_id = absint( $request->get_param( 'reward_id' ) );
		$action    = sanitize_key( (string) $request->get_param( 'action' ) );
		if ( 'paid' === $action ) {
			LNKup_Connector_Rewards::mark_paid( $reward_id );
		} else {
			$result = LNKup_Connector_Rewards::approve_reward( $reward_id );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		return rest_ensure_response( array( 'success' => true ) );
	}
}

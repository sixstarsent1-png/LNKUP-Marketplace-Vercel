<?php
/**
 * Weather Intelligence REST API.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Weather_REST {

	const NS = 'lnkup/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			self::NS,
			'/weather/event/(?P<event_id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'event_summary' ),
				'permission_callback' => array( __CLASS__, 'read_permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/weather/event/(?P<event_id>\d+)/refresh',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'refresh_event' ),
				'permission_callback' => array( __CLASS__, 'refresh_permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/weather/event/(?P<event_id>\d+)/history',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'event_history' ),
				'permission_callback' => array( __CLASS__, 'read_permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/weather/portfolio',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'portfolio_summary' ),
				'permission_callback' => array( __CLASS__, 'organizer_permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/weather/admin/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'admin_status' ),
				'permission_callback' => array( __CLASS__, 'admin_permission' ),
			)
		);
	}

	public static function event_summary( WP_REST_Request $request ) {
		$event_id = absint( $request['event_id'] );
		return rest_ensure_response( LNKup_Weather_Service::for_event( $event_id ) );
	}

	public static function refresh_event( WP_REST_Request $request ) {
		$event_id = absint( $request['event_id'] );
		$data     = LNKup_Weather_Service::refresh_event( $event_id, 'api' );
		return rest_ensure_response( $data );
	}

	public static function event_history( WP_REST_Request $request ) {
		$event_id = absint( $request['event_id'] );
		$limit    = min( 50, absint( $request->get_param( 'limit' ) ?: 30 ) );
		return rest_ensure_response(
			array(
				'event_id'  => $event_id,
				'snapshots' => LNKup_Weather_Tables::get_history( $event_id, $limit ),
			)
		);
	}

	public static function portfolio_summary( WP_REST_Request $request ) {
		return rest_ensure_response( LNKup_Weather_Service::portfolio_summary( get_current_user_id() ) );
	}

	public static function admin_status() {
		$provider = LNKup_Weather_Service::provider();
		$settings = lnkup_get_settings();
		return rest_ensure_response(
			array(
				'enabled'   => ! empty( $settings['weather_intelligence_enabled'] ),
				'provider'  => $provider->admin_status(),
				'refresh_minutes' => (int) ( $settings['weather_refresh_minutes'] ?? 30 ),
			)
		);
	}

	public static function read_permission( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$event_id = absint( $request['event_id'] );
		if ( LNKup_Capabilities::user_has_manage_all() ) {
			return true;
		}
		if ( LNKup_Capabilities::staff_can( $event_id, 'view_roster' ) ) {
			return true;
		}
		return self::vendor_can_view_event( $event_id );
	}

	public static function refresh_permission( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$event_id = absint( $request['event_id'] );
		return LNKup_Capabilities::user_has_manage_all() || LNKup_Capabilities::organizer_owns_event( $event_id );
	}

	public static function organizer_permission() {
		return is_user_logged_in() && ( user_can( get_current_user_id(), 'lnkup_organizer' ) || LNKup_Capabilities::user_has_manage_all() );
	}

	public static function admin_permission() {
		return current_user_can( 'lnkup_manage_settings' ) || LNKup_Capabilities::user_has_manage_all();
	}

	/**
	 * Vendor read access for paid/completed applications on event.
	 *
	 * @param int $event_id Event ID.
	 */
	private static function vendor_can_view_event( $event_id ) {
		$user_id = get_current_user_id();
		if ( ! user_can( $user_id, 'lnkup_vendor' ) ) {
			return false;
		}
		$apps = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array( 'key' => '_lnkup_event_id', 'value' => $event_id ),
				),
			)
		);
		foreach ( $apps as $app_id ) {
			$profile_id = (int) get_post_meta( $app_id, '_lnkup_vendor_profile_id', true );
			$is_owner   = LNKup_Capabilities::vendor_owns_profile( $profile_id, $user_id )
				|| (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true ) === (int) $user_id;
			$status     = get_post_meta( $app_id, '_lnkup_application_status', true );
			if ( $is_owner && in_array( $status, array( 'paid', 'completed', 'approved', 'payment_required' ), true ) ) {
				return true;
			}
		}
		return false;
	}
}

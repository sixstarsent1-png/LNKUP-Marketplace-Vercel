<?php
/**
 * Packets REST API — Vendor Event Pass.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Packets_REST {

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
		register_rest_route(
			self::NS,
			'/packets/pass/(?P<application_id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_pass' ),
				'permission_callback' => array( __CLASS__, 'pass_permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/packets/(?P<packet_id>\d+)/preview',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'preview_pass' ),
				'permission_callback' => array( __CLASS__, 'packet_organizer_permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/packets/(?P<packet_id>\d+)/pass-details',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'save_pass_details' ),
				'permission_callback' => array( __CLASS__, 'packet_organizer_permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/packets/event/(?P<event_id>\d+)/pass-details',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_event_pass_details' ),
					'permission_callback' => array( __CLASS__, 'event_organizer_permission' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'save_event_pass_details' ),
					'permission_callback' => array( __CLASS__, 'event_organizer_permission' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/packets/event/(?P<event_id>\d+)/completeness',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_completeness' ),
				'permission_callback' => array( __CLASS__, 'event_organizer_permission' ),
			)
		);
	}

	/**
	 * GET vendor event pass.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function get_pass( WP_REST_Request $request ) {
		$app_id = absint( $request['application_id'] );
		$result = LNKup_Packets::get_vendor_event_pass( $app_id, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	/**
	 * GET organizer preview (unlocked shape).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function preview_pass( WP_REST_Request $request ) {
		$packet_id = absint( $request['packet_id'] );
		$packet    = get_post( $packet_id );
		if ( ! $packet || 'lnkup_packet' !== $packet->post_type ) {
			return new WP_Error( 'invalid', __( 'Invalid packet.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$app_id = (int) get_post_meta( $packet_id, '_lnkup_application_id', true );
		if ( ! $app_id ) {
			return new WP_Error( 'invalid', __( 'Packet has no application.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$result = LNKup_Packets::get_vendor_event_pass( $app_id, get_current_user_id(), true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$result['preview'] = true;
		return rest_ensure_response( $result );
	}

	/**
	 * POST pass details.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function save_pass_details( WP_REST_Request $request ) {
		$packet_id = absint( $request['packet_id'] );
		$input     = $request->get_json_params();
		if ( ! is_array( $input ) ) {
			$input = array();
		}
		$result = LNKup_Packets::save_pass_details( $packet_id, $input, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$event_id = (int) get_post_meta( $packet_id, '_lnkup_event_id', true );
		return rest_ensure_response(
			array(
				'packet_id'    => $result,
				'completeness' => LNKup_Packets::get_pass_completeness( $event_id ),
				'lifecycle'    => LNKup_Packets::lifecycle( $event_id ),
			)
		);
	}

	/**
	 * GET event pass details for the in-app packet form.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function get_event_pass_details( WP_REST_Request $request ) {
		$result = LNKup_Packets::get_event_pass_details( absint( $request['event_id'] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	/**
	 * POST event pass details.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function save_event_pass_details( WP_REST_Request $request ) {
		$event_id = absint( $request['event_id'] );
		$input    = $request->get_json_params();
		if ( ! is_array( $input ) ) {
			$input = array();
		}
		$result = LNKup_Packets::save_event_pass_details( $event_id, $input, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response(
			array(
				'event_id'     => $event_id,
				'completeness' => LNKup_Packets::get_pass_completeness( $event_id ),
				'lifecycle'    => LNKup_Packets::lifecycle( $event_id ),
			)
		);
	}

	/**
	 * GET pass completeness for event.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function get_completeness( WP_REST_Request $request ) {
		$event_id = absint( $request['event_id'] );
		return rest_ensure_response( array_merge( LNKup_Packets::get_pass_completeness( $event_id ), array( 'lifecycle' => LNKup_Packets::lifecycle( $event_id ) ) ) );
	}

	/**
	 * Permission: vendor owns app, organizer owns event, or admin.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function pass_permission( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		return LNKup_Packets::can_view_pass( absint( $request['application_id'] ), get_current_user_id() );
	}

	/**
	 * Permission: organizer owns packet event or admin.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function packet_organizer_permission( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$user_id   = get_current_user_id();
		$packet_id = absint( $request['packet_id'] );
		if ( LNKup_Capabilities::user_has_manage_all( $user_id ) ) {
			return true;
		}
		$event_id = (int) get_post_meta( $packet_id, '_lnkup_event_id', true );
		return LNKup_Capabilities::staff_can( $event_id, 'edit_packets', $user_id );
	}

	/**
	 * Permission: organizer owns event or admin.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function event_organizer_permission( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$user_id  = get_current_user_id();
		$event_id = absint( $request['event_id'] );
		if ( LNKup_Capabilities::user_has_manage_all( $user_id ) ) {
			return true;
		}
		return LNKup_Capabilities::staff_can( $event_id, 'edit_packets', $user_id );
	}
}

<?php
/**
 * Push device REST API for native mobile.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Push_REST {

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
			'/push/devices',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'register_device' ),
					'permission_callback' => array( __CLASS__, 'logged_in_permission' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'unregister_device' ),
					'permission_callback' => array( __CLASS__, 'logged_in_permission' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/push/preferences',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_preferences' ),
					'permission_callback' => array( __CLASS__, 'logged_in_permission' ),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( __CLASS__, 'patch_preferences' ),
					'permission_callback' => array( __CLASS__, 'logged_in_permission' ),
				),
			)
		);
	}

	/**
	 * Logged-in users only.
	 *
	 * @return bool
	 */
	public static function logged_in_permission() {
		return is_user_logged_in();
	}

	/**
	 * POST /push/devices
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function register_device( WP_REST_Request $request ) {
		$user_id     = get_current_user_id();
		$token       = (string) $request->get_param( 'token' );
		$platform    = (string) $request->get_param( 'platform' );
		$app_version = (string) $request->get_param( 'app_version' );

		$result = LNKup_Push_Service::register_device( $user_id, $token, $platform, $app_version );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'token'   => sanitize_text_field( $token ),
			)
		);
	}

	/**
	 * DELETE /push/devices
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function unregister_device( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$token   = (string) $request->get_param( 'token' );
		if ( '' === $token ) {
			$body = $request->get_json_params();
			if ( is_array( $body ) && isset( $body['token'] ) ) {
				$token = (string) $body['token'];
			}
		}

		$result = LNKup_Push_Service::unregister_device( $user_id, $token );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'success' => true,
			)
		);
	}

	/**
	 * GET /push/preferences
	 *
	 * @return WP_REST_Response
	 */
	public static function get_preferences() {
		$user_id = get_current_user_id();
		return rest_ensure_response(
			array(
				'pushEnabled' => LNKup_Push_Service::user_push_enabled( $user_id ),
				'categories'  => LNKup_Push_Service::get_user_categories( $user_id ),
			)
		);
	}

	/**
	 * PATCH /push/preferences
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function patch_preferences( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$params  = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$has_enabled    = array_key_exists( 'pushEnabled', $params ) || array_key_exists( 'push_enabled', $params );
		$has_categories = array_key_exists( 'categories', $params ) && is_array( $params['categories'] );

		if ( ! $has_enabled && ! $has_categories ) {
			return new WP_Error(
				'lnkup_push_prefs_invalid',
				__( 'pushEnabled or categories is required.', 'lnkup-marketplace' ),
				array( 'status' => 400 )
			);
		}

		if ( $has_enabled ) {
			$enabled = array_key_exists( 'pushEnabled', $params )
				? (bool) $params['pushEnabled']
				: (bool) $params['push_enabled'];
			LNKup_Push_Service::set_user_push_enabled( $user_id, $enabled );
		}

		$categories = LNKup_Push_Service::get_user_categories( $user_id );
		if ( $has_categories ) {
			$categories = LNKup_Push_Service::set_user_categories( $user_id, $params['categories'] );
		}

		return rest_ensure_response(
			array(
				'pushEnabled' => LNKup_Push_Service::user_push_enabled( $user_id ),
				'categories'  => $categories,
			)
		);
	}
}

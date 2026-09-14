<?php
/**
 * Account REST — deletion for App Store Guideline 5.1.1(v).
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Account endpoints.
 */
class LNKup_Account_REST {

	const NS = 'lnkup/v1';

	/**
	 * Init.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Routes.
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::NS,
			'/account/delete',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'delete_account' ),
				'permission_callback' => static function () {
					return is_user_logged_in();
				},
			)
		);
	}

	/**
	 * Delete / schedule deletion for the authenticated user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_account( WP_REST_Request $request ) {
		$body    = $request->get_json_params();
		$confirm = is_array( $body ) && isset( $body['confirm'] ) ? (string) $body['confirm'] : '';
		$result  = LNKup_Account_Deletion::request_deletion( get_current_user_id(), $confirm );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}
}

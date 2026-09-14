<?php
/**
 * Inbox + mark-read REST for web BFF and Expo.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Inbox_REST {

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
			'/notifications/inbox',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_inbox' ),
				'permission_callback' => array( __CLASS__, 'logged_in_permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/notifications/inbox/count',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'count_inbox' ),
				'permission_callback' => array( __CLASS__, 'logged_in_permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/notifications/inbox/read-ids',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'read_ids' ),
				'permission_callback' => array( __CLASS__, 'logged_in_permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/notifications/inbox/(?P<client_id>[a-zA-Z0-9_-]+)/read',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'mark_read' ),
				'permission_callback' => array( __CLASS__, 'logged_in_permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/notifications/inbox/read-all',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'mark_all_read' ),
				'permission_callback' => array( __CLASS__, 'logged_in_permission' ),
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
	 * GET /notifications/inbox
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function list_inbox( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$result  = LNKup_Inbox_Service::list_items(
			$user_id,
			array(
				'filter'   => (string) $request->get_param( 'filter' ),
				'role'     => (string) $request->get_param( 'role' ),
				'page'     => $request->get_param( 'page' ),
				'per_page' => $request->get_param( 'per_page' ),
			)
		);
		$counts = LNKup_Inbox_Service::counts( $user_id, (string) $request->get_param( 'role' ) );
		return rest_ensure_response(
			array_merge(
				$result,
				array(
					'unread'         => $counts['unread'],
					'actionRequired' => $counts['actionRequired'],
					'readIds'        => $counts['readIds'],
				)
			)
		);
	}

	/**
	 * GET /notifications/inbox/count
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function count_inbox( WP_REST_Request $request ) {
		return rest_ensure_response(
			LNKup_Inbox_Service::counts( get_current_user_id(), (string) $request->get_param( 'role' ) )
		);
	}

	/**
	 * GET /notifications/inbox/read-ids
	 *
	 * @return WP_REST_Response
	 */
	public static function read_ids() {
		$ids = LNKup_Inbox_Service::read_ids( get_current_user_id() );
		return rest_ensure_response(
			array(
				'readIds' => $ids,
			)
		);
	}

	/**
	 * POST /notifications/inbox/{client_id}/read
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function mark_read( WP_REST_Request $request ) {
		$result = LNKup_Inbox_Service::mark_read( get_current_user_id(), (string) $request['client_id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response(
			array(
				'ok' => true,
				'id' => LNKup_Inbox_Service::sanitize_client_id( (string) $request['client_id'] ),
			)
		);
	}

	/**
	 * POST /notifications/inbox/read-all
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function mark_all_read( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}
		$ids  = isset( $params['ids'] ) && is_array( $params['ids'] ) ? $params['ids'] : array();
		$role = isset( $params['role'] ) ? (string) $params['role'] : (string) $request->get_param( 'role' );
		$result = LNKup_Inbox_Service::mark_all_read( get_current_user_id(), $ids, $role );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response(
			array(
				'ok' => true,
			)
		);
	}
}

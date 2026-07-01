<?php
/**
 * Applications REST API.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Applications_REST {

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
			'/applications/apply-context/(?P<opportunity_id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'apply_context' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NS,
			'/applications/apply-with-profile',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'apply_with_profile' ),
				'permission_callback' => array( __CLASS__, 'vendor_permission' ),
			)
		);
	}

	/**
	 * GET apply context.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function apply_context( WP_REST_Request $request ) {
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
		$context        = LNKup_Applications::build_apply_context( $opportunity_id );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		return rest_ensure_response( $context );
	}

	/**
	 * POST apply with profile.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function apply_with_profile( WP_REST_Request $request ) {
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

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$opportunity_id = absint( $params['opportunity_id'] ?? $request->get_param( 'opportunity_id' ) );
		$user_id        = get_current_user_id();

		$key = 'lnkup_apply_' . $user_id;
		if ( get_transient( $key ) ) {
			return new WP_Error( 'rate_limit', __( 'Please wait before submitting again.', 'lnkup-marketplace' ), array( 'status' => 429 ) );
		}
		set_transient( $key, 1, 30 );

		$result = LNKup_Applications::submit_with_profile(
			$user_id,
			$opportunity_id,
			array(
				'selected_category'   => $params['selected_category'] ?? '',
				'application_message' => $params['application_message'] ?? ( $params['message'] ?? '' ),
				'setup_notes'         => $params['setup_notes'] ?? '',
				'accepted_terms'      => ! empty( $params['accepted_terms'] ),
			)
		);

		if ( is_wp_error( $result ) ) {
			$status = 400;
			if ( 'duplicate' === $result->get_error_code() ) {
				$status = 409;
			} elseif ( in_array( $result->get_error_code(), array( 'not_logged_in', 'not_vendor', 'forbidden' ), true ) ) {
				$status = 403;
			}
			$data = $result->get_error_data();
			if ( ! is_array( $data ) ) {
				$data = array();
			}
			$data['status'] = $status;
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), $data );
		}

		$context  = LNKup_Applications::build_apply_context( $opportunity_id, $user_id );
		$warnings = is_array( $context ) ? ( $context['warnings'] ?? array() ) : array();

		return rest_ensure_response(
			array(
				'success'        => true,
				'application_id' => (int) $result,
				'status'         => 'submitted',
				'message'        => lnkup_copy( 'apply_success' ),
				'redirect_url'   => add_query_arg( 'view', 'applications', lnkup_page_url_helper( 'vendor-dashboard' ) ),
				'warnings'       => $warnings,
			)
		);
	}

	/**
	 * Vendor permission callback.
	 */
	public static function vendor_permission() {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		return LNKup_Roles::is_vendor() || LNKup_Capabilities::user_has_manage_all();
	}
}

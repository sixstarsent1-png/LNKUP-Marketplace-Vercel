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

		register_rest_route(
			self::NS,
			'/applications/vendor',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'vendor_applications' ),
				'permission_callback' => array( __CLASS__, 'vendor_permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/applications/organizer',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'organizer_applications' ),
				'permission_callback' => array( __CLASS__, 'organizer_permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/applications/(?P<id>\d+)/request-info',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'request_info' ),
				'permission_callback' => array( __CLASS__, 'organizer_permission' ),
				'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
			)
		);

		register_rest_route(
			self::NS,
			'/applications/(?P<id>\d+)/respond-info',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'respond_info' ),
				'permission_callback' => array( __CLASS__, 'vendor_permission' ),
				'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
			)
		);
	}

	/**
	 * POST organizer asks for more information.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function request_info( WP_REST_Request $request ) {
		$body  = $request->get_json_params() ?: $request->get_body_params();
		$entry = LNKup_Applications::request_info(
			(int) $request['id'],
			(string) ( $body['message'] ?? '' ),
			is_array( $body['documents'] ?? null ) ? $body['documents'] : array(),
			get_current_user_id()
		);
		if ( is_wp_error( $entry ) ) {
			return $entry;
		}
		return rest_ensure_response( self::info_payload( (int) $request['id'] ) );
	}

	/**
	 * POST vendor replies to an information request.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function respond_info( WP_REST_Request $request ) {
		$body  = $request->get_json_params() ?: $request->get_body_params();
		$entry = LNKup_Applications::respond_info( (int) $request['id'], (string) ( $body['message'] ?? '' ), get_current_user_id() );
		if ( is_wp_error( $entry ) ) {
			return $entry;
		}
		return rest_ensure_response( self::info_payload( (int) $request['id'] ) );
	}

	/**
	 * Status and request thread after an info action.
	 *
	 * @param int $app_id Application ID.
	 */
	private static function info_payload( $app_id ) {
		$status = (string) get_post_meta( $app_id, '_lnkup_application_status', true );
		return array(
			'application_id' => $app_id,
			'status'         => $status,
			'status_label'   => function_exists( 'lnkup_application_status_label' ) ? lnkup_application_status_label( $status ) : $status,
			'info_request'   => LNKup_Applications::open_info_request( $app_id ),
			'info_history'   => LNKup_Applications::info_history( $app_id ),
		);
	}

	/**
	 * GET vendor application list for dashboard / headless app.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function vendor_applications( WP_REST_Request $request ) {
		unset( $request );
		$user_id = get_current_user_id();
		$apps    = lnkup_get_applications_for_user( $user_id, 'vendor' );
		$items   = array();

		foreach ( $apps as $app ) {
			$meta       = LNKup_Vendor_Dashboard_Data::application_event_meta( $app );
			$status     = (string) get_post_meta( $app->ID, '_lnkup_application_status', true );
			$opp_id     = (int) get_post_meta( $app->ID, '_lnkup_opportunity_id', true );
			$card       = $opp_id ? lnkup_get_opportunity_card_data( $opp_id ) : array();
			$payment    = class_exists( 'LNKup_Payments' ) ? LNKup_Payments::get_for_application( $app->ID ) : null;
			$pay_status = $payment ? (string) get_post_meta( $payment->ID, '_lnkup_payment_status', true ) : '';

			if ( 'approved' === $status && $payment && 'pending' === $pay_status ) {
				$status = 'payment_required';
			}

			$amount_due = $meta['fee'];
			if ( $payment ) {
				$amount_due = (float) get_post_meta( $payment->ID, '_lnkup_total_amount', true );
				if ( $amount_due <= 0 ) {
					$amount_due = (float) get_post_meta( $payment->ID, '_lnkup_amount', true );
				}
				if ( $amount_due <= 0 ) {
					$amount_due = (float) get_post_meta( $payment->ID, '_lnkup_total', true );
				}
			}

			$items[] = array(
				'application_id'  => (int) $app->ID,
				'opportunity_id'  => $opp_id,
				'event_id'        => (int) $meta['event_id'],
				'event_name'      => (string) $meta['event_title'],
				'event_date'      => (string) $meta['date'],
				'city'            => (string) $meta['city'],
				'state'           => (string) $meta['state'],
				'organizer_name'  => (string) ( $card['organizer_name'] ?? '' ),
				'category'        => (string) get_post_meta( $app->ID, '_lnkup_selected_category', true ),
				'status'          => $status ?: 'submitted',
				'submitted_at'    => (string) ( get_post_meta( $app->ID, '_lnkup_submitted_at', true ) ?: $app->post_date ),
				'approved_at'     => (string) ( get_post_meta( $app->ID, '_lnkup_approved_at', true ) ?: get_post_meta( $app->ID, '_lnkup_decided_at', true ) ),
				'payment_due_at'  => (string) get_post_meta( $app->ID, '_lnkup_payment_due_at', true ),
				'amount_due'      => $amount_due,
				'currency'        => 'USD',
				'vendor_fee'      => (float) $meta['fee'],
				'featured_image'  => (string) ( $card['featured_image'] ?? '' ),
				'payment_id'      => $payment ? (int) $payment->ID : 0,
				'payment_status'  => $pay_status,
				'rejection_reason'=> (string) get_post_meta( $app->ID, '_lnkup_rejection_reason', true ),
				'info_request'    => LNKup_Applications::open_info_request( $app->ID ),
			);
		}

		return rest_ensure_response(
			array(
				'items' => $items,
				'total' => count( $items ),
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

		$key = 'lnkup_apply_' . $user_id . '_' . $opportunity_id;
		if ( get_transient( $key ) ) {
			return new WP_Error( 'rate_limit', __( 'Please wait before submitting again.', 'lnkup-marketplace' ), array( 'status' => 429 ) );
		}

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
			if ( 'rate_limit' === $result->get_error_code() ) {
				$status = 429;
			}
			$data['status'] = $status;
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), $data );
		}
		set_transient( $key, 1, 30 );

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
	 * GET organizer application list for dashboard / headless app.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function organizer_applications( WP_REST_Request $request ) {
		unset( $request );
		$user_id = get_current_user_id();
		$apps    = lnkup_get_applications_for_user( $user_id, 'organizer' );
		$items   = array();

		foreach ( $apps as $app ) {
			$meta       = LNKup_Vendor_Dashboard_Data::application_event_meta( $app );
			$status     = (string) get_post_meta( $app->ID, '_lnkup_application_status', true );
			$opp_id     = (int) get_post_meta( $app->ID, '_lnkup_opportunity_id', true );
			$profile_id = (int) get_post_meta( $app->ID, '_lnkup_vendor_profile_id', true );
			$vendor_id  = (int) get_post_meta( $app->ID, '_lnkup_vendor_user_id', true );
			$card       = $opp_id ? lnkup_get_opportunity_card_data( $opp_id ) : array();
			$payment    = class_exists( 'LNKup_Payments' ) ? LNKup_Payments::get_for_application( $app->ID ) : null;
			$pay_status = $payment ? (string) get_post_meta( $payment->ID, '_lnkup_payment_status', true ) : '';

			if ( 'approved' === $status && $payment && 'pending' === $pay_status ) {
				$status = 'payment_required';
			}

			$amount_due = $meta['fee'];
			if ( $payment ) {
				$amount_due = (float) get_post_meta( $payment->ID, '_lnkup_total_amount', true );
				if ( $amount_due <= 0 ) {
					$amount_due = (float) get_post_meta( $payment->ID, '_lnkup_amount', true );
				}
				if ( $amount_due <= 0 ) {
					$amount_due = (float) get_post_meta( $payment->ID, '_lnkup_total', true );
				}
			}

			$business_name = '';
			$trust_badges  = array();
			if ( $profile_id && class_exists( 'LNKup_Vendor_CRM_Profile' ) ) {
				$profile = LNKup_Vendor_CRM_Profile::get( $profile_id, $user_id );
				if ( is_array( $profile ) ) {
					$business_name = (string) ( $profile['business_name'] ?? '' );
					if ( ! empty( $profile['verified'] ) ) {
						$trust_badges[] = 'verified';
					}
					if ( ! empty( $profile['badges'] ) && is_array( $profile['badges'] ) ) {
						$trust_badges = array_merge( $trust_badges, $profile['badges'] );
					}
				}
			}
			if ( ! $business_name && $vendor_id ) {
				$user = get_userdata( $vendor_id );
				$business_name = $user ? $user->display_name : '';
			}

			$readiness = class_exists( 'LNKup_Vendor_Readiness' )
				? LNKup_Vendor_Readiness::checklist( $app->ID )
				: array();
			$docs_status = 'unknown';
			if ( ! empty( $readiness['percent'] ) && (int) $readiness['percent'] >= 100 ) {
				$docs_status = 'ready';
			} elseif ( ! empty( $readiness['pending_top'] ) ) {
				$docs_status = 'missing';
			} elseif ( ! empty( $readiness['percent'] ) ) {
				$docs_status = 'pending';
			}

			$items[] = array(
				'application_id'    => (int) $app->ID,
				'opportunity_id'    => $opp_id,
				'event_id'          => (int) $meta['event_id'],
				'event_name'        => (string) $meta['event_title'],
				'opportunity_name'  => (string) ( $card['title'] ?? $card['name'] ?? '' ),
				'event_date'        => (string) $meta['date'],
				'city'              => (string) $meta['city'],
				'state'             => (string) $meta['state'],
				'vendor_profile_id' => $profile_id,
				'vendor_user_id'    => $vendor_id,
				'vendor_business_name' => $business_name,
				'category'          => (string) get_post_meta( $app->ID, '_lnkup_selected_category', true ),
				'status'            => $status ?: 'submitted',
				'submitted_at'      => (string) ( get_post_meta( $app->ID, '_lnkup_submitted_at', true ) ?: $app->post_date ),
				'approved_at'       => (string) get_post_meta( $app->ID, '_lnkup_decided_at', true ),
				'payment_due_at'    => (string) get_post_meta( $app->ID, '_lnkup_payment_due_at', true ),
				'amount_due'        => $amount_due,
				'currency'          => 'USD',
				'vendor_fee'        => (float) $meta['fee'],
				'payment_id'        => $payment ? (int) $payment->ID : 0,
				'payment_status'    => $pay_status,
				'documents_status'  => $docs_status,
				'trust_badges'      => array_values( array_unique( array_filter( $trust_badges ) ) ),
				'message'           => (string) $app->post_content,
				'rejection_reason'  => (string) get_post_meta( $app->ID, '_lnkup_rejection_reason', true ),
				'info_request'      => LNKup_Applications::open_info_request( $app->ID ),
				'info_history'      => LNKup_Applications::info_history( $app->ID ),
			);
		}

		return rest_ensure_response(
			array(
				'items' => $items,
				'total' => count( $items ),
			)
		);
	}

	/**
	 * Organizer permission callback.
	 */
	public static function organizer_permission() {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		return current_user_can( 'lnkup_review_applications' )
			|| ( class_exists( 'LNKup_Roles' ) && LNKup_Roles::is_organizer() )
			|| current_user_can( 'manage_options' )
			|| LNKup_Capabilities::user_has_manage_all();
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

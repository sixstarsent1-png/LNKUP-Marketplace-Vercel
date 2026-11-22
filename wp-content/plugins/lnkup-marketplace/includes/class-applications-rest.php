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

		register_rest_route(
			self::NS,
			'/applications/(?P<id>\d+)/withdraw',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'withdraw' ),
				'permission_callback' => array( __CLASS__, 'vendor_permission' ),
				'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
			)
		);

		// Drafts are keyed by opportunity: a vendor has at most one open draft per vendor slot.
		register_rest_route(
			self::NS,
			'/applications/drafts',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_drafts' ),
				'permission_callback' => array( __CLASS__, 'vendor_permission' ),
			)
		);
		register_rest_route(
			self::NS,
			'/applications/drafts/(?P<opportunity_id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_draft' ),
					'permission_callback' => array( __CLASS__, 'vendor_permission' ),
					'args'                => array( 'opportunity_id' => array( 'sanitize_callback' => 'absint' ) ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( __CLASS__, 'save_draft' ),
					'permission_callback' => array( __CLASS__, 'vendor_permission' ),
					'args'                => array( 'opportunity_id' => array( 'sanitize_callback' => 'absint' ) ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'discard_draft' ),
					'permission_callback' => array( __CLASS__, 'vendor_permission' ),
					'args'                => array( 'opportunity_id' => array( 'sanitize_callback' => 'absint' ) ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/applications/drafts/(?P<opportunity_id>\d+)/submit',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'submit_draft' ),
				'permission_callback' => array( __CLASS__, 'vendor_permission' ),
				'args'                => array( 'opportunity_id' => array( 'sanitize_callback' => 'absint' ) ),
			)
		);
		register_rest_route(
			self::NS,
			'/applications/price-quote/(?P<opportunity_id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'price_quote' ),
				'permission_callback' => array( __CLASS__, 'vendor_permission' ),
				'args'                => array( 'opportunity_id' => array( 'sanitize_callback' => 'absint' ) ),
			)
		);
		register_rest_route(
			self::NS,
			'/opportunities/(?P<id>\d+)/application-questions',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_questions' ),
					'permission_callback' => '__return_true',
					'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( __CLASS__, 'save_questions' ),
					'permission_callback' => array( __CLASS__, 'organizer_permission' ),
					'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
				),
			)
		);
	}

	/**
	 * GET open drafts as resume cards.
	 */
	public static function list_drafts() {
		$items = LNKup_Application_Drafts::list_for_vendor( get_current_user_id() );
		return rest_ensure_response( array( 'items' => $items, 'total' => count( $items ) ) );
	}

	/**
	 * GET the vendor's open draft for an opportunity (draft: null when none).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function get_draft( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'draft' => LNKup_Application_Drafts::get_for_opportunity( get_current_user_id(), (int) $request['opportunity_id'] ) ) );
	}

	/**
	 * PUT autosave. Body: step, fields, answers, profile_overrides, document_ids, revision, client, invite_token.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function save_draft( WP_REST_Request $request ) {
		$body     = $request->get_json_params();
		$body     = is_array( $body ) ? $body : array();
		$revision = array_key_exists( 'revision', $body ) && null !== $body['revision'] ? (int) $body['revision'] : null;
		$draft    = LNKup_Application_Drafts::upsert( get_current_user_id(), (int) $request['opportunity_id'], $body, $revision );
		if ( is_wp_error( $draft ) ) {
			return $draft;
		}
		return rest_ensure_response( array( 'draft' => $draft ) );
	}

	/**
	 * DELETE discard the open draft for an opportunity.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function discard_draft( WP_REST_Request $request ) {
		$draft = LNKup_Application_Drafts::get_for_opportunity( get_current_user_id(), (int) $request['opportunity_id'] );
		if ( ! $draft ) {
			return rest_ensure_response( array( 'discarded' => false ) );
		}
		$result = LNKup_Application_Drafts::discard( (int) $draft['id'], get_current_user_id() );
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'discarded' => true ) );
	}

	/**
	 * POST finalize the open draft. Requires an Idempotency-Key header; the draft itself also
	 * guarantees a single application, so retries return the same application ID.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function submit_draft( WP_REST_Request $request ) {
		$key = (string) $request->get_header( 'idempotency_key' );
		if ( '' === $key || ! preg_match( '/^[A-Za-z0-9_\-]{8,64}$/', $key ) ) {
			return new WP_Error( 'lnkup_idempotency_key_required', __( 'Missing request key. Refresh and try again.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$user_id = get_current_user_id();
		$opp_id  = (int) $request['opportunity_id'];
		$body    = $request->get_json_params();
		$body    = is_array( $body ) ? $body : array();
		$draft   = LNKup_Application_Drafts::get_for_opportunity( $user_id, $opp_id );
		if ( ! $draft ) {
			// Retry after success: the draft is closed, the application exists.
			$existing = LNKup_Applications::find_active_application( $opp_id, $user_id );
			if ( $existing ) {
				return rest_ensure_response( array( 'success' => true, 'application_id' => $existing, 'status' => (string) get_post_meta( $existing, '_lnkup_application_status', true ), 'replayed' => true ) );
			}
			return new WP_Error( 'lnkup_not_found', __( 'Application draft not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$result = LNKup_Application_Drafts::finalize(
			(int) $draft['id'],
			$user_id,
			array(
				'accepted_terms'       => ! empty( $body['accepted_terms'] ),
				'accept_current_price' => ! empty( $body['accept_current_price'] ),
				'idempotency_key'      => $key,
				'share_consent'        => ! empty( $body['share_consent'] ),
				'share_document_ids'   => is_array( $body['share_document_ids'] ?? null ) ? array_map( 'absint', $body['share_document_ids'] ) : array(),
			)
		);
		if ( is_wp_error( $result ) ) {
			return self::rest_error( $result );
		}
		return rest_ensure_response(
			array_merge(
				array(
					'success'      => true,
					'message'      => lnkup_copy( 'apply_success' ),
					'redirect_url' => add_query_arg( 'view', 'applications', lnkup_page_url_helper( 'vendor-dashboard' ) ),
				),
				$result
			)
		);
	}

	/**
	 * GET server-computed price estimate before approval. Never computed in the browser.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function price_quote( WP_REST_Request $request ) {
		$opp_id = (int) $request['opportunity_id'];
		if ( 'lnkup_opportunity' !== get_post_type( $opp_id ) || 'publish' !== get_post_status( $opp_id ) ) {
			return new WP_Error( 'lnkup_not_found', __( 'Opportunity not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( get_post_meta( $opp_id, '_lnkup_invite_only', true ) && ! lnkup_vendor_can_discover_opportunity( get_current_user_id(), $opp_id ) && ! LNKup_Capabilities::user_has_manage_all() ) {
			return new WP_Error( 'lnkup_invite_only', __( 'This booth is invite-only.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		return rest_ensure_response( self::quote_for_opportunity( $opp_id, (string) $request->get_param( 'category' ) ) );
	}

	/**
	 * Fee engine estimate for an opportunity (shared by the wizard and multi-stop apply).
	 *
	 * @param int    $opp_id   Opportunity.
	 * @param string $category Category label or slug.
	 * @return array<string, mixed>
	 */
	public static function quote_for_opportunity( $opp_id, $category = '' ) {
		$event_id = (int) get_post_meta( $opp_id, '_lnkup_event_id', true );
		$term     = '' !== $category ? get_term_by( 'name', $category, 'lnkup_vendor_category' ) : null;
		$fees     = LNKup_Fee_Engine::calculate(
			array(
				'vendor_fee'   => (float) get_post_meta( $opp_id, '_lnkup_vendor_fee', true ),
				'organizer_id' => $event_id ? (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true ) : 0,
				'event_id'     => $event_id,
				'category'     => $term && ! is_wp_error( $term ) ? $term->slug : sanitize_title( $category ),
			)
		);
		return array(
			'opportunity_id' => (int) $opp_id,
			'is_estimate'    => true,
			'currency'       => 'USD',
			'lines'          => array_values(
				array_filter(
					array(
						array( 'key' => 'booth', 'label' => __( 'Booth fee', 'lnkup-marketplace' ), 'amount' => (float) $fees['vendor_fee'] ),
						array( 'key' => 'checkout_fee', 'label' => __( 'LNKup checkout fee', 'lnkup-marketplace' ), 'amount' => (float) $fees['vendor_checkout_fee'] ),
						array( 'key' => 'processing', 'label' => __( 'Card processing', 'lnkup-marketplace' ), 'amount' => (float) $fees['processing_fee'] ),
						array( 'key' => 'tax', 'label' => __( 'Sales tax', 'lnkup-marketplace' ), 'amount' => (float) $fees['tax_amount'] ),
					),
					static function ( $line ) {
						return 'booth' === $line['key'] || $line['amount'] > 0;
					}
				)
			),
			'total'          => (float) $fees['total'],
			'note'           => __( 'Estimate. You only pay if the organizer approves you, and the final amount is confirmed before checkout.', 'lnkup-marketplace' ),
		);
	}

	/**
	 * GET organizer questions for an opportunity (public: shown in the apply form).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function get_questions( WP_REST_Request $request ) {
		$opp_id = (int) $request['id'];
		if ( 'lnkup_opportunity' !== get_post_type( $opp_id ) ) {
			return new WP_Error( 'lnkup_not_found', __( 'Opportunity not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$user_id = get_current_user_id();
		if ( get_post_meta( $opp_id, '_lnkup_invite_only', true ) && ! LNKup_Application_Questions::can_manage( $opp_id, $user_id ) && ! lnkup_vendor_can_discover_opportunity( $user_id, $opp_id ) ) {
			return new WP_Error( 'lnkup_invite_only', __( 'This booth is invite-only.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		return rest_ensure_response(
			array(
				'questions'                    => LNKup_Application_Questions::get_for_opportunity( $opp_id ),
				'documents_required_to_apply'  => LNKup_Application_Questions::documents_gate( $opp_id ),
			)
		);
	}

	/**
	 * PUT organizer questions. Body: questions[], documents_required_to_apply (bool, optional).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function save_questions( WP_REST_Request $request ) {
		$body  = $request->get_json_params();
		$body  = is_array( $body ) ? $body : array();
		$saved = LNKup_Application_Questions::save_for_opportunity(
			(int) $request['id'],
			is_array( $body['questions'] ?? null ) ? $body['questions'] : array(),
			get_current_user_id(),
			array_key_exists( 'documents_required_to_apply', $body ) ? ! empty( $body['documents_required_to_apply'] ) : null
		);
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		return rest_ensure_response(
			array(
				'questions'                   => $saved,
				'documents_required_to_apply' => LNKup_Application_Questions::documents_gate( (int) $request['id'] ),
			)
		);
	}

	/**
	 * Normalize a service WP_Error into a REST error with an HTTP status.
	 *
	 * @param WP_Error $error Error.
	 */
	public static function rest_error( WP_Error $error ) {
		$data = $error->get_error_data();
		$data = is_array( $data ) ? $data : array();
		if ( empty( $data['status'] ) ) {
			$code           = $error->get_error_code();
			$data['status'] = 'duplicate' === $code ? 409 : ( in_array( $code, array( 'not_logged_in', 'not_vendor', 'forbidden', 'invite_only' ), true ) ? 403 : ( 'rate_limit' === $code ? 429 : 400 ) );
		}
		return new WP_Error( $error->get_error_code(), $error->get_error_message(), $data );
	}

	/**
	 * POST vendor withdraws an unpaid application.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function withdraw( WP_REST_Request $request ) {
		$result = LNKup_Applications::withdraw( (int) $request['id'], get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$status = (string) get_post_meta( (int) $request['id'], '_lnkup_application_status', true );
		return rest_ensure_response(
			array(
				'application_id' => (int) $request['id'],
				'status'         => $status,
				'status_label'   => function_exists( 'lnkup_application_status_label' ) ? lnkup_application_status_label( $status ) : $status,
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
				'status_history'  => LNKup_Applications::status_history( $app->ID ),
				'group_id'        => (string) get_post_meta( $app->ID, '_lnkup_submission_group_id', true ),
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
		$context        = LNKup_Applications::build_apply_context( $opportunity_id, 0, sanitize_text_field( (string) $request->get_param( 'invite' ) ) );
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

		$idempotency_key = (string) ( $request->get_header( 'idempotency_key' ) ?: ( $params['idempotency_key'] ?? '' ) );
		$replay          = '' !== $idempotency_key && LNKup_Applications::find_by_idempotency_key( $user_id, $opportunity_id, substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', $idempotency_key ), 0, 64 ) );
		$key             = 'lnkup_apply_' . $user_id . '_' . $opportunity_id;
		// A retry of an already-accepted request (same key) returns the original application, not a 429.
		if ( ! $replay && get_transient( $key ) ) {
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
				'invite_token'        => $params['invite_token'] ?? ( $params['invite'] ?? '' ),
				'answers'             => is_array( $params['answers'] ?? null ) ? $params['answers'] : array(),
				'document_ids'        => is_array( $params['document_ids'] ?? null ) ? array_map( 'absint', $params['document_ids'] ) : array(),
				'profile_overrides'   => is_array( $params['profile_overrides'] ?? null ) ? $params['profile_overrides'] : array(),
				'idempotency_key'     => $idempotency_key,
				'client'              => in_array( $params['client'] ?? '', array( 'web', 'mobile_web', 'app' ), true ) ? $params['client'] : '',
				'source'              => sanitize_key( (string) ( $params['source'] ?? 'web' ) ),
				'share_consent'       => ! empty( $params['share_consent'] ),
				'share_document_ids'  => is_array( $params['share_document_ids'] ?? null ) ? array_map( 'absint', $params['share_document_ids'] ) : array(),
			)
		);

		if ( is_wp_error( $result ) ) {
			return self::apply_error( $result );
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
	 * Map apply errors to stable HTTP statuses; errors that already carry a status keep it.
	 *
	 * @param WP_Error $error Error from submit_with_profile().
	 * @return WP_Error
	 */
	public static function apply_error( WP_Error $error ) {
		$code = $error->get_error_code();
		$data = $error->get_error_data();
		$data = is_array( $data ) ? $data : array();
		$map  = array(
			'duplicate'           => 409,
			'previously_declined' => 409,
			'not_logged_in'       => 403,
			'not_vendor'          => 403,
			'forbidden'           => 403,
			'invite_only'         => 403,
			'rate_limit'          => 429,
		);
		if ( isset( $data['status'] ) && ! is_int( $data['status'] ) ) {
			$data['existing_status'] = (string) $data['status'];
			unset( $data['status'] );
		}
		if ( isset( $map[ $code ] ) ) {
			$data['status'] = $map[ $code ];
		} elseif ( empty( $data['status'] ) ) {
			$data['status'] = 400;
		}
		return new WP_Error( $code, $error->get_error_message(), $data );
	}

	/**
	 * GET organizer application list for dashboard / headless app.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function organizer_applications( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$apps    = lnkup_get_applications_for_user( $user_id, 'organizer' );
		$items   = array();

		// Optional narrowing to one tour's stops and/or explicit events (unknown filters are ignored).
		$only_events = array_filter( array_map( 'absint', (array) $request->get_param( 'event_ids' ) ) );
		$tour_filter = absint( $request->get_param( 'tour_id' ) );
		if ( $tour_filter ) {
			$stops       = class_exists( 'LNKup_Tours' ) && LNKup_Capabilities::organizer_owns_tour( $tour_filter, $user_id ) ? LNKup_Tours::stop_event_ids( $tour_filter ) : array();
			$only_events = $only_events ? array_values( array_intersect( $only_events, $stops ) ) : $stops;
			$only_events = $only_events ? $only_events : array( -1 );
		}
		if ( $only_events ) {
			$apps = array_values(
				array_filter(
					$apps,
					static function ( $app ) use ( $only_events ) {
						return in_array( (int) get_post_meta( $app->ID, '_lnkup_event_id', true ), $only_events, true );
					}
				)
			);
		}

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
				'answers'           => LNKup_Application_Questions::answers_for_application( $app->ID ),
				'status_history'    => LNKup_Applications::status_history( $app->ID ),
				'group_id'          => (string) get_post_meta( $app->ID, '_lnkup_submission_group_id', true ),
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

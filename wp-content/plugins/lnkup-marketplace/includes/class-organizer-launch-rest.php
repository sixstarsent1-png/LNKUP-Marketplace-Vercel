<?php
/**
 * Organizer launch REST API — event + opportunity publish from Next.js BFF / Expo.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Thin REST slice for guided launch publish and event edits.
 */
class LNKup_Organizer_Launch_REST {

	const NS = 'lnkup/v1';

	/**
	 * Init routes.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Organizer permission (matches CRM REST).
	 *
	 * @return bool
	 */
	public static function organizer_permission(): bool {
		return current_user_can( 'lnkup_review_applications' )
			|| ( class_exists( 'LNKup_Roles' ) && LNKup_Roles::is_organizer() )
			|| current_user_can( 'manage_options' )
			|| LNKup_Capabilities::user_has_manage_all();
	}

	/**
	 * Register routes.
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::NS,
			'/organizer/launch/publish',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'publish' ),
				'permission_callback' => array( __CLASS__, 'organizer_permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/organizer/events/(?P<id>\d+)',
			array(
				'methods'             => array( 'POST', 'PATCH', 'PUT' ),
				'callback'            => array( __CLASS__, 'update_event' ),
				'permission_callback' => array( __CLASS__, 'organizer_permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/organizer/events/(?P<id>\d+)/duplicate',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'duplicate_event' ),
				'permission_callback' => array( __CLASS__, 'organizer_permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/organizer/events/(?P<id>\d+)/opportunities',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'add_opportunities' ),
				'permission_callback' => array( __CLASS__, 'organizer_permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/organizer/opportunities/(?P<id>\d+)/pricing',
			array(
				'methods'             => array( 'POST', 'PATCH' ),
				'callback'            => array( __CLASS__, 'update_pricing' ),
				'permission_callback' => array( __CLASS__, 'organizer_permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/organizer/opportunities/(?P<id>\d+)/status',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'update_listing_status' ),
				'permission_callback' => array( __CLASS__, 'organizer_permission' ),
				'args'                => array(
					'id'     => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'status' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( 'open', 'closed' ),
					),
				),
			)
		);
	}

	/**
	 * Open or close a vendor slot (closing keeps existing applications and hides it from discovery).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_listing_status( WP_REST_Request $request ) {
		if ( ! class_exists( 'LNKup_Organizer_Onboarding' ) ) {
			return new WP_Error( 'lnkup_unavailable', __( 'Slot status is not available.', 'lnkup-marketplace' ), array( 'status' => 503 ) );
		}
		$result = LNKup_Organizer_Onboarding::set_opportunity_listing_status(
			(int) $request['id'],
			sanitize_key( (string) $request['status'] ),
			get_current_user_id()
		);
		if ( is_wp_error( $result ) ) {
			return self::error_response( $result );
		}
		return rest_ensure_response( $result );
	}

	/**
	 * Update opportunity vendor fee and spots.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_pricing( WP_REST_Request $request ) {
		if ( ! class_exists( 'LNKup_Organizer_Onboarding' ) ) {
			return new WP_Error(
				'lnkup_unavailable',
				__( 'Pricing update is not available.', 'lnkup-marketplace' ),
				array( 'status' => 503 )
			);
		}

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}

		$opp_id = (int) $request['id'];
		$fee    = array_key_exists( 'vendor_fee', $body ) ? $body['vendor_fee'] : null;
		$spots  = array_key_exists( 'spots_total', $body ) ? absint( $body['spots_total'] ) : null;

		$result = LNKup_Organizer_Onboarding::update_opportunity_pricing(
			$opp_id,
			$fee,
			$spots,
			get_current_user_id()
		);

		if ( is_wp_error( $result ) ) {
			return self::error_response( $result );
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Patch event listing fields.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_event( WP_REST_Request $request ) {
		if ( ! class_exists( 'LNKup_Organizer_Onboarding' ) ) {
			return new WP_Error(
				'lnkup_unavailable',
				__( 'Event update is not available.', 'lnkup-marketplace' ),
				array( 'status' => 503 )
			);
		}

		$body  = $request->get_json_params();
		$input = self::map_request_to_onboarding_input( is_array( $body ) ? $body : array() );

		$result = LNKup_Organizer_Onboarding::update_event_from_request(
			(int) $request['id'],
			$input,
			get_current_user_id()
		);

		if ( is_wp_error( $result ) ) {
			return self::error_response( $result );
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Duplicate an event and its vendor slots.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function duplicate_event( WP_REST_Request $request ) {
		if ( ! class_exists( 'LNKup_Organizer_Onboarding' ) ) {
			return new WP_Error(
				'lnkup_unavailable',
				__( 'Event duplicate is not available.', 'lnkup-marketplace' ),
				array( 'status' => 503 )
			);
		}

		$body  = $request->get_json_params();
		$input = self::map_request_to_onboarding_input( is_array( $body ) ? $body : array() );

		$result = LNKup_Organizer_Onboarding::duplicate_event(
			(int) $request['id'],
			$input,
			get_current_user_id()
		);

		if ( is_wp_error( $result ) ) {
			return self::error_response( $result );
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Add priced vendor-type opportunities to an existing event.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function add_opportunities( WP_REST_Request $request ) {
		if ( ! class_exists( 'LNKup_Organizer_Onboarding' ) ) {
			return new WP_Error(
				'lnkup_unavailable',
				__( 'Opportunity create is not available.', 'lnkup-marketplace' ),
				array( 'status' => 503 )
			);
		}

		$body  = $request->get_json_params();
		$input = self::map_request_to_onboarding_input( is_array( $body ) ? $body : array() );
		if ( is_array( $body ) && ! empty( $body['opportunities'] ) && is_array( $body['opportunities'] ) ) {
			$input['opportunities'] = $body['opportunities'];
		}

		$result = LNKup_Organizer_Onboarding::create_opportunities_for_event(
			(int) $request['id'],
			$input,
			get_current_user_id()
		);

		if ( is_wp_error( $result ) ) {
			return self::error_response( $result );
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Create event + one or more priced opportunities from JSON body.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function publish( WP_REST_Request $request ) {
		if ( ! class_exists( 'LNKup_Organizer_Onboarding' ) ) {
			return new WP_Error(
				'lnkup_unavailable',
				__( 'Launch publish is not available.', 'lnkup-marketplace' ),
				array( 'status' => 503 )
			);
		}

		$body  = $request->get_json_params();
		$input = self::map_request_to_onboarding_input( is_array( $body ) ? $body : array() );
		if ( is_array( $body ) && ! empty( $body['opportunities'] ) && is_array( $body['opportunities'] ) ) {
			$input['opportunities'] = $body['opportunities'];
		}

		$event_result = LNKup_Organizer_Onboarding::create_event_from_request( $input );
		if ( is_wp_error( $event_result ) ) {
			return self::error_response( $event_result );
		}

		$event_id     = isset( $event_result['event_id'] ) ? (int) $event_result['event_id'] : 0;
		$organizer_id = get_current_user_id();

		if ( $event_id < 1 ) {
			return new WP_Error(
				'lnkup_event_failed',
				__( 'Event could not be created.', 'lnkup-marketplace' ),
				array( 'status' => 500 )
			);
		}

		// Draft: keep the event without vendor slots (listing stays draft, invisible to vendors).
		if ( is_array( $body ) && 'draft' === sanitize_key( (string) ( $body['save_mode'] ?? '' ) ) ) {
			return rest_ensure_response(
				array(
					'draft'    => true,
					'event_id' => $event_id,
					'message'  => __( 'Draft saved. Vendors can\'t see it until you publish vendor spots.', 'lnkup-marketplace' ),
				)
			);
		}

		$opp_result = LNKup_Organizer_Onboarding::create_opportunities_for_event( $event_id, $input, $organizer_id );
		if ( is_wp_error( $opp_result ) ) {
			$data = $opp_result->get_error_data();
			return rest_ensure_response(
				array(
					'partial'                => true,
					'event_id'               => $event_id,
					'event_created'          => true,
					'opportunity_created'    => false,
					'created_opportunities'  => is_array( $data ) ? ( $data['created_opportunities'] ?? array() ) : array(),
					'error_code'             => $opp_result->get_error_code(),
					'message'                => __( 'Your event was saved, but vendor spots were not published yet. Retry to finish publishing — nothing will be duplicated.', 'lnkup-marketplace' ),
					'detail'                 => $opp_result->get_error_message(),
					'view_event_url'         => $event_result['view_event_url'] ?? '',
				)
			);
		}

		$opp_id = isset( $opp_result['opportunity_id'] ) ? (int) $opp_result['opportunity_id'] : 0;

		return rest_ensure_response(
			array(
				'event_id'         => $event_id,
				'opportunity_id'   => $opp_id,
				'opportunity_ids'  => $opp_result['opportunity_ids'] ?? array( $opp_id ),
				'opportunities'    => $opp_result['opportunities'] ?? array(),
				'view_event_url'   => $opp_result['view_event_url'] ?? ( $event_result['view_event_url'] ?? '' ),
				'application_url'  => $opp_result['find_events_url'] ?? '',
				'operations_url'   => $opp_result['operations_url'] ?? '',
				'message'          => $opp_result['message'] ?? __( 'Event and opportunity published.', 'lnkup-marketplace' ),
			)
		);
	}

	/**
	 * Map REST JSON keys to onboarding service input.
	 *
	 * @param array<string, mixed> $body Request body.
	 * @return array<string, mixed>
	 */
	private static function map_request_to_onboarding_input( array $body ): array {
		$input = array();

		$map = array(
			'event_name'             => 'event_name',
			'event_date'             => 'event_date',
			'start_time'             => 'start_time',
			'end_time'               => 'end_time',
			'city'                   => 'city',
			'state'                  => 'state',
			'venue_name'             => 'venue_name',
			'venue_address'          => 'venue_address',
			'event_type'             => 'event_type',
			'expected_attendance'    => 'expected_attendance',
			'description'            => 'description',
			'ticketing_url'          => 'ticketing_url',
			'vendor_fee'             => 'vendor_fee',
			'spots_total'            => 'spots_total',
			'application_deadline'   => 'application_deadline',
			'indoor'                 => 'indoor',
			'outdoor'                => 'outdoor',
			'categories'             => 'categories',
			'organizer_notes'        => 'organizer_notes',
			'opportunity_title'      => 'opportunity_title',
			'document_mode'          => 'document_mode',
			'required_documents'     => 'required_documents',
			'client_token'           => 'client_token',
			'gallery_ids'            => 'gallery_ids',
			'featured_image_id'      => 'featured_image_id',
			'venue_hidden'           => 'venue_hidden',
		);

		foreach ( $map as $from => $to ) {
			if ( array_key_exists( $from, $body ) ) {
				$input[ $to ] = $body[ $from ];
			}
		}

		if ( empty( $input['event_name'] ) && ! empty( $body['title'] ) ) {
			$input['event_name'] = sanitize_text_field( (string) $body['title'] );
		}

		if ( empty( $input['document_mode'] ) && ! empty( $body['required_documents_mode'] ) ) {
			$input['document_mode'] = sanitize_key( (string) $body['required_documents_mode'] );
		}

		return $input;
	}

	/**
	 * Convert WP_Error to REST response.
	 *
	 * @param WP_Error $error Error.
	 * @return WP_Error
	 */
	private static function error_response( WP_Error $error ): WP_Error {
		$code   = $error->get_error_code();
		$status = 400;
		if ( 'forbidden' === $code || 'rest_forbidden' === $code ) {
			$status = 403;
		} elseif ( in_array( $code, array( 'missing_name', 'missing_date', 'invalid_spots', 'invalid_fee', 'invalid_category', 'spots_too_low', 'invalid_opportunity', 'invalid_event', 'missing_fee_rows' ), true ) ) {
			$status = 400;
		}
		return new WP_Error( $code, $error->get_error_message(), array( 'status' => $status ) );
	}
}

<?php
/**
 * REST routes for the organizer application review workspace and waitlist offers (lnkup/v1).
 *
 * Every route is behind application_review_v2_enabled (waitlist offers also need
 * waitlist_offers_enabled) and authorizes per event with LNKup_Capabilities::staff_can():
 *   review_applications  queue, workspace, rubric (read), waitlist (read)
 *   score_applications   own score
 *   manage_applications  decisions, reviewers, rubric (write), bulk, waitlist order and offers
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Application_Review_REST {

	const NS = 'lnkup/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		$id = array( 'id' => array( 'required' => true, 'type' => 'integer', 'minimum' => 1 ) );

		register_rest_route(
			self::NS,
			'/applications/review/queue',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'queue' ),
				'permission_callback' => array( __CLASS__, 'workspace_permission' ),
				'args'                => array(
					'group'          => array( 'type' => 'string', 'default' => '' ),
					'status'         => array( 'type' => 'string', 'default' => '' ),
					'event_id'       => array( 'type' => 'integer', 'default' => 0 ),
					'tour_id'        => array( 'type' => 'integer', 'default' => 0 ),
					'opportunity_id' => array( 'type' => 'integer', 'default' => 0 ),
					'assigned'       => array( 'type' => 'string', 'default' => '' ),
					'category'       => array( 'type' => 'string', 'default' => '' ),
					'missing_docs'   => array( 'type' => 'boolean', 'default' => false ),
					'search'         => array( 'type' => 'string', 'default' => '' ),
					'sort'           => array( 'type' => 'string', 'default' => 'newest' ),
					'page'           => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
					'per_page'       => array( 'type' => 'integer', 'default' => 25, 'minimum' => 1, 'maximum' => LNKup_Application_Review::MAX_PER_PAGE ),
				),
			)
		);
		register_rest_route( self::NS, '/applications/(?P<id>\d+)/workspace', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'workspace' ), 'permission_callback' => self::app_cap( 'review_applications' ), 'args' => $id ) );
		register_rest_route( self::NS, '/applications/(?P<id>\d+)/decision', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'decision' ), 'permission_callback' => self::app_cap( 'manage_applications' ), 'args' => $id ) );
		register_rest_route( self::NS, '/applications/(?P<id>\d+)/score', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'score' ), 'permission_callback' => self::app_cap( 'score_applications' ), 'args' => $id ) );
		register_rest_route( self::NS, '/applications/(?P<id>\d+)/reviewers', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'add_reviewer' ), 'permission_callback' => self::app_cap( 'manage_applications' ), 'args' => $id ) );
		register_rest_route(
			self::NS,
			'/applications/(?P<id>\d+)/reviewers/(?P<user_id>\d+)',
			array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'remove_reviewer' ), 'permission_callback' => self::app_cap( 'manage_applications' ), 'args' => $id )
		);
		register_rest_route(
			self::NS,
			'/events/(?P<id>\d+)/rubric',
			array(
				array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_rubric' ), 'permission_callback' => self::event_cap( 'review_applications' ), 'args' => $id ),
				array( 'methods' => 'PUT', 'callback' => array( __CLASS__, 'put_rubric' ), 'permission_callback' => self::event_cap( 'manage_applications' ), 'args' => $id ),
			)
		);
		register_rest_route( self::NS, '/events/(?P<id>\d+)/reviewers', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'reviewer_candidates' ), 'permission_callback' => self::event_cap( 'manage_applications' ), 'args' => $id ) );
		register_rest_route( self::NS, '/applications/bulk/preview', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'bulk_preview' ), 'permission_callback' => array( __CLASS__, 'workspace_permission' ) ) );
		register_rest_route( self::NS, '/applications/bulk/apply', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'bulk_apply' ), 'permission_callback' => array( __CLASS__, 'workspace_permission' ) ) );
		register_rest_route(
			self::NS,
			'/applications/bulk/jobs/(?P<job>[A-Za-z0-9]{8,40})',
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'bulk_job' ), 'permission_callback' => array( __CLASS__, 'workspace_permission' ) )
		);

		register_rest_route( self::NS, '/opportunities/(?P<id>\d+)/waitlist', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'waitlist' ), 'permission_callback' => self::opportunity_cap( 'review_applications' ), 'args' => $id ) );
		register_rest_route( self::NS, '/opportunities/(?P<id>\d+)/waitlist/reorder', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'waitlist_reorder' ), 'permission_callback' => self::opportunity_cap( 'manage_applications' ), 'args' => $id ) );
		register_rest_route(
			self::NS,
			'/applications/(?P<id>\d+)/waitlist-offer',
			array(
				array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'make_offer' ), 'permission_callback' => self::app_cap( 'manage_applications', true ), 'args' => $id ),
				array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'revoke_offer' ), 'permission_callback' => self::app_cap( 'manage_applications', true ), 'args' => $id ),
			)
		);
	}

	/* ---------- permissions ---------- */

	/**
	 * @param bool $offers Also require waitlist offers.
	 * @return true|WP_Error
	 */
	private static function feature_gate( $offers = false ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', __( 'Sign in to continue.', 'lnkup-marketplace' ), array( 'status' => 401 ) );
		}
		if ( ! LNKup_Feature_Flags::application_review() || ( $offers && ! LNKup_Feature_Flags::waitlist_offers() ) ) {
			return new WP_Error( 'lnkup_feature_off', __( 'This feature is not turned on yet.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( ! LNKup_Organizer_OS_Tables::ready() ) {
			return new WP_Error( 'lnkup_not_ready', __( 'This feature is still being set up. Try again in a minute.', 'lnkup-marketplace' ), array( 'status' => 503 ) );
		}
		return true;
	}

	/**
	 * Queue and bulk routes: anyone who can open the organizer workspace; rows and actions are
	 * scoped per event inside LNKup_Application_Review.
	 */
	public static function workspace_permission() {
		$gate = self::feature_gate();
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		return LNKup_Capabilities::can_access_org_workspace()
			? true
			: new WP_Error( 'lnkup_forbidden', __( 'Organizer access required.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
	}

	/**
	 * @param string $capability Capability on the application's event.
	 * @param bool   $offers     Also require waitlist offers.
	 */
	private static function app_cap( $capability, $offers = false ) {
		return static function ( WP_REST_Request $request ) use ( $capability, $offers ) {
			$gate = self::feature_gate( $offers );
			if ( is_wp_error( $gate ) ) {
				return $gate;
			}
			$app_id = absint( $request['id'] );
			if ( 'lnkup_application' !== get_post_type( $app_id ) ) {
				return new WP_Error( 'lnkup_not_found', __( 'Application not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
			}
			return LNKup_Capabilities::can_on_application( $app_id, $capability )
				? true
				: new WP_Error( 'lnkup_forbidden', __( 'You do not have access to this application.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		};
	}

	/**
	 * @param string $capability Capability on the event.
	 */
	private static function event_cap( $capability ) {
		return static function ( WP_REST_Request $request ) use ( $capability ) {
			$gate = self::feature_gate();
			if ( is_wp_error( $gate ) ) {
				return $gate;
			}
			$event_id = absint( $request['id'] );
			if ( 'lnkup_event' !== get_post_type( $event_id ) ) {
				return new WP_Error( 'lnkup_not_found', __( 'Event not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
			}
			return LNKup_Capabilities::staff_can( $event_id, $capability )
				? true
				: new WP_Error( 'lnkup_forbidden', __( 'You do not have access to this event.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		};
	}

	/**
	 * @param string $capability Capability on the opportunity's event.
	 */
	private static function opportunity_cap( $capability ) {
		return static function ( WP_REST_Request $request ) use ( $capability ) {
			$gate = self::feature_gate();
			if ( is_wp_error( $gate ) ) {
				return $gate;
			}
			$opp_id   = absint( $request['id'] );
			$event_id = (int) get_post_meta( $opp_id, '_lnkup_event_id', true );
			if ( 'lnkup_opportunity' !== get_post_type( $opp_id ) || ! $event_id ) {
				return new WP_Error( 'lnkup_not_found', __( 'Vendor slot not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
			}
			return LNKup_Capabilities::staff_can( $event_id, $capability )
				? true
				: new WP_Error( 'lnkup_forbidden', __( 'You do not have access to this event.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		};
	}

	/* ---------- handlers ---------- */

	public static function queue( WP_REST_Request $request ) {
		$result = LNKup_Application_Review::queue(
			get_current_user_id(),
			array(
				'group'          => (string) $request['group'],
				'status'         => (string) $request['status'],
				'event_id'       => (int) $request['event_id'],
				'tour_id'        => (int) $request['tour_id'],
				'opportunity_id' => (int) $request['opportunity_id'],
				'assigned'       => sanitize_text_field( (string) $request['assigned'] ),
				'category'       => (string) $request['category'],
				'missing_docs'   => (bool) $request['missing_docs'],
				'search'         => (string) $request['search'],
				'sort'           => sanitize_key( (string) $request['sort'] ),
				'page'           => (int) $request['page'],
				'per_page'       => (int) $request['per_page'],
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$response = rest_ensure_response( $result );
		$response->header( 'X-WP-Total', (string) $result['total'] );
		return $response;
	}

	public static function workspace( WP_REST_Request $request ) {
		$result = LNKup_Application_Review::workspace( (int) $request['id'], get_current_user_id() );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * One decision from the workspace; the canonical state machine validates the current status.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function decision( WP_REST_Request $request ) {
		$body   = self::body( $request );
		$action = sanitize_key( (string) ( $body['action'] ?? '' ) );
		if ( ! in_array( $action, array( 'approve', 'reject', 'waitlist', 'review', 'request_info' ), true ) ) {
			return new WP_Error( 'lnkup_bad_action', __( 'Choose a valid decision.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$extra = array(
			'rejection_reason' => sanitize_textarea_field( (string) ( $body['rejection_reason'] ?? '' ) ),
			'info_message'     => sanitize_textarea_field( (string) ( $body['info_message'] ?? '' ) ),
			'info_documents'   => array_map( 'sanitize_text_field', (array) ( $body['info_documents'] ?? array() ) ),
		);
		$result = LNKup_Applications::handle_organizer_action( (int) $request['id'], $action, get_current_user_id(), $extra );
		if ( is_wp_error( $result ) ) {
			$data   = $result->get_error_data();
			$status = is_array( $data ) && ! empty( $data['status'] ) ? (int) $data['status'] : ( 'forbidden' === $result->get_error_code() ? 403 : 409 );
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => $status ) );
		}
		return self::workspace( $request );
	}

	public static function score( WP_REST_Request $request ) {
		$result = LNKup_Application_Review::save_review( (int) $request['id'], get_current_user_id(), self::body( $request ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function add_reviewer( WP_REST_Request $request ) {
		$body   = self::body( $request );
		$result = LNKup_Application_Review::assign_reviewer( (int) $request['id'], absint( $body['user_id'] ?? 0 ), get_current_user_id() );
		return is_wp_error( $result ) ? $result : self::workspace( $request );
	}

	public static function remove_reviewer( WP_REST_Request $request ) {
		$result = LNKup_Application_Review::remove_reviewer( (int) $request['id'], (int) $request['user_id'], get_current_user_id() );
		return is_wp_error( $result ) ? $result : self::workspace( $request );
	}

	public static function get_rubric( WP_REST_Request $request ) {
		return rest_ensure_response( LNKup_Application_Review::rubric_for_event( (int) $request['id'] ) );
	}

	public static function put_rubric( WP_REST_Request $request ) {
		$body   = self::body( $request );
		$result = LNKup_Application_Review::save_rubric( (int) $request['id'], $body['criteria'] ?? array(), get_current_user_id() );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function reviewer_candidates( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'items' => LNKup_Application_Review::reviewer_candidates( (int) $request['id'] ) ) );
	}

	public static function bulk_preview( WP_REST_Request $request ) {
		$body   = self::body( $request );
		$result = LNKup_Application_Review::bulk_preview( (array) ( $body['ids'] ?? array() ), (string) ( $body['action'] ?? '' ), get_current_user_id() );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function bulk_apply( WP_REST_Request $request ) {
		$body   = self::body( $request );
		$result = LNKup_Application_Review::bulk_apply( (string) ( $body['token'] ?? '' ), get_current_user_id(), $body );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function bulk_job( WP_REST_Request $request ) {
		$result = LNKup_Application_Review::bulk_job_status( (string) $request['job'], get_current_user_id() );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function waitlist( WP_REST_Request $request ) {
		$opp_id = (int) $request['id'];
		return rest_ensure_response(
			array(
				'items'     => LNKup_Waitlist_Offers::waitlist( $opp_id ),
				'inventory' => LNKup_Opportunity_Inventory::summary( $opp_id ),
				'offers'    => LNKup_Feature_Flags::waitlist_offers(),
			)
		);
	}

	public static function waitlist_reorder( WP_REST_Request $request ) {
		$body   = self::body( $request );
		$result = LNKup_Waitlist_Offers::reorder( (int) $request['id'], (array) ( $body['order'] ?? array() ), get_current_user_id() );
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'items' => $result ) );
	}

	public static function make_offer( WP_REST_Request $request ) {
		$body   = self::body( $request );
		$result = LNKup_Waitlist_Offers::make_offer( (int) $request['id'], get_current_user_id(), absint( $body['hours'] ?? LNKup_Waitlist_Offers::DEFAULT_HOURS ) );
		return is_wp_error( $result ) ? $result : self::workspace( $request );
	}

	public static function revoke_offer( WP_REST_Request $request ) {
		$result = LNKup_Waitlist_Offers::revoke_offer( (int) $request['id'], get_current_user_id() );
		return is_wp_error( $result ) ? $result : self::workspace( $request );
	}

	private static function body( WP_REST_Request $request ) {
		$body = $request->get_json_params();
		return is_array( $body ) ? $body : (array) $request->get_body_params();
	}
}

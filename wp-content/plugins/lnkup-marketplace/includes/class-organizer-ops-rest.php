<?php
/**
 * REST routes for event readiness, organizer document review and the Overview (lnkup/v1).
 *
 * Readiness and Overview need organizer_ops_v2_enabled; documents need
 * organizer_document_review_enabled (which requires Passport document sharing).
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Organizer_Ops_REST {

	const NS = 'lnkup/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		$id = array( 'id' => array( 'required' => true, 'type' => 'integer', 'minimum' => 1 ) );

		register_rest_route( self::NS, '/events/(?P<id>\d+)/readiness', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'readiness' ), 'permission_callback' => self::event_cap( 'view_roster', 'ops' ), 'args' => $id ) );
		register_rest_route( self::NS, '/events/(?P<id>\d+)/readiness/(?P<key>[a-z_]+)', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'mark' ), 'permission_callback' => self::event_cap( 'edit_ops', 'ops' ), 'args' => $id ) );
		register_rest_route( self::NS, '/organizer/overview', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'overview' ), 'permission_callback' => array( __CLASS__, 'overview_permission' ) ) );

		register_rest_route(
			self::NS,
			'/events/(?P<id>\d+)/documents',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'event_documents' ),
				'permission_callback' => self::event_cap( 'view_documents', 'documents' ),
				'args'                => array_merge( $id, array( 'status' => array( 'type' => 'string', 'default' => '' ), 'type' => array( 'type' => 'string', 'default' => '' ) ) ),
			)
		);
		register_rest_route( self::NS, '/applications/(?P<id>\d+)/documents', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'application_documents' ), 'permission_callback' => self::app_cap( 'view_documents' ), 'args' => $id ) );
		register_rest_route( self::NS, '/applications/(?P<id>\d+)/documents/request', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'request_documents' ), 'permission_callback' => self::app_cap( 'review_documents' ), 'args' => $id ) );
		register_rest_route( self::NS, '/applications/(?P<id>\d+)/documents/(?P<type>[a-z0-9_]+)/review', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'review_document' ), 'permission_callback' => self::app_cap( 'review_documents' ), 'args' => $id ) );
		register_rest_route( self::NS, '/applications/(?P<id>\d+)/documents/(?P<type>[a-z0-9_]+)/waive', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'waive_document' ), 'permission_callback' => self::app_cap( 'review_documents' ), 'args' => $id ) );
	}

	/* ---------- permissions ---------- */

	/**
	 * @param string $feature ops|documents.
	 * @return true|WP_Error
	 */
	private static function gate( $feature ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', __( 'Sign in to continue.', 'lnkup-marketplace' ), array( 'status' => 401 ) );
		}
		$on = 'documents' === $feature ? LNKup_Feature_Flags::document_review() : LNKup_Feature_Flags::organizer_ops();
		if ( ! $on ) {
			return new WP_Error( 'lnkup_feature_off', __( 'This feature is not turned on yet.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( ! LNKup_Organizer_OS_Tables::ready( '2' ) ) {
			return new WP_Error( 'lnkup_not_ready', __( 'This feature is still being set up. Try again in a minute.', 'lnkup-marketplace' ), array( 'status' => 503 ) );
		}
		return true;
	}

	public static function overview_permission() {
		$gate = self::gate( 'ops' );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		return LNKup_Capabilities::can_access_org_workspace() ? true : new WP_Error( 'lnkup_forbidden', __( 'Organizer access required.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
	}

	/**
	 * @param string $capability Capability on the event.
	 * @param string $feature    ops|documents.
	 */
	private static function event_cap( $capability, $feature ) {
		return static function ( WP_REST_Request $request ) use ( $capability, $feature ) {
			$gate = self::gate( $feature );
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
	 * @param string $capability Capability on the application's event.
	 */
	private static function app_cap( $capability ) {
		return static function ( WP_REST_Request $request ) use ( $capability ) {
			$gate = self::gate( 'documents' );
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

	/* ---------- handlers ---------- */

	public static function readiness( WP_REST_Request $request ) {
		$event_id = (int) $request['id'];
		$out      = LNKup_Event_Readiness::evaluate( $event_id );
		$out['can_edit'] = LNKup_Capabilities::staff_can( $event_id, 'edit_ops' );
		$out['tasks']    = LNKup_Event_Tasks::event_counts( $event_id );
		return rest_ensure_response( $out );
	}

	public static function mark( WP_REST_Request $request ) {
		$body   = self::body( $request );
		$result = LNKup_Event_Readiness::mark( (int) $request['id'], (string) $request['key'], (string) ( $body['state'] ?? '' ), (string) ( $body['note'] ?? '' ), get_current_user_id() );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function overview() {
		return rest_ensure_response( LNKup_Organizer_Overview::for_user( get_current_user_id() ) );
	}

	public static function event_documents( WP_REST_Request $request ) {
		$event_id = (int) $request['id'];
		$rows     = LNKup_Organizer_Documents::requirements_for_event(
			$event_id,
			array(
				'status' => sanitize_key( (string) $request['status'] ),
				'type'   => sanitize_key( (string) $request['type'] ),
				'viewer' => get_current_user_id(),
			)
		);
		return rest_ensure_response(
			array(
				'items'      => $rows,
				'counts'     => LNKup_Organizer_Documents::counts_for_event( $event_id ),
				'types'      => LNKup_Documents::document_types(),
				'can_review' => LNKup_Capabilities::staff_can( $event_id, 'review_documents' ),
				'can_waive'  => LNKup_Capabilities::organizer_owns_event( $event_id ) || ( $rows && LNKup_Organizer_Documents::can_waive( (int) $rows[0]['application_id'], get_current_user_id() ) ),
				'is_owner'   => LNKup_Capabilities::organizer_owns_event( $event_id ),
			)
		);
	}

	public static function application_documents( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'items' => LNKup_Organizer_Documents::requirements_for_application( (int) $request['id'], get_current_user_id() ) ) );
	}

	public static function request_documents( WP_REST_Request $request ) {
		$body   = self::body( $request );
		$result = LNKup_Organizer_Documents::request( (int) $request['id'], array_map( 'sanitize_key', (array) ( $body['types'] ?? array() ) ), (string) ( $body['message'] ?? '' ), get_current_user_id() );
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'items' => $result ) );
	}

	public static function review_document( WP_REST_Request $request ) {
		$body   = self::body( $request );
		$result = LNKup_Organizer_Documents::review( (int) $request['id'], (string) $request['type'], sanitize_key( (string) ( $body['decision'] ?? '' ) ), (string) ( $body['reason'] ?? '' ), get_current_user_id() );
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'items' => $result ) );
	}

	public static function waive_document( WP_REST_Request $request ) {
		$body   = self::body( $request );
		$result = LNKup_Organizer_Documents::waive( (int) $request['id'], (string) $request['type'], (string) ( $body['reason'] ?? '' ), get_current_user_id() );
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'items' => $result ) );
	}

	private static function body( WP_REST_Request $request ) {
		$body = $request->get_json_params();
		return is_array( $body ) ? $body : (array) $request->get_body_params();
	}
}

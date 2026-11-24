<?php
/**
 * Operations REST API.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Operations_REST {

	const NS = 'lnkup/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		$org    = array( 'permission_callback' => array( __CLASS__, 'organizer_permission' ) );
		$vendor = array( 'permission_callback' => array( __CLASS__, 'vendor_permission' ) );
		$view   = self::event_cap( 'view_roster' );
		$ops    = self::event_cap( 'edit_ops' );
		$owner  = self::event_cap( 'owner' );

		register_rest_route( self::NS, '/operations/events/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'event_snapshot' ),
			'permission_callback' => $view,
		) );

		register_rest_route( self::NS, '/operations/events/(?P<id>\d+)/defaults', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'event_defaults' ),
			'permission_callback' => $view,
		) );

		register_rest_route( self::NS, '/operations/events/(?P<id>\d+)/lifecycle', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_lifecycle' ), 'permission_callback' => $view ),
			array( 'methods' => 'PUT', 'callback' => array( __CLASS__, 'set_lifecycle' ), 'permission_callback' => $owner ),
		) );

		register_rest_route( self::NS, '/operations/events/(?P<id>\d+)/vendors', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'event_vendors' ),
			'permission_callback' => $view,
		) );

		register_rest_route( self::NS, '/operations/events/(?P<id>\d+)/vendors/(?P<app_id>\d+)/status', array(
			'methods'             => 'PUT',
			'callback'            => array( __CLASS__, 'set_vendor_status' ),
			'permission_callback' => self::event_cap( 'check_in' ),
		) );

		register_rest_route( self::NS, '/operations/events/(?P<id>\d+)/feed', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'event_feed' ),
			'permission_callback' => $view,
		) );

		register_rest_route( self::NS, '/operations/events/(?P<id>\d+)/compliance', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'event_compliance' ),
			'permission_callback' => $view,
		) );

		register_rest_route( self::NS, '/operations/events/(?P<id>\d+)/booths', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_booths' ), 'permission_callback' => $view ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save_booth' ), 'permission_callback' => $ops ),
		) );

		register_rest_route( self::NS, '/operations/events/(?P<id>\d+)/booths/(?P<booth_id>\d+)/assign', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'assign_booth' ),
			'permission_callback' => $ops,
		) );

		register_rest_route( self::NS, '/operations/events/(?P<id>\d+)/booths/(?P<booth_id>\d+)/unassign', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'unassign_booth' ),
			'permission_callback' => $ops,
		) );

		register_rest_route( self::NS, '/operations/events/(?P<id>\d+)/booths/swap', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'swap_booths' ),
			'permission_callback' => $ops,
		) );

		register_rest_route( self::NS, '/operations/events/(?P<id>\d+)/parking', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_parking' ), 'permission_callback' => $view ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save_parking' ), 'permission_callback' => $ops ),
		) );

		register_rest_route( self::NS, '/operations/events/(?P<id>\d+)/loadin', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_loadin' ), 'permission_callback' => $view ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save_loadin' ), 'permission_callback' => $ops ),
		) );

		register_rest_route( self::NS, '/operations/events/(?P<id>\d+)/broadcasts', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_broadcasts' ), 'permission_callback' => $view ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'send_broadcast' ), 'permission_callback' => self::event_cap( 'broadcast' ) ),
		) );

		register_rest_route( self::NS, '/operations/events/(?P<id>\d+)/broadcasts/preview', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'preview_broadcast' ),
			'permission_callback' => self::event_cap( 'broadcast' ),
			'args'                => array(
				'audience' => array( 'type' => 'string', 'default' => 'all', 'sanitize_callback' => 'sanitize_key' ),
			),
		) );

		register_rest_route( self::NS, '/operations/events/(?P<id>\d+)/staff', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_staff' ), 'permission_callback' => $view ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'assign_staff' ), 'permission_callback' => self::event_cap( 'manage_staff' ) ),
		) );

		register_rest_route( self::NS, '/operations/events/(?P<id>\d+)/analytics', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'event_analytics' ),
			'permission_callback' => self::event_cap( 'view_intelligence' ),
		) );

		register_rest_route( self::NS, '/operations/events/(?P<id>\d+)/incidents', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_incidents' ), 'permission_callback' => $view ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create_incident' ), 'permission_callback' => $view ),
		) );

		register_rest_route( self::NS, '/operations/incidents/(?P<id>\d+)', array(
			'methods'             => 'PUT',
			'callback'            => array( __CLASS__, 'update_incident' ),
			'permission_callback' => self::row_cap( 'incidents', 'edit_ops' ),
		) );

		register_rest_route( self::NS, '/operations/applications/(?P<id>\d+)/readiness', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'app_readiness' ),
			'permission_callback' => array( __CLASS__, 'app_access_permission' ),
		) );

		register_rest_route( self::NS, '/operations/applications/(?P<id>\d+)/support', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'app_support' ),
			'permission_callback' => array( __CLASS__, 'app_access_permission' ),
		) );

		register_rest_route( self::NS, '/operations/applications/(?P<id>\d+)/packet', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'app_packet' ),
			'permission_callback' => array( __CLASS__, 'vendor_app_permission' ),
		) );

		register_rest_route( self::NS, '/operations/applications/(?P<id>\d+)/packet/engage', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'packet_engage' ),
			'permission_callback' => array( __CLASS__, 'vendor_app_permission' ),
		) );

		register_rest_route( self::NS, '/operations/applications/(?P<id>\d+)/checkin', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'app_checkin' ),
			'permission_callback' => array( __CLASS__, 'checkin_permission' ),
		) );

		register_rest_route( self::NS, '/operations/qr/scan', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'qr_scan' ),
			'permission_callback' => array( __CLASS__, 'scan_permission' ),
		) );

		register_rest_route( self::NS, '/operations/support', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_support' ),
				'permission_callback' => array( __CLASS__, 'support_list_permission' ),
				'args'                => array(
					'event_id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create_support' ), 'permission_callback' => array( __CLASS__, 'vendor_permission' ) ),
		) );

		register_rest_route( self::NS, '/operations/support/(?P<id>\d+)', array(
			'methods'             => 'PUT',
			'callback'            => array( __CLASS__, 'update_support' ),
			'permission_callback' => self::row_cap( 'support', 'edit_ops' ),
		) );

		register_rest_route( self::NS, '/operations/vendor-home', array_merge( $vendor, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'vendor_home' ),
		) ) );

		register_rest_route( self::NS, '/operations/vendor-homes', array_merge( $vendor, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'vendor_homes_batch' ),
		) ) );

		register_rest_route( self::NS, '/operations/dashboard/widgets', array_merge( $org, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'dashboard_widgets' ),
		) ) );
	}

	/**
	 * Organizer-level routes with no event in the path (dashboard widgets).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function organizer_permission( WP_REST_Request $request ) {
		unset( $request );
		return current_user_can( 'lnkup_review_applications' ) || LNKup_Capabilities::user_has_manage_all();
	}

	/**
	 * Permission callback requiring an event-scoped capability on the `{id}` event.
	 *
	 * @param string $capability LNKup_Capabilities::staff_can() capability.
	 * @return callable
	 */
	private static function event_cap( $capability ) {
		return static function ( WP_REST_Request $request ) use ( $capability ) {
			return self::check_event_cap( absint( $request['id'] ), $capability );
		};
	}

	/**
	 * Permission callback for routes whose `{id}` is a row in an operations table.
	 * The row's event decides access, so IDs from another tenant are rejected.
	 *
	 * @param string $table      incidents|support.
	 * @param string $capability Capability on the row's event.
	 * @return callable
	 */
	private static function row_cap( $table, $capability ) {
		return static function ( WP_REST_Request $request ) use ( $table, $capability ) {
			global $wpdb;
			$name     = 'incidents' === $table ? LNKup_Operations_Tables::incidents_table() : LNKup_Operations_Tables::support_table();
			$event_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$name} WHERE id = %d", absint( $request['id'] ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $event_id ) {
				return new WP_Error( 'not_found', __( 'Not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
			}
			return self::check_event_cap( $event_id, $capability );
		};
	}

	/**
	 * Resolve event access to true or a REST error.
	 *
	 * @param int    $event_id   Event ID.
	 * @param string $capability Capability.
	 * @return true|WP_Error
	 */
	private static function check_event_cap( $event_id, $capability ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', __( 'Sign in to continue.', 'lnkup-marketplace' ), array( 'status' => 401 ) );
		}
		if ( ! $event_id || 'lnkup_event' !== get_post_type( $event_id ) ) {
			return new WP_Error( 'not_found', __( 'Event not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( ! LNKup_Capabilities::staff_can( $event_id, $capability ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have access to this for this event.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Support tickets are listed per event the caller can view.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function support_list_permission( WP_REST_Request $request ) {
		return self::check_event_cap( absint( $request->get_param( 'event_id' ) ), 'view_roster' );
	}

	public static function vendor_permission() {
		return current_user_can( 'lnkup_manage_own_profile' ) || LNKup_Capabilities::user_has_manage_all();
	}

	public static function vendor_app_permission( WP_REST_Request $request ) {
		return LNKup_Capabilities::vendor_owns_application( absint( $request['id'] ) ) || self::organizer_for_app( $request );
	}

	public static function app_access_permission( WP_REST_Request $request ) {
		return self::vendor_app_permission( $request );
	}

	/**
	 * Check-in uses the application id, then the event that application belongs to.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public static function checkin_permission( WP_REST_Request $request ) {
		$app_id = absint( $request['id'] );
		$post   = $app_id ? get_post( $app_id ) : null;
		if ( ! $post || 'lnkup_application' !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Application not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$event_id = (int) get_post_meta( $app_id, '_lnkup_event_id', true );
		if ( ! LNKup_Checkins::actor_can_operate( $event_id ) ) {
			return new WP_Error( 'forbidden', __( 'You cannot manage this event.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		return true;
	}

	const SCAN_RATE_LIMIT  = 120;
	const SCAN_RATE_WINDOW = 300;

	/**
	 * QR scans must name the event being worked, and the actor must be able to check vendors in there.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function scan_permission( WP_REST_Request $request ) {
		$body     = $request->get_json_params() ?: $request->get_params();
		$event_id = absint( $body['event_id'] ?? 0 );
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', __( 'Sign in to continue.', 'lnkup-marketplace' ), array( 'status' => 401 ) );
		}
		if ( ! $event_id ) {
			return new WP_Error( 'event_required', __( 'Open check-in from an event before scanning.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$allowed = self::check_event_cap( $event_id, 'check_in' );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$key   = 'lnkup_qr_scan_' . get_current_user_id();
		$count = (int) get_transient( $key );
		if ( $count >= self::SCAN_RATE_LIMIT ) {
			return new WP_Error( 'rate_limited', __( 'Too many scans in a short time. Wait a moment and try again.', 'lnkup-marketplace' ), array( 'status' => 429 ) );
		}
		set_transient( $key, $count + 1, self::SCAN_RATE_WINDOW );
		return true;
	}

	private static function organizer_for_app( WP_REST_Request $request ) {
		$app_id = absint( $request['id'] ?? $request['app_id'] ?? 0 );
		$event_id = (int) get_post_meta( $app_id, '_lnkup_event_id', true );
		return LNKup_Capabilities::staff_can( $event_id, 'view_roster' );
	}

	public static function event_snapshot( WP_REST_Request $request ) {
		$event_id = absint( $request['id'] );
		if ( class_exists( 'LNKup_Event_Operations' ) ) {
			LNKup_Event_Operations::bootstrap_from_event( $event_id );
		}
		$data = LNKup_Event_Operations::snapshot( $event_id, get_current_user_id() );
		if ( ! $data ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		return rest_ensure_response( $data );
	}

	public static function event_defaults( WP_REST_Request $request ) {
		$event_id = absint( $request['id'] );
		if ( ! LNKup_Capabilities::organizer_owns_event( $event_id ) && ! LNKup_Capabilities::user_has_manage_all() ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		return rest_ensure_response( LNKup_Event_Operations::defaults_for_event( $event_id ) );
	}

	public static function get_lifecycle( WP_REST_Request $request ) {
		return rest_ensure_response( LNKup_Event_Lifecycle::get( absint( $request['id'] ) ) );
	}

	public static function set_lifecycle( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: $request->get_params();
		LNKup_Event_Lifecycle::set_stage( absint( $request['id'] ), $body['stage'] ?? '' );
		return rest_ensure_response( LNKup_Event_Lifecycle::get( absint( $request['id'] ) ) );
	}

	public static function event_vendors( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'columns' => LNKup_Event_Operations::vendors_for_kanban( absint( $request['id'] ) ) ) );
	}

	public static function set_vendor_status( WP_REST_Request $request ) {
		$body     = $request->get_json_params() ?: $request->get_params();
		$event_id = absint( $request['id'] );
		$app_id   = absint( $request['app_id'] );
		$status   = sanitize_key( (string) ( $body['status'] ?? '' ) );
		$map      = array(
			'checked_in'  => 'checked_in',
			'setting_up'  => 'setup_started',
			'live_ready'  => 'setup_complete',
			'checked_out' => 'checked_out',
			'late'        => 'late_arrival',
			'no_show'     => 'no_show',
		);
		if ( isset( $map[ $status ] ) ) {
			if ( ! empty( $body['override'] ) ) {
				if ( ! LNKup_Capabilities::staff_can( $event_id, 'edit_ops' ) ) {
					return new WP_Error( 'forbidden', __( 'Only the event owner or operations staff can override check-in rules.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
				}
				return LNKup_Checkins::rest_response( LNKup_Checkins::record_override( $app_id, $map[ $status ], (string) ( $body['reason'] ?? '' ), $event_id ) );
			}
			return LNKup_Checkins::rest_response( LNKup_Checkins::record( $app_id, $map[ $status ], 'manual', $event_id ) );
		}
		// Flags only: approval/payment states come from the booking, never from event-day status.
		if ( ! in_array( $status, array( 'needs_help', 'missing' ), true ) ) {
			return new WP_Error( 'invalid_status', __( 'That status cannot be set from event operations.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$state = LNKup_Checkins::evaluate( $app_id, '', get_current_user_id(), $event_id );
		if ( in_array( (string) ( $state['reason'] ?? '' ), array( 'not_found', 'wrong_event', 'forbidden', 'invalid_booking' ), true ) ) {
			return LNKup_Checkins::rest_response( $state );
		}
		if ( ! LNKup_Event_Operations::set_ops_status( $app_id, $status ) ) {
			return new WP_Error( 'write_failed', __( 'The status could not be saved.', 'lnkup-marketplace' ), array( 'status' => 500 ) );
		}
		return rest_ensure_response(
			array(
				'success'        => true,
				'application_id' => $app_id,
				'event_id'       => $event_id,
				'ops_status'     => $status,
			)
		);
	}

	public static function event_feed( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'items' => LNKup_Operations_Log::get_for_event( absint( $request['id'] ), 50 ) ) );
	}

	public static function event_compliance( WP_REST_Request $request ) {
		$alerts = LNKup_Event_Operations::compliance_alerts( absint( $request['id'] ) );
		return rest_ensure_response(
			array(
				'alerts' => $alerts,
				'groups' => LNKup_Event_Operations::group_compliance_alerts( $alerts ),
			)
		);
	}

	public static function get_booths( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'items' => LNKup_Booths::get_for_event( absint( $request['id'] ) ) ) );
	}

	public static function save_booth( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: $request->get_params();
		$id = LNKup_Booths::save( absint( $request['id'] ), $body );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return rest_ensure_response( array( 'id' => $id ) );
	}

	public static function assign_booth( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: $request->get_params();
		$result = LNKup_Booths::assign( absint( $request['booth_id'] ), absint( $body['application_id'] ?? 0 ), absint( $request['id'] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'success' => true ) );
	}

	public static function unassign_booth( WP_REST_Request $request ) {
		$result = LNKup_Booths::unassign( absint( $request['booth_id'] ), absint( $request['id'] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'success' => true ) );
	}

	public static function swap_booths( WP_REST_Request $request ) {
		$body   = $request->get_json_params() ?: $request->get_params();
		$result = LNKup_Booths::swap( absint( $body['booth_a'] ?? 0 ), absint( $body['booth_b'] ?? 0 ), absint( $request['id'] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'success' => true ) );
	}

	public static function get_parking( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'items' => LNKup_Parking::get_for_event( absint( $request['id'] ) ) ) );
	}

	public static function save_parking( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: $request->get_params();
		$id = LNKup_Parking::save( absint( $request['id'] ), $body );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return rest_ensure_response( array( 'id' => $id ) );
	}

	public static function get_loadin( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'windows' => LNKup_Loadin_Schedule::get_windows( absint( $request['id'] ) ) ) );
	}

	public static function save_loadin( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: $request->get_params();
		if ( ! empty( $body['assign'] ) ) {
			$result = LNKup_Loadin_Schedule::assign( absint( $body['window_id'] ), absint( $body['application_id'] ), absint( $request['id'] ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return rest_ensure_response( array( 'success' => true ) );
		}
		$id = LNKup_Loadin_Schedule::create_window( absint( $request['id'] ), $body );
		return rest_ensure_response( array( 'id' => $id ) );
	}

	public static function get_broadcasts( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'items' => LNKup_Broadcasts::get_for_event( absint( $request['id'] ) ) ) );
	}

	public static function send_broadcast( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: $request->get_params();
		$result = LNKup_Broadcasts::send( absint( $request['id'] ), get_current_user_id(), $body );
		return rest_ensure_response( $result );
	}

	public static function preview_broadcast( WP_REST_Request $request ) {
		$audience = (string) $request['audience'];
		if ( ! isset( LNKup_Broadcasts::audience_presets()[ $audience ] ) ) {
			return new WP_Error( 'invalid_audience', __( 'Choose who should receive this message.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		return rest_ensure_response(
			array(
				'audience'        => $audience,
				'recipient_count' => count( LNKup_Broadcasts::resolve_audience( absint( $request['id'] ), $audience ) ),
			)
		);
	}

	public static function get_staff( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'items' => LNKup_Event_Staff::get_for_event( absint( $request['id'] ) ) ) );
	}

	public static function assign_staff( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: $request->get_params();
		if ( ! empty( $body['remove'] ) ) {
			LNKup_Event_Staff::remove( absint( $request['id'] ), absint( $body['user_id'] ) );
			return rest_ensure_response( array( 'success' => true ) );
		}
		$id = LNKup_Event_Staff::assign( absint( $request['id'] ), absint( $body['user_id'] ), $body['staff_role'] ?? 'coordinator' );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return rest_ensure_response( array( 'id' => $id ) );
	}

	public static function event_analytics( WP_REST_Request $request ) {
		return rest_ensure_response( LNKup_Ops_Analytics::for_event( absint( $request['id'] ) ) );
	}

	public static function get_incidents( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'items' => LNKup_Incidents::get_for_event( absint( $request['id'] ) ) ) );
	}

	public static function create_incident( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: $request->get_params();
		$id = LNKup_Incidents::create( absint( $request['id'] ), get_current_user_id(), $body );
		return rest_ensure_response( array( 'id' => $id ) );
	}

	public static function update_incident( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: $request->get_params();
		unset( $body['event_id'] );
		LNKup_Incidents::update( absint( $request['id'] ), $body );
		return rest_ensure_response( array( 'success' => true ) );
	}

	public static function app_readiness( WP_REST_Request $request ) {
		return rest_ensure_response( LNKup_Vendor_Readiness::checklist( absint( $request['id'] ) ) );
	}

	public static function app_packet( WP_REST_Request $request ) {
		return rest_ensure_response( LNKup_Packets::get_dto( absint( $request['id'] ) ) );
	}

	public static function packet_engage( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: $request->get_params();
		$dto = LNKup_Packets::get_dto( absint( $request['id'] ) );
		if ( empty( $dto['packet_id'] ) ) {
			return new WP_Error( 'no_packet', __( 'Packet not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		LNKup_Packets::record_engagement( $dto['packet_id'], $body['section'] ?? '', $body['action'] ?? 'viewed' );
		return rest_ensure_response( array( 'success' => true ) );
	}

	public static function app_checkin( WP_REST_Request $request ) {
		$body   = $request->get_json_params() ?: $request->get_params();
		$result = LNKup_Checkins::record( absint( $request['id'] ), $body['action'] ?? 'checked_in', sanitize_key( (string) ( $body['method'] ?? 'manual' ) ) );
		return LNKup_Checkins::rest_response( $result );
	}

	public static function qr_scan( WP_REST_Request $request ) {
		$body   = $request->get_json_params() ?: $request->get_params();
		$result = LNKup_Checkins::scan_qr( $body['token'] ?? '', absint( $body['event_id'] ?? 0 ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( empty( $result['success'] ) && 'wrong_event' === (string) ( $result['reason'] ?? '' ) ) {
			// Same answer as an unknown token, so passes for other events cannot be probed from here.
			return new WP_Error( 'not_found', __( 'This pass is not valid for this event.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$app_id = absint( $result['application_id'] ?? 0 );
		if ( $app_id && ! empty( $result['success'] ) ) {
			$result['business_name'] = class_exists( 'LNKup_Event_Operations' ) ? LNKup_Event_Operations::vendor_name_for_app( $app_id ) : '';
			$result['booth']         = (string) get_post_meta( $app_id, '_lnkup_booth_placeholder', true );
		}
		return LNKup_Checkins::rest_response( $result );
	}

	public static function list_support( WP_REST_Request $request ) {
		$event_id = absint( $request->get_param( 'event_id' ) );
		return rest_ensure_response( array( 'items' => LNKup_Support_Requests::get_for_event( $event_id ) ) );
	}

	public static function create_support( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: $request->get_params();
		$result = LNKup_Support_Requests::create( get_current_user_id(), $body );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'id' => $result ) );
	}

	public static function update_support( WP_REST_Request $request ) {
		$body   = $request->get_json_params() ?: $request->get_params();
		$result = LNKup_Support_Requests::update( absint( $request['id'] ), $body );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'success' => true ) );
	}

	public static function app_support( WP_REST_Request $request ) {
		$app_id = absint( $request['id'] );
		return rest_ensure_response( array( 'items' => LNKup_Support_Requests::get_for_application( $app_id ) ) );
	}

	public static function vendor_home( WP_REST_Request $request ) {
		$app_id = absint( $request->get_param( 'application_id' ) );
		if ( $app_id ) {
			$data = LNKup_Event_Operations::vendor_home_for_application( $app_id, get_current_user_id() );
		} else {
			$data = LNKup_Event_Operations::vendor_home( get_current_user_id() );
		}
		return rest_ensure_response( $data );
	}

	/**
	 * Batch vendor-home enrichment for mobile ops (cuts N+1).
	 *
	 * Query: application_ids=1,2,3 (max 20).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function vendor_homes_batch( WP_REST_Request $request ) {
		$raw = $request->get_param( 'application_ids' );
		if ( is_string( $raw ) ) {
			$ids = array_map( 'absint', explode( ',', $raw ) );
		} else {
			$ids = array_map( 'absint', (array) $raw );
		}
		$ids     = array_values( array_filter( array_unique( $ids ) ) );
		$ids     = array_slice( $ids, 0, 20 );
		$user_id = get_current_user_id();
		$items   = array();
		foreach ( $ids as $app_id ) {
			if ( ! LNKup_Capabilities::vendor_owns_application( $app_id, $user_id ) && ! LNKup_Capabilities::user_has_manage_all( $user_id ) ) {
				continue;
			}
			$data = LNKup_Event_Operations::vendor_home_for_application( $app_id, $user_id );
			if ( is_array( $data ) ) {
				$data['application_id'] = $app_id;
				$items[]                = $data;
			}
		}
		return rest_ensure_response( array( 'items' => $items ) );
	}

	public static function dashboard_widgets( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$events = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'posts_per_page' => 5,
				'meta_key'       => '_lnkup_organizer_user_id',
				'meta_value'     => $user_id,
				'meta_query'     => array(
					array(
						'key'     => '_lnkup_event_date',
						'value'   => gmdate( 'Y-m-d' ),
						'compare' => '>=',
					),
				),
			)
		);
		$today_events = array();
		foreach ( $events as $event ) {
			$snap = LNKup_Event_Operations::snapshot( $event->ID, $user_id );
			if ( $snap ) {
				$today_events[] = array(
					'event_id' => $event->ID,
					'title'    => $event->post_title,
					'kpis'     => $snap['kpis'],
				);
			}
		}
		global $wpdb;
		$support = LNKup_Operations_Tables::support_table();
		$open_support = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$support} s INNER JOIN {$wpdb->posts} e ON e.ID = s.event_id INNER JOIN {$wpdb->postmeta} m ON m.post_id = e.ID AND m.meta_key = '_lnkup_organizer_user_id' AND m.meta_value = %d WHERE s.status = 'open'",
				$user_id
			)
		);
		return rest_ensure_response(
			array(
				'today_events'  => $today_events,
				'open_support'  => $open_support,
				'open_incidents'=> 0,
			)
		);
	}
}

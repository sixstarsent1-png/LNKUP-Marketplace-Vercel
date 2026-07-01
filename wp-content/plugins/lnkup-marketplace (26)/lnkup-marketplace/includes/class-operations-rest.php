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
		$org = array( 'permission_callback' => array( __CLASS__, 'organizer_permission' ) );
		$vendor = array( 'permission_callback' => array( __CLASS__, 'vendor_permission' ) );

		register_rest_route( self::NS, '/operations/events/(?P<id>\d+)', array_merge( $org, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'event_snapshot' ),
		) ) );

		register_rest_route( self::NS, '/operations/events/(?P<id>\d+)/defaults', array_merge( $org, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'event_defaults' ),
		) ) );

		register_rest_route( self::NS, '/operations/events/(?P<id>\d+)/lifecycle', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_lifecycle' ), 'permission_callback' => array( __CLASS__, 'organizer_permission' ) ),
			array( 'methods' => 'PUT', 'callback' => array( __CLASS__, 'set_lifecycle' ), 'permission_callback' => array( __CLASS__, 'organizer_permission' ) ),
		) );

		register_rest_route( self::NS, '/operations/events/(?P<id>\d+)/vendors', array_merge( $org, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'event_vendors' ),
		) ) );

		register_rest_route( self::NS, '/operations/events/(?P<id>\d+)/vendors/(?P<app_id>\d+)/status', array_merge( $org, array(
			'methods'  => 'PUT',
			'callback' => array( __CLASS__, 'set_vendor_status' ),
		) ) );

		register_rest_route( self::NS, '/operations/events/(?P<id>\d+)/feed', array_merge( $org, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'event_feed' ),
		) ) );

		register_rest_route( self::NS, '/operations/events/(?P<id>\d+)/compliance', array_merge( $org, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'event_compliance' ),
		) ) );

		register_rest_route( self::NS, '/operations/events/(?P<id>\d+)/booths', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_booths' ), 'permission_callback' => array( __CLASS__, 'organizer_permission' ) ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save_booth' ), 'permission_callback' => array( __CLASS__, 'organizer_permission' ) ),
		) );

		register_rest_route( self::NS, '/operations/events/(?P<id>\d+)/booths/(?P<booth_id>\d+)/assign', array_merge( $org, array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'assign_booth' ),
		) ) );

		register_rest_route( self::NS, '/operations/events/(?P<id>\d+)/parking', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_parking' ), 'permission_callback' => array( __CLASS__, 'organizer_permission' ) ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save_parking' ), 'permission_callback' => array( __CLASS__, 'organizer_permission' ) ),
		) );

		register_rest_route( self::NS, '/operations/events/(?P<id>\d+)/loadin', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_loadin' ), 'permission_callback' => array( __CLASS__, 'organizer_permission' ) ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save_loadin' ), 'permission_callback' => array( __CLASS__, 'organizer_permission' ) ),
		) );

		register_rest_route( self::NS, '/operations/events/(?P<id>\d+)/broadcasts', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_broadcasts' ), 'permission_callback' => array( __CLASS__, 'organizer_permission' ) ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'send_broadcast' ), 'permission_callback' => array( __CLASS__, 'organizer_permission' ) ),
		) );

		register_rest_route( self::NS, '/operations/events/(?P<id>\d+)/staff', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_staff' ), 'permission_callback' => array( __CLASS__, 'organizer_permission' ) ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'assign_staff' ), 'permission_callback' => array( __CLASS__, 'organizer_permission' ) ),
		) );

		register_rest_route( self::NS, '/operations/events/(?P<id>\d+)/analytics', array_merge( $org, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'event_analytics' ),
		) ) );

		register_rest_route( self::NS, '/operations/events/(?P<id>\d+)/incidents', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_incidents' ), 'permission_callback' => array( __CLASS__, 'organizer_permission' ) ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create_incident' ), 'permission_callback' => array( __CLASS__, 'organizer_permission' ) ),
		) );

		register_rest_route( self::NS, '/operations/incidents/(?P<id>\d+)', array_merge( $org, array(
			'methods'  => 'PUT',
			'callback' => array( __CLASS__, 'update_incident' ),
		) ) );

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

		register_rest_route( self::NS, '/operations/applications/(?P<id>\d+)/checkin', array_merge( $org, array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'app_checkin' ),
		) ) );

		register_rest_route( self::NS, '/operations/qr/scan', array_merge( $org, array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'qr_scan' ),
		) ) );

		register_rest_route( self::NS, '/operations/support', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'list_support' ), 'permission_callback' => array( __CLASS__, 'organizer_permission' ) ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create_support' ), 'permission_callback' => array( __CLASS__, 'vendor_permission' ) ),
		) );

		register_rest_route( self::NS, '/operations/support/(?P<id>\d+)', array_merge( $org, array(
			'methods'  => 'PUT',
			'callback' => array( __CLASS__, 'update_support' ),
		) ) );

		register_rest_route( self::NS, '/operations/vendor-home', array_merge( $vendor, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'vendor_home' ),
		) ) );

		register_rest_route( self::NS, '/operations/dashboard/widgets', array_merge( $org, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'dashboard_widgets' ),
		) ) );
	}

	public static function organizer_permission( WP_REST_Request $request ) {
		$event_id = absint( $request['id'] ?? 0 );
		if ( $event_id && ! LNKup_Capabilities::organizer_owns_event( $event_id ) ) {
			return false;
		}
		return current_user_can( 'lnkup_review_applications' ) || LNKup_Capabilities::user_has_manage_all();
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

	private static function organizer_for_app( WP_REST_Request $request ) {
		$app_id = absint( $request['id'] ?? $request['app_id'] ?? 0 );
		$event_id = (int) get_post_meta( $app_id, '_lnkup_event_id', true );
		return LNKup_Capabilities::organizer_owns_event( $event_id );
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
		$body = $request->get_json_params() ?: $request->get_params();
		LNKup_Event_Operations::set_ops_status( absint( $request['app_id'] ), $body['status'] ?? '' );
		return rest_ensure_response( array( 'success' => true ) );
	}

	public static function event_feed( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'items' => LNKup_Operations_Log::get_for_event( absint( $request['id'] ), 50 ) ) );
	}

	public static function event_compliance( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'alerts' => LNKup_Event_Operations::compliance_alerts( absint( $request['id'] ) ) ) );
	}

	public static function get_booths( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'items' => LNKup_Booths::get_for_event( absint( $request['id'] ) ) ) );
	}

	public static function save_booth( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: $request->get_params();
		$id = LNKup_Booths::save( absint( $request['id'] ), $body );
		return rest_ensure_response( array( 'id' => $id ) );
	}

	public static function assign_booth( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: $request->get_params();
		$result = LNKup_Booths::assign( absint( $request['booth_id'] ), absint( $body['application_id'] ?? 0 ) );
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
		return rest_ensure_response( array( 'id' => $id ) );
	}

	public static function get_loadin( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'windows' => LNKup_Loadin_Schedule::get_windows( absint( $request['id'] ) ) ) );
	}

	public static function save_loadin( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: $request->get_params();
		if ( ! empty( $body['assign'] ) ) {
			$result = LNKup_Loadin_Schedule::assign( absint( $body['window_id'] ), absint( $body['application_id'] ) );
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
		$body = $request->get_json_params() ?: $request->get_params();
		$result = LNKup_Checkins::record( absint( $request['id'] ), $body['action'] ?? 'checked_in', 'manual' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'success' => true ) );
	}

	public static function qr_scan( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: $request->get_params();
		$result = LNKup_Checkins::scan_qr( $body['token'] ?? '', absint( $body['event_id'] ?? 0 ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
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
		$body = $request->get_json_params() ?: $request->get_params();
		LNKup_Support_Requests::update( absint( $request['id'] ), $body );
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

<?php
/**
 * Map system REST API.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Map_REST {

	const NS = 'lnkup/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route( self::NS, '/maps/discovery/pins', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'discovery_pins' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( self::NS, '/maps/events/(?P<id>\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_event_map' ),
				'permission_callback' => array( __CLASS__, 'view_map_permission' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'save_event_map' ),
				'permission_callback' => array( __CLASS__, 'edit_map_permission' ),
			),
		) );

		register_rest_route( self::NS, '/maps/events/(?P<id>\d+)/publish', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'publish_map' ),
			'permission_callback' => array( __CLASS__, 'edit_map_permission' ),
		) );

		register_rest_route( self::NS, '/maps/events/(?P<id>\d+)/elements', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_elements' ),
				'permission_callback' => array( __CLASS__, 'view_map_permission' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'save_elements' ),
				'permission_callback' => array( __CLASS__, 'edit_map_permission' ),
			),
		) );

		register_rest_route( self::NS, '/maps/events/(?P<id>\d+)/elements/(?P<element_id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( __CLASS__, 'delete_element' ),
			'permission_callback' => array( __CLASS__, 'edit_map_permission' ),
		) );

		register_rest_route( self::NS, '/maps/events/(?P<id>\d+)/layers', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_layers' ),
			'permission_callback' => array( __CLASS__, 'view_map_permission' ),
		) );

		register_rest_route( self::NS, '/maps/events/(?P<id>\d+)/live', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'live_map' ),
			'permission_callback' => array( __CLASS__, 'staff_map_permission' ),
		) );

		register_rest_route( self::NS, '/maps/events/(?P<id>\d+)/search', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'search_map' ),
			'permission_callback' => array( __CLASS__, 'view_map_permission' ),
		) );

		register_rest_route( self::NS, '/maps/events/(?P<id>\d+)/routes', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_routes' ),
				'permission_callback' => array( __CLASS__, 'view_map_permission' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'save_route' ),
				'permission_callback' => array( __CLASS__, 'edit_map_permission' ),
			),
		) );

		register_rest_route( self::NS, '/maps/events/(?P<id>\d+)/parking-zones', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_parking_zones' ),
				'permission_callback' => array( __CLASS__, 'view_map_permission' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'save_parking_zone' ),
				'permission_callback' => array( __CLASS__, 'edit_map_permission' ),
			),
		) );

		register_rest_route( self::NS, '/maps/events/(?P<id>\d+)/duplicate', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'duplicate_map' ),
			'permission_callback' => array( __CLASS__, 'edit_map_permission' ),
		) );

		register_rest_route( self::NS, '/maps/events/(?P<id>\d+)/insights', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'event_insights' ),
			'permission_callback' => array( __CLASS__, 'view_map_permission' ),
		) );

		register_rest_route( self::NS, '/maps/events/(?P<id>\d+)/share', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'create_share' ),
			'permission_callback' => array( __CLASS__, 'edit_map_permission' ),
		) );

		register_rest_route( self::NS, '/maps/templates', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_templates' ),
				'permission_callback' => array( __CLASS__, 'organizer_permission' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'save_template' ),
				'permission_callback' => array( __CLASS__, 'organizer_permission' ),
			),
		) );

		register_rest_route( self::NS, '/maps/templates/(?P<template_id>\d+)/apply', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'apply_template' ),
			'permission_callback' => array( __CLASS__, 'apply_template_permission' ),
		) );

		register_rest_route( self::NS, '/maps/packet/(?P<application_id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'packet_map' ),
			'permission_callback' => array( __CLASS__, 'packet_permission' ),
		) );

		register_rest_route( self::NS, '/maps/share/(?P<token>[a-zA-Z0-9]+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'shared_map' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( self::NS, '/maps/marketplace/cities', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'marketplace_cities' ),
			'permission_callback' => array( __CLASS__, 'admin_permission' ),
		) );

		register_rest_route( self::NS, '/maps/geocode', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'geocode_address' ),
			'permission_callback' => array( __CLASS__, 'organizer_permission' ),
		) );

		register_rest_route( self::NS, '/maps/geocode/backfill', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'geocode_backfill' ),
			'permission_callback' => array( __CLASS__, 'admin_permission' ),
		) );
	}

	public static function organizer_permission() {
		return LNKup_Roles::is_organizer() || LNKup_Capabilities::user_has_manage_all();
	}

	public static function admin_permission() {
		return LNKup_Capabilities::can_view_market_map();
	}

	public static function view_map_permission( WP_REST_Request $request ) {
		return LNKup_Capabilities::can_view_event_map( absint( $request['id'] ) );
	}

	public static function edit_map_permission( WP_REST_Request $request ) {
		return LNKup_Capabilities::can_edit_event_map( absint( $request['id'] ) );
	}

	/**
	 * Template apply targets the event in the body (the route has no event in its path).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function apply_template_permission( WP_REST_Request $request ) {
		$body     = $request->get_json_params() ?: $request->get_params();
		$event_id = absint( $body['event_id'] ?? 0 );
		return $event_id && LNKup_Capabilities::can_edit_event_map( $event_id );
	}

	public static function staff_map_permission( WP_REST_Request $request ) {
		$event_id = absint( $request['id'] );
		if ( LNKup_Capabilities::can_edit_event_map( $event_id ) ) {
			return true;
		}
		return LNKup_Capabilities::staff_can_access_event( $event_id );
	}

	public static function packet_permission( WP_REST_Request $request ) {
		return LNKup_Capabilities::can_view_packet_map( absint( $request['application_id'] ) );
	}

	public static function visibility_scope() {
		if ( LNKup_Capabilities::user_has_manage_all() || LNKup_Roles::is_organizer() ) {
			return 'organizer';
		}
		if ( LNKup_Roles::is_vendor() ) {
			return 'vendor';
		}
		return 'public';
	}

	public static function discovery_pins( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'pins' => self::build_discovery_pins( $request ) ) );
	}

	public static function build_discovery_pins( WP_REST_Request $request ) {
		global $wpdb;
		if ( function_exists( 'lnkup_can_browse_marketplace' ) && ! lnkup_can_browse_marketplace() ) {
			return array();
		}
		$table  = LNKup_Marketplace_Index::table_name();
		$where  = array( 'lat IS NOT NULL', 'lng IS NOT NULL', 'lat != 0' );
		$params = array();
		if ( LNKup_Access_Policy::exclude_invite_only() ) {
			$where[] = LNKup_Access_Policy::invite_only_exclusion_sql( $table );
		}

		if ( $request->get_param( 'city' ) ) {
			$where[]  = 'city = %s';
			$params[] = sanitize_text_field( $request->get_param( 'city' ) );
		}
		if ( $request->get_param( 'state' ) ) {
			$where[]  = 'state = %s';
			$params[] = sanitize_text_field( $request->get_param( 'state' ) );
		}
		if ( $request->get_param( 'bbox' ) ) {
			$parts = array_map( 'floatval', explode( ',', $request->get_param( 'bbox' ) ) );
			if ( 4 === count( $parts ) ) {
				$where[] = 'lat BETWEEN %f AND %f AND lng BETWEEN %f AND %f';
				$params  = array_merge( $params, array( min( $parts[0], $parts[2] ), max( $parts[0], $parts[2] ), min( $parts[1], $parts[3] ), max( $parts[1], $parts[3] ) ) );
			}
		}

		$sql = "SELECT opportunity_id, event_id, title, city, state, lat, lng, vendor_fee, attendance, spots_remaining, spots_total, deadline, categories, organizer_verified FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY trending_score DESC LIMIT 200';
		if ( $params ) {
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} else {
			$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$pins = array();
		foreach ( (array) $rows as $row ) {
			$cats = json_decode( $row['categories'] ?? '[]', true ) ?: array();
			$pin  = array(
				'opportunity_id'      => (int) $row['opportunity_id'],
				'event_id'            => (int) $row['event_id'],
				'title'               => $row['title'],
				'city'                => $row['city'],
				'state'               => $row['state'],
				'lat'                 => (float) $row['lat'],
				'lng'                 => (float) $row['lng'],
				'vendor_fee'          => (float) $row['vendor_fee'],
				'attendance'          => (int) $row['attendance'],
				'spots_remaining'     => (int) $row['spots_remaining'],
				'total_spots'         => (int) $row['spots_total'],
				'spots_total'         => (int) $row['spots_total'],
				'deadline'            => $row['deadline'],
				'categories'          => $cats,
				'organizer_verified'  => (bool) $row['organizer_verified'],
				'apply_url'           => add_query_arg( 'apply', (int) $row['opportunity_id'], lnkup_page_url_helper( 'opportunities' ) ),
			);
			$pin = LNKup_Access_Policy::scrub_opportunity_item( $pin );
			if ( ! empty( $pin['access']['gated'] ) ) {
				$pin['spots_total'] = null;
			}
			unset( $pin['total_spots'] );
			$pins[] = $pin;
		}
		return $pins;
	}

	public static function get_event_map( WP_REST_Request $request ) {
		$event_id = absint( $request['id'] );
		$map      = LNKup_Maps::ensure( $event_id );
		return rest_ensure_response(
			array(
				'map'      => $map,
				'elements' => LNKup_Map_Elements::get_for_map( $map['id'], self::visibility_scope() ),
				'routes'   => LNKup_Map_Routes::get_for_event( $event_id ),
				'parking'  => LNKup_Parking_Zones::get_for_event( $event_id ),
				'insights' => LNKup_Map_Insights::for_event( $event_id ),
				'provider' => LNKup_Map_Provider::frontend_config(),
			)
		);
	}

	public static function save_event_map( WP_REST_Request $request ) {
		$event_id = absint( $request['id'] );
		$body     = $request->get_json_params() ?: $request->get_params();
		$map      = LNKup_Maps::ensure( $event_id );
		$updated  = LNKup_Maps::update( $map['id'], $body );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
		return rest_ensure_response( $updated );
	}

	public static function publish_map( WP_REST_Request $request ) {
		$map = LNKup_Maps::get_for_event( absint( $request['id'] ) );
		if ( ! $map ) {
			return new WP_Error( 'no_map', __( 'No map to publish.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( LNKup_Maps::publish( $map['id'] ) );
	}

	public static function get_elements( WP_REST_Request $request ) {
		$map = LNKup_Maps::ensure( absint( $request['id'] ) );
		return rest_ensure_response( array( 'items' => LNKup_Map_Elements::get_for_map( $map['id'], self::visibility_scope() ) ) );
	}

	public static function save_elements( WP_REST_Request $request ) {
		$event_id = absint( $request['id'] );
		$map      = LNKup_Maps::ensure( $event_id );
		$body     = $request->get_json_params() ?: $request->get_params();
		$items    = $body['elements'] ?? ( isset( $body['element_type'] ) ? array( $body ) : array() );
		return rest_ensure_response( array( 'items' => LNKup_Map_Elements::save_batch( $map['id'], $event_id, $items ) ) );
	}

	public static function delete_element( WP_REST_Request $request ) {
		$event_id = absint( $request['id'] );
		if ( ! LNKup_Map_Elements::delete( absint( $request['element_id'] ), $event_id ) ) {
			return new WP_Error( 'not_found', __( 'Map item not found for this event.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		delete_transient( 'lnkup_map_live_' . $event_id );
		return rest_ensure_response( array( 'success' => true ) );
	}

	public static function get_layers( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'layers' => LNKup_Map_Elements::layers() ) );
	}

	public static function live_map( WP_REST_Request $request ) {
		$event_id = absint( $request['id'] );
		$cached   = get_transient( 'lnkup_map_live_' . $event_id );
		if ( false !== $cached ) {
			return rest_ensure_response( $cached );
		}
		$map = LNKup_Maps::get_for_event( $event_id );
		if ( ! $map ) {
			return rest_ensure_response( array( 'elements' => array(), 'support' => array(), 'incidents' => array() ) );
		}
		$elements = LNKup_Map_Elements::get_for_map( $map['id'], 'staff' );
		$support  = class_exists( 'LNKup_Support_Requests' ) ? LNKup_Support_Requests::get_for_event( $event_id, 'open' ) : array();
		$incidents = class_exists( 'LNKup_Incidents' ) ? LNKup_Incidents::get_for_event( $event_id ) : array();
		$payload  = array(
			'elements'  => $elements,
			'support'   => $support,
			'incidents' => $incidents,
			'statuses'  => class_exists( 'LNKup_Event_Operations' ) ? LNKup_Event_Operations::ops_statuses() : array(),
		);
		set_transient( 'lnkup_map_live_' . $event_id, $payload, 30 );
		return rest_ensure_response( $payload );
	}

	public static function search_map( WP_REST_Request $request ) {
		$map = LNKup_Maps::get_for_event( absint( $request['id'] ) );
		if ( ! $map ) {
			return rest_ensure_response( array( 'items' => array() ) );
		}
		$q = sanitize_text_field( $request->get_param( 'q' ) ?: '' );
		return rest_ensure_response( array( 'items' => LNKup_Map_Elements::search( $map['id'], $q, self::visibility_scope() ) ) );
	}

	public static function get_routes( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'items' => LNKup_Map_Routes::get_for_event( absint( $request['id'] ) ) ) );
	}

	public static function save_route( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: $request->get_params();
		return rest_ensure_response( LNKup_Map_Routes::save( absint( $request['id'] ), $body ) );
	}

	public static function get_parking_zones( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'items' => LNKup_Parking_Zones::get_for_event( absint( $request['id'] ) ) ) );
	}

	public static function save_parking_zone( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: $request->get_params();
		return rest_ensure_response( LNKup_Parking_Zones::save( absint( $request['id'] ), $body ) );
	}

	public static function duplicate_map( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: $request->get_params();
		$source = absint( $body['source_event_id'] ?? 0 );
		if ( ! $source ) {
			return new WP_Error( 'missing_source', __( 'Source event required.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( ! LNKup_Capabilities::can_edit_event_map( $source ) ) {
			return new WP_Error( 'forbidden', __( 'You can only copy maps from your own events.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$result = LNKup_Maps::duplicate_from_event( $source, absint( $request['id'] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	public static function event_insights( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'items' => LNKup_Map_Insights::for_event( absint( $request['id'] ) ) ) );
	}

	public static function create_share( WP_REST_Request $request ) {
		$event_id = absint( $request['id'] );
		$map      = LNKup_Maps::ensure( $event_id );
		$body     = $request->get_json_params() ?: $request->get_params();
		$scope    = sanitize_key( $body['scope'] ?? 'public' );
		return rest_ensure_response( LNKup_Map_Sharing::create_token( $event_id, $map['id'], $scope ) );
	}

	public static function list_templates( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'items' => LNKup_Map_Templates::get_for_organizer( get_current_user_id() ) ) );
	}

	public static function save_template( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: $request->get_params();
		if ( ! empty( $body['from_event_id'] ) ) {
			if ( ! LNKup_Capabilities::organizer_owns_event( absint( $body['from_event_id'] ) ) ) {
				return new WP_Error( 'forbidden', __( 'You can only save templates from your own events.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
			}
			return rest_ensure_response(
				LNKup_Map_Templates::save_from_event(
					absint( $body['from_event_id'] ),
					get_current_user_id(),
					sanitize_text_field( $body['name'] ?? '' ),
					sanitize_key( $body['category'] ?? 'custom' )
				)
			);
		}
		return rest_ensure_response( LNKup_Map_Templates::save( get_current_user_id(), $body ) );
	}

	public static function apply_template( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: $request->get_params();
		$event_id = absint( $body['event_id'] ?? $request->get_param( 'event_id' ) ?? 0 );
		if ( ! $event_id ) {
			return new WP_Error( 'missing_event', __( 'Event ID required.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$result = LNKup_Map_Templates::apply_to_event( absint( $request['template_id'] ), $event_id, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	public static function packet_map( WP_REST_Request $request ) {
		$app_id   = absint( $request['application_id'] );
		$event_id = (int) get_post_meta( $app_id, '_lnkup_event_id', true );
		$map      = LNKup_Maps::get_for_event( $event_id );
		$booth    = class_exists( 'LNKup_Booths' ) ? LNKup_Booths::get_for_application( $app_id ) : null;
		$parking  = class_exists( 'LNKup_Parking' ) ? LNKup_Parking::get_for_application( $app_id ) : null;
		$elements = $map ? LNKup_Map_Elements::get_for_map( $map['id'], 'vendor' ) : array();
		$routes   = LNKup_Map_Routes::get_for_event( $event_id );
		$zones    = LNKup_Parking_Zones::get_for_event( $event_id );
		$event    = get_post( $event_id );
		return rest_ensure_response(
			array(
				'map'       => $map,
				'elements'  => $elements,
				'routes'    => $routes,
				'parking_zones' => $zones,
				'booth'     => $booth,
				'parking'   => $parking,
				'venue'     => array(
					'address' => LNKup_Geocoder::event_address_string( $event_id ),
					'lat'     => get_post_meta( $event_id, '_lnkup_lat', true ),
					'lng'     => get_post_meta( $event_id, '_lnkup_lng', true ),
					'loadin'  => get_post_meta( $event_id, '_lnkup_loadin_entrance', true ),
					'gate'    => get_post_meta( $event_id, '_lnkup_vendor_gate', true ),
				),
				'organizer_contact' => $event ? get_userdata( (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true ) ) : null,
			)
		);
	}

	public static function shared_map( WP_REST_Request $request ) {
		$share = LNKup_Map_Sharing::get_by_token( $request['token'] );
		if ( ! $share ) {
			return new WP_Error( 'invalid_token', __( 'Invalid or expired map link.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$scope = LNKup_Map_Sharing::visibility_for_scope( $share['scope'] );
		$map   = LNKup_Maps::get_for_event( (int) $share['event_id'] );
		if ( ! $map || 'published' !== $map['status'] ) {
			return new WP_Error( 'unavailable', __( 'Map not available.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response(
			array(
				'map'      => $map,
				'elements' => LNKup_Map_Elements::get_for_map( $map['id'], $scope ),
				'routes'   => LNKup_Map_Routes::get_for_event( (int) $share['event_id'] ),
				'scope'    => $share['scope'],
			)
		);
	}

	public static function marketplace_cities( WP_REST_Request $request ) {
		return rest_ensure_response(
			array(
				'cities'   => LNKup_Map_Insights::city_aggregates(),
				'insights' => LNKup_Map_Insights::marketplace_cities(),
			)
		);
	}

	public static function geocode_address( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: $request->get_params();
		if ( ! empty( $body['event_id'] ) ) {
			if ( ! LNKup_Capabilities::user_can_act_on_event( get_current_user_id(), absint( $body['event_id'] ), 'edit_ops' ) ) {
				return new WP_Error( 'lnkup_forbidden', __( 'You can only update locations for events you manage.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
			}
			if ( ! empty( $body['lat'] ) && ! empty( $body['lng'] ) ) {
				return rest_ensure_response( LNKup_Geocoder::set_manual_coords( absint( $body['event_id'] ), $body['lat'], $body['lng'] ) );
			}
			return rest_ensure_response( LNKup_Geocoder::geocode_event( absint( $body['event_id'] ) ) ?: array() );
		}
		$address = sanitize_text_field( $body['address'] ?? '' );
		return rest_ensure_response( LNKup_Geocoder::geocode( $address ) ?: array() );
	}

	public static function geocode_backfill( WP_REST_Request $request ) {
		$limit = min( 50, absint( $request->get_param( 'limit' ) ?: 25 ) );
		return rest_ensure_response( array( 'processed' => LNKup_Geocoder::backfill_events( $limit ) ) );
	}
}

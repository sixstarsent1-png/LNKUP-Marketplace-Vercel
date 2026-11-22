<?php
/**
 * Tours & series REST routes (lnkup/v1/tours*).
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Tours_REST {

	const NS = 'lnkup/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		$write_args = array(
			'title'       => array( 'type' => 'string', 'maxLength' => 200 ),
			'description' => array( 'type' => 'string', 'maxLength' => 5000 ),
			'type'        => array( 'type' => 'string', 'enum' => LNKup_Tours::TYPES ),
			'status'      => array( 'type' => 'string', 'enum' => array( 'publish', 'draft' ) ),
			'event_ids'   => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
		);

		register_rest_route(
			self::NS,
			'/tours',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'list_mine' ),
					'permission_callback' => array( 'LNKup_Recruitment_REST', 'organizer_permission' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'create' ),
					'permission_callback' => array( 'LNKup_Recruitment_REST', 'organizer_permission' ),
					'args'                => $write_args,
				),
			)
		);

		register_rest_route(
			self::NS,
			'/tours/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'detail' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( __CLASS__, 'update' ),
					'permission_callback' => array( __CLASS__, 'owner_permission' ),
					'args'                => $write_args,
				),
			)
		);
	}

	public static function owner_permission( WP_REST_Request $request ) {
		return LNKup_Recruitment_REST::organizer_permission() && LNKup_Tours::owns( absint( $request['id'] ), get_current_user_id() );
	}

	public static function list_mine() {
		return rest_ensure_response(
			array(
				'enabled' => LNKup_Match_Settings::enabled( 'matching_tours_enabled' ),
				'items'   => LNKup_Tours::list_for_organizer( get_current_user_id() ),
			)
		);
	}

	public static function create( WP_REST_Request $request ) {
		if ( ! LNKup_Match_Settings::enabled( 'matching_tours_enabled' ) ) {
			return new WP_Error( 'lnkup_feature_disabled', __( 'Tours are not available yet.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$result = LNKup_Tours::save( get_current_user_id(), self::body( $request ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function update( WP_REST_Request $request ) {
		if ( ! LNKup_Match_Settings::enabled( 'matching_tours_enabled' ) ) {
			return new WP_Error( 'lnkup_feature_disabled', __( 'Tours are not available yet.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$result = LNKup_Tours::save( get_current_user_id(), self::body( $request ), absint( $request['id'] ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function detail( WP_REST_Request $request ) {
		$tour_id = absint( $request['id'] );
		$user_id = get_current_user_id();
		if ( ! LNKup_Match_Settings::enabled( 'matching_tours_enabled' ) && ! LNKup_Tours::owns( $tour_id, $user_id ) ) {
			return new WP_Error( 'lnkup_not_found', __( 'Tour not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$result = LNKup_Tours::detail( $tour_id, $user_id );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * Only keys present in the request are applied (PATCH semantics).
	 */
	private static function body( WP_REST_Request $request ) {
		$data = array();
		foreach ( array( 'title', 'description', 'type', 'status', 'event_ids' ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$data[ $key ] = $request->get_param( $key );
			}
		}
		return $data;
	}
}

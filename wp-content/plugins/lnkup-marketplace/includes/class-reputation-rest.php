<?php
/**
 * Reputation REST API.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Reputation_REST {

	const NS = 'lnkup/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			self::NS,
			'/reputation/vendors/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'vendor_reputation' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NS,
			'/reputation/vendors/(?P<id>\d+)/reviews',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'vendor_reviews' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NS,
			'/reputation/vendors/(?P<id>\d+)/reviews/summary',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'review_summary' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NS,
			'/reputation/vendors/(?P<id>\d+)/events',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'completed_events' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NS,
			'/reputation/vendors/(?P<slug>[^/]+)/public',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'public_profile' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NS,
			'/reputation/reviews',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create_review' ),
				'permission_callback' => array( __CLASS__, 'organizer_permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/reputation/reviews/(?P<id>\d+)',
			array(
				'methods'             => 'PUT',
				'callback'            => array( __CLASS__, 'update_review' ),
				'permission_callback' => array( __CLASS__, 'organizer_permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/reputation/reviews/(?P<id>\d+)/report',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'report_review' ),
				'permission_callback' => array( __CLASS__, 'vendor_permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/reputation/applications/(?P<id>\d+)/review-eligibility',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'review_eligibility' ),
				'permission_callback' => array( __CLASS__, 'organizer_permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/reputation/vendor-dashboard',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'vendor_dashboard' ),
				'permission_callback' => array( __CLASS__, 'vendor_permission' ),
			)
		);
	}

	public static function organizer_permission() {
		return current_user_can( 'lnkup_review_applications' ) || LNKup_Capabilities::user_has_manage_all();
	}

	public static function vendor_permission() {
		return current_user_can( 'lnkup_manage_own_profile' ) || LNKup_Capabilities::user_has_manage_all();
	}

	public static function vendor_reputation( WP_REST_Request $request ) {
		$id = absint( $request['id'] );
		$rep = LNKup_Vendor_Reputation::get( $id );
		if ( ! $rep ) {
			return new WP_Error( 'not_found', __( 'Vendor not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( $rep );
	}

	public static function vendor_reviews( WP_REST_Request $request ) {
		$id = absint( $request['id'] );
		$include_private = self::organizer_permission() && LNKup_Capabilities::organizer_can_access_vendor( get_current_user_id(), (int) get_post_meta( $id, '_lnkup_owner_user_id', true ) );
		return rest_ensure_response( array( 'items' => LNKup_Vendor_Reviews::get_for_vendor( $id, 20, $include_private ) ) );
	}

	public static function review_summary( WP_REST_Request $request ) {
		return rest_ensure_response( LNKup_Vendor_Reviews::summary_for_vendor( absint( $request['id'] ) ) );
	}

	public static function completed_events( WP_REST_Request $request ) {
		$context = self::organizer_permission() ? 'crm' : 'public';
		return rest_ensure_response( array( 'items' => LNKup_Completed_Events::get_for_vendor( absint( $request['id'] ), $context ) ) );
	}

	public static function public_profile( WP_REST_Request $request ) {
		$profile_id = LNKup_Vendor_Reputation::resolve_profile( $request['slug'] );
		if ( ! $profile_id ) {
			return new WP_Error( 'not_found', __( 'Vendor not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$data = LNKup_Vendor_CRM_Profile::get_public( $profile_id );
		if ( ! $data ) {
			return new WP_Error( 'not_found', __( 'Vendor not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$data['html'] = LNKup_Reputation_Render::summary_card( $data['reputation'] );
		return rest_ensure_response( $data );
	}

	public static function create_review( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: $request->get_params();
		$result = LNKup_Vendor_Reviews::create( get_current_user_id(), $body );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'id' => $result ) );
	}

	public static function update_review( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: $request->get_params();
		$result = LNKup_Vendor_Reviews::update( absint( $request['id'] ), get_current_user_id(), $body );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'success' => true ) );
	}

	public static function report_review( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: $request->get_params();
		$result = LNKup_Vendor_Reviews::report( absint( $request['id'] ), get_current_user_id(), $body['reason'] ?? '' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'dispute_id' => $result ) );
	}

	public static function review_eligibility( WP_REST_Request $request ) {
		return rest_ensure_response( LNKup_Vendor_Reviews::can_review( absint( $request['id'] ), get_current_user_id() ) );
	}

	public static function vendor_dashboard( WP_REST_Request $request ) {
		$profile_id = lnkup_get_vendor_profile_id( get_current_user_id() );
		if ( ! $profile_id ) {
			return new WP_Error( 'no_profile', __( 'Complete your vendor profile first.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$rep = LNKup_Vendor_Reputation::get( $profile_id );
		return rest_ensure_response(
			array(
				'reputation'  => $rep,
				'tips'        => LNKup_Vendor_Reputation::get_improvement_tips( $profile_id ),
				'reviews'     => LNKup_Vendor_Reviews::get_for_vendor( $profile_id, 10 ),
				'events'      => LNKup_Completed_Events::get_for_vendor( $profile_id, 'vendor' ),
				'available_badges' => LNKup_Vendor_Badges::get_available( $profile_id ),
			)
		);
	}
}

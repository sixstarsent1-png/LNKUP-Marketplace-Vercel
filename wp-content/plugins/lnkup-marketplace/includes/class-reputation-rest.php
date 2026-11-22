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
		// Numeric-ID routes are for signed-in owners, organizers and staff only (no anonymous enumeration).
		register_rest_route(
			self::NS,
			'/reputation/vendors/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'vendor_reputation' ),
				'permission_callback' => array( __CLASS__, 'profile_by_id_permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/reputation/vendors/(?P<id>\d+)/reviews',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'vendor_reviews' ),
				'permission_callback' => array( __CLASS__, 'profile_by_id_permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/reputation/vendors/(?P<id>\d+)/reviews/summary',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'review_summary' ),
				'permission_callback' => array( __CLASS__, 'profile_by_id_permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/reputation/vendors/(?P<id>\d+)/events',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'completed_events' ),
				'permission_callback' => array( __CLASS__, 'profile_by_id_permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/reputation/vendors/(?P<slug>[a-z0-9\-]+)/public',
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
			'/reputation/reviews/(?P<id>\d+)/response',
			array(
				'methods'             => 'POST,PUT',
				'callback'            => array( __CLASS__, 'respond_review' ),
				'permission_callback' => array( __CLASS__, 'vendor_permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/reputation/disputes/(?P<id>\d+)/appeal',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'appeal_dispute' ),
				'permission_callback' => 'is_user_logged_in',
			)
		);

		register_rest_route(
			self::NS,
			'/reputation/admin/disputes/(?P<id>\d+)/decision',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'decide_dispute' ),
				'permission_callback' => array( 'LNKup_Capabilities', 'admin_can_moderate_reputation' ),
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

	/**
	 * Signed-in owner, organizer or staff, and the ID must be a vendor profile.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function profile_by_id_permission( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', __( 'Sign in to view vendor reputation.', 'lnkup-marketplace' ), array( 'status' => 401 ) );
		}
		$profile_id = absint( $request['id'] );
		if ( ! LNKup_Vendor_Reputation::is_vendor_profile( $profile_id ) ) {
			return new WP_Error( 'not_found', __( 'Vendor not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$user_id = get_current_user_id();
		if ( LNKup_Capabilities::user_has_manage_all( $user_id ) || self::is_owner( $profile_id, $user_id ) ) {
			return true;
		}
		return self::organizer_permission();
	}

	private static function is_owner( int $profile_id, int $user_id ): bool {
		return $user_id > 0 && (int) get_post_meta( $profile_id, '_lnkup_owner_user_id', true ) === $user_id;
	}

	/**
	 * Remove internal-only score fields unless the viewer is staff or the owner.
	 *
	 * @param array $rep        Reputation block.
	 * @param int   $profile_id Profile ID.
	 */
	private static function redact_internal_score( $rep, int $profile_id ) {
		if ( ! is_array( $rep ) ) {
			return $rep;
		}
		$user_id = get_current_user_id();
		if ( LNKup_Capabilities::user_has_manage_all( $user_id ) || self::is_owner( $profile_id, $user_id ) ) {
			return $rep;
		}
		unset( $rep['score'], $rep['label'], $rep['breakdown'] );
		if ( isset( $rep['metrics'] ) && is_array( $rep['metrics'] ) ) {
			unset( $rep['metrics']['dispute_count'] );
		}
		return $rep;
	}

	public static function vendor_reputation( WP_REST_Request $request ) {
		$id  = absint( $request['id'] );
		$rep = LNKup_Vendor_Reputation::get( $id );
		if ( ! $rep ) {
			return new WP_Error( 'not_found', __( 'Vendor not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$rep = self::redact_internal_score( $rep, $id );
		if ( class_exists( 'LNKup_Reputation_Summary' ) ) {
			$rep['indicators'] = LNKup_Reputation_Summary::for_profile( $id, 'organizer' );
		}
		return rest_ensure_response( $rep );
	}

	public static function vendor_reviews( WP_REST_Request $request ) {
		$id = absint( $request['id'] );
		// Private/internal notes are only ever returned to the organizer who wrote them (or staff).
		return rest_ensure_response( array( 'items' => LNKup_Vendor_Reviews::get_for_vendor( $id, 20, false, get_current_user_id() ) ) );
	}

	public static function review_summary( WP_REST_Request $request ) {
		return rest_ensure_response( LNKup_Vendor_Reviews::summary_for_vendor( absint( $request['id'] ) ) );
	}

	public static function completed_events( WP_REST_Request $request ) {
		$id      = absint( $request['id'] );
		$user_id = get_current_user_id();
		$context = ( LNKup_Capabilities::user_has_manage_all( $user_id ) || self::is_owner( $id, $user_id ) ) ? 'crm' : 'public';
		return rest_ensure_response( array( 'items' => LNKup_Completed_Events::get_for_vendor( $id, $context ) ) );
	}

	public static function public_profile( WP_REST_Request $request ) {
		$bucket = 'passport_public_' . ( class_exists( 'LNKup_Trust' ) ? LNKup_Trust::ip_hash() : '' );
		if ( class_exists( 'LNKup_Trust' ) && ! LNKup_Trust::rate_limit( $bucket, LNKup_Passport_Settings::int( 'passport_public_rate_per_minute' ), MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'rate_limited', __( 'Too many requests. Try again in a minute.', 'lnkup-marketplace' ), array( 'status' => 429 ) );
		}
		$profile_id = LNKup_Vendor_Reputation::resolve_public_profile( (string) $request['slug'] );
		if ( ! $profile_id ) {
			return new WP_Error( 'not_found', __( 'Vendor not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$data = LNKup_Vendor_CRM_Profile::get_public( $profile_id );
		if ( ! $data ) {
			return new WP_Error( 'not_found', __( 'Vendor not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$data['html'] = LNKup_Reputation_Render::indicators_card( $data['indicators'] ?? array() );
		$response     = rest_ensure_response( $data );
		$response->header( 'Cache-Control', 'public, max-age=300' );
		return $response;
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
		$result = LNKup_Vendor_Reviews::report( absint( $request['id'] ), get_current_user_id(), (string) ( $body['reason'] ?? '' ), (string) ( $body['reason_code'] ?? 'other' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'dispute_id' => $result ) );
	}

	public static function respond_review( WP_REST_Request $request ) {
		if ( ! LNKup_Passport_Settings::enabled( 'review_responses' ) ) {
			return new WP_Error( 'feature_disabled', __( 'Review responses open soon.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$body   = $request->get_json_params() ?: $request->get_params();
		$result = LNKup_Vendor_Reviews::respond( absint( $request['id'] ), get_current_user_id(), (string) ( $body['text'] ?? $body['response'] ?? '' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'success' => true, 'review' => $result ) );
	}

	public static function appeal_dispute( WP_REST_Request $request ) {
		$body   = $request->get_json_params() ?: $request->get_params();
		$result = LNKup_Vendor_Reviews::appeal( absint( $request['id'] ), get_current_user_id(), (string) ( $body['reason'] ?? '' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	public static function decide_dispute( WP_REST_Request $request ) {
		$body   = $request->get_json_params() ?: $request->get_params();
		$result = LNKup_Vendor_Reviews::decide(
			absint( $request['id'] ),
			(string) ( $body['decision'] ?? '' ),
			(string) ( $body['reason_code'] ?? '' ),
			(string) ( $body['note'] ?? '' )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
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
		if ( ! empty( $rep['pending'] ) ) {
			$rep = LNKup_Vendor_Reputation::refresh( $profile_id ) ? LNKup_Vendor_Reputation::get( $profile_id ) : $rep;
		}
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

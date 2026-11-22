<?php
/**
 * Organizer recruitment REST routes (lnkup/v1/match/organizer/*).
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Recruitment_REST {

	const NS = 'lnkup/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		$event_args = array(
			'event_id' => array( 'type' => 'integer', 'required' => true, 'minimum' => 1 ),
		);

		register_rest_route(
			self::NS,
			'/match/organizer/events/(?P<event_id>\d+)/recruitment',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'recruitment' ),
				'permission_callback' => array( 'LNKup_Match_REST', 'organizer_event_permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/match/organizer/events/(?P<event_id>\d+)/recruitment/dismiss-prompt',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'dismiss_prompt' ),
				'permission_callback' => array( 'LNKup_Match_REST', 'organizer_event_permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/match/organizer/recruitment/shortlist',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'shortlist' ),
				'permission_callback' => array( __CLASS__, 'body_event_permission' ),
				'args'                => array_merge(
					$event_args,
					array(
						'profile_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'required' => true ),
						'action'      => array( 'type' => 'string', 'enum' => array( 'add', 'remove' ), 'default' => 'add' ),
						'list_id'     => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
					)
				),
			)
		);

		$invite_args = array_merge(
			$event_args,
			array(
				'opportunity_id' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
				'profile_ids'    => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'required' => true ),
				'message'        => array( 'type' => 'string', 'default' => '', 'maxLength' => 1000 ),
			)
		);

		register_rest_route(
			self::NS,
			'/match/organizer/invites/preview',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'preview' ),
				'permission_callback' => array( __CLASS__, 'body_event_permission' ),
				'args'                => $invite_args,
			)
		);

		register_rest_route(
			self::NS,
			'/match/organizer/invites/batch',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'batch' ),
				'permission_callback' => array( __CLASS__, 'body_event_permission' ),
				'args'                => $invite_args,
			)
		);

		register_rest_route(
			self::NS,
			'/match/organizer/invites/jobs/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'job' ),
				'permission_callback' => array( __CLASS__, 'organizer_permission' ),
			)
		);
	}

	public static function organizer_permission() {
		$user_id = get_current_user_id();
		return $user_id && ( LNKup_Roles::is_organizer( $user_id ) || current_user_can( 'lnkup_review_applications' ) || LNKup_Capabilities::user_has_manage_all( $user_id ) );
	}

	/**
	 * Organizer role + owns the event named in the request body.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function body_event_permission( WP_REST_Request $request ) {
		return self::organizer_permission() && LNKup_Capabilities::organizer_owns_event( absint( $request->get_param( 'event_id' ) ), get_current_user_id() );
	}

	private static function guard_enabled() {
		if ( ! LNKup_Match_Settings::enabled( 'matching_organizer_discovery_enabled' ) ) {
			return new WP_Error( 'lnkup_feature_disabled', __( 'Vendor recruitment is not available yet.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		return true;
	}

	public static function recruitment( WP_REST_Request $request ) {
		$event_id = absint( $request['event_id'] );
		if ( ! LNKup_Match_Settings::enabled( 'matching_organizer_discovery_enabled' ) ) {
			return rest_ensure_response(
				array(
					'enabled'     => false,
					'event_id'    => $event_id,
					'readiness'   => LNKup_Recruitment::readiness( $event_id ),
					'suggestions' => array(),
					'pipeline'    => array_fill_keys( LNKup_Recruitment::STAGES, 0 ),
				)
			);
		}
		try {
			$assistant = LNKup_Recruitment::assistant( get_current_user_id(), $event_id );
			$pipeline  = LNKup_Recruitment::pipeline_for_event( get_current_user_id(), $event_id );
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'LNKup recruitment failed: ' . $e->getMessage() );
			return new WP_Error( 'lnkup_recruitment_unavailable', __( 'Recruitment tools are temporarily unavailable.', 'lnkup-marketplace' ), array( 'status' => 503 ) );
		}
		return rest_ensure_response(
			array_merge(
				$assistant,
				array(
					'enabled'        => true,
					'pipeline_items' => $pipeline['items'],
					'apply_url'      => add_query_arg( 'event', $event_id, lnkup_page_url_helper( 'opportunities' ) ),
				)
			)
		);
	}

	public static function dismiss_prompt( WP_REST_Request $request ) {
		delete_post_meta( absint( $request['event_id'] ), '_lnkup_recruit_prompt' );
		return rest_ensure_response( array( 'success' => true ) );
	}

	public static function shortlist( WP_REST_Request $request ) {
		$enabled = self::guard_enabled();
		if ( is_wp_error( $enabled ) ) {
			return $enabled;
		}
		$list_id = absint( $request->get_param( 'list_id' ) );
		if ( $list_id && ! LNKup_Capabilities::organizer_owns_crm_object( $list_id, get_current_user_id() ) ) {
			return new WP_Error( 'lnkup_forbidden', __( 'You can only add vendors to your own lists.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$result = LNKup_Recruitment::shortlist(
			get_current_user_id(),
			absint( $request->get_param( 'event_id' ) ),
			(array) $request->get_param( 'profile_ids' ),
			sanitize_key( $request->get_param( 'action' ) ),
			$list_id
		);
		return is_wp_error( $result ) ? $result : rest_ensure_response( array_merge( array( 'success' => true ), $result ) );
	}

	public static function preview( WP_REST_Request $request ) {
		$enabled = self::guard_enabled();
		if ( is_wp_error( $enabled ) ) {
			return $enabled;
		}
		$result = LNKup_Recruitment::preview(
			get_current_user_id(),
			absint( $request->get_param( 'event_id' ) ),
			absint( $request->get_param( 'opportunity_id' ) ),
			(array) $request->get_param( 'profile_ids' ),
			sanitize_textarea_field( (string) $request->get_param( 'message' ) )
		);
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function batch( WP_REST_Request $request ) {
		$enabled = self::guard_enabled();
		if ( is_wp_error( $enabled ) ) {
			return $enabled;
		}
		$result = LNKup_Recruitment::create_job(
			get_current_user_id(),
			absint( $request->get_param( 'event_id' ) ),
			absint( $request->get_param( 'opportunity_id' ) ),
			(array) $request->get_param( 'profile_ids' ),
			sanitize_textarea_field( (string) $request->get_param( 'message' ) )
		);
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function job( WP_REST_Request $request ) {
		$result = LNKup_Recruitment::job( get_current_user_id(), absint( $request['id'] ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}
}

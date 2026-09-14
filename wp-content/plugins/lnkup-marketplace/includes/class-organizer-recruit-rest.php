<?php
/**
 * Organizer recruit referral REST.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * REST routes for organizer recruitment referrals.
 */
class LNKup_Organizer_Recruit_REST {

	const NS = 'lnkup/v1';

	/**
	 * Init.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register routes.
	 */
	public static function register_routes(): void {
		$auth = array( 'permission_callback' => array( __CLASS__, 'refer_permission' ) );

		register_rest_route(
			self::NS,
			'/referrals/organizer-recruit',
			array_merge(
				$auth,
				array(
					'methods'  => 'GET',
					'callback' => array( __CLASS__, 'get_dashboard' ),
				)
			)
		);

		register_rest_route(
			self::NS,
			'/referrals/organizer-recruit/code',
			array_merge(
				$auth,
				array(
					'methods'  => 'POST',
					'callback' => array( __CLASS__, 'ensure_code' ),
				)
			)
		);
	}

	/**
	 * Vendor or organizer (or admin).
	 *
	 * @return bool|WP_Error
	 */
	public static function refer_permission() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', __( 'Authentication required.', 'lnkup-marketplace' ), array( 'status' => 401 ) );
		}
		$user_id = get_current_user_id();
		if ( ! LNKup_Organizer_Recruit_Referrals::user_can_refer( $user_id ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Vendors and organizers can share referral invites.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * GET dashboard.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_dashboard() {
		return rest_ensure_response(
			LNKup_Organizer_Recruit_Referrals::dashboard_for_user( get_current_user_id() )
		);
	}

	/**
	 * POST ensure code.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function ensure_code() {
		$row = LNKup_Organizer_Recruit_Referrals::get_or_create_code( get_current_user_id() );
		if ( ! $row ) {
			return new WP_Error( 'code_failed', __( 'Could not create invite code.', 'lnkup-marketplace' ), array( 'status' => 500 ) );
		}
		$code = (string) ( $row['code'] ?? '' );
		return rest_ensure_response(
			array(
				'code'                => $code,
				'share_url'           => $code ? LNKup_Organizer_Recruit_Referrals::share_url( $code, 'organizer' ) : '',
				'share_url_organizer' => $code ? LNKup_Organizer_Recruit_Referrals::share_url( $code, 'organizer' ) : '',
				'share_url_vendor'    => $code ? LNKup_Organizer_Recruit_Referrals::share_url( $code, 'vendor' ) : '',
				'program'             => LNKup_Organizer_Recruit_Referrals::PROGRAM,
			)
		);
	}
}

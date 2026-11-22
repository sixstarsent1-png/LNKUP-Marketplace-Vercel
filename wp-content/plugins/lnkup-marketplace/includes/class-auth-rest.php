<?php
/**
 * REST login for native mobile (website password → Bearer token).
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Auth REST routes.
 */
class LNKup_Auth_REST {

	const NS = 'lnkup/v1';

	/**
	 * Init routes.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register REST routes.
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::NS,
			'/auth/login',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'login' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NS,
			'/auth/register',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'register' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NS,
			'/auth/logout',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'logout' ),
				'permission_callback' => array( __CLASS__, 'logged_in_permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/auth/me',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'me' ),
				'permission_callback' => array( __CLASS__, 'logged_in_permission' ),
			)
		);
	}

	/**
	 * @return bool
	 */
	public static function logged_in_permission(): bool {
		return is_user_logged_in();
	}

	/**
	 * POST /auth/login — username + website password.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function login( WP_REST_Request $request ) {
		if ( self::is_rate_limited() ) {
			return new WP_Error(
				'auth_rate_limited',
				__( 'Too many sign-in attempts. Try again in a few minutes.', 'lnkup-marketplace' ),
				array( 'status' => 429 )
			);
		}

		$username = sanitize_text_field( (string) $request->get_param( 'username' ) );
		$password = (string) $request->get_param( 'password' );
		if ( '' === $username ) {
			$username = sanitize_text_field( (string) $request->get_param( 'user_login' ) );
		}
		if ( '' === $password ) {
			$password = (string) $request->get_param( 'user_pass' );
		}

		$username = trim( $username );
		$password = trim( $password );

		if ( '' === $username || '' === $password ) {
			return new WP_Error(
				'validation_error',
				__( 'Enter your username and password.', 'lnkup-marketplace' ),
				array( 'status' => 400 )
			);
		}

		$user = wp_authenticate( $username, $password );
		if ( is_wp_error( $user ) ) {
			self::bump_rate_limit();
			return new WP_Error(
				'auth_required',
				__( 'Invalid username or password.', 'lnkup-marketplace' ),
				array( 'status' => 401 )
			);
		}

		if ( ! self::user_may_use_mobile( $user ) ) {
			return new WP_Error(
				'forbidden',
				__( 'This account cannot use the LNKup app.', 'lnkup-marketplace' ),
				array( 'status' => 403 )
			);
		}

		$issued = LNKup_Mobile_Auth::issue_token( (int) $user->ID );
		if ( is_wp_error( $issued ) ) {
			return $issued;
		}

		return rest_ensure_response(
			array(
				'token'      => $issued['token'],
				'expires_at' => $issued['expires_at'],
				'user'       => self::serialize_user( $user ),
			)
		);
	}

	/**
	 * POST /auth/register — create a vendor or organizer and return a Bearer token.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function register( WP_REST_Request $request ) {
		if ( self::is_rate_limited() ) {
			return new WP_Error(
				'auth_rate_limited',
				__( 'Too many sign-in attempts. Try again in a few minutes.', 'lnkup-marketplace' ),
				array( 'status' => 429 )
			);
		}

		if ( ! class_exists( 'LNKup_Registration' ) ) {
			return new WP_Error(
				'registration_unavailable',
				__( 'Account creation is not available right now.', 'lnkup-marketplace' ),
				array( 'status' => 503 )
			);
		}

		$role_param = sanitize_key( (string) $request->get_param( 'role' ) );
		$terms_raw  = $request->get_param( 'terms' );
		$terms      = true === $terms_raw || 1 === $terms_raw || '1' === (string) $terms_raw || 'true' === strtolower( (string) $terms_raw );

		$user_id = LNKup_Registration::register_user(
			array(
				'email'            => (string) $request->get_param( 'email' ),
				'password'         => (string) $request->get_param( 'password' ),
				'password_confirm' => (string) $request->get_param( 'password_confirm' ),
				'role'             => $role_param,
				'full_name'        => sanitize_text_field( (string) $request->get_param( 'full_name' ) ),
				'terms'            => $terms,
				// Organizer basics (optional here so older app builds keep working).
				'organization_name' => sanitize_text_field( (string) $request->get_param( 'organization_name' ) ),
				'city'             => sanitize_text_field( (string) $request->get_param( 'city' ) ),
				'state'            => sanitize_text_field( (string) $request->get_param( 'state' ) ),
				'category'         => sanitize_title( (string) $request->get_param( 'category' ) ),
			)
		);

		if ( is_wp_error( $user_id ) ) {
			self::bump_rate_limit();
			$data   = $user_id->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
			return new WP_Error( $user_id->get_error_code(), $user_id->get_error_message(), array( 'status' => $status ? $status : 400 ) );
		}

		$user = get_userdata( (int) $user_id );
		if ( ! $user || ! self::user_may_use_mobile( $user ) ) {
			return new WP_Error(
				'forbidden',
				__( 'This account cannot use the LNKup app.', 'lnkup-marketplace' ),
				array( 'status' => 403 )
			);
		}

		$issued = LNKup_Mobile_Auth::issue_token( (int) $user->ID );
		if ( is_wp_error( $issued ) ) {
			return $issued;
		}

		return rest_ensure_response(
			array(
				'token'      => $issued['token'],
				'expires_at' => $issued['expires_at'],
				'user'       => self::serialize_user( $user ),
			)
		);
	}

	/**
	 * POST /auth/logout — revoke current Bearer token.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function logout( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$token   = LNKup_Mobile_Auth::get_bearer_token_from_request();
		if ( $user_id && $token ) {
			LNKup_Mobile_Auth::revoke_token( $user_id, $token );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * GET /auth/me
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function me( WP_REST_Request $request ) {
		$user = wp_get_current_user();
		if ( ! $user || ! $user->ID ) {
			return new WP_Error(
				'auth_required',
				__( 'Sign in to continue.', 'lnkup-marketplace' ),
				array( 'status' => 401 )
			);
		}
		return rest_ensure_response( array( 'user' => self::serialize_user( $user ) ) );
	}

	/**
	 * @param WP_User $user User.
	 * @return array{id:int,name:string,slug:string,roles:string[]}
	 */
	private static function serialize_user( WP_User $user ): array {
		return array(
			'id'    => (int) $user->ID,
			'name'  => (string) $user->display_name,
			'slug'  => (string) $user->user_nicename,
			'roles' => array_values( array_map( 'strval', (array) $user->roles ) ),
		);
	}

	/**
	 * Vendors, organizers, and admins may use the app.
	 *
	 * @param WP_User $user User.
	 */
	private static function user_may_use_mobile( WP_User $user ): bool {
		$roles = (array) $user->roles;
		$ok    = array( 'lnkup_vendor', 'lnkup_organizer', 'lnkup_admin', 'administrator' );
		foreach ( $ok as $role ) {
			if ( in_array( $role, $roles, true ) ) {
				return true;
			}
		}
		return ! empty( $user->allcaps['lnkup_manage_all'] );
	}

	/**
	 * Simple IP rate limit for failed logins.
	 */
	private static function is_rate_limited(): bool {
		$key   = self::rate_limit_key();
		$count = (int) get_transient( $key );
		return $count >= 12;
	}

	/**
	 * Bump failed-login counter (15 minutes).
	 */
	private static function bump_rate_limit(): void {
		$key   = self::rate_limit_key();
		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, 15 * MINUTE_IN_SECONDS );
	}

	/**
	 * @return string
	 */
	private static function rate_limit_key(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : 'unknown';
		return 'lnkup_auth_fail_' . md5( $ip );
	}
}

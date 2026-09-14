<?php
/**
 * Branded registration shortcode and handler.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Registration {

	/**
	 * Allowed WP page slugs for post-signup redirects.
	 *
	 * @var array<int, string>
	 */
	private static $safe_page_slugs = array(
		'vendor-dashboard',
		'vendor-profile',
		'organizer-dashboard',
		'opportunities',
		'find-events',
		'sign-up',
	);

	/**
	 * Init hooks.
	 */
	public static function init(): void {
		add_shortcode( 'lnkup_register_form', array( __CLASS__, 'render_form' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle_submission' ), 5 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle_claim_submission' ), 6 );
		add_action( 'login_init', array( __CLASS__, 'redirect_core_register' ), 1 );
	}

	/**
	 * Send WP core /wp-login.php?action=register to branded signup.
	 */
	public static function redirect_core_register(): void {
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'register' !== $action ) {
			return;
		}
		$url = function_exists( 'lnkup_registration_url' ) ? lnkup_registration_url() : home_url( '/sign-up/' );
		$role = isset( $_GET['role'] ) ? sanitize_key( wp_unslash( (string) $_GET['role'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( in_array( $role, array( 'vendor', 'organizer' ), true ) ) {
			$url = add_query_arg( 'role', $role, $url );
		}
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Process registration POST on sign-up page.
	 */
	public static function maybe_handle_submission(): void {
		if ( is_user_logged_in() ) {
			return;
		}
		if ( empty( $_POST['lnkup_register_submit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		if ( ! isset( $_POST['lnkup_register_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lnkup_register_nonce'] ) ), 'lnkup_register' ) ) {
			return;
		}

		$errors = self::register_user_from_post();
		if ( is_wp_error( $errors ) ) {
			$GLOBALS['lnkup_register_errors'] = $errors;
			return;
		}

		$role = isset( $_POST['lnkup_role'] ) ? sanitize_text_field( wp_unslash( $_POST['lnkup_role'] ) ) : 'lnkup_vendor'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! in_array( $role, array( 'lnkup_vendor', 'lnkup_organizer' ), true ) ) {
			$role = 'lnkup_vendor';
		}
		$claim_token = isset( $_POST['lnkup_claim_external_event_token'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['lnkup_claim_external_event_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( 'lnkup_organizer' === $role && $claim_token ) {
			$redirect = add_query_arg( 'claim_external_event', $claim_token, lnkup_registration_url() );
		} else {
			$redirect = self::resolve_post_signup_redirect( $role );
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Complete external event claim for logged-in organizers.
	 */
	public static function maybe_handle_claim_submission(): void {
		if ( ! is_user_logged_in() || empty( $_POST['lnkup_claim_external_submit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		if ( ! isset( $_POST['lnkup_claim_external_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['lnkup_claim_external_nonce'] ) ), 'lnkup_claim_external' ) ) {
			return;
		}
		if ( ! LNKup_Roles::is_organizer() && ! LNKup_Capabilities::user_has_manage_all() ) {
			return;
		}
		$result = LNKup_External_Events::maybe_complete_pending_claim( get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			$GLOBALS['lnkup_claim_errors'] = $result;
			return;
		}
		if ( is_array( $result ) && ! empty( $result['dashboard_url'] ) ) {
			wp_safe_redirect( $result['dashboard_url'] );
			exit;
		}
	}

	/**
	 * Create user from POST data (web sign-up form).
	 *
	 * @return true|WP_Error
	 */
	public static function register_user_from_post() {
		$result = self::register_user(
			array(
				'email'            => isset( $_POST['user_email'] ) ? sanitize_email( wp_unslash( $_POST['user_email'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'password'         => isset( $_POST['user_pass'] ) ? (string) wp_unslash( $_POST['user_pass'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'password_confirm' => isset( $_POST['user_pass_confirm'] ) ? (string) wp_unslash( $_POST['user_pass_confirm'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'role'             => isset( $_POST['lnkup_role'] ) ? sanitize_text_field( wp_unslash( $_POST['lnkup_role'] ) ) : 'lnkup_vendor', // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'terms'            => ! empty( $_POST['lnkup_terms'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'full_name'        => isset( $_POST['lnkup_full_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['lnkup_full_name'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'outreach_token'   => isset( $_POST['lnkup_outreach_token'] ) ? sanitize_text_field( wp_unslash( $_POST['lnkup_outreach_token'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'claim_token'      => isset( $_POST['lnkup_claim_external_event_token'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['lnkup_claim_external_event_token'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		wp_set_current_user( $result );
		wp_set_auth_cookie( $result, true );

		return true;
	}

	/**
	 * Create a vendor or organizer account from sanitized input.
	 *
	 * @param array<string, mixed> $input Registration fields.
	 * @return int|WP_Error User ID on success.
	 */
	public static function register_user( array $input ) {
		if ( ! get_option( 'users_can_register' ) ) {
			return new WP_Error( 'registration_disabled', __( 'Registration is currently disabled.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}

		$email    = isset( $input['email'] ) ? sanitize_email( (string) $input['email'] ) : '';
		$password = isset( $input['password'] ) ? (string) $input['password'] : '';
		$confirm  = isset( $input['password_confirm'] ) ? (string) $input['password_confirm'] : '';
		$role     = isset( $input['role'] ) ? sanitize_text_field( (string) $input['role'] ) : 'lnkup_vendor';
		$terms    = ! empty( $input['terms'] );
		$full_name = isset( $input['full_name'] ) ? sanitize_text_field( (string) $input['full_name'] ) : '';

		if ( in_array( $role, array( 'vendor', 'lnkup_vendor' ), true ) ) {
			$role = 'lnkup_vendor';
		} elseif ( in_array( $role, array( 'organizer', 'lnkup_organizer' ), true ) ) {
			$role = 'lnkup_organizer';
		} else {
			$role = 'lnkup_vendor';
		}

		if ( ! $email || ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', __( 'Please enter a valid email address.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( email_exists( $email ) ) {
			return new WP_Error( 'email_exists', __( 'An account with this email already exists.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( strlen( $password ) < 8 ) {
			return new WP_Error( 'weak_password', __( 'Password must be at least 8 characters.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( $password !== $confirm ) {
			return new WP_Error( 'password_mismatch', __( 'Passwords do not match.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( ! $terms ) {
			return new WP_Error( 'terms_required', __( 'Please accept the terms to continue.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}

		$outreach_token = isset( $input['outreach_token'] ) ? sanitize_text_field( (string) $input['outreach_token'] ) : '';
		$claim_token    = isset( $input['claim_token'] ) ? sanitize_text_field( (string) $input['claim_token'] ) : '';
		if ( $outreach_token && class_exists( 'LNKup_Outreach' ) ) {
			$outreach_ctx = LNKup_Outreach::get_signup_context( $outreach_token );
			if ( ! $outreach_ctx ) {
				return new WP_Error( 'invalid_outreach', __( 'This invitation link is invalid or has expired.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
			}
			if ( strtolower( $email ) !== strtolower( $outreach_ctx['email'] ) ) {
				return new WP_Error( 'email_mismatch', __( 'Please sign up with the email address that received the invitation.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
			}
			if ( ! empty( $outreach_ctx['target_role'] ) ) {
				$role = $outreach_ctx['target_role'];
			}
		}

		if ( $claim_token && class_exists( 'LNKup_External_Events' ) ) {
			$claim_ctx = LNKup_External_Events::get_claim_signup_context( $claim_token );
			if ( ! $claim_ctx ) {
				return new WP_Error( 'invalid_claim', __( 'This event claim link is invalid or has expired.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
			}
			if ( strtolower( $email ) !== strtolower( (string) $claim_ctx['email'] ) ) {
				return new WP_Error( 'email_mismatch', __( 'Please sign up with the email address that received the vendor interest notification.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
			}
			$role = 'lnkup_organizer';
		}

		$username = self::generate_username_from_email( $email );
		$user_id  = wp_create_user( $username, $password, $email );
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$user = new WP_User( $user_id );
		$user->set_role( $role );

		if ( class_exists( 'LNKup_Organizer_Recruit_Referrals' ) ) {
			$granted = 'lnkup_organizer' === $role ? 'organizer' : ( 'lnkup_vendor' === $role ? 'vendor' : '' );
			LNKup_Organizer_Recruit_Referrals::try_attribute_from_pending( (int) $user_id, $granted );
		}

		if ( $full_name ) {
			wp_update_user(
				array(
					'ID'           => $user_id,
					'display_name' => $full_name,
					'first_name'   => $full_name,
				)
			);
		}

		self::store_market_signup_meta( (int) $user_id, $input );

		if ( 'lnkup_vendor' === $role ) {
			self::ensure_vendor_profile_stub( (int) $user_id, $full_name );
		}

		return (int) $user_id;
	}

	/**
	 * Default + safe custom post-signup destination.
	 *
	 * @param string $role lnkup_vendor|lnkup_organizer.
	 */
	private static function resolve_post_signup_redirect( string $role ): string {
		$default = 'lnkup_organizer' === $role
			? lnkup_page_url_helper( 'organizer-dashboard' ) . '#overview'
			: lnkup_page_url_helper( 'vendor-dashboard' );

		$requested = '';
		if ( ! empty( $_POST['lnkup_redirect'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$requested = (string) wp_unslash( $_POST['lnkup_redirect'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		} elseif ( ! empty( $_GET['redirect'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$requested = (string) wp_unslash( $_GET['redirect'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$safe = self::sanitize_redirect_target( $requested, $role );
		return $safe ? $safe : $default;
	}

	/**
	 * Map app/WP redirect hints to same-site LNKup pages only.
	 *
	 * @param string $redirect Raw redirect.
	 * @param string $role     Role key.
	 */
	private static function sanitize_redirect_target( string $redirect, string $role ): string {
		$redirect = trim( $redirect );
		if ( '' === $redirect ) {
			return '';
		}

		// App path aliases → WP dashboards.
		$path = $redirect;
		if ( preg_match( '#^https?://#i', $redirect ) ) {
			$parts = wp_parse_url( $redirect );
			$host  = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
			$home  = wp_parse_url( home_url( '/' ) );
			$home_host = isset( $home['host'] ) ? strtolower( (string) $home['host'] ) : '';
			if ( ! $host || ! $home_host || $host !== $home_host ) {
				return '';
			}
			$path = ( isset( $parts['path'] ) ? (string) $parts['path'] : '/' )
				. ( ! empty( $parts['fragment'] ) ? '#' . $parts['fragment'] : '' );
		}

		$path_only = strtok( $path, '?' );
		$path_only = $path_only ? $path_only : '/';
		$fragment  = '';
		if ( false !== strpos( $path, '#' ) ) {
			$bits     = explode( '#', $path, 2 );
			$path_only = $bits[0];
			$fragment  = $bits[1] ?? '';
		}
		$path_only = '/' . ltrim( (string) $path_only, '/' );
		$path_only = untrailingslashit( $path_only ) ?: '/';

		$app_map = array(
			'/vendor/onboarding'     => 'vendor-dashboard',
			'/vendor'                => 'vendor-dashboard',
			'/vendor/find'           => 'vendor-dashboard',
			'/organizer/onboarding'  => 'organizer-dashboard',
			'/organizer'             => 'organizer-dashboard',
		);
		if ( isset( $app_map[ $path_only ] ) ) {
			$slug = $app_map[ $path_only ];
			$url  = lnkup_page_url_helper( $slug );
			if ( 'organizer-dashboard' === $slug ) {
				$url .= '#overview';
			}
			return $url;
		}

		$slug = trim( $path_only, '/' );
		$slug = $slug ? strtok( $slug, '/' ) : '';
		if ( ! $slug || ! in_array( $slug, self::$safe_page_slugs, true ) ) {
			return '';
		}

		// Role mismatch guard.
		if ( 'lnkup_vendor' === $role && false !== strpos( $slug, 'organizer' ) ) {
			return '';
		}
		if ( 'lnkup_organizer' === $role && false !== strpos( $slug, 'vendor' ) ) {
			return '';
		}

		$url = lnkup_page_url_helper( $slug );
		$apply_id = 0;
		if ( preg_match( '/[?&]apply=(\d+)/', $redirect, $apply_match ) ) {
			$apply_id = (int) $apply_match[1];
		}
		if ( $apply_id > 0 && in_array( $slug, array( 'opportunities', 'find-events' ), true ) ) {
			$url = add_query_arg( 'apply', $apply_id, $url );
		}
		if ( $fragment && preg_match( '/^[a-z0-9_-]+$/i', $fragment ) ) {
			$url .= '#' . $fragment;
		} elseif ( 'organizer-dashboard' === $slug ) {
			$url .= '#overview';
		}
		return $url;
	}

	/**
	 * Create a minimal vendor profile so apply flows have a profile ID.
	 *
	 * @param int    $user_id     User ID.
	 * @param string $full_name   Optional display name.
	 */
	private static function ensure_vendor_profile_stub( int $user_id, string $full_name = '' ): void {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return;
		}
		if ( function_exists( 'lnkup_get_vendor_profile_id' ) && lnkup_get_vendor_profile_id( $user_id ) ) {
			return;
		}

		$title = $full_name ? $full_name : __( 'My Business', 'lnkup-marketplace' );
		$profile_id = wp_insert_post(
			array(
				'post_title'  => $title,
				'post_type'   => 'lnkup_vendor_profile',
				'post_status' => 'publish',
			),
			true
		);
		if ( is_wp_error( $profile_id ) || ! $profile_id ) {
			return;
		}
		update_post_meta( (int) $profile_id, '_lnkup_owner_user_id', $user_id );
		update_post_meta( (int) $profile_id, '_lnkup_verification_status', 'pending' );
		if ( $full_name ) {
			update_post_meta( (int) $profile_id, '_lnkup_owner_name', $full_name );
			update_post_meta( (int) $profile_id, '_lnkup_business_name', $title );
		}
	}

	/**
	 * Generate a unique username from email local part.
	 *
	 * @param string $email Email.
	 */
	private static function generate_username_from_email( string $email ): string {
		$base = sanitize_user( strstr( $email, '@', true ) ?: 'vendor', true );
		if ( '' === $base ) {
			$base = 'vendor';
		}
		$candidate = $base;
		$suffix    = 1;
		while ( username_exists( $candidate ) ) {
			$candidate = $base . $suffix;
			++$suffix;
		}
		return $candidate;
	}

	/**
	 * Persist city market attribution from sign-up form.
	 *
	 * @param int                  $user_id User ID.
	 * @param array<string, mixed> $input   Optional register() fields.
	 */
	private static function store_market_signup_meta( int $user_id, array $input = array() ): void {
		$market_slug = isset( $input['market'] ) ? sanitize_title( (string) $input['market'] ) : '';
		if ( ! $market_slug && ! empty( $_POST['lnkup_market'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$market_slug = sanitize_title( wp_unslash( (string) $_POST['lnkup_market'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		if ( ! $market_slug && ! empty( $_GET['market'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$market_slug = sanitize_title( wp_unslash( (string) $_GET['market'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( ! $market_slug || ! class_exists( 'LNKup_Market_Pages' ) ) {
			return;
		}
		if ( ! LNKup_Market_Pages::get_market_by_slug( $market_slug ) ) {
			return;
		}

		$has_outreach = ! empty( $_POST['lnkup_outreach_token'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$referrer_type = (string) get_user_meta( $user_id, '_lnkup_signup_referrer_type', true );
		$has_connector_ref = 'city_connector' === $referrer_type;

		update_user_meta( $user_id, '_lnkup_market_interest', $market_slug );
		update_user_meta( $user_id, '_lnkup_signup_city_page', $market_slug );
		if ( $has_outreach ) {
			update_user_meta( $user_id, '_lnkup_signup_source', 'outreach' );
		} elseif ( $has_connector_ref ) {
			update_user_meta( $user_id, '_lnkup_signup_source', 'connector_ref' );
		} else {
			update_user_meta( $user_id, '_lnkup_signup_source', 'city_page' );
		}

		$interest = '';
		if ( ! empty( $_POST['lnkup_signup_interest'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$interest = sanitize_key( wp_unslash( (string) $_POST['lnkup_signup_interest'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		} elseif ( ! empty( $_GET['interest'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$interest = sanitize_key( wp_unslash( (string) $_GET['interest'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( 'connector' === $interest ) {
			update_user_meta( $user_id, '_lnkup_signup_interest', 'connector' );
		}
	}

	/**
	 * Resolve market slug from request for form prefill.
	 */
	private static function get_request_market_slug(): string {
		if ( ! empty( $_POST['lnkup_market'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return sanitize_title( wp_unslash( (string) $_POST['lnkup_market'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		if ( ! empty( $_GET['market'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return sanitize_title( wp_unslash( (string) $_GET['market'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		return '';
	}

	/**
	 * Resolve connector interest flag from request.
	 */
	private static function get_request_signup_interest(): string {
		if ( ! empty( $_POST['lnkup_signup_interest'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return sanitize_key( wp_unslash( (string) $_POST['lnkup_signup_interest'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		if ( ! empty( $_GET['interest'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return sanitize_key( wp_unslash( (string) $_GET['interest'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		return '';
	}

	/**
	 * Resolve referral code from request.
	 */
	private static function get_request_ref_code(): string {
		if ( ! empty( $_POST['lnkup_ref'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return sanitize_text_field( wp_unslash( (string) $_POST['lnkup_ref'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		if ( ! empty( $_GET['ref'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return sanitize_text_field( wp_unslash( (string) $_GET['ref'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		return '';
	}

	/**
	 * Resolve redirect hint from request for form hidden field.
	 */
	private static function get_request_redirect(): string {
		if ( ! empty( $_POST['lnkup_redirect'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return sanitize_text_field( wp_unslash( (string) $_POST['lnkup_redirect'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		if ( ! empty( $_GET['redirect'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return sanitize_text_field( wp_unslash( (string) $_GET['redirect'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( ! empty( $_GET['apply'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$apply_id = absint( wp_unslash( (string) $_GET['apply'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $apply_id > 0 ) {
				return add_query_arg( 'apply', $apply_id, lnkup_page_url_helper( 'opportunities' ) );
			}
		}
		return '';
	}

	/**
	 * Render registration form shortcode.
	 *
	 * @param array $atts Attributes.
	 */
	public static function render_form( $atts = array() ): string {
		$claim_token = '';
		if ( ! empty( $_GET['claim_external_event'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$claim_token = sanitize_text_field( wp_unslash( (string) $_GET['claim_external_event'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( is_user_logged_in() ) {
			if ( ( $claim_token || get_user_meta( get_current_user_id(), '_lnkup_pending_external_claim', true ) ) && ( LNKup_Roles::is_organizer() || LNKup_Capabilities::user_has_manage_all() ) ) {
				return self::render_claim_confirmation( $claim_token );
			}
			$user = wp_get_current_user();
			$dash = in_array( 'lnkup_organizer', (array) $user->roles, true )
				? lnkup_page_url_helper( 'organizer-dashboard' )
				: lnkup_page_url_helper( 'vendor-dashboard' );
			return '<div class="lnkup-signup-logged-in"><p>' . esc_html__( 'You are already signed in.', 'lnkup-marketplace' ) . '</p><a class="lnkup-btn lnkup-btn--primary" href="' . esc_url( $dash ) . '">' . esc_html__( 'Go to dashboard', 'lnkup-marketplace' ) . '</a></div>';
		}

		$outreach_ctx   = null;
		$outreach_token = '';
		$claim_ctx      = null;
		if ( ! empty( $_GET['claim_external_event'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$claim_token = sanitize_text_field( wp_unslash( (string) $_GET['claim_external_event'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( class_exists( 'LNKup_External_Events' ) ) {
				$claim_ctx = LNKup_External_Events::get_claim_signup_context( $claim_token );
			}
		}
		if ( ! empty( $_POST['lnkup_claim_external_event_token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$claim_token = sanitize_text_field( wp_unslash( (string) $_POST['lnkup_claim_external_event_token'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! $claim_ctx && class_exists( 'LNKup_External_Events' ) ) {
				$claim_ctx = LNKup_External_Events::get_claim_signup_context( $claim_token );
			}
		}
		if ( ! empty( $_GET['outreach'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$outreach_token = sanitize_text_field( wp_unslash( $_GET['outreach'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( class_exists( 'LNKup_Outreach' ) ) {
				$outreach_ctx = LNKup_Outreach::get_signup_context( $outreach_token );
			}
		}
		if ( ! empty( $_POST['lnkup_outreach_token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$outreach_token = sanitize_text_field( wp_unslash( $_POST['lnkup_outreach_token'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! $outreach_ctx && class_exists( 'LNKup_Outreach' ) ) {
				$outreach_ctx = LNKup_Outreach::get_signup_context( $outreach_token );
			}
		}

		$selected_role = isset( $_POST['lnkup_role'] ) ? sanitize_text_field( wp_unslash( $_POST['lnkup_role'] ) ) : 'lnkup_vendor'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( $outreach_ctx && ! empty( $outreach_ctx['target_role'] ) ) {
			$selected_role = $outreach_ctx['target_role'];
		} elseif ( $claim_ctx ) {
			$selected_role = 'lnkup_organizer';
		} elseif ( ! empty( $_GET['role'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$role_param = sanitize_key( wp_unslash( $_GET['role'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 'organizer' === $role_param ) {
				$selected_role = 'lnkup_organizer';
			} elseif ( 'vendor' === $role_param ) {
				$selected_role = 'lnkup_vendor';
			}
		} elseif ( ! empty( $_GET['interest'] ) && empty( $_POST['lnkup_role'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$interest_param = sanitize_key( wp_unslash( (string) $_GET['interest'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 'organizer' === $interest_param ) {
				$selected_role = 'lnkup_organizer';
			} elseif ( 'vendor' === $interest_param ) {
				$selected_role = 'lnkup_vendor';
			}
		}
		if ( ! in_array( $selected_role, array( 'lnkup_vendor', 'lnkup_organizer' ), true ) ) {
			$selected_role = 'lnkup_vendor';
		}

		$posted_email = isset( $_POST['user_email'] ) ? sanitize_email( wp_unslash( $_POST['user_email'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! $posted_email && $outreach_ctx && ! empty( $outreach_ctx['email'] ) ) {
			$posted_email = sanitize_email( $outreach_ctx['email'] );
		}
		if ( ! $posted_email && $claim_ctx && ! empty( $claim_ctx['email'] ) ) {
			$posted_email = sanitize_email( (string) $claim_ctx['email'] );
		}

		$posted_name = isset( $_POST['lnkup_full_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['lnkup_full_name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$role_locked  = (bool) ( $outreach_ctx || $claim_ctx );
		$email_locked = (bool) ( ( $outreach_ctx && ! empty( $outreach_ctx['email'] ) ) || ( $claim_ctx && ! empty( $claim_ctx['email'] ) ) );
		$market_slug  = self::get_request_market_slug();
		$signup_interest = self::get_request_signup_interest();
		$ref_code     = self::get_request_ref_code();
		$org_ref_code = '';
		if ( class_exists( 'LNKup_Organizer_Recruit_Referrals' ) ) {
			$org_ref_code = LNKup_Organizer_Recruit_Referrals::current_invite_code();
			if ( $org_ref_code && ! $signup_interest ) {
				$signup_interest = LNKup_Organizer_Recruit_Referrals::current_invite_interest() ?: 'organizer';
			}
		}
		$redirect_hint = self::get_request_redirect();
		$org_inviter_name = '';
		if ( $org_ref_code && class_exists( 'LNKup_Organizer_Recruit_Referrals' ) ) {
			$resolved = LNKup_Organizer_Recruit_Referrals::resolve_code_public( $org_ref_code );
			$org_inviter_name = is_array( $resolved ) ? (string) ( $resolved['referrer_name'] ?? '' ) : '';
		}
		$org_invite_interest = in_array( $signup_interest, array( 'organizer', 'vendor' ), true ) ? $signup_interest : 'organizer';
		if ( $org_ref_code && empty( $_POST['lnkup_role'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$selected_role = 'organizer' === $org_invite_interest ? 'lnkup_organizer' : 'lnkup_vendor';
		}

		ob_start();
		?>
		<div class="lnkup-signup-form-wrap" data-testid="lnkup-signup-form">
			<?php if ( ! empty( $GLOBALS['lnkup_register_errors'] ) && is_wp_error( $GLOBALS['lnkup_register_errors'] ) ) : ?>
				<div class="lnkup-alert lnkup-alert--error" role="alert">
					<p><?php echo esc_html( $GLOBALS['lnkup_register_errors']->get_error_message() ); ?></p>
				</div>
			<?php endif; ?>

			<form class="lnkup-signup-form" method="post" action="">
				<?php wp_nonce_field( 'lnkup_register', 'lnkup_register_nonce' ); ?>
				<input type="hidden" name="lnkup_register_submit" value="1" />
				<?php if ( $outreach_token ) : ?>
					<input type="hidden" name="lnkup_outreach_token" value="<?php echo esc_attr( $outreach_token ); ?>" data-testid="lnkup-outreach-token" />
				<?php endif; ?>
				<?php if ( $claim_token ) : ?>
					<input type="hidden" name="lnkup_claim_external_event_token" value="<?php echo esc_attr( $claim_token ); ?>" data-testid="lnkup-claim-external-token" />
				<?php endif; ?>
				<?php if ( $market_slug ) : ?>
					<input type="hidden" name="lnkup_market" value="<?php echo esc_attr( $market_slug ); ?>" data-testid="lnkup-signup-market" />
				<?php endif; ?>
				<?php if ( 'connector' === $signup_interest ) : ?>
					<input type="hidden" name="lnkup_signup_interest" value="connector" />
				<?php endif; ?>
				<?php if ( $ref_code ) : ?>
					<input type="hidden" name="lnkup_ref" value="<?php echo esc_attr( $ref_code ); ?>" data-testid="lnkup-signup-ref" />
				<?php endif; ?>
				<?php if ( $org_ref_code ) : ?>
					<input type="hidden" name="lnkup_org_ref" value="<?php echo esc_attr( $org_ref_code ); ?>" data-testid="lnkup-signup-org-ref" />
					<input type="hidden" name="lnkup_signup_interest" value="<?php echo esc_attr( $org_invite_interest ); ?>" />
				<?php endif; ?>
				<?php if ( $redirect_hint ) : ?>
					<input type="hidden" name="lnkup_redirect" value="<?php echo esc_attr( $redirect_hint ); ?>" />
				<?php endif; ?>

				<?php if ( $org_inviter_name ) : ?>
					<div class="lnkup-alert lnkup-alert--info" data-testid="lnkup-org-recruit-invited-by">
						<p>
							<?php
							if ( 'vendor' === $org_invite_interest ) {
								printf(
									/* translators: %s: inviter display name */
									esc_html__( 'Invited by %s — create a vendor account to find events on LNKup.', 'lnkup-marketplace' ),
									esc_html( $org_inviter_name )
								);
							} else {
								printf(
									/* translators: %s: inviter display name */
									esc_html__( 'Invited by %s — create an organizer account to run events on LNKup.', 'lnkup-marketplace' ),
									esc_html( $org_inviter_name )
								);
							}
							?>
						</p>
					</div>
				<?php endif; ?>

				<?php if ( $outreach_ctx && ! empty( $outreach_ctx['message'] ) ) : ?>
					<div class="lnkup-alert lnkup-alert--info" role="status" data-testid="lnkup-outreach-message">
						<p><?php echo esc_html( $outreach_ctx['message'] ); ?></p>
					</div>
				<?php endif; ?>
				<?php if ( $claim_ctx && ! empty( $claim_ctx['message'] ) ) : ?>
					<div class="lnkup-alert lnkup-alert--info" role="status" data-testid="lnkup-claim-external-message">
						<p><?php echo esc_html( $claim_ctx['message'] ); ?></p>
					</div>
				<?php endif; ?>

				<fieldset class="lnkup-signup-form__roles"<?php echo $role_locked ? ' data-outreach-locked="1"' : ''; ?>>
					<legend class="lnkup-signup-form__label"><?php esc_html_e( 'I am signing up as', 'lnkup-marketplace' ); ?></legend>
					<div class="lnkup-signup-form__role-toggle" role="radiogroup" data-testid="lnkup-role-toggle">
						<label class="lnkup-signup-form__role-option<?php echo 'lnkup_vendor' === $selected_role ? ' is-active' : ''; ?>">
							<input type="radio" name="lnkup_role" value="lnkup_vendor"<?php checked( $selected_role, 'lnkup_vendor' ); ?><?php disabled( $role_locked && 'lnkup_vendor' !== $selected_role ); ?> />
							<span class="lnkup-signup-form__role-title"><?php esc_html_e( 'Vendor', 'lnkup-marketplace' ); ?></span>
							<span class="lnkup-signup-form__role-desc"><?php esc_html_e( 'Find events and apply for booths', 'lnkup-marketplace' ); ?></span>
						</label>
						<label class="lnkup-signup-form__role-option<?php echo 'lnkup_organizer' === $selected_role ? ' is-active' : ''; ?>">
							<input type="radio" name="lnkup_role" value="lnkup_organizer"<?php checked( $selected_role, 'lnkup_organizer' ); ?><?php disabled( $role_locked && 'lnkup_organizer' !== $selected_role ); ?> />
							<span class="lnkup-signup-form__role-title"><?php esc_html_e( 'Organizer', 'lnkup-marketplace' ); ?></span>
							<span class="lnkup-signup-form__role-desc"><?php esc_html_e( 'Launch events and book vendors', 'lnkup-marketplace' ); ?></span>
						</label>
					</div>
					<p class="lnkup-signup-form__hint lnkup-signup-form__role-hint">
						<?php esc_html_e( 'Vendors discover opportunities and pay only when accepted. Organizers publish events, review applications, and collect booth fees.', 'lnkup-marketplace' ); ?>
					</p>
				</fieldset>

				<p class="lnkup-signup-form__field">
					<label class="lnkup-signup-form__label" for="lnkup_full_name"><?php esc_html_e( 'Full name', 'lnkup-marketplace' ); ?> <span class="lnkup-signup-form__optional"><?php esc_html_e( '(optional)', 'lnkup-marketplace' ); ?></span></label>
					<input type="text" class="lnkup-signup-form__input" id="lnkup_full_name" name="lnkup_full_name" autocomplete="name" value="<?php echo esc_attr( $posted_name ); ?>" />
				</p>

				<p class="lnkup-signup-form__field">
					<label class="lnkup-signup-form__label" for="lnkup_user_email"><?php esc_html_e( 'Email', 'lnkup-marketplace' ); ?></label>
					<input type="email" class="lnkup-signup-form__input" id="lnkup_user_email" name="user_email" required autocomplete="email" value="<?php echo esc_attr( $posted_email ); ?>"<?php echo $email_locked ? ' readonly data-testid="lnkup-outreach-email"' : ''; ?> />
				</p>

				<p class="lnkup-signup-form__field">
					<label class="lnkup-signup-form__label" for="lnkup_user_pass"><?php esc_html_e( 'Password', 'lnkup-marketplace' ); ?></label>
					<input type="password" class="lnkup-signup-form__input" id="lnkup_user_pass" name="user_pass" required autocomplete="new-password" minlength="8" />
					<span class="lnkup-signup-form__hint"><?php esc_html_e( 'At least 8 characters', 'lnkup-marketplace' ); ?></span>
				</p>

				<p class="lnkup-signup-form__field">
					<label class="lnkup-signup-form__label" for="lnkup_user_pass_confirm"><?php esc_html_e( 'Confirm password', 'lnkup-marketplace' ); ?></label>
					<input type="password" class="lnkup-signup-form__input" id="lnkup_user_pass_confirm" name="user_pass_confirm" required autocomplete="new-password" minlength="8" />
				</p>

				<p class="lnkup-signup-form__field lnkup-signup-form__field--checkbox">
					<label>
						<input type="checkbox" name="lnkup_terms" value="1" required />
						<?php esc_html_e( 'I agree to the LNKup terms of use.', 'lnkup-marketplace' ); ?>
					</label>
				</p>

				<button type="submit" class="lnkup-btn lnkup-btn--primary lnkup-btn--lg lnkup-btn--block" data-testid="lnkup-signup-submit">
					<?php esc_html_e( 'Create free account', 'lnkup-marketplace' ); ?>
				</button>

				<p class="lnkup-signup-form__footer">
					<?php esc_html_e( 'Already have an account?', 'lnkup-marketplace' ); ?>
					<a href="<?php echo esc_url( wp_login_url( lnkup_registration_url() ) ); ?>"><?php esc_html_e( 'Log in', 'lnkup-marketplace' ); ?></a>
				</p>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render claim confirmation for logged-in organizers.
	 *
	 * @param string $claim_token Optional token from URL.
	 */
	public static function render_claim_confirmation( string $claim_token = '' ): string {
		$user_id = get_current_user_id();
		$pending = get_user_meta( $user_id, '_lnkup_pending_external_claim', true );
		if ( ! is_array( $pending ) ) {
			$pending = array();
		}
		if ( $claim_token && empty( $pending['token'] ) && class_exists( 'LNKup_External_Events' ) ) {
			LNKup_External_Events::store_pending_claim_from_request( $user_id );
			$pending = get_user_meta( $user_id, '_lnkup_pending_external_claim', true );
		}
		if ( ! is_array( $pending ) || empty( $pending['external_event_id'] ) ) {
			return '<div class="lnkup-alert lnkup-alert--error"><p>' . esc_html__( 'No pending event claim found.', 'lnkup-marketplace' ) . '</p></div>';
		}

		$event = LNKup_External_Events::get_row( (int) $pending['external_event_id'] );
		$title = $event['title'] ?? lnkup_copy( 'organizer_claim_event' );
		$token = (string) ( $pending['token'] ?? $claim_token );

		ob_start();
		?>
		<div class="lnkup-external-claim-panel" id="lnkup-external-claim-panel" data-external-event-id="<?php echo esc_attr( (string) (int) $pending['external_event_id'] ); ?>" data-claim-token="<?php echo esc_attr( $token ); ?>" data-testid="lnkup-external-claim-panel">
			<h2><?php echo esc_html( lnkup_copy( 'organizer_claim_event' ) ); ?></h2>
			<p><?php echo esc_html( $title ); ?></p>
			<p class="lnkup-muted"><?php echo esc_html( lnkup_copy( 'organizer_claim_pending_requests' ) ); ?></p>
			<?php if ( ! empty( $GLOBALS['lnkup_claim_errors'] ) && is_wp_error( $GLOBALS['lnkup_claim_errors'] ) ) : ?>
				<div class="lnkup-alert lnkup-alert--error"><p><?php echo esc_html( $GLOBALS['lnkup_claim_errors']->get_error_message() ); ?></p></div>
			<?php endif; ?>
			<p class="lnkup-alert" id="lnkup-external-claim-status" hidden role="status"></p>
			<button type="button" class="lnkup-btn lnkup-btn--primary" id="lnkup-external-claim-submit" data-testid="lnkup-external-claim-submit"><?php esc_html_e( 'Claim event on LNKup', 'lnkup-marketplace' ); ?></button>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}

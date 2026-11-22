<?php
/**
 * City Connector referral attribution.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Connector_Attribution {

	const COOKIE = 'lnkup_connector_ref';

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'capture_ref' ), 4 );
		add_action( 'user_register', array( __CLASS__, 'attribute_signup' ), 18, 1 );
	}

	/**
	 * Capture connector ref from query string.
	 */
	public static function capture_ref() {
		if ( empty( $_GET['ref'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$code = sanitize_text_field( wp_unslash( (string) $_GET['ref'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $code ) {
			return;
		}
		$row = LNKup_Connector::resolve_code( $code );
		if ( ! $row || ! LNKup_Connector::is_account_program( (string) ( $row['program'] ?? '' ) ) ) {
			return;
		}
		setcookie(
			self::COOKIE,
			$code,
			time() + ( 30 * DAY_IN_SECONDS ),
			COOKIEPATH,
			COOKIE_DOMAIN,
			is_ssl(),
			true
		);
		$_COOKIE[ self::COOKIE ] = $code; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		LNKup_Connector::record_event(
			(int) $row['connector_id'],
			0,
			0,
			'clicked'
		);
		if ( ! empty( $_GET['cc'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			LNKup_Connector::record_content_click( (int) $row['connector_id'], sanitize_key( wp_unslash( (string) $_GET['cc'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
	}

	/**
	 * Get ref code from request.
	 */
	public static function get_ref_from_request() {
		if ( ! empty( $_POST['lnkup_ref'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return sanitize_text_field( wp_unslash( (string) $_POST['lnkup_ref'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		if ( ! empty( $_GET['ref'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return sanitize_text_field( wp_unslash( (string) $_GET['ref'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( ! empty( $_COOKIE[ self::COOKIE ] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return sanitize_text_field( wp_unslash( (string) $_COOKIE[ self::COOKIE ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
		return '';
	}

	/**
	 * Attribute signup to connector.
	 *
	 * @param int $user_id User ID.
	 */
	public static function attribute_signup( $user_id ) {
		$code = self::get_ref_from_request();
		if ( ! $code ) {
			return;
		}
		$code_row = LNKup_Connector::resolve_code( $code );
		if ( ! $code_row || ! LNKup_Connector::is_account_program( (string) ( $code_row['program'] ?? '' ) ) ) {
			return;
		}
		$program = (string) $code_row['program'];
		if ( (int) $code_row['user_id'] === (int) $user_id ) {
			return;
		}

		global $wpdb;
		$ref_table = LNKup_Treasury_Tables::referrals_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$ref_table} WHERE referred_user_id = %d LIMIT 1", absint( $user_id ) )
		);
		if ( $existing ) {
			return;
		}

		$user = get_userdata( $user_id );
		$role = $user && ! empty( $user->roles ) ? (string) $user->roles[0] : 'lnkup_vendor';
		$has_outreach = ! empty( $_POST['lnkup_outreach_token'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$signup_source = $has_outreach ? 'outreach' : 'connector_ref';

		if ( ! get_user_meta( $user_id, '_lnkup_referral_code', true ) ) {
			update_user_meta( $user_id, '_lnkup_referral_code', $code );
			update_user_meta( $user_id, '_lnkup_referred_by_user_id', (int) $code_row['user_id'] );
			update_user_meta( $user_id, '_lnkup_referred_by_connector_id', (int) $code_row['connector_id'] );
			update_user_meta( $user_id, '_lnkup_referral_market', (string) ( $code_row['market_slug'] ?? '' ) );
			update_user_meta( $user_id, '_lnkup_signup_referrer_type', $program );
		}

		$source_url = '';
		if ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			$source_url = esc_url_raw( wp_unslash( (string) $_SERVER['HTTP_REFERER'] ) );
		}

		$wpdb->insert(
			$ref_table,
			array(
				'referrer_user_id' => (int) $code_row['user_id'],
				'referred_user_id' => absint( $user_id ),
				'referral_code_id' => (int) $code_row['id'],
				'program'          => $program,
				'market_slug'      => (string) ( $code_row['market_slug'] ?? '' ),
				'target_role'      => $role,
				'source_url'       => $source_url,
				'signup_source'    => $signup_source,
				'status'           => 'registered',
				'reward_status'    => 'none',
				'created_at'       => current_time( 'mysql' ),
			)
		);
		$referral_id = (int) $wpdb->insert_id;
		LNKup_Connector::record_event( (int) $code_row['connector_id'], $referral_id, $user_id, 'registered' );
		if ( $referral_id ) {
			do_action( 'lnkup_partner_referral_attributed', $referral_id, (int) $code_row['connector_id'], absint( $user_id ), $program );
		}
		LNKup_Connector_Rewards::maybe_queue_signup_reward( (int) $code_row['connector_id'], $referral_id, $role );
	}
}

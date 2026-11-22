<?php
/**
 * Vendor Passport feature flags and tunables (stored in lnkup_settings).
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Passport_Settings {

	const FLAGS = array(
		'passport_enabled',
		'passport_verification_v2',
		'document_vault_sharing',
		'passport_history_v2',
		'review_responses',
		'matching_reputation_signals',
	);

	/**
	 * Integer settings: key => array( default, min, max ).
	 */
	const INTS = array(
		'passport_review_min_count'      => array( 3, 1, 50 ),
		'passport_review_min_organizers' => array( 2, 1, 20 ),
		'passport_report_monthly_cap'    => array( 5, 1, 50 ),
		'passport_share_token_minutes'   => array( 15, 1, 120 ),
		'passport_review_edit_days'      => array( 7, 0, 60 ),
		'passport_response_edit_days'    => array( 7, 0, 60 ),
		'passport_public_rate_per_minute' => array( 60, 5, 1000 ),
	);

	const DEFAULT_REMINDER_DAYS = array( 30, 14, 7 );

	/**
	 * Default profile strength section weights (sum 100). Documents and trust are
	 * separate Passport indicators and are not part of completeness.
	 */
	const DEFAULT_STRENGTH_WEIGHTS = array(
		'business_basics'     => 30,
		'contact_socials'     => 10,
		'markets_served'      => 15,
		'setup_requirements'  => 15,
		'photos_proof'        => 20,
		'booking_preferences' => 10,
	);

	/**
	 * Whether a Passport flag is on. Sub-flags require passport_enabled.
	 *
	 * @param string $flag Flag key.
	 */
	public static function enabled( string $flag = 'passport_enabled' ): bool {
		if ( ! in_array( $flag, self::FLAGS, true ) ) {
			return false;
		}
		$settings = lnkup_get_settings();
		if ( empty( $settings['passport_enabled'] ) ) {
			return false;
		}
		$on = ! empty( $settings[ $flag ] );
		if ( $on && 'document_vault_sharing' === $flag && class_exists( 'LNKup_Private_Uploads_Probe' ) && LNKup_Private_Uploads_Probe::is_exposed() ) {
			$on = false;
		}
		return (bool) apply_filters( 'lnkup_passport_flag', $on, $flag );
	}

	/**
	 * Set when the last save() refused to turn on Document Vault sharing.
	 *
	 * @var bool
	 */
	public static $sharing_blocked = false;

	/**
	 * Bounded integer setting.
	 *
	 * @param string $key Setting key from INTS.
	 */
	public static function int( string $key ): int {
		if ( ! isset( self::INTS[ $key ] ) ) {
			return 0;
		}
		list( $default, $min, $max ) = self::INTS[ $key ];
		$settings = lnkup_get_settings();
		$value    = isset( $settings[ $key ] ) ? (int) $settings[ $key ] : $default;
		return max( $min, min( $max, $value ) );
	}

	/**
	 * Expiry reminder thresholds in days, descending.
	 *
	 * @return int[]
	 */
	public static function reminder_days(): array {
		$settings = lnkup_get_settings();
		$raw      = $settings['passport_reminder_days'] ?? self::DEFAULT_REMINDER_DAYS;
		if ( is_string( $raw ) ) {
			$raw = explode( ',', $raw );
		}
		$days = array_values( array_unique( array_filter( array_map( 'absint', (array) $raw ) ) ) );
		rsort( $days );
		return $days ? array_slice( $days, 0, 5 ) : self::DEFAULT_REMINDER_DAYS;
	}

	/**
	 * Profile strength weights (filterable), normalized to integers >= 0.
	 *
	 * @return array<string,int>
	 */
	public static function strength_weights(): array {
		$settings = lnkup_get_settings();
		$weights  = array();
		foreach ( self::DEFAULT_STRENGTH_WEIGHTS as $key => $default ) {
			$setting_key     = 'passport_strength_' . $key;
			$weights[ $key ] = isset( $settings[ $setting_key ] ) ? max( 0, min( 100, (int) $settings[ $setting_key ] ) ) : $default;
		}
		$weights = (array) apply_filters( 'lnkup_profile_strength_weights', $weights );
		if ( array_sum( array_map( 'intval', $weights ) ) <= 0 ) {
			return self::DEFAULT_STRENGTH_WEIGHTS;
		}
		return array_map( 'intval', $weights );
	}

	/**
	 * Persist admin-submitted Passport settings.
	 *
	 * @param array $input Raw input.
	 * @return array<string,mixed> Saved Passport settings.
	 */
	public static function save( array $input ): array {
		$settings = get_option( 'lnkup_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$sharing_was_on = ! empty( $settings['document_vault_sharing'] );
		foreach ( self::FLAGS as $flag ) {
			$settings[ $flag ] = ! empty( $input[ $flag ] );
		}
		self::$sharing_blocked = false;
		if ( ! $sharing_was_on && ! empty( $settings['document_vault_sharing'] ) && class_exists( 'LNKup_Private_Uploads_Probe' ) ) {
			LNKup_Private_Uploads_Probe::run();
			if ( ! LNKup_Private_Uploads_Probe::is_protected() ) {
				$settings['document_vault_sharing'] = false;
				self::$sharing_blocked              = true;
			}
		}
		foreach ( self::INTS as $key => $range ) {
			if ( isset( $input[ $key ] ) ) {
				$settings[ $key ] = max( $range[1], min( $range[2], (int) $input[ $key ] ) );
			}
		}
		if ( isset( $input['passport_reminder_days'] ) ) {
			$days = array_values( array_unique( array_filter( array_map( 'absint', explode( ',', (string) $input['passport_reminder_days'] ) ) ) ) );
			rsort( $days );
			$settings['passport_reminder_days'] = $days ? array_slice( $days, 0, 5 ) : self::DEFAULT_REMINDER_DAYS;
		}
		foreach ( array_keys( self::DEFAULT_STRENGTH_WEIGHTS ) as $key ) {
			$setting_key = 'passport_strength_' . $key;
			if ( isset( $input[ $setting_key ] ) ) {
				$settings[ $setting_key ] = max( 0, min( 100, (int) $input[ $setting_key ] ) );
			}
		}
		update_option( 'lnkup_settings', $settings, false );
		if ( class_exists( 'LNKup_Passport_Projection' ) ) {
			LNKup_Passport_Projection::flush_all_public_cache();
		}
		return self::snapshot();
	}

	/**
	 * Current Passport settings for admin display.
	 *
	 * @return array<string,mixed>
	 */
	public static function snapshot(): array {
		$settings = lnkup_get_settings();
		$out      = array();
		foreach ( self::FLAGS as $flag ) {
			$out[ $flag ] = ! empty( $settings[ $flag ] );
		}
		foreach ( array_keys( self::INTS ) as $key ) {
			$out[ $key ] = self::int( $key );
		}
		$out['passport_reminder_days'] = self::reminder_days();
		foreach ( self::strength_weights() as $key => $weight ) {
			$out[ 'passport_strength_' . $key ] = $weight;
		}
		return $out;
	}
}

/**
 * Whether the Vendor Passport is enabled (for the theme navigation).
 */
function lnkup_passport_enabled(): bool {
	return LNKup_Passport_Settings::enabled( 'passport_enabled' );
}

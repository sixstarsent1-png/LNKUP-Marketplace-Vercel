<?php
/**
 * Matching weights, thresholds, caps and feature flags (stored in lnkup_settings).
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Match_Settings {

	const FLAGS = array(
		'matching_enabled',
		'matching_vendor_discovery_enabled',
		'matching_organizer_discovery_enabled',
		'matching_tours_enabled',
	);

	const WEIGHT_KEYS = array(
		'category'     => 'match_weight_category',
		'geo'          => 'match_weight_geo',
		'requirements' => 'match_weight_requirements',
		'preferences'  => 'match_weight_preferences',
		'dates'        => 'match_weight_dates',
		'signals'      => 'match_weight_signals',
	);

	const DEFAULT_WEIGHTS = array(
		'category'     => 30,
		'geo'          => 25,
		'requirements' => 20,
		'preferences'  => 10,
		'dates'        => 10,
		'signals'      => 5,
	);

	/**
	 * Whether a matching feature flag is on. Sub-flags require the master flag.
	 *
	 * @param string $flag Flag key.
	 */
	public static function enabled( $flag = 'matching_enabled' ) {
		if ( ! in_array( $flag, self::FLAGS, true ) ) {
			return false;
		}
		$settings = lnkup_get_settings();
		if ( empty( $settings['matching_enabled'] ) ) {
			return false;
		}
		return ! empty( $settings[ $flag ] );
	}

	/**
	 * Factor weights (0-100 each), keyed by factor.
	 *
	 * @return array<string,int>
	 */
	public static function weights() {
		$settings = lnkup_get_settings();
		$weights  = array();
		foreach ( self::WEIGHT_KEYS as $factor => $key ) {
			$value              = isset( $settings[ $key ] ) ? (int) $settings[ $key ] : self::DEFAULT_WEIGHTS[ $factor ];
			$weights[ $factor ] = max( 0, min( 100, $value ) );
		}
		if ( array_sum( $weights ) <= 0 ) {
			return self::DEFAULT_WEIGHTS;
		}
		return $weights;
	}

	/**
	 * Minimum score (0-100) for a match to be recommended.
	 */
	public static function min_score() {
		return self::int_setting( 'match_min_score', 40, 0, 100 );
	}

	public static function vendor_weekly_invite_cap() {
		return self::int_setting( 'match_invite_vendor_weekly_cap', 5, 1, 50 );
	}

	public static function organizer_daily_invite_cap() {
		return self::int_setting( 'match_invite_org_daily_cap', 100, 1, 1000 );
	}

	public static function batch_max() {
		return self::int_setting( 'match_invite_batch_max', 50, 1, 200 );
	}

	/**
	 * Validate and persist admin-submitted matching settings.
	 *
	 * @param array $input Raw input.
	 * @return array Saved matching settings.
	 */
	public static function save( $input ) {
		$settings = get_option( 'lnkup_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$before = array_intersect_key( lnkup_get_settings(), array_flip( self::keys() ) );

		foreach ( self::FLAGS as $flag ) {
			$settings[ $flag ] = ! empty( $input[ $flag ] );
		}
		foreach ( self::WEIGHT_KEYS as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$settings[ $key ] = max( 0, min( 100, (int) $input[ $key ] ) );
			}
		}
		$ranges = array(
			'match_min_score'                => array( 0, 100 ),
			'match_invite_vendor_weekly_cap' => array( 1, 50 ),
			'match_invite_org_daily_cap'     => array( 1, 1000 ),
			'match_invite_batch_max'         => array( 1, 200 ),
		);
		foreach ( $ranges as $key => $range ) {
			if ( isset( $input[ $key ] ) ) {
				$settings[ $key ] = max( $range[0], min( $range[1], (int) $input[ $key ] ) );
			}
		}
		update_option( 'lnkup_settings', $settings, false );

		$after = array_intersect_key( lnkup_get_settings(), array_flip( self::keys() ) );
		if ( $before !== $after ) {
			$log = get_option( 'lnkup_match_settings_log', array() );
			$log = is_array( $log ) ? $log : array();
			array_unshift(
				$log,
				array(
					'user_id' => get_current_user_id(),
					'at'      => current_time( 'mysql' ),
					'before'  => $before,
					'after'   => $after,
				)
			);
			update_option( 'lnkup_match_settings_log', array_slice( $log, 0, 50 ), false );
			if ( class_exists( 'LNKup_Match_Cron' ) ) {
				LNKup_Match_Cron::queue_full_rebuild();
			}
		}
		return $after;
	}

	/**
	 * All setting keys owned by matching.
	 *
	 * @return string[]
	 */
	public static function keys() {
		return array_merge(
			self::FLAGS,
			array_values( self::WEIGHT_KEYS ),
			array( 'match_min_score', 'match_invite_vendor_weekly_cap', 'match_invite_org_daily_cap', 'match_invite_batch_max' )
		);
	}

	/**
	 * Bounded integer setting.
	 */
	private static function int_setting( $key, $default, $min, $max ) {
		$settings = lnkup_get_settings();
		$value    = isset( $settings[ $key ] ) ? (int) $settings[ $key ] : $default;
		return max( $min, min( $max, $value ) );
	}
}

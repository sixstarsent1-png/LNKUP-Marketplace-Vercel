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
	 * Whether a matching feature is switched on for anyone (compute, cron, background jobs).
	 * Sub-flags require the master flag.
	 *
	 * @param string $flag Flag key.
	 */
	public static function active( $flag = 'matching_enabled' ) {
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
	 * Whether a matching feature is on for a user: active and inside its rollout audience.
	 *
	 * @param string   $flag    Flag key.
	 * @param int|null $user_id Null = current user; 0 = system context.
	 */
	public static function enabled( $flag = 'matching_enabled', $user_id = null ) {
		if ( ! self::active( $flag ) ) {
			return false;
		}
		return ! class_exists( 'LNKup_Rollout' ) || LNKup_Rollout::in_audience( $flag, $user_id );
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

	const VERSION_OPTION  = 'lnkup_match_rules_version';
	const VERSIONS_OPTION = 'lnkup_match_rules_versions';

	/**
	 * Settings that change scores or who is recommended. Changing these creates a rules version
	 * and recomputes matches; flags and invite caps do not.
	 *
	 * @return string[]
	 */
	public static function rule_keys() {
		return array_merge( array_values( self::WEIGHT_KEYS ), array( 'match_min_score' ) );
	}

	/**
	 * Current rules version (0 = settings from before versioning).
	 */
	public static function rules_version() {
		return (int) get_option( self::VERSION_OPTION, 0 );
	}

	/**
	 * Saved rules versions, newest first.
	 *
	 * @return array<int,array{version:int,settings:array,note:string,user_id:int,at:string,reverted_from?:int}>
	 */
	public static function versions() {
		$list = get_option( self::VERSIONS_OPTION, array() );
		return is_array( $list ) ? $list : array();
	}

	/**
	 * Current rule values (weights + minimum score), with defaults applied.
	 *
	 * @return array<string,int>
	 */
	public static function current_rules() {
		$rules = array();
		foreach ( self::WEIGHT_KEYS as $factor => $key ) {
			$rules[ $key ] = self::weights()[ $factor ];
		}
		$rules['match_min_score'] = self::min_score();
		return $rules;
	}

	/**
	 * Restore an earlier rules version as a new version.
	 *
	 * @param int    $version Version to restore.
	 * @param string $note    Why.
	 * @return array|WP_Error Saved settings.
	 */
	public static function revert( $version, $note = '' ) {
		foreach ( self::versions() as $row ) {
			if ( (int) $row['version'] === (int) $version ) {
				$note = trim( (string) $note );
				return self::save(
					(array) $row['settings'],
					sprintf( 'Revert to v%d', (int) $version ) . ( '' !== $note ? ': ' . $note : '' ),
					(int) $version
				);
			}
		}
		return new WP_Error( 'lnkup_match_version_missing', __( 'That rules version no longer exists.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
	}

	/**
	 * Validate and persist admin-submitted matching settings.
	 *
	 * Weight / minimum-score changes need a short note, create a new rules version and recompute
	 * matches. Flag and cap changes are logged but never trigger a rebuild.
	 *
	 * @param array  $input         Raw input (only keys present are changed).
	 * @param string $note          Reason for a rules change.
	 * @param int|null $reverted_from Version being restored (null for a normal save).
	 * @return array|WP_Error Saved matching settings.
	 */
	public static function save( $input, $note = '', $reverted_from = null ) {
		$settings = get_option( 'lnkup_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$before       = array_intersect_key( lnkup_get_settings(), array_flip( self::keys() ) );
		$rules_before = self::current_rules();
		$was_active   = self::active();

		foreach ( self::FLAGS as $flag ) {
			if ( array_key_exists( $flag, $input ) ) {
				$settings[ $flag ] = ! empty( $input[ $flag ] );
			}
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

		$rules_after = array();
		foreach ( self::WEIGHT_KEYS as $factor => $key ) {
			$rules_after[ $key ] = isset( $settings[ $key ] ) ? max( 0, min( 100, (int) $settings[ $key ] ) ) : self::DEFAULT_WEIGHTS[ $factor ];
		}
		$rules_after['match_min_score'] = isset( $settings['match_min_score'] ) ? max( 0, min( 100, (int) $settings['match_min_score'] ) ) : 40;
		$rules_changed                  = $rules_after !== $rules_before;
		$note                           = sanitize_text_field( (string) $note );
		if ( $rules_changed && '' === trim( $note ) ) {
			return new WP_Error( 'lnkup_match_note_required', __( 'Add a short note explaining the ranking change before saving it.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
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
					'note'    => $note,
				)
			);
			update_option( 'lnkup_match_settings_log', array_slice( $log, 0, 50 ), false );
		}
		if ( $rules_changed ) {
			self::record_version( $rules_before, $rules_after, $note, null === $reverted_from ? null : (int) $reverted_from );
		}
		$turned_on = ! $was_active && self::active();
		if ( ( $rules_changed || $turned_on ) && class_exists( 'LNKup_Match_Cron' ) ) {
			LNKup_Match_Cron::queue_full_rebuild();
		}
		if ( $rules_changed && class_exists( 'LNKup_Match_Quality' ) && method_exists( 'LNKup_Match_Quality', 'flush' ) ) {
			LNKup_Match_Quality::flush();
		}
		return $after;
	}

	/**
	 * Append a rules version (seeding v0 with the previous values the first time).
	 */
	private static function record_version( array $rules_before, array $rules_after, $note, $reverted_from ) {
		$versions = self::versions();
		$now      = current_time( 'mysql' );
		if ( ! $versions ) {
			$versions[] = array(
				'version'  => self::rules_version(),
				'settings' => $rules_before,
				'note'     => __( 'Settings before versioning', 'lnkup-marketplace' ),
				'user_id'  => 0,
				'at'       => $now,
			);
		}
		$next = max( array_map( 'intval', wp_list_pluck( $versions, 'version' ) ) ) + 1;
		$row  = array(
			'version'  => $next,
			'settings' => $rules_after,
			'note'     => $note,
			'user_id'  => get_current_user_id(),
			'at'       => $now,
		);
		if ( null !== $reverted_from ) {
			$row['reverted_from'] = (int) $reverted_from;
		}
		array_unshift( $versions, $row );
		update_option( self::VERSIONS_OPTION, array_slice( $versions, 0, 50 ), false );
		update_option( self::VERSION_OPTION, $next, false );
		do_action( 'lnkup_match_rules_versioned', $next, $rules_after, $rules_before );
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

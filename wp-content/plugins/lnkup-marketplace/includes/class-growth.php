<?php
/**
 * Vendor growth platform orchestrator.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Growth {

	/**
	 * Zero-state dashboard when vendor has no growth history or a module fails.
	 *
	 * @return array<string, mixed>
	 */
	public static function vendor_dashboard_zero() {
		return array(
			'kpis' => array(
				'lifetime_revenue'         => 0,
				'revenue_this_month'       => 0,
				'vendor_score'             => 0,
				'growth_rate'              => 0,
				'profile_strength'         => 0,
				'completed_events'         => 0,
				'application_success_rate' => 0,
				'cities_served'            => 0,
				'applications_submitted'   => 0,
				'applications_approved'    => 0,
			),
			'insights'           => array(),
			'recommendations'    => array(),
			'benchmarks'         => array(),
			'achievements'       => array(
				'earned'   => array(),
				'progress' => array(),
				'next'     => null,
			),
			'profile_strength'   => array(
				'score'      => 0,
				'items'      => array(),
				'incomplete' => array(),
			),
			'goals'              => array(),
			'roi'                => array(
				'total_spend'        => 0,
				'estimated_revenue'  => 0,
				'profit_estimate'    => 0,
			),
			'calendar'           => array(),
			'empty_state'        => true,
		);
	}

	/**
	 * Safely invoke a growth module; never throw or return WP_Error to callers.
	 *
	 * @param callable $callable Module callback.
	 * @param int      $user_id  Vendor user ID.
	 * @param mixed    $fallback Fallback value.
	 * @return mixed
	 */
	private static function safe_module( $callable, $user_id, $fallback ) {
		try {
			$result = call_user_func( $callable, $user_id );
			if ( is_wp_error( $result ) ) {
				return $fallback;
			}
			return $result ?? $fallback;
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'LNKup Growth module error: ' . $e->getMessage() );
			}
			return $fallback;
		}
	}

	public static function vendor_dashboard( $user_id = 0 ) {
		$user_id = $user_id ?: get_current_user_id();
		if ( ! $user_id ) {
			return self::vendor_dashboard_zero();
		}

		$cached = get_transient( 'lnkup_growth_vendor_' . $user_id );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		try {
			self::safe_module( array( 'LNKup_Growth_Goals', 'sync_progress_from_metrics' ), $user_id, null );
		} catch ( Throwable $e ) {
			// Continue with partial dashboard.
		}

		$zero = self::vendor_dashboard_zero();
		$kpis = self::safe_module( array( 'LNKup_Growth_Kpis', 'for_vendor' ), $user_id, $zero['kpis'] );
		if ( ! is_array( $kpis ) ) {
			$kpis = $zero['kpis'];
		}

		$profile_strength = self::safe_module( array( 'LNKup_Growth_Profile_Strength', 'score' ), $user_id, $zero['profile_strength'] );
		if ( ! is_array( $profile_strength ) ) {
			$profile_strength = $zero['profile_strength'];
		}

		$data = array(
			'kpis'               => $kpis,
			'insights'           => self::safe_module( array( 'LNKup_Growth_Recommendations', 'personalized_insights' ), $user_id, array() ),
			'recommendations'    => self::safe_module( array( 'LNKup_Growth_Recommendations', 'generate' ), $user_id, array() ),
			'benchmarks'         => self::safe_module( array( 'LNKup_Growth_Benchmarks', 'for_vendor' ), $user_id, array() ),
			'achievements'       => self::safe_module( array( 'LNKup_Growth_Achievements', 'for_vendor' ), $user_id, $zero['achievements'] ),
			'profile_strength'   => $profile_strength,
			'goals'              => self::safe_module( array( 'LNKup_Growth_Goals', 'list_for_vendor' ), $user_id, array() ),
			'roi'                => $zero['roi'],
			'calendar'           => array(),
			'empty_state'        => empty( $kpis['applications_submitted'] ) && empty( $kpis['completed_events'] ),
		);

		if ( ! is_array( $data['insights'] ) ) {
			$data['insights'] = array();
		}
		if ( ! is_array( $data['recommendations'] ) ) {
			$data['recommendations'] = array();
		}
		if ( ! is_array( $data['benchmarks'] ) ) {
			$data['benchmarks'] = array();
		}
		if ( ! is_array( $data['achievements'] ) ) {
			$data['achievements'] = $zero['achievements'];
		}
		if ( ! is_array( $data['goals'] ) ) {
			$data['goals'] = array();
		}

		set_transient( 'lnkup_growth_vendor_' . $user_id, $data, 10 * MINUTE_IN_SECONDS );
		return $data;
	}

	public static function admin_summary() {
		$vendors = count_users();
		$vendor_count = isset( $vendors['avail_roles']['lnkup_vendor'] ) ? (int) $vendors['avail_roles']['lnkup_vendor'] : 0;
		return array(
			'active_vendors'   => $vendor_count,
			'avg_profile_score'=> self::marketplace_avg_profile(),
			'goals_in_progress'=> self::count_active_goals(),
			'recommendations'  => self::count_active_recommendations(),
		);
	}

	private static function marketplace_avg_profile() {
		global $wpdb;
		$table = LNKup_Growth_Tables::benchmarks_table();
		if ( ! $table || $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return 0;
		}
		$p50   = $wpdb->get_var( "SELECT p50 FROM {$table} WHERE metric_key = 'profile_strength' ORDER BY snapshot_date DESC LIMIT 1" );
		return $p50 ? round( (float) $p50 ) : 0;
	}

	private static function count_active_goals() {
		global $wpdb;
		$table = LNKup_Growth_Tables::goals_table();
		if ( ! $table || $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return 0;
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'active'" );
	}

	private static function count_active_recommendations() {
		global $wpdb;
		$table = LNKup_Growth_Tables::recommendations_table();
		if ( ! $table || $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return 0;
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE dismissed_at IS NULL" );
	}

	public static function backfill_vendor( $user_id ) {
		LNKup_Growth_Journey::detect_and_record( $user_id );
		LNKup_Growth_Goals::seed_defaults( $user_id );
		LNKup_Growth_Snapshots::capture( $user_id );
	}

	public static function backfill_all() {
		$vendors = get_users( array( 'role' => 'lnkup_vendor', 'fields' => 'ID' ) );
		foreach ( $vendors as $vid ) {
			self::backfill_vendor( (int) $vid );
		}
	}
}

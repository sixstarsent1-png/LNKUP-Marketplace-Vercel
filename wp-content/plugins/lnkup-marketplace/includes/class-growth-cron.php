<?php
/**
 * Vendor growth cron jobs.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Growth_Cron {

	const HOOK = 'lnkup_growth_daily';

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run_daily' ) );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 3600, 'daily', self::HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	public static function run_daily() {
		if ( get_transient( 'lnkup_growth_daily_' . gmdate( 'Y-m-d' ) ) ) {
			return;
		}
		set_transient( 'lnkup_growth_daily_' . gmdate( 'Y-m-d' ), 1, DAY_IN_SECONDS );
		$vendors = get_users( array( 'role' => 'lnkup_vendor', 'fields' => 'ID' ) );
		foreach ( $vendors as $vid ) {
			LNKup_Growth_Snapshots::capture( (int) $vid );
			LNKup_Growth_Goals::sync_progress_from_metrics( (int) $vid );
		}
		LNKup_Growth_Benchmarks::compute_marketplace();
	}
}

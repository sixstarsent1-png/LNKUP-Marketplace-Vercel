<?php
/**
 * Vendor growth daily snapshots and trend data.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Growth_Snapshots {

	/**
	 * Store daily snapshot for vendor.
	 *
	 * @param int $user_id Vendor user ID.
	 */
	public static function capture( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return false;
		}
		$metrics = LNKup_Growth_Kpis::for_vendor( $user_id );
		global $wpdb;
		$table = LNKup_Growth_Tables::snapshots_table();
		$date  = gmdate( 'Y-m-d' );
		$wpdb->replace(
			$table,
			array(
				'vendor_user_id' => $user_id,
				'snapshot_date'  => $date,
				'metrics'        => wp_json_encode( $metrics ),
				'created_at'     => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s' )
		);
		delete_transient( 'lnkup_growth_kpis_' . $user_id );
		return true;
	}

	/**
	 * Revenue growth rate vs prior month.
	 *
	 * @param int $user_id Vendor user ID.
	 */
	public static function growth_rate( $user_id ) {
		global $wpdb;
		$table = LNKup_Growth_Tables::snapshots_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT snapshot_date, metrics FROM {$table} WHERE vendor_user_id = %d ORDER BY snapshot_date DESC LIMIT 60",
				$user_id
			),
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();
		if ( count( $rows ) < 2 ) {
			return 0;
		}
		$current = json_decode( $rows[0]['metrics'], true );
		$prior   = json_decode( $rows[ min( 29, count( $rows ) - 1 ) ]['metrics'], true );
		$cur_rev = (float) ( $current['revenue_this_month'] ?? 0 );
		$old_rev = (float) ( $prior['revenue_this_month'] ?? 0 );
		if ( $old_rev <= 0 ) {
			return $cur_rev > 0 ? 100 : 0;
		}
		return round( ( ( $cur_rev - $old_rev ) / $old_rev ) * 100, 1 );
	}

	/**
	 * Trend series for charts.
	 *
	 * @param int    $user_id Vendor user ID.
	 * @param string $metric  Metric key.
	 * @param int    $days    Days of history.
	 */
	public static function trend( $user_id, $metric, $days = 30 ) {
		global $wpdb;
		$table = LNKup_Growth_Tables::snapshots_table();
		$since = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT snapshot_date, metrics FROM {$table} WHERE vendor_user_id = %d AND snapshot_date >= %s ORDER BY snapshot_date ASC",
				$user_id,
				$since
			),
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();
		$series = array();
		foreach ( $rows as $row ) {
			$m = json_decode( $row['metrics'], true );
			$series[] = array(
				'date'  => $row['snapshot_date'],
				'value' => (float) ( $m[ $metric ] ?? 0 ),
			);
		}
		return $series;
	}

	/**
	 * Backfill milestones for existing vendors.
	 */
	public static function backfill_milestones( $user_id ) {
		LNKup_Growth_Journey::detect_and_record( $user_id );
	}
}

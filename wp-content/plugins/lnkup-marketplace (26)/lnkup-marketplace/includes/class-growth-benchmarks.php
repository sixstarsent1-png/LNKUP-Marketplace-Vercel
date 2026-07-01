<?php
/**
 * Marketplace benchmark percentiles (privacy-safe).
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Growth_Benchmarks {

	const METRICS = array( 'revenue', 'approval_rate', 'vendor_score', 'completed_events', 'profile_strength' );

	public static function compute_marketplace() {
		$vendors = get_users( array( 'role' => 'lnkup_vendor', 'fields' => 'ID' ) );
		$date    = gmdate( 'Y-m-d' );
		$metrics = array();
		foreach ( self::METRICS as $key ) {
			$metrics[ $key ] = array();
		}
		foreach ( $vendors as $vid ) {
			$kpis = LNKup_Growth_Kpis::for_vendor( (int) $vid );
			$metrics['revenue'][]           = (float) ( $kpis['lifetime_revenue'] ?? 0 );
			$metrics['approval_rate'][]     = (float) ( $kpis['application_success_rate'] ?? 0 );
			$metrics['vendor_score'][]      = (float) ( $kpis['vendor_score'] ?? 0 );
			$metrics['completed_events'][]  = (float) ( $kpis['completed_events'] ?? 0 );
			$metrics['profile_strength'][]  = (float) ( $kpis['profile_strength'] ?? 0 );
		}
		global $wpdb;
		$table = LNKup_Growth_Tables::benchmarks_table();
		foreach ( self::METRICS as $key ) {
			$values = $metrics[ $key ];
			sort( $values, SORT_NUMERIC );
			$n = count( $values );
			$wpdb->replace(
				$table,
				array(
					'snapshot_date' => $date,
					'metric_key'    => $key,
					'p25'           => self::percentile( $values, 25 ),
					'p50'           => self::percentile( $values, 50 ),
					'p75'           => self::percentile( $values, 75 ),
					'p90'           => self::percentile( $values, 90 ),
					'sample_size'   => $n,
					'created_at'    => current_time( 'mysql' ),
				)
			);
		}
	}

	public static function for_vendor( $user_id ) {
		$user_id = $user_id ?: get_current_user_id();
		$kpis    = LNKup_Growth_Kpis::for_vendor( $user_id );
		$out     = array();
		$map     = array(
			'revenue'          => $kpis['lifetime_revenue'] ?? 0,
			'approval_rate'    => $kpis['application_success_rate'] ?? 0,
			'vendor_score'     => $kpis['vendor_score'] ?? 0,
			'completed_events' => $kpis['completed_events'] ?? 0,
			'profile_strength' => $kpis['profile_strength'] ?? 0,
		);
		foreach ( $map as $key => $value ) {
			$out[ $key ] = self::compare_metric( $key, (float) $value );
		}
		return $out;
	}

	private static function compare_metric( $key, $value ) {
		global $wpdb;
		$table = LNKup_Growth_Tables::benchmarks_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE metric_key = %s ORDER BY snapshot_date DESC LIMIT 1",
				$key
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return array(
				'percentile'    => 50,
				'label'         => __( 'Marketplace Average', 'lnkup-marketplace' ),
				'vs_marketplace'=> 'average',
			);
		}
		$pct = 50;
		if ( $value >= (float) $row['p90'] ) {
			$pct = 90;
		} elseif ( $value >= (float) $row['p75'] ) {
			$pct = 75;
		} elseif ( $value >= (float) $row['p50'] ) {
			$pct = 50;
		} elseif ( $value >= (float) $row['p25'] ) {
			$pct = 25;
		} else {
			$pct = 10;
		}
		return array(
			'percentile'     => $pct,
			'label'          => self::label_for_percentile( $pct ),
			'vs_marketplace' => self::vs_label( $pct ),
			'marketplace_p50'=> (float) $row['p50'],
		);
	}

	private static function percentile( $sorted, $pct ) {
		$n = count( $sorted );
		if ( ! $n ) {
			return 0;
		}
		$idx = (int) ceil( ( $pct / 100 ) * $n ) - 1;
		$idx = max( 0, min( $n - 1, $idx ) );
		return round( (float) $sorted[ $idx ], 4 );
	}

	private static function label_for_percentile( $pct ) {
		if ( $pct >= 90 ) {
			return __( 'Top 10%', 'lnkup-marketplace' );
		}
		if ( $pct >= 75 ) {
			return __( 'Above Average', 'lnkup-marketplace' );
		}
		if ( $pct >= 50 ) {
			return __( 'Marketplace Average', 'lnkup-marketplace' );
		}
		return __( 'Needs Improvement', 'lnkup-marketplace' );
	}

	private static function vs_label( $pct ) {
		if ( $pct >= 75 ) {
			return 'above_average';
		}
		if ( $pct >= 50 ) {
			return 'average';
		}
		return 'below_average';
	}
}

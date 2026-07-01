<?php
/**
 * Intelligence metric snapshots.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Intelligence_Snapshots {

	/**
	 * Save or update a scoped snapshot for a date.
	 *
	 * @param string $scope_type event|opportunity|organizer|market.
	 * @param int    $scope_id   Scope ID (0 for market).
	 * @param array  $metrics    Metrics payload.
	 * @param string $date       Y-m-d date.
	 */
	public static function save( $scope_type, $scope_id, $metrics, $date = '' ) {
		global $wpdb;
		$table = LNKup_Intelligence_Tables::metric_snapshots_table();
		$date  = $date ?: gmdate( 'Y-m-d' );
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE scope_type = %s AND scope_id = %d AND snapshot_date = %s",
				sanitize_key( $scope_type ),
				absint( $scope_id ),
				$date
			)
		);
		$row = array(
			'scope_type'    => sanitize_key( $scope_type ),
			'scope_id'      => absint( $scope_id ),
			'snapshot_date' => $date,
			'metrics'       => wp_json_encode( $metrics ),
			'created_at'    => current_time( 'mysql' ),
		);
		if ( $existing ) {
			$wpdb->update( $table, array( 'metrics' => $row['metrics'] ), array( 'id' => $existing ) );
			return (int) $existing;
		}
		$wpdb->insert( $table, $row );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Get latest snapshot for scope.
	 *
	 * @param string $scope_type Scope type.
	 * @param int    $scope_id   Scope ID.
	 * @param int    $limit      Number of days.
	 */
	public static function get_history( $scope_type, $scope_id, $limit = 30 ) {
		global $wpdb;
		$table = LNKup_Intelligence_Tables::metric_snapshots_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE scope_type = %s AND scope_id = %d ORDER BY snapshot_date DESC LIMIT %d",
				sanitize_key( $scope_type ),
				absint( $scope_id ),
				absint( $limit )
			),
			ARRAY_A
		);
		foreach ( $rows as &$row ) {
			$row['metrics'] = json_decode( $row['metrics'] ?? '{}', true );
		}
		return $rows ?: array();
	}

	/**
	 * Daily marketplace rollup.
	 */
	public static function rollup_daily() {
		$date = gmdate( 'Y-m-d' );
		if ( class_exists( 'LNKup_Revenue_Intelligence' ) ) {
			$revenue = LNKup_Revenue_Intelligence::marketplace_summary();
		} else {
			$revenue = array();
		}
		if ( class_exists( 'LNKup_Market_Trends' ) ) {
			$cities     = LNKup_Market_Trends::cities( array( 'limit' => 10 ) );
			$categories = LNKup_Market_Trends::categories( array( 'limit' => 10 ) );
		} else {
			$cities     = array();
			$categories = array();
		}
		$metrics = array(
			'revenue'    => $revenue,
			'cities'     => $cities,
			'categories' => $categories,
			'applications' => self::count_applications_today(),
			'paid_today'   => self::count_paid_today(),
		);
		self::save( 'market', 0, $metrics, $date );
		global $wpdb;
		$table = LNKup_Intelligence_Tables::market_snapshots_table();
		$wpdb->replace(
			$table,
			array(
				'snapshot_date' => $date,
				'metrics'       => wp_json_encode( $metrics ),
				'created_at'    => current_time( 'mysql' ),
			)
		);
		$events = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'posts_per_page' => 50,
				'post_status'    => 'publish',
				'fields'         => 'ids',
			)
		);
		foreach ( $events as $event_id ) {
			self::rollup_event( $event_id, $date );
		}
	}

	/**
	 * Rollup single event metrics.
	 *
	 * @param int    $event_id Event ID.
	 * @param string $date     Date.
	 */
	public static function rollup_event( $event_id, $date = '' ) {
		$event_id = absint( $event_id );
		$date     = $date ?: gmdate( 'Y-m-d' );
		$apps     = self::applications_for_event( $event_id );
		$metrics  = array(
			'application_count' => count( $apps ),
			'paid_count'        => self::count_by_status( $apps, array( 'paid', 'completed' ) ),
			'approved_count'    => self::count_by_status( $apps, array( 'approved', 'payment_required', 'paid', 'completed' ) ),
		);
		if ( class_exists( 'LNKup_Event_Score' ) ) {
			$score = LNKup_Event_Score::compute( $event_id );
			$metrics['event_score'] = $score['score'];
			$metrics['event_label'] = $score['label'];
		}
		if ( class_exists( 'LNKup_Funnel_Analytics' ) ) {
			$metrics['application_funnel'] = LNKup_Funnel_Analytics::application_funnel( $event_id );
			$metrics['payment_funnel']     = LNKup_Funnel_Analytics::payment_funnel( $event_id );
		}
		self::save( 'event', $event_id, $metrics, $date );
	}

	private static function applications_for_event( $event_id ) {
		return get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_lnkup_event_id',
				'meta_value'     => absint( $event_id ),
			)
		);
	}

	private static function count_by_status( $app_ids, $statuses ) {
		$n = 0;
		foreach ( $app_ids as $app_id ) {
			if ( in_array( get_post_meta( $app_id, '_lnkup_application_status', true ), $statuses, true ) ) {
				$n++;
			}
		}
		return $n;
	}

	private static function count_applications_today() {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_lnkup_submitted_at' AND pm.meta_value >= %s AND p.post_type = 'lnkup_application'",
				gmdate( 'Y-m-d 00:00:00' )
			)
		);
	}

	private static function count_paid_today() {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_lnkup_paid_at' AND pm.meta_value >= %s AND p.post_type = 'lnkup_application'",
				gmdate( 'Y-m-d 00:00:00' )
			)
		);
	}
}

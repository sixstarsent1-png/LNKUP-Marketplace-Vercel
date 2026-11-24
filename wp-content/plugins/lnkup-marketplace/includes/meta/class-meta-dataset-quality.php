<?php
/**
 * Meta Dataset Quality API sync (developers.facebook.com/docs/marketing-api/conversions-api/dataset-quality-api/).
 *
 * GET /{version}/dataset_quality?dataset_id=…&fields=web{…}, daily, stored as snapshots.
 * Only values Meta actually returns are stored; a missing field is stored as "unavailable"
 * (NULL), never as zero. The dashboard reads snapshots, never the live API.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Meta_Dataset_Quality {

	const HOOK          = 'lnkup_meta_dq_sync';
	const STATUS_OPTION = 'lnkup_meta_dq_status';

	/** Full field set from the official docs (Nov 2026). */
	const FIELDS_FULL = 'web{event_name,event_match_quality{composite_score,match_key_feedback{identifier,coverage{percentage}},diagnostics},acr{percentage,description},event_potential_aly_acr_increase{percentage,description},event_coverage{percentage,description,goal_percentage},dedupe_key_feedback{dedupe_key,browser_events_with_dedupe_key{percentage,description},server_events_with_dedupe_key{percentage,description},overall_browser_coverage_from_dedupe_key{percentage,description}},data_freshness{upload_frequency,description},value_diagnostics{issue_type,issue_count,event_count,issue_rate,issue_example},event_delay{events_delayed_over_2h,events_delayed_over_24h,total_events,pct_delayed_over_2h,pct_delayed_over_24h}}';

	/** Core fields, used if Meta rejects one of the newer fields for this token or dataset. */
	const FIELDS_CORE = 'web{event_name,event_match_quality{composite_score,match_key_feedback{identifier,coverage{percentage}}},event_coverage{percentage,goal_percentage},data_freshness{upload_frequency}}';

	public static function init(): void {
		add_action( self::HOOK, array( __CLASS__, 'sync' ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function status(): array {
		$status = get_option( self::STATUS_OPTION, array() );
		return is_array( $status ) ? $status : array();
	}

	/**
	 * Pull metrics and store today's snapshot.
	 *
	 * @param bool $manual Triggered from wp-admin (still rate limited).
	 * @return array<string,mixed> Status.
	 */
	public static function sync( bool $manual = false ): array {
		$status = self::status();
		if ( ! LNKup_Meta_Config::dataset_quality_enabled() ) {
			return $status;
		}
		if ( $manual && get_transient( 'lnkup_meta_dq_manual' ) ) {
			$status['notice'] = 'rate_limited';
			return $status;
		}
		if ( $manual ) {
			set_transient( 'lnkup_meta_dq_manual', 1, 10 * MINUTE_IN_SECONDS );
		}

		$token   = LNKup_Meta_Config::dataset_quality_token();
		$query   = array( 'dataset_id' => LNKup_Meta_Config::dataset_id(), 'fields' => self::FIELDS_FULL );
		$started = microtime( true );
		$result  = LNKup_Meta_Capi_Client::get( 'dataset_quality', $query, $token );
		$fields  = 'full';
		if ( empty( $result['ok'] ) && 0 === strpos( (string) $result['error_code'], '100' ) ) {
			$query['fields'] = self::FIELDS_CORE;
			$result          = LNKup_Meta_Capi_Client::get( 'dataset_quality', $query, $token );
			$fields          = 'core';
		}
		LNKup_Meta_Dispatcher::log_delivery( 'dataset_quality', 0, $result, false, (int) round( ( microtime( true ) - $started ) * 1000 ) );

		$status['checked_at'] = time();
		if ( empty( $result['ok'] ) ) {
			$status['ok']            = false;
			$status['error_code']    = (string) $result['error_code'];
			$status['error_message'] = LNKup_Meta_Redactor::redact( (string) $result['error_message'] );
			$status['auth_error']    = ! empty( $result['auth_error'] );
			update_option( self::STATUS_OPTION, $status, false );
			return $status;
		}

		$web    = isset( $result['body']['web'] ) && is_array( $result['body']['web'] ) ? $result['body']['web'] : array();
		$stored = self::store( $web );
		$status = array_merge(
			$status,
			array(
				'ok'                => true,
				'last_success_at'   => time(),
				'error_code'        => '',
				'error_message'     => '',
				'auth_error'        => false,
				'field_set'         => $fields,
				'events_reported'   => count( $web ),
				'metrics_available' => $stored,
			)
		);
		update_option( self::STATUS_OPTION, $status, false );
		return $status;
	}

	/**
	 * Store one row per event × metric for today. Returns the metric names Meta provided.
	 *
	 * @param array<int,mixed> $web Response "web" array.
	 * @return string[]
	 */
	public static function store( array $web ): array {
		global $wpdb;
		$date      = gmdate( 'Y-m-d' );
		$available = array();
		foreach ( $web as $item ) {
			if ( ! is_array( $item ) || empty( $item['event_name'] ) ) {
				continue;
			}
			$event = substr( sanitize_text_field( (string) $item['event_name'] ), 0, 64 );
			foreach ( self::extract( $item ) as $metric => $pair ) {
				$available[ $metric ] = true;
				$value_sql = null === $pair[0] ? 'NULL' : $wpdb->prepare( '%f', $pair[0] );
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->query(
					$wpdb->prepare(
						'INSERT INTO ' . LNKup_Meta_Tables::snapshots() . " (snapshot_date, event_name, metric, value, detail, created_at) VALUES (%s, %s, %s, {$value_sql}, %s, %s) ON DUPLICATE KEY UPDATE value = VALUES(value), detail = VALUES(detail), created_at = VALUES(created_at)",
						$date,
						$event,
						$metric,
						$pair[1],
						gmdate( 'Y-m-d H:i:s' )
					)
				);
				// phpcs:enable
			}
		}
		return array_keys( $available );
	}

	/**
	 * Flatten one event's response into metric => [numeric value|null, detail text].
	 *
	 * @param array<string,mixed> $item Event entry.
	 * @return array<string,array{0:?float,1:string}>
	 */
	public static function extract( array $item ): array {
		$out = array();
		$num = static function ( $value ): ?float {
			return is_numeric( $value ) ? (float) $value : null;
		};
		$emq = isset( $item['event_match_quality'] ) && is_array( $item['event_match_quality'] ) ? $item['event_match_quality'] : null;
		if ( null !== $emq ) {
			if ( isset( $emq['composite_score'] ) ) {
				$out['emq_score'] = array( $num( $emq['composite_score'] ), '' );
			}
			foreach ( (array) ( $emq['match_key_feedback'] ?? array() ) as $key ) {
				if ( is_array( $key ) && ! empty( $key['identifier'] ) && isset( $key['coverage']['percentage'] ) ) {
					$out[ 'key_' . sanitize_key( (string) $key['identifier'] ) ] = array( $num( $key['coverage']['percentage'] ), '' );
				}
			}
			if ( ! empty( $emq['diagnostics'] ) && is_array( $emq['diagnostics'] ) ) {
				$diag = array();
				foreach ( array_slice( $emq['diagnostics'], 0, 10 ) as $d ) {
					if ( is_array( $d ) ) {
						$diag[] = array(
							'name'     => sanitize_text_field( (string) ( $d['name'] ?? '' ) ),
							'solution' => sanitize_text_field( (string) ( $d['solution'] ?? '' ) ),
							'pct'      => $num( $d['percentage'] ?? null ),
							'affected' => (int) ( $d['affected_event_count'] ?? 0 ),
						);
					}
				}
				$out['emq_diagnostics'] = array( (float) count( $diag ), (string) wp_json_encode( $diag ) );
			}
		}
		if ( isset( $item['event_coverage']['percentage'] ) ) {
			$out['event_coverage'] = array( $num( $item['event_coverage']['percentage'] ), '' );
		}
		if ( isset( $item['acr']['percentage'] ) ) {
			$out['acr'] = array( $num( $item['acr']['percentage'] ), sanitize_text_field( (string) ( $item['acr']['description'] ?? '' ) ) );
		}
		foreach ( (array) ( $item['dedupe_key_feedback'] ?? array() ) as $key ) {
			if ( ! is_array( $key ) || empty( $key['dedupe_key'] ) ) {
				continue;
			}
			$name = sanitize_key( (string) $key['dedupe_key'] );
			foreach ( array( 'browser_events_with_dedupe_key' => 'browser', 'server_events_with_dedupe_key' => 'server', 'overall_browser_coverage_from_dedupe_key' => 'overall' ) as $field => $label ) {
				if ( isset( $key[ $field ]['percentage'] ) ) {
					$out[ 'dedupe_' . $name . '_' . $label ] = array( $num( $key[ $field ]['percentage'] ), '' );
				}
			}
		}
		if ( ! empty( $item['data_freshness']['upload_frequency'] ) ) {
			$out['freshness'] = array( null, sanitize_key( (string) $item['data_freshness']['upload_frequency'] ) );
		}
		if ( isset( $item['event_delay'] ) && is_array( $item['event_delay'] ) ) {
			foreach ( array( 'pct_delayed_over_2h' => 'delay_2h_pct', 'pct_delayed_over_24h' => 'delay_24h_pct' ) as $field => $metric ) {
				if ( isset( $item['event_delay'][ $field ] ) ) {
					$out[ $metric ] = array( $num( $item['event_delay'][ $field ] ), '' );
				}
			}
		}
		if ( ! empty( $item['value_diagnostics'] ) && is_array( $item['value_diagnostics'] ) ) {
			$issues = array();
			foreach ( $item['value_diagnostics'] as $issue ) {
				if ( is_array( $issue ) ) {
					$issues[] = array( 'type' => sanitize_key( (string) ( $issue['issue_type'] ?? '' ) ), 'rate' => $num( $issue['issue_rate'] ?? null ), 'count' => (int) ( $issue['issue_count'] ?? 0 ) );
				}
			}
			$out['value_issues'] = array( (float) count( $issues ), (string) wp_json_encode( $issues ) );
		}
		return $out;
	}

	/**
	 * Latest snapshot value per event for a metric.
	 *
	 * @param string $metric Metric.
	 * @return array<string,array{value:?float,date:string,detail:string}>
	 */
	public static function latest( string $metric ): array {
		global $wpdb;
		$table = LNKup_Meta_Tables::snapshots();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT s.event_name, s.value, s.detail, s.snapshot_date FROM {$table} s INNER JOIN (SELECT event_name, MAX(snapshot_date) d FROM {$table} WHERE metric = %s GROUP BY event_name) m ON m.event_name = s.event_name AND m.d = s.snapshot_date WHERE s.metric = %s", $metric, $metric ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['event_name'] ] = array(
				'value'  => null === $row['value'] ? null : (float) $row['value'],
				'date'   => (string) $row['snapshot_date'],
				'detail' => (string) $row['detail'],
			);
		}
		return $out;
	}

	/**
	 * Daily series for one metric over N days (only days with snapshots).
	 *
	 * @param string $metric Metric.
	 * @param int    $days   Days.
	 * @return array<string,array<string,?float>> event => date => value
	 */
	public static function series( string $metric, int $days ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT event_name, snapshot_date, value FROM ' . LNKup_Meta_Tables::snapshots() . ' WHERE metric = %s AND snapshot_date >= %s ORDER BY snapshot_date ASC', $metric, gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS ) ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['event_name'] ][ (string) $row['snapshot_date'] ] = null === $row['value'] ? null : (float) $row['value'];
		}
		return $out;
	}
}

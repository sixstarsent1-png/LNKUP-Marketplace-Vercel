<?php
/**
 * External event vendor demand metrics.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_External_Events_Demand {

	/**
	 * Recompute demand metrics for an external event.
	 *
	 * @param int $external_id External event ID.
	 */
	public static function recalculate( int $external_id ): void {
		global $wpdb;

		$event = LNKup_External_Events::get_row( $external_id );
		if ( ! $event ) {
			return;
		}

		$table = LNKup_External_Events_Tables::interest_table();
		if ( ! LNKup_External_Events_Tables::table_exists( $table ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT vendor_category, booth_type, created_at FROM {$table} WHERE external_event_id = %d ORDER BY created_at DESC",
				$external_id
			),
			ARRAY_A
		);

		$total           = count( $rows );
		$by_category     = array();
		$by_business     = array();
		$last_interest   = '';
		$velocity        = 0;
		$week_ago        = gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) );

		foreach ( $rows as $row ) {
			$cat = trim( (string) ( $row['vendor_category'] ?? '' ) );
			if ( $cat ) {
				$by_category[ $cat ] = ( $by_category[ $cat ] ?? 0 ) + 1;
			}
			$booth = trim( (string) ( $row['booth_type'] ?? '' ) );
			if ( $booth ) {
				$by_business[ $booth ] = ( $by_business[ $booth ] ?? 0 ) + 1;
			}
			if ( ! $last_interest && ! empty( $row['created_at'] ) ) {
				$last_interest = (string) $row['created_at'];
			}
			if ( ! empty( $row['created_at'] ) && $row['created_at'] >= $week_ago ) {
				++$velocity;
			}
		}

		arsort( $by_category );
		$top_categories = array();
		foreach ( array_slice( $by_category, 0, 5, true ) as $name => $count ) {
			$top_categories[] = array(
				'label' => $name,
				'count' => $count,
			);
		}

		$trend = 'stable';
		if ( $velocity >= 3 ) {
			$trend = 'rising';
		} elseif ( $total > 0 && $velocity === 0 ) {
			$trend = 'cooling';
		}

		$by_city = array();
		if ( ! empty( $event['city'] ) ) {
			$by_city[ $event['city'] ] = $total;
		}

		$metrics = array(
			'vendor_interest_total'        => $total,
			'vendor_interest_by_category'  => $by_category,
			'vendor_interest_by_business_type' => $by_business,
			'vendor_interest_by_city'      => $by_city,
			'top_requested_categories'     => $top_categories,
		);

		$events_table = LNKup_External_Events_Tables::events_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$events_table,
			array(
				'vendor_interest_count'           => $total,
				'demand_metrics_json'             => wp_json_encode( $metrics ),
				'last_interest_at'                => $last_interest ?: null,
				'interest_velocity'               => $velocity,
				'interest_trend'                  => $trend,
				'top_requested_categories_json'   => wp_json_encode( $top_categories ),
				'updated_at'                      => current_time( 'mysql' ),
			),
			array( 'id' => $external_id )
		);

		if ( class_exists( 'LNKup_External_Events_Demand_Score' ) ) {
			LNKup_External_Events_Demand_Score::recalculate( $external_id );
		}
	}

	/**
	 * Public-safe demand DTO from event row.
	 *
	 * @param array<string, mixed> $row Event row.
	 * @return array<string, mixed>
	 */
	public static function public_dto( array $row ): array {
		$metrics = array();
		if ( ! empty( $row['demand_metrics_json'] ) ) {
			$decoded = json_decode( (string) $row['demand_metrics_json'], true );
			if ( is_array( $decoded ) ) {
				$metrics = $decoded;
			}
		}

		$total = (int) ( $metrics['vendor_interest_total'] ?? $row['vendor_interest_count'] ?? 0 );
		$breakdown = array();
		$by_cat = $metrics['vendor_interest_by_category'] ?? array();
		if ( is_array( $by_cat ) ) {
			foreach ( array_slice( $by_cat, 0, 4, true ) as $label => $count ) {
				$breakdown[] = array(
					'label' => (string) $label,
					'count' => (int) $count,
				);
			}
		}

		$recent = '';
		if ( ! empty( $row['last_interest_at'] ) ) {
			$ts = strtotime( (string) $row['last_interest_at'] );
			if ( $ts && $ts >= strtotime( '-1 day' ) ) {
				$recent = lnkup_copy( 'vendor_interest_recent_activity' );
			}
		}

		return array(
			'total'              => $total,
			'breakdown'          => $breakdown,
			'recent_activity'    => $recent,
			'interest_velocity'  => (int) ( $row['interest_velocity'] ?? 0 ),
			'interest_trend'     => (string) ( $row['interest_trend'] ?? 'stable' ),
			'demand_score'       => (int) ( $row['demand_score'] ?? 0 ),
		);
	}
}

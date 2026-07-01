<?php
/**
 * Organizer opportunity demand score.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_External_Events_Demand_Score {

	/**
	 * Recalculate weighted demand score (0-100).
	 *
	 * @param int $external_id External event ID.
	 */
	public static function recalculate( int $external_id ): void {
		global $wpdb;

		$event = LNKup_External_Events::get_row( $external_id );
		if ( ! $event ) {
			return;
		}

		$metrics = array();
		if ( ! empty( $event['demand_metrics_json'] ) ) {
			$decoded = json_decode( (string) $event['demand_metrics_json'], true );
			if ( is_array( $decoded ) ) {
				$metrics = $decoded;
			}
		}

		$total      = (int) ( $metrics['vendor_interest_total'] ?? $event['vendor_interest_count'] ?? 0 );
		$categories = is_array( $metrics['vendor_interest_by_category'] ?? null ) ? count( $metrics['vendor_interest_by_category'] ) : 0;
		$velocity   = (int) ( $event['interest_velocity'] ?? 0 );
		$trend      = (string) ( $event['interest_trend'] ?? 'stable' );

		$unique_vendors = $total;
		$quality_bonus  = self::vendor_quality_bonus( $external_id );
		$city_bonus     = self::city_demand_bonus( (string) ( $event['city'] ?? '' ) );

		$score = 0;
		$score += min( 35, $total * 4 );
		$score += min( 15, $unique_vendors * 2 );
		$score += min( 15, $categories * 5 );
		$score += min( 15, $velocity * 3 );
		$score += $quality_bonus;
		$score += $city_bonus;
		if ( 'rising' === $trend ) {
			$score += 5;
		}

		$score = min( 100, max( 0, (int) round( $score ) ) );

		$table = LNKup_External_Events_Tables::events_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array(
				'demand_score' => $score,
				'updated_at'   => current_time( 'mysql' ),
			),
			array( 'id' => $external_id )
		);
	}

	/**
	 * @param int $external_id External event ID.
	 */
	protected static function vendor_quality_bonus( int $external_id ): int {
		global $wpdb;
		$table = LNKup_External_Events_Tables::interest_table();
		if ( ! LNKup_External_Events_Tables::table_exists( $table ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$profile_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT vendor_profile_id FROM {$table} WHERE external_event_id = %d AND vendor_profile_id > 0 LIMIT 20",
				$external_id
			)
		);
		if ( empty( $profile_ids ) || ! class_exists( 'LNKup_Reputation_Score' ) ) {
			return 0;
		}
		$sum = 0;
		$n   = 0;
		foreach ( $profile_ids as $profile_id ) {
			$score = LNKup_Reputation_Score::calculate( (int) $profile_id );
			if ( isset( $score['score'] ) ) {
				$sum += (int) $score['score'];
				++$n;
			}
		}
		if ( ! $n ) {
			return 0;
		}
		return min( 10, (int) round( ( $sum / $n ) / 10 ) );
	}

	/**
	 * @param string $city City name.
	 */
	protected static function city_demand_bonus( string $city ): int {
		if ( '' === $city ) {
			return 0;
		}
		global $wpdb;
		$table = LNKup_External_Events_Tables::events_table();
		if ( ! LNKup_External_Events_Tables::table_exists( $table ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$city_total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(vendor_interest_count) FROM {$table} WHERE city = %s AND workflow_status = %s",
				$city,
				LNKup_External_Events::WORKFLOW_PUBLISHED
			)
		);
		return min( 5, (int) floor( $city_total / 5 ) );
	}

	/**
	 * Human label for score.
	 *
	 * @param int $score Score 0-100.
	 */
	public static function label( int $score ): string {
		if ( $score >= 75 ) {
			return lnkup_copy( 'demand_score_high' );
		}
		if ( $score >= 50 ) {
			return lnkup_copy( 'external_event_high_demand' );
		}
		return lnkup_copy( 'demand_score' );
	}

	/**
	 * Derive card badges from event row.
	 *
	 * @param array<string, mixed> $row Event row.
	 * @return array<int, string>
	 */
	public static function badges_for_row( array $row ): array {
		$badges = array();
		$score  = (int) ( $row['demand_score'] ?? 0 );
		if ( $score >= 75 ) {
			$badges[] = 'high_demand';
		}
		if ( 'rising' === ( $row['interest_trend'] ?? '' ) && (int) ( $row['interest_velocity'] ?? 0 ) >= 2 ) {
			$badges[] = 'trending';
		}
		if ( ! empty( $row['last_interest_at'] ) && strtotime( (string) $row['last_interest_at'] ) >= strtotime( '-3 days' ) ) {
			$badges[] = 'recently_requested';
		}
		return $badges;
	}
}

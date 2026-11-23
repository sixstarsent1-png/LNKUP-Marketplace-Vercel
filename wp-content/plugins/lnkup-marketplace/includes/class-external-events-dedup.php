<?php
/**
 * External event duplicate detection.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_External_Events_Dedup {

	const THRESHOLD = 70;

	/**
	 * Scan for duplicates and store suggestions.
	 *
	 * @param int $external_id Event ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function scan( int $external_id ): array {
		global $wpdb;

		$event = LNKup_External_Events::get_row( $external_id );
		if ( ! $event ) {
			return array();
		}

		$table = LNKup_External_Events_Tables::events_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$candidates = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id != %d AND duplicate_of_id = 0 ORDER BY id DESC LIMIT 200",
				$external_id
			),
			ARRAY_A
		);

		$suggestions = array();
		foreach ( (array) $candidates as $candidate ) {
			$confidence = self::confidence( $event, $candidate );
			if ( $confidence < self::THRESHOLD ) {
				continue;
			}
			self::store_pair( $external_id, (int) $candidate['id'], $confidence );
			$suggestions[] = array(
				'id'         => (int) $candidate['id'],
				'title'      => $candidate['title'] ?? '',
				'confidence' => $confidence,
			);
		}

		$events_table = LNKup_External_Events_Tables::events_table();
		if ( ! empty( $suggestions ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$events_table,
				array( 'duplicate_confidence' => (int) $suggestions[0]['confidence'] ),
				array( 'id' => $external_id )
			);
		}

		return $suggestions;
	}

	/**
	 * @param array<string, mixed> $a Event A.
	 * @param array<string, mixed> $b Event B.
	 */
	public static function confidence( array $a, array $b ): int {
		if ( ! empty( $a['source_url'] ) && ! empty( $b['source_url'] ) && $a['source_url'] === $b['source_url'] ) {
			return 100;
		}
		if ( ! empty( $a['source_event_id'] ) && $a['source_event_id'] === ( $b['source_event_id'] ?? '' )
			&& ( $a['source_platform'] ?? '' ) === ( $b['source_platform'] ?? '' ) ) {
			return 95;
		}

		$score = 0;
		if ( self::similar_text( (string) ( $a['title'] ?? '' ), (string) ( $b['title'] ?? '' ) ) >= 0.85 ) {
			$score += 35;
		}
		if ( ! empty( $a['venue_name'] ) && strtolower( $a['venue_name'] ) === strtolower( (string) ( $b['venue_name'] ?? '' ) ) ) {
			$score += 20;
		}
		if ( ! empty( $a['start_datetime'] ) && $a['start_datetime'] === ( $b['start_datetime'] ?? '' ) ) {
			$score += 20;
		}
		if ( ! empty( $a['city'] ) && strtolower( $a['city'] ) === strtolower( (string) ( $b['city'] ?? '' ) ) ) {
			$score += 10;
		}
		if ( ! empty( $a['organizer_name'] ) && strtolower( $a['organizer_name'] ) === strtolower( (string) ( $b['organizer_name'] ?? '' ) ) ) {
			$score += 15;
		}
		return min( 99, $score );
	}

	/**
	 * Merge duplicate into target event.
	 *
	 * @param int $source_id Source event to merge from.
	 * @param int $target_id Target canonical event.
	 * @return true|WP_Error
	 */
	public static function merge( int $source_id, int $target_id ) {
		global $wpdb;

		if ( $source_id === $target_id ) {
			return new WP_Error( 'invalid_merge', __( 'Cannot merge an event into itself.', 'lnkup-marketplace' ) );
		}

		$source = LNKup_External_Events::get_row( $source_id );
		$target = LNKup_External_Events::get_row( $target_id );
		if ( ! $source || ! $target ) {
			return new WP_Error( 'not_found', __( 'Event not found.', 'lnkup-marketplace' ) );
		}

		$interest_table = LNKup_External_Events_Tables::interest_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$interest_table} SET external_event_id = %d WHERE external_event_id = %d",
				$target_id,
				$source_id
			)
		);

		$events_table = LNKup_External_Events_Tables::events_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$events_table,
			array(
				'workflow_status'  => LNKup_External_Events::WORKFLOW_HIDDEN,
				'claim_status'     => LNKup_External_Events::CLAIM_HIDDEN,
				'duplicate_of_id'  => $target_id,
				'updated_at'       => current_time( 'mysql' ),
			),
			array( 'id' => $source_id )
		);

		LNKup_External_Events_Demand::recalculate( $target_id );
		LNKup_External_Events_Timeline::log( $target_id, 'merged', get_current_user_id(), array( 'merged_id' => $source_id ) );

		return true;
	}

	/**
	 * @param int $event_a Event A ID.
	 * @param int $event_b Event B ID.
	 * @param int $confidence Confidence score.
	 */
	protected static function store_pair( int $event_a, int $event_b, int $confidence ): void {
		global $wpdb;
		$table = LNKup_External_Events_Tables::duplicates_table();
		if ( ! LNKup_External_Events_Tables::table_exists( $table ) ) {
			return;
		}
		$a = min( $event_a, $event_b );
		$b = max( $event_a, $event_b );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE event_a_id = %d AND event_b_id = %d LIMIT 1",
				$a,
				$b
			)
		);
		$now = current_time( 'mysql' );
		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array( 'confidence' => $confidence, 'updated_at' => $now ),
				array( 'id' => (int) $existing )
			);
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'event_a_id'  => $a,
				'event_b_id'  => $b,
				'confidence'  => $confidence,
				'status'      => 'suggested',
				'created_at'  => $now,
				'updated_at'  => $now,
			)
		);
	}

	/**
	 * Likely duplicates of a LNKup event among other LNKup events (same date window).
	 *
	 * @param int $event_id LNKup event ID.
	 * @return array<int, array{event_id: int, confidence: int}>
	 */
	public static function lnk_event_candidates( int $event_id ): array {
		$base = self::lnk_event_shape( $event_id );
		if ( '' === $base['start_datetime'] ) {
			return array();
		}
		$ids = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'post__not_in'   => array( $event_id ),
				'meta_key'       => '_lnkup_event_date', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $base['start_datetime'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		$out = array();
		foreach ( $ids as $other_id ) {
			$score = self::confidence( $base, self::lnk_event_shape( (int) $other_id ) );
			if ( $score >= self::THRESHOLD ) {
				$out[] = array( 'event_id' => (int) $other_id, 'confidence' => $score );
			}
		}
		usort(
			$out,
			static function ( $x, $y ) {
				return $y['confidence'] <=> $x['confidence'];
			}
		);
		return $out;
	}

	/**
	 * LNKup event in the shape confidence() compares.
	 *
	 * @param int $event_id Event ID.
	 * @return array<string, string>
	 */
	private static function lnk_event_shape( int $event_id ): array {
		$source = (string) get_post_meta( $event_id, '_lnkup_external_source_url', true );
		return array(
			'title'          => (string) lnkup_plain_title( $event_id ),
			'venue_name'     => (string) get_post_meta( $event_id, '_lnkup_venue_name', true ),
			'start_datetime' => (string) get_post_meta( $event_id, '_lnkup_event_date', true ),
			'city'           => (string) get_post_meta( $event_id, '_lnkup_city', true ),
			'source_url'     => $source ? untrailingslashit( strtolower( $source ) ) : '',
		);
	}

	/**
	 * @param string $a String A.
	 * @param string $b String B.
	 */
	protected static function similar_text( string $a, string $b ): float {
		$a = strtolower( trim( $a ) );
		$b = strtolower( trim( $b ) );
		if ( '' === $a || '' === $b ) {
			return 0.0;
		}
		similar_text( $a, $b, $pct );
		return $pct / 100;
	}
}

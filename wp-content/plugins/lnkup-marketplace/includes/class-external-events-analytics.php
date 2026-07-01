<?php
/**
 * External events admin analytics aggregates.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_External_Events_Analytics {

	/**
	 * Dashboard KPI payload.
	 *
	 * @return array<string, mixed>
	 */
	public static function dashboard(): array {
		global $wpdb;
		$table = LNKup_External_Events_Tables::events_table();
		if ( ! LNKup_External_Events_Tables::table_exists( $table ) ) {
			return array( 'kpis' => array(), 'top_cities' => array(), 'top_categories' => array(), 'recent_claims' => array() );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$published = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE workflow_status = %s", LNKup_External_Events::WORKFLOW_PUBLISHED )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$claimed = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE claim_status = %s", LNKup_External_Events::CLAIM_CLAIMED )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$interest_sum = (int) $wpdb->get_var( "SELECT COALESCE(SUM(vendor_interest_count),0) FROM {$table}" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$emails_sent = (int) $wpdb->get_var( "SELECT COALESCE(SUM(emails_sent),0) FROM {$table}" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$pending = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE workflow_status = %s", LNKup_External_Events::WORKFLOW_PENDING_REVIEW )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$missing_email = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE workflow_status = %s AND (organizer_email = '' OR organizer_email IS NULL)",
				LNKup_External_Events::WORKFLOW_PUBLISHED
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$pending_notify = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				WHERE workflow_status = %s AND claim_status = %s
				AND vendor_interest_count > 0
				AND (organizer_email = '' OR organizer_email IS NULL OR followup_stage = 0)",
				LNKup_External_Events::WORKFLOW_PUBLISHED,
				LNKup_External_Events::CLAIM_UNCLAIMED
			)
		);

		$claim_rate = $published > 0 ? round( ( $claimed / $published ) * 100, 1 ) : 0;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$top_cities = $wpdb->get_results(
			"SELECT city, SUM(vendor_interest_count) AS interests, COUNT(*) AS events
			FROM {$table} WHERE city != '' GROUP BY city ORDER BY interests DESC LIMIT 8",
			ARRAY_A
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$recent_claims = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, title, city, claimed_event_id, updated_at FROM {$table}
				WHERE claim_status = %s ORDER BY updated_at DESC LIMIT 10",
				LNKup_External_Events::CLAIM_CLAIMED
			),
			ARRAY_A
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$top_events = $wpdb->get_results(
			"SELECT id, title, vendor_interest_count, demand_score, city FROM {$table}
			WHERE workflow_status = 'published' ORDER BY vendor_interest_count DESC LIMIT 10",
			ARRAY_A
		);

		return array(
			'kpis' => array(
				'external_events'        => $total,
				'published'              => $published,
				'pending_review'         => $pending,
				'missing_organizer_email'=> $missing_email,
				'pending_organizer_notify'=> $pending_notify,
				'claim_rate'             => $claim_rate,
				'vendor_interest'        => $interest_sum,
				'emails_sent'            => $emails_sent,
				'events_converted'       => $claimed,
			),
			'top_cities'    => $top_cities ?: array(),
			'most_requested'=> $top_events ?: array(),
			'recent_claims' => $recent_claims ?: array(),
		);
	}

	/**
	 * @param int $external_id External event ID.
	 * @return array<string, mixed>
	 */
	public static function for_event( int $external_id ): array {
		$row = LNKup_External_Events::get_row( $external_id );
		if ( ! $row ) {
			return array();
		}
		return array(
			'event'     => array(
				'id'                    => (int) $row['id'],
				'title'                 => $row['title'] ?? '',
				'vendor_interest_count' => (int) ( $row['vendor_interest_count'] ?? 0 ),
				'demand_score'          => (int) ( $row['demand_score'] ?? 0 ),
				'emails_sent'           => (int) ( $row['emails_sent'] ?? 0 ),
				'emails_opened'         => (int) ( $row['emails_opened'] ?? 0 ),
				'emails_clicked'        => (int) ( $row['emails_clicked'] ?? 0 ),
				'followup_stage'        => (int) ( $row['followup_stage'] ?? 0 ),
			),
			'demand'    => LNKup_External_Events_Demand::public_dto( $row ),
			'timeline'  => LNKup_External_Events_Timeline::get_for_event( $external_id, 30 ),
		);
	}

	/**
	 * Public aggregated demand stats.
	 *
	 * @return array<string, mixed>
	 */
	public static function public_demand_stats(): array {
		global $wpdb;
		$table = LNKup_External_Events_Tables::events_table();
		if ( ! LNKup_External_Events_Tables::table_exists( $table ) ) {
			return array( 'cities' => array() );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$cities = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT city, SUM(vendor_interest_count) AS total FROM {$table}
				WHERE workflow_status = %s AND city != '' GROUP BY city ORDER BY total DESC LIMIT 10",
				LNKup_External_Events::WORKFLOW_PUBLISHED
			),
			ARRAY_A
		);
		return array( 'cities' => $cities ?: array() );
	}
}

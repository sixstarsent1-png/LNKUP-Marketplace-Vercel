<?php
/**
 * External event activity timeline.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_External_Events_Timeline {

	/**
	 * Activity type labels.
	 *
	 * @return array<string, string>
	 */
	public static function type_labels(): array {
		return array(
			'imported'           => __( 'Imported', 'lnkup-marketplace' ),
			'published'          => __( 'Published', 'lnkup-marketplace' ),
			'rejected'           => __( 'Rejected', 'lnkup-marketplace' ),
			'hidden'             => __( 'Hidden', 'lnkup-marketplace' ),
			'vendor_interest'    => __( 'Vendor interest', 'lnkup-marketplace' ),
			'organizer_notified' => __( 'Organizer notified', 'lnkup-marketplace' ),
			'interest_no_organizer_email' => __( 'Interest — no organizer email', 'lnkup-marketplace' ),
			'admin_missing_email_notified' => __( 'Admin alerted — missing email', 'lnkup-marketplace' ),
			'organizer_email_extracted' => __( 'Organizer email extracted', 'lnkup-marketplace' ),
			'organizer_notified_deferred' => __( 'Organizer notified (deferred)', 'lnkup-marketplace' ),
			'email_sent'         => __( 'Reminder sent', 'lnkup-marketplace' ),
			'email_opened'       => __( 'Email opened', 'lnkup-marketplace' ),
			'email_clicked'      => __( 'Email clicked', 'lnkup-marketplace' ),
			'claim_started'      => __( 'Claim started', 'lnkup-marketplace' ),
			'claim_completed'    => __( 'Claim completed', 'lnkup-marketplace' ),
			'converted'          => __( 'Converted to LNK event', 'lnkup-marketplace' ),
			'merged'             => __( 'Merged duplicate', 'lnkup-marketplace' ),
			'needs_update'       => __( 'Needs update', 'lnkup-marketplace' ),
			'refetched'          => __( 'Re-fetched from source', 'lnkup-marketplace' ),
			'sync_discovered'    => __( 'Auto-sync imported', 'lnkup-marketplace' ),
			'sync_refreshed'     => __( 'Auto-sync refreshed', 'lnkup-marketplace' ),
			'sync_failed'          => __( 'Auto-sync failed', 'lnkup-marketplace' ),
		);
	}

	/**
	 * Log timeline activity.
	 *
	 * @param int                  $external_id External event ID.
	 * @param string               $type        Activity type.
	 * @param int                  $actor_id    Actor user ID.
	 * @param array<string, mixed> $payload     Optional payload.
	 */
	public static function log( int $external_id, string $type, int $actor_id = 0, array $payload = array() ): void {
		global $wpdb;
		$table = LNKup_External_Events_Tables::activity_table();
		if ( ! LNKup_External_Events_Tables::table_exists( $table ) || $external_id <= 0 ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'external_event_id' => $external_id,
				'activity_type'     => sanitize_key( $type ),
				'actor_user_id'     => $actor_id,
				'payload_json'      => wp_json_encode( $payload ),
				'created_at'        => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * @param int $external_id External event ID.
	 * @param int $limit       Limit.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_for_event( int $external_id, int $limit = 50 ): array {
		global $wpdb;
		$table  = LNKup_External_Events_Tables::activity_table();
		$labels = self::type_labels();
		if ( ! LNKup_External_Events_Tables::table_exists( $table ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE external_event_id = %d ORDER BY created_at DESC LIMIT %d",
				$external_id,
				$limit
			),
			ARRAY_A
		);
		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = array(
				'id'         => (int) $row['id'],
				'type'       => $row['activity_type'],
				'label'      => $labels[ $row['activity_type'] ] ?? $row['activity_type'],
				'payload'    => json_decode( (string) ( $row['payload_json'] ?? '{}' ), true ) ?: array(),
				'created_at' => $row['created_at'],
			);
		}
		return $items;
	}
}

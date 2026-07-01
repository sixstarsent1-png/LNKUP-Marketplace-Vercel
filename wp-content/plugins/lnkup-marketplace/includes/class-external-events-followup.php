<?php
/**
 * Organizer email follow-up sequences.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_External_Events_Followup {

	const THRESHOLDS = array( 1, 3, 5, 10 );

	/**
	 * Queue follow-up check after new interest.
	 *
	 * @param int $external_id External event ID.
	 */
	public static function maybe_queue( int $external_id ): void {
		if ( ! self::rate_limit_ok( $external_id ) ) {
			return;
		}
		$stage = self::stage_for_count( $external_id );
		if ( $stage <= 0 ) {
			return;
		}
		self::send_stage( $external_id, $stage );
	}

	/**
	 * Process pending follow-ups (cron).
	 */
	public static function process_queue(): void {
		global $wpdb;
		$table = LNKup_External_Events_Tables::events_table();
		if ( ! LNKup_External_Events_Tables::table_exists( $table ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id FROM {$table}
				WHERE workflow_status = %s AND claim_status = %s
				AND vendor_interest_count > 0
				AND organizer_email != ''
				ORDER BY last_interest_at DESC LIMIT 20",
				LNKup_External_Events::WORKFLOW_PUBLISHED,
				LNKup_External_Events::CLAIM_UNCLAIMED
			),
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			if ( ! self::rate_limit_ok( (int) $row['id'] ) ) {
				continue;
			}
			$stage = self::stage_for_count( (int) $row['id'] );
			if ( $stage > 0 ) {
				self::send_stage( (int) $row['id'], $stage );
			}
		}
	}

	/**
	 * @param int $external_id External event ID.
	 */
	protected static function stage_for_count( int $external_id ): int {
		$event = LNKup_External_Events::get_row( $external_id );
		if ( ! $event || LNKup_External_Events::CLAIM_CLAIMED === $event['claim_status'] ) {
			return 0;
		}
		$count  = (int) ( $event['vendor_interest_count'] ?? 0 );
		$sent   = (int) ( $event['followup_stage'] ?? 0 );
		$target = 0;
		foreach ( self::THRESHOLDS as $i => $threshold ) {
			if ( $count >= $threshold ) {
				$target = $i + 1;
			}
		}
		if ( $target <= $sent ) {
			return 0;
		}
		return $target;
	}

	/**
	 * @param int $external_id External event ID.
	 * @param int $stage       Stage 1-4.
	 */
	public static function send_stage( int $external_id, int $stage ): bool {
		global $wpdb;

		$event = LNKup_External_Events::get_row( $external_id );
		if ( ! $event || ! is_email( $event['organizer_email'] ?? '' ) ) {
			return false;
		}

		$token = LNKup_External_Events::generate_claim_token( $external_id, (string) $event['organizer_email'] );
		if ( is_wp_error( $token ) ) {
			return false;
		}

		$claim_url = LNKup_External_Events::build_claim_landing_url( (string) $token );
		$metrics   = LNKup_External_Events_Demand::public_dto( $event );
		$sent      = LNKup_Notifications::send_external_event_followup( $event, $stage, $claim_url, $metrics );
		if ( ! $sent ) {
			return false;
		}

		$now          = current_time( 'mysql' );
		$events_table = LNKup_External_Events_Tables::events_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$events_table,
			array(
				'followup_stage'     => $stage,
				'emails_sent'        => (int) ( $event['emails_sent'] ?? 0 ) + 1,
				'last_email_sent_at' => $now,
				'updated_at'         => $now,
			),
			array( 'id' => $external_id )
		);

		$emails_table = LNKup_External_Events_Tables::emails_table();
		$subject_key  = 'external_followup_subject_' . $stage;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$emails_table,
			array(
				'external_event_id' => $external_id,
				'stage'             => $stage,
				'subject'           => lnkup_copy( $subject_key ),
				'token_hash'        => wp_hash( (string) $token ),
				'sent_at'           => $now,
				'created_at'        => $now,
			)
		);

		LNKup_External_Events_Timeline::log( $external_id, 'email_sent', 0, array( 'stage' => $stage ) );
		LNKup_External_Events::track( 'external_event_organizer_notified', 0, $external_id, array( 'stage' => $stage ) );

		return true;
	}

	/**
	 * @param int $external_id External event ID.
	 */
	protected static function rate_limit_ok( int $external_id ): bool {
		$settings = lnkup_get_settings();
		if ( empty( $settings['external_followup_rate_limit_enabled'] ) ) {
			return true;
		}
		$event = LNKup_External_Events::get_row( $external_id );
		if ( ! $event || empty( $event['last_email_sent_at'] ) ) {
			return true;
		}
		$min_hours = max( 1, (int) ( $settings['external_followup_min_hours'] ?? 24 ) );
		$last      = strtotime( (string) $event['last_email_sent_at'] );
		return $last <= ( time() - ( $min_hours * HOUR_IN_SECONDS ) );
	}

	/**
	 * Track email open/click.
	 *
	 * @param string $token Claim token.
	 * @param string $type  open|click.
	 */
	public static function track_email( string $token, string $type ): void {
		global $wpdb;
		$row = LNKup_External_Events::get_token_row( $token );
		if ( ! $row ) {
			return;
		}
		$external_id = (int) $row['external_event_id'];
		$now         = current_time( 'mysql' );
		$events_table = LNKup_External_Events_Tables::events_table();
		$field        = 'click' === $type ? 'emails_clicked' : 'emails_opened';
		$event        = LNKup_External_Events::get_row( $external_id );
		if ( ! $event ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$events_table,
			array( $field => (int) ( $event[ $field ] ?? 0 ) + 1 ),
			array( 'id' => $external_id )
		);
		$emails_table = LNKup_External_Events_Tables::emails_table();
		$col          = 'click' === $type ? 'clicked_at' : 'opened_at';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$emails_table} SET {$col} = %s WHERE external_event_id = %d AND {$col} IS NULL ORDER BY id DESC LIMIT 1",
				$now,
				$external_id
			)
		);
		LNKup_External_Events_Timeline::log( $external_id, 'email_' . $type . 'ed', 0 );
	}
}

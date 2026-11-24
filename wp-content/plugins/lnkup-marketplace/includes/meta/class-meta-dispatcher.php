<?php
/**
 * Background Conversions API dispatcher.
 *
 * The outbox table is the source of truth; Action Scheduler (via LNKup_Comms_Queue when the
 * communications plugin is active) or WP-Cron only wakes the worker, and a 5-minute sweep
 * recovers anything a lost job left behind. Rows are claimed atomically, so concurrent
 * workers never send the same event twice. Retries keep the original event_id and event_time.
 *
 * States: pending → sending → sent | test | pending (retry) | failed (dead letter) | expired.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Meta_Dispatcher {

	const HOOK        = 'lnkup_meta_capi_dispatch';
	const SWEEP_HOOK  = 'lnkup_meta_capi_sweep';
	const PURGE_HOOK  = 'lnkup_meta_purge';
	const BATCH       = 50;
	const MAX_BATCHES = 6;
	const MAX_ATTEMPTS = 6;
	/** Meta rejects website events older than 7 days; stop an hour early. */
	const MAX_AGE     = 7 * DAY_IN_SECONDS - HOUR_IN_SECONDS;
	const STALE_CLAIM = 10 * MINUTE_IN_SECONDS;
	const BACKOFF     = array( 60, 300, 1800, 7200, 43200 );
	const RETENTION_DAYS = 180;

	public static function init(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( self::SWEEP_HOOK, array( __CLASS__, 'sweep' ) );
		add_action( self::PURGE_HOOK, array( __CLASS__, 'purge' ) );
	}

	/**
	 * Seconds to wait before retry N (1-based).
	 *
	 * @param int  $attempt   Attempts made so far.
	 * @param bool $throttled Rate limited by Meta.
	 * @param int  $retry_after Server hint in seconds.
	 */
	public static function backoff( int $attempt, bool $throttled = false, int $retry_after = 0 ): int {
		$index = max( 0, min( count( self::BACKOFF ) - 1, $attempt - 1 ) );
		$delay = self::BACKOFF[ $index ];
		if ( $throttled ) {
			$delay = max( $delay, $retry_after, 900 );
		}
		return $delay;
	}

	/**
	 * Wake the worker soon (deduplicated).
	 */
	public static function schedule(): void {
		if ( class_exists( 'LNKup_Comms_Queue' ) && LNKup_Comms_Queue::ready() ) {
			LNKup_Comms_Queue::job( self::HOOK );
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time(), self::HOOK );
		}
	}

	private static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	public static function sweep(): void {
		global $wpdb;
		$table = LNKup_Meta_Tables::conversions();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$due = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = 'pending' AND (next_attempt_at IS NULL OR next_attempt_at <= %s)", self::now() ) );
		if ( $due > 0 ) {
			self::run();
		} else {
			self::expire_and_release();
		}
	}

	/**
	 * Expire events Meta would reject and release claims from workers that died.
	 */
	private static function expire_and_release(): void {
		global $wpdb;
		$table = LNKup_Meta_Tables::conversions();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'expired', request_context = NULL, last_error_code = 'event_too_old' WHERE status IN ('pending','sending') AND event_time < %d", time() - self::MAX_AGE ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'pending', claim_token = '' WHERE status = 'sending' AND claimed_at < %s", gmdate( 'Y-m-d H:i:s', time() - self::STALE_CLAIM ) ) );
		// phpcs:enable
	}

	/**
	 * Claim up to one batch. Solo rows (from a rejected batch) are sent one at a time.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function claim(): array {
		global $wpdb;
		$table = LNKup_Meta_Tables::conversions();
		$token = wp_generate_password( 24, false, false );
		$now   = self::now();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$claimed = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'sending', claim_token = %s, claimed_at = %s WHERE status = 'pending' AND solo = 1 AND (next_attempt_at IS NULL OR next_attempt_at <= %s) ORDER BY id ASC LIMIT 1", $token, $now, $now ) );
		if ( 0 === $claimed ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'sending', claim_token = %s, claimed_at = %s WHERE status = 'pending' AND solo = 0 AND (next_attempt_at IS NULL OR next_attempt_at <= %s) ORDER BY id ASC LIMIT %d", $token, $now, $now, self::BATCH ) );
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE claim_token = %s AND status = 'sending'", $token ), ARRAY_A );
		// phpcs:enable
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Build one server event from an outbox row.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	public static function build_event( array $row ): array {
		$context = json_decode( (string) ( $row['request_context'] ?? '' ), true );
		$custom  = json_decode( (string) ( $row['custom_data'] ?? '' ), true );
		$event   = array(
			'event_name'       => (string) $row['event_name'],
			'event_time'       => (int) $row['event_time'],
			'event_id'         => (string) $row['event_id'],
			'action_source'    => 'website',
			'event_source_url' => '' !== (string) $row['event_source_url'] ? (string) $row['event_source_url'] : home_url( '/' ),
			'user_data'        => LNKup_Meta_User_Data::build( (int) $row['user_id'], is_array( $context ) ? $context : array() ),
		);
		if ( is_array( $custom ) && array() !== $custom ) {
			$event['custom_data'] = $custom;
		}
		$dpo = LNKup_Meta_Config::data_processing_options();
		if ( null !== $dpo ) {
			$event['data_processing_options']         = $dpo['options'];
			$event['data_processing_options_country'] = $dpo['country'];
			$event['data_processing_options_state']   = $dpo['state'];
		}
		return $event;
	}

	/**
	 * Send due events in batches.
	 */
	public static function run(): void {
		if ( '' !== LNKup_Meta_Config::capi_block_reason() ) {
			return;
		}
		$lock = 'lnkup_meta_dispatch_lock';
		if ( get_transient( $lock ) ) {
			return;
		}
		set_transient( $lock, 1, 2 * MINUTE_IN_SECONDS );
		try {
			self::expire_and_release();
			$started = time();
			for ( $i = 0; $i < self::MAX_BATCHES && time() - $started < 25; $i++ ) {
				$rows = self::claim();
				if ( array() === $rows ) {
					break;
				}
				self::send_rows( $rows );
			}
		} finally {
			delete_transient( $lock );
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$more = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Meta_Tables::conversions() . " WHERE status = 'pending' AND (next_attempt_at IS NULL OR next_attempt_at <= %s)", self::now() ) );
		if ( $more > 0 ) {
			self::schedule();
		}
	}

	/**
	 * @param array<int,array<string,mixed>> $rows Claimed rows.
	 */
	private static function send_rows( array $rows ): void {
		global $wpdb;
		$table  = LNKup_Meta_Tables::conversions();
		$events = array();
		$skip   = array();
		foreach ( $rows as $row ) {
			$event = self::build_event( $row );
			// Website events require the browser user agent; without it Meta rejects the whole batch.
			if ( empty( $event['user_data']['client_user_agent'] ) ) {
				$skip[] = (int) $row['id'];
				continue;
			}
			$events[ (int) $row['id'] ] = $event;
		}
		if ( array() !== $skip ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "UPDATE {$table} SET status = 'skipped', last_error_code = 'missing_user_agent', request_context = NULL, claim_token = '' WHERE id IN (" . implode( ',', array_map( 'intval', $skip ) ) . ')' );
		}
		if ( array() === $events ) {
			return;
		}

		$test_mode = LNKup_Meta_Config::test_mode();
		$started   = microtime( true );
		$result    = LNKup_Meta_Capi_Client::send_events( array_values( $events ), $test_mode ? LNKup_Meta_Config::test_event_code() : '' );
		$ids       = implode( ',', array_map( 'intval', array_keys( $events ) ) );
		$now       = self::now();

		self::log_delivery( 'capi', count( $events ), $result, $test_mode, (int) round( ( microtime( true ) - $started ) * 1000 ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		if ( ! empty( $result['ok'] ) ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s, sent_at = %s, attempts = attempts + 1, request_context = NULL, claim_token = '', last_error_code = '', last_error = '' WHERE id IN ({$ids})", $test_mode ? 'test' : 'sent', $now ) );
			update_option( 'lnkup_meta_last_delivery', array( 'at' => time(), 'ok' => true, 'test' => $test_mode ), false );
			return;
		}

		update_option( 'lnkup_meta_last_delivery', array( 'at' => time(), 'ok' => false, 'code' => (string) $result['error_code'], 'auth' => ! empty( $result['auth_error'] ) ), false );
		$code    = substr( (string) $result['error_code'], 0, 64 );
		$message = substr( (string) $result['error_message'], 0, 500 );

		if ( empty( $result['retryable'] ) && count( $events ) > 1 ) {
			// One bad event rejects the whole batch: resend each on its own to isolate it.
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'pending', solo = 1, claim_token = '', last_error_code = %s, last_error = %s WHERE id IN ({$ids})", $code, $message ) );
			self::schedule();
			return;
		}

		foreach ( $rows as $row ) {
			$id = (int) $row['id'];
			if ( ! isset( $events[ $id ] ) ) {
				continue;
			}
			$attempts = (int) $row['attempts'] + 1;
			$too_old  = (int) $row['event_time'] < time() - self::MAX_AGE;
			if ( $too_old ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'expired', attempts = %d, request_context = NULL, claim_token = '', last_error_code = %s, last_error = %s WHERE id = %d", $attempts, $code, $message, $id ) );
				continue;
			}
			if ( empty( $result['retryable'] ) || $attempts >= self::MAX_ATTEMPTS ) {
				// Dead letter. Context is kept (purged after 8 days) so an admin can retry after a fix.
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'failed', attempts = %d, claim_token = '', last_error_code = %s, last_error = %s WHERE id = %d", $attempts, $code, $message, $id ) );
				continue;
			}
			$next = gmdate( 'Y-m-d H:i:s', time() + self::backoff( $attempts, ! empty( $result['throttled'] ), (int) $result['retry_after'] ) );
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'pending', attempts = %d, next_attempt_at = %s, claim_token = '', last_error_code = %s, last_error = %s WHERE id = %d", $attempts, $next, $code, $message, $id ) );
		}
		// phpcs:enable
	}

	/**
	 * @param string              $api         capi|dataset_quality|graph.
	 * @param int                 $count       Events in the request.
	 * @param array<string,mixed> $result      Client result.
	 * @param bool                $is_test     Test mode.
	 * @param int                 $duration_ms Duration.
	 */
	public static function log_delivery( string $api, int $count, array $result, bool $is_test, int $duration_ms ): void {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			LNKup_Meta_Tables::deliveries(),
			array(
				'batch_id'        => substr( wp_generate_password( 16, false, false ), 0, 16 ),
				'api'             => $api,
				'event_count'     => $count,
				'http_status'     => (int) $result['http'],
				'events_received' => (int) $result['events_received'],
				'fbtrace_id'      => (string) $result['fbtrace_id'],
				'error_code'      => (string) $result['error_code'],
				'error_message'   => LNKup_Meta_Redactor::redact( (string) $result['error_message'] ),
				'is_test'         => $is_test ? 1 : 0,
				'duration_ms'     => $duration_ms,
				'created_at'      => self::now(),
			),
			array( '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s' )
		);
	}

	/**
	 * Admin: move failed (dead-letter) events that are still within Meta's window back to pending.
	 */
	public static function retry_failed(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . LNKup_Meta_Tables::conversions() . " SET status = 'pending', attempts = 0, solo = 1, next_attempt_at = NULL WHERE status = 'failed' AND request_context IS NOT NULL AND event_time >= %d", time() - self::MAX_AGE ) );
		if ( $count > 0 ) {
			self::schedule();
		}
		return $count;
	}

	/**
	 * Data minimization and retention.
	 */
	public static function purge(): void {
		global $wpdb;
		$table = LNKup_Meta_Tables::conversions();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET request_context = NULL WHERE request_context IS NOT NULL AND event_time < %d", time() - 8 * DAY_IN_SECONDS ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s LIMIT 5000", gmdate( 'Y-m-d H:i:s', time() - self::RETENTION_DAYS * DAY_IN_SECONDS ) ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . LNKup_Meta_Tables::deliveries() . ' WHERE created_at < %s LIMIT 5000', gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) ) );
		// phpcs:enable
		if ( class_exists( 'LNKup_Acquisition_Attribution' ) ) {
			LNKup_Acquisition_Attribution::purge_expired();
		}
	}
}

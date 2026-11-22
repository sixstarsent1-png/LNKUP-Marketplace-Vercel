<?php
/**
 * Action Scheduler wrapper.
 *
 * The messages table is the durable source of what must be sent; Action Scheduler
 * only wakes the worker. A sweeper re-enqueues anything due that lost its action
 * (e.g. queue table truncated, worker crash), so a lost job never loses a message.
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_Queue {

	const GROUP = 'lnkup-comms';

	const RECURRING = array(
		'lnkup_comms_hourly'        => HOUR_IN_SECONDS,
		'lnkup_comms_push_receipts' => 15 * MINUTE_IN_SECONDS,
		'lnkup_comms_daily'         => DAY_IN_SECONDS,
		'lnkup_comms_weekly'        => WEEK_IN_SECONDS,
	);

	public static function init(): void {
		add_action( 'lnkup_comms_deliver', array( 'LNKup_Comms_Engine', 'deliver' ), 10, 1 );
		add_action( 'lnkup_comms_retry', array( 'LNKup_Comms_Engine', 'retry_channel' ), 10, 2 );
		add_action( 'lnkup_comms_hourly', array( __CLASS__, 'sweep' ), 5 );
		add_action( 'lnkup_comms_daily', array( __CLASS__, 'purge_history' ), 90 );
		add_action( 'action_scheduler_init', array( __CLASS__, 'ensure_recurring' ) );
	}

	public static function ready(): bool {
		return function_exists( 'as_enqueue_async_action' ) && did_action( 'action_scheduler_init' );
	}

	/**
	 * Wake the worker for a message at a UTC timestamp.
	 */
	public static function enqueue( int $message_id, int $timestamp ): void {
		if ( $message_id < 1 ) {
			return;
		}
		if ( ! self::ready() ) {
			wp_schedule_single_event( max( time(), $timestamp ), 'lnkup_comms_deliver', array( $message_id ) );
			return;
		}
		if ( $timestamp <= time() + 5 ) {
			as_enqueue_async_action( 'lnkup_comms_deliver', array( $message_id ), self::GROUP );
			return;
		}
		as_schedule_single_action( $timestamp, 'lnkup_comms_deliver', array( $message_id ), self::GROUP );
	}

	public static function enqueue_retry( int $message_id, string $channel, int $delay ): void {
		$args = array( $message_id, $channel );
		if ( ! self::ready() ) {
			wp_schedule_single_event( time() + $delay, 'lnkup_comms_retry', $args );
			return;
		}
		as_schedule_single_action( time() + $delay, 'lnkup_comms_retry', $args, self::GROUP );
	}

	/**
	 * Run a background job (non-message work: matching batches, campaign batches).
	 *
	 * @param string $hook  Hook.
	 * @param array  $args  Args.
	 * @param int    $delay Seconds.
	 */
	public static function job( string $hook, array $args = array(), int $delay = 0 ): void {
		if ( ! self::ready() ) {
			wp_schedule_single_event( time() + $delay, $hook, $args );
			return;
		}
		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( $hook, $args, self::GROUP ) ) {
			return;
		}
		if ( $delay > 0 ) {
			as_schedule_single_action( time() + $delay, $hook, $args, self::GROUP );
		} else {
			as_enqueue_async_action( $hook, $args, self::GROUP );
		}
	}

	public static function ensure_recurring(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || get_transient( 'lnkup_comms_recurring_checked' ) ) {
			return;
		}
		set_transient( 'lnkup_comms_recurring_checked', 1, HOUR_IN_SECONDS );
		foreach ( self::RECURRING as $hook => $interval ) {
			if ( as_has_scheduled_action( $hook, array(), self::GROUP ) ) {
				continue;
			}
			as_schedule_recurring_action( self::first_run( $hook ), $interval, $hook, array(), self::GROUP );
		}
	}

	/**
	 * Daily/weekly jobs run at the configured digest hour (site time); weekly on Mondays.
	 */
	private static function first_run( string $hook ): int {
		if ( ! in_array( $hook, array( 'lnkup_comms_daily', 'lnkup_comms_weekly' ), true ) ) {
			return time() + 60;
		}
		$hour = max( 0, min( 23, LNKup_Comms_Settings::int( 'digest_hour' ) ) );
		$now  = new DateTimeImmutable( 'now', wp_timezone() );
		$run  = $now->setTime( $hour, 0 );
		if ( 'lnkup_comms_weekly' === $hook ) {
			$run = $now->modify( 'monday this week' )->setTime( $hour, 0 );
		}
		while ( $run <= $now ) {
			$run = $run->modify( 'lnkup_comms_weekly' === $hook ? '+1 week' : '+1 day' );
		}
		return $run->getTimestamp();
	}

	/**
	 * Recover stuck or orphaned messages.
	 */
	public static function sweep(): void {
		global $wpdb;
		$table = LNKup_Comms_Schema::table( 'messages' );
		// Worker died mid-send: release after 30 minutes.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'scheduled', updated_at = %s WHERE status = 'sending' AND updated_at < %s", LNKup_Comms_Schema::now(), LNKup_Comms_Schema::now( -30 * MINUTE_IN_SECONDS ) ) );
		// Due for 15+ minutes with no worker: wake it.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE status = 'scheduled' AND scheduled_at < %s ORDER BY scheduled_at ASC LIMIT 200", LNKup_Comms_Schema::now( -15 * MINUTE_IN_SECONDS ) ) );
		foreach ( (array) $ids as $id ) {
			self::enqueue( (int) $id, time() );
		}
	}

	/**
	 * Retention: delete finished message history older than retention_days (min 30).
	 * Scheduled messages are never purged.
	 */
	public static function purge_history(): void {
		global $wpdb;
		$days     = max( 30, LNKup_Comms_Settings::int( 'retention_days' ) );
		$cutoff   = LNKup_Comms_Schema::now( -$days * DAY_IN_SECONDS );
		$messages = LNKup_Comms_Schema::table( 'messages' );
		for ( $i = 0; $i < 20; $i++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$messages} WHERE created_at < %s AND status NOT IN ('scheduled','sending') LIMIT 500", $cutoff ) ) );
			if ( array() === $ids ) {
				break;
			}
			$in = implode( ',', $ids );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( 'DELETE FROM ' . LNKup_Comms_Schema::table( 'deliveries' ) . " WHERE message_id IN ({$in})" );
			$wpdb->query( 'DELETE FROM ' . LNKup_Comms_Schema::table( 'clicks' ) . " WHERE message_id IN ({$in})" );
			$wpdb->query( "DELETE FROM {$messages} WHERE id IN ({$in})" );
			// phpcs:enable
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . LNKup_Comms_Schema::table( 'events' ) . ' WHERE created_at < %s LIMIT 5000', $cutoff ) );
	}

	/**
	 * Queue depth for health checks.
	 */
	public static function pending_actions(): int {
		if ( ! class_exists( 'ActionScheduler_Store' ) || ! did_action( 'action_scheduler_init' ) ) {
			return 0;
		}
		$ids = as_get_scheduled_actions(
			array(
				'group'    => self::GROUP,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1000,
			),
			'ids'
		);
		return is_array( $ids ) ? count( $ids ) : 0;
	}

	public static function failed_actions( int $since_seconds = DAY_IN_SECONDS ): int {
		if ( ! class_exists( 'ActionScheduler_Store' ) || ! did_action( 'action_scheduler_init' ) ) {
			return 0;
		}
		$ids = as_get_scheduled_actions(
			array(
				'group'        => self::GROUP,
				'status'       => ActionScheduler_Store::STATUS_FAILED,
				'date'         => gmdate( 'Y-m-d H:i:s', time() - $since_seconds ),
				'date_compare' => '>=',
				'per_page'     => 1000,
			),
			'ids'
		);
		return is_array( $ids ) ? count( $ids ) : 0;
	}
}

<?php
/**
 * Outreach email queue cron.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Outreach_Cron {

	const HOOK = 'lnkup_outreach_process_queue';

	/**
	 * Init cron hook.
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
	}

	/**
	 * @param array $schedules Schedules.
	 */
	public static function add_schedule( $schedules ) {
		if ( ! isset( $schedules['lnkup_five_minutes'] ) ) {
			$schedules['lnkup_five_minutes'] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every five minutes', 'lnkup-marketplace' ),
			);
		}
		return $schedules;
	}

	/**
	 * Schedule next batch using configured interval.
	 */
	public static function schedule_processing() {
		self::reschedule( time() + self::get_batch_interval() );
	}

	/**
	 * Schedule resume at start of next hour (+ buffer).
	 */
	public static function schedule_next_hour() {
		$tz   = wp_timezone();
		$next = new DateTime( 'now', $tz );
		$next->modify( 'next hour' );
		$next->modify( '+5 minutes' );
		self::reschedule( $next->getTimestamp() );
	}

	/**
	 * Schedule resume tomorrow at configured site-local time.
	 */
	public static function schedule_next_day() {
		$settings  = lnkup_get_settings();
		$time_str  = (string) ( $settings['outreach_resume_time'] ?? '08:00' );
		$parts     = array_map( 'intval', explode( ':', $time_str . ':00' ) );
		$hour      = max( 0, min( 23, $parts[0] ?? 8 ) );
		$minute    = max( 0, min( 59, $parts[1] ?? 0 ) );
		$tz        = wp_timezone();
		$next      = new DateTime( 'tomorrow', $tz );
		$next->setTime( $hour, $minute, 0 );
		self::reschedule( $next->getTimestamp() );
	}

	/**
	 * Seconds between batches when rate limiting is enabled; 60 when disabled.
	 */
	public static function get_batch_interval() {
		$settings = lnkup_get_settings();
		if ( empty( $settings['outreach_rate_limit_enabled'] ) ) {
			return 60;
		}
		return max( 60, (int) ( $settings['outreach_batch_interval_seconds'] ?? 180 ) );
	}

	/**
	 * Replace any existing outreach cron with a single event.
	 *
	 * @param int $timestamp Unix timestamp.
	 */
	public static function reschedule( $timestamp ) {
		self::clear_scheduled();
		wp_schedule_single_event( max( time() + 30, (int) $timestamp ), self::HOOK );
	}

	/**
	 * Clear all scheduled outreach cron events.
	 */
	public static function clear_scheduled() {
		$timestamp = wp_next_scheduled( self::HOOK );
		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
			$timestamp = wp_next_scheduled( self::HOOK );
		}
	}

	/**
	 * Process outreach batch.
	 */
	public static function run() {
		if ( ! class_exists( 'LNKup_Outreach' ) ) {
			return;
		}
		LNKup_Outreach::process_batch();
	}
}

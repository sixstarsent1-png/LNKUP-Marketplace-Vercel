<?php
/**
 * External events cron — follow-up emails and auto-sync.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_External_Events_Cron {

	const HOOK          = 'lnkup_external_events_followup';
	const HOOK_SYNC     = 'lnkup_external_events_sync';

	/**
	 * Init cron.
	 */
	public static function init(): void {
		add_action( self::HOOK, array( __CLASS__, 'run_followup' ) );
		add_action( self::HOOK_SYNC, array( __CLASS__, 'run_sync' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
		add_action( 'update_option_lnkup_settings', array( __CLASS__, 'sync_schedule' ), 10, 0 );
	}

	/**
	 * Schedule follow-up cron.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 300, 'lnkup_fifteen_minutes', self::HOOK );
		}
		self::sync_schedule();
	}

	/**
	 * Schedule or clear auto-sync cron based on settings.
	 */
	public static function sync_schedule(): void {
		self::unschedule_sync();

		if ( ! class_exists( 'LNKup_External_Events_Sync' ) ) {
			return;
		}

		$config = LNKup_External_Events_Sync::get_config();
		if ( empty( $config['enabled'] ) ) {
			return;
		}

		$recurrence = 'twice_daily' === ( $config['frequency'] ?? 'daily' ) ? 'lnkup_twelve_hours' : 'daily';
		if ( ! wp_next_scheduled( self::HOOK_SYNC ) ) {
			wp_schedule_event( time() + 600, $recurrence, self::HOOK_SYNC );
		}
	}

	/**
	 * Clear sync cron.
	 */
	public static function unschedule_sync(): void {
		wp_clear_scheduled_hook( self::HOOK_SYNC );
	}

	/**
	 * @param array<string, mixed> $schedules Schedules.
	 * @return array<string, mixed>
	 */
	public static function add_schedule( array $schedules ): array {
		if ( ! isset( $schedules['lnkup_fifteen_minutes'] ) ) {
			$schedules['lnkup_fifteen_minutes'] = array(
				'interval' => 15 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 15 minutes', 'lnkup-marketplace' ),
			);
		}
		if ( ! isset( $schedules['lnkup_twelve_hours'] ) ) {
			$schedules['lnkup_twelve_hours'] = array(
				'interval' => 12 * HOUR_IN_SECONDS,
				'display'  => __( 'Every 12 hours', 'lnkup-marketplace' ),
			);
		}
		return $schedules;
	}

	/**
	 * Run follow-up batch.
	 */
	public static function run_followup(): void {
		if ( class_exists( 'LNKup_External_Events_Followup' ) ) {
			LNKup_External_Events_Followup::process_queue();
		}
	}

	/**
	 * Run auto-sync batch.
	 */
	public static function run_sync(): void {
		if ( ! class_exists( 'LNKup_External_Events_Sync' ) ) {
			return;
		}
		$config = LNKup_External_Events_Sync::get_config();
		if ( empty( $config['enabled'] ) ) {
			return;
		}
		LNKup_External_Events_Sync::run( LNKup_External_Events_Sync::MODE_FULL );
	}
}

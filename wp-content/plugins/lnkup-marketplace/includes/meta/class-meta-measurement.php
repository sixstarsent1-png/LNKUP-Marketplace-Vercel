<?php
/**
 * Meta measurement module bootstrap (Pixel, Conversions API, Dataset Quality, attribution).
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Meta_Measurement {

	const FIVE_MINUTES = 'lnkup_meta_five_minutes';
	/** When conversion recording started; activity before this can never have conversions. */
	const INSTALLED_OPTION = 'lnkup_meta_installed_at';

	public static function installed_at(): int {
		$at = (int) get_option( self::INSTALLED_OPTION, 0 );
		if ( $at > 0 ) {
			return $at;
		}
		global $wpdb;
		// Sites upgraded before this option existed: use the outbox table's creation time.
		$created = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT CREATE_TIME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', LNKup_Meta_Tables::conversions() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$at      = '' !== $created && strtotime( $created ) ? min( time(), (int) strtotime( $created ) ) : time();
		add_option( self::INSTALLED_OPTION, $at, '', false );
		return $at;
	}

	public static function init(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval

		LNKup_Tracking_Consent::init();
		LNKup_Meta_Cookies::init();
		LNKup_Acquisition_Attribution::init();
		LNKup_Conversion_Listeners::init();
		LNKup_Meta_Dispatcher::init();
		LNKup_Meta_Pixel::init();
		LNKup_Meta_REST::init();
		LNKup_Meta_Dataset_Quality::init();
		LNKup_Meta_Alerts::init();
		if ( is_admin() ) {
			LNKup_Meta_Admin::init();
		}
		add_action( 'init', array( __CLASS__, 'schedule' ), 20 );
		add_action( 'init', array( __CLASS__, 'installed_at' ), 21 );
	}

	/**
	 * @param array<string,array<string,mixed>> $schedules Schedules.
	 * @return array<string,array<string,mixed>>
	 */
	public static function cron_schedules( $schedules ) {
		$schedules = is_array( $schedules ) ? $schedules : array();
		if ( ! isset( $schedules[ self::FIVE_MINUTES ] ) ) {
			$schedules[ self::FIVE_MINUTES ] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every five minutes (LNKup Meta)', 'lnkup-marketplace' ),
			);
		}
		return $schedules;
	}

	/**
	 * Ensure recurring jobs exist (cheap: wp_next_scheduled reads the cached cron option).
	 */
	public static function schedule(): void {
		$jobs = array(
			LNKup_Meta_Dispatcher::SWEEP_HOOK   => self::FIVE_MINUTES,
			LNKup_Meta_Dataset_Quality::HOOK    => 'daily',
			LNKup_Meta_Alerts::HOOK             => 'hourly',
			LNKup_Meta_Dispatcher::PURGE_HOOK   => 'daily',
		);
		foreach ( $jobs as $hook => $recurrence ) {
			if ( ! wp_next_scheduled( $hook ) ) {
				wp_schedule_event( time() + 120, $recurrence, $hook );
			}
		}
	}
}

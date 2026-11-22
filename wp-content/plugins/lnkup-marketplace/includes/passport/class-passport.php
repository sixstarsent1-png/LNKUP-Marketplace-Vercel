<?php
/**
 * Vendor Passport & Reputation OS bootstrap: init, migrations, daily cron.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Passport {

	const DAILY_HOOK        = 'lnkup_passport_daily';
	const MIGRATION_OPTION  = 'lnkup_passport_migration_version';
	const MIGRATION_VERSION = '1';

	public static function init(): void {
		$services = array(
			'LNKup_Passport_Public',
			'LNKup_Document_Migration',
			'LNKup_Passport_Projection',
			'LNKup_Passport_REST',
			'LNKup_Verification_Records',
			'LNKup_Document_Vault',
			'LNKup_Participation_History',
			'LNKup_Passport_Admin',
		);
		foreach ( $services as $class ) {
			if ( class_exists( $class ) && method_exists( $class, 'init' ) ) {
				$class::init();
			}
		}
		add_action( 'init', array( 'LNKup_Passport_Tables', 'maybe_upgrade' ), 4 );
		add_action( self::DAILY_HOOK, array( __CLASS__, 'run_daily' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_migrate' ), 6 );
		add_action( self::DAILY_HOOK, array( __CLASS__, 'maybe_migrate' ), 1 );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
			wp_schedule_event( strtotime( 'tomorrow 05:15:00' ), 'daily', self::DAILY_HOOK );
		}
	}

	/**
	 * Additive, idempotent data migrations. Safe to re-run; never deletes vendor data.
	 */
	public static function maybe_migrate(): void {
		if ( get_option( self::MIGRATION_OPTION ) === self::MIGRATION_VERSION ) {
			return;
		}
		if ( ! LNKup_Passport_Tables::exists( LNKup_Passport_Tables::review_index_table() ) ) {
			LNKup_Passport_Tables::create_tables();
		}
		$lock = 'lnkup_passport_migration_lock';
		if ( get_transient( $lock ) ) {
			return;
		}
		set_transient( $lock, 1, 5 * MINUTE_IN_SECONDS );
		try {
			$pending = 0;
			$pending += LNKup_Vendor_Reviews::backfill_index( 300 );
			if ( class_exists( 'LNKup_Verification_Records' ) ) {
				$pending += LNKup_Verification_Records::backfill_from_meta( 300 );
			}
			if ( class_exists( 'LNKup_Document_Vault' ) ) {
				$pending += LNKup_Document_Vault::backfill_groups( 300 );
			}
			if ( 0 === $pending ) {
				update_option( self::MIGRATION_OPTION, self::MIGRATION_VERSION, false );
			}
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'LNKup Passport migration failed: ' . $e->getMessage() );
		}
		delete_transient( $lock );
	}

	/**
	 * Daily: expire verification/documents, send expiry reminders, refresh stale reputation.
	 */
	public static function run_daily(): void {
		$jobs = array(
			array( 'LNKup_Verification_Records', 'expire_due' ),
			array( 'LNKup_Document_Vault', 'send_expiry_reminders' ),
			array( 'LNKup_Document_Vault', 'expire_shares' ),
			array( __CLASS__, 'refresh_stale_reputation' ),
		);
		foreach ( $jobs as $job ) {
			if ( ! class_exists( $job[0] ) || ! method_exists( $job[0], $job[1] ) ) {
				continue;
			}
			try {
				call_user_func( $job );
			} catch ( Throwable $e ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'LNKup Passport daily job ' . $job[0] . '::' . $job[1] . ' failed: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Refresh reputation caches older than a week (bounded batch).
	 */
	public static function refresh_stale_reputation(): void {
		global $wpdb;
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_lnkup_reputation_updated_at'
				WHERE p.post_type = 'lnkup_vendor_profile' AND p.post_status = 'publish'
				AND ( pm.meta_value IS NULL OR pm.meta_value < %s )
				ORDER BY pm.meta_value ASC LIMIT 100",
				gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) )
			)
		);
		foreach ( (array) $ids as $profile_id ) {
			LNKup_Vendor_Reputation::refresh( (int) $profile_id );
		}
	}
}

<?php
/**
 * Organizer Portfolio OS tables: template versions, background jobs, audit trail, tour interest.
 *
 * Canonical records stay in CPTs (lnkup_tour, lnkup_event, lnkup_opportunity, lnkup_application).
 * These tables only hold immutable template snapshots, job progress, audit rows and
 * "interested in the whole tour" signals that are never approvals or bookings.
 *
 * The schema is versioned on its own option so it upgrades without a plugin version bump.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Portfolio_Tables {

	const SCHEMA_VERSION = '1';
	const VERSION_OPTION = 'lnkup_portfolio_db_version';

	const TEMPLATE_VERSIONS = 'lnkup_template_versions';
	const JOBS              = 'lnkup_portfolio_jobs';
	const AUDIT             = 'lnkup_portfolio_audit';
	const INTEREST          = 'lnkup_tour_interest';

	/**
	 * @param string $table Table suffix constant.
	 */
	public static function table_name( $table ) {
		global $wpdb;
		return $wpdb->prefix . $table;
	}

	public static function template_versions_table() {
		return self::table_name( self::TEMPLATE_VERSIONS );
	}

	public static function jobs_table() {
		return self::table_name( self::JOBS );
	}

	public static function audit_table() {
		return self::table_name( self::AUDIT );
	}

	public static function interest_table() {
		return self::table_name( self::INTEREST );
	}

	/**
	 * Create or upgrade tables once per schema version.
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::VERSION_OPTION ) === self::SCHEMA_VERSION ) {
			return;
		}
		try {
			self::create_tables();
			update_option( self::VERSION_OPTION, self::SCHEMA_VERSION, false );
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'LNKup portfolio table migration failed: ' . $e->getMessage() );
		}
	}

	public static function create_tables() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$versions = self::template_versions_table();
		dbDelta(
			"CREATE TABLE {$versions} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				template_id bigint(20) unsigned NOT NULL DEFAULT 0,
				version int(10) unsigned NOT NULL DEFAULT 1,
				config_json longtext NOT NULL,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY template_version (template_id,version)
			) {$charset};"
		);

		$jobs = self::jobs_table();
		dbDelta(
			"CREATE TABLE {$jobs} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				job_key varchar(80) NOT NULL DEFAULT '',
				organizer_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				tour_id bigint(20) unsigned NOT NULL DEFAULT 0,
				type varchar(32) NOT NULL DEFAULT '',
				status varchar(16) NOT NULL DEFAULT 'queued',
				total int(10) unsigned NOT NULL DEFAULT 0,
				done int(10) unsigned NOT NULL DEFAULT 0,
				failed int(10) unsigned NOT NULL DEFAULT 0,
				payload longtext NOT NULL,
				results longtext NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY job_key (job_key),
				KEY organizer_status (organizer_user_id,status),
				KEY tour_id (tour_id)
			) {$charset};"
		);

		$audit = self::audit_table();
		dbDelta(
			"CREATE TABLE {$audit} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				organizer_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				object_type varchar(24) NOT NULL DEFAULT '',
				object_id bigint(20) unsigned NOT NULL DEFAULT 0,
				action varchar(48) NOT NULL DEFAULT '',
				details longtext NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY object_ref (object_type,object_id),
				KEY organizer_user_id (organizer_user_id)
			) {$charset};"
		);

		$interest = self::interest_table();
		dbDelta(
			"CREATE TABLE {$interest} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				tour_id bigint(20) unsigned NOT NULL DEFAULT 0,
				vendor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(16) NOT NULL DEFAULT 'interested',
				note varchar(500) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY tour_vendor (tour_id,vendor_user_id),
				KEY vendor_user_id (vendor_user_id)
			) {$charset};"
		);
	}
}

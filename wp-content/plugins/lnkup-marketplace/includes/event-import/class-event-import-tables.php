<?php
/**
 * Tables for external sources, import jobs and provider connections (dbDelta, additive only).
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Event_Import_Tables {

	const SCHEMA_OPTION  = 'lnkup_event_import_schema';
	const SCHEMA_VERSION = '1';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'maybe_install' ), 6 );
	}

	public static function sources(): string {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_event_sources';
	}

	public static function jobs(): string {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_import_jobs';
	}

	public static function items(): string {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_import_job_items';
	}

	public static function connections(): string {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_provider_connections';
	}

	public static function maybe_install(): void {
		if ( get_option( self::SCHEMA_OPTION ) === self::SCHEMA_VERSION ) {
			return;
		}
		self::install();
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		dbDelta(
			'CREATE TABLE ' . self::sources() . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				draft_id bigint(20) unsigned NOT NULL DEFAULT 0,
				organizer_id bigint(20) unsigned NOT NULL DEFAULT 0,
				provider varchar(32) NOT NULL DEFAULT '',
				external_event_id varchar(100) NOT NULL DEFAULT '',
				external_occurrence_id varchar(100) NOT NULL DEFAULT '',
				external_series_id varchar(100) NOT NULL DEFAULT '',
				url varchar(500) NOT NULL DEFAULT '',
				url_hash char(40) NOT NULL DEFAULT '',
				is_primary tinyint(1) NOT NULL DEFAULT 0,
				mode varchar(10) NOT NULL DEFAULT 'link',
				ownership_state varchar(20) NOT NULL DEFAULT 'attested',
				availability varchar(20) NOT NULL DEFAULT 'unknown',
				last_sync_at datetime DEFAULT NULL,
				last_attempt_at datetime DEFAULT NULL,
				source_version varchar(64) NOT NULL DEFAULT '',
				provenance longtext,
				pending_changes longtext,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY organizer_url (organizer_id,url_hash),
				KEY event (event_id),
				KEY provider_ext (provider,external_event_id,external_occurrence_id)
			) {$c};"
		);
		dbDelta(
			'CREATE TABLE ' . self::jobs() . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				organizer_id bigint(20) unsigned NOT NULL DEFAULT 0,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				method varchar(20) NOT NULL DEFAULT '',
				status varchar(24) NOT NULL DEFAULT 'queued',
				file_name varchar(190) NOT NULL DEFAULT '',
				total_items int(10) unsigned NOT NULL DEFAULT 0,
				created_count int(10) unsigned NOT NULL DEFAULT 0,
				linked_count int(10) unsigned NOT NULL DEFAULT 0,
				failed_count int(10) unsigned NOT NULL DEFAULT 0,
				skipped_count int(10) unsigned NOT NULL DEFAULT 0,
				options longtext,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY organizer (organizer_id,created_at)
			) {$c};"
		);
		dbDelta(
			'CREATE TABLE ' . self::items() . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				job_id bigint(20) unsigned NOT NULL DEFAULT 0,
				row_key char(40) NOT NULL DEFAULT '',
				input longtext,
				preview longtext,
				status varchar(16) NOT NULL DEFAULT 'pending',
				action varchar(16) NOT NULL DEFAULT 'draft',
				draft_id bigint(20) unsigned NOT NULL DEFAULT 0,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				error varchar(255) NOT NULL DEFAULT '',
				attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY job_row (job_id,row_key),
				KEY status (job_id,status)
			) {$c};"
		);
		dbDelta(
			'CREATE TABLE ' . self::connections() . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				organizer_id bigint(20) unsigned NOT NULL DEFAULT 0,
				provider varchar(32) NOT NULL DEFAULT '',
				account_label varchar(190) NOT NULL DEFAULT '',
				external_org_id varchar(100) NOT NULL DEFAULT '',
				token text,
				refresh_token text,
				expires_at datetime DEFAULT NULL,
				scopes varchar(190) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'active',
				last_sync_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY organizer_provider (organizer_id,provider)
			) {$c};"
		);
		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
	}
}

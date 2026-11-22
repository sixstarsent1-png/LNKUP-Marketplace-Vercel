<?php
/**
 * Vendor Passport tables: verification records, document shares, review index.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Passport_Tables {

	const SCHEMA_OPTION  = 'lnkup_passport_schema_version';
	const SCHEMA_VERSION = '1';

	public static function create_tables(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$verification = self::verification_table();
		dbDelta(
			"CREATE TABLE {$verification} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				vendor_profile_id bigint(20) unsigned NOT NULL DEFAULT 0,
				type varchar(40) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT '',
				authority varchar(120) NOT NULL DEFAULT '',
				source varchar(40) NOT NULL DEFAULT '',
				document_id bigint(20) unsigned NOT NULL DEFAULT 0,
				reviewer_id bigint(20) unsigned NOT NULL DEFAULT 0,
				verified_at datetime DEFAULT NULL,
				expires_at datetime DEFAULT NULL,
				revoked_at datetime DEFAULT NULL,
				reason text,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY profile_type (vendor_profile_id, type, id),
				KEY status_expires (status, expires_at)
			) {$charset};"
		);

		$shares = self::share_table();
		dbDelta(
			"CREATE TABLE {$shares} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				document_id bigint(20) unsigned NOT NULL DEFAULT 0,
				vendor_profile_id bigint(20) unsigned NOT NULL DEFAULT 0,
				organizer_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				application_id bigint(20) unsigned NOT NULL DEFAULT 0,
				granted_by bigint(20) unsigned NOT NULL DEFAULT 0,
				granted_at datetime NOT NULL,
				revoked_at datetime DEFAULT NULL,
				expires_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY organizer_doc (organizer_user_id, document_id),
				KEY profile_id (vendor_profile_id),
				KEY application_id (application_id)
			) {$charset};"
		);

		$reviews = self::review_index_table();
		dbDelta(
			"CREATE TABLE {$reviews} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				application_id bigint(20) unsigned DEFAULT NULL,
				review_id bigint(20) unsigned NOT NULL DEFAULT 0,
				organizer_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				vendor_profile_id bigint(20) unsigned NOT NULL DEFAULT 0,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				overall tinyint unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'published',
				verified_participation tinyint(1) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY application_id (application_id),
				KEY profile_status (vendor_profile_id, status),
				KEY organizer_user_id (organizer_user_id)
			) {$charset};"
		);

		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
	}

	public static function verification_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_vendor_verification';
	}

	public static function share_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_document_share';
	}

	public static function review_index_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_review_index';
	}

	public static function exists( string $table ): bool {
		global $wpdb;
		static $cache = array();
		if ( ! isset( $cache[ $table ] ) ) {
			$cache[ $table ] = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return $cache[ $table ];
	}
}

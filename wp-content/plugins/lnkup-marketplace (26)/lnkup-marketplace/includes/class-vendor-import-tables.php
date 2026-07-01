<?php
/**
 * Vendor CSV import database tables.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Vendor_Import_Tables {

	/**
	 * Create import tables.
	 */
	public static function create_tables() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$jobs = self::job_table();
		dbDelta(
			"CREATE TABLE {$jobs} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				organizer_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				file_path varchar(500) NOT NULL DEFAULT '',
				original_filename varchar(255) NOT NULL DEFAULT '',
				headers_json longtext,
				row_count int unsigned NOT NULL DEFAULT 0,
				mapping_json longtext,
				options_json longtext,
				status varchar(24) NOT NULL DEFAULT 'uploaded',
				summary_json longtext,
				created_at datetime NOT NULL,
				expires_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY organizer_user_id (organizer_user_id),
				KEY status (status),
				KEY expires_at (expires_at)
			) {$charset};"
		);

		$prospects = self::prospect_table();
		dbDelta(
			"CREATE TABLE {$prospects} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				organizer_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				normalized_email varchar(191) NOT NULL DEFAULT '',
				business_name varchar(191) NOT NULL DEFAULT '',
				contact_name varchar(191) NOT NULL DEFAULT '',
				phone varchar(32) NOT NULL DEFAULT '',
				city varchar(100) NOT NULL DEFAULT '',
				state varchar(32) NOT NULL DEFAULT '',
				vendor_category varchar(100) NOT NULL DEFAULT '',
				meta_json longtext,
				import_job_id bigint(20) unsigned NOT NULL DEFAULT 0,
				linked_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				outreach_recipient_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY organizer_user_id (organizer_user_id),
				KEY org_email (organizer_user_id, normalized_email),
				KEY org_business (organizer_user_id, business_name),
				KEY linked_user_id (linked_user_id)
			) {$charset};"
		);

		$list_prospects = self::list_prospect_table();
		dbDelta(
			"CREATE TABLE {$list_prospects} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				list_id bigint(20) unsigned NOT NULL DEFAULT 0,
				prospect_id bigint(20) unsigned NOT NULL DEFAULT 0,
				added_at datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY list_prospect (list_id, prospect_id),
				KEY list_id (list_id)
			) {$charset};"
		);
	}

	/**
	 * Import job table name.
	 */
	public static function job_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_crm_import_job';
	}

	/**
	 * Prospect table name.
	 */
	public static function prospect_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_organizer_vendor_prospect';
	}

	/**
	 * List-prospect junction table name.
	 */
	public static function list_prospect_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_vendor_list_prospect';
	}
}

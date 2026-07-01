<?php
/**
 * Organizer CRM custom database tables.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Organizer_CRM_Tables {

	/**
	 * Create all CRM tables.
	 */
	public static function create_tables() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$rel = $wpdb->prefix . 'lnkup_organizer_vendor_rel';
		dbDelta(
			"CREATE TABLE {$rel} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				organizer_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				vendor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				vendor_profile_id bigint(20) unsigned NOT NULL DEFAULT 0,
				is_favorite tinyint(1) NOT NULL DEFAULT 0,
				is_preferred tinyint(1) NOT NULL DEFAULT 0,
				first_connected_at datetime NOT NULL,
				last_activity_at datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY org_vendor (organizer_user_id, vendor_user_id),
				KEY vendor_user_id (vendor_user_id)
			) {$charset};"
		);

		$list_items = $wpdb->prefix . 'lnkup_vendor_list_item';
		dbDelta(
			"CREATE TABLE {$list_items} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				list_id bigint(20) unsigned NOT NULL DEFAULT 0,
				vendor_profile_id bigint(20) unsigned NOT NULL DEFAULT 0,
				added_at datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY list_vendor (list_id, vendor_profile_id),
				KEY list_id (list_id)
			) {$charset};"
		);

		$tags = $wpdb->prefix . 'lnkup_organizer_vendor_tag';
		dbDelta(
			"CREATE TABLE {$tags} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				organizer_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				vendor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				tag_slug varchar(50) NOT NULL DEFAULT '',
				tag_label varchar(100) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY org_vendor_tag (organizer_user_id, vendor_user_id, tag_slug),
				KEY organizer_user_id (organizer_user_id)
			) {$charset};"
		);

		$activity = $wpdb->prefix . 'lnkup_crm_activity';
		dbDelta(
			"CREATE TABLE {$activity} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				organizer_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				vendor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				application_id bigint(20) unsigned NOT NULL DEFAULT 0,
				activity_type varchar(50) NOT NULL DEFAULT '',
				payload longtext,
				actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY org_vendor (organizer_user_id, vendor_user_id),
				KEY activity_type (activity_type),
				KEY created_at (created_at)
			) {$charset};"
		);
	}

	/**
	 * Relationship table name.
	 */
	public static function rel_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_organizer_vendor_rel';
	}

	/**
	 * List items table name.
	 */
	public static function list_item_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_vendor_list_item';
	}

	/**
	 * Tags table name.
	 */
	public static function tag_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_organizer_vendor_tag';
	}

	/**
	 * Activity table name.
	 */
	public static function activity_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_crm_activity';
	}
}

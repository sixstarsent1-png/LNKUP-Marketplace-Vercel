<?php
/**
 * Reputation custom database tables.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Reputation_Tables {

	/**
	 * Create reputation tables.
	 */
	public static function create_tables() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$badges = $wpdb->prefix . 'lnkup_vendor_badge';
		dbDelta(
			"CREATE TABLE {$badges} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				vendor_profile_id bigint(20) unsigned NOT NULL DEFAULT 0,
				badge_slug varchar(50) NOT NULL DEFAULT '',
				source varchar(20) NOT NULL DEFAULT 'auto',
				earned_at datetime NOT NULL,
				expires_at datetime DEFAULT NULL,
				revoked_at datetime DEFAULT NULL,
				revoked_by bigint(20) unsigned NOT NULL DEFAULT 0,
				meta longtext,
				PRIMARY KEY (id),
				UNIQUE KEY profile_badge (vendor_profile_id, badge_slug),
				KEY vendor_profile_id (vendor_profile_id)
			) {$charset};"
		);

		$activity = $wpdb->prefix . 'lnkup_reputation_activity';
		dbDelta(
			"CREATE TABLE {$activity} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				vendor_profile_id bigint(20) unsigned NOT NULL DEFAULT 0,
				vendor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				organizer_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				application_id bigint(20) unsigned NOT NULL DEFAULT 0,
				activity_type varchar(50) NOT NULL DEFAULT '',
				payload longtext,
				actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY vendor_profile_id (vendor_profile_id),
				KEY activity_type (activity_type)
			) {$charset};"
		);

		$snapshots = $wpdb->prefix . 'lnkup_score_snapshot';
		dbDelta(
			"CREATE TABLE {$snapshots} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				vendor_profile_id bigint(20) unsigned NOT NULL DEFAULT 0,
				score smallint NOT NULL DEFAULT 0,
				label varchar(80) NOT NULL DEFAULT '',
				breakdown longtext,
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY vendor_profile_id (vendor_profile_id),
				KEY created_at (created_at)
			) {$charset};"
		);
	}

	public static function badge_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_vendor_badge';
	}

	public static function activity_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_reputation_activity';
	}

	public static function snapshot_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_score_snapshot';
	}
}

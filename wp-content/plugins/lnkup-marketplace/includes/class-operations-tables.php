<?php
/**
 * Vendor operations custom database tables.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Operations_Tables {

	/**
	 * Create all operations tables.
	 */
	public static function create_tables() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$booths = $wpdb->prefix . 'lnkup_booths';
		dbDelta(
			"CREATE TABLE {$booths} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				booth_number varchar(40) NOT NULL DEFAULT '',
				booth_type varchar(40) NOT NULL DEFAULT 'standard',
				booth_size varchar(40) NOT NULL DEFAULT '',
				pos_x decimal(6,2) NOT NULL DEFAULT 0,
				pos_y decimal(6,2) NOT NULL DEFAULT 0,
				has_power tinyint(1) NOT NULL DEFAULT 0,
				has_water tinyint(1) NOT NULL DEFAULT 0,
				application_id bigint(20) unsigned NOT NULL DEFAULT 0,
				notes text,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY event_booth (event_id, booth_number),
				KEY event_id (event_id),
				KEY application_id (application_id)
			) {$charset};"
		);

		$parking = $wpdb->prefix . 'lnkup_parking';
		dbDelta(
			"CREATE TABLE {$parking} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				application_id bigint(20) unsigned NOT NULL DEFAULT 0,
				lot varchar(80) NOT NULL DEFAULT '',
				row_label varchar(40) NOT NULL DEFAULT '',
				space_number varchar(40) NOT NULL DEFAULT '',
				vehicle_type varchar(40) NOT NULL DEFAULT 'standard',
				gate varchar(80) NOT NULL DEFAULT '',
				qr_token varchar(64) NOT NULL DEFAULT '',
				instructions text,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY event_id (event_id),
				KEY application_id (application_id)
			) {$charset};"
		);

		$windows = $wpdb->prefix . 'lnkup_loadin_windows';
		dbDelta(
			"CREATE TABLE {$windows} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				start_at datetime NOT NULL,
				end_at datetime NOT NULL,
				gate varchar(80) NOT NULL DEFAULT '',
				max_slots smallint NOT NULL DEFAULT 10,
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY event_id (event_id)
			) {$charset};"
		);

		$assignments = $wpdb->prefix . 'lnkup_loadin_assignments';
		dbDelta(
			"CREATE TABLE {$assignments} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				window_id bigint(20) unsigned NOT NULL DEFAULT 0,
				application_id bigint(20) unsigned NOT NULL DEFAULT 0,
				assigned_at datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY window_app (window_id, application_id),
				KEY application_id (application_id)
			) {$charset};"
		);

		$checkins = $wpdb->prefix . 'lnkup_checkins';
		dbDelta(
			"CREATE TABLE {$checkins} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				application_id bigint(20) unsigned NOT NULL DEFAULT 0,
				action varchar(40) NOT NULL DEFAULT '',
				method varchar(20) NOT NULL DEFAULT 'manual',
				actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY event_id (event_id),
				KEY application_id (application_id),
				KEY action (action)
			) {$charset};"
		);

		$support = $wpdb->prefix . 'lnkup_support_requests';
		dbDelta(
			"CREATE TABLE {$support} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				application_id bigint(20) unsigned NOT NULL DEFAULT 0,
				vendor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				category varchar(40) NOT NULL DEFAULT '',
				priority varchar(20) NOT NULL DEFAULT 'normal',
				status varchar(20) NOT NULL DEFAULT 'open',
				message text,
				assigned_staff_id bigint(20) unsigned NOT NULL DEFAULT 0,
				responded_at datetime DEFAULT NULL,
				resolved_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY event_id (event_id),
				KEY status (status)
			) {$charset};"
		);

		$incidents = $wpdb->prefix . 'lnkup_incidents';
		dbDelta(
			"CREATE TABLE {$incidents} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				incident_type varchar(40) NOT NULL DEFAULT '',
				location varchar(255) NOT NULL DEFAULT '',
				notes text,
				photos longtext,
				assigned_staff_id bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'open',
				resolution text,
				reporter_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				resolved_at datetime DEFAULT NULL,
				PRIMARY KEY (id),
				KEY event_id (event_id),
				KEY status (status)
			) {$charset};"
		);

		$log = $wpdb->prefix . 'lnkup_operations_log';
		dbDelta(
			"CREATE TABLE {$log} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				application_id bigint(20) unsigned NOT NULL DEFAULT 0,
				activity_type varchar(50) NOT NULL DEFAULT '',
				payload longtext,
				actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY event_id (event_id),
				KEY activity_type (activity_type),
				KEY created_at (created_at)
			) {$charset};"
		);

		$staff = $wpdb->prefix . 'lnkup_event_staff';
		dbDelta(
			"CREATE TABLE {$staff} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				staff_role varchar(40) NOT NULL DEFAULT '',
				assigned_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY event_user (event_id, user_id),
				KEY event_id (event_id)
			) {$charset};"
		);

		$engagement = $wpdb->prefix . 'lnkup_packet_engagement';
		dbDelta(
			"CREATE TABLE {$engagement} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				packet_id bigint(20) unsigned NOT NULL DEFAULT 0,
				section_key varchar(40) NOT NULL DEFAULT '',
				action varchar(20) NOT NULL DEFAULT 'viewed',
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY packet_id (packet_id)
			) {$charset};"
		);

		$broadcasts = $wpdb->prefix . 'lnkup_broadcasts';
		dbDelta(
			"CREATE TABLE {$broadcasts} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				audience_filter longtext,
				template varchar(40) NOT NULL DEFAULT '',
				message text,
				sent_by bigint(20) unsigned NOT NULL DEFAULT 0,
				sent_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY event_id (event_id)
			) {$charset};"
		);
	}

	public static function booths_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_booths';
	}

	public static function parking_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_parking';
	}

	public static function loadin_windows_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_loadin_windows';
	}

	public static function loadin_assignments_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_loadin_assignments';
	}

	public static function checkins_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_checkins';
	}

	public static function support_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_support_requests';
	}

	public static function incidents_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_incidents';
	}

	public static function log_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_operations_log';
	}

	public static function staff_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_event_staff';
	}

	public static function packet_engagement_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_packet_engagement';
	}

	public static function broadcasts_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_broadcasts';
	}
}

<?php
/**
 * Vendor growth platform custom database tables.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Growth_Tables {

	public static function snapshots_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_growth_snapshots';
	}

	public static function benchmarks_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_marketplace_benchmarks';
	}

	public static function goals_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_vendor_goals';
	}

	public static function roi_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_vendor_roi';
	}

	public static function profit_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_vendor_profit_estimates';
	}

	public static function milestones_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_vendor_milestones';
	}

	public static function recommendations_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_vendor_recommendations';
	}

	public static function create_tables() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$snapshots = self::snapshots_table();
		dbDelta(
			"CREATE TABLE {$snapshots} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				vendor_user_id bigint(20) unsigned NOT NULL,
				snapshot_date date NOT NULL,
				metrics longtext NOT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY vendor_date (vendor_user_id, snapshot_date),
				KEY snapshot_date (snapshot_date)
			) {$charset};"
		);

		$benchmarks = self::benchmarks_table();
		dbDelta(
			"CREATE TABLE {$benchmarks} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				snapshot_date date NOT NULL,
				metric_key varchar(64) NOT NULL,
				p25 decimal(12,4) NOT NULL DEFAULT 0,
				p50 decimal(12,4) NOT NULL DEFAULT 0,
				p75 decimal(12,4) NOT NULL DEFAULT 0,
				p90 decimal(12,4) NOT NULL DEFAULT 0,
				sample_size int unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY date_metric (snapshot_date, metric_key)
			) {$charset};"
		);

		$goals = self::goals_table();
		dbDelta(
			"CREATE TABLE {$goals} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				vendor_user_id bigint(20) unsigned NOT NULL,
				goal_type varchar(64) NOT NULL,
				title varchar(255) NOT NULL DEFAULT '',
				target_value decimal(12,2) NOT NULL DEFAULT 0,
				current_value decimal(12,2) NOT NULL DEFAULT 0,
				unit varchar(32) NOT NULL DEFAULT '',
				deadline date DEFAULT NULL,
				status varchar(32) NOT NULL DEFAULT 'active',
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY vendor_user (vendor_user_id),
				KEY status (status)
			) {$charset};"
		);

		$roi = self::roi_table();
		dbDelta(
			"CREATE TABLE {$roi} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				vendor_user_id bigint(20) unsigned NOT NULL,
				application_id bigint(20) unsigned NOT NULL,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				gross_revenue decimal(12,2) NOT NULL DEFAULT 0,
				estimated_expenses decimal(12,2) NOT NULL DEFAULT 0,
				estimated_profit decimal(12,2) NOT NULL DEFAULT 0,
				profit_margin decimal(5,2) NOT NULL DEFAULT 0,
				worth_it_rating varchar(32) NOT NULL DEFAULT '',
				attendance int unsigned NOT NULL DEFAULT 0,
				travel_distance decimal(8,2) NOT NULL DEFAULT 0,
				time_invested_hours decimal(6,2) NOT NULL DEFAULT 0,
				organizer_rating decimal(3,2) NOT NULL DEFAULT 0,
				would_recommend tinyint(1) NOT NULL DEFAULT 0,
				repeat_potential varchar(32) NOT NULL DEFAULT '',
				notes text,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY vendor_app (vendor_user_id, application_id),
				KEY event_id (event_id)
			) {$charset};"
		);

		$profit = self::profit_table();
		dbDelta(
			"CREATE TABLE {$profit} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				vendor_user_id bigint(20) unsigned NOT NULL,
				application_id bigint(20) unsigned NOT NULL,
				booth_fee decimal(12,2) NOT NULL DEFAULT 0,
				travel_cost decimal(12,2) NOT NULL DEFAULT 0,
				hotel decimal(12,2) NOT NULL DEFAULT 0,
				food decimal(12,2) NOT NULL DEFAULT 0,
				gas decimal(12,2) NOT NULL DEFAULT 0,
				inventory_cost decimal(12,2) NOT NULL DEFAULT 0,
				staff_cost decimal(12,2) NOT NULL DEFAULT 0,
				marketing_cost decimal(12,2) NOT NULL DEFAULT 0,
				misc_expenses decimal(12,2) NOT NULL DEFAULT 0,
				gross_revenue decimal(12,2) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY vendor_app (vendor_user_id, application_id)
			) {$charset};"
		);

		$milestones = self::milestones_table();
		dbDelta(
			"CREATE TABLE {$milestones} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				vendor_user_id bigint(20) unsigned NOT NULL,
				milestone_key varchar(64) NOT NULL,
				title varchar(255) NOT NULL DEFAULT '',
				description text,
				achieved_at datetime NOT NULL,
				metadata longtext,
				PRIMARY KEY (id),
				UNIQUE KEY vendor_milestone (vendor_user_id, milestone_key),
				KEY achieved_at (achieved_at)
			) {$charset};"
		);

		$recs = self::recommendations_table();
		dbDelta(
			"CREATE TABLE {$recs} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				vendor_user_id bigint(20) unsigned NOT NULL,
				action_key varchar(64) NOT NULL,
				title varchar(255) NOT NULL,
				reason text NOT NULL,
				priority int NOT NULL DEFAULT 50,
				source varchar(32) NOT NULL DEFAULT 'rules',
				dismissed_at datetime DEFAULT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				expires_at datetime DEFAULT NULL,
				PRIMARY KEY (id),
				KEY vendor_active (vendor_user_id, dismissed_at),
				KEY action_key (action_key)
			) {$charset};"
		);
	}
}

<?php
/**
 * Event intelligence custom database tables.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Intelligence_Tables {

	public static function create_tables() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$snapshots = $wpdb->prefix . 'lnkup_metric_snapshots';
		dbDelta(
			"CREATE TABLE {$snapshots} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				scope_type varchar(20) NOT NULL DEFAULT 'event',
				scope_id bigint(20) unsigned NOT NULL DEFAULT 0,
				snapshot_date date NOT NULL,
				metrics longtext,
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY scope_date (scope_type, scope_id, snapshot_date),
				KEY snapshot_date (snapshot_date)
			) {$charset};"
		);

		$event_scores = $wpdb->prefix . 'lnkup_event_scores';
		dbDelta(
			"CREATE TABLE {$event_scores} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				score smallint NOT NULL DEFAULT 0,
				label varchar(40) NOT NULL DEFAULT '',
				breakdown longtext,
				computed_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY event_id (event_id),
				KEY computed_at (computed_at)
			) {$charset};"
		);

		$opp_scores = $wpdb->prefix . 'lnkup_opportunity_scores';
		dbDelta(
			"CREATE TABLE {$opp_scores} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				opportunity_id bigint(20) unsigned NOT NULL DEFAULT 0,
				quality_score smallint NOT NULL DEFAULT 0,
				label varchar(40) NOT NULL DEFAULT '',
				issues longtext,
				computed_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY opportunity_id (opportunity_id)
			) {$charset};"
		);

		$market = $wpdb->prefix . 'lnkup_market_snapshots';
		dbDelta(
			"CREATE TABLE {$market} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				snapshot_date date NOT NULL,
				metrics longtext,
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY snapshot_date (snapshot_date)
			) {$charset};"
		);

		$risks = $wpdb->prefix . 'lnkup_risk_alerts';
		dbDelta(
			"CREATE TABLE {$risks} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				scope_type varchar(20) NOT NULL DEFAULT 'event',
				scope_id bigint(20) unsigned NOT NULL DEFAULT 0,
				level varchar(20) NOT NULL DEFAULT 'medium',
				category varchar(40) NOT NULL DEFAULT '',
				message text NOT NULL,
				action_text text,
				action_key varchar(60) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'open',
				deadline_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY scope (scope_type, scope_id),
				KEY status (status)
			) {$charset};"
		);

		$recs = $wpdb->prefix . 'lnkup_recommendations';
		dbDelta(
			"CREATE TABLE {$recs} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				scope_type varchar(20) NOT NULL DEFAULT 'event',
				scope_id bigint(20) unsigned NOT NULL DEFAULT 0,
				priority varchar(20) NOT NULL DEFAULT 'medium',
				action_key varchar(60) NOT NULL DEFAULT '',
				reason text,
				payload longtext,
				status varchar(20) NOT NULL DEFAULT 'open',
				expires_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY user_scope (user_id, scope_type, scope_id),
				KEY status (status)
			) {$charset};"
		);

		$reports = $wpdb->prefix . 'lnkup_event_reports';
		dbDelta(
			"CREATE TABLE {$reports} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				organizer_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				report longtext,
				generated_at datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY event_id (event_id)
			) {$charset};"
		);
	}

	public static function metric_snapshots_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_metric_snapshots';
	}

	public static function event_scores_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_event_scores';
	}

	public static function opportunity_scores_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_opportunity_scores';
	}

	public static function market_snapshots_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_market_snapshots';
	}

	public static function risk_alerts_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_risk_alerts';
	}

	public static function recommendations_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_recommendations';
	}

	public static function event_reports_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_event_reports';
	}
}

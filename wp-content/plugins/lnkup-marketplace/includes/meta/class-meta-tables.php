<?php
/**
 * Tables for the conversion outbox, Meta delivery log, Dataset Quality snapshots and alerts.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Meta_Tables {

	public static function conversions(): string {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_conversion_events';
	}

	public static function deliveries(): string {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_meta_deliveries';
	}

	public static function snapshots(): string {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_meta_quality_snapshots';
	}

	public static function alerts(): string {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_meta_alerts';
	}

	public static function create_tables(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		add_option( 'lnkup_meta_installed_at', time(), '', false );

		// request_context holds IP / user agent / fbp / fbc only until the event is sent or expires.
		dbDelta(
			'CREATE TABLE ' . self::conversions() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id char(36) NOT NULL,
			dedupe_key varchar(191) NOT NULL,
			event_name varchar(64) NOT NULL,
			schema_version smallint(5) unsigned NOT NULL DEFAULT 1,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			role varchar(20) NOT NULL DEFAULT '',
			entity_type varchar(32) NOT NULL DEFAULT '',
			entity_id bigint(20) unsigned NOT NULL DEFAULT 0,
			source_platform varchar(10) NOT NULL DEFAULT 'server',
			action_source varchar(20) NOT NULL DEFAULT 'website',
			event_source_url varchar(500) NOT NULL DEFAULT '',
			event_time int(10) unsigned NOT NULL,
			custom_data text,
			request_context text,
			attribution_json text,
			match_presence varchar(191) NOT NULL DEFAULT '',
			consent_state varchar(20) NOT NULL DEFAULT 'granted',
			value_internal decimal(12,2) DEFAULT NULL,
			status varchar(12) NOT NULL DEFAULT 'pending',
			browser_status varchar(12) NOT NULL DEFAULT 'none',
			solo tinyint(1) NOT NULL DEFAULT 0,
			attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
			next_attempt_at datetime DEFAULT NULL,
			claim_token varchar(32) NOT NULL DEFAULT '',
			claimed_at datetime DEFAULT NULL,
			sent_at datetime DEFAULT NULL,
			last_error_code varchar(64) NOT NULL DEFAULT '',
			last_error varchar(500) NOT NULL DEFAULT '',
			is_test tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY dedupe_key (dedupe_key),
			UNIQUE KEY event_id (event_id),
			KEY status_next (status,next_attempt_at),
			KEY name_created (event_name,created_at),
			KEY user_id (user_id),
			KEY claim_token (claim_token),
			KEY created_at (created_at)
		) {$charset};"
		);

		dbDelta(
			'CREATE TABLE ' . self::deliveries() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			batch_id varchar(32) NOT NULL DEFAULT '',
			api varchar(20) NOT NULL DEFAULT 'capi',
			event_count smallint(5) unsigned NOT NULL DEFAULT 0,
			http_status smallint(5) unsigned NOT NULL DEFAULT 0,
			events_received smallint(5) unsigned NOT NULL DEFAULT 0,
			fbtrace_id varchar(64) NOT NULL DEFAULT '',
			error_code varchar(64) NOT NULL DEFAULT '',
			error_message varchar(500) NOT NULL DEFAULT '',
			is_test tinyint(1) NOT NULL DEFAULT 0,
			duration_ms int(10) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY api_created (api,created_at)
		) {$charset};"
		);

		dbDelta(
			'CREATE TABLE ' . self::snapshots() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			snapshot_date date NOT NULL,
			event_name varchar(64) NOT NULL,
			metric varchar(64) NOT NULL,
			value decimal(10,2) DEFAULT NULL,
			detail text,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY snapshot (snapshot_date,event_name,metric),
			KEY event_metric (event_name,metric)
		) {$charset};"
		);

		dbDelta(
			'CREATE TABLE ' . self::alerts() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			alert_key varchar(64) NOT NULL,
			severity varchar(10) NOT NULL DEFAULT 'medium',
			title varchar(191) NOT NULL DEFAULT '',
			affected_events varchar(255) NOT NULL DEFAULT '',
			evidence text,
			suspected_cause text,
			recommended_action text,
			status varchar(12) NOT NULL DEFAULT 'open',
			first_seen datetime NOT NULL,
			last_seen datetime NOT NULL,
			notified_at datetime DEFAULT NULL,
			resolved_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY key_status (alert_key,status),
			KEY status (status)
		) {$charset};"
		);
	}
}

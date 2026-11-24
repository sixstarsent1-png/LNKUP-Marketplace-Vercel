<?php
/**
 * Marketing OS tables (additive dbDelta; nothing is dropped or rewritten).
 *
 * - lnkup_tracking_links        one row per tracked link (target resolved by ID, never a URL)
 * - lnkup_tracking_link_daily   clicks / unique clicks / signups / applications / bookings per day
 * - lnkup_analytics_daily       daily rollup of lnkup_analytics_events (kept after raw purge)
 * - lnkup_ads_outbox            GA4 / TikTok server events awaiting dispatch
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Marketing_Tables {

	const SCHEMA_VERSION = '1.0.0';
	const SCHEMA_OPTION  = 'lnkup_marketing_schema';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'maybe_upgrade' ), 5 );
	}

	public static function links(): string {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_tracking_links';
	}

	public static function link_daily(): string {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_tracking_link_daily';
	}

	public static function analytics_daily(): string {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_analytics_daily';
	}

	public static function ads_outbox(): string {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_ads_outbox';
	}

	public static function maybe_upgrade(): void {
		if ( get_option( self::SCHEMA_OPTION ) === self::SCHEMA_VERSION ) {
			return;
		}
		self::create_tables();
	}

	public static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();

		dbDelta(
			'CREATE TABLE ' . self::links() . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				code varchar(16) NOT NULL,
				target_type varchar(20) NOT NULL DEFAULT '',
				target_id bigint(20) unsigned NOT NULL DEFAULT 0,
				target_key varchar(100) NOT NULL DEFAULT '',
				owner_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				owner_type varchar(20) NOT NULL DEFAULT '',
				owner_ref_id bigint(20) unsigned NOT NULL DEFAULT 0,
				label varchar(120) NOT NULL DEFAULT '',
				channel varchar(40) NOT NULL DEFAULT '',
				campaign varchar(100) NOT NULL DEFAULT '',
				utm_source varchar(100) NOT NULL DEFAULT '',
				utm_medium varchar(100) NOT NULL DEFAULT '',
				utm_campaign varchar(100) NOT NULL DEFAULT '',
				utm_content varchar(100) NOT NULL DEFAULT '',
				ref_param varchar(10) NOT NULL DEFAULT '',
				ref_code varchar(64) NOT NULL DEFAULT '',
				status varchar(16) NOT NULL DEFAULT 'active',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY code (code),
				KEY owner (owner_user_id,status),
				KEY target (target_type,target_id)
			) {$c};"
		);
		dbDelta(
			'CREATE TABLE ' . self::link_daily() . " (
				link_id bigint(20) unsigned NOT NULL,
				day date NOT NULL,
				clicks int(10) unsigned NOT NULL DEFAULT 0,
				unique_clicks int(10) unsigned NOT NULL DEFAULT 0,
				bot_clicks int(10) unsigned NOT NULL DEFAULT 0,
				signups int(10) unsigned NOT NULL DEFAULT 0,
				applications int(10) unsigned NOT NULL DEFAULT 0,
				bookings int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (link_id,day),
				KEY day (day)
			) {$c};"
		);
		dbDelta(
			'CREATE TABLE ' . self::analytics_daily() . " (
				day date NOT NULL,
				event_type varchar(50) NOT NULL,
				events int(10) unsigned NOT NULL DEFAULT 0,
				users int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (day,event_type),
				KEY event_type (event_type)
			) {$c};"
		);
		dbDelta(
			'CREATE TABLE ' . self::ads_outbox() . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				platform varchar(16) NOT NULL,
				event_id varchar(64) NOT NULL,
				event_name varchar(64) NOT NULL,
				payload longtext,
				status varchar(16) NOT NULL DEFAULT 'pending',
				attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
				last_error varchar(191) NOT NULL DEFAULT '',
				is_test tinyint(1) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				sent_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY platform_event (platform,event_id),
				KEY status (status,created_at)
			) {$c};"
		);
		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
	}
}

<?php
/**
 * Matching & Discovery OS tables (derived indexes, caches, recruitment pipeline).
 *
 * Canonical records stay in CPTs + post meta; these tables are rebuildable.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Match_Tables {

	/**
	 * Prefixed table name.
	 *
	 * @param string $name Short name.
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_' . $name;
	}

	public static function vendor_index_table() {
		return self::table( 'match_vendor_index' );
	}

	public static function vendor_categories_table() {
		return self::table( 'match_vendor_categories' );
	}

	public static function opportunity_categories_table() {
		return self::table( 'match_opportunity_categories' );
	}

	public static function results_table() {
		return self::table( 'match_results' );
	}

	public static function dirty_table() {
		return self::table( 'match_dirty' );
	}

	public static function pipeline_table() {
		return self::table( 'recruitment_pipeline' );
	}

	public static function jobs_table() {
		return self::table( 'recruitment_jobs' );
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $table Full table name.
	 */
	public static function exists( $table ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * Create or upgrade all matching tables.
	 */
	public static function create_tables() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$vendor_index = self::vendor_index_table();
		dbDelta(
			"CREATE TABLE {$vendor_index} (
			profile_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			discoverable tinyint(1) NOT NULL DEFAULT 1,
			accept_invites tinyint(1) NOT NULL DEFAULT 1,
			status varchar(20) NOT NULL DEFAULT 'active',
			city varchar(100) NOT NULL DEFAULT '',
			state varchar(10) NOT NULL DEFAULT '',
			zip varchar(12) NOT NULL DEFAULT '',
			lat decimal(6,2) DEFAULT NULL,
			lng decimal(6,2) DEFAULT NULL,
			radius_miles int(11) NOT NULL DEFAULT 0,
			travel_national tinyint(1) NOT NULL DEFAULT 0,
			cities_served text,
			states_served text,
			event_types text,
			fee_max decimal(10,2) DEFAULT NULL,
			env_pref varchar(20) NOT NULL DEFAULT '',
			available_months varchar(64) NOT NULL DEFAULT '',
			primary_category bigint(20) unsigned NOT NULL DEFAULT 0,
			verified tinyint(1) NOT NULL DEFAULT 0,
			profile_completeness tinyint(3) unsigned NOT NULL DEFAULT 0,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (profile_id),
			KEY user_id (user_id),
			KEY state_city (state, city),
			KEY geo (lat, lng),
			KEY discoverable (discoverable, status)
		) {$charset};"
		);

		$vendor_cats = self::vendor_categories_table();
		dbDelta(
			"CREATE TABLE {$vendor_cats} (
			profile_id bigint(20) unsigned NOT NULL,
			term_id bigint(20) unsigned NOT NULL,
			group_key varchar(64) NOT NULL DEFAULT '',
			PRIMARY KEY  (profile_id, term_id),
			KEY term_id (term_id),
			KEY group_key (group_key)
		) {$charset};"
		);

		$opp_cats = self::opportunity_categories_table();
		dbDelta(
			"CREATE TABLE {$opp_cats} (
			opportunity_id bigint(20) unsigned NOT NULL,
			term_id bigint(20) unsigned NOT NULL,
			group_key varchar(64) NOT NULL DEFAULT '',
			PRIMARY KEY  (opportunity_id, term_id),
			KEY term_id (term_id),
			KEY group_key (group_key)
		) {$charset};"
		);

		$results = self::results_table();
		dbDelta(
			"CREATE TABLE {$results} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			profile_id bigint(20) unsigned NOT NULL,
			opportunity_id bigint(20) unsigned NOT NULL,
			event_id bigint(20) unsigned NOT NULL DEFAULT 0,
			tour_id bigint(20) unsigned NOT NULL DEFAULT 0,
			score tinyint(3) unsigned NOT NULL DEFAULT 0,
			confidence tinyint(3) unsigned NOT NULL DEFAULT 0,
			eligible tinyint(1) NOT NULL DEFAULT 0,
			block_reasons text,
			factors text,
			reasons text,
			computed_at datetime NOT NULL,
			first_matched_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY profile_opp (profile_id, opportunity_id),
			KEY opp_score (opportunity_id, eligible, score),
			KEY profile_score (profile_id, eligible, score),
			KEY event_id (event_id),
			KEY tour_id (tour_id)
		) {$charset};"
		);

		$dirty = self::dirty_table();
		dbDelta(
			"CREATE TABLE {$dirty} (
			entity_type varchar(20) NOT NULL,
			entity_id bigint(20) unsigned NOT NULL,
			queued_at datetime NOT NULL,
			PRIMARY KEY  (entity_type, entity_id),
			KEY queued_at (queued_at)
		) {$charset};"
		);

		$pipeline = self::pipeline_table();
		dbDelta(
			"CREATE TABLE {$pipeline} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			organizer_user_id bigint(20) unsigned NOT NULL,
			event_id bigint(20) unsigned NOT NULL,
			opportunity_id bigint(20) unsigned NOT NULL DEFAULT 0,
			profile_id bigint(20) unsigned NOT NULL,
			vendor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			stage varchar(20) NOT NULL DEFAULT 'identified',
			source varchar(20) NOT NULL DEFAULT 'manual',
			invite_id bigint(20) unsigned NOT NULL DEFAULT 0,
			application_id bigint(20) unsigned NOT NULL DEFAULT 0,
			stage_at text,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY org_event_profile (organizer_user_id, event_id, profile_id),
			KEY event_stage (event_id, stage),
			KEY vendor_user_id (vendor_user_id),
			KEY invite_id (invite_id),
			KEY application_id (application_id)
		) {$charset};"
		);

		$jobs = self::jobs_table();
		dbDelta(
			"CREATE TABLE {$jobs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			organizer_user_id bigint(20) unsigned NOT NULL,
			event_id bigint(20) unsigned NOT NULL,
			opportunity_id bigint(20) unsigned NOT NULL DEFAULT 0,
			message text,
			recipients longtext,
			total int(11) NOT NULL DEFAULT 0,
			sent int(11) NOT NULL DEFAULT 0,
			suppressed int(11) NOT NULL DEFAULT 0,
			failed int(11) NOT NULL DEFAULT 0,
			suppression_reasons text,
			status varchar(20) NOT NULL DEFAULT 'queued',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY organizer_status (organizer_user_id, status),
			KEY status (status)
		) {$charset};"
		);
	}
}

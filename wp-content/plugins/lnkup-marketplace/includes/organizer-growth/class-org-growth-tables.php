<?php
/**
 * Organizer Acquisition OS tables.
 *
 * Leads are organizer *organizations* (prospect → active). Organizer accounts stay WordPress
 * users and are linked through `org_lead_accounts`, never duplicated.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Org_Growth_Tables {

	const SCHEMA_VERSION = '1.1.0';
	const SCHEMA_OPTION  = 'lnkup_org_growth_schema';

	const LEADS       = 'lnkup_org_leads';
	const LEAD_EVENTS = 'lnkup_org_lead_events';
	const ACCOUNTS    = 'lnkup_org_lead_accounts';
	const ACTIVITY    = 'lnkup_org_lead_activity';
	const TASKS       = 'lnkup_org_lead_tasks';
	const TAGS        = 'lnkup_org_lead_tags';
	const VIEWS       = 'lnkup_org_lead_saved_views';
	const IMPORTS     = 'lnkup_org_lead_imports';
	const IMPORT_ROWS = 'lnkup_org_lead_import_rows';
	const MILESTONES  = 'lnkup_org_lead_milestones';
	const PARTNERS    = 'lnkup_org_partners';
	const TEMPLATES   = 'lnkup_org_outreach_templates';
	const SUPPRESSION = 'lnkup_contact_suppression';
	const SPEND       = 'lnkup_org_growth_spend';

	public static function table( string $suffix ): string {
		global $wpdb;
		return $wpdb->prefix . $suffix;
	}

	public static function exists( string $suffix ): bool {
		global $wpdb;
		$name = self::table( $suffix );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) === $name;
	}

	/**
	 * Run dbDelta when the stored schema version is behind.
	 */
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
			'CREATE TABLE ' . self::table( self::LEADS ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(191) NOT NULL DEFAULT '',
				name_key varchar(191) NOT NULL DEFAULT '',
				org_type varchar(24) NOT NULL DEFAULT 'organizer_org',
				parent_lead_id bigint(20) unsigned NOT NULL DEFAULT 0,
				partner_id bigint(20) unsigned NOT NULL DEFAULT 0,
				category varchar(64) NOT NULL DEFAULT '',
				website varchar(255) NOT NULL DEFAULT '',
				domain varchar(191) NOT NULL DEFAULT '',
				email varchar(191) NOT NULL DEFAULT '',
				phone varchar(48) NOT NULL DEFAULT '',
				instagram varchar(100) NOT NULL DEFAULT '',
				facebook varchar(255) NOT NULL DEFAULT '',
				socials longtext,
				city varchar(100) NOT NULL DEFAULT '',
				state varchar(32) NOT NULL DEFAULT '',
				service_area varchar(255) NOT NULL DEFAULT '',
				description text,
				event_frequency varchar(24) NOT NULL DEFAULT '',
				cities_served varchar(255) NOT NULL DEFAULT '',
				event_categories varchar(255) NOT NULL DEFAULT '',
				estimated_booths int(11) NOT NULL DEFAULT 0,
				application_process varchar(255) NOT NULL DEFAULT '',
				next_event_date date DEFAULT NULL,
				application_url varchar(255) NOT NULL DEFAULT '',
				source_verified_at datetime DEFAULT NULL,
				source varchar(64) NOT NULL DEFAULT '',
				source_campaign varchar(100) NOT NULL DEFAULT '',
				source_detail varchar(255) NOT NULL DEFAULT '',
				referral_code varchar(32) NOT NULL DEFAULT '',
				referrer_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				assigned_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				assigned_connector_id bigint(20) unsigned NOT NULL DEFAULT 0,
				priority varchar(8) NOT NULL DEFAULT '',
				stage varchar(32) NOT NULL DEFAULT 'discovered',
				outcome varchar(32) NOT NULL DEFAULT '',
				last_contacted_at datetime DEFAULT NULL,
				next_follow_up_at datetime DEFAULT NULL,
				contact_preference varchar(24) NOT NULL DEFAULT '',
				qualification_notes text,
				objections text,
				score int(11) NOT NULL DEFAULT 0,
				tier varchar(2) NOT NULL DEFAULT '',
				score_explanation longtext,
				scored_at datetime DEFAULT NULL,
				is_house_affiliated tinyint(1) NOT NULL DEFAULT 0,
				possible_duplicate_of bigint(20) unsigned NOT NULL DEFAULT 0,
				merged_into_id bigint(20) unsigned NOT NULL DEFAULT 0,
				import_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY stage (stage),
				KEY outcome (outcome),
				KEY assigned_user_id (assigned_user_id),
				KEY assigned_connector_id (assigned_connector_id),
				KEY city_state (state, city),
				KEY email (email),
				KEY domain (domain),
				KEY instagram (instagram),
				KEY name_key (name_key),
				KEY score (score),
				KEY next_follow_up_at (next_follow_up_at),
				KEY parent_lead_id (parent_lead_id),
				KEY partner_id (partner_id),
				KEY import_id (import_id),
				KEY merged_into_id (merged_into_id)
			) {$c};"
		);

		dbDelta(
			'CREATE TABLE ' . self::table( self::LEAD_EVENTS ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				lead_id bigint(20) unsigned NOT NULL DEFAULT 0,
				name varchar(191) NOT NULL DEFAULT '',
				url varchar(255) NOT NULL DEFAULT '',
				event_date date DEFAULT NULL,
				city varchar(100) NOT NULL DEFAULT '',
				state varchar(32) NOT NULL DEFAULT '',
				category varchar(64) NOT NULL DEFAULT '',
				verified_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY lead_id (lead_id)
			) {$c};"
		);

		dbDelta(
			'CREATE TABLE ' . self::table( self::ACCOUNTS ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				lead_id bigint(20) unsigned NOT NULL DEFAULT 0,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				link_method varchar(32) NOT NULL DEFAULT '',
				status varchar(16) NOT NULL DEFAULT 'linked',
				linked_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY user_id (user_id),
				KEY lead_id (lead_id),
				KEY status (status)
			) {$c};"
		);

		dbDelta(
			'CREATE TABLE ' . self::table( self::ACTIVITY ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				lead_id bigint(20) unsigned NOT NULL DEFAULT 0,
				type varchar(32) NOT NULL DEFAULT '',
				channel varchar(24) NOT NULL DEFAULT '',
				outcome varchar(32) NOT NULL DEFAULT '',
				summary varchar(255) NOT NULL DEFAULT '',
				payload longtext,
				actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				occurred_at datetime NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY lead_type (lead_id, type),
				KEY occurred_at (occurred_at)
			) {$c};"
		);

		dbDelta(
			'CREATE TABLE ' . self::table( self::TASKS ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				lead_id bigint(20) unsigned NOT NULL DEFAULT 0,
				type varchar(24) NOT NULL DEFAULT 'follow_up',
				title varchar(191) NOT NULL DEFAULT '',
				notes text,
				assigned_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				due_at datetime DEFAULT NULL,
				status varchar(16) NOT NULL DEFAULT 'open',
				completed_at datetime DEFAULT NULL,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY lead_id (lead_id),
				KEY assignee_status_due (assigned_user_id, status, due_at)
			) {$c};"
		);

		dbDelta(
			'CREATE TABLE ' . self::table( self::TAGS ) . " (
				lead_id bigint(20) unsigned NOT NULL DEFAULT 0,
				tag varchar(64) NOT NULL DEFAULT '',
				PRIMARY KEY  (lead_id, tag),
				KEY tag (tag)
			) {$c};"
		);

		dbDelta(
			'CREATE TABLE ' . self::table( self::VIEWS ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				name varchar(100) NOT NULL DEFAULT '',
				filters longtext,
				is_shared tinyint(1) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY user_id (user_id)
			) {$c};"
		);

		dbDelta(
			'CREATE TABLE ' . self::table( self::IMPORTS ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				file_name varchar(191) NOT NULL DEFAULT '',
				source_label varchar(100) NOT NULL DEFAULT '',
				verified_at datetime DEFAULT NULL,
				mapping longtext,
				status varchar(20) NOT NULL DEFAULT 'uploaded',
				total_rows int(11) NOT NULL DEFAULT 0,
				processed_rows int(11) NOT NULL DEFAULT 0,
				created_count int(11) NOT NULL DEFAULT 0,
				duplicate_count int(11) NOT NULL DEFAULT 0,
				error_count int(11) NOT NULL DEFAULT 0,
				rolled_back_count int(11) NOT NULL DEFAULT 0,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY status (status)
			) {$c};"
		);

		dbDelta(
			'CREATE TABLE ' . self::table( self::IMPORT_ROWS ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				import_id bigint(20) unsigned NOT NULL DEFAULT 0,
				row_num int(11) NOT NULL DEFAULT 0,
				raw longtext,
				status varchar(20) NOT NULL DEFAULT 'pending',
				lead_id bigint(20) unsigned NOT NULL DEFAULT 0,
				duplicate_of bigint(20) unsigned NOT NULL DEFAULT 0,
				match_reason varchar(64) NOT NULL DEFAULT '',
				message varchar(255) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY import_status (import_id, status)
			) {$c};"
		);

		dbDelta(
			'CREATE TABLE ' . self::table( self::MILESTONES ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				lead_id bigint(20) unsigned NOT NULL DEFAULT 0,
				milestone varchar(32) NOT NULL DEFAULT '',
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				source_object_id bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(16) NOT NULL DEFAULT 'active',
				achieved_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY lead_milestone (lead_id, milestone),
				KEY milestone (milestone, achieved_at)
			) {$c};"
		);

		dbDelta(
			'CREATE TABLE ' . self::table( self::PARTNERS ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(191) NOT NULL DEFAULT '',
				partner_type varchar(48) NOT NULL DEFAULT '',
				contact_name varchar(191) NOT NULL DEFAULT '',
				contact_email varchar(191) NOT NULL DEFAULT '',
				agreement_status varchar(24) NOT NULL DEFAULT 'none',
				referral_arrangement text,
				owner_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				notes text,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id)
			) {$c};"
		);

		dbDelta(
			'CREATE TABLE ' . self::table( self::TEMPLATES ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				template_key varchar(64) NOT NULL DEFAULT '',
				name varchar(191) NOT NULL DEFAULT '',
				subject varchar(191) NOT NULL DEFAULT '',
				body longtext,
				required_fields varchar(255) NOT NULL DEFAULT '',
				fallback_key varchar(64) NOT NULL DEFAULT '',
				is_active tinyint(1) NOT NULL DEFAULT 1,
				is_seeded tinyint(1) NOT NULL DEFAULT 0,
				updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY template_key (template_key)
			) {$c};"
		);

		dbDelta(
			'CREATE TABLE ' . self::table( self::SUPPRESSION ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				scope varchar(8) NOT NULL DEFAULT 'email',
				value_hash char(64) NOT NULL DEFAULT '',
				reason varchar(24) NOT NULL DEFAULT 'manual',
				source varchar(64) NOT NULL DEFAULT '',
				lead_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY scope_value (scope, value_hash)
			) {$c};"
		);

		dbDelta(
			'CREATE TABLE ' . self::table( self::SPEND ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				spent_on date NOT NULL,
				channel varchar(64) NOT NULL DEFAULT '',
				campaign varchar(100) NOT NULL DEFAULT '',
				city varchar(100) NOT NULL DEFAULT '',
				state varchar(32) NOT NULL DEFAULT '',
				amount_cents bigint(20) NOT NULL DEFAULT 0,
				description varchar(255) NOT NULL DEFAULT '',
				entered_by bigint(20) unsigned NOT NULL DEFAULT 0,
				voided_at datetime DEFAULT NULL,
				void_reason varchar(255) NOT NULL DEFAULT '',
				voided_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY channel_date (channel, spent_on),
				KEY spent_on (spent_on)
			) {$c};"
		);

		if ( class_exists( 'LNKup_Outreach_Tables' ) ) {
			$rec = LNKup_Outreach_Tables::table_name( LNKup_Outreach_Tables::RECIPIENTS );
			$cam = LNKup_Outreach_Tables::table_name( LNKup_Outreach_Tables::CAMPAIGNS );
			self::add_column( $rec, 'lead_id', 'bigint(20) unsigned NOT NULL DEFAULT 0', 'campaign_id' );
			self::add_column( $rec, 'template_key', "varchar(64) NOT NULL DEFAULT ''", 'lead_id' );
			self::add_column( $rec, 'skip_reason', "varchar(64) NOT NULL DEFAULT ''", 'error_message' );
			self::add_column( $cam, 'template_key', "varchar(64) NOT NULL DEFAULT ''", 'message' );
			self::add_column( $cam, 'approval_status', "varchar(20) NOT NULL DEFAULT ''", 'status' );
			self::add_column( $cam, 'approved_by', 'bigint(20) unsigned NOT NULL DEFAULT 0', 'approval_status' );
			self::add_column( $cam, 'daily_cap', 'int(11) NOT NULL DEFAULT 0', 'approved_by' );
			self::add_index( $rec, 'lead_id', 'lead_id' );
		}

		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Additive column on a table owned by another class (no dbDelta re-declaration needed).
	 */
	private static function add_column( string $table, string $column, string $definition, string $after ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) );
		if ( $has ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$column} {$definition} AFTER {$after}" );
	}

	private static function add_index( string $table, string $index, string $column ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has = $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", $index ) );
		if ( $has ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE {$table} ADD KEY {$index} ({$column})" );
	}
}

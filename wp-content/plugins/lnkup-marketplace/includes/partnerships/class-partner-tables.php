<?php
/**
 * Partnership OS tables.
 *
 * Programs, versioned commission rules, connector territories and the review queue. Referral,
 * code and reward rows stay in the treasury tables; this class only adds columns to them.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Partner_Tables {

	const SCHEMA_VERSION = '1.1.1';
	const SCHEMA_OPTION  = 'lnkup_partner_schema';

	const PROGRAMS    = 'lnkup_partner_programs';
	const RULES       = 'lnkup_commission_rules';
	const TERRITORIES = 'lnkup_connector_territories';
	const REVIEWS     = 'lnkup_partner_reviews';

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
	 * Run migrations when the stored schema version is behind.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::SCHEMA_OPTION ) === self::SCHEMA_VERSION ) {
			return;
		}
		if ( ! class_exists( 'LNKup_Treasury_Tables' ) || ! LNKup_Financial_Tables::table_exists( LNKup_Treasury_Tables::referral_rewards_table() ) ) {
			return;
		}
		self::create_tables();
		self::migrate_columns();
		self::backfill_idempotency_keys();
		LNKup_Partner_Programs::seed();
		LNKup_Commission_Rules::seed();
		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
	}

	public static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();

		dbDelta(
			'CREATE TABLE ' . self::table( self::PROGRAMS ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				slug varchar(32) NOT NULL DEFAULT '',
				name varchar(100) NOT NULL DEFAULT '',
				status varchar(16) NOT NULL DEFAULT 'disabled',
				settings longtext,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY slug (slug)
			) {$c};"
		);

		dbDelta(
			'CREATE TABLE ' . self::table( self::RULES ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				program varchar(32) NOT NULL DEFAULT '',
				version int(11) NOT NULL DEFAULT 1,
				name varchar(100) NOT NULL DEFAULT '',
				source varchar(16) NOT NULL DEFAULT 'custom',
				basis varchar(16) NOT NULL DEFAULT 'platform_fee',
				rate decimal(6,2) NOT NULL DEFAULT 0.00,
				fixed_amount decimal(14,2) NOT NULL DEFAULT 0.00,
				cap decimal(14,2) NOT NULL DEFAULT 0.00,
				term_months int(11) NOT NULL DEFAULT 12,
				hold_days int(11) NOT NULL DEFAULT 0,
				qualifying_event varchar(32) NOT NULL DEFAULT 'paid_booking',
				target_role varchar(16) NOT NULL DEFAULT '',
				stacking_policy varchar(24) NOT NULL DEFAULT 'exclusive',
				requires_approval tinyint(1) NOT NULL DEFAULT 1,
				agreement_ref varchar(191) NOT NULL DEFAULT '',
				status varchar(16) NOT NULL DEFAULT 'draft',
				effective_from datetime DEFAULT NULL,
				effective_to datetime DEFAULT NULL,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY program_version (program, version),
				KEY program_status_from (program, status, effective_from)
			) {$c};"
		);

		dbDelta(
			'CREATE TABLE ' . self::table( self::TERRITORIES ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				connector_id bigint(20) unsigned NOT NULL DEFAULT 0,
				scope_type varchar(16) NOT NULL DEFAULT 'city',
				value varchar(128) NOT NULL DEFAULT '',
				assigned_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY connector_scope_value (connector_id, scope_type, value),
				KEY scope_value (scope_type, value)
			) {$c};"
		);

		dbDelta(
			'CREATE TABLE ' . self::table( self::REVIEWS ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				type varchar(24) NOT NULL DEFAULT '',
				dedupe_key varchar(96) NOT NULL DEFAULT '',
				referred_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				referral_ids varchar(255) NOT NULL DEFAULT '',
				reward_id bigint(20) unsigned NOT NULL DEFAULT 0,
				payment_id bigint(20) unsigned NOT NULL DEFAULT 0,
				amount decimal(14,2) NOT NULL DEFAULT 0.00,
				status varchar(16) NOT NULL DEFAULT 'open',
				resolution varchar(32) NOT NULL DEFAULT '',
				winner_referral_id bigint(20) unsigned NOT NULL DEFAULT 0,
				payload longtext,
				notes text,
				resolved_by bigint(20) unsigned NOT NULL DEFAULT 0,
				resolved_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY dedupe_key (dedupe_key),
				KEY type_status (type, status),
				KEY referred_user_id (referred_user_id),
				KEY reward_id (reward_id)
			) {$c};"
		);
	}

	/**
	 * Additive columns on treasury and connector tables.
	 */
	public static function migrate_columns(): void {
		global $wpdb;
		$refs    = LNKup_Treasury_Tables::referrals_table();
		$rewards = LNKup_Treasury_Tables::referral_rewards_table();

		self::add_column( $refs, 'attribution_status', "varchar(16) NOT NULL DEFAULT ''" );
		self::add_column( $refs, 'rule_id', 'bigint(20) unsigned NOT NULL DEFAULT 0' );
		self::add_index( $refs, 'referred_user_id', 'referred_user_id' );
		self::add_index( $refs, 'attribution_status', 'attribution_status' );

		self::add_column( $rewards, 'reversed_amount', 'decimal(14,2) NOT NULL DEFAULT 0.00' );
		self::add_column( $rewards, 'rule_id', 'bigint(20) unsigned NOT NULL DEFAULT 0' );
		self::add_column( $rewards, 'rule_version', 'int(11) NOT NULL DEFAULT 0' );
		self::add_column( $rewards, 'basis', "varchar(16) NOT NULL DEFAULT ''" );
		self::add_column( $rewards, 'basis_amount', 'decimal(14,2) NOT NULL DEFAULT 0.00' );
		self::add_column( $rewards, 'currency', "varchar(3) NOT NULL DEFAULT 'USD'" );
		self::add_column( $rewards, 'payout_reference', "varchar(128) NOT NULL DEFAULT ''" );
		self::add_column( $rewards, 'hold_reason', "varchar(64) NOT NULL DEFAULT ''" );
		self::add_column( $rewards, 'held_from_status', "varchar(32) NOT NULL DEFAULT ''" );
		self::add_column( $rewards, 'idempotency_key', 'varchar(191) NULL DEFAULT NULL' );
		self::add_column( $rewards, 'program', "varchar(32) NOT NULL DEFAULT ''" );
		// Rows inserted by code that sets no key must stay NULL so the unique index never rejects them.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE {$rewards} MODIFY COLUMN idempotency_key varchar(191) NULL DEFAULT NULL" );
		self::add_index( $rewards, 'payment_id', 'payment_id' );
		self::add_index( $rewards, 'program_status', 'program, reward_status' );

		if ( class_exists( 'LNKup_Connector_Tables' ) ) {
			$connectors = LNKup_Connector_Tables::connectors_table();
			self::add_column( $connectors, 'commission_rule_id', 'bigint(20) unsigned NOT NULL DEFAULT 0' );
			self::add_column( $connectors, 'program', "varchar(32) NOT NULL DEFAULT 'city_connector'" );
			self::add_column( $connectors, 'organization', "varchar(191) NOT NULL DEFAULT ''" );
			self::add_column( $connectors, 'website', "varchar(255) NOT NULL DEFAULT ''" );
			self::add_column( $connectors, 'partner_id', 'bigint(20) unsigned NOT NULL DEFAULT 0' );
			self::add_column( $connectors, 'application', 'longtext' );
			self::add_column( $connectors, 'reviewed_by', 'bigint(20) unsigned NOT NULL DEFAULT 0' );
			self::add_column( $connectors, 'reviewed_at', 'datetime DEFAULT NULL' );
			self::add_index( $connectors, 'program_status', 'program, status' );
			self::add_index( $connectors, 'partner_id', 'partner_id' );
		}

		if ( class_exists( 'LNKup_Org_Growth_Tables' ) ) {
			$partners = LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::PARTNERS );
			self::add_column( $partners, 'category', "varchar(48) NOT NULL DEFAULT ''" );
			self::add_column( $partners, 'website', "varchar(255) NOT NULL DEFAULT ''" );
			self::add_column( $partners, 'domain', "varchar(191) NOT NULL DEFAULT ''" );
			self::add_column( $partners, 'name_key', "varchar(191) NOT NULL DEFAULT ''" );
			self::add_column( $partners, 'cities_served', "varchar(255) NOT NULL DEFAULT ''" );
			self::add_column( $partners, 'stage', "varchar(24) NOT NULL DEFAULT 'prospect'" );
			self::add_column( $partners, 'agreement_ref', "varchar(191) NOT NULL DEFAULT ''" );
			self::add_column( $partners, 'commission_rule_id', 'bigint(20) unsigned NOT NULL DEFAULT 0' );
			self::add_column( $partners, 'lead_id', 'bigint(20) unsigned NOT NULL DEFAULT 0' );
			self::add_column( $partners, 'next_follow_up_at', 'datetime DEFAULT NULL' );
			self::add_index( $partners, 'stage', 'stage' );
			self::add_index( $partners, 'name_key', 'name_key' );
			self::add_index( $partners, 'domain', 'domain' );
		}

		$programs = self::table( self::PROGRAMS );
		if ( self::exists( self::PROGRAMS ) ) {
			// Affiliate and strategic partner programs open for applications; earning still needs a published rule.
			$wpdb->query( "UPDATE {$programs} SET status = 'active' WHERE slug IN ('affiliate','strategic_partner') AND status = 'disabled'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/**
	 * Stamp a deterministic idempotency key on every reward row, then enforce uniqueness.
	 *
	 * Legacy rows that collide (true historical duplicates) keep a suffixed key so the unique
	 * index can be created without touching amounts or statuses.
	 */
	public static function backfill_idempotency_keys(): void {
		global $wpdb;
		$table = LNKup_Treasury_Tables::referral_rewards_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"UPDATE {$table} SET idempotency_key = LEFT(CONCAT(
				IF(program = '', IF(connector_id > 0, 'city_connector', 'vendor_referral'), program), ':',
				user_id, ':', referral_id, ':', payment_id, ':', event_type
			), 191) WHERE idempotency_key IS NULL OR idempotency_key = ''"
		);
		$dupes = $wpdb->get_col( "SELECT idempotency_key FROM {$table} WHERE idempotency_key IS NOT NULL GROUP BY idempotency_key HAVING COUNT(*) > 1" );
		foreach ( (array) $dupes as $key ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE idempotency_key = %s ORDER BY id ASC", $key ) );
			array_shift( $ids );
			foreach ( $ids as $id ) {
				$wpdb->update( $table, array( 'idempotency_key' => substr( $key, 0, 170 ) . ':legacy:' . (int) $id ), array( 'id' => (int) $id ) );
			}
		}
		$has = $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", 'idempotency_key' ) );
		if ( ! $has ) {
			$wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY idempotency_key (idempotency_key)" );
		}
		// phpcs:enable
	}

	/**
	 * Deterministic reward idempotency key.
	 */
	public static function reward_key( string $program, int $user_id, int $referral_id, int $payment_id, string $event_type ): string {
		return substr( sanitize_key( $program ) . ':' . $user_id . ':' . $referral_id . ':' . $payment_id . ':' . sanitize_key( $event_type ), 0, 191 );
	}

	/**
	 * Whether the reward table already carries the Partnership OS columns.
	 */
	public static function ready(): bool {
		return get_option( self::SCHEMA_OPTION ) === self::SCHEMA_VERSION;
	}

	private static function add_column( string $table, string $column, string $definition ): void {
		global $wpdb;
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) );
		if ( $has ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$column} {$definition}" );
	}

	private static function add_index( string $table, string $index, string $columns ): void {
		global $wpdb;
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has = $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", $index ) );
		if ( $has ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE {$table} ADD KEY {$index} ({$columns})" );
	}
}

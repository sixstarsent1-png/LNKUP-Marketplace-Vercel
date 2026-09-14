<?php
/**
 * City Connector database tables.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Connector_Tables {

	public static function connectors_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_connectors';
	}

	public static function events_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_connector_events';
	}

	/**
	 * Create connector tables and migrate referral columns.
	 */
	public static function create_tables() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$connectors = self::connectors_table();
		dbDelta(
			"CREATE TABLE {$connectors} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				display_name varchar(191) NOT NULL DEFAULT '',
				email varchar(191) NOT NULL DEFAULT '',
				phone varchar(32) NOT NULL DEFAULT '',
				market_slug varchar(64) NOT NULL DEFAULT '',
				city varchar(128) NOT NULL DEFAULT '',
				state varchar(8) NOT NULL DEFAULT '',
				status varchar(32) NOT NULL DEFAULT 'pending',
				referral_code_id bigint(20) unsigned NOT NULL DEFAULT 0,
				connected_to varchar(32) NOT NULL DEFAULT '',
				interest_note text,
				social_url varchar(255) NOT NULL DEFAULT '',
				approved_by bigint(20) unsigned NOT NULL DEFAULT 0,
				approved_at datetime DEFAULT NULL,
				admin_notes text,
				payout_preference varchar(32) NOT NULL DEFAULT '',
				bio text,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY user_id (user_id),
				KEY status (status),
				KEY market_slug (market_slug),
				KEY email (email)
			) {$charset};"
		);

		$events = self::events_table();
		dbDelta(
			"CREATE TABLE {$events} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				connector_id bigint(20) unsigned NOT NULL DEFAULT 0,
				referral_id bigint(20) unsigned NOT NULL DEFAULT 0,
				referred_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				event_type varchar(64) NOT NULL DEFAULT '',
				metadata longtext,
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY connector_id (connector_id),
				KEY referral_id (referral_id),
				KEY event_type (event_type)
			) {$charset};"
		);

		$attr = self::booking_attr_table();
		dbDelta(
			"CREATE TABLE {$attr} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				payment_id bigint(20) unsigned NOT NULL DEFAULT 0,
				connector_id bigint(20) unsigned NOT NULL DEFAULT 0,
				referral_id bigint(20) unsigned NOT NULL DEFAULT 0,
				reason varchar(16) NOT NULL DEFAULT '',
				gmv decimal(14,2) NOT NULL DEFAULT 0.00,
				opportunity_id bigint(20) unsigned NOT NULL DEFAULT 0,
				market_slug varchar(64) NOT NULL DEFAULT '',
				status varchar(16) NOT NULL DEFAULT 'counted',
				counted_at datetime DEFAULT NULL,
				available_at datetime DEFAULT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY payment_id (payment_id),
				KEY connector_id (connector_id),
				KEY status (status)
			) {$charset};"
		);

		self::migrate_referral_columns();
		self::migrate_connector_columns();
	}

	/**
	 * Idempotent booking attribution ledger.
	 */
	public static function booking_attr_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_connector_booking_attr';
	}

	/**
	 * Add optional connector profile columns.
	 */
	public static function migrate_connector_columns() {
		global $wpdb;
		$table = self::connectors_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return;
		}
		$cols = array(
			'bio'                        => 'text',
			'current_rank'               => "varchar(32) NOT NULL DEFAULT ''",
			'highest_rank'               => "varchar(32) NOT NULL DEFAULT ''",
			'rank_earned_at'             => 'datetime DEFAULT NULL',
			'rank_calculated_at'         => 'datetime DEFAULT NULL',
			'founding_partner'           => 'tinyint(1) NOT NULL DEFAULT 0',
			'leaderboard_opt_in'         => 'tinyint(1) NOT NULL DEFAULT 0',
			'activated_organizer_count'  => 'int(11) NOT NULL DEFAULT 0',
			'recurring_organizer_count'  => 'int(11) NOT NULL DEFAULT 0',
			'qualifying_booking_count'   => 'int(11) NOT NULL DEFAULT 0',
			'qualifying_gmv'             => 'decimal(14,2) NOT NULL DEFAULT 0.00',
			'approved_city_count'        => 'int(11) NOT NULL DEFAULT 0',
			'rank_celebration_pending'   => "varchar(32) NOT NULL DEFAULT ''",
			'rank_celebration_ack'       => "varchar(32) NOT NULL DEFAULT ''",
			'growth_styles'              => "varchar(191) NOT NULL DEFAULT ''",
		);
		foreach ( $cols as $col => $def ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM `' . $table . '` LIKE %s', $col ) );
			if ( empty( $exists ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$def}" );
			}
		}
	}

	/**
	 * Extend treasury referral tables for connector program.
	 */
	public static function migrate_referral_columns() {
		global $wpdb;

		$codes = LNKup_Treasury_Tables::referral_codes_table();
		$refs  = LNKup_Treasury_Tables::referrals_table();
		$rewards = LNKup_Treasury_Tables::referral_rewards_table();

		if ( ! LNKup_Financial_Tables::table_exists( $codes ) ) {
			return;
		}

		$code_cols = array(
			'program'      => "varchar(32) NOT NULL DEFAULT 'vendor_referral'",
			'market_slug'  => "varchar(64) NOT NULL DEFAULT ''",
			'connector_id' => 'bigint(20) unsigned NOT NULL DEFAULT 0',
		);
		foreach ( $code_cols as $col => $def ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM `' . $codes . '` LIKE %s', $col ) );
			if ( empty( $exists ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "ALTER TABLE `{$codes}` ADD COLUMN `{$col}` {$def}" );
			}
		}

		$ref_cols = array(
			'program'               => "varchar(32) NOT NULL DEFAULT 'vendor_referral'",
			'market_slug'           => "varchar(64) NOT NULL DEFAULT ''",
			'target_role'           => "varchar(16) NOT NULL DEFAULT ''",
			'source_url'            => "varchar(255) NOT NULL DEFAULT ''",
			'signup_source'         => "varchar(32) NOT NULL DEFAULT ''",
			'profile_completed_at'  => 'datetime DEFAULT NULL',
			'first_application_at'  => 'datetime DEFAULT NULL',
			'first_payment_at'      => 'datetime DEFAULT NULL',
			'first_event_at'        => 'datetime DEFAULT NULL',
			'first_opportunity_at'  => 'datetime DEFAULT NULL',
			'attributed_revenue'    => 'decimal(14,2) NOT NULL DEFAULT 0.00',
			'reward_status'         => "varchar(32) NOT NULL DEFAULT 'none'",
		);
		foreach ( $ref_cols as $col => $def ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM `' . $refs . '` LIKE %s', $col ) );
			if ( empty( $exists ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "ALTER TABLE `{$refs}` ADD COLUMN `{$col}` {$def}" );
			}
		}

		$reward_cols = array(
			'reward_status' => "varchar(32) NOT NULL DEFAULT 'pending'",
			'approved_by'   => 'bigint(20) unsigned NOT NULL DEFAULT 0',
			'approved_at'   => 'datetime DEFAULT NULL',
			'paid_at'       => 'datetime DEFAULT NULL',
			'connector_id'  => 'bigint(20) unsigned NOT NULL DEFAULT 0',
			'payment_id'    => 'bigint(20) unsigned NOT NULL DEFAULT 0',
			'available_at'  => 'datetime DEFAULT NULL',
			'program'          => "varchar(32) NOT NULL DEFAULT ''",
			'idempotency_key'  => "varchar(191) NOT NULL DEFAULT ''",
		);
		foreach ( $reward_cols as $col => $def ) {
			if ( ! LNKup_Financial_Tables::table_exists( $rewards ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM `' . $rewards . '` LIKE %s', $col ) );
			if ( empty( $exists ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "ALTER TABLE `{$rewards}` ADD COLUMN `{$col}` {$def}" );
			}
		}
	}
}

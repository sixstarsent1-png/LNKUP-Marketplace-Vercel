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

		self::migrate_referral_columns();
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

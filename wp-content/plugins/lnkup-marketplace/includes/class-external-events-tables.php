<?php
/**
 * External event discovery SQL tables.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_External_Events_Tables {

	const SCHEMA_VERSION = '2.0';

	/**
	 * Create external events tables.
	 */
	public static function create_tables(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$events = self::events_table();
		dbDelta(
			"CREATE TABLE {$events} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				source_platform varchar(32) NOT NULL DEFAULT 'eventbrite',
				source_event_id varchar(64) NOT NULL DEFAULT '',
				source_url varchar(500) NOT NULL DEFAULT '',
				title varchar(255) NOT NULL DEFAULT '',
				description_excerpt varchar(280) NOT NULL DEFAULT '',
				image_url varchar(500) NOT NULL DEFAULT '',
				start_datetime datetime DEFAULT NULL,
				end_datetime datetime DEFAULT NULL,
				city varchar(100) NOT NULL DEFAULT '',
				state varchar(32) NOT NULL DEFAULT '',
				venue_name varchar(255) NOT NULL DEFAULT '',
				organizer_name varchar(255) NOT NULL DEFAULT '',
				organizer_url varchar(500) NOT NULL DEFAULT '',
				organizer_email varchar(191) NOT NULL DEFAULT '',
				event_type varchar(128) NOT NULL DEFAULT '',
				vendor_fit_score int unsigned NOT NULL DEFAULT 0,
				workflow_status varchar(32) NOT NULL DEFAULT 'published',
				claim_status varchar(24) NOT NULL DEFAULT 'unclaimed',
				claimed_event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				vendor_interest_count int unsigned NOT NULL DEFAULT 0,
				demand_score int unsigned NOT NULL DEFAULT 0,
				demand_metrics_json longtext,
				last_interest_at datetime DEFAULT NULL,
				interest_velocity int unsigned NOT NULL DEFAULT 0,
				interest_trend varchar(16) NOT NULL DEFAULT 'stable',
				top_requested_categories_json longtext,
				followup_stage tinyint unsigned NOT NULL DEFAULT 0,
				emails_sent int unsigned NOT NULL DEFAULT 0,
				emails_opened int unsigned NOT NULL DEFAULT 0,
				emails_clicked int unsigned NOT NULL DEFAULT 0,
				last_email_sent_at datetime DEFAULT NULL,
				duplicate_of_id bigint(20) unsigned NOT NULL DEFAULT 0,
				duplicate_confidence int unsigned NOT NULL DEFAULT 0,
				last_synced_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY source_url (source_url(191)),
				KEY workflow_claim (workflow_status, claim_status, city(50)),
				KEY platform_source (source_platform, source_event_id),
				KEY start_datetime (start_datetime),
				KEY demand_score (demand_score)
			) {$charset};"
		);

		$interest = self::interest_table();
		dbDelta(
			"CREATE TABLE {$interest} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				external_event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				vendor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				vendor_profile_id bigint(20) unsigned NOT NULL DEFAULT 0,
				vendor_category varchar(128) NOT NULL DEFAULT '',
				booth_type varchar(128) NOT NULL DEFAULT '',
				power_needs varchar(128) NOT NULL DEFAULT '',
				message text,
				status varchar(32) NOT NULL DEFAULT 'submitted',
				organizer_email_sent_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY event_vendor (external_event_id, vendor_user_id),
				KEY vendor_user_id (vendor_user_id),
				KEY status (status)
			) {$charset};"
		);

		$tokens = self::claim_tokens_table();
		dbDelta(
			"CREATE TABLE {$tokens} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				external_event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				email varchar(191) NOT NULL DEFAULT '',
				token varchar(64) NOT NULL DEFAULT '',
				expires_at datetime NOT NULL,
				opened_at datetime DEFAULT NULL,
				used_at datetime DEFAULT NULL,
				organizer_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY token (token),
				KEY external_event_id (external_event_id),
				KEY email (email(100))
			) {$charset};"
		);

		$activity = self::activity_table();
		dbDelta(
			"CREATE TABLE {$activity} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				external_event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				activity_type varchar(64) NOT NULL DEFAULT '',
				actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				payload_json longtext,
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY external_event_id (external_event_id),
				KEY activity_type (activity_type)
			) {$charset};"
		);

		$emails = self::emails_table();
		dbDelta(
			"CREATE TABLE {$emails} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				external_event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				stage tinyint unsigned NOT NULL DEFAULT 0,
				subject varchar(255) NOT NULL DEFAULT '',
				token_hash varchar(64) NOT NULL DEFAULT '',
				sent_at datetime DEFAULT NULL,
				opened_at datetime DEFAULT NULL,
				clicked_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY external_event_id (external_event_id),
				KEY token_hash (token_hash)
			) {$charset};"
		);

		$duplicates = self::duplicates_table();
		dbDelta(
			"CREATE TABLE {$duplicates} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_a_id bigint(20) unsigned NOT NULL DEFAULT 0,
				event_b_id bigint(20) unsigned NOT NULL DEFAULT 0,
				confidence int unsigned NOT NULL DEFAULT 0,
				status varchar(24) NOT NULL DEFAULT 'suggested',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY event_a_id (event_a_id),
				KEY event_b_id (event_b_id),
				KEY status (status)
			) {$charset};"
		);

		self::maybe_backfill_v2();
	}

	/**
	 * Backfill V1 rows after schema upgrade.
	 */
	public static function maybe_backfill_v2(): void {
		if ( get_option( 'lnkup_external_events_schema' ) === self::SCHEMA_VERSION ) {
			return;
		}

		global $wpdb;
		$table = self::events_table();
		if ( ! self::table_exists( $table ) ) {
			update_option( 'lnkup_external_events_schema', self::SCHEMA_VERSION );
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			"UPDATE {$table} SET workflow_status = 'published'
			WHERE workflow_status = '' OR workflow_status IS NULL"
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET workflow_status = %s WHERE claim_status = %s",
				'hidden',
				'hidden'
			)
		);

		update_option( 'lnkup_external_events_schema', self::SCHEMA_VERSION );
	}

	/**
	 * @param string $table Table name.
	 */
	public static function table_exists( string $table ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	public static function events_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_external_events';
	}

	public static function interest_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_external_event_interest';
	}

	public static function claim_tokens_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_external_event_claim_tokens';
	}

	public static function activity_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_external_event_activity';
	}

	public static function emails_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_external_event_emails';
	}

	public static function duplicates_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_external_event_duplicates';
	}
}

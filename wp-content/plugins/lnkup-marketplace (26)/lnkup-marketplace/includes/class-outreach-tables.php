<?php
/**
 * Outreach campaign database tables.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Outreach_Tables {

	const CAMPAIGNS   = 'lnkup_outreach_campaigns';
	const RECIPIENTS  = 'lnkup_outreach_recipients';

	/**
	 * Upgrade tables if needed.
	 */
	public static function maybe_upgrade() {
		if ( ! self::table_exists( self::CAMPAIGNS ) ) {
			self::create_tables();
		}
	}

	/**
	 * @param string $table Table suffix.
	 */
	public static function table_name( $table ) {
		global $wpdb;
		return $wpdb->prefix . $table;
	}

	/**
	 * @param string $table Table suffix.
	 */
	public static function table_exists( $table ) {
		global $wpdb;
		$name = self::table_name( $table );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) === $name;
	}

	/**
	 * Create outreach tables.
	 */
	public static function create_tables() {
		global $wpdb;
		$charset   = $wpdb->get_charset_collate();
		$campaigns = self::table_name( self::CAMPAIGNS );
		$recipients = self::table_name( self::RECIPIENTS );

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			"CREATE TABLE {$campaigns} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(191) NOT NULL DEFAULT '',
				target_role varchar(32) NOT NULL DEFAULT 'lnkup_vendor',
				message text,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				source varchar(16) NOT NULL DEFAULT 'admin',
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				opportunity_id bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'draft',
				total int(11) NOT NULL DEFAULT 0,
				sent int(11) NOT NULL DEFAULT 0,
				skipped int(11) NOT NULL DEFAULT 0,
				failed int(11) NOT NULL DEFAULT 0,
				registered int(11) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY created_by (created_by),
				KEY status (status),
				KEY source (source)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$recipients} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				campaign_id bigint(20) unsigned NOT NULL,
				email varchar(191) NOT NULL DEFAULT '',
				name varchar(191) NOT NULL DEFAULT '',
				token varchar(64) NOT NULL DEFAULT '',
				status varchar(24) NOT NULL DEFAULT 'pending',
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				sent_at datetime DEFAULT NULL,
				opened_at datetime DEFAULT NULL,
				registered_at datetime DEFAULT NULL,
				error_message varchar(255) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY token (token),
				KEY campaign_id (campaign_id),
				KEY email (email),
				KEY status (status)
			) {$charset};"
		);
	}
}

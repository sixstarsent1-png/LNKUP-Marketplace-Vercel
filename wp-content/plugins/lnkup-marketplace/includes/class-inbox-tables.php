<?php
/**
 * Notification inbox index tables.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Inbox_Tables {

	const INDEX    = 'lnkup_inbox_index';
	const DELIVERY = 'lnkup_notification_deliveries';

	/**
	 * Full table name.
	 *
	 * @param string $table Table suffix.
	 * @return string
	 */
	public static function table_name( $table ) {
		global $wpdb;
		return $wpdb->prefix . $table;
	}

	/**
	 * Inbox index table.
	 *
	 * @return string
	 */
	public static function index_table() {
		return self::table_name( self::INDEX );
	}

	/**
	 * Transactional delivery claims. No message bodies or tokens.
	 *
	 * @return string
	 */
	public static function delivery_table() {
		return self::table_name( self::DELIVERY );
	}

	/**
	 * Create inbox tables.
	 */
	public static function create_tables() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$index   = self::index_table();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			"CREATE TABLE {$index} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				client_id varchar(120) NOT NULL DEFAULT '',
				notification_type varchar(64) NOT NULL DEFAULT '',
				role varchar(16) NOT NULL DEFAULT '',
				title varchar(255) NOT NULL DEFAULT '',
				message text,
				href varchar(255) NOT NULL DEFAULT '',
				severity varchar(16) NOT NULL DEFAULT 'info',
				action_required tinyint(1) NOT NULL DEFAULT 0,
				payload longtext,
				read_at datetime DEFAULT NULL,
				dismissed_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY user_client (user_id, client_id),
				KEY user_unread (user_id, read_at, created_at),
				KEY user_role_created (user_id, role, created_at)
			) {$charset};"
		);

		$delivery = self::delivery_table();
		dbDelta(
			"CREATE TABLE {$delivery} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				dedupe_key varchar(191) NOT NULL DEFAULT '',
				event_key varchar(64) NOT NULL DEFAULT '',
				ref_id bigint(20) unsigned NOT NULL DEFAULT 0,
				recipient_id bigint(20) unsigned NOT NULL DEFAULT 0,
				channel varchar(16) NOT NULL DEFAULT '',
				window_key varchar(64) NOT NULL DEFAULT '',
				status varchar(16) NOT NULL DEFAULT 'queued',
				attempts smallint unsigned NOT NULL DEFAULT 0,
				last_error varchar(255) NOT NULL DEFAULT '',
				next_attempt_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY dedupe_key (dedupe_key),
				KEY status_next (status, next_attempt_at),
				KEY recipient_event (recipient_id, event_key)
			) {$charset};"
		);
	}
}

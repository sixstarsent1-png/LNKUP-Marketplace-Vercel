<?php
/**
 * Notification inbox index tables.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Inbox_Tables {

	const INDEX = 'lnkup_inbox_index';

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
	}
}

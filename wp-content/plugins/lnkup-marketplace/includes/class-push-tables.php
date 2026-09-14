<?php
/**
 * Push device token tables.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Push_Tables {

	const DEVICES = 'lnkup_push_devices';

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
	 * Create push device tables.
	 */
	public static function create_tables() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$devices = self::table_name( self::DEVICES );

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			"CREATE TABLE {$devices} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				token varchar(255) NOT NULL DEFAULT '',
				platform varchar(16) NOT NULL DEFAULT 'unknown',
				app_version varchar(32) NOT NULL DEFAULT '',
				enabled tinyint(1) NOT NULL DEFAULT 1,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY token (token),
				KEY user_id (user_id),
				KEY enabled (enabled)
			) {$charset};"
		);
	}
}

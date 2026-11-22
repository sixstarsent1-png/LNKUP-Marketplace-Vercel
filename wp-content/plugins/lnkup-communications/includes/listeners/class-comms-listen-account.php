<?php
/**
 * Account lifecycle: deletion cleanup, role changes, legacy push preference sync.
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_Listen_Account {

	/**
	 * @var bool True while this plugin itself writes the legacy push meta.
	 */
	public static $writing_legacy = false;

	public static function init(): void {
		add_action( 'lnkup_account_deletion_requested', array( __CLASS__, 'on_deletion' ), 20 );
		add_action( 'delete_user', array( __CLASS__, 'on_deletion' ), 20 );
		add_action( 'set_user_role', array( __CLASS__, 'on_role_change' ), 20, 3 );
		add_action( 'remove_user_role', array( __CLASS__, 'on_role_removed' ), 20, 2 );
		add_action( 'updated_user_meta', array( __CLASS__, 'on_legacy_push_meta' ), 20, 4 );
		add_action( 'added_user_meta', array( __CLASS__, 'on_legacy_push_meta' ), 20, 4 );
	}

	/**
	 * Remove communication data (inbox, history, preferences) and stop future sends.
	 * Push devices are already revoked by lnkup-marketplace.
	 */
	public static function on_deletion( $user_id ): void {
		LNKup_Comms_Engine::purge_user( absint( $user_id ) );
	}

	/**
	 * Cancel scheduled messages for a role the user no longer holds.
	 *
	 * @param int      $user_id   User.
	 * @param string   $role      New role.
	 * @param string[] $old_roles Previous roles.
	 */
	public static function on_role_change( $user_id, $role, $old_roles ): void {
		unset( $role, $old_roles );
		self::cancel_orphaned_roles( absint( $user_id ) );
	}

	public static function on_role_removed( $user_id, $role ): void {
		unset( $role );
		self::cancel_orphaned_roles( absint( $user_id ) );
	}

	private static function cancel_orphaned_roles( int $user_id ): void {
		global $wpdb;
		$roles = LNKup_Comms_Preferences::user_roles( $user_id );
		foreach ( array( 'vendor', 'organizer', 'partner' ) as $role ) {
			if ( in_array( $role, $roles, true ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . LNKup_Comms_Schema::table( 'messages' ) . " SET status = 'cancelled', suppression_reason = 'cancelled:role_removed', updated_at = %s WHERE recipient_user_id = %d AND recipient_role = %s AND status = 'scheduled'", LNKup_Comms_Schema::now(), $user_id, $role ) );
		}
	}

	/**
	 * Keep our push rows in sync when an older app version uses /push/preferences.
	 */
	public static function on_legacy_push_meta( $meta_id, $user_id, $meta_key, $value ): void {
		unset( $meta_id );
		if ( self::$writing_legacy || ! class_exists( 'LNKup_Push_Service' ) || LNKup_Push_Service::META_PUSH_CATEGORIES !== $meta_key ) {
			return;
		}
		$decoded = is_string( $value ) ? json_decode( $value, true ) : $value;
		if ( is_array( $decoded ) ) {
			LNKup_Comms_Preferences::sync_from_legacy( absint( $user_id ), array_map( 'boolval', $decoded ) );
		}
	}
}

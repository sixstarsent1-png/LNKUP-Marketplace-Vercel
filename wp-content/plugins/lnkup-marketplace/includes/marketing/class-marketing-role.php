<?php
/**
 * Marketing staff role, unified suppression bridge, and activity timestamp.
 *
 * - Role `lnkup_marketing` has only `read` and `lnkup_manage_marketing`: no financial, payout,
 *   document, application or user-management capabilities. Administrators and lnkup_admin get
 *   `lnkup_manage_marketing` too.
 * - Marketing staff are excluded from advertising measurement like other staff.
 * - Suppression bridge: Communications' check_email also consults the organizer-outreach list
 *   (lnkup_contact_suppression, including domain do-not-contact), so a contact who unsubscribed
 *   from outreach is not emailed by campaigns or automations. Outreach already consults the
 *   Communications list, so all senders now honor both.
 * - `_lnkup_last_login_at` (UTC) is recorded on sign-in for the "inactive N days" audience filter.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Marketing_Role {

	const ROLE    = 'lnkup_marketing';
	const CAP     = 'lnkup_manage_marketing';
	const VERSION = '1';
	const OPTION  = 'lnkup_marketing_role_version';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'maybe_install' ), 4 );
		add_action( 'wp_login', array( __CLASS__, 'on_login' ), 10, 2 );
		add_filter( 'lnkup_comms_email_suppressed', array( __CLASS__, 'bridge_suppression' ), 10, 4 );
		add_filter( 'lnkup_meta_exclude_user', array( __CLASS__, 'exclude_from_ads' ), 10, 2 );
	}

	public static function maybe_install(): void {
		if ( get_option( self::OPTION ) === self::VERSION && get_role( self::ROLE ) ) {
			return;
		}
		remove_role( self::ROLE );
		add_role( self::ROLE, __( 'LNKup Marketing', 'lnkup-marketplace' ), array( 'read' => true, self::CAP => true ) );
		foreach ( array( 'administrator', 'lnkup_admin' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role ) {
				$role->add_cap( self::CAP );
			}
		}
		update_option( self::OPTION, self::VERSION, false );
	}

	/**
	 * @param string  $login Login.
	 * @param WP_User $user  User.
	 */
	public static function on_login( $login, $user ): void {
		if ( $user instanceof WP_User ) {
			update_user_meta( $user->ID, '_lnkup_last_login_at', time() );
		}
	}

	/**
	 * Outreach suppression for Communications sends. Bounces and complaints block every message;
	 * unsubscribes and do-not-contact block promotional messages.
	 *
	 * @param string $result      Current result.
	 * @param string $email       Address.
	 * @param string $category    Category.
	 * @param bool   $promotional Promotional.
	 */
	public static function bridge_suppression( $result, $email, $category, $promotional ): string {
		global $wpdb;
		if ( '' !== (string) $result || ! class_exists( 'LNKup_Contact_Suppression' ) || ! class_exists( 'LNKup_Org_Growth_Tables' ) ) {
			return (string) $result;
		}
		if ( ! LNKup_Org_Growth_Tables::exists( LNKup_Org_Growth_Tables::SUPPRESSION ) ) {
			return '';
		}
		$email = strtolower( trim( (string) $email ) );
		if ( ! is_email( $email ) ) {
			return '';
		}
		$domain = substr( $email, (int) strrpos( $email, '@' ) + 1 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$reason = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT reason FROM ' . LNKup_Contact_Suppression::table() . " WHERE (scope = 'email' AND value_hash = %s) OR (scope = 'domain' AND value_hash = %s) ORDER BY FIELD(reason, 'bounce', 'complaint') DESC LIMIT 1", LNKup_Contact_Suppression::hash( $email ), LNKup_Contact_Suppression::hash( $domain ) ) );
		if ( '' === $reason ) {
			return '';
		}
		if ( in_array( $reason, array( 'bounce', 'complaint' ), true ) || $promotional ) {
			return 'suppressed_outreach_' . sanitize_key( $reason );
		}
		return '';
	}

	/**
	 * @param bool $excluded Excluded.
	 * @param int  $user_id  User.
	 */
	public static function exclude_from_ads( $excluded, $user_id ): bool {
		return (bool) $excluded || ( (int) $user_id > 0 && user_can( (int) $user_id, self::CAP ) );
	}
}

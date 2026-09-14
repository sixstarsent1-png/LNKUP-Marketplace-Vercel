<?php
/**
 * Account deletion for App Store Guideline 5.1.1(v).
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Initiate and process user account deletion.
 */
class LNKup_Account_Deletion {

	const META_REQUESTED = '_lnkup_account_deletion_requested_at';
	const META_SCHEDULED = '_lnkup_account_deletion_scheduled_at';
	const CRON_HOOK      = 'lnkup_process_account_deletions';
	const GRACE_DAYS     = 30;

	/**
	 * Init hooks.
	 */
	public static function init(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'process_scheduled' ) );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
		add_filter( 'wp_authenticate_user', array( __CLASS__, 'block_deleted_login' ), 30, 2 );
	}

	/**
	 * Request deletion for the current user (Apple: initiate in-app).
	 *
	 * @param int    $user_id User ID.
	 * @param string $confirm Must be DELETE.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function request_deletion( int $user_id, string $confirm ) {
		if ( 'DELETE' !== strtoupper( trim( $confirm ) ) ) {
			return new WP_Error(
				'confirm_required',
				__( 'Type DELETE to confirm account deletion.', 'lnkup-marketplace' ),
				array( 'status' => 400 )
			);
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error( 'invalid_user', __( 'Account not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}

		if ( user_can( $user_id, 'manage_options' ) && ! LNKup_Capabilities::user_has_manage_all( $user_id ) ) {
			// Allow administrators who also use marketplace; still block sole super-admin edge via count.
		}
		if ( is_super_admin( $user_id ) ) {
			return new WP_Error(
				'forbidden',
				__( 'Site administrator accounts cannot be deleted from the app. Contact support.', 'lnkup-marketplace' ),
				array( 'status' => 403 )
			);
		}

		$now       = time();
		$scheduled = $now + ( self::GRACE_DAYS * DAY_IN_SECONDS );

		update_user_meta( $user_id, self::META_REQUESTED, gmdate( 'c', $now ) );
		update_user_meta( $user_id, self::META_SCHEDULED, gmdate( 'c', $scheduled ) );

		self::scrub_personal_data( $user_id );
		self::revoke_app_passwords( $user_id );
		self::revoke_push_devices( $user_id );

		/**
		 * Fires when a user requests account deletion from the app.
		 *
		 * @param int $user_id User ID.
		 */
		do_action( 'lnkup_account_deletion_requested', $user_id );

		return array(
			'success'              => true,
			'message'              => sprintf(
				/* translators: %d: grace period days */
				__( 'Your account deletion has been scheduled. Personal data will be removed within %d days. You have been signed out.', 'lnkup-marketplace' ),
				self::GRACE_DAYS
			),
			'grace_days'           => self::GRACE_DAYS,
			'scheduled_for'        => gmdate( 'c', $scheduled ),
			'signed_out_required'  => true,
		);
	}

	/**
	 * Block login after deletion requested.
	 *
	 * @param WP_User|WP_Error|null $user     User.
	 * @param string                $password Password (unused).
	 * @return WP_User|WP_Error|null
	 */
	public static function block_deleted_login( $user, $password = '' ) {
		if ( ! $user instanceof WP_User ) {
			return $user;
		}
		if ( get_user_meta( $user->ID, self::META_REQUESTED, true ) ) {
			return new WP_Error(
				'lnkup_account_deleted',
				__( 'This account is scheduled for deletion and can no longer sign in.', 'lnkup-marketplace' )
			);
		}
		return $user;
	}

	/**
	 * Scrub PII from user + vendor profile.
	 *
	 * @param int $user_id User ID.
	 */
	public static function scrub_personal_data( int $user_id ): void {
		$anon_email = sprintf( 'deleted+%d@invalid.lnkup.local', $user_id );
		wp_update_user(
			array(
				'ID'           => $user_id,
				'display_name' => __( 'Deleted User', 'lnkup-marketplace' ),
				'nickname'     => 'deleted-' . $user_id,
				'first_name'   => '',
				'last_name'    => '',
				'user_email'   => $anon_email,
				'user_url'     => '',
				'description'  => '',
			)
		);

		$profile_keys = array(
			'contact_email',
			'contact_phone',
			'website',
			'instagram',
			'tiktok',
			'owner_name',
			'products_services',
			'availability_notes',
			'city',
			'state',
		);

		$profiles = get_posts(
			array(
				'post_type'      => 'lnkup_vendor_profile',
				'posts_per_page' => 20,
				'post_status'    => 'any',
				'meta_key'       => '_lnkup_owner_user_id',
				'meta_value'     => $user_id,
				'fields'         => 'ids',
			)
		);
		foreach ( $profiles as $profile_id ) {
			foreach ( $profile_keys as $key ) {
				delete_post_meta( (int) $profile_id, '_lnkup_' . $key );
			}
			wp_update_post(
				array(
					'ID'         => (int) $profile_id,
					'post_title' => sprintf( 'Deleted Profile %d', (int) $profile_id ),
					'post_content' => '',
				)
			);
		}

		delete_user_meta( $user_id, 'billing_phone' );
		delete_user_meta( $user_id, 'billing_address_1' );
		delete_user_meta( $user_id, 'shipping_address_1' );
	}

	/**
	 * Revoke WordPress Application Passwords for mobile auth.
	 *
	 * @param int $user_id User ID.
	 */
	public static function revoke_app_passwords( int $user_id ): void {
		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return;
		}
		$passwords = WP_Application_Passwords::get_user_application_passwords( $user_id );
		if ( ! is_array( $passwords ) ) {
			return;
		}
		foreach ( $passwords as $row ) {
			if ( empty( $row['uuid'] ) ) {
				continue;
			}
			WP_Application_Passwords::delete_application_password( $user_id, $row['uuid'] );
		}
	}

	/**
	 * Clear Expo push device tokens.
	 *
	 * @param int $user_id User ID.
	 */
	public static function revoke_push_devices( int $user_id ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'lnkup_push_devices';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $table, array( 'user_id' => $user_id ), array( '%d' ) );
		}
		delete_user_meta( $user_id, '_lnkup_push_token' );
		delete_user_meta( $user_id, '_lnkup_expo_push_token' );
	}

	/**
	 * Cron: hard-delete users past grace period.
	 */
	public static function process_scheduled(): void {
		$users = get_users(
			array(
				'meta_key'     => self::META_SCHEDULED,
				'meta_compare' => 'EXISTS',
				'fields'       => array( 'ID' ),
				'number'       => 50,
			)
		);
		$now = time();
		foreach ( $users as $user ) {
			$user_id   = (int) $user->ID;
			$scheduled = strtotime( (string) get_user_meta( $user_id, self::META_SCHEDULED, true ) );
			if ( ! $scheduled || $scheduled > $now ) {
				continue;
			}
			if ( is_super_admin( $user_id ) ) {
				continue;
			}
			self::scrub_personal_data( $user_id );
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $user_id );
		}
	}
}

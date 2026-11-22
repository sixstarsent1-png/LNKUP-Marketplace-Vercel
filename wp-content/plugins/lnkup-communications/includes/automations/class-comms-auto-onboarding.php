<?php
/**
 * Onboarding sequences for NEW vendor and organizer accounts.
 *
 * Steps are scheduled at registration; each one re-checks the exact missing step
 * at send time (validity) and is suppressed once setup is complete. Existing
 * accounts are never back-filled when the flag is turned on.
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_Auto_Onboarding {

	const META_STARTED = '_lnkup_comms_onboarding_started';

	public static function init(): void {
		add_action( 'user_register', array( __CLASS__, 'on_register' ), 30 );
		add_action( 'add_user_role', array( __CLASS__, 'on_role_added' ), 30, 2 );
		add_action( 'set_user_role', array( __CLASS__, 'on_role_set' ), 30, 3 );
	}

	public static function on_register( $user_id ): void {
		self::maybe_start( absint( $user_id ) );
	}

	public static function on_role_added( $user_id, $role ): void {
		unset( $role );
		self::maybe_start( absint( $user_id ) );
	}

	public static function on_role_set( $user_id, $role, $old_roles ): void {
		unset( $role, $old_roles );
		self::maybe_start( absint( $user_id ) );
	}

	/**
	 * Start the sequence for each marketplace role once.
	 */
	public static function maybe_start( int $user_id ): void {
		if ( ! LNKup_Comms_Settings::enabled( 'flow_onboarding' ) ) {
			return;
		}
		$user = get_userdata( $user_id );
		// Only accounts created in the last 2 days (role changes on old accounts do not start drips).
		if ( ! $user || strtotime( $user->user_registered . ' UTC' ) < time() - 2 * DAY_IN_SECONDS ) {
			return;
		}
		$started = (array) get_user_meta( $user_id, self::META_STARTED, true );
		foreach ( LNKup_Comms_Preferences::user_roles( $user_id ) as $role ) {
			if ( in_array( $role, $started, true ) || user_can( $user, 'manage_options' ) ) {
				continue;
			}
			$started[] = $role;
			if ( 'vendor' === $role ) {
				$ctx = array( 'vendor_user_id' => $user_id );
				LNKup_Comms_Engine::dispatch( 'v_welcome', $user_id, $ctx, array( 'at' => time() + 120, 'source' => 'onboarding' ) );
				foreach ( array( 'd1' => DAY_IN_SECONDS, 'd3' => 3 * DAY_IN_SECONDS, 'd7' => 7 * DAY_IN_SECONDS ) as $window => $delay ) {
					LNKup_Comms_Engine::dispatch( 'v_onboarding', $user_id, $ctx, array( 'window' => $window, 'at' => time() + $delay, 'cancel_key' => 'user:' . $user_id . '|onboarding', 'source' => 'onboarding' ) );
				}
			} else {
				$ctx = array( 'organizer_user_id' => $user_id );
				LNKup_Comms_Engine::dispatch( 'o_welcome', $user_id, $ctx, array( 'at' => time() + 120, 'source' => 'onboarding' ) );
				foreach ( array( 'd1' => DAY_IN_SECONDS, 'd3' => 3 * DAY_IN_SECONDS, 'd7' => 7 * DAY_IN_SECONDS ) as $window => $delay ) {
					LNKup_Comms_Engine::dispatch( 'o_onboarding', $user_id, $ctx, array( 'window' => $window, 'at' => time() + $delay, 'cancel_key' => 'user:' . $user_id . '|onboarding', 'source' => 'onboarding' ) );
				}
			}
		}
		update_user_meta( $user_id, self::META_STARTED, array_values( array_unique( $started ) ) );
	}
}

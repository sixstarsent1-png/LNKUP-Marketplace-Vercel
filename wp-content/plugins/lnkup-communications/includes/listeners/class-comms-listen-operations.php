<?php
/**
 * Event operations: pass/booth updates, check-in, event completion.
 * Organizer check-in activity is summarized per event in short buckets instead
 * of one push per vendor.
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_Listen_Operations {

	const CHECKIN_BUCKET = 15 * MINUTE_IN_SECONDS;

	public static function init(): void {
		add_action( 'lnkup_pass_details_updated', array( __CLASS__, 'on_pass_updated' ), 20, 3 );
		add_action( 'lnkup_booth_assigned', array( __CLASS__, 'on_booth_assigned' ), 20, 3 );
		add_action( 'lnkup_checkin_recorded', array( __CLASS__, 'on_checkin' ), 20, 3 );
		add_action( 'lnkup_application_event_completed', array( __CLASS__, 'on_event_completed' ), 20, 2 );
	}

	public static function on_pass_updated( $packet_id, $event_id, $app_id ): void {
		unset( $packet_id, $event_id );
		$app_id = absint( $app_id );
		$vendor = (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true );
		// One message per 10 minutes so a burst of edits becomes a single notice.
		$bucket = (int) ( floor( time() / 600 ) * 600 );
		LNKup_Comms_Engine::dispatch( 'v_pass_updated', $vendor, array( 'application_id' => $app_id ), array( 'window' => 'p' . $bucket, 'at' => $bucket + 600, 'cancel_key' => 'app:' . $app_id . '|ops', 'source' => 'pass_updated' ) );
	}

	public static function on_booth_assigned( $booth_id, $app_id, $event_id ): void {
		unset( $event_id );
		$app_id = absint( $app_id );
		$vendor = (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true );
		LNKup_Comms_Engine::dispatch( 'v_booth_assigned', $vendor, array( 'application_id' => $app_id ), array( 'window' => 'b' . absint( $booth_id ), 'cancel_key' => 'app:' . $app_id . '|ops', 'source' => 'booth_assigned' ) );
	}

	/**
	 * @param int    $event_id Event.
	 * @param int    $app_id   Application.
	 * @param string $action   checked_in|late_arrival|setup_started|setup_complete|checked_out|no_show.
	 */
	public static function on_checkin( $event_id, $app_id, $action ): void {
		$event_id = absint( $event_id );
		$app_id   = absint( $app_id );
		if ( ! in_array( $action, array( 'checked_in', 'late_arrival' ), true ) ) {
			return;
		}
		$vendor = (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true );
		LNKup_Comms_Engine::dispatch( 'v_checked_in', $vendor, array( 'application_id' => $app_id ), array( 'source' => 'checkin' ) );

		$organizer = (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true );
		$bucket    = (int) ( floor( time() / self::CHECKIN_BUCKET ) * self::CHECKIN_BUCKET );
		LNKup_Comms_Engine::dispatch( 'o_checkin_summary', $organizer, array( 'event_id' => $event_id ), array( 'window' => 'c' . $bucket, 'at' => $bucket + self::CHECKIN_BUCKET, 'source' => 'checkin' ) );
	}

	/**
	 * @param int $app_id   Application.
	 * @param int $event_id Event.
	 */
	public static function on_event_completed( $app_id, $event_id ): void {
		$app_id   = absint( $app_id );
		$event_id = absint( $event_id );
		$vendor   = (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true );
		LNKup_Comms_Engine::dispatch( 'v_event_thanks', $vendor, array( 'application_id' => $app_id ), array( 'at' => time() + 6 * HOUR_IN_SECONDS, 'source' => 'event_completed' ) );
		$organizer = (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true );
		// One wrap-up per event, the morning after (dedupe on the event).
		LNKup_Comms_Engine::dispatch( 'o_event_completed', $organizer, array( 'event_id' => $event_id ), array( 'at' => time() + 12 * HOUR_IN_SECONDS, 'source' => 'event_completed' ) );
	}
}

<?php
/**
 * Event and opportunity lifecycle: publish, cancel/postpone, logistics changes,
 * opportunity open/close/remove. Also cancels obsolete scheduled messages.
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_Listen_Events {

	const ACTIVE_FOR_CANCEL = array( 'submitted', 'under_review', 'info_requested', 'approved', 'payment_required', 'waitlisted', 'paid' );

	public static function init(): void {
		add_action( 'lnkup_event_status_changed', array( __CLASS__, 'on_event_status' ), 20, 4 );
		add_action( 'lnkup_event_cancelled', array( __CLASS__, 'on_event_cancelled' ), 20, 3 );
		add_action( 'lnkup_event_logistics_changed', array( __CLASS__, 'on_logistics' ), 20, 2 );
		add_action( 'lnkup_event_removed', array( __CLASS__, 'cancel_event_messages' ), 20 );
		add_action( 'lnkup_opportunity_status_changed', array( __CLASS__, 'on_opportunity_status' ), 20, 3 );
		add_action( 'lnkup_opportunity_removed', array( __CLASS__, 'on_opportunity_removed' ), 20 );
		add_action( 'publish_lnkup_opportunity', array( __CLASS__, 'on_opportunity_published' ), 30, 2 );
	}

	/**
	 * Cancel every scheduled message tied to an event (except the cancellation notice itself).
	 */
	public static function cancel_event_messages( $event_id, string $reason = 'event_removed' ): int {
		global $wpdb;
		$table = LNKup_Comms_Schema::table( 'messages' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'cancelled', suppression_reason = %s, updated_at = %s WHERE event_id = %d AND status = 'scheduled' AND trigger_key <> 'v_event_cancelled'", substr( 'cancelled:' . $reason, 0, 64 ), LNKup_Comms_Schema::now(), absint( $event_id ) ) );
		return (int) $wpdb->rows_affected;
	}

	/**
	 * @param int    $event_id Event.
	 * @param string $old      Old value.
	 * @param string $new      New value.
	 * @param string $key      Meta key that changed.
	 */
	public static function on_event_status( $event_id, $old, $new, $key = '_lnkup_event_status' ): void {
		$event_id = absint( $event_id );
		if ( '_lnkup_event_status' !== $key || 'live' !== $new || 'live' === $old ) {
			return;
		}
		$organizer = (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true );
		if ( ! $organizer ) {
			return;
		}
		// Published for the first time: dedupe keys make repeats harmless.
		LNKup_Comms_Engine::dispatch( 'o_event_published', $organizer, array( 'event_id' => $event_id ), array( 'source' => 'event_live' ) );
		LNKup_Comms_Queue::job( 'lnkup_comms_recruit_event', array( $event_id ), HOUR_IN_SECONDS );
		foreach ( self::open_opportunities( $event_id ) as $opp_id ) {
			LNKup_Comms_Auto_Discovery::queue_opportunity( $opp_id );
		}
	}

	/**
	 * @return int[]
	 */
	private static function open_opportunities( int $event_id ): array {
		$ids = get_posts(
			array(
				'post_type'      => 'lnkup_opportunity',
				'post_status'    => 'publish',
				'posts_per_page' => 20,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_lnkup_event_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => (string) $event_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return array_values( array_filter( array_map( 'intval', (array) $ids ), array( 'LNKup_Comms_Context', 'opportunity_open' ) ) );
	}

	/**
	 * @param int    $event_id Event.
	 * @param string $status   cancelled|postponed.
	 * @param string $note     Organizer note.
	 */
	public static function on_event_cancelled( $event_id, $status, $note ): void {
		unset( $note );
		$event_id = absint( $event_id );
		self::cancel_event_messages( $event_id, 'event_' . sanitize_key( (string) $status ) );
		foreach ( LNKup_Comms_Context::event_app_ids( $event_id, self::ACTIVE_FOR_CANCEL ) as $app_id ) {
			$vendor = (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true );
			LNKup_Comms_Engine::dispatch( 'v_event_cancelled', $vendor, array( 'application_id' => $app_id ), array( 'variant' => 'postponed' === $status ? 'postponed' : 'cancelled', 'window' => sanitize_key( (string) $status ), 'source' => 'event_cancelled' ) );
		}
	}

	/**
	 * @param int      $event_id Event.
	 * @param string[] $changed  Changed field labels.
	 */
	public static function on_logistics( $event_id, $changed ): void {
		$event_id = absint( $event_id );
		$labels   = implode( ', ', array_map( 'sanitize_text_field', array_map( 'strval', (array) $changed ) ) );
		if ( '' === $labels ) {
			return;
		}
		$window = 'l' . substr( md5( $labels . gmdate( 'YmdHi' ) ), 0, 10 );
		foreach ( LNKup_Comms_Context::event_app_ids( $event_id, LNKup_Comms_Context::ACTIVE_BOOKING ) as $app_id ) {
			$vendor = (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true );
			LNKup_Comms_Engine::dispatch( 'v_logistics_changed', $vendor, array( 'application_id' => $app_id, 'changed_fields' => $labels ), array( 'window' => $window, 'source' => 'logistics_changed' ) );
		}
	}

	public static function on_opportunity_status( $opp_id, $old, $new ): void {
		$opp_id = absint( $opp_id );
		if ( 'closed' === $new ) {
			LNKup_Comms_Engine::cancel( 'opp:' . $opp_id, array(), 'opportunity_closed' );
			return;
		}
		if ( 'open' === $new && 'open' !== $old ) {
			LNKup_Comms_Auto_Discovery::queue_opportunity( $opp_id );
		}
	}

	public static function on_opportunity_removed( $opp_id ): void {
		LNKup_Comms_Engine::cancel( 'opp:' . absint( $opp_id ), array(), 'opportunity_removed' );
	}

	/**
	 * @param int     $opp_id Opportunity.
	 * @param WP_Post $post   Post.
	 */
	public static function on_opportunity_published( $opp_id, $post = null ): void {
		unset( $post );
		LNKup_Comms_Auto_Discovery::queue_opportunity( absint( $opp_id ) );
	}
}

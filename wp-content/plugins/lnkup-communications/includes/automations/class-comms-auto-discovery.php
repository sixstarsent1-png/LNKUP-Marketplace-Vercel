<?php
/**
 * Opportunity discovery: when an opportunity opens, notify matching vendors who
 * have not applied. Promotional — honours opt-outs, caps, digests and quiet hours
 * through the engine. Processed in background batches; never inside the publish request.
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_Auto_Discovery {

	const BATCH = 200;

	public static function init(): void {
		add_action( 'lnkup_comms_match_opportunity', array( __CLASS__, 'process' ), 10, 2 );
		add_action( 'lnkup_comms_recruit_event', array( __CLASS__, 'recruit_event' ), 10, 1 );
	}

	/**
	 * Queue matching 10 minutes out so organizers can finish editing first.
	 */
	public static function queue_opportunity( int $opp_id ): void {
		if ( $opp_id < 1 || ! LNKup_Comms_Settings::enabled( 'flow_discovery' ) ) {
			return;
		}
		LNKup_Comms_Queue::job( 'lnkup_comms_match_opportunity', array( $opp_id, 1 ), 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * @param int $opp_id Opportunity.
	 * @param int $page   Batch page.
	 */
	public static function process( $opp_id, $page = 1 ): void {
		global $wpdb;
		$opp_id = absint( $opp_id );
		$page   = max( 1, absint( $page ) );
		if ( ! LNKup_Comms_Settings::enabled( 'flow_discovery' ) || LNKup_Comms_Settings::automation_paused( 'v_opportunity_match' ) || ! LNKup_Comms_Context::opportunity_open( $opp_id ) ) {
			return;
		}
		if ( (bool) get_post_meta( $opp_id, '_lnkup_invite_only', true ) ) {
			return;
		}
		// Tour stops are announced once per tour (v_tour_match), never one message per stop.
		$tour_id = LNKup_Comms_Matching::tour_for_opportunity( $opp_id );
		if ( $tour_id && LNKup_Comms_Settings::enabled( 'flow_discovery_tours' ) ) {
			if ( 1 === $page ) {
				LNKup_Comms_Auto_Matching::queue_tour( $tour_id );
			}
			return;
		}
		$sig        = LNKup_Comms_Matching::signature( $opp_id );
		$worked     = array_flip( $sig['organizer_id'] ? LNKup_Comms_Matching::worked_with( $sig['organizer_id'] ) : array() );
		$result     = LNKup_Comms_Matching::vendor_page( $opp_id, $page, self::BATCH );
		$messages   = LNKup_Comms_Schema::table( 'messages' );
		foreach ( $result['user_ids'] as $vendor_id ) {
			if ( $vendor_id === $sig['organizer_id'] || LNKup_Comms_Context::vendor_applied_to( $vendor_id, $opp_id ) ) {
				continue;
			}
			// One discovery message per vendor per opportunity, whichever trigger applies.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$messages} WHERE recipient_user_id = %d AND opportunity_id = %d AND trigger_key IN ('v_opportunity_match','v_organizer_new_opportunity')", $vendor_id, $opp_id ) );
			if ( $exists ) {
				continue;
			}
			LNKup_Comms_Engine::dispatch(
				isset( $worked[ $vendor_id ] ) ? 'v_organizer_new_opportunity' : 'v_opportunity_match',
				$vendor_id,
				array( 'opportunity_id' => $opp_id, 'vendor_user_id' => $vendor_id ),
				array( 'cancel_key' => 'opp:' . $opp_id, 'source' => 'discovery' )
			);
		}
		// Past collaborators who are not in the geographic match still hear from organizers they worked with.
		if ( 1 === $page ) {
			foreach ( array_keys( $worked ) as $vendor_id ) {
				if ( in_array( $vendor_id, $result['user_ids'], true ) || LNKup_Comms_Context::vendor_applied_to( (int) $vendor_id, $opp_id ) ) {
					continue;
				}
				LNKup_Comms_Engine::dispatch( 'v_organizer_new_opportunity', (int) $vendor_id, array( 'opportunity_id' => $opp_id, 'vendor_user_id' => (int) $vendor_id ), array( 'cancel_key' => 'opp:' . $opp_id, 'source' => 'discovery_worked_with' ) );
			}
		}
		if ( $result['has_more'] ) {
			LNKup_Comms_Queue::job( 'lnkup_comms_match_opportunity', array( $opp_id, $page + 1 ), 60 );
		}
	}

	/**
	 * Recruitment nudge to the organizer after an event goes live.
	 *
	 * @param int $event_id Event.
	 */
	public static function recruit_event( $event_id ): void {
		$event_id  = absint( $event_id );
		$organizer = (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true );
		if ( ! $organizer || ! LNKup_Comms_Settings::enabled( 'flow_recruitment' ) ) {
			return;
		}
		$count = LNKup_Comms_Matching::count_for_event( $event_id );
		LNKup_Comms_Engine::dispatch(
			'o_recruit_vendors',
			$organizer,
			array( 'event_id' => $event_id, 'count' => $count > 0 ? (string) $count : '' ),
			array( 'variant' => $count > 0 ? 'count' : 'generic', 'source' => 'recruitment' )
		);
	}
}

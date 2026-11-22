<?php
/**
 * Unfinished application drafts → at most two reminders per save (24h and 72h after the
 * last save). Every save reschedules; submit, discard and expiry cancel. The send-time
 * validity rule `draft_still_open` re-checks the draft, deadline and existing applications.
 *
 * Drafts are not applications: messages carry opportunity_id + draft_id, never application_id.
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_Listen_Drafts {

	const WINDOWS = array(
		'd24' => DAY_IN_SECONDS,
		'd72' => 3 * DAY_IN_SECONDS,
	);

	public static function init(): void {
		add_action( 'lnkup_application_draft_saved', array( __CLASS__, 'on_saved' ), 20, 4 );
		add_action( 'lnkup_application_draft_submitted', array( __CLASS__, 'on_closed' ), 20, 1 );
		add_action( 'lnkup_application_draft_closed', array( __CLASS__, 'on_closed' ), 20, 1 );
	}

	public static function cancel_key( int $draft_id ): string {
		return 'draft:' . $draft_id;
	}

	/**
	 * @param int $draft_id Draft.
	 * @param int $opp_id   Opportunity.
	 * @param int $vendor   Vendor user.
	 * @param int $revision Draft revision after this save.
	 */
	public static function on_saved( $draft_id, $opp_id, $vendor, $revision = 0 ): void {
		$draft_id = absint( $draft_id );
		$opp_id   = absint( $opp_id );
		$vendor   = absint( $vendor );
		if ( ! $draft_id || ! $opp_id || ! $vendor ) {
			return;
		}
		LNKup_Comms_Engine::cancel( self::cancel_key( $draft_id ), array(), 'draft_saved' );
		if ( ! LNKup_Comms_Settings::enabled( 'flow_application_drafts' ) ) {
			return;
		}
		$saved_gmt = (string) get_post_meta( $draft_id, '_lnkup_draft_updated_at_gmt', true );
		$saved     = '' !== $saved_gmt ? (int) strtotime( $saved_gmt . ' UTC' ) : time();
		$deadline  = self::deadline_ts( $opp_id );
		$revision  = (int) $revision ?: (int) get_post_meta( $draft_id, '_lnkup_draft_revision', true );

		foreach ( self::WINDOWS as $key => $offset ) {
			$at = $saved + $offset;
			// No reminder that would arrive after applications close.
			if ( $deadline && $at >= $deadline ) {
				continue;
			}
			LNKup_Comms_Engine::dispatch(
				'v_application_draft_reminder',
				$vendor,
				array(
					'opportunity_id' => $opp_id,
					'vendor_user_id' => $vendor,
					'draft_id'       => $draft_id,
					'draft_rev'      => $revision,
				),
				array(
					'window'     => $key . '_d' . $draft_id . '_r' . $revision,
					'variant'    => $key,
					'at'         => $at,
					'cancel_key' => self::cancel_key( $draft_id ),
					'source'     => 'application_draft',
				)
			);
		}
	}

	/**
	 * @param int $draft_id Draft that was submitted, discarded or expired.
	 */
	public static function on_closed( $draft_id ): void {
		$draft_id = absint( $draft_id );
		if ( $draft_id ) {
			LNKup_Comms_Engine::cancel( self::cancel_key( $draft_id ), array(), 'draft_closed' );
		}
	}

	/**
	 * End of the application deadline day in the event's timezone, as a UTC timestamp (0 = none).
	 *
	 * @param int $opp_id Opportunity.
	 */
	private static function deadline_ts( int $opp_id ): int {
		$deadline = trim( (string) get_post_meta( $opp_id, '_lnkup_application_deadline', true ) );
		if ( '' === $deadline ) {
			return 0;
		}
		$event_id = (int) get_post_meta( $opp_id, '_lnkup_event_id', true );
		$due      = date_create_immutable( $deadline . ( strlen( $deadline ) <= 10 ? ' 23:59:59' : '' ), LNKup_Comms_Context::event_timezone( $event_id ) );
		return $due instanceof DateTimeImmutable ? $due->getTimestamp() : 0;
	}
}

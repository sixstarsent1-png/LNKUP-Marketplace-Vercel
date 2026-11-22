<?php
/**
 * Organizer acquisition pipeline: stages, outcomes and move rules.
 *
 * Stages from first_event_published onward are milestone-only: they are reached when real
 * marketplace activity is recorded (LNKup_Org_Lead_Lifecycle), never by editing the CRM.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Org_Lead_Pipeline {

	const STAGES = array(
		'discovered'            => 'Discovered',
		'research_needed'       => 'Research Needed',
		'qualified'             => 'Qualified',
		'outreach_ready'        => 'Outreach Ready',
		'contacted'             => 'Contacted',
		'responded'             => 'Responded',
		'meeting_scheduled'     => 'Meeting Scheduled',
		'demo_completed'        => 'Demo Completed',
		'interested'            => 'Interested',
		'registration_started'  => 'Registration Started',
		'registered'            => 'Registered',
		'onboarding'            => 'Onboarding',
		'first_event_published' => 'First Event Published',
		'first_application'     => 'First Application Received',
		'first_paid_booking'    => 'First Paid Booking',
		'activated'             => 'Activated',
		'repeat_organizer'      => 'Repeat Organizer',
	);

	const OUTCOMES = array(
		'not_interested' => 'Not Interested',
		'unqualified'    => 'Unqualified',
		'unresponsive'   => 'Unresponsive',
		'do_not_contact' => 'Do Not Contact',
		'duplicate'      => 'Duplicate',
		'archived'       => 'Archived',
	);

	/** Stages only milestones may set. */
	const MILESTONE_STAGES = array( 'first_event_published', 'first_application', 'first_paid_booking', 'activated', 'repeat_organizer' );

	/** Stages that need a linked organizer account. */
	const ACCOUNT_STAGES = array( 'registered', 'onboarding' );

	/** milestone key => stage it implies. */
	const MILESTONE_TO_STAGE = array(
		'registered'            => 'registered',
		'first_event_published' => 'first_event_published',
		'first_application'     => 'first_application',
		'first_paid_booking'    => 'first_paid_booking',
		'activated'             => 'activated',
		'repeat_organizer'      => 'repeat_organizer',
	);

	public static function is_stage( string $stage ): bool {
		return isset( self::STAGES[ $stage ] );
	}

	public static function is_outcome( string $outcome ): bool {
		return isset( self::OUTCOMES[ $outcome ] );
	}

	public static function rank( string $stage ): int {
		$pos = array_search( $stage, array_keys( self::STAGES ), true );
		return false === $pos ? -1 : (int) $pos;
	}

	/**
	 * Validate a manual stage move.
	 *
	 * @param array<string, mixed> $lead  Lead row.
	 * @param string               $stage Target stage.
	 * @return true|WP_Error
	 */
	public static function validate_manual_move( array $lead, string $stage ) {
		if ( ! self::is_stage( $stage ) ) {
			return new WP_Error( 'lnkup_invalid_stage', __( 'Unknown pipeline stage.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( in_array( $stage, self::MILESTONE_STAGES, true ) ) {
			return new WP_Error(
				'lnkup_stage_milestone_only',
				__( 'This stage is reached automatically when the organizer publishes an event, receives applications or takes paid bookings. It cannot be set by hand.', 'lnkup-marketplace' ),
				array( 'status' => 409 )
			);
		}
		$current = (string) ( $lead['stage'] ?? '' );
		if ( in_array( $current, self::MILESTONE_STAGES, true ) && self::rank( $stage ) < self::rank( $current ) ) {
			return new WP_Error( 'lnkup_stage_backwards', __( 'This organizer already reached a verified milestone; the stage cannot be moved back.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		if ( in_array( $stage, self::ACCOUNT_STAGES, true ) && ! LNKup_Org_Leads::linked_user_ids( (int) $lead['id'] ) ) {
			return new WP_Error( 'lnkup_stage_needs_account', __( 'Link the organizer\'s LNKup account before marking them registered.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		return true;
	}

	/**
	 * Stage implied by a milestone, only ever moving forward.
	 */
	public static function stage_after_milestone( string $current, string $milestone ): string {
		$target = self::MILESTONE_TO_STAGE[ $milestone ] ?? '';
		if ( '' === $target ) {
			return $current;
		}
		return self::rank( $target ) > self::rank( $current ) ? $target : $current;
	}

	/**
	 * @return array<int, array{key:string,label:string,milestone_only:bool}>
	 */
	public static function describe(): array {
		$out = array();
		foreach ( self::STAGES as $key => $label ) {
			$out[] = array(
				'key'            => $key,
				'label'          => $label,
				'milestone_only' => in_array( $key, self::MILESTONE_STAGES, true ),
			);
		}
		return $out;
	}

	/**
	 * @return array<int, array{key:string,label:string}>
	 */
	public static function describe_outcomes(): array {
		$out = array();
		foreach ( self::OUTCOMES as $key => $label ) {
			$out[] = array(
				'key'   => $key,
				'label' => $label,
			);
		}
		return $out;
	}
}

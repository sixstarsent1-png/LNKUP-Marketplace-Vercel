<?php
/**
 * Boolean feature flags stored in the `lnkup_settings` option.
 *
 * Organizer Management OS flags (all off by default):
 * - application_review_v2_enabled: review queue, reviewer assignment, scoring, bulk preview.
 * - waitlist_offers_enabled:       offer an open spot to a waitlisted vendor with a deadline.
 * - organizer_ops_v2_enabled:          event tasks, event readiness checklist, new Overview.
 * - organizer_document_review_enabled: per-event document review (needs Passport document sharing).
 * Organization teams keep their existing flag, `trust_org_teams`.
 *
 * Organizer Portfolio OS (off by default):
 * - portfolio_os_enabled:  tour/series wizard, recurring generation, templates, bulk pricing,
 *                          tour dashboard and public tour pages.
 * - portfolio_os_user_ids: optional comma-separated organizer IDs that get the feature while the
 *                          global flag is off (staged rollout).
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Feature_Flags {

	const APPLICATION_REVIEW = 'application_review_v2_enabled';
	const WAITLIST_OFFERS    = 'waitlist_offers_enabled';
	const ORG_TEAMS          = 'trust_org_teams';
	const PORTFOLIO          = 'portfolio_os_enabled';
	const PORTFOLIO_USERS    = 'portfolio_os_user_ids';
	const ORGANIZER_OPS      = 'organizer_ops_v2_enabled';
	const DOCUMENT_REVIEW    = 'organizer_document_review_enabled';
	const ONBOARDING_V2      = 'organizer_onboarding_v2_enabled';

	/**
	 * Organizer onboarding v2: progressive signup fields, Get started routing by event format,
	 * resumable server-side state and the /organizer/onboarding REST contract. Global only:
	 * signup happens before there is a user to put in a rollout cohort.
	 */
	public static function onboarding_v2() {
		return self::enabled( self::ONBOARDING_V2 );
	}

	/**
	 * Event tasks, the event readiness checklist and the redesigned organizer Overview.
	 */
	public static function organizer_ops() {
		return self::organizer_os( self::ORGANIZER_OPS );
	}

	/**
	 * Per-event organizer document review. Files are only reachable through Passport shares,
	 * so the feature also needs Passport document sharing.
	 */
	public static function document_review() {
		return self::organizer_os( self::DOCUMENT_REVIEW )
			&& class_exists( 'LNKup_Passport_Settings' )
			&& LNKup_Passport_Settings::enabled( 'document_vault_sharing' );
	}

	/**
	 * @param string $key Settings key.
	 */
	public static function enabled( $key ) {
		$settings = lnkup_get_settings();
		return (bool) apply_filters( 'lnkup_feature_flag', ! empty( $settings[ $key ] ), $key );
	}

	const ORGANIZER_OS_PILOT = 'organizer_os_pilot_user_ids';

	public static function application_review() {
		return self::organizer_os( self::APPLICATION_REVIEW );
	}

	/**
	 * Waitlist offers are part of the review workspace, so both flags must be on.
	 */
	public static function waitlist_offers() {
		return self::application_review() && self::organizer_os( self::WAITLIST_OFFERS );
	}

	/**
	 * Organizer Management OS flag: on globally, or for pilot organizers (and their team members)
	 * listed in `organizer_os_pilot_user_ids` while the global flag is still off.
	 *
	 * @param string   $key     Flag key.
	 * @param int|null $user_id User (null = current user).
	 */
	public static function organizer_os( $key, $user_id = null ) {
		if ( self::enabled( $key ) ) {
			return true;
		}
		$pilot = self::pilot_ids();
		if ( ! $pilot ) {
			return false;
		}
		$user_id = null === $user_id ? get_current_user_id() : absint( $user_id );
		if ( ! $user_id ) {
			return false;
		}
		$on = in_array( $user_id, $pilot, true );
		if ( ! $on && class_exists( 'LNKup_Org_Teams' ) ) {
			$on = (bool) array_intersect( LNKup_Org_Teams::orgs_for_user( $user_id ), $pilot );
		}
		return (bool) apply_filters( 'lnkup_feature_flag', $on, $key . ':pilot' );
	}

	/**
	 * Organizer IDs in the Organizer OS pilot.
	 *
	 * @return int[]
	 */
	public static function pilot_ids() {
		$settings = lnkup_get_settings();
		return array_values( array_filter( array_map( 'absint', explode( ',', (string) ( $settings[ self::ORGANIZER_OS_PILOT ] ?? '' ) ) ) ) );
	}

	/**
	 * Whether a feature may be in use for anyone (global flag or a non-empty pilot). For crons.
	 *
	 * @param string $key Flag key.
	 */
	public static function organizer_os_anywhere( $key ) {
		return self::enabled( $key ) || (bool) self::pilot_ids();
	}

	/**
	 * Portfolio OS for a user: global flag, or the user is on the rollout allowlist.
	 * Public tour pages pass $user_id = 0 and only follow the global flag.
	 *
	 * @param int|null $user_id User (null = current user).
	 */
	public static function portfolio( $user_id = null ) {
		if ( self::enabled( self::PORTFOLIO ) ) {
			return true;
		}
		$user_id = null === $user_id ? get_current_user_id() : absint( $user_id );
		if ( ! $user_id ) {
			return false;
		}
		$settings = lnkup_get_settings();
		$allowed  = array_filter( array_map( 'absint', explode( ',', (string) ( $settings[ self::PORTFOLIO_USERS ] ?? '' ) ) ) );
		return (bool) apply_filters( 'lnkup_feature_flag', in_array( $user_id, $allowed, true ), self::PORTFOLIO_USERS );
	}
}

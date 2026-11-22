<?php
/**
 * Boolean feature flags stored in the `lnkup_settings` option.
 *
 * Organizer Management OS flags (all off by default):
 * - application_review_v2_enabled: review queue, reviewer assignment, scoring, bulk preview.
 * - waitlist_offers_enabled:       offer an open spot to a waitlisted vendor with a deadline.
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
	 * @param string $key Settings key.
	 */
	public static function enabled( $key ) {
		$settings = lnkup_get_settings();
		return (bool) apply_filters( 'lnkup_feature_flag', ! empty( $settings[ $key ] ), $key );
	}

	public static function application_review() {
		return self::enabled( self::APPLICATION_REVIEW );
	}

	/**
	 * Waitlist offers are part of the review workspace, so both flags must be on.
	 */
	public static function waitlist_offers() {
		return self::application_review() && self::enabled( self::WAITLIST_OFFERS );
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

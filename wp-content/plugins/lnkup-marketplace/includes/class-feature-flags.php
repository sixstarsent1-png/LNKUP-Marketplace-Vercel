<?php
/**
 * Boolean feature flags stored in the `lnkup_settings` option.
 *
 * Organizer Management OS flags (all off by default):
 * - application_review_v2_enabled: review queue, reviewer assignment, scoring, bulk preview.
 * - waitlist_offers_enabled:       offer an open spot to a waitlisted vendor with a deadline.
 * Organization teams keep their existing flag, `trust_org_teams`.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Feature_Flags {

	const APPLICATION_REVIEW = 'application_review_v2_enabled';
	const WAITLIST_OFFERS    = 'waitlist_offers_enabled';
	const ORG_TEAMS          = 'trust_org_teams';

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
}

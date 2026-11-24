<?php
/**
 * Recommendation funnel: impressions (which opportunities, band, position), clicks and
 * applications that followed a recommendation. Also guards the public client-event endpoint.
 *
 * Attribution is observed, not causal: it records that an application followed a recommendation.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Match_Funnel {

	const ATTRIBUTION_DAYS = 14;
	const MAX_ITEMS        = 24;
	const RATE_PER_MINUTE  = 120;

	/**
	 * Event types clients may send to /marketplace/track/event (Next.js, Expo, WordPress JS).
	 * Server-recorded types (impressions, attribution, searches, views) are never accepted from clients.
	 */
	const CLIENT_EVENTS = array(
		// Matching.
		'match_click', 'recommendation_clicked',
		// Event media (data.media_source = resolved image source; no personal data).
		'event_card_clicked', 'event_card_image_failed',
		// WordPress JS.
		'saved_search_match_clicked', 'market_page_view', 'market_opportunity_click',
		// Expo.
		'organizer_experiment_viewed', 'organizer_experiment_recommendation_opened', 'organizer_experiment_recommendation_action_clicked',
		'organizer_experiment_decision_recorded', 'tutorial_viewed', 'tutorial_step_clicked', 'tutorial_step_completed',
		'tutorial_push_cta_clicked', 'map_view_opened', 'map_pin_clicked',
		// Next.js (lib/analytics/lnkup-events.ts).
		'landing_cta_clicked', 'browse_opportunities_clicked', 'login_started', 'vendor_onboarding_started',
		'vendor_onboarding_step_completed', 'profile_completion_clicked', 'document_upload_clicked', 'save_opportunity_clicked',
		'save_search_clicked', 'apply_clicked', 'apply_prelaunch_blocked', 'apply_submitted', 'payment_handoff_clicked',
		'payment_return_landed', 'payment_return_reconcile_started', 'payment_return_confirmed', 'payment_return_timeout',
		'payment_return_manual_retry', 'share_link_copied', 'deep_opportunity_opened', 'vendor_pass_viewed',
		'vendor_operations_viewed', 'vendor_locked_pass_viewed', 'vendor_unlocked_pass_viewed', 'vendor_calendar_added',
		'vendor_directions_clicked', 'vendor_support_started', 'vendor_support_submitted', 'vendor_rules_viewed',
		'vendor_loadin_viewed', 'wordpress_operations_handoff_clicked', 'search_performed', 'filter_changed', 'filter_cleared',
		'near_me_clicked', 'near_me_allowed', 'near_me_denied', 'market_page_viewed', 'saved_search_opened', 'zero_results_shown',
		'organizer_landing_viewed', 'organizer_join_clicked', 'organizer_onboarding_started', 'organizer_onboarding_step_completed',
		'launch_event_started', 'launch_event_draft_saved', 'launch_event_publish_clicked', 'launch_event_published',
		'launch_event_handoff_clicked', 'organizer_event_workspace_viewed', 'organizer_vendor_directory_viewed',
		'organizer_vendor_profile_opened', 'organizer_review_action_attempted', 'organizer_review_action_succeeded',
		'organizer_review_action_failed', 'organizer_list_action_clicked', 'organizer_invite_action_clicked',
		'organizer_vendor_risk_viewed', 'application_link_copied', 'opportunity_preview_clicked', 'wordpress_dashboard_opened',
		'vendor_reputation_viewed', 'vendor_review_card_viewed', 'vendor_badge_viewed', 'public_vendor_profile_viewed',
		'organizer_reputation_preview_viewed', 'vendor_feedback_started', 'vendor_feedback_submitted', 'share_vendor_profile_clicked',
		'vendor_wallet_viewed', 'vendor_readiness_blocker_viewed', 'vendor_readiness_action_clicked', 'vendor_payment_detail_viewed',
		'vendor_continue_payment_clicked', 'vendor_receipt_clicked', 'vendor_invoice_clicked', 'vendor_credits_viewed',
		'vendor_dispute_opened', 'vendor_dispute_handoff_clicked', 'organizer_money_viewed', 'organizer_event_money_viewed',
		'financial_handoff_clicked', 'wordpress_financial_center_opened', 'notifications_viewed', 'notification_clicked',
		'notification_marked_read', 'notification_mark_all_read', 'notification_preferences_viewed', 'notification_preference_changed',
		'saved_search_alert_frequency_changed', 'saved_search_resumed', 'pwa_install_prompt_shown', 'pwa_install_clicked',
		'pwa_install_dismissed', 'wordpress_notification_settings_opened',
	);

	/** Client events that only make sense for a signed-in user. */
	const SIGNED_IN_EVENTS = array( 'match_click' );

	const SECTIONS = array( 'recommended', 'preferred_markets', 'new', 'deadlines', 'previous_organizers', 'previously_worked', 'tours', 'series', 'home', 'find', 'alert' );

	public static function init() {
		add_action( 'lnkup_application_submitted', array( __CLASS__, 'attribute_application' ), 25 );
	}

	/**
	 * Validate, throttle and record one client event.
	 *
	 * @param string $type     Raw event type.
	 * @param mixed  $data     Raw data.
	 * @param int    $opp_id   Opportunity ID.
	 * @return array{recorded:bool,reason:string}|WP_Error
	 */
	public static function track_client_event( $type, $data, $opp_id ) {
		$type = sanitize_key( (string) $type );
		if ( 'recommendation_clicked' === $type ) {
			$type = 'match_click';
		}
		if ( ! in_array( $type, self::CLIENT_EVENTS, true ) ) {
			return array( 'recorded' => false, 'reason' => 'unknown_event' );
		}
		$user_id = get_current_user_id();
		if ( ! $user_id && in_array( $type, self::SIGNED_IN_EVENTS, true ) ) {
			return array( 'recorded' => false, 'reason' => 'sign_in_required' );
		}
		if ( ! self::within_rate( $user_id ) ) {
			return new WP_Error( 'lnkup_rate_limited', __( 'Too many events. Try again shortly.', 'lnkup-marketplace' ), array( 'status' => 429 ) );
		}
		$clean = self::clean_data( is_array( $data ) ? $data : array() );
		$opp   = absint( $opp_id ?: ( $clean['opportunity_id'] ?? 0 ) );
		if ( 'match_click' === $type ) {
			if ( ! $opp ) {
				return array( 'recorded' => false, 'reason' => 'opportunity_required' );
			}
			$clean = array(
				'section'       => in_array( (string) ( $clean['section'] ?? '' ), self::SECTIONS, true ) ? (string) $clean['section'] : 'find',
				'position'      => max( 0, min( 500, (int) ( $clean['position'] ?? 0 ) ) ),
				'band'          => in_array( (string) ( $clean['band'] ?? '' ), array( 'strong', 'good', 'possible' ), true ) ? (string) $clean['band'] : self::cached_band( $user_id, $opp ),
				'rules_version' => self::rules_version(),
				'source'        => substr( sanitize_key( (string) ( $clean['source'] ?? '' ) ), 0, 32 ),
			);
		}
		LNKup_Marketplace_Analytics::track( $type, $clean, $user_id, $opp );
		return array( 'recorded' => true, 'reason' => '' );
	}

	/**
	 * Record which recommendations a vendor was shown.
	 *
	 * @param int    $user_id Vendor.
	 * @param string $section Section key.
	 * @param array  $items   Recommendation items (opportunity_id, match.band).
	 */
	public static function impression( $user_id, $section, array $items ) {
		$rows = array();
		foreach ( array_slice( array_values( $items ), 0, self::MAX_ITEMS ) as $i => $item ) {
			$opp = (int) ( $item['opportunity_id'] ?? 0 );
			if ( $opp ) {
				$rows[] = array(
					'opportunity_id' => $opp,
					'band'           => (string) ( $item['match']['band'] ?? '' ),
					'position'       => $i + 1,
				);
			}
		}
		$cohort = class_exists( 'LNKup_Rollout' ) ? LNKup_Rollout::config( 'matching_vendor_discovery_enabled' )['audience'] : 'all';
		LNKup_Marketplace_Analytics::track(
			'match_impression',
			array(
				'section'       => sanitize_key( (string) $section ),
				'count'         => count( $items ),
				'items'         => $rows,
				'rules_version' => self::rules_version(),
				'cohort'        => $cohort,
			),
			(int) $user_id
		);
	}

	/**
	 * On submit: if the vendor clicked or was shown this opportunity as a recommendation recently,
	 * record match_rec_application. A click wins over an impression.
	 *
	 * @param int $app_id Application ID.
	 */
	public static function attribute_application( $app_id ) {
		global $wpdb;
		$app_id = absint( $app_id );
		$vendor = (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true );
		$opp    = (int) get_post_meta( $app_id, '_lnkup_opportunity_id', true );
		if ( ! $vendor || ! $opp ) {
			return;
		}
		$table = LNKup_Marketplace_Analytics::table_name();
		$since = wp_date( 'Y-m-d H:i:s', time() - self::ATTRIBUTION_DAYS * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$click = $wpdb->get_var( $wpdb->prepare( "SELECT event_data FROM {$table} WHERE event_type = 'match_click' AND user_id = %d AND opportunity_id = %d AND created_at >= %s ORDER BY id DESC LIMIT 1", $vendor, $opp, $since ) );
		$data  = null;
		$via   = '';
		if ( null !== $click ) {
			$data = json_decode( (string) $click, true );
			$via  = 'click';
		} else {
			$needle = '%"opportunity_id":' . $opp . ',%';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$seen = $wpdb->get_var( $wpdb->prepare( "SELECT event_data FROM {$table} WHERE event_type = 'match_impression' AND user_id = %d AND created_at >= %s AND event_data LIKE %s ORDER BY id DESC LIMIT 1", $vendor, $since, $needle ) );
			if ( null !== $seen ) {
				$decoded = json_decode( (string) $seen, true );
				$item    = array();
				foreach ( (array) ( $decoded['items'] ?? array() ) as $row ) {
					if ( (int) ( $row['opportunity_id'] ?? 0 ) === $opp ) {
						$item = $row;
						break;
					}
				}
				$data = array(
					'section'       => (string) ( $decoded['section'] ?? '' ),
					'band'          => (string) ( $item['band'] ?? '' ),
					'position'      => (int) ( $item['position'] ?? 0 ),
					'rules_version' => (int) ( $decoded['rules_version'] ?? 0 ),
				);
				$via = 'impression';
			}
		}
		if ( ! is_array( $data ) ) {
			return;
		}
		LNKup_Marketplace_Analytics::track(
			'match_rec_application',
			array(
				'section'        => (string) ( $data['section'] ?? '' ),
				'band'           => (string) ( $data['band'] ?? '' ),
				'position'       => (int) ( $data['position'] ?? 0 ),
				'rules_version'  => (int) ( $data['rules_version'] ?? 0 ),
				'via'            => $via,
				'application_id' => $app_id,
			),
			$vendor,
			$opp
		);
	}

	/**
	 * Current matching rules version (0 before versioning was introduced).
	 */
	public static function rules_version() {
		return class_exists( 'LNKup_Match_Settings' ) && method_exists( 'LNKup_Match_Settings', 'rules_version' ) ? LNKup_Match_Settings::rules_version() : 0;
	}

	/**
	 * Band of the cached match for a vendor + opportunity, or ''.
	 */
	private static function cached_band( $user_id, $opp_id ) {
		if ( ! $user_id || ! class_exists( 'LNKup_Match_Tables' ) ) {
			return '';
		}
		global $wpdb;
		$profile = (int) lnkup_get_vendor_profile_id( (int) $user_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$score = $profile ? $wpdb->get_var( $wpdb->prepare( 'SELECT score FROM ' . LNKup_Match_Tables::results_table() . ' WHERE profile_id = %d AND opportunity_id = %d', $profile, (int) $opp_id ) ) : null;
		return null === $score ? '' : LNKup_Match_Engine::band( (int) $score );
	}

	/**
	 * Per-user (or per-IP for anonymous) events-per-minute limit.
	 *
	 * @param int $user_id User.
	 */
	private static function within_rate( $user_id ) {
		$who   = $user_id ? 'u' . $user_id : 'ip' . md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) . wp_salt( 'nonce' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$key   = 'lnkup_evt_rate_' . substr( md5( $who . gmdate( 'YmdHi' ) ), 0, 20 );
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_PER_MINUTE ) {
			return false;
		}
		set_transient( $key, $count + 1, 2 * MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Flat, scalar-only event data with short values (max 20 keys).
	 *
	 * @param array $data Raw data.
	 * @return array<string,scalar>
	 */
	private static function clean_data( array $data ) {
		$out = array();
		foreach ( array_slice( $data, 0, 20, true ) as $key => $value ) {
			$key = substr( sanitize_key( (string) $key ), 0, 40 );
			if ( '' === $key || ! ( is_scalar( $value ) || null === $value ) ) {
				continue;
			}
			$out[ $key ] = is_string( $value ) ? substr( sanitize_text_field( $value ), 0, 200 ) : $value;
		}
		return $out;
	}
}

<?php
/**
 * Catalog of notification categories and triggers.
 *
 * A trigger describes WHAT a message is (audience, category, priority, channels,
 * template, deep link, feature flag, validity rule). Listeners and automations
 * decide WHEN to dispatch it; the engine decides HOW it is delivered.
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_Registry {

	const PRIORITIES = array( 'critical', 'high', 'normal', 'promotional' );

	/**
	 * Preference categories. `tab` maps to the inbox tab per role; `legacy` maps to
	 * the lnkup-marketplace push category so core push honours the same choice.
	 */
	public static function categories(): array {
		return array(
			'applications'  => array(
				'label'       => __( 'Application updates', 'lnkup-communications' ),
				'description' => __( 'Submissions, decisions, information requests and new vendor requests.', 'lnkup-communications' ),
				'roles'       => array( 'vendor', 'organizer' ),
				'promotional' => false,
				'locked'      => false,
				'tab'         => array( 'vendor' => 'applications', 'organizer' => 'applications' ),
				'legacy'      => 'applications',
			),
			'payments'      => array(
				'label'       => __( 'Payments & bookings', 'lnkup-communications' ),
				'description' => __( 'Payment due, receipts, failed payments, refunds and confirmed bookings.', 'lnkup-communications' ),
				'roles'       => array( 'vendor', 'organizer' ),
				'promotional' => false,
				'locked'      => false,
				'tab'         => array( 'vendor' => 'payments', 'organizer' => 'payments' ),
				'legacy'      => 'payments',
			),
			'documents'     => array(
				'label'       => __( 'Documents & compliance', 'lnkup-communications' ),
				'description' => __( 'Required documents, approvals, rejections and expiring documents.', 'lnkup-communications' ),
				'roles'       => array( 'vendor' ),
				'promotional' => false,
				'locked'      => false,
				'tab'         => array( 'vendor' => 'events', 'organizer' => 'operations' ),
				'legacy'      => 'packets',
			),
			'reputation'    => array(
				'label'       => __( 'Passport, reviews & verification', 'lnkup-communications' ),
				'description' => __( 'Verification decisions, organizer reviews, responses and moderation outcomes.', 'lnkup-communications' ),
				'roles'       => array( 'vendor', 'organizer' ),
				'promotional' => false,
				'locked'      => false,
				'tab'         => array( 'vendor' => 'all', 'organizer' => 'all' ),
				'legacy'      => 'account',
			),
			'event_ops'     => array(
				'label'       => __( 'Event operations', 'lnkup-communications' ),
				'description' => __( 'Event readiness, setup, check-in, schedule and location changes.', 'lnkup-communications' ),
				'roles'       => array( 'vendor', 'organizer' ),
				'promotional' => false,
				'locked'      => false,
				'tab'         => array( 'vendor' => 'events', 'organizer' => 'events' ),
				'legacy'      => 'packets',
			),
			'announcements' => array(
				'label'       => __( 'Organizer announcements', 'lnkup-communications' ),
				'description' => __( 'Messages from organizers of events you are part of.', 'lnkup-communications' ),
				'roles'       => array( 'vendor' ),
				'promotional' => false,
				'locked'      => false,
				'tab'         => array( 'vendor' => 'events', 'organizer' => 'operations' ),
				'legacy'      => 'packets',
			),
			'opportunities' => array(
				'label'       => __( 'New opportunities for you', 'lnkup-communications' ),
				'description' => __( 'Events accepting vendors that match your categories and markets.', 'lnkup-communications' ),
				'roles'       => array( 'vendor' ),
				'promotional' => true,
				'locked'      => false,
				'tab'         => array( 'vendor' => 'opportunities', 'organizer' => 'operations' ),
				'legacy'      => 'discovery',
			),
			'digests'       => array(
				'label'       => __( 'Summaries & digests', 'lnkup-communications' ),
				'description' => __( 'Daily or weekly summaries of opportunities and event operations.', 'lnkup-communications' ),
				'roles'       => array( 'vendor', 'organizer' ),
				'promotional' => false,
				'locked'      => false,
				'tab'         => array( 'vendor' => 'opportunities', 'organizer' => 'operations' ),
				'legacy'      => 'discovery',
			),
			'recruitment'   => array(
				'label'       => __( 'Vendor recruitment & growth tips', 'lnkup-communications' ),
				'description' => __( 'Help filling vendor slots and planning your next event.', 'lnkup-communications' ),
				'roles'       => array( 'organizer' ),
				'promotional' => true,
				'locked'      => false,
				'tab'         => array( 'vendor' => 'opportunities', 'organizer' => 'operations' ),
				'legacy'      => 'discovery',
			),
			'onboarding'    => array(
				'label'       => __( 'Getting started', 'lnkup-communications' ),
				'description' => __( 'Setup guidance while your account is new.', 'lnkup-communications' ),
				'roles'       => array( 'vendor', 'organizer' ),
				'promotional' => false,
				'locked'      => false,
				'tab'         => array( 'vendor' => 'all', 'organizer' => 'all' ),
				'legacy'      => 'account',
			),
			'product_news'  => array(
				'label'       => __( 'LNKup news & promotions', 'lnkup-communications' ),
				'description' => __( 'New features, partnerships and marketplace insights.', 'lnkup-communications' ),
				'roles'       => array( 'vendor', 'organizer' ),
				'promotional' => true,
				'locked'      => false,
				'tab'         => array( 'vendor' => 'all', 'organizer' => 'all' ),
				'legacy'      => 'account',
			),
			'partnerships'  => array(
				'label'       => __( 'Partner program updates', 'lnkup-communications' ),
				'description' => __( 'Referral progress, commission records and partner program decisions.', 'lnkup-communications' ),
				'roles'       => array( 'partner', 'vendor', 'organizer' ),
				'promotional' => false,
				'locked'      => false,
				'tab'         => array( 'vendor' => 'all', 'organizer' => 'all', 'partner' => 'all' ),
				'legacy'      => 'account',
			),
			'account'       => array(
				'label'       => __( 'Account & security', 'lnkup-communications' ),
				'description' => __( 'Security and account notices. These cannot be turned off.', 'lnkup-communications' ),
				'roles'       => array( 'vendor', 'organizer' ),
				'promotional' => false,
				'locked'      => true,
				'tab'         => array( 'vendor' => 'all', 'organizer' => 'all' ),
				'legacy'      => 'account',
			),
		);
	}

	/**
	 * Inbox tabs per role.
	 */
	public static function tabs( string $role ): array {
		if ( 'partner' === $role ) {
			return array( 'all' );
		}
		if ( 'organizer' === $role ) {
			return array( 'all', 'applications', 'payments', 'events', 'operations' );
		}
		return array( 'all', 'applications', 'payments', 'events', 'opportunities' );
	}

	/**
	 * Trigger definitions.
	 *
	 * Keys: role (vendor|organizer|any|admin), category, priority, channels, flag
	 * ('' = always on), link (LNKup_Comms_Context::deep_link key), type (mobile/inbox
	 * notification type), validity (LNKup_Comms_Context::check rule), expires (seconds).
	 */
	public static function triggers(): array {
		static $triggers = null;
		if ( null !== $triggers ) {
			return $triggers;
		}
		$all = array( 'inbox', 'email', 'push' );
		$day = DAY_IN_SECONDS;

		$triggers = array(
			// ---------------------------------------------------------------- Vendor: applications.
			'v_application_submitted' => array( 'role' => 'vendor', 'category' => 'applications', 'priority' => 'normal', 'channels' => array( 'inbox', 'email' ), 'flag' => 'flow_applications', 'link' => 'booking', 'type' => 'application_submitted', 'expires' => 7 * $day ),
			'v_application_approved'  => array( 'role' => 'vendor', 'category' => 'applications', 'priority' => 'high', 'channels' => $all, 'flag' => 'flow_applications', 'link' => 'approval', 'type' => 'application_approved', 'validity' => 'app_is_approved', 'expires' => 14 * $day ),
			'v_application_rejected'  => array( 'role' => 'vendor', 'category' => 'applications', 'priority' => 'normal', 'channels' => $all, 'flag' => 'flow_applications', 'link' => 'booking', 'type' => 'application_rejected', 'validity' => 'app_is_rejected', 'expires' => 7 * $day ),
			'v_application_waitlisted' => array( 'role' => 'vendor', 'category' => 'applications', 'priority' => 'normal', 'channels' => $all, 'flag' => 'flow_applications', 'link' => 'booking', 'type' => 'application_waitlisted', 'validity' => 'app_is_waitlisted', 'expires' => 7 * $day ),
			'v_info_requested'        => array( 'role' => 'vendor', 'category' => 'applications', 'priority' => 'high', 'channels' => $all, 'flag' => 'flow_applications', 'link' => 'booking', 'type' => 'info_requested', 'validity' => 'app_info_requested', 'expires' => 14 * $day ),
			'v_application_withdrawn' => array( 'role' => 'vendor', 'category' => 'applications', 'priority' => 'normal', 'channels' => array( 'inbox', 'email' ), 'flag' => 'flow_applications', 'link' => 'booking', 'type' => 'application_withdrawn', 'expires' => 3 * $day ),
			'v_application_cancelled' => array( 'role' => 'vendor', 'category' => 'applications', 'priority' => 'high', 'channels' => $all, 'flag' => 'flow_applications', 'link' => 'booking', 'type' => 'application_cancelled', 'validity' => 'app_is_cancelled', 'expires' => 7 * $day ),

			// ---------------------------------------------------------------- Vendor: payments.
			'v_payment_reminder'      => array( 'role' => 'vendor', 'category' => 'payments', 'priority' => 'high', 'channels' => $all, 'flag' => 'flow_payments', 'link' => 'wallet', 'type' => 'payment_due_soon', 'validity' => 'payment_is_due', 'expires' => 2 * $day ),
			'v_payment_failed'        => array( 'role' => 'vendor', 'category' => 'payments', 'priority' => 'high', 'channels' => $all, 'flag' => 'flow_payments', 'link' => 'wallet', 'type' => 'payment_failed', 'validity' => 'payment_failed', 'expires' => 3 * $day ),
			'v_booking_confirmed'     => array( 'role' => 'vendor', 'category' => 'payments', 'priority' => 'high', 'channels' => $all, 'flag' => 'flow_payments', 'link' => 'pass', 'type' => 'payment_paid', 'validity' => 'payment_confirmed', 'expires' => 30 * $day ),
			'v_receipt_available'     => array( 'role' => 'vendor', 'category' => 'payments', 'priority' => 'normal', 'channels' => array( 'inbox' ), 'flag' => 'flow_payments', 'link' => 'wallet', 'type' => 'receipt_available', 'expires' => 30 * $day ),
			'v_refund_completed'      => array( 'role' => 'vendor', 'category' => 'payments', 'priority' => 'normal', 'channels' => array( 'inbox', 'email', 'push' ), 'flag' => 'flow_payments', 'link' => 'wallet', 'type' => 'refund_completed', 'validity' => 'payment_refunded', 'expires' => 14 * $day ),

			// ---------------------------------------------------------------- Vendor: documents.
			'v_documents_needed'      => array( 'role' => 'vendor', 'category' => 'documents', 'priority' => 'high', 'channels' => $all, 'flag' => 'flow_documents', 'link' => 'documents', 'type' => 'document_missing', 'validity' => 'documents_outstanding', 'expires' => 3 * $day ),
			'v_document_approved'     => array( 'role' => 'vendor', 'category' => 'documents', 'priority' => 'normal', 'channels' => array( 'inbox', 'push' ), 'flag' => 'flow_documents', 'link' => 'documents', 'type' => 'document_approved', 'validity' => 'document_approved', 'expires' => 7 * $day ),
			'v_document_rejected'     => array( 'role' => 'vendor', 'category' => 'documents', 'priority' => 'high', 'channels' => $all, 'flag' => 'flow_documents', 'link' => 'documents', 'type' => 'document_rejected', 'validity' => 'document_rejected', 'expires' => 7 * $day ),
			'v_document_expiring'     => array( 'role' => 'vendor', 'category' => 'documents', 'priority' => 'normal', 'channels' => array( 'inbox', 'email' ), 'flag' => 'flow_documents', 'link' => 'documents', 'type' => 'document_expiring', 'expires' => 7 * $day ),

			// ---------------------------------------------------------------- Vendor Passport (verification, reviews, invites).
			'v_verification_update'   => array( 'role' => 'vendor', 'category' => 'reputation', 'priority' => 'high', 'channels' => $all, 'flag' => 'flow_passport', 'link' => 'passport', 'type' => 'passport_verification', 'expires' => 14 * $day ),
			'v_verification_expiring' => array( 'role' => 'vendor', 'category' => 'reputation', 'priority' => 'normal', 'channels' => array( 'inbox', 'email' ), 'flag' => 'flow_passport', 'link' => 'passport', 'type' => 'passport_verification_expiring', 'expires' => 14 * $day ),
			'v_review_received'       => array( 'role' => 'vendor', 'category' => 'reputation', 'priority' => 'normal', 'channels' => $all, 'flag' => 'flow_passport', 'link' => 'passport', 'type' => 'passport_review_received', 'expires' => 14 * $day ),
			'v_review_moderated'      => array( 'role' => 'vendor', 'category' => 'reputation', 'priority' => 'normal', 'channels' => array( 'inbox', 'email' ), 'flag' => 'flow_passport', 'link' => 'passport', 'type' => 'passport_review_moderated', 'expires' => 14 * $day ),
			'v_tour_invite'           => array( 'role' => 'vendor', 'category' => 'opportunities', 'priority' => 'normal', 'channels' => $all, 'flag' => 'flow_passport', 'link' => 'tour', 'type' => 'discovery_invite', 'expires' => 14 * $day ),
			'o_review_response'       => array( 'role' => 'organizer', 'category' => 'reputation', 'priority' => 'normal', 'channels' => array( 'inbox' ), 'flag' => 'flow_passport', 'link' => 'org_vendors', 'type' => 'organizer_review_response', 'expires' => 14 * $day ),
			'o_review_moderated'      => array( 'role' => 'organizer', 'category' => 'reputation', 'priority' => 'normal', 'channels' => array( 'inbox', 'email' ), 'flag' => 'flow_passport', 'link' => 'org_vendors', 'type' => 'organizer_review_moderated', 'expires' => 14 * $day ),
			'o_document_shared'       => array( 'role' => 'organizer', 'category' => 'applications', 'priority' => 'normal', 'channels' => array( 'inbox', 'push' ), 'flag' => 'flow_passport', 'link' => 'org_review', 'type' => 'organizer_document_shared', 'expires' => 7 * $day ),
			'o_shared_document_expiring' => array( 'role' => 'organizer', 'category' => 'applications', 'priority' => 'normal', 'channels' => array( 'inbox' ), 'flag' => 'flow_passport', 'link' => 'org_review', 'type' => 'organizer_vendor_missing_documents', 'expires' => 7 * $day ),

			// ---------------------------------------------------------------- Vendor: operations.
			'v_pass_updated'          => array( 'role' => 'vendor', 'category' => 'event_ops', 'priority' => 'normal', 'channels' => array( 'inbox', 'push' ), 'flag' => 'flow_operations', 'link' => 'pass', 'type' => 'pass_updated', 'validity' => 'booking_active', 'expires' => 3 * $day ),
			'v_booth_assigned'        => array( 'role' => 'vendor', 'category' => 'event_ops', 'priority' => 'normal', 'channels' => array( 'inbox', 'push' ), 'flag' => 'flow_operations', 'link' => 'pass', 'type' => 'booth_assigned', 'validity' => 'booking_active', 'expires' => 3 * $day ),
			'v_logistics_changed'     => array( 'role' => 'vendor', 'category' => 'event_ops', 'priority' => 'high', 'channels' => $all, 'flag' => 'flow_operations', 'link' => 'pass', 'type' => 'event_logistics_changed', 'validity' => 'booking_active', 'expires' => 2 * $day ),
			'v_event_cancelled'       => array( 'role' => 'vendor', 'category' => 'event_ops', 'priority' => 'critical', 'channels' => $all, 'flag' => 'flow_operations', 'link' => 'booking', 'type' => 'event_cancelled', 'validity' => 'event_is_cancelled', 'expires' => 7 * $day ),
			'v_checked_in'            => array( 'role' => 'vendor', 'category' => 'event_ops', 'priority' => 'normal', 'channels' => array( 'inbox', 'push' ), 'flag' => 'flow_operations', 'link' => 'pass', 'type' => 'checked_in', 'expires' => $day ),
			'v_countdown'             => array( 'role' => 'vendor', 'category' => 'event_ops', 'priority' => 'normal', 'channels' => $all, 'flag' => 'flow_countdown', 'link' => 'pass', 'type' => 'event_reminder', 'validity' => 'booking_upcoming', 'expires' => $day ),
			'v_announcement'          => array( 'role' => 'vendor', 'category' => 'announcements', 'priority' => 'normal', 'channels' => $all, 'flag' => 'organizer_broadcasts', 'link' => 'pass', 'type' => 'organizer_announcement', 'validity' => 'event_not_cancelled_or_critical', 'expires' => 3 * $day ),
			'v_event_thanks'          => array( 'role' => 'vendor', 'category' => 'event_ops', 'priority' => 'normal', 'channels' => array( 'inbox', 'email' ), 'flag' => 'flow_post_event', 'link' => 'vendor_find', 'type' => 'event_completed', 'expires' => 7 * $day ),

			// ---------------------------------------------------------------- Vendor: engagement.
			'v_welcome'               => array( 'role' => 'vendor', 'category' => 'onboarding', 'priority' => 'normal', 'channels' => array( 'inbox', 'email' ), 'flag' => 'flow_onboarding', 'link' => 'vendor_profile', 'type' => 'activation_profile', 'expires' => 3 * $day ),
			'v_onboarding'            => array( 'role' => 'vendor', 'category' => 'onboarding', 'priority' => 'normal', 'channels' => $all, 'flag' => 'flow_onboarding', 'link' => 'vendor_step', 'type' => 'activation_profile', 'validity' => 'vendor_onboarding_step', 'expires' => 2 * $day ),
			'v_opportunity_match'     => array( 'role' => 'vendor', 'category' => 'opportunities', 'priority' => 'promotional', 'channels' => $all, 'flag' => 'flow_discovery', 'link' => 'opportunity', 'type' => 'discovery_profile_match', 'validity' => 'match_still_recommended', 'expires' => 5 * $day ),
			'v_organizer_new_opportunity' => array( 'role' => 'vendor', 'category' => 'opportunities', 'priority' => 'promotional', 'channels' => $all, 'flag' => 'flow_discovery', 'link' => 'opportunity', 'type' => 'discovery_favorite_organizer', 'validity' => 'opportunity_still_eligible', 'expires' => 5 * $day ),
			'v_tour_match'            => array( 'role' => 'vendor', 'category' => 'opportunities', 'priority' => 'promotional', 'channels' => $all, 'flag' => 'flow_discovery_tours', 'link' => 'tour', 'type' => 'discovery_tour_match', 'validity' => 'tour_has_matching_stop', 'expires' => 7 * $day ),
			'v_weekly_matches'        => array( 'role' => 'vendor', 'category' => 'opportunities', 'priority' => 'promotional', 'channels' => array( 'inbox', 'email' ), 'flag' => 'flow_discovery_weekly', 'link' => 'vendor_find', 'type' => 'discovery_weekly_matches', 'expires' => 3 * $day ),
			'v_saved_search_match'    => array( 'role' => 'vendor', 'category' => 'opportunities', 'priority' => 'normal', 'channels' => $all, 'flag' => 'flow_discovery', 'link' => 'vendor_saved_searches', 'type' => 'discovery_saved_search', 'expires' => 3 * $day ),
			'v_saved_deadline'        => array( 'role' => 'vendor', 'category' => 'opportunities', 'priority' => 'normal', 'channels' => $all, 'flag' => 'flow_discovery', 'link' => 'opportunity', 'type' => 'discovery_favorite_deadline', 'validity' => 'saved_still_open', 'expires' => 2 * $day ),
			'v_invited_to_apply'      => array( 'role' => 'vendor', 'category' => 'opportunities', 'priority' => 'normal', 'channels' => $all, 'flag' => 'flow_discovery', 'link' => 'opportunity', 'type' => 'discovery_invite', 'validity' => 'invite_still_open', 'expires' => 14 * $day ),

			// ---------------------------------------------------------------- Organizer: applications.
			'o_application_received'  => array( 'role' => 'organizer', 'category' => 'applications', 'priority' => 'high', 'channels' => $all, 'flag' => 'flow_applications', 'link' => 'org_review', 'type' => 'organizer_new_vendor_request', 'validity' => 'org_has_pending', 'expires' => 3 * $day ),
			'o_applications_grouped'  => array( 'role' => 'organizer', 'category' => 'applications', 'priority' => 'normal', 'channels' => $all, 'flag' => 'flow_applications', 'link' => 'org_review', 'type' => 'organizer_new_vendor_request', 'validity' => 'org_has_pending', 'expires' => 2 * $day ),
			'o_info_response'         => array( 'role' => 'organizer', 'category' => 'applications', 'priority' => 'normal', 'channels' => $all, 'flag' => 'flow_applications', 'link' => 'org_review', 'type' => 'organizer_info_response', 'expires' => 7 * $day ),
			'o_application_withdrawn' => array( 'role' => 'organizer', 'category' => 'applications', 'priority' => 'normal', 'channels' => array( 'inbox', 'push' ), 'flag' => 'flow_applications', 'link' => 'org_review', 'type' => 'organizer_application_withdrawn', 'expires' => 3 * $day ),
			'o_waitlist_spot'         => array( 'role' => 'organizer', 'category' => 'applications', 'priority' => 'normal', 'channels' => array( 'inbox', 'push' ), 'flag' => 'flow_applications', 'link' => 'org_review', 'type' => 'organizer_waitlist_spot', 'expires' => 2 * $day ),
			'o_pending_review'        => array( 'role' => 'organizer', 'category' => 'applications', 'priority' => 'normal', 'channels' => $all, 'flag' => 'flow_digests', 'link' => 'org_review', 'type' => 'organizer_new_vendor_request', 'validity' => 'org_has_stale_pending', 'expires' => $day ),

			// ---------------------------------------------------------------- Organizer: payments.
			'o_vendor_paid'           => array( 'role' => 'organizer', 'category' => 'payments', 'priority' => 'normal', 'channels' => $all, 'flag' => 'flow_payments', 'link' => 'org_event', 'type' => 'organizer_vendor_paid', 'validity' => 'payment_confirmed', 'expires' => 7 * $day ),
			'o_vendor_payment_failed' => array( 'role' => 'organizer', 'category' => 'payments', 'priority' => 'normal', 'channels' => array( 'inbox' ), 'flag' => 'flow_payments', 'link' => 'org_event', 'type' => 'organizer_payment_failed', 'validity' => 'payment_failed', 'expires' => 3 * $day ),
			'o_refund_completed'      => array( 'role' => 'organizer', 'category' => 'payments', 'priority' => 'normal', 'channels' => array( 'inbox', 'email' ), 'flag' => 'flow_payments', 'link' => 'org_money', 'type' => 'organizer_refund_completed', 'validity' => 'payment_refunded', 'expires' => 14 * $day ),
			'o_dispute_opened'        => array( 'role' => 'organizer', 'category' => 'payments', 'priority' => 'high', 'channels' => $all, 'flag' => 'flow_payments', 'link' => 'org_money', 'type' => 'organizer_dispute_opened', 'expires' => 14 * $day ),
			'o_chargeback_closed'     => array( 'role' => 'organizer', 'category' => 'payments', 'priority' => 'normal', 'channels' => array( 'inbox', 'email' ), 'flag' => 'flow_payments', 'link' => 'org_money', 'type' => 'organizer_dispute_closed', 'expires' => 14 * $day ),
			'o_payout_status'         => array( 'role' => 'organizer', 'category' => 'payments', 'priority' => 'normal', 'channels' => $all, 'flag' => 'fin_payout_notifications', 'link' => 'org_money', 'type' => 'organizer_payout', 'expires' => 7 * $day ),
			'o_payout_setup'          => array( 'role' => 'organizer', 'category' => 'payments', 'priority' => 'high', 'channels' => $all, 'flag' => 'fin_payout_notifications', 'link' => 'org_money', 'type' => 'organizer_payout_setup', 'expires' => 7 * $day ),

			// ---------------------------------------------------------------- Organizer: events & operations.
			'o_event_published'       => array( 'role' => 'organizer', 'category' => 'onboarding', 'priority' => 'normal', 'channels' => array( 'inbox', 'email' ), 'flag' => 'flow_onboarding', 'link' => 'org_event', 'type' => 'organizer_event_published', 'expires' => 3 * $day ),
			'o_recruit_vendors'       => array( 'role' => 'organizer', 'category' => 'recruitment', 'priority' => 'promotional', 'channels' => $all, 'flag' => 'flow_recruitment', 'link' => 'org_vendors', 'type' => 'organizer_recruit_vendors', 'validity' => 'event_accepting', 'expires' => 3 * $day ),
			'o_checkin_summary'       => array( 'role' => 'organizer', 'category' => 'event_ops', 'priority' => 'normal', 'channels' => array( 'inbox', 'push' ), 'flag' => 'flow_operations', 'link' => 'org_checkin', 'type' => 'organizer_checkin_activity', 'expires' => $day ),
			'o_countdown'             => array( 'role' => 'organizer', 'category' => 'event_ops', 'priority' => 'normal', 'channels' => $all, 'flag' => 'flow_countdown', 'link' => 'org_event', 'type' => 'organizer_event_needs_attention', 'validity' => 'event_upcoming', 'expires' => $day ),
			'o_event_completed'       => array( 'role' => 'organizer', 'category' => 'event_ops', 'priority' => 'normal', 'channels' => array( 'inbox', 'email' ), 'flag' => 'flow_post_event', 'link' => 'org_event', 'type' => 'organizer_event_completed', 'expires' => 7 * $day ),
			'o_ops_summary'           => array( 'role' => 'organizer', 'category' => 'digests', 'priority' => 'normal', 'channels' => array( 'email' ), 'flag' => 'flow_digests', 'link' => 'org_home', 'type' => 'organizer_ops_summary', 'expires' => $day ),

			// ---------------------------------------------------------------- Organizer: engagement.
			'o_welcome'               => array( 'role' => 'organizer', 'category' => 'onboarding', 'priority' => 'normal', 'channels' => array( 'inbox', 'email' ), 'flag' => 'flow_onboarding', 'link' => 'org_launch', 'type' => 'activation_create_event', 'expires' => 3 * $day ),
			'o_onboarding'            => array( 'role' => 'organizer', 'category' => 'onboarding', 'priority' => 'normal', 'channels' => $all, 'flag' => 'flow_onboarding', 'link' => 'org_step', 'type' => 'activation_create_event', 'validity' => 'organizer_onboarding_step', 'expires' => 2 * $day ),
			'o_setup_profile'         => array( 'role' => 'organizer', 'category' => 'onboarding', 'priority' => 'normal', 'channels' => $all, 'flag' => 'flow_activation', 'link' => 'org_step', 'type' => 'activation_create_event', 'validity' => 'organizer_activation_stage', 'expires' => 2 * $day ),
			'o_setup_payouts'         => array( 'role' => 'organizer', 'category' => 'onboarding', 'priority' => 'normal', 'channels' => $all, 'flag' => 'flow_activation', 'link' => 'org_money', 'type' => 'organizer_payout_setup', 'validity' => 'organizer_activation_stage', 'expires' => 2 * $day ),
			'o_publish_draft'         => array( 'role' => 'organizer', 'category' => 'onboarding', 'priority' => 'normal', 'channels' => $all, 'flag' => 'flow_activation', 'link' => 'org_event', 'type' => 'activation_create_event', 'validity' => 'event_is_draft', 'expires' => 2 * $day ),
			'o_first_booking'         => array( 'role' => 'organizer', 'category' => 'onboarding', 'priority' => 'normal', 'channels' => $all, 'flag' => 'flow_activation', 'link' => 'org_event', 'type' => 'organizer_vendor_paid', 'expires' => 3 * $day ),
			'o_no_applications'       => array( 'role' => 'organizer', 'category' => 'recruitment', 'priority' => 'promotional', 'channels' => array( 'inbox', 'email' ), 'flag' => 'flow_retention', 'link' => 'org_vendors', 'type' => 'organizer_recruit_vendors', 'validity' => 'event_has_no_applications', 'expires' => 2 * $day ),
			'o_publish_next'          => array( 'role' => 'organizer', 'category' => 'recruitment', 'priority' => 'promotional', 'channels' => array( 'inbox', 'email' ), 'flag' => 'flow_retention', 'link' => 'org_launch', 'type' => 'activation_create_event', 'validity' => 'organizer_has_no_upcoming', 'expires' => 3 * $day ),
			'o_open_spots'            => array( 'role' => 'organizer', 'category' => 'recruitment', 'priority' => 'normal', 'channels' => $all, 'flag' => 'flow_recruitment_updates', 'link' => 'org_vendors', 'type' => 'organizer_open_spots', 'validity' => 'event_has_open_spots', 'expires' => 2 * $day ),
			'o_new_matching_vendors'  => array( 'role' => 'organizer', 'category' => 'recruitment', 'priority' => 'promotional', 'channels' => array( 'inbox', 'email' ), 'flag' => 'flow_recruitment_updates', 'link' => 'org_vendors', 'type' => 'organizer_recruit_vendors', 'validity' => 'event_has_new_matches', 'expires' => 3 * $day ),
			'o_reactivation'          => array( 'role' => 'organizer', 'category' => 'recruitment', 'priority' => 'promotional', 'channels' => array( 'email' ), 'flag' => 'flow_retention', 'link' => 'org_launch', 'type' => 'activation_create_event', 'validity' => 'organizer_has_no_upcoming', 'expires' => 3 * $day ),

			// ---------------------------------------------------------------- Partner programs (City Connector, affiliate, strategic partner).
			// Email only until partner accounts have app screens; every commission notice re-reads the ledger at send time.
			'p_application_approved'  => array( 'role' => 'partner', 'category' => 'partnerships', 'priority' => 'high', 'channels' => array( 'email' ), 'flag' => 'flow_partners', 'link' => 'partner_dashboard', 'type' => 'partner_application', 'validity' => 'partner_account_active', 'expires' => 14 * $day ),
			'p_referral_registered'   => array( 'role' => 'partner', 'category' => 'partnerships', 'priority' => 'normal', 'channels' => array( 'email' ), 'flag' => 'flow_partners', 'link' => 'partner_referrals', 'type' => 'partner_referral', 'validity' => 'partner_referral_winning', 'expires' => 7 * $day ),
			'p_referral_milestone'    => array( 'role' => 'partner', 'category' => 'partnerships', 'priority' => 'normal', 'channels' => array( 'email' ), 'flag' => 'flow_partners', 'link' => 'partner_referrals', 'type' => 'partner_referral', 'validity' => 'partner_referral_winning', 'expires' => 7 * $day ),
			'p_commission_recorded'   => array( 'role' => 'partner', 'category' => 'partnerships', 'priority' => 'normal', 'channels' => array( 'email' ), 'flag' => 'flow_partners', 'link' => 'partner_earnings', 'type' => 'partner_commission', 'validity' => 'partner_reward_live', 'expires' => 7 * $day ),
			'p_commission_approved'   => array( 'role' => 'partner', 'category' => 'partnerships', 'priority' => 'normal', 'channels' => array( 'email' ), 'flag' => 'flow_partners', 'link' => 'partner_earnings', 'type' => 'partner_commission', 'validity' => 'partner_reward_approved', 'expires' => 14 * $day ),
			'p_payout_recorded'       => array( 'role' => 'partner', 'category' => 'partnerships', 'priority' => 'normal', 'channels' => array( 'email' ), 'flag' => 'flow_partners', 'link' => 'partner_earnings', 'type' => 'partner_payout', 'validity' => 'partner_payout_recorded', 'expires' => 14 * $day ),
			'p_lead_update'           => array( 'role' => 'partner', 'category' => 'partnerships', 'priority' => 'normal', 'channels' => array( 'email' ), 'flag' => 'flow_partners', 'link' => 'partner_leads', 'type' => 'partner_lead', 'validity' => 'partner_lead_owned', 'expires' => 7 * $day ),

			// ---------------------------------------------------------------- Referral invites (vendors and organizers who invite others).
			'r_invite_joined'         => array( 'role' => 'any', 'category' => 'partnerships', 'priority' => 'normal', 'channels' => $all, 'flag' => 'flow_partners', 'link' => 'referral_invites', 'type' => 'referral_joined', 'validity' => 'partner_referral_winning', 'expires' => 7 * $day ),
			'r_credit_earned'         => array( 'role' => 'any', 'category' => 'partnerships', 'priority' => 'normal', 'channels' => array( 'inbox', 'email' ), 'flag' => 'flow_partners', 'link' => 'referral_invites', 'type' => 'referral_credit', 'validity' => 'referrer_credit_live', 'expires' => 7 * $day ),

			// ---------------------------------------------------------------- LNKup admins: partner operations.
			'a_partner_application'   => array( 'role' => 'admin', 'category' => 'account', 'priority' => 'normal', 'channels' => array( 'email' ), 'flag' => 'flow_partners', 'link' => 'home', 'type' => 'system', 'validity' => 'partner_application_pending', 'expires' => 7 * $day ),
			'a_partner_review'        => array( 'role' => 'admin', 'category' => 'account', 'priority' => 'normal', 'channels' => array( 'email' ), 'flag' => 'flow_partners', 'link' => 'home', 'type' => 'system', 'validity' => 'partner_review_open', 'expires' => 7 * $day ),

			// ---------------------------------------------------------------- Shared.
			'digest_email'            => array( 'role' => 'any', 'category' => 'digests', 'priority' => 'normal', 'channels' => array( 'email' ), 'flag' => '', 'link' => 'home', 'type' => 'digest', 'expires' => $day ),
			'campaign_message'        => array( 'role' => 'any', 'category' => 'product_news', 'priority' => 'promotional', 'channels' => $all, 'flag' => 'campaigns_enabled', 'link' => 'home', 'type' => 'platform_announcement', 'expires' => 7 * $day ),
			'admin_health_alert'      => array( 'role' => 'admin', 'category' => 'account', 'priority' => 'high', 'channels' => array( 'email' ), 'flag' => '', 'link' => 'home', 'type' => 'system', 'expires' => $day ),
		);

		/**
		 * Filter the trigger catalog.
		 *
		 * @param array $triggers Trigger definitions.
		 */
		$triggers = (array) apply_filters( 'lnkup_comms_triggers', $triggers );
		return $triggers;
	}

	public static function get( string $key ): ?array {
		$all = self::triggers();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function category( string $key ): ?array {
		$all = self::categories();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Inbox tab for a stored notification type (covers core-written rows too).
	 */
	public static function tab_for_type( string $type, string $role ): string {
		static $map = null;
		if ( null === $map ) {
			$map = array();
			foreach ( self::triggers() as $def ) {
				$cat = self::category( (string) $def['category'] );
				if ( $cat ) {
					$map[ (string) $def['type'] ] = $cat['tab'];
				}
			}
			$core = array(
				'payment_required'                  => 'payments',
				'payment_paid'                      => 'payments',
				'payment_confirmed'                 => 'payments',
				'organizer_payment_received'        => 'payments',
				'packet_unlocked'                   => 'events',
				'system'                            => 'events',
				'document_missing'                  => 'events',
				'organizer_vendor_missing_documents' => 'operations',
				'review_requested'                  => 'operations',
			);
			foreach ( $core as $type_key => $tab ) {
				if ( ! isset( $map[ $type_key ] ) ) {
					$map[ $type_key ] = array( 'vendor' => $tab, 'organizer' => $tab );
				}
			}
		}
		if ( isset( $map[ $type ][ $role ] ) ) {
			return (string) $map[ $type ][ $role ];
		}
		if ( 0 === strpos( $type, 'discovery_' ) ) {
			return 'opportunities';
		}
		if ( 0 === strpos( $type, 'application_' ) || 'info_requested' === $type ) {
			return 'applications';
		}
		return 'all';
	}

	/**
	 * Notification types that belong to an inbox tab (for SQL filtering).
	 *
	 * @return string[]
	 */
	public static function types_for_tab( string $tab, string $role ): array {
		$types = array();
		$candidates = array();
		foreach ( self::triggers() as $def ) {
			$candidates[] = (string) $def['type'];
		}
		$candidates = array_merge(
			$candidates,
			array( 'payment_required', 'payment_paid', 'payment_confirmed', 'organizer_payment_received', 'packet_unlocked', 'system', 'document_missing', 'organizer_vendor_missing_documents', 'review_requested', 'application_rejected', 'application_waitlisted', 'discovery_saved_search', 'discovery_favorite_deadline', 'discovery_deadline', 'discovery_almost_full', 'discovery_invite' )
		);
		foreach ( array_unique( $candidates ) as $type ) {
			if ( self::tab_for_type( $type, $role ) === $tab ) {
				$types[] = $type;
			}
		}
		return $types;
	}
}

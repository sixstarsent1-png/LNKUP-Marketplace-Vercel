export type LnkupAnalyticsEventName =
  | "landing_cta_clicked"
  | "browse_opportunities_clicked"
  | "login_started"
  | "vendor_onboarding_started"
  | "vendor_onboarding_step_completed"
  | "profile_completion_clicked"
  | "document_upload_clicked"
  | "save_opportunity_clicked"
  | "save_search_clicked"
  | "apply_clicked"
  | "apply_prelaunch_blocked"
  | "apply_submitted"
  | "payment_handoff_clicked"
  | "payment_return_landed"
  | "payment_return_reconcile_started"
  | "payment_return_confirmed"
  | "payment_return_timeout"
  | "payment_return_manual_retry"
  | "share_link_copied"
  | "deep_opportunity_opened"
  | "vendor_pass_viewed"
  | "vendor_operations_viewed"
  | "vendor_locked_pass_viewed"
  | "vendor_unlocked_pass_viewed"
  | "vendor_calendar_added"
  | "vendor_directions_clicked"
  | "vendor_support_started"
  | "vendor_support_submitted"
  | "vendor_rules_viewed"
  | "vendor_loadin_viewed"
  | "wordpress_operations_handoff_clicked"
  | "search_performed"
  | "filter_changed"
  | "filter_cleared"
  | "map_view_opened"
  | "map_pin_clicked"
  | "near_me_clicked"
  | "near_me_allowed"
  | "near_me_denied"
  | "market_page_viewed"
  | "saved_search_created"
  | "saved_search_opened"
  | "zero_results_shown"
  | "recommendation_clicked"
  | "organizer_experiment_viewed"
  | "organizer_experiment_recommendation_opened"
  | "organizer_experiment_recommendation_action_clicked"
  | "organizer_experiment_decision_recorded"
  | "organizer_landing_viewed"
  | "organizer_join_clicked"
  | "organizer_onboarding_started"
  | "organizer_onboarding_step_completed"
  | "launch_event_started"
  | "launch_event_draft_saved"
  | "launch_event_publish_clicked"
  | "launch_event_published"
  | "launch_event_handoff_clicked"
  | "organizer_event_workspace_viewed"
  | "organizer_vendor_directory_viewed"
  | "organizer_vendor_profile_opened"
  | "organizer_review_action_attempted"
  | "organizer_review_action_succeeded"
  | "organizer_review_action_failed"
  | "organizer_list_action_clicked"
  | "organizer_invite_action_clicked"
  | "organizer_vendor_risk_viewed"
  | "application_link_copied"
  | "opportunity_preview_clicked"
  | "wordpress_dashboard_opened"
  | "vendor_reputation_viewed"
  | "vendor_review_card_viewed"
  | "vendor_badge_viewed"
  | "public_vendor_profile_viewed"
  | "organizer_reputation_preview_viewed"
  | "vendor_feedback_started"
  | "vendor_feedback_submitted"
  | "share_vendor_profile_clicked"
  | "vendor_wallet_viewed"
  | "vendor_readiness_blocker_viewed"
  | "vendor_readiness_action_clicked"
  | "vendor_payment_detail_viewed"
  | "vendor_continue_payment_clicked"
  | "vendor_receipt_clicked"
  | "vendor_invoice_clicked"
  | "vendor_credits_viewed"
  | "vendor_dispute_opened"
  | "vendor_dispute_handoff_clicked"
  | "organizer_money_viewed"
  | "organizer_event_money_viewed"
  | "financial_handoff_clicked"
  | "wordpress_financial_center_opened"
  | "notifications_viewed"
  | "notification_clicked"
  | "notification_marked_read"
  | "notification_mark_all_read"
  | "notification_preferences_viewed"
  | "notification_preference_changed"
  | "saved_search_alert_frequency_changed"
  | "saved_search_paused"
  | "saved_search_resumed"
  | "pwa_install_prompt_shown"
  | "pwa_install_clicked"
  | "pwa_install_dismissed"
  | "wordpress_notification_settings_opened"
  | "tutorial_viewed"
  | "tutorial_step_clicked"
  | "tutorial_step_completed"
  | "authentication_gate_displayed"
  | "authentication_gate_clicked"
  | "guest_registration_started"
  | "authentication_intent_restored"

export type LnkupAnalyticsEvent = {
  event: LnkupAnalyticsEventName
  source?: string
  opportunityId?: string
  eventId?: string
  requestId?: string
  metadata?: Record<string, string | number | boolean | null>
}

const ALLOWED_EVENTS = new Set<LnkupAnalyticsEventName>([
  "landing_cta_clicked",
  "browse_opportunities_clicked",
  "login_started",
  "vendor_onboarding_started",
  "vendor_onboarding_step_completed",
  "profile_completion_clicked",
  "document_upload_clicked",
  "save_opportunity_clicked",
  "save_search_clicked",
  "apply_clicked",
  "apply_prelaunch_blocked",
  "apply_submitted",
  "payment_handoff_clicked",
  "payment_return_landed",
  "payment_return_reconcile_started",
  "payment_return_confirmed",
  "payment_return_timeout",
  "payment_return_manual_retry",
  "share_link_copied",
  "deep_opportunity_opened",
  "vendor_pass_viewed",
  "vendor_operations_viewed",
  "vendor_locked_pass_viewed",
  "vendor_unlocked_pass_viewed",
  "vendor_calendar_added",
  "vendor_directions_clicked",
  "vendor_support_started",
  "vendor_support_submitted",
  "vendor_rules_viewed",
  "vendor_loadin_viewed",
  "wordpress_operations_handoff_clicked",
  "search_performed",
  "filter_changed",
  "filter_cleared",
  "map_view_opened",
  "map_pin_clicked",
  "near_me_clicked",
  "near_me_allowed",
  "near_me_denied",
  "market_page_viewed",
  "saved_search_created",
  "saved_search_opened",
  "zero_results_shown",
  "recommendation_clicked",
  "organizer_experiment_viewed",
  "organizer_experiment_recommendation_opened",
  "organizer_experiment_recommendation_action_clicked",
  "organizer_experiment_decision_recorded",
  "organizer_landing_viewed",
  "organizer_join_clicked",
  "organizer_onboarding_started",
  "organizer_onboarding_step_completed",
  "launch_event_started",
  "launch_event_draft_saved",
  "launch_event_publish_clicked",
  "launch_event_published",
  "launch_event_handoff_clicked",
  "organizer_event_workspace_viewed",
  "organizer_vendor_directory_viewed",
  "organizer_vendor_profile_opened",
  "organizer_review_action_attempted",
  "organizer_review_action_succeeded",
  "organizer_review_action_failed",
  "organizer_list_action_clicked",
  "organizer_invite_action_clicked",
  "organizer_vendor_risk_viewed",
  "application_link_copied",
  "opportunity_preview_clicked",
  "wordpress_dashboard_opened",
  "vendor_reputation_viewed",
  "vendor_review_card_viewed",
  "vendor_badge_viewed",
  "public_vendor_profile_viewed",
  "organizer_reputation_preview_viewed",
  "vendor_feedback_started",
  "vendor_feedback_submitted",
  "share_vendor_profile_clicked",
  "vendor_wallet_viewed",
  "vendor_readiness_blocker_viewed",
  "vendor_readiness_action_clicked",
  "vendor_payment_detail_viewed",
  "vendor_continue_payment_clicked",
  "vendor_receipt_clicked",
  "vendor_invoice_clicked",
  "vendor_credits_viewed",
  "vendor_dispute_opened",
  "vendor_dispute_handoff_clicked",
  "organizer_money_viewed",
  "organizer_event_money_viewed",
  "financial_handoff_clicked",
  "wordpress_financial_center_opened",
  "notifications_viewed",
  "notification_clicked",
  "notification_marked_read",
  "notification_mark_all_read",
  "notification_preferences_viewed",
  "notification_preference_changed",
  "saved_search_alert_frequency_changed",
  "saved_search_paused",
  "saved_search_resumed",
  "pwa_install_prompt_shown",
  "pwa_install_clicked",
  "pwa_install_dismissed",
  "wordpress_notification_settings_opened",
  "tutorial_viewed",
  "tutorial_step_clicked",
  "tutorial_step_completed",
  "authentication_gate_displayed",
  "authentication_gate_clicked",
  "guest_registration_started",
  "authentication_intent_restored",
])

export function isAllowedAnalyticsEvent(event: string): event is LnkupAnalyticsEventName {
  return ALLOWED_EVENTS.has(event as LnkupAnalyticsEventName)
}

/** Map Next.js event names to WordPress marketplace analytics event_type */
export function toWpEventType(event: LnkupAnalyticsEventName): string {
  const map: Record<LnkupAnalyticsEventName, string> = {
    landing_cta_clicked: "landing_cta_clicked",
    browse_opportunities_clicked: "browse_opportunities_clicked",
    login_started: "login_started",
    vendor_onboarding_started: "vendor_onboarding_started",
    vendor_onboarding_step_completed: "vendor_onboarding_step_completed",
    profile_completion_clicked: "profile_completion_clicked",
    document_upload_clicked: "document_upload_clicked",
    save_opportunity_clicked: "save_opportunity_clicked",
    save_search_clicked: "save_search_clicked",
    apply_clicked: "apply_clicked",
    apply_prelaunch_blocked: "apply_prelaunch_blocked",
    apply_submitted: "apply_submitted",
    payment_handoff_clicked: "payment_handoff_clicked",
    payment_return_landed: "payment_return_landed",
    payment_return_reconcile_started: "payment_return_reconcile_started",
    payment_return_confirmed: "payment_return_confirmed",
    payment_return_timeout: "payment_return_timeout",
    payment_return_manual_retry: "payment_return_manual_retry",
    share_link_copied: "share_link_copied",
    deep_opportunity_opened: "deep_opportunity_opened",
    vendor_pass_viewed: "vendor_pass_viewed",
    vendor_operations_viewed: "vendor_operations_viewed",
    vendor_locked_pass_viewed: "vendor_locked_pass_viewed",
    vendor_unlocked_pass_viewed: "vendor_unlocked_pass_viewed",
    vendor_calendar_added: "vendor_calendar_added",
    vendor_directions_clicked: "vendor_directions_clicked",
    vendor_support_started: "vendor_support_started",
    vendor_support_submitted: "vendor_support_submitted",
    vendor_rules_viewed: "vendor_rules_viewed",
    vendor_loadin_viewed: "vendor_loadin_viewed",
    wordpress_operations_handoff_clicked: "wordpress_operations_handoff_clicked",
    search_performed: "search_performed",
    filter_changed: "filter_changed",
    filter_cleared: "filter_cleared",
    map_view_opened: "map_view_opened",
    map_pin_clicked: "map_pin_clicked",
    near_me_clicked: "near_me_clicked",
    near_me_allowed: "near_me_allowed",
    near_me_denied: "near_me_denied",
    market_page_viewed: "market_page_viewed",
    saved_search_created: "saved_search_created",
    saved_search_opened: "saved_search_opened",
    zero_results_shown: "zero_results_shown",
    recommendation_clicked: "recommendation_clicked",
    organizer_experiment_viewed: "organizer_experiment_viewed",
    organizer_experiment_recommendation_opened: "organizer_experiment_recommendation_opened",
    organizer_experiment_recommendation_action_clicked:
      "organizer_experiment_recommendation_action_clicked",
    organizer_experiment_decision_recorded: "organizer_experiment_decision_recorded",
    organizer_landing_viewed: "organizer_landing_viewed",
    organizer_join_clicked: "organizer_join_clicked",
    organizer_onboarding_started: "organizer_onboarding_started",
    organizer_onboarding_step_completed: "organizer_onboarding_step_completed",
    launch_event_started: "launch_event_started",
    launch_event_draft_saved: "launch_event_draft_saved",
    launch_event_publish_clicked: "launch_event_publish_clicked",
    launch_event_published: "launch_event_published",
    launch_event_handoff_clicked: "launch_event_handoff_clicked",
    organizer_event_workspace_viewed: "organizer_event_workspace_viewed",
    organizer_vendor_directory_viewed: "organizer_vendor_directory_viewed",
    organizer_vendor_profile_opened: "organizer_vendor_profile_opened",
    organizer_review_action_attempted: "organizer_review_action_attempted",
    organizer_review_action_succeeded: "organizer_review_action_succeeded",
    organizer_review_action_failed: "organizer_review_action_failed",
    organizer_list_action_clicked: "organizer_list_action_clicked",
    organizer_invite_action_clicked: "organizer_invite_action_clicked",
    organizer_vendor_risk_viewed: "organizer_vendor_risk_viewed",
    application_link_copied: "application_link_copied",
    opportunity_preview_clicked: "opportunity_preview_clicked",
    wordpress_dashboard_opened: "wordpress_dashboard_opened",
    vendor_reputation_viewed: "vendor_reputation_viewed",
    vendor_review_card_viewed: "vendor_review_card_viewed",
    vendor_badge_viewed: "vendor_badge_viewed",
    public_vendor_profile_viewed: "public_vendor_profile_viewed",
    organizer_reputation_preview_viewed: "organizer_reputation_preview_viewed",
    vendor_feedback_started: "vendor_feedback_started",
    vendor_feedback_submitted: "vendor_feedback_submitted",
    share_vendor_profile_clicked: "share_vendor_profile_clicked",
    vendor_wallet_viewed: "vendor_wallet_viewed",
    vendor_readiness_blocker_viewed: "vendor_readiness_blocker_viewed",
    vendor_readiness_action_clicked: "vendor_readiness_action_clicked",
    vendor_payment_detail_viewed: "vendor_payment_detail_viewed",
    vendor_continue_payment_clicked: "vendor_continue_payment_clicked",
    vendor_receipt_clicked: "vendor_receipt_clicked",
    vendor_invoice_clicked: "vendor_invoice_clicked",
    vendor_credits_viewed: "vendor_credits_viewed",
    vendor_dispute_opened: "vendor_dispute_opened",
    vendor_dispute_handoff_clicked: "vendor_dispute_handoff_clicked",
    organizer_money_viewed: "organizer_money_viewed",
    organizer_event_money_viewed: "organizer_event_money_viewed",
    financial_handoff_clicked: "financial_handoff_clicked",
    wordpress_financial_center_opened: "wordpress_financial_center_opened",
    notifications_viewed: "notifications_viewed",
    notification_clicked: "notification_clicked",
    notification_marked_read: "notification_marked_read",
    notification_mark_all_read: "notification_mark_all_read",
    notification_preferences_viewed: "notification_preferences_viewed",
    notification_preference_changed: "notification_preference_changed",
    saved_search_alert_frequency_changed: "saved_search_alert_frequency_changed",
    saved_search_paused: "saved_search_paused",
    saved_search_resumed: "saved_search_resumed",
    pwa_install_prompt_shown: "pwa_install_prompt_shown",
    pwa_install_clicked: "pwa_install_clicked",
    pwa_install_dismissed: "pwa_install_dismissed",
    wordpress_notification_settings_opened: "wordpress_notification_settings_opened",
    tutorial_viewed: "tutorial_viewed",
    tutorial_step_clicked: "tutorial_step_clicked",
    tutorial_step_completed: "tutorial_step_completed",
    authentication_gate_displayed: "authentication_gate_displayed",
    authentication_gate_clicked: "authentication_gate_clicked",
    guest_registration_started: "guest_registration_started",
    authentication_intent_restored: "authentication_intent_restored",
  }
  return map[event] ?? event
}

export function sanitizeAnalyticsMetadata(
  metadata?: Record<string, string | number | boolean | null>,
): Record<string, string | number | boolean | null> {
  if (!metadata) return {}
  const safe: Record<string, string | number | boolean | null> = {}
  const blocked = new Set([
    "password",
    "apppassword",
    "app_password",
    "token",
    "secret",
    "credential",
    "document",
    "file",
    "lat",
    "lng",
    "latitude",
    "longitude",
    "coordinates",
  ])
  for (const [key, value] of Object.entries(metadata)) {
    const lower = key.toLowerCase()
    if (blocked.has(lower) || lower.includes("password") || lower.includes("secret")) continue
    if (typeof value === "string" && value.length > 200) continue
    safe[key] = value
  }
  return safe
}

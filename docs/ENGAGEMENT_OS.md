# Engagement OS — Implementation Ledger

Living ledger for the Engagement, Communications, Matching and Growth work that builds on the existing lnkup-communications plugin (1.2.x) and the lnkup-marketplace matching OS (12.24–12.34). Started Nov 23, 2026.

Status labels: **Verified** (code + automated test), **Needs improvement**, **Partial**, **Duplicated**, **Broken**, **Missing**, **Deprecated**, **Needs production check** (works in tests; no production delivery evidence).

Related: [COMMUNICATIONS_MATRIX.md](COMMUNICATIONS_MATRIX.md), [COMMUNICATIONS_AUDIT.md](COMMUNICATIONS_AUDIT.md), [COMMUNICATIONS_ROLLOUT.md](COMMUNICATIONS_ROLLOUT.md), [MATCHING_DISCOVERY.md](MATCHING_DISCOVERY.md).

## 1. Audit baseline (before this work)

| Area | Status | Evidence |
|---|---|---|
| Notification engine (dispatch, dedupe, send-time revalidation, cancel keys) | Verified · Needs production check | `LNKup_Comms_Engine`, `LNKup_Comms_Context::check()`, `comms-engine-test.php` |
| Background processing (Action Scheduler, retries 5m/30m/2h/6h) | Verified · Needs production check | `LNKup_Comms_Queue`; real cron runner documented in COMMUNICATIONS_ROLLOUT Step 3 |
| Priorities (critical / high / normal / promotional) | Verified | `LNKup_Comms_Registry::PRIORITIES` |
| Frequency caps | Partial | Promotional only, per channel; no per-category or weekly push cap |
| Cross-channel cooldown | Missing | Each channel decided independently |
| Quiet hours + user timezone | Verified (push only) | `LNKup_Comms_Preferences::quiet_seconds_remaining()` |
| Digests (daily / weekly per category) | Verified | `LNKup_Comms_Auto_Digests` |
| Preferences (13 categories × email mode × push) | Verified | `/comms/preferences`, `/notification-settings/`, Expo, Next.js |
| One-click unsubscribe, suppression list, bounce/complaint webhook | Verified · Needs production check | `LNKup_Comms_Suppression`, `class-comms-channel-email.php` |
| Expo receipts + DeviceNotRegistered cleanup, multi-device | Verified | `LNKup_Comms_Channel_Push::check_receipts()` |
| Android FCM | Missing (needs credentials) | No `google-services.json` |
| Admin (overview, flows, templates, campaigns, logs, analytics, settings, health) | Verified | `LNKup_Comms_Admin` |
| Template version history / drafts | Missing | Single override row |
| Per-user communication timeline | Partial | Delivery logs filtered by user ID only |
| Suppression/bounce list viewer | Missing | Counts only |
| Audit log for settings/template changes | Missing | Only campaigns + CLI flag toggles |
| Holdout groups | Missing | Rollout cohorts are staged rollouts, not holdouts |
| Attribution (observed) | Verified | `LNKup_Comms_Analytics::attributed()`, `match_alert_application` |
| Matching engine, event candidates, recruitment pipeline, batch invites, tours | Verified | MATCHING_DISCOVERY §1–§11 |
| Event slot fulfillment (by category, deadline, invites) | Missing | Only `LNKup_Opportunity_Inventory::summary()` per opportunity |
| Organizer Find Vendors sections (new nearby, previously booked, saved, recently viewed) | Missing | One ranked list + filters |
| Near-real-time "new vendors near your event" with vendor cards | Missing | Weekly count only (`o_new_matching_vendors`) |
| Invite expiry | Broken | Expiry applied only when someone acts; lists keep "sent" forever |
| Invite accepted/declined → organizer | Missing | `lnkup_vendor_invite_status_changed` fires, nobody listens |
| Vendor invitations accept/decline UI | Missing | WP Passport lists open invites only; Expo/Next.js none |
| Draft application reminders | Missing | Server drafts exist (`LNKup_Application_Drafts`) |
| Vendor inactivity journey | Missing | — |
| First-event-completed vendor message | Partial | `v_event_thanks` is generic |
| Published event with no open opportunities | Partial | Only an onboarding step |
| Engagement state / next best action | Partial | `LNKup_Comms_Next_Steps` computed per request, onboarding only |
| WP dashboard notification center | Missing | Preferences page only |
| Notification type drift (WP ↔ Expo ↔ Next.js) | Broken | 8 types unknown to Expo, ~56 to Next.js, 42 without an Expo fallback route |
| Expo badge sync on launch/foreground | Partial | Only on inbox load + foreground push |
| Next.js unread count refresh | Partial | Fetched once on mount |
| Organizer home per-event/per-slot queries | Needs improvement | `LNKup_Organizer_Overview::upcoming()` uncached |

## 2. Work in this pass

Filled in as each phase lands (see §3 onward).

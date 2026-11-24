# LNKup Communications — Notification Matrix

Source of truth: `LNKup_Comms_Registry::triggers()` (`wp-content/plugins/lnkup-communications/includes/class-comms-registry.php`).
Copy lives in `includes/templates/catalog.php` (editable in **LNKup → Communications → Email & push templates**).

**How to read this**

- **Push / Email / In-App** = default channels. In-app (inbox) is always written when listed. Email and push then follow the recipient's preferences, caps, quiet hours and suppression list.
- **Priority**:
  - `critical` ignores category opt-outs and quiet hours. It still respects the master push switch and OS permission.
  - `high` ignores quiet hours.
  - `promotional` is capped (default 2 push/day, 3 email/week) and honours marketing unsubscribes.
- **Suppression rule** = the validity check run when the message is scheduled **and again right before sending** (`LNKup_Comms_Context::check`). It re-reads canonical CPT meta, payments, verification and readiness.
- **Deep link** = in-app route. Every route is checked against the mobile allowlist. Emails link to the matching website screen through a signed click-tracking URL.
- Every trigger is behind a feature flag (shown in brackets). While a flag is off, lnkup-marketplace keeps sending its own legacy message for that flow.

## Vendor

| Trigger | Recipient | Push | Email | In-App | Timing | Priority | Deep link | Suppression rule |
|---|---|---|---|---|---|---|---|---|
| `v_application_submitted` [flow_applications] | Vendor | – | ✓ | ✓ | On `lnkup_application_submitted` | normal | `/booking/{app}` | Vendor must own application |
| `v_application_approved` (`:payment` / `:free`) | Vendor | ✓ | ✓ | ✓ | On status → `payment_required`/`approved` | high | Payment due → `/(vendor)/wallet`; free → `/pass/{app}` | Status still approved/payment_required (free booths that finalize instantly send only the booking confirmation) |
| `v_application_rejected` | Vendor | ✓ | ✓ | ✓ | On status → `rejected` | normal | `/booking/{app}` | Status still rejected |
| `v_application_waitlisted` | Vendor | ✓ | ✓ | ✓ | On status → `waitlisted` | normal | `/booking/{app}` | Status still waitlisted |
| `v_info_requested` | Vendor | ✓ | ✓ | ✓ | On status → `info_requested` | high | `/booking/{app}` | Status still info_requested |
| `v_application_withdrawn` | Vendor | – | ✓ | ✓ | On `lnkup_application_withdrawn` | normal | `/booking/{app}` | — |
| `v_application_cancelled` | Vendor | ✓ | ✓ | ✓ | On status → `cancelled` (not withdrawn) | high | `/booking/{app}` | Status still cancelled |
| `v_payment_reminder` [flow_payments] | Vendor | ✓ | ✓ | ✓ | 72h and 24h before `_lnkup_payment_due_at`; core invoice reminders (7d/3d/24h/past due); installment/deposit due | high | `/(vendor)/wallet` | Still payable: app approved/payment_required, payment pending/sent, not voided, not satisfied, event not past/cancelled. Cancelled on payment |
| `v_payment_failed` | Vendor | ✓ | ✓ | ✓ | 2 min after payment status → `failed` (verified webhook) | high | `/(vendor)/wallet` | Payment still failed, not voided, app still payable |
| `v_booking_confirmed` | Vendor | ✓ | ✓ | ✓ | On `lnkup_payment_completed` or status → `paid` (deduplicated) | high | `/pass/{app}` | Payment status paid/completed and app paid/completed |
| `v_receipt_available` | Vendor | – | – | ✓ | On `lnkup_receipt_issued` | normal | `/(vendor)/wallet` | — |
| `v_refund_completed` | Vendor | ✓ | ✓ | ✓ | Payment status → refunded/partially_refunded, or `charge.refunded` sync | normal | `/(vendor)/wallet` | Payment status refunded/partially_refunded |
| `v_documents_needed` [flow_documents] | Vendor | ✓ | ✓ | ✓ | 1h after paid; core daily document check | high | `/(vendor)/documents` | Verification code `missing` for required docs only; active booking; event not past |
| `v_document_approved` | Vendor | ✓ | – | ✓ | `_lnkup_verification_status` → approved | normal | `/(vendor)/documents` | Still approved |
| `v_document_rejected` | Vendor | ✓ | ✓ | ✓ | → rejected | high | `/(vendor)/documents` | Still rejected |
| `v_document_expiring` | Vendor | – | ✓ | ✓ | Daily: approved docs expiring in 30d / 7d | normal | `/(vendor)/documents` | Once per doc per window |
| `v_pass_updated` [flow_operations] | Vendor | ✓ | – | ✓ | `lnkup_pass_details_updated`, grouped per 10 min | normal | `/pass/{app}` | Active booking, event not past/cancelled |
| `v_booth_assigned` | Vendor | ✓ | – | ✓ | `lnkup_booth_assigned` | normal | `/pass/{app}` | Active booking |
| `v_logistics_changed` | Booked vendors | ✓ | ✓ | ✓ | `lnkup_event_logistics_changed` | high | `/pass/{app}` | Active booking |
| `v_event_cancelled` (`:cancelled` / `:postponed`) | All active applicants | ✓ | ✓ | ✓ | `lnkup_event_cancelled` | **critical** | `/booking/{app}` | Event still cancelled/postponed |
| `v_checked_in` | Vendor | ✓ | – | ✓ | `lnkup_checkin_recorded` (checked_in / late_arrival) | normal | `/pass/{app}` | — |
| `v_countdown` (`:ready` / `:action`) [flow_countdown] | Confirmed vendors | ✓ | ✓ | ✓ | Hourly scan, event timezone: 14d, 7d, 72h, 24h, morning (07:00 local). Prepared vendors only get 7d / 24h / morning | normal | `/pass/{app}` | Paid booking, event upcoming and not cancelled |
| `v_announcement` [organizer_broadcasts] | Event group chosen by organizer | ✓ | ✓ | ✓ | Organizer send (batched); core ops broadcast | normal / high (schedule change, check-in) | `/pass/{app}` | Recipient must have an application on that event |
| `v_event_thanks` [flow_post_event] | Vendor | – | ✓ | ✓ | 6h after check-out / event completion | normal | `/(vendor)/find` | — |
| `v_welcome` [flow_onboarding] | New vendor | – | ✓ | ✓ | 2 min after registration | normal | `/(vendor)/profile` | New accounts only (≤ 2 days old) |
| `v_onboarding` | New vendor | ✓ | ✓ | ✓ | 24h, 72h, 7d after registration | normal | Next missing step (profile / find) | Suppressed once profile is complete and the vendor has applied |
| `v_opportunity_match` [flow_discovery] | Matching vendors | ✓ | ✓ (weekly digest by default) | ✓ | 10 min after an opportunity opens; batches of 200 | promotional | `/opportunity/{opp}` | Opportunity open (published, open status, deadline future, spots left, event not cancelled/past), not invite-only, vendor has not applied. One discovery message per vendor per opportunity |
| `v_organizer_new_opportunity` | Vendors with past paid bookings with the organizer | ✓ | ✓ | ✓ | Same as above | promotional | `/opportunity/{opp}` | Same as above |
| `digest_email` | Users with daily/weekly categories | – | ✓ | – | Daily / weekly at digest hour | normal | Item links | Items re-validated; empty digests not sent |

## Organizer

| Trigger | Recipient | Push | Email | In-App | Timing | Priority | Deep link | Suppression rule |
|---|---|---|---|---|---|---|---|---|
| `o_application_received` [flow_applications] | Event owner + authorized staff | ✓ | ✓ | ✓ | **First** application of an event, immediate | high | `/(organizer)/vendors?event={id}` | Applications still pending for the event |
| `o_applications_grouped` | Same | ✓ | ✓ | ✓ | Later applications grouped into one message per burst window (default 30 min); count computed at send | normal | `/(organizer)/vendors?event={id}` | Suppressed if the queue was cleared |
| `o_info_response` | Same | ✓ | ✓ | ✓ | Vendor replies (info_requested → under_review) | normal | `/(organizer)/vendors?event={id}` | — |
| `o_application_withdrawn` | Same | ✓ | – | ✓ | `lnkup_application_withdrawn` | normal | `/(organizer)/vendors?event={id}` | — |
| `o_waitlist_spot` | Same | ✓ | – | ✓ | `lnkup_waitlist_spot_available` when waitlisted vendors exist | normal | `/(organizer)/vendors?event={id}` | — |
| `o_pending_review` [flow_digests] | Organizer | ✓ | ✓ | ✓ | Daily when applications wait > 48h (configurable) | normal | `/(organizer)/vendors` | Stale pending count > 0 at send |
| `o_vendor_paid` [flow_payments] | **Event owner only** | ✓ | ✓ | ✓ | Verified payment completion | normal | `/(organizer)/events/{id}` | Payment confirmed |
| `o_vendor_payment_failed` | Owner only | – | – | ✓ | Verified failure | normal | `/(organizer)/events/{id}` | Payment still failed, not voided |
| `o_refund_completed` | Owner only | – | ✓ | ✓ | Refund status / sync | normal | `/(organizer)/money` | Payment refunded |
| `o_dispute_opened` | Owner only | ✓ | ✓ | ✓ | Payment status → disputed | high | `/(organizer)/money` | — |
| `o_chargeback_closed` | Owner only | – | ✓ | ✓ | `lnkup_chargeback_closed` | normal | `/(organizer)/money` | — |
| `o_payout_status` (`:paid` / `:failed`) [**fin_payout_notifications**] | Organizer | ✓ | ✓ | ✓ | Verified payout ledger transition | normal | `/(organizer)/money` | Off until financial reconciliation sign-off |
| `o_payout_setup` (`:ready` / `:action`) [fin_payout_notifications] | Organizer | ✓ | ✓ | ✓ | Connect status gained or lost "ready" | high | `/(organizer)/money` | Same |
| `o_event_published` [flow_onboarding] | Organizer | – | ✓ | ✓ | Event listing becomes `live` | normal | `/(organizer)/events/{id}` | — |
| `o_recruit_vendors` (`:count` / `:generic`) [flow_recruitment] | Organizer | ✓ | ✓ | ✓ | 1h after the event goes live | promotional | `/(organizer)/vendors` | Event still live. The count is shown only when real matching public profiles exist; no identities |
| `o_checkin_summary` [flow_operations] | Owner + staff | ✓ | – | ✓ | Grouped per 15 min on check-ins | normal | `/(organizer)/events/checkin?event={id}` | — |
| `o_countdown` [flow_countdown] | Owner + staff | ✓ | ✓ | ✓ | 30d, 14d, 7d, 72h, 24h, morning; live events only | normal | `/(organizer)/events/{id}` | Event upcoming, not cancelled |
| `o_event_completed` [flow_post_event] | Owner + staff | – | ✓ | ✓ | 12h after first completion signal; once per event | normal | `/(organizer)/events/{id}` | — |
| `o_ops_summary` [flow_digests] | Organizer with upcoming events | – | ✓ | – | Daily or weekly (organizer's "Summaries" setting; default weekly) | normal | Event links | Only organizers with upcoming events |
| `o_welcome` [flow_onboarding] | New organizer | – | ✓ | ✓ | Day 0 | normal | `/(organizer)/launch` | New accounts only |
| `o_onboarding` | New organizer | ✓ | ✓ | ✓ | Days 1, 3, 7 | normal | Exact next step (create event / add slot / categories / deadline / publish / payout setup) | Suppressed once setup is complete |
| `o_no_applications` [flow_retention] | Organizer | – | ✓ | ✓ | Daily: live 5+ days, event ≥ 7 days out, zero applications | promotional | `/(organizer)/vendors` | Still zero applications |
| `o_publish_next` (`:recurring` / `:default`) | Organizer | – | ✓ | ✓ | 3–30 days after last event, no upcoming events | promotional | `/(organizer)/launch` | No upcoming events. "Recurring" only with ≥ 3 past events at a regular cadence |
| `o_reactivation` | Organizer | – | ✓ | – | Last event > 60 days ago, at most once per quarter | promotional | `/(organizer)/launch` | No upcoming events |

## Engagement OS additions (lnkup-communications 1.3.0)

Details, journey maps and tests: [ENGAGEMENT_OS.md](ENGAGEMENT_OS.md).

| Trigger | Recipient | Push | Email | In-App | Timing | Priority | Deep link | Suppression rule |
|---|---|---|---|---|---|---|---|---|
| `o_invite_response` (`:accepted` / `:declined`) [flow_recruitment_updates] | Organizer | ✓ | – | ✓ | Vendor answers an invitation | normal | `/(organizer)/vendors?tab=invites` | Invite status unchanged |
| `o_new_vendors_nearby` [flow_recruitment_updates] | Organizer | ✓ | ✓ (public vendor cards) | ✓ | Hourly, ≥3 new discoverable matches, 72h cooldown per event, baseline on first run | promotional | `/(organizer)/vendors/find?event={id}` | Event live, upcoming, spots open |
| `o_open_spots:deadline` [flow_recruitment_updates] | Organizer | ✓ | ✓ | ✓ | 7 and 2 days before the application deadline | normal | Find Vendors | Spots still open |
| `o_event_no_slots` [flow_activation] | Organizer | ✓ | ✓ | ✓ | Published ≥1 day without a published opportunity (day 1, day 4) | normal | `/(organizer)/events/{id}` | Still no published opportunity |
| `v_invite_expiring` [flow_discovery] | Vendor | ✓ | ✓ | ✓ | Open invite expiring in 2–3 days | normal | `/(vendor)/invitations` | Invite open, opportunity open, not applied |
| `v_application_draft` [flow_vendor_engagement] | Vendor | ✓ | ✓ | ✓ | 24h / 72h after last edit; ≤3 days before deadline | normal | `/opportunity/{opp}` | Draft open, opportunity open, not applied. Cancelled on submit |
| `v_inactive` [flow_vendor_engagement] | Vendor | ✓ | ✓ | ✓ | Idle 14 / 30 / 60 days, once per segment, only with live recommendations | promotional | `/(vendor)/find` | No new activity, recommendations still exist |
| `v_event_thanks:first` [flow_post_event] | Vendor | – | ✓ | ✓ | First completed booking | normal | `/(vendor)/find` | — |

Policy added in 1.3.0: weekly promotional push cap (default 3), daily push cap default 1, promotional email cap default 2/week, optional per-category caps, cross-channel cooldown (default 4h after a high, critical or promotional email/push), and measurement holdouts for engagement triggers. Inbox tabs gained **Invitations** and **Account**.

## Shared / admin

| Trigger | Recipient | Push | Email | In-App | Timing | Priority | Deep link | Suppression rule |
|---|---|---|---|---|---|---|---|---|
| `campaign_message` [campaigns_enabled] | Admin-defined segment | per campaign | per campaign | ✓ | Admin starts a campaign; batches of 100 | promotional | Selected link | Category opt-outs, caps, suppression list. Large audiences require confirming the exact count |
| `admin_health_alert` | `support_email` / site admin | – | ✓ | – | Hourly while any health check is in error (at most every 6h) | high | wp-admin health tab | — |

## Global rules

- **Dedupe**: one message per `trigger|recipient|object|window`. Retries and duplicate hooks never create a second logical message, and there is one inbox row per message even when the user has several devices.
- **Stale prevention**: scheduled messages are cancelled immediately when the underlying state changes (cancel keys `app:{id}|payment`, `opp:{id}`, `event:{id}`, …). The send-time validity check is the backstop.
- **Authorization at send**:
  - Vendor messages require ownership of the application or document.
  - Organizer messages require event ownership or staff `view_roster`. Financial messages require **ownership**, never staff.
  - Removing a role cancels scheduled messages for that role.
- **Quiet hours** use the recipient's timezone. Event countdowns use the event timezone (`_lnkup_timezone`, falling back to the site timezone).
- **Retention**: finished history older than `retention_days` (default 180) is purged daily. Message bodies are never stored.

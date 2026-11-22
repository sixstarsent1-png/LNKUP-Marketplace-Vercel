# LNKup Communications Audit (Phase Zero)

Audit date: 2026-11-22. Plugin audited: `lnkup-marketplace` 12.22.1. Mobile: `lnk-up-marketplace-mobile` (Expo SDK 57). Web: `lnk-up-marketplace-ui` (Next.js 16).

Everything below is **verified from code** unless marked *(inference)*.

## 1. What works today

### Email
Only three `wp_mail` call sites exist in `wp-content`:

| File | Function | Purpose |
|---|---|---|
| `includes/class-notifications.php` | `LNKup_Notifications::send()` | Branded HTML wrapper (`templates/emails/wrapper.php`). Every other message routes here. |
| `includes/class-invoices.php` | `LNKup_Invoices::email_invoice()` | Unbranded invoice HTML, fired on `lnkup_application_approved`. Duplicates the "payment due" email. |
| `includes/class-financial-cron.php` | `LNKup_Financial_Cron::send_reminder_email()` | Plain-text `installment_due` / `deposit_due`. |

Global toggles in `lnkup_settings`: `notify_vendor`, `notify_organizer`, `notify_admin`, `activation_emails_enabled`, `saved_search_alerts_enabled`, `deadline_reminder_favorites`, `outreach_*`. **No per-user email preferences.**

### Delivery ledger
`wp_lnkup_notification_deliveries` (`LNKup_Inbox_Tables::delivery_table()`): `claim_delivery()` uses `INSERT IGNORE` on `event|ref|user|channel|window`; `finish_delivery()` schedules WP-Cron retries at 15m / 1h / 6h. `retry_delivery()` can only replay 8 event keys.

### Messages currently sent

Ledger = dedupe + retry. Direct = no dedupe.

| Message | Recipient | Trigger | Path |
|---|---|---|---|
| application_submitted | vendor (email+inbox), organizer (email+inbox+push) | `LNKup_Applications` submit | Ledger |
| application_approved / payment_required | vendor | approve transition, `LNKup_Payments` | Ledger |
| payment_confirmed / packet_unlocked (one "booking confirmed") | vendor | paid transition, `LNKup_Packets` | Ledger |
| documents_needed | vendor | financial daily cron, organizer nudge | Ledger |
| arrival_reminder (24h) | vendor | financial daily cron | Ledger |
| invoice reminder 7d/3d/24h/past_due | vendor | financial daily cron | Ledger |
| event_logistics_changed | booked vendors | `LNKup_Organizer_Onboarding::handle_logistics_change` | Ledger |
| info_requested / info_response | vendor / organizer | application info thread | Ledger |
| application_rejected, application_waitlisted | vendor | transition | Direct |
| pass_details_updated | vendor | packets, booths | Direct |
| organizer_vendor_paid | organizer | `LNKup_Payments::mark_paid` | Direct |
| document_pending_admin, external_event_missing_email_admin | admin | uploads, external events | Email only |
| saved_search_match, favorite_deadline_reminder, favorite_organizer_new_event, deadline_approaching, almost_full, profile_match | vendor | `LNKup_Marketplace_Cron` (phase 1c only) and `publish_lnkup_opportunity` | Direct, synchronous fan-out |
| vendor_invite | vendor | `LNKup_Vendor_Invites` | Direct |
| post_event_review_prompt | organizer | marketplace daily cron | Direct |
| platform_outreach, admin "Email accounts" broadcast | non-users / users | outreach queue | Async queue (rate limited) |
| external_event_vendor_interest / followup | external organizers | external events | Email only, open pixel, **no unsubscribe** |
| activation drip (7 messages) | vendor / organizer | `LNKup_Activation_Emails` | Direct |
| ops_broadcast | event vendors | `LNKup_Broadcasts` | Direct, synchronous loop |

All sends are **synchronous** inside the request or cron run, except the outreach queue and retries.

### In-app inbox
`wp_lnkup_inbox_index` (`LNKup_Inbox_Service`), REST: `GET /notifications/inbox`, `/count`, `/read-ids`, `POST /notifications/inbox/{client_id}/read`, `/read-all`. Separate legacy stores: user meta `lnkup_ops_broadcasts`, `lnkup_vendor_notices`.

### Push
`wp_lnkup_push_devices` (unique token, `enabled`). `LNKup_Push_Service` posts to Expo in chunks of 100. REST: `POST|DELETE /push/devices`, `GET|PATCH /push/preferences` (user meta `lnkup_push_enabled`, `lnkup_push_categories` with `applications, payments, packets, discovery, account`).

### Mobile
`expo-notifications ~57.0.11`, permission + token in `lib/push/notifications.native.ts`, tap/cold-start in `components/push-bootstrap.native.tsx`, routing allowlist in `lib/navigation/pending-destination.ts`, inbox `components/notifications-screen.tsx`, bell, push preferences card, token unregistered on logout (`lib/auth/auth-context.tsx`).

## 2. Partially implemented / broken

- **Scheduler:** visitor-triggered WP-Cron only. No Action Scheduler. Finance reconciliation confirmed `lnkup_financial_hourly` never ran. `lnkup_marketplace_daily` only runs in marketplace phase `1c`.
- **Push receipts never checked.** `DeviceNotRegistered` tokens never disabled.
- **Android FCM missing** — no `google-services.json` / `googleServicesFile`.
- **Permission requested automatically on sign-in** — no contextual pre-prompt.
- **Type drift** between mobile `LnkupNotificationType`, routing table and the web union.
- **Web preferences** route (`app/api/notifications/preferences`) does not proxy WordPress.
- **Duplicate approval email** (invoice + payment due).
- **`lnkup_waitlist_spot_available`** fires but nothing notifies.

## 3. Missing

- Per-user email preferences, digests, quiet hours, timezone.
- Unsubscribe links, `List-Unsubscribe`, suppression list, bounce/complaint handling.
- Frequency caps.
- Admin communications dashboard, campaign builder, audience preview, health.
- Organizer event communications with audience preview and history.
- Connector communications.

### Hooks missing in core (status written directly with `update_post_meta`)

- Payment failed / processing / reopened / partially paid / refunded status (`class-stripe-webhooks.php`, `class-payments.php`, `class-financial-service.php`, `class-refunds.php`, `void_pending_payment`).
- Webhook refund (`charge.refunded`), dispute close (`close_chargeback`).
- Connect `account.updated` / deauthorized.
- Organizer payout transitions.
- Check-in actions (`LNKup_Checkins::apply_side_effects`).
- Per-application event completion (`mark_post_event_reviewable`).
- Event publish / status, opportunity open/close/trash.
- Document upload, document review.
- Logistics change (calls notifications directly), invite status.

### Hooks listened for but never fired
`lnkup_event_completed` (escrow release listens), `lnkup_vendor_checked_in`, `lnkup_review_submitted`. These are **not** fired by the communications work: firing them would trigger untested escrow/timeline side effects. New, communication-specific hooks are used instead. A separate financial review should decide whether to wire them.

### No code path
Organizer event cancellation, vendor withdrawal, per-event timezone.

## 4. Bugs found

- Document REST maps "expires within 30 days" to `expired` (`class-documents-rest.php`).
- External event helpers look up vendor profiles by `_lnkup_vendor_user_id`; the canonical owner key is `_lnkup_owner_user_id`.
- `lnkup_invoice_created` fires with reversed argument order in `class-payments.php` vs `class-invoices.php` (left as-is; listeners already cope).
- Account deletion leaves inbox rows, delivery rows and preference meta.

## 5. Financial dependency

`docs/finance/RECONCILIATION_2026-11-21.md` documents wallet/ledger discrepancies (duplicate credits, missing ledger rows, failed `connect_settlement` inserts). Fixes ship in 12.22.1 (not yet deployed). Communications rules:

- Payment success only from `lnkup_payment_completed` (fired by `LNKup_Financial_Service::finalize_payment` after a verified webhook).
- Payment failed / refunded / dispute only from new hooks placed at verified webhook handlers.
- Payout and Connect status notifications are behind the `fin_payout_notifications` flag, **off** until reconciliation sign-off.
- No notification reads wallet or summary display figures.

## 6. Security / privacy concerns

- Outreach and external-organizer emails have no opt-out (CAN-SPAM risk).
- Open-tracking pixel on external follow-ups.
- Push payloads carry only IDs and safe text (OK).
- Synchronous fan-out loops can time out requests and partially send.

## 7. Resolution

See the `lnkup-communications` plugin (`wp-content/plugins/lnkup-communications/`) and `docs/COMMUNICATIONS_MATRIX.md`.

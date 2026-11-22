# LNKup Communications

Communications & Engagement OS for LNKup Marketplace. It is the single engine for push (Expo), email (`wp_mail`) and the in-app inbox, for vendors and organizers. It depends on **lnkup-marketplace**, which remains the system of record. This plugin stores only communication state: messages, deliveries, preferences and suppressions.

- Audit: `docs/COMMUNICATIONS_AUDIT.md`
- Notification matrix: `docs/COMMUNICATIONS_MATRIX.md`
- Admin: **LNKup → Communications**

## Architecture

```
lnkup-marketplace hooks (verified state changes) ─┐
Automations (Action Scheduler: hourly/daily/weekly)├─► LNKup_Comms_Engine::dispatch()  (dedupe, authorize, validate, store)
Organizer / admin campaigns ───────────────────────┘          │
                                                              ▼
                                Action Scheduler (group lnkup-comms) ► LNKup_Comms_Engine::deliver()
                                                              │   re-validate · prefs · caps · quiet hours · render
                                        ┌─────────────────────┼─────────────────────┐
                                        ▼                     ▼                     ▼
                              Inbox (wp_lnkup_inbox_index)  Email (wp_mail)    Push (Expo tickets → receipts)
```

- **Never sends inside a business request.** `dispatch()` writes a row and queues it.
- **Delegation.** lnkup-marketplace asks `lnkup_pre_send_notification` before each legacy send. A message key is claimed only while its flow flag is on. If a flag is turned off, or this plugin is deactivated, the legacy sends resume automatically.
- **Financial safety.** Payment messages fire only from `lnkup_payment_completed`, `lnkup_payment_status_changed` (canonical meta written by webhook handlers or the Financial Service), `lnkup_refund_synced`, `lnkup_chargeback_closed`, Connect status, and payout ledger hooks. Nothing reads wallet balances or summary figures. Payout and payout-setup messages sit behind `fin_payout_notifications`, which should stay **off** until financial reconciliation sign-off (`docs/finance/RECONCILIATION_2026-11-21.md`).

## Tables (prefix `wp_lnkup_comms_`)

| Table | Contents |
|---|---|
| `events` | One row per logical trigger occurrence, deduplicated |
| `messages` | One per recipient: status, priority, category, schedule, expiry, cancel key, read/opened/clicked |
| `deliveries` | Per channel (and per device for push): provider ref, status, attempts, error, suppression reason |
| `preferences` | `user × category × channel → mode` |
| `suppressions` | SHA-256 email hash × scope (unsubscribe / bounce / complaint) |
| `templates` | Admin overrides of the catalog |
| `campaigns` | Admin and organizer campaigns, which double as the audit trail |
| `clicks` | Signed click-tracking hits |

## Lifecycle hooks added to lnkup-marketplace

| Hook | Fired from |
|---|---|
| `lnkup_pre_send_notification` (filter) | Every `LNKup_Notifications::send_*`, `LNKup_Invoices::email_invoice`, `LNKup_Financial_Cron::send_reminder_email` |
| `lnkup_payment_status_changed`, `lnkup_event_status_changed`, `lnkup_opportunity_status_changed`, `lnkup_document_status_changed`, `lnkup_vendor_invite_status_changed` | `LNKup_Lifecycle_Hooks` (meta watcher on canonical status keys) |
| `lnkup_opportunity_removed`, `lnkup_event_removed`, `lnkup_event_published` | `LNKup_Lifecycle_Hooks` |
| `lnkup_refund_synced` | `charge.refunded` webhook handler |
| `lnkup_chargeback_closed` | `LNKup_Financial_Disputes::close_chargeback` |
| `lnkup_connect_account_status_changed` | `LNKup_Stripe_Connect::apply_stripe_account_payload` |
| `lnkup_organizer_payout_status_changed` | `LNKup_Organizer_Payouts::mark_paid` / `handle_async_failure` |
| `lnkup_checkin_recorded`, `lnkup_application_event_completed` | `LNKup_Checkins::apply_side_effects`, `LNKup_Marketplace_Cron::mark_post_event_reviewable` |
| `lnkup_document_uploaded`, `lnkup_event_logistics_changed` | Documents upload, organizer logistics edit |
| `lnkup_application_withdrawn` | `LNKup_Applications::withdraw()` — new: `POST /applications/{id}/withdraw` |
| `lnkup_event_cancelled` | `LNKup_Event_Status` — new: `POST /organizer/events/{id}/status` |
| `lnkup_event_workspace_actions` (filter) | Organizer event workspace header (adds **Communications**) |
| `lnkup_dashboard_nav_items` (filter, theme) | Dashboard sidebar (adds **Notifications**) |

`lnkup_event_completed` and `lnkup_vendor_checked_in` are deliberately **not** fired. Escrow release and the payment timeline listen to them, and wiring them needs a separate financial review.

## REST (`lnkup/v1`)

| Route | Who |
|---|---|
| `GET|PATCH /comms/preferences` | Signed-in user |
| `GET /comms/inbox?role&tab&page&filter`, `GET /comms/inbox/count`, `POST /comms/inbox/{id}/read`, `POST /comms/inbox/read-all`, `POST /comms/opened` | Signed-in user (own rows only) |
| `GET /comms/next-steps?role`, `GET /comms/registry` | Signed-in user |
| `GET /comms/events/{id}/audiences|templates|history`, `POST /comms/events/{id}/preview|send` | Event owner or staff with `broadcast`; flag `organizer_broadcasts` |
| `/comms/admin/*` (overview, logs, health, settings, templates, campaigns, analytics, automations, test-send) | `manage_options` / `lnkup_manage_all` |
| `POST /comms/email-webhook/{postmark|sendgrid|mailgun|ses|generic}` | Shared secret (`X-LNKup-Webhook-Secret` or `?key=`) |
| `/?lnkup_unsubscribe=…` (GET confirm, POST RFC 8058 one-click) | HMAC-signed token |
| `/?lnkup_c=…` | HMAC-signed click redirect (same-site only) |

The existing `/notifications/inbox*` and `/push/*` routes keep working. Changes made through legacy push preferences sync into the new preferences.

## Deployment

### Required

1. **Real cron runner.** WP-Cron depends on traffic; production must not rely on it for reminders.
   - `wp-config.php`: `define( 'DISABLE_WP_CRON', true );`
   - Server cron, every minute:
     `* * * * * cd /path/to/wp && wp action-scheduler run --batch-size=50 --quiet && wp cron event run --due-now --quiet`
   - Check: **System health → Background worker** turns `ok` within 2 hours.
2. **Deploy lnkup-marketplace with the seam edits first**, then activate lnkup-communications. With every flag off, nothing changes for users.
3. **Expo access token.** If enhanced push security is enabled in Expo, set `expo_push_access_token` in LNKup → Settings. It is server-side only.

### Push (EAS, native — requires a new binary)

- **Android:** create an FCM V1 service account and upload it with `eas credentials` → Android → Push notifications. Add `google-services.json` and set `"android": { "googleServicesFile": "./google-services.json" }` in `app.json`. **This requires a new EAS build.**
- **iOS:** APNs key in EAS credentials. It is already set up if iOS push works today.
- **OTA-eligible (JavaScript only):** the inbox tabs and pagination, preferences screen, permission pre-prompt, Android channel creation (`setNotificationChannelAsync`), next-steps cards, event communications screen, and routing changes. Publish with `npm run update:production` in `lnk-up-marketplace-mobile`.
- **Android channels:** enable **Settings → Use Android channels** only after the OTA update that creates them has reached users. Until then the server keeps sending to `default`.

### Email

- Keeps the site's existing `wp_mail` transport (SMTP or provider plugin). No second provider is added.
- Verify **SPF, DKIM and DMARC** alignment for the sending domain (thelnkup.com) with the mail provider.
- Optional bounce and complaint webhooks: choose the provider and secret in **Settings**, then add the displayed URL in the provider dashboard.
- Set **Sender postal address**. It is required in commercial emails (CAN-SPAM).

### Flag activation order (one at a time; watch Delivery logs and System health between steps)

1. `flow_applications`
2. `flow_payments`
3. `flow_documents`
4. `flow_operations`
5. `organizer_broadcasts`
6. `flow_onboarding` (new accounts only)
7. `flow_countdown`
8. `flow_digests`
9. `flow_post_event`
10. `flow_discovery` (start with a small cap)
11. `flow_recruitment`
12. `flow_retention`
13. `campaigns_enabled`
14. `fin_payout_notifications` (after reconciliation sign-off)

### Rollback

- **Single flow:** untick its flag. lnkup-marketplace resumes the legacy send for that flow on the next event.
- **Emergency stop:** tick **Pause ALL deliveries**. Messages stay queued and nothing is lost.
- **Full rollback:** deactivate the plugin. The delegation filter disappears and every legacy send resumes. Tables are kept, and reactivating resumes from the same state.

## Tests

- PHP integration (wp-env): `npm run test:comms:php`. Seven suites: schema, engine, lifecycle, preferences, push, organizer, security.
- Financial regression: `npm run test:fin:php`
- Playwright: `powershell -File scripts/e2e/run-specs.ps1 tests/e2e/communications.spec.ts --project=chromium`
- Mobile: `npm test` and `npm run typecheck` in `lnk-up-marketplace-mobile`

## Bundled library

- `lib/action-scheduler` — WooCommerce Action Scheduler 4.2.0, GPL-3.0. It declares "Requires at least: WordPress 6.9", so confirm the production WordPress version, or pin an older Action Scheduler release.

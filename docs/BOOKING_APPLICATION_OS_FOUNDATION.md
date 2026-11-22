# Booking & Application OS: Foundation (plugin 12.26.0)

Covers Stages 1, 3–5 and 7 of the Booking & Application OS brief (plus the pre-approval part of Stage 6). The baseline audit and the numbered bugs (`B-n`) are in [BOOKING_APPLICATION_OS_AUDIT.md](BOOKING_APPLICATION_OS_AUDIT.md). WordPress stays the system of record; no new application or booking engine was added.

## A. What shipped

| Area | Result |
|---|---|
| Integrity fixes (Stage 7) | B-1 … B-18 closed (table below). Ship without a flag. |
| Server-side drafts (Stage 3) | `LNKup_Application_Drafts`; autosave, cross-device resume with revision conflicts, idempotent submit, daily expiry. |
| Wizard UI (Stages 2–3) | Step wizard in the apply modal behind `apply_wizard_enabled`; collapses to one step when there are no questions/documents. Resume cards and Withdraw on vendor Requests. |
| Prefill + questions (Stage 4) | Profile snapshot prefill, "this application only" overrides vs. "also update my profile", organizer questions per slot, document attachment, optional "documents required to apply" gate. |
| Multi-stop (Stage 5) | `LNKup_Application_Groups`; tour / series multi-apply behind `tour_multi_apply_enabled` (requires Tours & series). |
| Pricing (Stage 6, partial) | Read-only `GET /applications/price-quote/{opp}` estimate from `LNKup_Fee_Engine`; checkout summary shows what `prepare()` will charge. |

### Integrity fixes

| Bug | Fix | Files |
|---|---|---|
| B-1 | Exact `verify_token` match (`esc_like` + `hash_equals`), token format check, 20 req / 10 min / IP limit, no third-party QR service. | `class-invoices.php`, `public/class-shortcodes.php` |
| B-2 | Webhooks always need a valid signature. Payment simulation needs test mode **and** `WP_DEBUG` **and** an admin (vendors only with the explicit `LNKUP_ALLOW_PAYMENT_SIMULATION` dev constant). | `financial/class-financial-service.php`, `class-stripe.php` |
| B-3 | `GET /financial/checkout/{id}/summary` writes nothing; it previews current fees in memory. Fees persist in `prepare()`; confirmation via `POST /confirm` and webhooks. | `financial/class-checkout.php`, `class-payments.php` (`pending_fee_preview`) |
| B-4 | `INSERT IGNORE` on `event_id` + atomic `claim_event()` (`pending/failed → processing`); cron reclaims stale `processing`. Refund/dispute handlers run under the `pay_{id}` lock. | `financial/class-stripe-webhooks.php` |
| B-5 | A voided payment is replaced by a fresh payment + invoice on re-approval. | `class-payments.php`, `class-invoices.php` |
| B-6 | Payment creation failure rolls the application back to its previous status and returns 503. | `class-applications.php` |
| B-7 | `paid` is no longer reachable from `rejected`/`cancelled`. Late money sets `_lnkup_needs_review = paid_after_close` (shows in reconciliation as `charge_paid_after_close`). Rejecting a `processing` payment closes open charges and records it. | `class-applications.php`, `class-payments.php` |
| B-8 | Full refund or lost chargeback locks the pass, cancels the application (releases inventory) and keeps payment history. Pass blocked for `refunded`/`chargeback_lost`/`disputed`; check-in also, plus `partially_refunded` when the event sets `_lnkup_block_checkin_partial_refund`. | `class-refunds.php`, `class-packets.php`, `class-checkins.php`, `financial/class-stripe-webhooks.php` |
| B-9 | Card refunds capped at what the card collected; the credit-covered part goes back to the wallet. Deposit bookings refund across every PaymentIntent (resumable). `charge.refunded` sums per charge. Earlier deposit intents resolve to their payment. | `class-refunds.php`, `financial/class-stripe-provider.php`, `financial/class-stripe-webhooks.php` |
| B-10 | Voiding a payment restores applied credit once (`LNKup_Credits::restore_for_payment`). | `class-credits.php`, `class-applications.php` |
| B-11 | `lnkup_invoice_created( $payment_id, $invoice_id )` fires once; line items are only what the vendor pays and sum to the total; void / refunded statuses; no $0.00 invoice emails. | `class-invoices.php` |
| B-12 | Scoped commission rules beat global rules. Upgrade copies the active global percent rule into `organizer_commission_percent`, then retires the rule. Effective fees unchanged; audit-logged. `seed_defaults()` no longer seeds a global rule. | `financial/class-fee-engine.php` |
| B-13 | Fee calc uses the application's chosen category and `_lnkup_payment_method` when set. | `class-payments.php`, `financial/class-fee-engine.php` |
| B-14 | Capacity 0 = unlimited everywhere (`spots_unlimited` on cards). | `helpers.php`, `class-apply-with-profile-view.php`, `matching/class-tours.php`, `class-applications.php` |
| B-15 | Invite-only enforced at apply, draft save, price quote and questions; invite token validated and linked (`_lnkup_invite_id`); apply context hidden from uninvited visitors. | `class-applications.php`, REST, view, `class-ajax.php`, `public/js/marketplace.js` |
| B-16 | Only active statuses block re-applying (`ACTIVE_STATUSES`). | `class-applications.php` |
| B-17 | `_lnkup_decided_at` written; status timeline `_lnkup_status_history` (records `approved` before `payment_required`); `paid → completed` when the event date passes. | `class-applications.php`, `class-marketplace-cron.php` |
| B-18 | wp-admin metabox cannot write status/ownership/snapshots; status edits go through `transition()`, and payment statuses (`payment_required`, `paid`, `completed`) cannot be set manually. | `class-meta.php` |

Also: application snapshots are stored as JSON with a URL-safe sanitizer (`LNKup_Meta::sanitize_json_meta`, `json_for_meta`); deadlines compare in the site timezone (`lnkup_deadline_passed`); cancelled/postponed events block applying.

## B. REST API

All `lnkup/v1`, all with `permission_callback`.

| Method | Route | Permission | Notes |
|---|---|---|---|
| GET | `/applications/drafts` | vendor | Resume cards (open drafts, newest first). |
| GET | `/applications/drafts/{opportunity_id}` | vendor | `{ draft: DTO \| null }`. |
| PUT | `/applications/drafts/{opportunity_id}` | vendor | Body `step, fields{selected_category, application_message, setup_notes}, answers{}, profile_overrides{}, document_ids[], revision, client, invite_token`. 409 `lnkup_draft_conflict` with `data.draft` when `revision` is stale; 413 over 20 KB; 409 `duplicate` if already applied. |
| DELETE | `/applications/drafts/{opportunity_id}` | vendor | Marks the draft discarded (kept for analytics). |
| POST | `/applications/drafts/{opportunity_id}/submit` | vendor | **`Idempotency-Key` header required.** Body `accepted_terms, accept_current_price`. 409 `lnkup_price_changed` (`previous_price`, `current_price`), 422 `lnkup_answers_required` (`fields[]`). Replays return `replayed: true` and the same `application_id`. |
| GET | `/applications/price-quote/{opportunity_id}?category=` | vendor | `{ is_estimate, lines[{key,label,amount}], total, note }`. |
| GET | `/opportunities/{id}/application-questions` | public (invite-only filtered) | `{ questions[], documents_required_to_apply }`. |
| PUT | `/opportunities/{id}/application-questions` | organizer + owns event | Max 20 questions; types `text, textarea, select, checkbox, file`. |
| GET | `/tours/{id}/apply-context` | vendor | Stops, slots with `quote`, `questions`, `categories`, `can_apply`/`block_reason`, profile, vendor documents, saved group draft. 404 when the flag is off. |
| PUT | `/tours/{id}/apply-draft` | vendor | Autosave of the multi-stop form (`group_id` required). |
| POST | `/tours/{id}/apply` | vendor | **`Idempotency-Key` header** + `group_id`. Returns `results[]` per stop: `submitted, already_submitted, already_applied, closed, full, ineligible, needs_input, error`, and a summary. |

Changed: `POST /applications/apply-with-profile` accepts `invite_token`, `source`; `GET /applications/apply-context/{id}` accepts `?invite=`; `/applications/vendor` and `/applications/organizer` items add `status_history`, `group_id` (organizer also `answers`).

## C. Data

No new tables; `LNKUP_VERSION` 12.25.0 → 12.26.0.

- Post status `lnkup_app_draft` (internal, excluded from `any`, admin lists and search). Drafts carry only `_lnkup_draft_*` meta, so no existing query, counter, inventory hold or notification can see them. Direct SQL filters also exclude the status.
- New application meta: `_lnkup_application_answers` (frozen with labels), `_lnkup_document_ids`, `_lnkup_submission_group_id`, `_lnkup_idempotency_key`, `_lnkup_invite_id`, `_lnkup_status_history`, `_lnkup_started_at`, `_lnkup_submitted_client`, `_lnkup_draft_id`.
- Opportunity meta: `_lnkup_application_questions`, `_lnkup_documents_required_to_apply`. Event meta: `_lnkup_block_checkin_partial_refund` (optional).
- Payment meta: `_lnkup_refunded_by_charge`, `_lnkup_refunded_by_intent`, `_lnkup_refund_inflight`, `_lnkup_credit_restored`, `_lnkup_closed_while_processing`, `_lnkup_needs_review = paid_after_close`.
- Webhook log status gains `processing` (claimed, in flight).
- Settings: `apply_wizard_enabled`, `tour_multi_apply_enabled` (default off; LNKup → Settings → Vendor applications).

## D. Financial verification

- **Pricing**: only `LNKup_Fee_Engine`. Before approval: estimate route. After approval: the payment's frozen booth price plus fees, recalculated only while `pending` and persisted in `prepare()`. Drafts quote the booth price at start; a changed price is shown and must be accepted before submit.
- **Payment truth**: only verified webhooks / server-side intent checks mark paid. Return pages and the summary route never do.
- **Duplicates**: application submit is per-vendor/slot locked + idempotency key; drafts submit once; multi-stop uses `{group}-{opp}` keys; webhooks are claimed atomically; refunds are claimed per row and resumable across intents.
- **Refunds**: card part ≤ collected card amount; credit part back to wallet; organizer share reversed through the existing `sync_payment_refund_status` path; booking closed on full refund / lost chargeback; history kept.
- **Consistency**: invoices = payment line items; organizer and vendor views read the same payment/ledger records. Combined checkout across organizers stays out of scope (one Connect destination per payment).

## E. Testing

Done in this workspace (no PHP runtime or Docker available here):
- All changed PHP files parse (php-parser, PHP 8 grammar); all changed JS passes `node --check`; new specs type-check with `tsc --strict`.
- `node scripts/money-path-verify.mjs` passed; `node scripts/phase26-verify.mjs` passed after updating its webhook-guard snippet to the new stricter guard; `node scripts/gap-close-verify.mjs` passed.

**Not run yet** (needs wp-env):
- `npm run test:apps:php`: `scripts/e2e/php/booking-application-os-test.php` (B-1…B-18, drafts, questions, multi-stop, last-spot approval) plus the refund/dispute, checkout and security financial suites.
- `npm run test:fin:php` (full financial regression).
- Playwright: `tests/e2e/apply-draft-resume.spec.ts` (two devices, conflict, idempotent replay), `tests/e2e/tour-multi-apply.spec.ts` (per-stop results, retry without duplicates, forged slot), additions to `apply-with-profile.spec.ts`. Re-seed first: `npm run e2e:seed` (enables both flags locally; adds `PLAYWRIGHT_DRAFT_OPPORTUNITY_ID`, `PLAYWRIGHT_TOUR_ID`).

Concurrency on the last slot is covered sequentially under the inventory lock; true parallel requests are not exercised by `wp eval-file`.

## F. Deployment

1. Before deploy: run `/admin/financial/reconcile` and save the report. Record the active `wp_lnkup_fee_rules` rows (`scope_type = 'global'`) and `organizer_commission_percent`; the upgrade copies the global percent rule into the setting and retires the rule (audit `fee_global_commission_rule_retired`). Confirm the copied value is the intended production commission.
2. Deploy plugin 12.26.0 (and the `lnkup-communications` analytics query change). The version bump runs the deferred upgrade.
3. Confirm both webhook signing secrets are set. Unsigned events are now always refused, including in test mode.
4. Re-run reconciliation; compare. Look for `charge_paid_after_close`.
5. Enable `apply_wizard_enabled`, then `tour_multi_apply_enabled` (with Tours & series) when ready.

Rollback: turn the two flags off (the old one-step form returns). The integrity fixes only move statuses forward and keep data compatible with 12.25. Retired global fee rules can be reactivated from SQL if needed; the setting already holds the same value.

## G. Remaining limitations

- No mobile (Expo) wizard yet; the REST contract above is ready for it (Stage 11).
- No abandonment / payment reminders yet (Stage 9), no funnel analytics (Stage 12), no add-ons (Stage 17), no admin health page (Stage 23).
- `fee_payer_mode = split` still behaves as `vendor`; ACH processing rate applies only when `_lnkup_payment_method` is known before charging (Stripe picks the method after the amount is set, so card rate is the default).
- Refunds still cannot start from `partially_paid` bookings (refund rules unchanged).
- Recurring series use the multi-stop flow; "notify me about future dates" relies on existing saved searches.
- The organizer slot editor still requires at least 1 spot; unlimited capacity is honored when set elsewhere (API / onboarding).

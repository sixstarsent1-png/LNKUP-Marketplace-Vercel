# Root-cause audit: organizer Wallet missing recent paid invoices

Status: **code-level causes confirmed and fixed locally; production impact not yet measured.** Every item below is proven by a failing-then-passing local test on wp-env (see `scripts/e2e/php/`). Which of these produced the specific production symptoms (Available $0, LNKup settled $0, newest invoices missing, four +$230 rows) is confirmed only after the read-only diagnostics run against production (see "Production confirmation" at the end).

Severity: **P0** loses or hides money, **P1** wrong or duplicated figures, **P2** misleading display.

## Confirmed defects

### 1. Every `connect_settlement` ledger insert failed silently — P0
- **Evidence:** `wp_lnkup_organizer_wallet_entries.entry_type` was `varchar(16)`; `'connect_settlement'` is 18 characters. WordPress rejects over-length values, so `$wpdb->insert()` returned `false` with no error. Reproduced locally: the insert returns false and the row is missing.
- **Where:** `LNKup_Treasury_Tables::create_tables()`; writers `LNKup_Organizer_Wallet::record_connect_settlement()`, `migrate_treasury_balance_to_connect_settlement()`, `LNKup_Organizer_Payouts::restore_failed_cashout()`.
- **User impact:**
  - **LNKup settled always $0:** no destination charge ever got its ledger mirror.
  - **Repeated settlement side effects:** destination payments never looked settled, so each dashboard read re-ran settlement (platform fee rows, timeline entries, activity, audit).
  - **Balance silently lost:** the treasury → Connect migration zeroed `available_balance` first, then its ledger row failed, so platform-held money disappeared from Available. Matches "Available $0 while ledger credits exist".
  - **Restores lost:** destination cashout restores (failed or cancelled payouts) never wrote their row.
- **Fix:** widen to `varchar(32)` (dbDelta on upgrade to 12.22.0); migration rolls back the zeroing if its row cannot be written; diagnostics report the column width and every migration audit event that has no ledger row (`migration_zeroed_without_ledger`).

### 2. GET requests wrote to the ledger, concurrently — P1
- **Evidence:**
  - `get_balances()` ran migrate + backfill on every read. `organizer_balance_payload()`, `/transactions` and `vendor_dashboard()` each ran a full reconcile, guarded only by transients (check-then-set, with two different keys).
  - The Money page fires `/organizer-balances`, `/dashboard` and `/transactions` in parallel (`Promise.all`), so two requests could settle the same payment at once.
  - Ledger inserts had no unique key: guards were `SELECT … LIMIT 1` followed by `INSERT`.
- **User impact:** duplicate settlement credits are possible; balances move when someone merely opens the page.
- **Fix:**
  - All money GETs are now pure reads.
  - Catch-up runs in `LNKup_Financial_Service::sync_organizer()` / `sync_vendor()`, triggered by the hourly cron, Cash Out, or `POST /financial/sync` (throttled 30s).
  - The ledger has a unique `idempotency_key` (`payment:{id}:credit`, `payment:{id}:connect_settlement`, `payout:{id}:debit|restore`). Rows are inserted first; balances move only if the insert succeeded, using atomic `UPDATE … SET x = x ± amount`.
  - Test: `financial-sync-test.php` "money GET endpoints did not write…".

### 3. Finalize was not serialized — P1
- **Evidence:** `finalize_payment()` checked `status === 'paid'` and then wrote it, with no lock. A webhook, the client confirm and the checkout-summary fallback could all pass the check together.
- **Fix:** per-payment MySQL `GET_LOCK('lnkup_pay_{id}')` around finalize and repair; the status is re-checked inside the lock. Test: a held lock makes a second finalizer back off; replaying finalize leaves exactly one settlement row.

### 4. Paid but never settled stayed unsettled forever — P1
- **Evidence:** if `mark_paid()` succeeded and `settle_treasury()` then crashed, every retry hit `already_paid` and returned. `reconcile_payment()` only backfilled destination charges.
- **Fix:** `_lnkup_settled_at` marker plus `ensure_settled()` in both finalize and reconcile, for both settlement modes. For safety, automatic recovery only applies to payments paid after the 12.22 upgrade (`lnkup_fin_auto_recovery_since`). Older gaps go through the approval-gated repair tool.

### 5. Late `payment_intent.payment_failed` overwrote `paid` — P0
- **Evidence:** the handler set `failed` unconditionally. Events arrive out of order, and a failed first attempt can be followed by a success on the same or a newer PaymentIntent.
- **User impact:** a collected payment drops out of Collected and the Wallet, and the vendor appears unpaid.
- **Fix:** ignored when the payment is paid, refunded or disputed, or when the event's intent is not the stored one (audited as `payment_failed_ignored`). Reconcile also re-checks `failed` payments against Stripe. `mark_paid()` keeps the original paid date.

### 6. Newest paid invoices fell off the list — P1
- **Evidence:**
  - `LNKup_Transactions::list_for_user()` sorted by the transaction's `created_at` (invoice creation) with LIMIT 25. An invoice created early and paid today sat behind 25 newer unpaid invoices.
  - `lnkup_payment_display_rows()` grouped by **event** and kept the larger amount, so two $230 bookings at one event showed as one $230 row (Revenue by month and vendor lists undercounted).
- **Fix:** sort by `COALESCE(paid_at, created_at)` with `page` and `X-WP-Total` / `X-WP-TotalPages` headers. "Load more" on Vendor revenue and the Ledger. Grouping is by booking (application), and revenue-by-month sums every paid payment. Test: `financial-lists-test.php`.

### 7. Platform-charge recovery credited gross instead of organizer net — P1
- **Evidence:** `backfill_stripe_payment_meta()` stored `_lnkup_stripe_destination_amount = amount − application_fee` even for platform charges, where the application fee is 0, and `record_connect_settlement()` preferred that value. Recovered platform charges were therefore credited at full gross, including LNKup's fee.
- **Fix:** destination amount is only written and read for real destination charges. Test: "stale destination amount on a platform charge is ignored".

### 8. Settlement re-priced past charges with today's fee settings — P1
- **Evidence:** `settle_treasury()` called `calculate_for_payment()` from current settings, and `persist_payment_settlement_meta('settle')` then overwrote `_lnkup_application_fee_cents` / `_lnkup_fee_breakdown`, replacing the fee actually sent to Stripe.
- **Fix:** `LNKup_Fee_Engine::settlement_breakdown()` uses the charge-time snapshot and overlays Stripe's actual application fee for destination charges. The settle step no longer overwrites the snapshot (the recalculated value is kept separately as `_lnkup_fee_breakdown_settled`).

### 9. Cancelled destination cashouts never released "Processing" — P1
- **Evidence:** `cancel()` restored a treasury "available" credit, but a destination cashout had been booked as a Connect debit plus `processing_balance`. Treasury restores also decremented processing they never incremented.
- **Fix:** cancel uses the same keyed restore as failures, and processing is only released in destination mode. Restore rows say "cancelled" or "failed" correctly.

### 10. Missing webhook coverage, slow retries — P1
- **Evidence:** no handlers for `checkout.session.async_payment_succeeded` / `async_payment_failed`, `payout.updated` / `payout.canceled` or `charge.dispute.closed`. Failed events were retried only by the daily cron.
- **Fix:** handlers added; `lnkup_financial_hourly` (DB-locked) retries webhooks and syncs recent unsettled or unpaid payments; Checkout-Session payments without a stored PaymentIntent are resolved from the session (GET).

### 11. Figures shown as $0 when unknown; Cash Out and "Payouts active" misleading — P2
- **Evidence:**
  - When the Stripe Connect balance could not be read, Available and Pending showed $0.
  - "Paid out" and "Lifetime paid" counted only LNKup cashouts, while destination organizers are paid by Stripe's automatic payouts.
  - The status line read "Settlement method: Direct organizer payouts".
  - The audit list made one Stripe call per row.
- **Fix:**
  - **Money contract v1:** integer cents, a source and `synced_at` per figure, and `unavailable` (null) instead of $0. In destination mode, Paid out comes from the connected account's payouts; LNKup cashouts are reported separately.
  - **Payout state** mirrors the server's cashout rule, and every disabled Cash Out explains why.
  - **No per-row Stripe calls:** list rows no longer call Stripe (live status only on the single-payment audit).
- **See:** `MONEY_DEFINITIONS.md`.

### 12. Audit log dropped context — P2
`LNKup_Financial_Audit::log()` stored only `amount` and `metadata`; top-level context (fee breakdowns, sources, reasons) was discarded. It is now kept as metadata.

## The brief's suspected causes (A–J)

| Suspect | Finding |
| --- | --- |
| A. Missing webhook processing | Partly: async Checkout, payout cancel/update and dispute-closed were unhandled; retries were daily (#10). |
| B. Incorrect status mapping | Yes: late `payment_failed` overwrote `paid` (#5). |
| C. Incorrect organizer ownership | No defect found in code; payments are scoped by `_lnkup_organizer_user_id`, and the diagnostics flag destination mismatches per payment in production. |
| D. Incorrect database query | Yes: `entry_type` column too narrow for `connect_settlement` (#1). |
| E. Missing pagination | Yes: Transactions LIMIT 25 / ledger LIMIT 50 with no paging (#6). |
| F. Incorrect sorting | Yes: by invoice creation instead of paid date (#6). |
| G. Duplicate payment storage paths | Yes: concurrent reconcile on GET + no unique keys could double-settle (#2, #3). |
| H. Wrong Stripe account | Not shown in code. The diagnostics compare each PaymentIntent's `transfer_data.destination` with the stored destination (`destination_mismatch`). |
| I. Cache issues | No page/object cache involved in money reads. Transient "locks" were the race (#2). Connect payouts are cached 5 minutes server-side. |
| J. Incomplete payment confirmation | Yes: paid-but-unsettled never recovered (#4); Checkout Session payments without a stored intent could not reconcile (#10). |

## The four "+$230 Vendor payment settlement" rows (Oct 2 ×2, Oct 9 ×2)

"Vendor payment settlement" is written only by `credit_from_payment()` for **platform-settled (treasury) charges**, one row per payment. What the rows mean depends on the production data:

- **Two different payment IDs on the same day:** separate bookings, not duplicates. The diagnostics' `same_amount_same_day` verdict is `distinct_payments`.
- **The same payment ID twice:** a duplicate from the concurrent-reconcile race (#2, #3). The repair tool offsets the second row with an equal debit; nothing is deleted.
- **Treasury credits on an organizer who is now Connect-ready:** these balances were meant to migrate into `connect_settlement` (LNKup settled) but could not be recorded (#1). If they sat in escrow (`pending_balance`), the migration skips them, and in destination mode the Pending tile shows Stripe's pending instead, so they were invisible on every tile. The new `platform_held` metric shows them.

## Production confirmation (pending)

Run `GET /wp-json/lnkup/v1/admin/financial/reconcile?organizer_id={id}` (and `…&stripe=1`) on thelnkup.com after deploy. It is read-only and never writes or calls Stripe write endpoints. The numbers go into `RECONCILIATION_<date>.md`. Until then, which of #1–#11 caused each production symptom is **unverified**.


## Phase 1 Stripe audit (12.23.0): P0 fixes

An in-depth audit of checkout, refunds and chargebacks, and Connect cashouts found further P0 defects. Each is fixed and covered by a wp-env test (`financial-checkout-test.php`, `financial-refund-dispute-test.php`, `financial-cashout-test.php`). Production impact is measured by the new read-only issue codes, and reported in the next reconciliation report.

### Checkout and confirmation

| # | Defect | Fix |
| --- | --- | --- |
| 13 | Wallet credit subtracted twice: the fee engine took it out of `_lnkup_total_amount`, and `stored_amount_due()` took it out again, so vendors were undercharged by the credit (or got the booth for credit alone). | `refresh_pending_fees` keeps the total gross; credit is subtracted once. Issue code `credit_undercharged`. |
| 14 | Deposit or first installment marked the whole booking paid (packet unlocked, balance never billed, organizer credited in full). | New `partially_paid` status. Cumulative `_lnkup_amount_paid_cents`; next amount due set to the balance. Settlement only when fully paid. Replayed partial charges are ignored. |
| 15 | Refunded, disputed or voided bookings could be confirmed back to `paid` (packet re-unlocked, organizer re-credited). | `LNKup_Payments::is_collectible()` gate in finalize, `mark_paid` and `mark_processing`. Charges on closed or voided payments are recorded for review, never applied. |
| 16 | A second successful charge for one booking was silently absorbed and overwrote the first charge's references. | One live charge per booking: open PaymentIntents are reused or cancelled, and open sessions expired, before a new one is created. A succeeded or processing charge blocks a new one. Extra charges are recorded in `_lnkup_extra_charges` for an admin-approved refund. |
| 17 | The hosted-checkout route charged processing, refunded or voided bookings again. | `create_checkout` requires a payable status for every mode. |
| 18 | Webhooks finalized without checking the amount. | The amount stamped when the PaymentIntent or session was created must be covered, in USD. A mismatch sets `_lnkup_needs_review = amount_mismatch` and the booking is not finalized. |
| 19 | Voided payments kept a live payment link. | Voiding cancels the open PaymentIntent and expires the session. |
| 20 | One card decline made the booking unpayable. | A declined (non-voided) payment reopens on retry. |

### Refunds and chargebacks

| # | Defect | Fix |
| --- | --- | --- |
| 21 | Refunds and credit refunds never reversed the organizer's share. | `LNKup_Organizer_Wallet::reverse_for_refund()`, called from `sync_payment_refund_status()` for both the approval and the `charge.refunded` paths. Proportional and keyed by the cumulative amount returned. |
| 22 | Lost chargebacks left the organizer with the money. | `charge.dispute.closed` lost reverses the share and sets `chargeback_lost`; won reinstates it. Destination organizers who still hold the money are flagged `needs_recovery`. |
| 23 | Organizers could resolve bank chargebacks, including granting credit on top of the chargeback. | Chargebacks are admin-only and can never be resolved with credit. Credit outcomes on ordinary disputes are recorded as credit refunds. |
| 24 | Reserves on refunded or disputed payments were paid out. | They are marked `used` instead. |

### Connect cashouts and payouts

| # | Defect | Fix |
| --- | --- | --- |
| 25 | Recovery formula wrong both ways. It subtracted the Connect balance, and the claim counted the current cashout's own debit, so platform-held money could be stranded or transferred twice. | Claim = platform-held credits minus `recovery_transfer` debits (one per transfer ID), refunds and offsets. Cashout debits and payout restores are not counted. |
| 26 | Admin "recover stranded" could double-transfer. | Per-organizer lock plus a deterministic idempotency key. |
| 27 | "Pay out fees" could sweep organizer money: the owed total skipped many organizers, pending balances and in-flight payouts. | `organizer_owed_breakdown()` sums every wallet: available, pending, platform claim and in-flight treasury cashouts. |
| 28 | A payout already failed or cancelled (balance restored) could still be marked paid. | `mark_paid` accepts only `requested`/`approved`/`processing`. A failed or cancelled payout that later reports paid re-debits once (`payout:{id}:redebit`). Totals update atomically. |
| 29 | Restores used the current Connect state, not how the payout was booked. | The bucket follows `provider`. |
| 30 | Payout webhooks trusted `metadata.payout_id` from any account. | The event must come from the owner's connected account, for a Connect payout. |
| 31 | A cashout ran even if its ledger debit failed. | The cashout stops and the payout row is marked failed. |

Also fixed: the admin "treasury platform fees" figure now counts one row per payment. Production had 17–676 duplicate rows per payment from the repeated settlement.

## Phase 2 Stripe audit (12.23.1): P1/P2 fixes and fee model

Covered by `financial-phase2-test.php`; all 13 financial suites pass.

### Fee model (new bookings only; announce before relying on it)

- **Destination charges:** the application fee is now everything except the organizer's share. The share is booth − commission + tax − any organizer-funded promo. LNKup therefore keeps the vendor-paid processing fee, because it pays Stripe's fee on these charges. Before, the application fee was only the platform fee, so the organizer received the processing fee and LNKup paid Stripe out of its own fee. Deposits and installments get a proportional share. Setting `processing_fee_payee = organizer` restores the old rule.
- **Promos:** organizer promo codes reduce the organizer's share, and marketplace codes are funded by LNKup. `_lnkup_promo_funded_by` is recorded at redemption.
- **Credit refunds** debit the organizer, like card refunds (#21).

### P1/P2 defects

| # | Defect | Fix |
| --- | --- | --- |
| 32 | Organizer promo codes applied to any organizer's booking when no context was passed. Usage limits could be exceeded by concurrent redemptions. | Context required. Redemption is locked per payment and claims a use atomically. |
| 33 | `fee_payer_mode = organizer` still charged the vendor the checkout fee. | Moved into the organizer commission. LNKup's total fee is unchanged. |
| 34 | Refund cap was applied to what was left, not cumulatively. | Cap applies to the booking total; the rule fee is taken once. Note: the refund-rules table is keyed by situation and has no scope or cap columns, so rules currently never cap a refund (unchanged behaviour). |
| 35 | Refunds above the approval threshold could be approved by the organizer. | `refund_needs_approval` (403) unless the approver is a LNKup admin. |
| 36 | A refund stranded in `processing` (request died mid-call) stayed stuck forever. | The hourly cron requeues it to `pending` after 15 minutes, inside Stripe's 24h idempotency window, so a retry reuses the same Stripe refund. Rows stuck more than 20h are only flagged (`refund_stuck_needs_review`). |
| 37 | Collected revenue counted only `paid`. Pending revenue counted refunded, failed and voided bookings. | `LNKup_Financial::collected_amount()` (see `MONEY_DEFINITIONS.md`). Pending covers only money still due. |
| 38 | Escrowed payments put the whole credit in pending, but escrow released only the held amount, stranding the rest. | Only the held part goes to pending; the rest is available (`credit_unheld`). Full refunds and lost chargebacks cancel escrow. |
| 39 | Dispute resolution could run twice, and always set the payment back to `paid`. | Atomic claim (409 on a second resolve). Restores the pre-dispute status (`paid`, `partially_refunded`). A credit outcome grants only what was not already refunded. |
| 40 | A user could hold two live subscriptions (double billing). Paid plans without a Stripe price activated for free. Late webhooks revived cancelled subscriptions. A no-change update inserted a duplicate row. | Per-user lock and `already_subscribed`; `plan_not_billable`. Cancelled rows are never revived. Upsert by `provider_ref`. |
| 41 | Instant Connect payouts debited the gross amount but paid out the net, leaving an uncollected "fee" on the connected account. | Connect payouts pay out the full debited amount; Stripe charges its instant fee there. Platform-transfer payouts keep the LNKup fee. |
| 42 | Cashout `client_token` replay reported success for a failed cashout. Tokens with JSON-escaped characters never matched. | Token limited to `[A-Za-z0-9_-]`. A replay of a failed or cancelled cashout returns that failure. |
| 43 | A balance in another currency was shown (and cashed out) as USD. | Only the platform currency counts. Others are reported in `other_currency_cents`. |
| 44 | Legacy `LNKup_Payouts::mark_completed` could complete a payout twice. | Atomic status claim. |
| 45 | One Stripe customer ID for test and live mode ("No such customer" after switching). Concurrent checkouts created duplicate customers. | Per-mode `_lnkup_stripe_customer_id_{test,live}`. A legacy ID is adopted only after Stripe confirms it in this mode. Creation is locked, with a deterministic idempotency key. |
| 46 | Fresh installs never stamped the auto-recovery cutoff, so settlement auto-recovery never ran. | Stamped on activation. |

Repair tool additions (dry run first; applied only with approval): `void_duplicate_escrow_holds`, `reverse_refund_share`, `reverse_chargeback_share`, `refund_extra_charge` (Stripe-verified; applying issues one refund keyed `lnkup_extra_refund_{pi}`) and `record_late_payout`. Report-only items: `report_credit_undercharged`, `report_partial_marked_paid`.

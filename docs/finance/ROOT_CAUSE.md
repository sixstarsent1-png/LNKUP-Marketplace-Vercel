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

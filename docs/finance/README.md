# Financial reconciliation (plugin 12.22.0): deliverables

| Deliverable | Where |
| --- | --- |
| A. Root-cause audit | [ROOT_CAUSE.md](ROOT_CAUSE.md) |
| B. Financial architecture map | [ARCHITECTURE.md](ARCHITECTURE.md) |
| C. Reconciliation report | `RECONCILIATION_<date>.md`, written after the read-only production run (see "Remaining actions"). Method: [ROOT_CAUSE.md](ROOT_CAUSE.md#production-confirmation-pending) |
| D. Code changes | "Code changes" below |
| E. Automated tests | "Tests" below |
| F. Organizer verification | [MONEY_DEFINITIONS.md](MONEY_DEFINITIONS.md) (every figure: definition, source, sync time) |
| G. Vendor verification | `vendor_money_contract()`; payments list sorted by paid date, one row per booking; packet unlock unchanged (`mark_paid` → application paid) |
| H. Mobile verification | Shared fixture `scripts/e2e/fixtures/money-contract-v1.json`, checked by the PHP contract test (server emits exactly these keys) and `lnk-up-marketplace-mobile/__tests__/money-contract.test.ts` (mapping) |
| I. Deployment checklist | [DEPLOYMENT_CHECKLIST.md](DEPLOYMENT_CHECKLIST.md), [WEBHOOKS_AND_ENDPOINTS.md](WEBHOOKS_AND_ENDPOINTS.md) |
| J. Remaining actions | "Remaining actions" below |

## Code changes

WordPress plugin `wp-content/plugins/lnkup-marketplace/`:

| File | Change |
| --- | --- |
| `lnkup-marketplace.php` | 12.22.0; stamps `lnkup_fin_auto_recovery_since`; loads the reconciliation admin |
| `includes/financial/class-treasury-tables.php` | `entry_type varchar(32)`, `idempotency_key` UNIQUE, `payment_post_id` index |
| `includes/financial/class-organizer-wallet.php` | Pure `get_balances()`; insert-first keyed ledger writes; atomic balance updates; CAS migration with rollback; net-not-gross recovery; paged, referenced `list_entries()` |
| `includes/financial/class-financial-service.php` | `with_lock()`; locked finalize; `ensure_settled()` + cutoff; Checkout Session intent lookup; pure balance payload; `sync_organizer` / `sync_vendor`; `repair_settle`; Stripe refs stored; money contracts v1 |
| `includes/financial/class-fee-engine.php` | `settlement_breakdown()` (charge-time snapshot + Stripe fee) |
| `includes/financial/class-stripe-connect.php` | Settle keeps the fee snapshot; `payout.updated` / `canceled` |
| `includes/financial/class-stripe-webhooks.php` | Async Checkout events, dispute closed, paid guard on `payment_failed` |
| `includes/financial/class-stripe-client.php` | GET-only `read()` (expand, list filters, connected account) |
| `includes/financial/class-organizer-payouts.php` | Keyed restores; cancel mirrors failure restore; processing released only in destination mode |
| `includes/financial/class-financial-reconciliation.php` (new) | Read-only diagnostics REST + report |
| `includes/financial/class-financial-repair.php` (new) | Dry run / token / apply repair REST |
| `includes/financial/bootstrap.php` | Loads the two new classes |
| `includes/class-financial-rest.php` | No reconcile on GET; `POST /financial/sync`; contract on balances; paged transactions with headers; paged ledger; live audit only per payment |
| `includes/class-financial-cron.php` | `lnkup_financial_hourly` (locked): webhook retry + sync queue + health option |
| `includes/class-financial-audit.php` | Keeps top-level context as metadata |
| `includes/class-payments.php` | `mark_paid()` keeps the first paid date; `get_audit()` has no per-row Stripe call and adds refs/settled fields |
| `includes/class-transactions.php` | Paid-date sort, count, `paid_at` / `amount_cents` |
| `includes/helpers.php` | Group by booking; paid-date sort helpers |
| `includes/class-financial-reports.php` | Revenue by month sums every paid payment |
| `includes/class-financial-render.php`, `public/class-shortcodes.php` | Recent activity and vendor payments sorted and dated by paid date |
| `admin/class-financial-reconciliation-admin.php` (new) | wp-admin Reconciliation panel |
| `public/js/financial.js`, `public/css/financial.css` | Contract-first figures, "Unavailable" not $0, payout state, Cash Out reasons, Sync now, ledger references, paging |

Clients:

- `lnk-up-marketplace-mobile/lib/api/organizer-financial.ts`, `app/(organizer)/money.tsx`: additive contract mapping, Unavailable, Cash Out reason. **Not published (no OTA).**
- `lnk-up-marketplace-ui/lib/api/organizer-financial.ts`: additive contract mapping.

## Tests

| Suite | Result |
| --- | --- |
| `scripts/e2e/run-financial-php.ps1`: 8 wp-env suites (schema, reconciliation, sync, contract, lists, repair, cron, security) | 176 assertions passed, 0 failed |
| Mobile `npx jest` (incl. new `money-contract.test.ts`) | 110 passed (14 suites); `tsc --noEmit` clean |
| Playwright WordPress specs (financial, checkout, vendor, security, Connect), chromium | 69 passed. 5 failed, and those same 5 fail with these changes stashed (pre-existing: Connect refresh JSON, vendor `#financial` shell, vendor payments hub tabs, refunds tab, parallel application submissions). 1 flaky (passed 3/3 on re-run) |
| Next.js `tsc` | No errors in changed files (pre-existing errors elsewhere) |

Not covered locally: live Stripe responses (no keys on wp-env). The Stripe read paths are exercised against production in read-only mode only.

## Remaining actions

**Completed code changes:** everything in "Code changes", locally verified.

**Verified fixes (local evidence):**

- Exactly-once settlement under replay and lock contention.
- Pure GETs.
- `connect_settlement` rows now persist.
- Out-of-order `payment_failed` cannot unpay.
- Paid-date ordering and paging.
- Net-not-gross recovery.
- Fee snapshot.
- Keyed payout restores.
- Contract unavailable-vs-zero.
- Cross-user isolation.
- Repair dry run, token, stale-plan and offset rules.
- Hourly lock.

**Unverified assumptions (need production data):**

- Which defects produced the production symptoms.
- Whether the four $230 rows are four payments or duplicates.
- Whether treasury credits sit in escrow pending.
- The Connect account state and balances.
- Webhook delivery history.

**Required Stripe Dashboard configuration:** add the events listed in [WEBHOOKS_AND_ENDPOINTS.md](WEBHOOKS_AND_ENDPOINTS.md) to the platform and Connect endpoints; confirm both signing secrets.

**Production-only tests (read-only):**

- Run the reconciliation report with Stripe verification for the affected organizer and write `RECONCILIATION_<date>.md`.
- Validate the Money page against it (checklist §7).

**Historical changes awaiting approval:** any repair plan items (duplicate offsets, migration restores, Stripe-confirmed settlements). Nothing has been applied to production.

**Release gates still open:**

1. Admin Application Password for read-only diagnostics.
2. Approval to run the deploy workflow.
3. Review of the reconciliation report.
4. Approval of the repair plan.
5. Approval for a mobile OTA.

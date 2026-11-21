# Deployment checklist: financial reconciliation (plugin 12.22.0)

No step here moves money. Do not run test charges, payouts, transfers or refunds in production.

## 1. Before deploy

- [ ] Back up the production database. At minimum: `wp_lnkup_organizer_wallets`, `wp_lnkup_organizer_wallet_entries`, `wp_lnkup_organizer_payouts`, `wp_lnkup_webhook_events`, `wp_lnkup_transactions`, `wp_lnkup_financial_audit`, `wp_postmeta` rows for `lnkup_payment`.
- [ ] Note the current plugin version (12.21.0) and the git commit, for rollback.
- [ ] Confirm an administrator account exists for wp-admin → LNKup → Reconciliation (`manage_options`).

## 2. Database migration (automatic on first request after deploy)

`lnkup_maybe_upgrade_db()` runs dbDelta because `LNKUP_VERSION` changed:

- `organizer_wallet_entries.entry_type` `varchar(16)` → `varchar(32)` (no data change; fixes the silent `connect_settlement` insert failure).
- New nullable `idempotency_key varchar(96)` with a UNIQUE index. Existing rows stay NULL, so there are no conflicts.
- New `payment_post_id` index.
- Option `lnkup_fin_auto_recovery_since` = deploy time. Automatic recovery only applies to payments paid after this.
- Cron `lnkup_financial_hourly` scheduled.

Verify: wp-admin → LNKup → Reconciliation → Health shows "Ledger column fits connect_settlement: Yes", "Ledger idempotency keys: Enabled", DB version 12.22.0, and a next hourly run.

## 3. Webhooks and WAF

Follow `WEBHOOKS_AND_ENDPOINTS.md`:

- [ ] Platform endpoint has all listed events, including `checkout.session.async_payment_*` and `charge.dispute.closed`.
- [ ] Connect endpoint has `payout.updated` and `payout.canceled`.
- [ ] Both signing secrets are saved in LNKup settings.
- [ ] WAF / security plugin allows anonymous `POST /wp-json/lnkup/v1/stripe-webhook`.
- [ ] WP-Cron runs. If `DISABLE_WP_CRON` is set, a system cron hits `wp-cron.php` every 15 minutes.

## 4. Cache invalidation

- Purge page/CDN cache for `/organizer-dashboard/*` and `/vendor-dashboard/*` so `financial.js?ver=12.22.0` and the new CSS load.
- Exclude `/wp-json/lnkup/v1/financial/*` and `/wp-json/lnkup/v1/admin/*` from any caching (they are per-user).
- No object-cache flush needed; the Connect payout totals transient (`lnkup_connect_payouts_{id}`, 5 min) refreshes on its own.

## 5. Reconciliation dry run (read-only)

1. wp-admin → LNKup → Reconciliation: review organizers ranked by issue count.
2. Open the affected organizer and click **Verify with Stripe (read-only)**.
3. Record the results in `docs/finance/RECONCILIATION_<date>.md`:
   - WP paid vs Stripe succeeded
   - missing ledger
   - duplicates
   - unrecorded migrations
   - Connect balance
   - payouts
   - the four $230 rows' payment IDs and verdict
4. Click **Dry run repair** and review every item and its reason.
5. **Stop. Get written approval** before applying anything.

## 6. Apply approved repairs (only after approval)

- Apply from the same screen: type APPLY with the dry-run token (valid 30 minutes; refused if data changed).
- Re-run the report: issue counts for the repaired items drop to 0, and `repair-runs` lists the run.

## 7. Production validation (no money movement)

- [ ] Organizer Money: Gross collected equals the report's WP paid gross. Available and Pending match the Stripe Connect balance, or show "Unavailable", never a fake $0. Paid out matches Stripe payouts.
- [ ] Newest paid invoice appears first in Revenue, with its paid date.
- [ ] Ledger rows show payment references; "Load more" pages.
- [ ] Cash Out is disabled with a reason when it is not possible.
- [ ] Vendor Payments: the same invoice shows Paid, and its packet is unlocked.
- [ ] Mobile (after an approved OTA or the next build): the Money tab matches web for the same organizer.
- [ ] Health: "Last webhook received" updates after the next real payment; "Webhooks not processed" stays 0.

## 8. Monitoring

- Reconciliation → Health daily for the first week: hourly run present, errors 0, webhooks processed.
- Audit actions to watch in `wp_lnkup_financial_audit`:
  - `settlement_recovered`
  - `historical_settlement_skipped`
  - `payment_failed_ignored`
  - `treasury_migration_rolled_back` (should never appear after the schema fix)
  - `reconciliation_repair_run`
- Stripe Dashboard → Webhooks: failure rate 0.

## 9. Rollback

- Code: redeploy 12.21.0. The new column and index are additive; older code ignores them. `entry_type varchar(32)` is backward compatible.
- Data: repairs are offset rows (debits with `source_type = reconciliation_offset*`) and restored rows keyed `repair:*`, each linked to a `run_id` in the audit log. Reversing one is another audited offset; never delete rows.
- If the hourly job misbehaves: `wp cron event unschedule lnkup_financial_hourly`. Webhooks and client confirms still finalize payments.

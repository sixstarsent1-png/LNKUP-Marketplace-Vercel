# Production reconciliation, 2026-11-21

Source: `GET lnkup/v1/admin/financial/reconcile` on thelnkup.com (plugin 12.22.0), run as administrator #1 with `stripe=1`. Everything here was read-only. Stripe was read with GET requests only; nothing was written in WordPress or Stripe. Stripe IDs are masked.

## Platform summary

| Organizer | Payments | Paid | Collected | Ledger rows | Issues |
| --- | --- | --- | --- | --- | --- |
| Six Stars Entertainment (#537) | 64 | 9 | $2,667.72 | 4 | 7 paid without ledger, 2 duplicate ledger |
| Nasir Jones (#1) | 6 | 4 | $342.79 | 19 | 3 paid without ledger |

- **Schema:** after the upgrade, `entry_type` is `varchar(32)`, idempotency keys are on, and the auto-recovery cutoff is 2026-11-22 04:07:58.
- **Webhooks:** none waiting or failed. The last one arrived on 2026-11-17 04:39, the time of the last payment. Processed: 13 `payment_intent.succeeded`, 3 `checkout.session.completed`, 10 `transfer.created`, 2 `payout.created`, 2 `payout.paid`.
- **Hourly job:** has not run yet. WP-Cron needs site traffic; see the deployment checklist.

## Six Stars Entertainment (#537)

**Connect account:** `acct_…ZiMW`. Charges and payouts are enabled, there's no disabled reason, the payout schedule is **daily**, and **4 requirements are currently due** (none past due).

### Stripe vs WordPress

| | Count | Gross |
| --- | --- | --- |
| WordPress paid payments | 9 | $2,667.72 |
| Stripe succeeded PaymentIntents (organizer metadata) | 9 | $2,667.72 |
| Stripe succeeded but missing in WordPress | 0 | — |

**No paid invoice is missing from WordPress.** Every Stripe charge has a matching paid payment with the same amount.

### Every paid payment

| Payment | Paid | Gross | Charge type | Stripe fee | Transfer | Ledger rows | Times settled |
| --- | --- | --- | --- | --- | --- | --- | --- |
| #1352 | 2026-11-17 | $267.83 | destination | $29.99 | yes | **none** | 17 |
| #1347 | 2026-11-17 | $267.83 | destination | $29.99 | yes | **none** | 17 |
| #1344 | 2026-11-17 | $267.83 | destination | $29.99 | yes | **none** | 17 |
| #984 | 2026-10-23 | $422.18 | destination | $41.99 | yes | **none** | 18 |
| #904 | 2026-10-20 | $267.83 | destination | $29.99 | yes | **none** | 20 |
| #983 | 2026-10-18 | $319.28 | destination | $33.99 | yes | **none** | 21 |
| #958 | 2026-10-16 | $319.28 | destination | $33.99 | yes | **none** | 23 |
| #856 | 2026-10-09 | $267.83 | platform | — | no | **2 × $230 credit** | 2 |
| #804 | 2026-10-02 | $267.83 | platform | — | no | **2 × $230 credit** | 2 |

### Where the $2,667.72 is

- **7 destination charges:** $2,132.06 gross − $229.93 LNKup fees = **$1,902.13** transferred to the organizer's Stripe account.
- **Stripe automatic payouts:** 5 payouts on Oct 20, 21, 22 and 27 and Nov 19, all paid, totalling **$1,902.13**. That exactly equals the destination net. **All destination money has already reached the organizer's bank.**
- **Connect balance now:** available $0.00, pending $0.00. This is correct; it was all paid out.
- **2 platform charges (#804, #856):** $535.66 gross is on **LNKup's platform account**. No transfer exists. The organizer's share was recorded as $230 each (**$460**) in the wallet's escrow pending.

### What the old Wallet showed vs the truth

| Card | Old value | Truth | Cause |
| --- | --- | --- | --- |
| Available | $0.00 | $0.00 on Stripe; **$460 owed by LNKup**, held in escrow | Escrowed treasury credits are not "available"; destination Pending showed Stripe's pending, hiding the wallet's |
| Pending | $0.00 | Stripe $0.00; wallet $920 (**should be $460**) | Duplicate credits (below) |
| Collected | $2,667.72 | $2,667.72 | Correct |
| Paid out / Lifetime paid | $0.00 | **$1,902.13** (Stripe automatic payouts) | Only LNKup-initiated cashouts were counted |
| LNKup settled | $0.00 | Ledger mirror should be $1,902.13 | `connect_settlement` inserts failed (column too narrow) |
| Ledger list | 4 rows, Oct 2–9 | Should also list the 7 payments from Oct 16 – Nov 17 | Their ledger rows never saved, so the newest invoices were missing |

### The four +$230 "Vendor payment settlement" rows

| Ledger row | Payment | Written | Verdict |
| --- | --- | --- | --- |
| #19 | #804 | 2026-10-02 23:44:59 | Original credit |
| #20 | #804 | 2026-10-02 23:44:59 (same second) | **Duplicate** |
| #21 | #856 | 2026-10-09 12:53:16 | Original credit |
| #22 | #856 | 2026-10-09 12:53:17 (1 s later) | **Duplicate** |

Each payment received one `payment_intent.succeeded` webhook and was settled twice within one second. That is the unlocked finalize race (ROOT_CAUSE #2/#3), now fixed with the per-payment lock and the unique ledger key. **Overstatement: $460** in the wallet's pending balance.

### Additional exposure found and fixed in 12.22.1 (not yet deployed)

Each destination payment was settled 17–23 times. Settlement inserts a platform-fee row, a reserve hold and an escrow hold with no duplicate check, so those tables may hold many copies per payment.

The dangerous part is reserves. `release_expired()` credits each expired reserve hold to the organizer's **available** balance. For destination charges, LNKup never held that money. Six Stars' destination payments passed the 30-day reserve window on Nov 15 or later.

The production ledger has **no reserve-release rows yet**, so nothing has been credited.

12.22.1:

- stops reserves on destination charges
- credits a reserve release at most once per payment, and never for destination charges
- writes at most one platform-fee or escrow row per payment
- adds read-only side-effect counts to this report

The counts for this report will be added after 12.22.1 is deployed.

12.22.1 also corrects the Cash Out recovery claim. It now counts only money LNKup actually holds (migrations, platform-charge recoveries), never destination mirrors. Otherwise, restoring the 7 missing mirrors would have offered $1,902.13 a second time from LNKup's balance.

### Proposed historical corrections (awaiting approval; not applied)

| # | Change | Effect |
| --- | --- | --- |
| 1 | Offset duplicate row #20 (payment #804) with an equal debit | Wallet pending −$230 |
| 2 | Offset duplicate row #22 (payment #856) with an equal debit | Wallet pending −$230 |
| 3 | Record the missing ledger mirror for each of the 7 destination payments (Stripe-verified) | LNKup settled shows $1,902.13; no change to Available or Cash Out |

After these, the wallet shows **$460** owed by LNKup for #804/#856. That is released by escrow and paid through Cash Out as a Stripe transfer from the platform balance.

### Open questions for you

1. **Is $230 the right share for #804/#856?** Destination charges of the same $267.83 net the organizer $237.84 ($267.83 − $29.99 fee). The two platform charges credited $230 each, $7.84 less per booking. The repair tool does not change this. Tell me which amount is contractually correct.
2. **Escrow release timing for #804/#856.** Escrow holds wait for release (event completion or the release window). If you want the $460 paid now, that is a separate, explicit admin action.

## Nasir Jones (#1)

4 paid payments ($342.79) and 3 without a ledger row. These are test bookings on the administrator account, reported for completeness. The same repair rules apply after review.

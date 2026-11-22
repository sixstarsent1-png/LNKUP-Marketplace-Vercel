# Production reconciliation — 2026-11-22 (plugin 12.24.0, includes 12.23.1 fixes)

Read-only report: `GET /wp-json/lnkup/v1/admin/financial/reconcile?organizer_id={id}&stripe=1`. Repair dry run: `POST /admin/financial/repair` with mode `dry_run`. Nothing was written to production.

## Summary

- **Stripe vs WordPress agree:** every succeeded charge is paid in WordPress, and nothing is missing either way.
  - Six Stars (#537): 9 charges, $2,667.72.
  - Org 1: 4 charges, $342.79.
- **No new damage:** none of the 12.23 issue codes appear, for either organizer:
  - `credit_undercharged`, `partial_marked_paid`, `extra_succeeded_charge`
  - `refund_not_debited`, `repaid_after_refund`, `lost_chargeback`
  - `charge_on_voided_payment`, `charge_amount_mismatch`
- **Webhooks:** every received event was processed, with none failed. The last event arrived on 2026-11-17. A "Connected accounts" destination was added on 2026-11-22; before that, no organizer payout events could arrive.

## Six Stars (#537)

| Finding | Payments | Effect |
| --- | --- | --- |
| Destination charges with no ledger row | 1352, 1347, 1344, 984, 904, 983, 958 | Stripe already sent the organizer share to Six Stars' Connect account and paid it out. The ledger shows none of it. |
| Duplicate settlement credit | 804 (row #20), 856 (row #22) | $230 each. Pending is overstated by $460. |
| Duplicate escrow holds | 804, 856 | Two holds of $230 per payment, so a release would move $460 twice. |
| Duplicate platform-fee rows | all 9 | Reporting only. The totals already count one row per payment. |

## Org 1

| Finding | Payments | Effect |
| --- | --- | --- |
| Destination charges with no ledger row | 187, 195, 182 | Already on the Connect account; the ledger is missing them. |
| `application_fee_mismatch` | 187, 195, 182 | The local `_lnkup_application_fee_cents` is stale; for example, #187 shows $10.55 locally but $6.41 in Stripe. The ledger mirror uses Stripe's destination amount ($7.26 on #187), so money is unaffected. |
| Duplicate platform-fee rows | 187, 195, 182 | Reporting only. |

## Repair dry run (awaiting approval)

| Item | Type | Ledger effect |
| --- | --- | --- |
| 537 `settle:1352`, `:1347`, `:1344`, `:984`, `:904`, `:983`, `:958` | `settle_missing_payment` (Connect mirror rows: record only, no money moves, not cashable) | $0 |
| 537 `dup:20`, `dup:22` | `offset_duplicate_settlement` (offset row added, original kept) | −$460.00 pending |
| 537 `escrow:804`, `escrow:856` | `void_duplicate_escrow_holds` (keep the first hold of each) | $0 |
| 1 `settle:187`, `:195`, `:182` | `settle_missing_payment` (Connect mirrors) | $0 |

Net ledger effect: Six Stars −$460.00, org 1 $0. No Stripe charges, transfers, payouts or refunds. Nothing is deleted. Every change is an audited offset or status row recorded under the run ID.

## Applied (approved 2026-11-22)

**Decisions:**
- The organizer share for #804 and #856 stays at $230 each.
- The remaining $460 escrow (one hold per payment) is released by the normal post-event escrow rules.

**Runs:** all 14 items applied with no errors.
- Six Stars: `run_20261122090049_TYjBVv`, 11 items.
- Org 1: `run_20261122090051_bZHNOJ`, 3 items.

**Verification report (read-only, with Stripe checks), after the runs:**

| | Six Stars (#537) | Org 1 |
| --- | --- | --- |
| WordPress paid vs Stripe succeeded | $2,667.72 = $2,667.72 | $342.79 = $342.79 |
| Connect mirror rows | $1,902.13, matching Stripe's transfers | $30.90 ($7.26 + $8.23 + $15.41) |
| Pending balance | $460.00 (the two real $230 escrowed credits) | $235.00 (unchanged) |
| Escrow holds on #804 and #856 | one `held` and one `void` each | — |

**Issues that remain, and why they're harmless:**
- `paid_missing_ledger` and `duplicate_ledger` are cleared.
- `settled_repeatedly` and `duplicate_platform_fee_rows` are history: past audit entries and fee rows that are kept, not deleted. The totals count one fee row per payment.
- Org 1's `application_fee_mismatch` is the stale local fee meta; the money is correct.
- `duplicate_escrow_holds` still showed because the detector counted voided holds. That is fixed in the next plugin release: only `held` holds count, and the repair plan no longer offers already-voided duplicates.

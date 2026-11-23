# LNKup money definitions (contract v1)

Every organizer and vendor money figure on web and mobile comes from one server-side contract built in `LNKup_Financial_Service`. Clients render it; they never compute balances.

- Organizer: `money` block on `GET lnkup/v1/financial/dashboard?role=organizer`, `GET /financial/organizer-wallet`, `GET /financial/organizer-balances` (`organizer_money_contract()`).
- Vendor: `money` block on `GET /financial/dashboard?role=vendor` (`vendor_money_contract()`).

The block is additive. Legacy fields (`available_balance`, `display_balances`, `money_categories`, …) are unchanged so older app builds keep working.

## Metric shape

```json
{ "cents": 23000, "status": "ok", "source": "stripe_connect", "synced_at": "2026-11-17T15:04:05+00:00" }
```

| Field | Meaning |
| --- | --- |
| `cents` | Integer US cents. `null` when `status` is not `ok`. |
| `status` | `ok`, or `unavailable` when the source could not be read (Stripe unreachable, account not readable). **Unavailable is never shown as $0.** |
| `source` | `wordpress_payments` (paid payment records), `ledger` (organizer wallet ledger), `stripe_connect` (connected account balance), `stripe_connect_payouts` (payouts on the connected account), `lnkup_cashouts` (LNKup payout requests). |
| `synced_at` | ISO time the figure was read from Stripe; `null` for WordPress-sourced figures. |

## Organizer metrics

| Key | UI label | Definition | Source |
| --- | --- | --- | --- |
| `collected` | Collected | What vendors paid, before fees and refunds: the booking total of `paid`, `refunded`, `partially_refunded` and `disputed` payments, plus the paid part of `partially_paid` deposits. Lost chargebacks are excluded. Refunds are their own line. | `wordpress_payments` |
| `platform_fees` | Platform fees | LNKup fees on those payments. | `wordpress_payments` |
| `refunds` | Refunds | Money returned to vendors. | `wordpress_payments` |
| `net_earnings` | Net earnings | `collected − platform_fees − refunds`, floored at 0. | derived |
| `available` | Available | Destination mode: connected-account available balance plus ledger-capped platform recovery (same figure Cash Out uses). Treasury mode: ledger available. | `stripe_connect` / `ledger` |
| `pending` | Pending | Destination: connected-account pending balance (charges still settling at Stripe). Treasury: ledger pending (escrow). | `stripe_connect` / `ledger` |
| `in_transit` | In transit | Destination: Stripe payouts `pending`/`in_transit` to the bank. Treasury: LNKup cashouts processing. | `stripe_connect_payouts` / `ledger` |
| `paid_out` | Paid out | Destination: Stripe payouts with status `paid` on the connected account — includes Stripe's automatic payouts, not just LNKup Cash Out. Treasury: LNKup cashouts marked paid. | `stripe_connect_payouts` / `lnkup_cashouts` |
| `processing` | Processing | LNKup cashouts requested and not yet paid. | `ledger` |
| `lnkup_cashouts_paid` | Lifetime cashouts | LNKup Cash Out requests marked paid. | `ledger` |
| `reserve` | Reserve | Amount held back under `reserve_percent`. | `ledger` |
| `lnkup_settled` | LNKup settled | Ledger mirror of destination charges (`connect_settlement` rows) minus payout debits. An audit figure; money itself sits on the connected account. | `ledger` |
| `platform_held` | Held by LNKup | Treasury available + pending for platform-settled charges not yet moved to the organizer. | `ledger` |

### Payout status (`money.payouts`)

| `state` | Label | When |
| --- | --- | --- |
| `active` | Payouts active | Connect account ready (charges + payouts enabled, no disabled reason) **and** settlement mode is destination. |
| `setup_incomplete` | Finish payout setup | Account exists but is not ready, or ready but not yet used for destination charges. |
| `verifying` | Verifying payout details | Stripe is reviewing submitted details. |
| `action_required` | Payout details needed | Stripe has past-due requirements. |
| `paused` | Payouts paused | Account disabled, rejected or deauthorized. |
| `not_connected` | Set up payouts | No connected account. |
| `manual` | Paid by LNKup | Connect is not configured on the marketplace; LNKup pays organizers from the platform balance. |

`payouts.method` is `connect` (destination charges, money on the organizer's connected account) or `lnkup` (platform-settled balance paid out by LNKup).

`can_cash_out` mirrors the server's cashout rule (`LNKup_Organizer_Payouts::request_cashout_locked`): destination mode needs `state = active`; platform-settled balances can be cashed out unless payouts are `paused`. It additionally requires `available.status = ok` and available ≥ $1.00. Otherwise `cash_out_reason` is `payouts_not_active`, `balance_unavailable` or `no_available_balance`, and the Cash Out button explains why instead of failing.

## Vendor metrics

| Key | Definition |
| --- | --- |
| `paid` | What the vendor paid and kept paid: the total of `paid` and `disputed` payments, the paid part of `partially_paid` deposits, and the total minus the refund on `partially_refunded` payments. `count` is the number of payments contributing. |
| `processing` | Payments Stripe reports as processing (ACH). |
| `outstanding` | `pending` and `failed` payments still owed, plus the unpaid balance of `partially_paid` deposits. |
| `refunded` | `_lnkup_refund_amount` on `refunded` (the total when not recorded) and `partially_refunded` payments. `paid` + `refunded` equals what the vendor has paid. |

## Freshness

- `money.generated_at` is when the server built the contract.
- `money.last_synced_at` is the last time `sync_organizer()` / `sync_vendor()` ran for this user (hourly cron, Cash Out, or `POST /financial/sync`).
- GET requests are pure reads; they never settle payments, migrate balances or create ledger rows.

## Ledger rules behind the figures

- One settlement row per payment, enforced by the unique `idempotency_key` (`payment:{id}:credit` or `payment:{id}:connect_settlement`) and a per-payment MySQL lock around finalize.
- Settlement uses the fee breakdown frozen at checkout (`_lnkup_fee_breakdown`), overlaid with Stripe's actual application fee for destination charges. Today's fee settings never re-price a past charge.
- Destination charges (12.23.1+): `application_fee_amount` = charge − organizer share, where the share = booth − commission + tax − any organizer-funded promo (proportional for deposits). LNKup keeps the processing fee and funds marketplace promos and platform credits. `processing_fee_payee = organizer` restores application fee = platform fee.
- Refunds, credit refunds and lost chargebacks debit the organizer's share proportionally, keyed by the cumulative amount returned (`payment:{id}:refund:{cents}`, `payment:{id}:chargeback`).
- Escrowed payments: only the held amount sits in pending (`payment:{id}:credit`); the rest is available at once (`payment:{id}:credit_unheld`).
- Payout debits and restores are keyed `payout:{id}:debit` / `payout:{id}:restore`, so a payout is debited once and restored at most once.
- Historical corrections are offset rows written by the approval-gated repair tool; nothing is deleted.

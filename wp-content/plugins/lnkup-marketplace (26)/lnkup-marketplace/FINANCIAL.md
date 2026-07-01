# LNKup Financial Platform (v12.1.7 Connect Marketplace)

Stripe-first financial OS with **Marketplace Treasury System** and **Stripe Connect Express** destination charges — organizer wallets as ledger mirrors, application fees on the platform, and treasury fallback when organizers are not onboarded.

## Setup

1. Run `composer install` in this plugin directory for Stripe PHP SDK and Dompdf (optional PDF).
2. Configure Stripe keys in **LNKup → Settings**: publishable key, secret key, webhook secret.
3. Enable **Stripe Connect** in **LNKup → Settings** (country, currency, vendor checkout fee, organizer commission %, payout schedule).
4. Review status in **LNKup → Financial → Stripe Connect**.
5. Point Stripe webhooks to: `https://yoursite.com/wp-json/lnkup/v1/stripe-webhook`

### Stripe Dashboard (Connect)

1. Enable **Connect** → **Express** accounts.
2. Complete platform profile and branding.
3. Register webhook endpoint (URL above) with events:
   - `account.updated`
   - `checkout.session.completed`
   - `payment_intent.succeeded`
   - `payment_intent.payment_failed`
   - `transfer.created`
   - `transfer.failed`
   - `payout.created`
   - `payout.paid`
   - `payout.failed`
   - `charge.refunded`
   - `charge.dispute.created`
4. Set Connect return/refresh URLs to organizer dashboard `#financial` (or override in settings).

### Environment / wp_options

| Key | Required |
|-----|----------|
| `stripe_secret_key` | Yes |
| `stripe_public_key` | Yes |
| `stripe_webhook_secret` | Yes (production) |
| `stripe_connect_enabled` | Yes to activate Connect |
| `connect_platform_country` | e.g. `US` |
| `connect_platform_currency` | e.g. `usd` |
| `vendor_checkout_fee` | Flat fee bundled into vendor booth checkout (default **$5.99**) |
| `organizer_commission_percent` | Percent of booth price from organizer (default **6%**) |
| `connect_manual_fallback` | Treasury when organizer not onboarded |

Use Stripe **test mode** keys and Connect test accounts before live.

## Marketplace fees at checkout (dual take)

Vendors see a **single bundled line** at checkout — **Booth reservation** = organizer booth price + vendor checkout fee. There is no separate "Marketplace fee" row in the checkout UI.

| Component | Payer | Default | Stripe |
|-----------|-------|---------|--------|
| Booth price | Vendor pays organizer portion | Set per opportunity | Destination transfer |
| Vendor checkout fee | Vendor | **$5.99** flat | Part of `application_fee_amount` |
| Organizer commission | Organizer (from booth revenue) | **6%** of booth | Part of `application_fee_amount` |

**Example ($250 booth):**

| Line | Amount |
|------|--------|
| Booth reservation (bundled) | $255.99 |
| Processing (~2.9% + $0.30) | ~$7.72 |
| LNKup take (application fee) | $20.99 ($5.99 + $15.00) |
| Organizer net (before reserve) | $235.00 |

`LNKup_Fee_Engine::calculate()` is the source of truth. `platform_fee` in breakdowns = vendor checkout fee + organizer commission (used for Connect `application_fee_amount`). `organizer_net` = booth − organizer commission − reserve.

## Architecture

All payment operations route through `LNKup_Financial_Service`. Treasury modules live in `includes/financial/`:

| Module | Responsibility |
|--------|----------------|
| `LNKup_Financial_Service` | Facade — checkout, settlement, cashouts |
| `LNKup_Fee_Engine` | Full payment breakdown + `application_fee_amount` alignment |
| `LNKup_Stripe_Connect` | Onboarding, `settlement_mode_for_organizer()`, destination charges, Connect payouts |
| `LNKup_Organizer_Wallet` | Ledger mirror (`connect_settlement` entries) or treasury balances |
| `LNKup_Organizer_Payouts` | Cashout — Connect **Payout** API (destination) or Transfer (treasury path) |
| `LNKup_Stripe_Webhooks` | Idempotent reconciliation for account, transfer, payout, refund events |

### Settlement modes

| Mode | When | Payment | Wallet | Cashout |
|------|------|---------|--------|---------|
| `destination` | Connect active + ready | PI/Checkout with `application_fee_amount` + `transfer_data.destination` | Ledger tracking only | Stripe Connect `payouts` API — **no** platform Transfer |
| `platform_treasury` | Not onboarded / fallback | Platform charge | Credits `available` / `pending` | Manual queue or Transfer when later onboarded |

## Settlement pipeline

After `finalize_payment()` marks a payment paid:

1. `LNKup_Fee_Engine::calculate_for_payment()` — full breakdown persisted on payment meta
2. `LNKup_Stripe_Connect::persist_payment_settlement_meta()` — `_lnkup_settlement_mode`, fee breakdown
3. `LNKup_Platform_Fees::record()`
4. `LNKup_Reserves::maybe_hold()`
5. `LNKup_Organizer_Wallet::credit_from_payment()` — branches on settlement mode
6. Timeline, activity, audit, transaction `settlement_mode` columns

## REST (Connect)

- `GET /financial/connect/status` — requirements, badges, `settlement_mode`
- `POST /financial/connect/onboarding-link`
- `POST /financial/connect/refresh` — sync from Stripe
- `POST /financial/connect/deauthorize` — disable local Connect row (audit only)
- `GET /financial/organizer-wallet`, `POST /financial/organizer-wallet/cashout`
- `GET /financial/payments/{id}/audit` — booth fee, Stripe PI, ledger settlement debug
- `POST /financial/payments/{id}/reconcile` — idempotent finalize/backfill when webhooks missed

Organizer dashboard handles `?connect=return` and `?connect=refresh` via `financial.js`.

### Destination mode UI (v12.1.4)

When `settlement_mode === 'destination'` and Connect is active:

- Balance hero shows **live Stripe Connect balance** (available + pending) as primary figures.
- **Settled in LNKup** shows the sum of `connect_settlement` ledger entries (reporting mirror).
- Vendor revenue rows expand to a **fee audit** (booth meta, application fee, destination gross, ledger flag).
- **Reconcile** on a paid payment backfills Stripe PI meta and creates missing ledger rows.

### FAQ: Platform “Gross transfer volume” is $0

In **destination charge** mode, funds move via `transfer_data` on the PaymentIntent — not separate Transfer API objects. Platform dashboard “Gross transfer volume” may stay **0** while application fees and payment volume still accrue. This is expected.

## Database (v12.1)

Extended tables via `LNKup_Financial_Tables::create_tables()` on version bump:

- `lnkup_connected_accounts` — `details_submitted`, `requirements_due`, `requirements_past_due`, `disabled_reason`, `last_synced_at`
- `lnkup_transactions` — `settlement_mode`, Connect IDs, `gross_amount`, `stripe_fee`, `organizer_net`, `reserve_hold`
- `lnkup_organizer_wallet_entries.entry_type` — includes `connect_settlement`

## Production checklist

1. Run DB migration on staging (activate plugin or bump to 12.1.0).
2. Configure Connect settings in admin.
3. Register webhooks; verify delivery in Stripe Dashboard.
4. Onboard test organizer Express account end-to-end.
5. Vendor test payment — confirm application fee on platform balance, net on connected account.
6. Verify **no** platform `Transfer` on cashout when destination mode.
7. Test fallback organizer (no Connect) — payment succeeds, `pending_manual` wallet path.
8. Test refund on destination charge.
9. Load organizer/vendor financial dashboards — no REST 500s.
10. Promote to live keys only after checklist passes.

## Backward compatibility

`lnkup_payment` CPT remains the checkout source of truth. Existing financial REST routes preserved. Legacy `lnkup_payouts` coexists with `lnkup_organizer_payouts`.

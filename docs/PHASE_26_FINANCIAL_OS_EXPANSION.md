# Phase 26 — Financial OS Expansion

WordPress Financial OS remains the system of record.  
Apps (`lnk-up-marketplace-ui`, `lnk-up-marketplace-mobile`) stay **read-only wallet + checkout handoff**.

**Status:** Built (executed)  
**Depends on:** Financial OS Connect baseline, Phase 22 honesty gates  
**Roadmap:** [SYSTEM_OVERVIEW.md §14.2](SYSTEM_OVERVIEW.md#142-app-roadmap-phases-126) · Queue: [PHASES_22_26_ROADMAP.md](PHASES_22_26_ROADMAP.md)  
**Release checklist:** [PHASE_26_RELEASE_CHECKLIST.md](PHASE_26_RELEASE_CHECKLIST.md)  
**Financial reference:** [FINANCIAL.md](../wp-content/plugins/lnkup-marketplace/FINANCIAL.md)

---

## Goal

Promote documented Financial OS stubs to production-capable platform features: ACH live path, Stripe Billing subscriptions, Apple/Google Pay operator flags, webhook idempotency evidence, and app/mobile handoff-only pay.

---

## Non-goals

- Embedded Stripe Payment Element / Payment Sheet in Expo or Next.js as system of record
- Changing Connect settlement model without a WP migration plan
- Attendee ticketing / WooCommerce

---

## Deliverables

### 1) Admin settings (LNKup → Settings)

| Flag | Behavior |
|------|----------|
| `ach_enabled` | Allow `us_bank_account` on PaymentIntents; exclude when off |
| `stripe_billing_enabled` | Real Stripe subscriptions when plan has `stripe_price_id` |
| `apple_pay_enabled` | Operator flag (Dashboard / Payment Element domains) |
| `google_pay_enabled` | Operator flag (Dashboard / Payment Element domains) |

Sanitize + UI checkboxes ship with Connect / payment settings.

### 2) ACH live path

- `LNKup_Stripe_Provider` keeps `automatic_payment_methods`; excludes `us_bank_account` when ACH off on **PaymentIntents and Checkout Sessions**; sets bank verification options when on
- `payment_intent.processing` → `LNKup_Financial_Service::mark_payment_processing()` — **does not** unlock packets / mark application paid
- `checkout.session.completed` → finalize only if `payment_status=paid`; otherwise mark processing and wait for `payment_intent.succeeded` (mobile redirect / Session handoff)
- `payment_intent.succeeded` → existing finalize path
- Reconcile treats `processing` as incomplete (409), not paid
- Fee Engine ACH estimate (`0.8%` capped at `$5`) when `payment_method` is ACH and flag on
- Webhook signing secret present ⇒ no `test_mode` unsigned bypass

### 3) Stripe Billing

- `LNKup_Subscriptions::subscribe()` creates Stripe Subscriptions via `LNKup_Stripe_Client` when flag + `stripe_price_id`
- Cancel cancels remote `sub_*` then local row
- Webhooks `customer.subscription.*` sync local status via `sync_from_stripe_event()`
- Without price id / flag off → simulated (unchanged operator safety)

### 4) Webhook idempotency evidence

**LNKup → Stripe Connect** admin page shows:

- 7-day processed / pending / failed counts
- Recent event table (`event_id`, type, status, attempts)
- Required events list includes `payment_intent.processing` + subscription events

Duplicate Stripe deliveries still return `200` + `duplicate:true` when already processed.

### 5) App / mobile handoff

- Expo `payBoothFee` (native + web) opens `/financial/checkout/{id}/redirect-url` via browser — no Payment Sheet as SOR
- Wallet / pass already treat `browser` return as awaiting webhook reconcile

### 6) Verify

```bash
# WordPress + docs contract
node scripts/phase26-verify.mjs

# Mobile handoff paths
cd lnk-up-marketplace-mobile && npm run phase26:verify
```

---

## Operator enablement

1. Stripe Dashboard: enable ACH debit + Apple/Google Pay domains as needed.
2. LNKup → Settings: flip matching flags.
3. For Billing: set `stripe_price_id` on subscription plan rows; enable `stripe_billing_enabled`.
4. Confirm webhook destination includes processing + subscription events.
5. Review **Stripe Connect** admin for webhook health / recent events.

---

## Next

Phases 22–26 complete. Further financial depth is product-driven (insurance/financing catalogs, BNPL, etc. remain placeholders).

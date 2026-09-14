# Phase 26 — Release Checklist

**Doc:** [PHASE_26_FINANCIAL_OS_EXPANSION.md](PHASE_26_FINANCIAL_OS_EXPANSION.md)

## Pre-release

- [ ] Deploy WordPress plugin with Phase 26 Financial OS changes
- [ ] Stripe webhook endpoint includes `payment_intent.processing`, `customer.subscription.created|updated|deleted`
- [ ] LNKup → Settings: ACH / Billing / Apple Pay / Google Pay flags reviewed (default off)
- [ ] Stripe Dashboard: ACH + wallet methods enabled only when intentionally launching
- [ ] Subscription plans have `stripe_price_id` before turning on Billing

## Smoke

- [ ] Card checkout still finalizes on `payment_intent.succeeded` and unlocks packet
- [ ] With ACH on: bank payment stays `processing` until succeeded (packet stays locked)
- [ ] Checkout Session / mobile redirect: `checkout.session.completed` with `payment_status!=paid` does **not** unlock; unlock only after `payment_intent.succeeded` or `payment_status=paid`
- [ ] With ACH off: Checkout Session excludes `us_bank_account` (same as PaymentIntent)
- [ ] Webhook with signing secret rejects unsigned payloads even if Test Mode is checked
- [ ] Duplicate webhook delivery returns 200 / does not double-unlock
- [ ] LNKup → Stripe Connect shows recent webhook rows + 7d counts
- [ ] Mobile Wallet / Pass Pay opens browser handoff (not native Payment Sheet)
- [ ] Billing off: subscribe remains simulated; Billing on + price id creates Stripe sub

## Verify scripts

```bash
node scripts/phase26-verify.mjs
cd lnk-up-marketplace-mobile && npm run phase26:verify
```

Session ACH + webhook signature contract snippets are covered by `phase26-verify.mjs` (Session finalize gate, Session exclusions, secret-required verify).

## Sign-off

| Role | Name | Date |
|------|------|------|
| Engineering | | |
| Ops / Finance | | |

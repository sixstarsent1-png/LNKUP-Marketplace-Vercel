# Phase 12 — Financial Read-Only Expansion

Production Next.js app: `lnk-up-marketplace-ui/` (Vercel). WordPress Financial OS remains source of truth.

## Overview

Phase 12 replaces mock `/vendor/wallet`, expands read-only `/organizer/money`, adds payment detail drawer with timeline/receipt links, and wires home financial attention — all via BFF composition of existing `lnkup/v1/financial/*` routes. Checkout is handoff-only via existing `POST /api/vendor/requests/[id]/payment-link`. No Stripe SDK, no financial write operations in Next.js.

## Financial OS boundary

| Allowed in Next.js | Handoff only | Never in Next.js |
|--------------------|--------------|------------------|
| Summary, activity, invoices/receipts lists | Checkout redirect URL | Stripe SDK |
| Credits display (read-only) | WordPress Financial Center links | PaymentIntent creation |
| Payout status text | | Refunds, cashouts, reserves controls |
| Payment timeline (read-only) | | Fee calculation |

User-facing copy only: “Payment required”, “Payouts active”, “Finish payout setup”, “Open Financial Center”.

## Environment variables

| Variable | Required | Notes |
|----------|----------|-------|
| `NEXT_PUBLIC_LNKUP_LIVE_API` | Yes | `true` = BFF → WordPress; `false` = mock fixtures |
| `WORDPRESS_API_URL` | Yes (live) | WordPress REST base |
| `NEXT_PUBLIC_WORDPRESS_SITE_URL` | Yes | Dashboard handoff URLs |
| `SESSION_SECRET` | Yes (live auth) | Encrypts httpOnly session cookie |

## Vendor routes

| Route | Purpose |
|-------|---------|
| `/vendor/wallet` | Live wallet summary, payment-required, activity, invoices/receipts, credits |
| `/vendor/requests` | Payment detail drawer (timeline + continue payment) |
| `/vendor/pass/[id]` | Payment detail drawer when locked |
| `/vendor` | Wallet/credits/receipt attention items |
| `GET /api/vendor/wallet` | Summary + payment-required list |
| `GET /api/vendor/wallet/activity` | Activity feed |
| `GET /api/vendor/wallet/invoices` | Invoices |
| `GET /api/vendor/wallet/receipts` | Receipts |
| `GET /api/vendor/requests/[id]/payment` | Extended with `detail` + timeline |
| `POST /api/vendor/requests/[id]/payment-link` | Unchanged — checkout handoff |

## Organizer routes

| Route | Purpose |
|-------|---------|
| `/organizer/money` | Overview, Activity, Events, Payouts (read-only info) |
| `/organizer/events/[id]` | Payments tab with event money snapshot |
| `/organizer` | Payment-required vendor count attention |
| `GET /api/organizer/money` | Enriched money summary |
| `GET /api/organizer/money/activity` | Activity feed |
| `GET /api/organizer/money/events` | Per-event financial summaries (cap 10) |
| `GET /api/organizer/events/[id]/money` | Single event snapshot |
| `GET /api/organizer/financial` | Phase 4 alias (backward compat) |

## WordPress routes (read-only composition)

| WP Route | Role |
|----------|------|
| `GET /financial/summary?role=vendor` | Vendor wallet summary |
| `GET /financial/dashboard?role=vendor` | Vendor dashboard KPIs |
| `GET /financial/activity?role=vendor` | Vendor activity |
| `GET /financial/invoices` | Vendor invoices |
| `GET /financial/receipts` | Vendor receipts |
| `GET /financial/checkout/{id}/summary` | Checkout breakdown |
| `GET /financial/payments/{id}/timeline` | Payment timeline |
| `GET /financial/organizer-balances` | Organizer balances |
| `GET /financial/dashboard?role=organizer` | Organizer dashboard |
| `GET /financial/activity?role=organizer` | Organizer activity |
| `GET /financial/organizer-wallet/entries` | Activity fallback |
| `GET /financial/events/{id}/financials` | Event money snapshot |

## Status label mapping

Neutral labels via `mapPaymentStatusLabel()` — no Stripe jargon in UI.

## Mock vs live

| Mode | Behavior |
|------|----------|
| Mock | `mockVendorWallet`, `mockVendorActivity`, `mockOrganizerFinancial`, etc. |
| Live | BFF proxies WordPress with session auth |

## Security

- All BFF routes require session auth in live mode
- Analytics metadata: status/type only — never amounts, line items, payment IDs, or Stripe identifiers
- No Stripe SDK imports in `app/` or `lib/` (handoff URL strings OK)

## Analytics events

| Event | Trigger |
|-------|---------|
| `vendor_wallet_viewed` | Wallet page loaded |
| `vendor_payment_detail_viewed` | Payment detail drawer opened |
| `vendor_continue_payment_clicked` | Continue payment in drawer |
| `vendor_receipt_clicked` | Receipt CTA clicked |
| `vendor_invoice_clicked` | Invoice CTA clicked |
| `vendor_credits_viewed` | Credits tab viewed |
| `organizer_money_viewed` | Organizer money page loaded |
| `organizer_event_money_viewed` | Event workspace payments tab |
| `financial_handoff_clicked` | Handoff card CTA |
| `wordpress_financial_center_opened` | Open Financial Center |

## QA checklist

- [ ] `/vendor/wallet` shows summary, payment-required, activity, documents, credits, handoff
- [ ] Payment detail drawer opens from wallet, requests, pass
- [ ] Continue payment uses `payment-link` handoff (no embedded checkout)
- [ ] `/organizer/money` tabs: Overview, Activity, Events, Payouts — no cashout/refund buttons
- [ ] Event workspace Payments tab shows event snapshot
- [ ] `rg stripe lnk-up-marketplace-ui/app lnk-up-marketplace-ui/lib` — handoff strings only
- [ ] Phase 12 Playwright spec green

## Known gaps

- Invoice PDF proxy not implemented — uses WP URL handoff when auth required
- Organizer event money activity filter depends on WP `event_id` query support
- Full WordPress Financial Center not ported to Next.js (by design)

**Next:** [Phase 14C — Wallet Polish](PHASE_14C_WALLET_POLISH.md) · [E2E Marketplace Journey](E2E_MARKETPLACE_JOURNEY.md)

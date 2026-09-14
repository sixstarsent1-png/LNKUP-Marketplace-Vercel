# Phase 14C — Wallet Polish

Production Next.js app: `lnk-up-marketplace-ui/` (Vercel). WordPress Financial OS remains source of truth.

**Status:** Built + two-week stabilization complete  
**Parent narrative:** [E2E_MARKETPLACE_JOURNEY.md](E2E_MARKETPLACE_JOURNEY.md)  
**Depends on:** Phase 12 (financial read-only), Phase 14A (post-pay return + refetch)  
**Related:** [PHASE_12_FINANCIAL_READ_ONLY.md](PHASE_12_FINANCIAL_READ_ONLY.md)
**Execution log:** [PHASE_14_TWO_WEEK_EXECUTION_LOG.md](PHASE_14_TWO_WEEK_EXECUTION_LOG.md)

---

## Goal

Polish **read-only money visibility** so vendors and organizers can understand what happened, open receipts, and connect payments to bookings — without porting Financial Center or adding Stripe to the app.

**Financial OS rule (unchanged):** Read-only composition of WP `/financial/*`; checkout remains handoff-only.

---

## Problem statement

| Today | Impact |
|-------|--------|
| Activity rows show generic labels | Users can’t scan “Booth payment for {event}” |
| Paid activity not tappable | No path from wallet list → payment detail |
| Receipts exist in API but weak deep links | Extra taps to WordPress |
| Organizer request cards lack payment status | Can’t see who paid after approval |
| Two-wallet confusion | App vs WP Financial Center narrative unclear |

---

## Deliverables

### 1. Human-readable activity labels (BFF mapping)

Extend `mapPaymentStatusLabel()` / activity composer in `lib/api/financial*.ts`:

| WP raw type / description | App label |
|---------------------------|-----------|
| `booth_fee`, application payment | “Booth payment — {eventName}” |
| `platform_fee` | “Platform fee” |
| `payout` | “Payout to your bank” |
| `credit_applied` | “Referral credit applied” |
| `refund` | “Refund — {eventName}” |
| Unknown | “Payment activity” + date (never raw Stripe IDs) |

**Rules:**

- Prefer event/vendor names from linked application or payment meta
- Truncate long titles with ellipsis; full title in detail drawer
- No Stripe Connect jargon (“destination charge”, “Express account”)

### 2. Tappable paid activity → payment detail drawer

| Surface | Behavior |
|---------|----------|
| `/vendor/wallet` Activity tab | Paid rows clickable when `paymentId` or `applicationId` present |
| `/organizer/money` Activity tab | Same for collected fees |
| Drawer | Reuse Phase 12 payment detail drawer: timeline, amount, status, receipt link |

**Empty / non-tappable:** Rows without payment linkage show chevron disabled + tooltip “Details in Financial Center”.

### 3. Receipt and invoice deep links

| Source | Target |
|--------|--------|
| Wallet Invoices / Receipts tabs | Row tap → drawer or external receipt URL from WP |
| Payment detail drawer | **View receipt** → WP receipt URL or `GET /financial/receipts/{id}` handoff |
| Pass page (paid) | **View receipt** links to wallet with `?highlight={paymentId}` |

**BFF:** Ensure `receipt_url` / `invoice_url` normalized on wallet activity items (nullable).

**Fallback:** “Open Financial Center” handoff card when URL unavailable.

### 4. Organizer per-vendor payment status on request cards

| Card state | Chip / row |
|------------|------------|
| Pending review | “Pending” |
| Approved, unpaid | “Payment required” + amount |
| Paid | “Paid” + collected amount + date |
| Rejected | “Rejected” |

**Data:** Compose from application status + `GET /financial/events/{id}/financials` or per-application payment summary.

**Surfaces:**

- `/organizer/vendors`
- `/organizer/events/[id]` Requests tab

### 5. Post-pay wallet highlight (with 14A)

When landing on `/vendor/wallet?payment=success&applicationId=`:

- Refetch activity (14A)
- Scroll/highlight matching row
- Success banner: “Receipt added to your wallet”

Optional: `?highlight={paymentId}` for direct row emphasis.

### 6. Single source of truth narrative

| Surface | Copy |
|---------|------|
| Wallet footer | “Full statements and tax documents are in WordPress Financial Center.” |
| Organizer money | Same + link to organizer dashboard financial tab |
| Never | Imply app wallet is authoritative for disputes or tax |

---

## Acceptance criteria

- [ ] Activity feed shows human-readable labels for common WP activity types
- [ ] Vendor can tap paid activity → payment detail drawer with timeline
- [ ] Organizer can tap collected activity → read-only detail
- [ ] Receipt button opens WP URL when available; handoff when not
- [ ] Organizer request cards show payment status (pending / required / paid)
- [ ] Wallet highlight works with 14A `payment=success` return
- [ ] No new financial write routes in Next.js
- [ ] No Stripe SDK in app
- [ ] Mock fixtures include tappable paid rows with receipt URLs
- [ ] Playwright: tap activity → drawer visible (mock)
- [ ] `npx next build` passes

---

## Mock vs live

| Mode | Behavior |
|------|----------|
| `LIVE_API=false` | Fixtures with varied activity types, receipt URLs, organizer payment statuses |
| `LIVE_API=true` | BFF maps live WP activity; null-safe when meta missing |

---

## Out of scope (14C)

- Embedded checkout
- Refunds, cashouts, reserve controls in app
- Full Financial Center port
- Real-time push when payment completes (polling/refetch from 14A is sufficient)
- Editing payout bank accounts

---

## QA checklist

1. Vendor wallet activity shows “Booth payment — {event}” for application payments
2. Tap paid row → drawer with timeline + receipt CTA
3. Receipt opens correct URL (or Financial Center handoff)
4. Organizer vendors list shows Paid vs Payment required
5. After 14A return, wallet highlights new receipt row
6. Generic/unknown WP rows degrade gracefully
7. Organizer money activity uses same label mapping

---

## Files likely touched (implementation reference)

| Layer | Paths |
|-------|-------|
| BFF | `lib/api/financial.ts`, `lib/api/financial-server.ts`, wallet/activity routes, organizer money routes |
| UI | `app/vendor/wallet/page.tsx`, `app/organizer/money/page.tsx`, `app/organizer/vendors/page.tsx`, `components/financial/payment-detail-drawer.tsx` |
| Tests | `tests/e2e/lnkup-app-phase12-financial.spec.ts` (extend), `lnkup-app-phase14c-wallet-polish.spec.ts` (new) |

---

## Implementation snapshot (Jul 2026)

- Vendor and organizer activity rows are now tappable via shared `PaymentActivityList` interaction support.
- Vendor wallet activity click-through opens the payment detail drawer using mapped request/application context.
- Organizer money activity click-through navigates to event workspace/handoff targets when event context is present.
- Activity labeling now prefers human-readable financial copy (`Booth payment`, `Collected vendor payment`, etc.).
- Organizer vendor request cards now include payment context copy (`Payment required`/`Paid` + amount where available).
- New coverage in `tests/e2e/lnkup-app-phase14c-wallet-polish.spec.ts` validates clickable activity and request-card payment context.

### Verification status

- Wallet/activity click-through behavior verified in E2E (vendor + organizer).
- Invoice/receipt card accessibility preserved and verified in E2E.

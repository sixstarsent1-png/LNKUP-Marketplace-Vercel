# App Phase 3 — Approved → Payment → Packet

Next.js app (`lnk-up-marketplace-ui/`) vendor booking bridge after organizer approval.

**Status:** Mostly built  
**WordPress:** System of record — all payments via `LNKup_Financial_Service`  
**Related:** [LIVE_API_INTEGRATION.md](LIVE_API_INTEGRATION.md) · [APP_GAP_ANALYSIS.md](APP_GAP_ANALYSIS.md)

---

## Goal

Complete the path: **Application status → Payment required → Payment handoff → Packet locked/unlocked preview**.

Vendors should clearly understand when they are approved, when payment is required, how to continue payment safely through WordPress, and what event packet/pass details unlock after payment.

**Do not** build embedded Stripe checkout in Next.js.

---

## Financial OS rules

- No Stripe SDK, PaymentIntents, or financial write logic in Next.js or BFF
- Checkout is a **handoff** to WordPress URLs from `LNKup_Financial_Service`
- App may only **read** payment/application state through BFF routes

---

## Deliverables

### 1. Payment-required state on `/vendor/requests`

Request cards support statuses: `approved`, `payment_required`, `paid`, `completed`, plus Phase 1 statuses.

| Status | UI |
|--------|-----|
| `approved` / `payment_required` | Chip “Payment required”, amount/due date if available, CTA “Continue payment”, copy “Your event packet unlocks after payment.” |
| `paid` | Chip “Booked”, CTA “View event pass”, copy “Your packet/pass is ready.” |
| `completed` | Chip “Completed”, CTA “View details” or review placeholder |

Hide amount/due rows when unavailable — no fake payment data.

### 2. Payment handoff BFF

| Route | Method | Purpose |
|-------|--------|---------|
| `/api/vendor/requests/[id]/payment` | GET | Payment summary |
| `/api/vendor/requests/[id]/payment-link` | POST | Checkout redirect URL from WP |

**WordPress sources:** `/financial/checkout/{paymentId}/summary`, `/financial/checkout/{id}/redirect-url`, application payment meta.

**Normalized handoff status:** `ready` | `already_paid` | `not_required` | `unavailable` | `auth_required` | `error`

### 3. Payment drawer/modal

- Explains fees and next steps; does **not** collect card details
- “Continue payment” opens WP checkout URL in same tab or new tab
- Loading, error, and unavailable states

### 4. Event pass / packet preview — `/vendor/pass/[id]`

| Route | Method | WordPress |
|-------|--------|-----------|
| `/api/vendor/requests/[id]/packet` | GET | `GET /packets/pass/{application_id}` |

- **Locked:** preview teaser (event name, date, city) + “Complete payment to unlock”
- **Unlocked:** load-in, booth, parking, contacts, map link when WP provides them
- QA: `?locked=1`, `?error=1`

### 5. Find Events + Vendor Home integration

- Cards respect application state (payment required → Requests; paid → pass)
- Home highlights payment-required and booked next actions

---

## Acceptance criteria

- [x] Payment-required requests show actionable CTA
- [x] Payment handoff BFF exists (no Stripe in Next.js)
- [x] Payment drawer explains without collecting card data
- [x] Pass page shows locked/unlocked states
- [ ] Wallet page remains mock (out of scope for Phase 3)
- [x] Mock/live toggle preserved
- [x] `npx next build` passes

---

## Out of scope

- Embedded Stripe checkout
- Organizer financial cashout
- Full wallet, refunds, reviews, maps editor
- Full WordPress operations dashboard port

**Next:** [App Phase 4 — Organizer Lite](PHASE_4_ORGANIZER_LITE.md)

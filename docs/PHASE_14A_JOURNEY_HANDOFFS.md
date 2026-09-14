# Phase 14A — Journey Handoffs

Production Next.js app: `lnk-up-marketplace-ui/` (Vercel). WordPress Financial OS remains source of truth.

**Status:** Built + two-week stabilization complete  
**Parent narrative:** [E2E_MARKETPLACE_JOURNEY.md](E2E_MARKETPLACE_JOURNEY.md)  
**Depends on:** Phases 3 (payment handoff), 9 (launch/share), 12 (wallet read-only), 13 (notifications)  
**Execution log:** [PHASE_14_TWO_WEEK_EXECUTION_LOG.md](PHASE_14_TWO_WEEK_EXECUTION_LOG.md)  
**Next:** [Phase 14B — Organizer Loop](PHASE_14B_ORGANIZER_LOOP.md)

---

## Goal

Close the **vendor checkout → return to app** gap and improve **organizer share → vendor discovery** targeting. Vendors who pay on Stripe should land back in the app with confirmation, refreshed state, and clear next steps. Organizers should share links that open the specific opportunity, not a generic find feed.

**Financial OS rule (unchanged):** No Stripe SDK in Next.js. Checkout URL creation stays in WordPress `LNKup_Financial_Service`. This phase only changes **return URLs**, **query-param UX**, and **share link shape**.

---

## Problem statement

| Today | Impact |
|-------|--------|
| Stripe `success_url` points to WordPress vendor dashboard | Vendor loses app context; wallet/pass never auto-open |
| No `?payment=success` handling | Stale requests/wallet until manual refresh |
| Share panel emits filter-based find URL | Vendor lands on search results, not the slot |
| Payment drawer copy is generic | Users don’t know they’ll return to the app |

---

## Deliverables

### 1. WordPress checkout return URLs

**WP changes** (plugin — `LNKup_Financial_Service` / checkout redirect builder):

| Input | Behavior |
|-------|----------|
| `return_to=app` query on `POST /financial/checkout/{id}/redirect-url` (or BFF body field) | Build `success_url` / `cancel_url` targeting Next.js app base |
| App base URL | New option or env: `LNKUP_APP_URL` (e.g. `https://lnkup-marketplace-ui.vercel.app`) |
| Success path template | `/vendor/requests?applicationId={id}&payment=success` **or** `/vendor/pass/{applicationId}?payment=success` |
| Cancel path template | `/vendor/requests?applicationId={id}&payment=cancelled` |
| Fallback | If `LNKUP_APP_URL` unset, keep current WP dashboard URLs (no regression) |

**BFF changes:**

| Route | Change |
|-------|--------|
| `POST /api/vendor/requests/[id]/payment-link` | Pass `return_to: 'app'` and `app_base_url` from `NEXT_PUBLIC_APP_URL` (or existing Vercel URL env) to WP redirect-url endpoint |

**Security:**

- Validate `app_base_url` against allowlist (production + preview hostnames)
- Never accept arbitrary open redirects from client without server allowlist
- Signed or opaque `applicationId` in return URL only — no payment secrets in query string

### 2. App routes — `?payment=success` handling

| Route | Query params | UX |
|-------|--------------|-----|
| `/vendor/requests` | `applicationId`, `payment=success` \| `cancelled` \| `processing` | Success banner: “Payment received — your pass is ready”; cancel banner with retry CTA |
| `/vendor/pass/[id]` | `payment=success` | Confirmation hero + “View receipt in wallet” |
| `/vendor/wallet` | `payment=success`, optional `applicationId` | Success banner + highlight matching activity row when available |

**Processing state:**

- On success return, poll `GET /api/vendor/requests/[id]/payment` every 3s for up to 30s while WP webhook settles
- Show “Payment processing…” chip if status still `payment_required` after redirect
- Stop polling when status → `paid` or timeout → “Refresh” CTA

**Copy (user-facing):**

- Before handoff: “You’ll complete payment in secure checkout, then return here for your pass.”
- After success: “You’re booked for {eventName}.”
- After cancel: “Payment wasn’t completed. You can try again anytime.”

### 3. Auto-refetch after return

On mount when `payment=success` or `payment=processing`:

| Data | Action |
|------|--------|
| Requests list | `refetch()` vendor requests query |
| Wallet summary + activity | `refetch()` wallet endpoints |
| Pass packet | `refetch()` if on pass page |
| Notification count | `refetch()` bell badge (payment-required may clear) |
| Home retention cards | Recompose attention items |

After successful refetch, strip or replace query params via `router.replace()` to avoid banner re-show on refresh (preserve `applicationId` only if needed for deep link).

### 4. Deep opportunity links (organizer share)

**Today:** `GET /api/organizer/events/[id]/share` returns filter-based `application_url`.

**Target:**

| Field | Example |
|-------|---------|
| `application_url` (legacy) | Keep for backward compat |
| `opportunity_url` (new) | `/vendor/find?opportunityId={oppId}` or `/vendor/opportunities/{oppId}` if route added |
| `apply_url` (new) | Same + `&apply=1` to auto-open apply modal |

**App behavior:**

- `/vendor/find?opportunityId=` — scroll/highlight matching card, pre-select filters
- `apply=1` — open apply modal for that opportunity when authenticated
- Unauthenticated → sign-in with `?redirect=` preserving opportunity context

**WP changes (optional enhancement):**

- Include `opportunity_id` in publish response and share REST payload
- Document deep link contract in `class-organizer-launch-rest.php`

### 5. Analytics

| Event | Trigger |
|-------|---------|
| `payment_handoff_clicked` | Continue payment in drawer (existing) |
| `payment_return_landed` | Page load with `payment=success\|cancelled\|processing` |
| `payment_return_confirmed` | Poll sees `paid` after return |
| `share_link_copied` | Organizer copies deep opportunity link |
| `deep_opportunity_opened` | Vendor lands with `opportunityId` |

---

## Acceptance criteria

- [ ] WP checkout success URL can target Next.js app when `LNKUP_APP_URL` configured
- [ ] BFF payment-link passes app return intent; allowlist enforced
- [ ] `/vendor/requests` shows success/cancel/processing banners from query params
- [ ] `/vendor/pass/[id]` shows post-pay confirmation when `payment=success`
- [ ] Wallet refetches on payment return; banner shown
- [ ] Polling resolves processing lag within 30s or shows refresh CTA
- [ ] Organizer share panel copies deep opportunity link
- [ ] `/vendor/find` honors `opportunityId` (+ optional `apply=1`)
- [ ] No Stripe SDK added to Next.js `app/` or `lib/`
- [ ] Mock mode: simulate return URLs with `?payment=success` on fixture data
- [ ] Playwright: handoff → return success banner smoke (mock)
- [ ] `npx next build` passes

---

## Mock vs live

| Mode | Behavior |
|------|----------|
| `LIVE_API=false` | Payment-link returns mock Stripe URL; app routes handle `?payment=success` against fixtures |
| `LIVE_API=true` | BFF → WP redirect-url with `return_to=app`; live polling against payment summary |

---

## Out of scope (14A)

- Embedded Stripe checkout (Phase 15 — not recommended)
- Organizer in-app approval (Phase 14B)
- Tappable wallet activity rows (Phase 14C)
- WP-backed launch drafts
- Changing fee engine or Connect settlement model

---

## QA checklist

1. Vendor with `payment_required` → Continue payment → (mock/live) return with `?payment=success` → banner + status `paid`
2. Cancel Stripe → `?payment=cancelled` → retry CTA works
3. Slow webhook → processing state → auto-resolves or refresh CTA
4. Organizer copies share link → vendor opens specific opportunity card
5. Unauthenticated deep link → login → returns to opportunity with context
6. `LNKUP_APP_URL` unset → WP fallback URLs unchanged

---

## Files likely touched (implementation reference)

| Layer | Paths |
|-------|-------|
| WP plugin | `includes/financial/*` checkout redirect, `class-organizer-launch-rest.php` share payload |
| BFF | `app/api/vendor/requests/[id]/payment-link/route.ts`, organizer share route |
| UI | `components/marketplace/payment-detail-drawer.tsx`, `app/vendor/requests/page.tsx`, `app/vendor/pass/[id]/page.tsx`, `app/vendor/wallet/page.tsx`, `app/vendor/find/page.tsx`, organizer event share panel |
| Tests | `tests/e2e/lnkup-app-phase14a-journey-handoffs.spec.ts` (new) |

---

## Implementation snapshot (Jul 2026)

- Checkout handoff now supports app return intent from BFF (`return_to=app`, `app_base_url`, `application_id`).
- WordPress checkout redirect route forwards return args into `LNKup_Financial_Service` and Stripe success/cancel URL generation.
- App return UX is live on vendor requests/wallet/pass with `payment=success|cancelled|processing`.
- Organizer share panel now emits deep opportunity links (`opportunityUrl`, `applyUrl`) while preserving legacy `applicationUrl`.
- Analytics events added and verified in app traffic: `payment_return_landed`, `payment_return_confirmed`, `share_link_copied`, `deep_opportunity_opened`.

### Ops and environment notes

- App-side return base: `NEXT_PUBLIC_APP_URL` (fallback: `VERCEL_URL` when present).
- WP-side canonical app base: `LNKUP_APP_URL` (or plugin setting `app_url`).
- Optional allowlist override: `LNKUP_APP_URL_ALLOWLIST` (comma-separated hostnames) for stricter BFF host validation.
- If app URL is unset or not allowed, checkout continues with safe WordPress dashboard fallback URLs.

### Verification status

- `npx next build` (app) passes.
- `tests/e2e/lnkup-app-phase14a-journey-handoffs.spec.ts` passes.
- Full live Stripe sandbox verification remains an operator step requiring authenticated WP + Stripe test credentials.

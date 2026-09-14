# Phase 10 — Vendor Operations + Event Day Pass

Production Next.js app: `lnk-up-marketplace-ui/` (Vercel). WordPress remains source of truth for operations data.

## Overview

Phase 10 makes vendor event-day operations live by replacing mock `/vendor/operations` with BFF-backed data composed from vendor applications + WordPress pass/operations APIs. The event pass page (`/vendor/pass/[id]`) is the **single detail surface** — no separate `/vendor/operations/[requestId]` route.

## Environment variables

| Variable | Required | Notes |
|----------|----------|-------|
| `NEXT_PUBLIC_LNKUP_LIVE_API` | Yes | `true` = BFF → WordPress; `false` = mock fixtures in hooks/BFF |
| `WORDPRESS_API_URL` | Yes (live) | WordPress REST base |
| `NEXT_PUBLIC_WORDPRESS_SITE_URL` | Yes | Public site + vendor dashboard handoff |
| `SESSION_SECRET` | Yes (live auth) | Encrypts httpOnly session cookie |

## Routes

| Route | Purpose |
|-------|---------|
| `/vendor/operations` | Grouped operations list (payment required, event day, pass ready, upcoming, completed) |
| `/vendor/pass/[applicationId]` | Locked/unlocked event pass — QR, logistics, checklist, support |
| `GET /api/vendor/operations` | Composed operations list |
| `GET /api/vendor/operations/[requestId]` | Single-event ops enrichment (pass page optional) |
| `GET/POST /api/vendor/operations/[requestId]/support` | Support history + submit |
| `GET /api/vendor/pass/[id]/calendar.ics` | ICS download (unlocked + date only) |
| `GET /api/vendor/requests/[id]/packet` | Existing pass BFF (unchanged) |

## WordPress routes (read/write)

| WP Route | Role | Notes |
|----------|------|-------|
| `GET /lnkup/v1/applications/vendor` | Vendor | Source list for BFF composition |
| `GET /lnkup/v1/packets/pass/{id}` | Vendor | Locked/unlocked pass DTO |
| `GET /lnkup/v1/operations/vendor-home?application_id=` | Vendor | Per-app ops summary enrichment |
| `GET /lnkup/v1/operations/applications/{id}/support` | Vendor | Support history |
| `POST /lnkup/v1/operations/support` | Vendor | `{ application_id, category, message, priority }` |

No new WordPress plugin routes were required for Phase 10.

## Locked vs unlocked pass rules

| Surface | Locked | Unlocked |
|---------|--------|----------|
| Venue address | Hidden | Shown |
| Load-in / parking / booth | Hidden | Shown |
| QR / check-in code | Hidden | Shown |
| Directions | Disabled | Enabled when address present |
| Calendar `.ics` | Not generated | Generated when date present |
| Emergency / day-of contacts | Hidden | Shown when WP provides |

## Mock vs live

| Mode | Behavior |
|------|----------|
| Mock (`LIVE_API=false`) | Hooks use `mockVendorOperationsEvents()`, `mockVendorPacketLocked/Unlocked`, client-side ICS |
| Live | BFF proxies WordPress with session auth; parallel enrichment per application |

## Security

- All BFF routes require session auth in live mode
- Pass ownership enforced by WordPress (`vendor_owns_application`)
- Locked pass never renders venue address in UI, ICS, or directions
- Analytics metadata sanitized — no addresses, contacts, or credentials
- Stripe checkout remains WordPress handoff only (Financial OS boundary)

## Analytics events

| Event | Trigger |
|-------|---------|
| `vendor_operations_viewed` | Operations page loaded |
| `vendor_pass_viewed` | Pass page loaded |
| `vendor_locked_pass_viewed` | Locked pass rendered |
| `vendor_unlocked_pass_viewed` | Unlocked pass rendered |
| `vendor_calendar_added` | Calendar download |
| `vendor_directions_clicked` | Directions opened |
| `vendor_support_started` | Support modal opened |
| `vendor_support_submitted` | Support form submitted |
| `vendor_rules_viewed` | Rules section engaged |
| `vendor_loadin_viewed` | Load-in field viewed |
| `wordpress_operations_handoff_clicked` | WordPress ops handoff |

## QA checklist

- [ ] `/vendor/operations` groups events by status with correct CTAs
- [ ] Locked pass shows payment CTA, no venue address
- [ ] Unlocked pass shows QR (or check-in fallback), logistics grid, actions row
- [ ] Calendar download works mock + live; disabled when locked or no date
- [ ] Directions only when unlocked + address
- [ ] Support modal validates, submits, handoffs on WP 404
- [ ] Vendor home shows event-day attention + “View all operations”
- [ ] Request cards route to pass for booked vendors
- [ ] `npx next build` passes in `lnk-up-marketplace-ui`
- [ ] `PLAYWRIGHT_APP_URL=http://localhost:3000 npx playwright test tests/e2e/lnkup-app-phase10-vendor-operations.spec.ts --project=lnkup-app`

## Known gaps

- No multi-event vendor list REST in WordPress — BFF composes from applications
- No organizer ops editor in Next.js (out of scope)
- Support POST handoffs to WordPress when route unavailable

## Related docs

- [PHASE_3_PAYMENT_PACKET.md](PHASE_3_PAYMENT_PACKET.md) — payment + pass foundation
- [SYSTEM_OVERVIEW.md](SYSTEM_OVERVIEW.md) §14.2 — roadmap status

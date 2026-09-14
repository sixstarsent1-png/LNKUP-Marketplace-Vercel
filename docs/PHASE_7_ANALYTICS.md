# Phase 7 — Launch Analytics + Marketplace Optimization

Production Next.js app: `lnk-up-marketplace-ui/` (Vercel). WordPress remains the system of record for business data and Financial OS.

## Overview

Internal launch command center at `/ops/analytics` for funnel visibility, vendor activation, opportunity performance, search/market demand, launch alerts, BFF health, and prelaunch status. **Not** a full admin dashboard.

## Environment variables

| Variable | Required | Notes |
|----------|----------|-------|
| `ENABLE_QA_TOOLS` | Optional | `true` enables `/qa` and ops analytics (with `ENABLE_OPS_ANALYTICS`) |
| `ENABLE_OPS_ANALYTICS` | Optional | `true` enables `/ops/analytics` without `/qa` |
| `NEXT_PUBLIC_LNKUP_LIVE_API` | Yes | `true` = BFF → WordPress; `false` = mock analytics fixtures |
| `WORDPRESS_API_URL` | Yes (live) | WordPress REST base site URL |
| `SESSION_SECRET` | Yes (live) | Session encryption |
| `OPS_WP_USERNAME` | Optional (live ops) | Server-only WP user for admin analytics routes |
| `OPS_WP_APP_PASSWORD` | Optional (live ops) | Application Password for ops user — **never exposed to client** |
| `NEXT_PUBLIC_LNKUP_LAUNCH_DATE` | Optional | Display launch date (default `2026-08-01`) |

## Routes

| Route | Purpose |
|-------|---------|
| `/ops/analytics` | Launch command center UI (gated) |
| `GET /api/ops/analytics/funnel?range=7d` | Marketplace funnel |
| `GET /api/ops/analytics/summary?range=7d` | Vendor activation, opportunities, search, markets, alerts |
| `GET /api/ops/analytics/health` | BFF + WP health probes |
| `GET /api/ops/analytics/prelaunch` | Prelaunch gate status |

All ops API routes return **404** when neither `ENABLE_QA_TOOLS` nor `ENABLE_OPS_ANALYTICS` is `true`.

## Analytics events tracked (Phase 6 + 7 audit)

Events POST to `/api/analytics/track` → WordPress `POST /marketplace/track/event`.

| Event | Instrumentation |
|-------|-----------------|
| `landing_cta_clicked` | `components/marketing/vendors-cta-tracker.tsx` |
| `browse_opportunities_clicked` | vendors CTA, `markets/[slug]/market-page-client.tsx` |
| `login_started` | `app/login/page.tsx` |
| `vendor_onboarding_started` | `app/vendor/onboarding/page.tsx`, `app/vendor/page.tsx` |
| `vendor_onboarding_step_completed` | `components/vendor/vendor-onboarding-stepper.tsx` |
| `profile_completion_clicked` | `app/vendor/page.tsx` |
| `document_upload_clicked` | vendor home, onboarding stepper |
| `save_opportunity_clicked` | `components/marketplace/opportunity-card.tsx` |
| `save_search_clicked` | `components/vendor/save-search-dialog.tsx` |
| `apply_clicked` | `components/marketplace/opportunity-card.tsx` |
| `apply_prelaunch_blocked` | `components/marketplace/apply-modal.tsx` |
| `apply_submitted` | apply modal |
| `payment_handoff_clicked` | `components/vendor/vendor-payment-drawer.tsx` |
| `vendor_pass_viewed` | `app/vendor/pass/[applicationId]/page.tsx` |

Safe metadata only: `opportunityId`, `eventId`, `requestId`, `source`, `city`, `category`, `q`. No credentials or document payloads.

Tracking failures are silent (UI never breaks).

## WordPress routes expected

| WP route | Used for |
|----------|----------|
| `GET /lnkup/v1/marketplace/analytics` | Search insights, top cities, zero-result searches (admin auth) |
| `GET /lnkup/v1/growth/admin/summary` | Active vendor count (admin auth) |
| `GET /lnkup/v1/marketplace/search` | Opportunity list + application counts |
| `GET /lnkup/v1/applications/apply-context/{id}` | Prelaunch gate probe |
| `POST /lnkup/v1/marketplace/track/event` | Client event ingestion |

### Known unavailable / partial metrics (live)

| Metric | Status |
|--------|--------|
| Full 11-step funnel with trends | **Partial** — WP has no platform funnel REST; BFF derives best-effort from analytics totals |
| Vendor stuck-vendor list | **Aggregate only** unless WP adds activation endpoint |
| Range filters (24h/7d) on WP data | WP analytics is 30d fixed; UI range applies to mock + labels |
| Prelaunch settings REST | **Inferred** via apply-context probe + env launch date |

When live data is unavailable, sections show friendly empty/unavailable states — **never fake live numbers**.

## Mock vs live behavior

- `NEXT_PUBLIC_LNKUP_LIVE_API=false`: all ops BFF routes return mock fixtures with `mocked: true`; UI shows **Demo / mock data** banner.
- `NEXT_PUBLIC_LNKUP_LIVE_API=true`: BFF calls WordPress. Missing `OPS_WP_*` credentials → partial data + configuration notice.

## Prelaunch gate note

Prelaunch mode is **not a bug**. Search may work while apply submit is gated until launch date. The prelaunch card explains recommended QA (use WP staging/admin preview for full apply E2E).

## Security

- Ops routes gated by server env flags only.
- No Application Passwords, Stripe secrets, or raw WP errors in UI.
- `OPS_WP_*` used server-side only in BFF adapters.
- No Stripe SDK in Next.js app.

## Manual QA checklist

- [ ] `/ops/analytics` returns 404 when `ENABLE_QA_TOOLS` and `ENABLE_OPS_ANALYTICS` are unset
- [ ] Page loads with `ENABLE_QA_TOOLS=true` or `ENABLE_OPS_ANALYTICS=true`
- [ ] Mock mode (`NEXT_PUBLIC_LNKUP_LIVE_API=false`) shows demo banner and sample funnel
- [ ] Funnel, vendor activation, opportunities, search, markets, alerts, health, prelaunch sections render
- [ ] `?error=1` shows error states with retry
- [ ] Refresh re-fetches all sections
- [ ] `/qa` links to `/ops/analytics`
- [ ] Save search on Find Events fires `save_search_clicked`
- [ ] `npx next build` passes in `lnk-up-marketplace-ui`

## Playwright

```bash
pnpm test:e2e:app -- tests/e2e/lnkup-app-phase7-analytics.spec.ts
```

Set `ENABLE_QA_TOOLS=true` on the dev server for full section assertions (tests skip section checks when route is gated).

## Next phase

Phase 8: Search, Maps + Market Discovery.

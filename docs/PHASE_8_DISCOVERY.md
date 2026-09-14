# Phase 8 — Search, Maps + Market Discovery

Production Next.js app: `lnk-up-marketplace-ui/` (Vercel). WordPress remains source of truth.

## Overview

Phase 8 upgrades Find Events into a LinkedIn Jobs–style discovery surface: advanced filters with URL sync, richer opportunity cards, Leaflet map/list mode, nearby geolocation, enhanced market pages, discovery rails, saved-search alerts, and zero-results UX.

## Environment variables

| Variable | Required | Notes |
|----------|----------|-------|
| `NEXT_PUBLIC_LNKUP_LIVE_API` | Yes | `true` = BFF → WordPress; `false` = mock discovery fixtures |
| `WORDPRESS_API_URL` | Yes (live) | WordPress REST base |
| `NEXT_PUBLIC_WORDPRESS_SITE_URL` | Yes | Public site + organizer links |

No map API token required — uses OpenStreetMap tiles via Leaflet.

## Routes

| Route | Purpose |
|-------|---------|
| `/vendor/find` | Advanced search + filters + map/list toggle |
| `/vendor/map` | Standalone map discovery |
| `/markets/[slug]` | City market landing pages |
| `GET /api/marketplace/search` | Full filter proxy to WP |
| `GET /api/marketplace/map-pins` | Discovery map pins |
| `GET /api/marketplace/sections/closing-soon` | Closing soon rail |
| `GET /api/marketplace/sections/new` | New this week rail |
| `GET /api/marketplace/sections/most-saved` | Mock only (WP unavailable live) |
| `PATCH /api/marketplace/saved-searches/[id]` | Alert frequency updates |

## URL params (`/vendor/find`)

| Param | Description |
|-------|-------------|
| `q` | Search query |
| `city`, `state` | Location filters |
| `radius`, `lat`, `lng` | Nearby search (lat/lng never sent to analytics) |
| `category`, `eventType` | Category / event type |
| `dateFrom`, `dateTo`, `deadlineBefore` | Date filters |
| `feeMin`, `feeMax` | Vendor fee range |
| `attendanceMin`, `attendanceMax` | Attendance range |
| `indoor`, `outdoor`, `verified`, `docsRequired` | `1` when active |
| `sort` | `recommended`, `newest`, `closing_soon`, `event_date`, `fee_low`, `attendance_high` |
| `page` | Pagination |
| `view` | `list` or `map` |

## WordPress routes expected

| WP route | BFF usage |
|----------|-----------|
| `GET /marketplace/search` | All filter params (city, lat/lng/radius, fee, categories, sort, etc.) |
| `GET /marketplace/map-pins` | City/state/bbox pins |
| `GET /marketplace/sections/trending?section=` | closing_soon, recently_added |
| `GET /marketplace/sections/recommended` | Recommended rail |
| `GET /marketplace/saved-searches` | Saved searches CRUD + PATCH |

## Map provider

- **Leaflet** + **react-leaflet** with OpenStreetMap tiles
- Dynamic import (`ssr: false`) to avoid SSR issues
- Unavailable state: "Map view is not available yet" — list view remains default

## Nearby privacy

- Geolocation used only in-browser; lat/lng sent to BFF for search only
- Analytics events never include coordinates
- Denied/unsupported → city search fallback sheet

## Saved search alerts

- Frequencies: none, instant, daily, weekly
- WordPress owns email/push delivery — Next.js only creates/updates preferences
- Run saved search → navigates to `/vendor/find` with encoded filters

## Discovery analytics events

`search_performed`, `filter_changed`, `filter_cleared`, `map_view_opened`, `map_pin_clicked`, `near_me_clicked`, `near_me_allowed`, `near_me_denied`, `market_page_viewed`, `saved_search_created`, `saved_search_opened`, `zero_results_shown`, `recommendation_clicked`

## Mock vs live

- `NEXT_PUBLIC_LNKUP_LIVE_API=false`: mock opportunities, pins, rails, market pages
- Live + missing WP route: unavailable UI, not fabricated numbers

## Known gaps

| Feature | Status |
|---------|--------|
| Most Saved rail | Hidden in live mode (no WP endpoint) |
| Map pins vs search filters | Pins support city/state/bbox only |
| Public market registry REST | Static `markets.ts` + search by city |

## Manual QA checklist

- [ ] `/vendor/find?city=Charlotte&state=NC` applies filters on load
- [ ] Filter drawer applies fee, category, verified filters
- [ ] Clear filters resets URL
- [ ] Map/list toggle works; pins show in mock mode
- [ ] Near me denied shows city fallback
- [ ] Zero-results panel with suggestions
- [ ] `/markets/charlotte` shows hero, count, opportunities
- [ ] Save search with alert frequency
- [ ] Favorites → run saved search navigates to find
- [ ] `npx next build` passes

## Next phase

Phase 9: Organizer Conversion + Event Launch Flow.

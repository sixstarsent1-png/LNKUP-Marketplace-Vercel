# Performance comparisons

Lab measurements with `scripts/perf-baseline.mjs` (Playwright, no CPU/network throttling) on wp-env under Docker for Windows. Raw data: `perf-baseline.json` (Phase 0, before) and `perf-after.json` (after Phase 9, flags on).

**Caveat:** LCP on this machine tracks server response time almost exactly (LCP − TTFB ≈ 100–300 ms everywhere), and TTFB swings between ~1.8 s and ~8 s from run to run because the local site has a large WP-Cron/Action Scheduler backlog. LCP numbers are therefore not comparable across runs; transfer size, request count, DOM size and CLS are.

## Stable metrics (before → after)

| Page | Width | Transfer | Requests | DOM nodes | CLS |
|---|---|---|---|---|---|
| `/` | 390 | 312 KB → 318 KB | 23 → 26 | 858 → 887 | 0.002 → 0.002 |
| `/` | 1440 | 340 KB → 346 KB | 23 → 26 | 858 → 887 | 0.022 → 0.022 |
| Event page | 390 | 144 KB → 170 KB | 21 → 28 | 465 → 499 | 0.001 → 0.001 |
| Event page | 1440 | 502 KB → 286 KB | 27 → 34 | 465 → 499 | 0.001 → 0.001 |
| `/markets/charlotte/` | 390 | 1948 KB → 280 KB | 21 → 29 | 798 → 648 | 0 → 0.001 |
| `/markets/charlotte/` | 1440 | 1953 KB → 304 KB | 23 → 29 | 798 → 648 | 0 → 0.001 |
| `/opportunities/` | 390 | 379 KB → 419 KB | 34 → 40 | 1205 → 1227 | 0 → 0 |
| `/opportunities/` | 1440 | 480 KB → 516 KB | 41 → 47 | 1205 → 1227 | 0.001 → 0.001 |

The event page was measured at `/lnkup_event/…` before and `/events/…` after (same post; the old URL now 301s). Small request increases on every page include the concurrent session's homepage and discovery changes made between the two runs.

- The market page transfers ~85% less after the Phase 2 rebuild on the Design System 5.0 layout (the old template's market partials were removed).
- Layout shift stays at or below 0.022 (good threshold 0.1).
- The attribution script is 3 KB unminified; the organizer toolkit and QR scripts load only on the organizer Marketing view.

## Database queries per page (flags off vs on, same code)

Measured with a temporary local mu-plugin printing `get_num_queries()` (removed afterwards); two warm requests each.

| Page | Flags off | Flags on |
|---|---|---|
| `/` | 167 | 167 |
| Event page | 142–145 | 143 |
| `/markets/charlotte/` | 223–227 | 226–228 |
| `/opportunities/` | 166 | 166 |

The SEO policy, tracked links and organizer marketing add no measurable queries to public pages (index policy decisions are memoized per request; market health and city rows are memoized; sitemaps and diagnostics are cached).

## Not measured

Field Core Web Vitals (needs production traffic), throttled mobile lab runs, and load testing of `/l/` redirects.

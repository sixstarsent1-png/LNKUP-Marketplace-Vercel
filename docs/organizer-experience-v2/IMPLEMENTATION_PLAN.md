# Organizer Experience OS 2.0 — Implementation plan

WordPress stays the system of record. Every stage reuses an existing service; new code is presentation, a small number of guarded REST additions, and one dry-run audit script.

## Stages and files

| Stage | Work | Files |
| --- | --- | --- |
| 0 Audit | Docs, before screenshots, REST baselines | `docs/organizer-experience-v2/*`, `scripts/os-shots.mjs` |
| 1 Design system | Shared `lnkup-os.css` + `lnkup-os.js`; `LNKup_OS_UI` (alias `LNKup_Vendor_UI`); `organizer-os.css`; grouped organizer nav; compact top bar with bell | `includes/class-os-ui.php`, `includes/class-vendor-ui.php`, `public/css/lnkup-os.css`, `public/css/vendor-os.css`, `public/css/organizer-os.css`, `public/js/lnkup-os.js`, `themes/lnkup-theme/inc/dashboard.php` |
| 2 Contracts | `LNKup_Organizer_Presentation`; duplicate-event location fix; dry-run audit; organizer-safe weather | `includes/class-organizer-presentation.php`, `includes/class-organizer-onboarding.php`, `includes/class-weather-service.php`, `scripts/audit-event-locations.php` |
| 3 Home | Command center on `vos-layout` | `public/class-organizer-dashboard-views.php` (`render_home_os`) |
| 4 Events | Portfolio table/grid, filters, New menu, duplicate dialog | `class-organizer-dashboard-views.php`, `class-shortcodes.php` (card grid fix), `public/js/organizer-os.js` |
| 5 Workspace | Header + tabs alias map, Overview, Inventory drawer + capacity guard | `class-shortcodes.php` `render_event_workspace`, `includes/class-organizer-inventory.php` (REST `PATCH /organizer/inventory/{id}`) |
| 6 Applications | Shared table, saved views, review drawer next/prev, roster matrix, safe CSV | `class-organizer-dashboard-views.php`, `organizer-review.js`, `class-vendor-directory.php` |
| 7 Vendors | One workspace with section tabs; decoded categories; explainable matches | `class-organizer-dashboard-views.php`, `find-vendors.js` |
| 8 Money | Primary tabs + More, flow strip, labeled figures, status columns, cash-out drawer | `class-financial-render.php`, `financial.js`, `financial.css` |
| 9 Operations | Lifecycle overview, fraction metrics with denominators | `class-organizer-dashboard-views.php` `render_operations` |
| 10 Booths & Map | Vendor names, assign drawer, bulk create preview, map start screen, publish warnings | `operations.js`, `maps.js`, `includes/class-booths.php` |
| 11 Packets & check-in | Tracking states with denominators, check-in result states | `operations.js`, check-in view |
| 12 Tours | `POST /portfolio/tours/{id}/attach`; stop table; add-existing drawer | `includes/portfolio/class-portfolio-rest.php`, `class-portfolio-tours.php`, `portfolio.js` |
| 13 Marketing & intel | Share/QR/embed in Marketing; labeled projections; weather placement | `class-organizer-marketing.php`, `intelligence.js` |
| 14 Settings | `view=settings` hub | `class-organizer-dashboard-views.php`, `helpers.php` allowlist |
| 15 Expo | Shared tokens + presentation fields | `lnk-up-marketplace-mobile/app/(organizer)/*`, `lib/theme.ts`, `lib/api/types.ts` |
| 16 QA | `organizer-os.spec.ts`, regressions, axe, overflow, 8-width shots, `RESULTS.md` | `tests/e2e/organizer-os.spec.ts`, `docs/organizer-experience-v2/RESULTS.md` |

## REST changes

| Route | Change | Permission |
| --- | --- | --- |
| `POST /portfolio/tours/{id}/attach` | New. Body `{ event_ids: int[] }` | Tour owner (`LNKup_Portfolio_Tours` ownership) and each event owned by the same organizer |
| `PATCH /organizer/inventory/{opportunity_id}` | New. Edits title, price, total spots, category, status of one booth type with the capacity guard | `organizer_owns_event` on the opportunity's event |
| `GET /organizer/portfolio` | New, read-only. `LNKup_Organizer_Presentation::portfolio_rows()` for Expo and the Next.js BFF | logged-in organizer; scoped to the current user |
| `POST /organizer/events/{id}/duplicate` | Additive: rejects missing city/state unless `keep_location` is true | unchanged |

No migrations. No new tables. The only data-touching script is `scripts/audit-event-locations.php`, which reports and never writes.

## Rollback

Each stage is additive. Reverting the plugin and theme files to the previous release restores the old UI; there is no schema or data to roll back. The attach route only writes the same tour linkage meta that the existing stop-creation flow writes, and every attach is recorded in the tour audit log so it can be reversed with the existing `detach`.

## Baseline (before)

From `screenshots/before/rest.json` (local WordPress, seeded `e2e_org_a`, workspace event 968, 1440px first load). "Settle" is navigation start to network idle plus skeletons gone; REST counts only `/wp-json/` calls made by the page after load.

| Screen | Settle (ms) | REST calls | Slowest call (ms) |
| --- | --- | --- | --- |
| Home | 5,169 | 1 | 2,462 |
| Events | 2,531 | 0 | — |
| Money | 5,304 | 7 | 1,664 (8,278 summed) |
| Vendors | 8,263 | 3 | 2,202 |
| Marketing | 14,915 | 2 | 5,516 |
| Tours | 12,311 | 1 | 6,771 |
| Event operations | 9,175 | 5 | 2,500 |

Page-level horizontal overflow was 0 on every route at 1440 and 390 (`overflow.json`); the Events layout defect is a squeezed *inner* column, not page overflow (see `screenshots/before/events-1440.png`).

Targets for 2.0: Home and Events add no new REST calls (server-rendered from the contracts); Money keeps its call count or lower.

# Organizer Experience OS 2.0: results

Scope: the authenticated organizer experience on WordPress (system of record), on the same design layer as Vendor Experience OS 2.0, plus Expo organizer screens consuming the new presentation contracts. Ships as **lnkup-marketplace 12.38.0**.

Companion docs: [AUDIT.md](AUDIT.md), [ROUTE_MAP.md](ROUTE_MAP.md), [COMPETITIVE_RESEARCH.md](COMPETITIVE_RESEARCH.md), [DESIGN_SYSTEM.md](DESIGN_SYSTEM.md), [DATA_INTEGRITY.md](DATA_INTEGRITY.md), [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md).

- Before screenshots: `screenshots/before/{screen}-{width}.png` for 24 organizer screens at 1440 and 390, with `overflow.json` and `rest.json` (REST call timings).
- After screenshots: `screenshots/after/{screen}-{width}.png` for the same screens at 1920, 1440, 1280, 1024, 768, 430, 390, and 360, with `overflow.json` and `rest.json`.

## Before and after by screen

| Screen | Before | After | Evidence |
|---|---|---|---|
| Navigation and shell | Flat list, no organizer bell, long breadcrumb | Grouped nav (Workspace, Vendor Management, Business, Organization), Help and Notifications as secondary, Tasks and Referrals under More, collapse toggle, organizer bell, truncated canonical breadcrumb | `home-1440.png` |
| Home | Launch checklist and widget grid fed by one slow widgets call | Command center: six metrics that link to their filtered views and equal the portfolio contract, next-event spotlight, "Needs attention" action center, portfolio table, recent applications, money card, recruitment by category, activity, quick actions, compact onboarding | `home-1440.png`, `home-390.png`; `organizer-os.spec.ts` metrics test |
| Events | Card grid squeezed by a two-column rule on hidden media | Table or grid (remembered), publication / chronology / market filters, "Showing X of Y", one New menu, Duplicate picker | `events-1440.png`, `events-390.png` |
| Duplicate event | Prefilled source city; the copy kept the source city, coordinates and market | City and state required unless "Keep the same location" is checked; venue fields cleared when the location changes | REST test in `organizer-os.spec.ts` (Greensboro copy reads "Greensboro, NC") |
| Event workspace | Long tab strip with legacy names | Header with identity, switcher, overflow menu and primary action. Tabs: Overview, Inventory, Applications, Confirmed vendors, Money, Operations, Check-in, Marketing, Settings (`slots`, `requests`, `financials` still work) | `event-overview-1440.png` |
| Overview | Separate stat cards | Funnel, capacity bar (confirmed / held / open), event money (gross), readiness, deadlines, weather panel | `event-overview-1440.png` |
| Inventory | Inline slot cards | Booth-type table with an edit drawer (name, price, total spaces) that refuses capacity below held + confirmed, add drawer, preview link | `event-inventory-1440.png`; inventory test |
| Applications | Card list | Shared table with selection, safe bulk actions with an open-space check, and a review drawer with previous / next. "Ask for info" and decisions are in the drawer. `?app=ID` deep-links | `applications-1440.png`; drawer and info-request specs |
| Confirmed vendors | Roster list | Readiness matrix (payment, documents, packet, booth, parking, load-in, pass, check-in) with the responsible party; CSV export escapes formula cells | `event-roster-1440.png` |
| Vendors | Three separate pages | One workspace header with tabs for Discover, Lists, and Invitations; explainable match reasons; multi-select invite drawer; import | `vendors-1440.png`, `lists-1440.png`, `invites-1440.png` |
| Money | Eleven tabs, duplicate hero, Stripe wording | Flow strip (collected, fees, refunds, net, available, pending), seven primary tabs plus More, transactions with separate payment, settlement and payout columns, forecast methodology and projection labels, invoice numbers, plain payout copy | `money-1440.png`, `money-390.png` |
| Operations | Flat KPIs; compliance alerts for unbooked vendors | Lifecycle KPI cards, compliance grouped by owner for booked vendors only, packet alerts only for paid vendors, "Recent activity · refreshes every 15 seconds" | `event-ops-1440.png` |
| Booths and map | Booth list only; map editor opened on an empty canvas | Booth summary and table, two-way assign drawer, unassign and swap, bulk create with preview, waiting list. Map Builder start screen (no map / upload / layout), inspector, layout warnings before publish, element table | `event-ops-1440.png`; booth and map tests |
| Check-in | Scan view | Counts, manual pass code, vendor search, recent check-ins; late arrival only after the arrival window | `event-checkin-1440.png`; check-in test |
| Tours and series | Stop cards | Stop table with confirmed, collected gross and outstanding; "Add existing events" drawer | `tours-1440.png`; tour attach test |
| Marketing | Embed code duplicated on Get started | Workspace Marketing tab with tracked apply link (`?apply=`), QR and embed; `?om_tab=embed` deep link; Get started links there | `marketing-1440.png`, `start-1440.png` |
| Intelligence | Raw JSON post-event report | Projections labeled "Projection, not a measured result"; report as a readable list | `intelligence-1440.png` |
| Settings (new) | Nav linked to a missing view | Hub: Getting started progress, public profile preview and completeness, payouts status, notifications, defaults and templates, help, team role matrix from `LNKup_Org_Teams::capability_matrix()` | `settings-1440.png`; settings test |
| Expo | City and state joined locally, no capacity | Events and event detail use `/organizer/portfolio` and `/organizer/events/{id}/summary`: canonical title and place, capacity bar, "Collected (gross)" label | `__tests__/organizer-portfolio.test.ts` |

## Financial reconciliation

- **Home and Money share one contract.** Home's "Collected" metric and the Money flow strip both read `LNKup_Financial_Service::organizer_money_contract()` (net of fees and refunds). Home caches the live balance payload for 2 minutes (`lnkup_oos_balance_{user}`) so it does not call Stripe on every load.
- **Event scope is gross.** Workspace and tour figures come from payment records (`LNKup_Organizer_Presentation::event_money()`) and are labeled gross: what vendors paid, before LNKup fees. Mobile event detail says "Collected (gross)", and Mobile Money's by-event list says "Booth fees vendors paid, before LNKup fees".
- **Transactions** keep payment, settlement and payout as separate columns. Payouts are batched, so a payout column can show one payout across several rows.
- No payments, payouts, refunds or cash-outs were made. The cash-out test only opens the tab and asserts no POST is sent.

## State definitions

- **Capacity:** confirmed = applications `paid` or `completed`; held = `approved` or `payment_required`; open = total − confirmed − held (never below 0). Total 0 means no slot sets a spot count.
- **Inventory guard:** the drawer refuses totals below held + confirmed, and the server (`update_opportunity_pricing`) refuses totals below `LNKup_Opportunity_Inventory::holds()`.
- **Chronology:** upcoming, today, completed, undated. **Publication:** live (published with an open slot) or draft.
- **Weather** (`LNKup_Weather_Service::for_viewer()`): organizers see the forecast, or one of:
  - "Add a date and location to see the forecast." (no date or no location)
  - "Weather isn't available for this event." (not configured or unavailable)
  - "Weather is temporarily unavailable." (lookup error)

  Admins also keep the configuration message. Retry is hidden when weather is not configured.

## REST changes

New routes (all under `lnkup/v1`):

| Route | Permission callback | Ownership |
|---|---|---|
| `GET /organizer/portfolio` | Logged in and organizer, or `user_has_manage_all()` | Rows are only the current user's events |
| `GET /organizer/events/{id}/summary` | `organizer_owns_event( id )` | Same check |
| `POST /portfolio/tours/{id}/attach` | `tour_permission`: organizer and `organizer_owns_tour()`; non-owners get 404 | `attach_events()` also requires each event to be the caller's and not in another tour |
| `POST /operations/events/{id}/booths/{booth_id}/unassign` | `event_cap( 'edit_ops' )` | `LNKup_Booths::unassign()` checks the booth belongs to the event, under a lock |
| `POST /operations/events/{id}/booths/swap` | `event_cap( 'edit_ops' )` | `LNKup_Booths::swap()` checks both booths belong to the event, under locks |

Additive fields: pricing PATCH accepts `title`; duplicate accepts `keep_location`; operations snapshot adds `kpis.lifecycle` and `compliance_groups`; compliance returns `groups`; budget invoices add `number`; tour stops add `confirmed`, `collected_gross`, `outstanding`. No response shape was removed.

Security notes: every new route has a `permission_callback`. Another organizer gets 403 or 404 on the summary, attach and pricing routes (`organizer-os.spec.ts`). CSV exports escape formula cells (`LNKup_Vendor_Directory::csv_safe_cell()`). No Stripe calls were added outside `LNKup_Financial_Service`.

## Migrations

None. `scripts/audit-event-locations.php` is a dry-run report of events whose location may have been inherited by duplication. It changes nothing.

## Verification that ran

- **New spec** `tests/e2e/organizer-os.spec.ts`: **18/18 passed**. Covers:
  - Home metrics equal portfolio sums and link to filtered views.
  - Events filters and grid.
  - Duplicate requires a location and does not inherit it.
  - Inventory guard.
  - Application drawer previous / next.
  - Map Builder start screen.
  - Money flow and cash-out preview.
  - Check-in search and an unknown pass.
  - Settings hub.
  - axe on Home, Events, Settings: no serious or critical findings.
  - No overflow at 1440, 1024, 390 on 7 routes.
  - Tour attach keeps stop IDs and counts.
  - Booth assigned in Operations appears on the vendor's event home.
  - Another organizer is denied.
- **Regression:** every `tests/e2e/organizer-*` spec plus `vendor-os`, `vendor-dashboard-ui`, `vendor-journey`, `vendor-security` (Chromium, 2 workers). 123 passed, 20 skipped. The 10 failures are the same tests that fail on the previous commit (checked by running them against a stash of HEAD). They need `application_review`, `organizer_ops` or `document_review` on, or reviewer team seed data: `organizer-documents`, `organizer-financial` (category count and labels), `organizer-readiness`, `organizer-review`, `organizer-tasks`, `organizer-team` (3), `organizer-waitlist`.
- **Bugs found during regression and fixed:**
  - `location-field.js` silently cancelled a submit made before its lookup finished (Get started save, sign-up and Create Event). It now resolves at once and continues the submit when the place is valid.
  - The application-intel drawer rendered inside the hidden card store, so "View details" opened nothing visible. It is now moved out on load.
  - Settings read setup progress from a different source than Home, so its Getting started card could disappear while Home showed setup unfinished. Both now use one `setup_progress()` helper.
- **Tests updated for intended changes:**
  - Create Event fills the state.
  - Money test opens More for the Ledger tab.
  - Info-request and workspace tests open the application drawer.
  - Vendor Bookings tab is named "Past".
  - Get started uses a longer save timeout on the local stack.
- `php -l` on all 98 changed PHP files: clean. `node --check` on the changed JS.
- Expo: `npx tsc --noEmit` clean; Jest 245/245.

## Not tested

- Expo on a device or simulator.
- Live Stripe: Connect onboarding, payouts, cash-out, refunds.
- Screen-reader walkthroughs (automated axe and keyboard checks only).
- Feature-flagged review, ops and document review workspaces in this environment (flags off locally).

## Known gaps

- Packet editor: section reorder and a template picker are not built.
- Cash out stays a Money tab rather than a standalone drawer from Home.
- Mobile Money activity keeps one combined status per row.
- Map editing and bulk tour creation stay desktop-first on mobile.
- Home still takes several seconds on the local stack (login → Home ≈ 8 s under test load).

## Rollback and deployment

- **Rollback:** revert the release commit, or reinstall the 12.37.0 plugin zip. No schema or data migration to undo; the new routes and fields are additive.
- **Deploy:**
  1. Build the plugin zip at 12.38.0.
  2. Upload to staging.
  3. Hard refresh (asset versions change with `LNKUP_VERSION`).
  4. Run `tests/e2e/organizer-os.spec.ts` against staging.
  5. Optionally run `wp eval-file scripts/audit-event-locations.php` and review inherited locations.
  6. Promote to production.

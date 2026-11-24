# Organizer Experience OS 2.0 — Audit

Scope: every organizer-facing screen rendered by WordPress (`wp-content/plugins/lnkup-marketplace`, `wp-content/themes/lnkup-theme`), plus the Expo organizer tabs. Evidence is file and line references into the current tree, and the before screenshots in `screenshots/before/` (1440 and 390 widths, seeded `e2e_org_a`).

Classification:

- **Keep** — works, only needs the shared design layer.
- **Restyle** — correct data, wrong hierarchy or density.
- **Rebuild** — information architecture or data contract is wrong.
- **Fix** — data integrity or behavior bug.

## Shell and navigation

| Area | Evidence | Class | Finding |
| --- | --- | --- | --- |
| Sidebar nav | `inc/dashboard.php` `lnkup_get_dashboard_nav('organizer')` ~470–522 | Rebuild | 5 primary items, 6 secondary, 7 under "More". Applications, Operations, Intelligence and Invites are hidden under More even though they are daily jobs. No groups. Get started sits in nav permanently, even after completion. |
| Top bar | `lnkup_dashboard_shell_open` ~853–925 | Restyle | Shows `post_title` of the event raw (~827) — repeated date/venue metadata leaks into the breadcrumb. No notifications bell for organizers (only vendor resolves `notification-center`). |
| Body classes | `LNKup_Vendor_UI::is_vendor_surface()` | Rebuild | Organizer screens get no design layer: they render with `organizer-crm.css` (95 KB) + `marketplace-v2.css` legacy styles. |
| Bottom nav (mobile) | `lnkup_dashboard_shell_close` ~1206–1274 | Keep | Home, Events, Vendors, Money + More. |

## Screens

| Screen | Route | Renderer | Class | Findings |
| --- | --- | --- | --- | --- |
| Home | `view=home` | `render_home` / `render_home_v2` (views ~449, ~820) | Rebuild | Single column. Stat strip sits *below* the action list. "Collected booth fees" uses `lnkup_organizer_collected_booth_total` (gross booth amount, all time) while Money shows net figures from Stripe — unlabeled difference. Action rows link to unfiltered pages in two places. Raw `post_title` used in "%s has no open vendor spots" (~561). Weather card can show the admin configuration message. |
| Events | `view=events` | `render_events` → `LNKup_Shortcodes::render_organizer_events` (~3430) | Rebuild | Card list only. `render_organizer_event_card` (~3073) nests media + body inside the legacy `.lnkup-grid` with no `minmax(0, …)` so the body column collapses when the title is long (see `events-390.png`). No table view, no filters, no publication vs. chronological split. |
| Event workspace | `?event=ID&tab=` | `render_event_workspace` (shortcodes ~4691) | Rebuild | Tabs: overview, slots, requests, vendors, financials + readiness/tasks/documents + links to ops and scan. Switcher lists raw titles ordered by title (~4771). Stat strip and a second "Review N applications" button duplicate the header. "Slots" naming. Slot editing uses one form per row with its own Save. |
| Applications | `view=applications` | `render_applications` (~1370), `organizer-review.js` | Restyle | Two modes (review v2 and classic). Filters are correct; bulk actions exist in `organizer-requests.js`. No next/prev in the review panel when entered from a filtered list. |
| Roster | `?event=&roster=1` | `render_event_roster` | Restyle | Readiness data exists (`LNKup_Vendor_Readiness`) but columns don't show responsible party. CSV export (`organizer_vendor_export_url`) doesn't escape leading `= + - @`. |
| Vendors / Find vendors / Lists / Invites | `view=vendors|lists|invites` | `render_vendors` (~1802), `render_find_vendors` (~2031), `render_lists` (~2111), `render_invites` (~2144) | Rebuild | Four entry points for one job. Categories shown with HTML entities (`Food &amp; Beverage`) in some cards. Match reasons shown without evidence when the engine returns none. |
| Money | `view=financial` | `LNKup_Financial_Render::organizer_shell` (~122) + `financial.js` | Rebuild | Two tab rows (4 section pills + 14 underline tabs). Setup banner always large. Overview hero mixes gross and net without labels. Transactions table has one combined status. |
| Operations | `view=operations` | `render_operations` (~2290) | Restyle | Card per event with 5 facts; "Ready" denominator is booked vendors but not stated. Event ops page (`?ops=1`) has a nine-card strip and a "Live feed" label with no live refresh. |
| Booths & Map | `?event=&ops=1#booths` | `operations.js`, `maps.js` | Rebuild | Booth table shows `App 0` when assignment has no vendor name; "Add booth" opens a modal anchored top-left. Map builder opens directly into an empty dark canvas with no starting choice. |
| Packets | `?event=&ops=1#packet` | `LNKup_Packets`, `operations.js` | Restyle | Single "sent" count; no distinction between prepared, published, viewed, acknowledged. |
| Check-in | `?event=&scan=1` | `render_scan_view` | Restyle | Works; duplicate and wrong-event responses share one generic error string. |
| Tours & Series | `view=tours` | `render_portfolio` (~1209) + `portfolio.js` | Fix + Restyle | No route to attach existing events to a tour (`class-portfolio-rest.php` has `detach` only). |
| Marketing | `view=marketing` | `LNKup_Organizer_Marketing::render` | Restyle | Share link and embed code live in Get started, not here. |
| Intelligence | `view=intelligence` | `render_intelligence` (~2541) | Restyle | Projections not labeled as projections. |
| Team & Trust | `view=team` | `render_team` (~2206), `org-team.js` | Restyle | Role names map to `LNKup_Org_Teams` caps; good. Needs a settings hub. |
| Profile | `view=profile` | `render_profile` (~1233) | Restyle | No public preview next to the form. |
| Get started | `view=start` | `render_start` (~128) | Restyle | Large stepper stays in nav after completion; the embed code is only here. |
| Create | `view=create` | `render_create` (~2234) | Keep | Duplicate flow lives on Events cards. |

## Data integrity findings

1. **Duplicate event keeps the source city.** `LNKup_Organizer_Onboarding::insert_duplicate_event` (~1222) copies `event_meta_keys_to_copy()` (~1811) — city, state, lat/lng, zip, address lines, market, region, neighborhood, geocode status and manual override. Overrides only apply when present in `$input`. Renaming a Houston event to "Greensboro …" without re-entering city and state keeps Houston everywhere (map pin, place label, discovery market).
2. **Weather setup text shown to organizers.** `LNKup_Weather_Service` (~160) returns "Configure your OpenWeather API key in LNKup Settings…" as the DTO message; `LNKup_Weather_Render` prints it on Home and Operations.
3. **Raw titles.** `post_title` is used in the event switcher (~4791), Operations cards (~2339), the shell breadcrumb (`dashboard.php` ~827), Home welcome subtitle (~652) and the unpublished-event action row (~561). `LNKup_Vendor_Presentation::customer_event_title()` already strips repeated date/venue/organizer suffixes.
4. **Places.** City/state are concatenated directly in the workspace (`$where`, ~4715) — produces "Atlanta, GEORGIA", "Ellenwood, GA, GA". `lnkup_display_place()` (helpers ~3105) normalizes both.
5. **Home vs. Money collected.** Home = gross booth fees of `paid` payment posts, all time (`lnkup_organizer_collected_booth_total`). Money = Stripe Connect balance (net of platform fee and Stripe fees). Both are correct; neither is labeled. See `DATA_INTEGRITY.md`.

## Accessibility and layout

- Organizer pages have no shared focus ring; buttons in `organizer-crm.css` use `outline: none` without replacement in several places.
- Two levels of tab rows in Money and workspace make keyboard navigation long.
- Event card body collapses at 390px (see before screenshot).
- Status pills rely on color alone (`lnkup-status-pill--success` vs `--muted`).

## Baseline measurements

Captured by `node scripts/os-shots.mjs --role=organizer docs/organizer-experience-v2/screenshots/before 1440,390`:

- `screenshots/before/*.png` — every organizer route at 1440 and 390.
- `screenshots/before/overflow.json` — page-level horizontal overflow per route and width.
- `screenshots/before/rest.json` — REST requests per route, with per-request timing and time to settle. Home, Events and Money figures are summarized in `IMPLEMENTATION_PLAN.md`.

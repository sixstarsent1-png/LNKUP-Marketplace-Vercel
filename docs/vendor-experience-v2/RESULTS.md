# Vendor Experience 2.0: visual overhaul results

Scope: the authenticated vendor experience on WordPress (system of record), plus Expo token and booking-card alignment. Organizer screens are unchanged except for shared helpers (event card actions, invoice and transaction titles).

- Before screenshots: `screenshots/before/` (first pass, captured before this overhaul).
- After screenshots: `screenshots/after/{screen}-{width}.png` for 9 screens at 1920, 1440, 1280, 1024, 768, 430, 390, and 360. Each was captured after network idle and after skeletons cleared.
- Overflow: `screenshots/after/overflow.json` shows 0 px page-level horizontal overflow for all 72 screen and width pairs.

## Before and after by screen

| Screen | Before | After | Evidence |
|---|---|---|---|
| Navigation and shell | Flat list, a duplicate brand and business name in the top bar | Collapsible Marketplace and My Business groups, a desktop icon-collapse mode, a notifications bell with a live unread count, no duplicate identity | `home-1440.png` |
| Home | Small header, oversized Getting Started, one "Next step" card, dot list | Identity hero with greeting. Main column: spotlight (image, countdown, prep progress, pass, booth), then "Needs your attention" with past-event balances in their own group, then recommendations, then grouped activity. Rail: 4 clickable metrics, invitations with images and organizer, compact setup, vendor level. Module order follows lifecycle stage (`data-vos-stage`). | `home-1440.png`, `home-390.png` |
| Explore | "Explore" header, split search, two buttons per card, hidden status and save | "Find your next vendor opportunity" header, one search bar with place field, sticky filter bar, chip filters. Cards show one status badge, save, organizer, title, place and date, fee, category summary, deadline, and one primary action ("Apply now" plus a text Details link). | `explore-1440.png` |
| Opportunity detail | Generic "Explore Vendor Spots" CTA for everyone | Sticky action column with fee, facts, and deadline. The CTA reflects the vendor's state: Continue application, View application, Complete payment, View booking, Choose a booth and apply, or Applications closed. | Verified in browser on `/events/e2e-summer-market/` |
| Bookings | Initials tile, several chips, `<details>` timeline | Horizontal card with event image, one status with icon, amount, place, booth, and organizer, one primary action, Details, and Withdraw. Tabs: All, Needs action, Applications, Upcoming, Past. | `bookings-1440.png`, `bookings-360.png` |
| Booking detail (new) | none | `?view=applications&app=ID`: hero, then Overview (the four lifecycle dimensions plus a timeline), Payments (snapshot line items, receipt), Documents, Event prep, and Activity tabs | `vendor-os.spec.ts` booking detail test |
| Wallet | Empty "Balance" shell and skeleton until JS loaded, 11 tabs | Server-rendered summary (amount due, total spent, credits, next installment). Outstanding payments grouped into upcoming and past events, with images and invoice numbers. Five tabs plus a More menu. Invoice drawer with stored line items. Ledger rows for removed payments show "no longer due". | `wallet-1440.png`, `wallet-390.png` |
| Receipt | Plain page, PDF link always shown | Branded document: paid status, payee "{organizer} (collected through LNKup)", payment method, booth, transaction reference, line items or a stated recorded total, Print, View booking. PDF link appears only when Dompdf is installed. | Receipt-signing test in `vendor-os.spec.ts` |
| Event Operations | "Mission control" text, bare select, dashed lock box | Dark event header with image, status, countdown, prep progress, and an event switcher. Readiness split into "Your next steps", "Waiting on organizer", and a collapsed "complete" group with a your-steps count. Compact pay-to-unlock row. | `operations-1440.png`, `operations-390.png` |
| Growth | KPIs without units, invented "Booked" funnel stage, duplicate markets, "Trending" labels | KPI grid with units and periods ("—" plus "Not enough history yet" when no comparison). Real-count funnel. Spending chart titled with its window. Monthly table fallback. Deduped markets with reasons. "Markets with recent listings". Organizer cards show booth fees paid. | `growth-1440.png` |
| Documents | "No documents uploaded" alongside a document in review, ring chart | Four-count summary (verified, in review, expiring, not uploaded). Cards with status badges. Honest copy for missing documents. | `documents-1440.png` |
| Saved | Flat grid titled Favorites | "Saved", grouped into Closing soon, Available, Applications closed, and Past events | `saved-1440.png` |
| Passport | Off in this environment ("coming soon" empty state) | Restyled through the shared tokens. The Passport tab styles are ready for when the flag is on. | `passport-1440.png` |
| Notifications | Shell title "Home", narrow column | Title hidden, wider column, vendor tokens | Captured during the pass |
| Expo | White background, gray borders, raw status labels | `colors.canvas` background, warm `colors.line` borders, server `primary_status` labels, organizer line, past-event invoice note. Booking tabs renamed to Needs action, Applications, Upcoming, Past. | `npx tsc --noEmit` clean |

## Verification that ran

- Playwright, Chromium, against `http://localhost:8888` with the seeded vendor: `tests/e2e/vendor-os.spec.ts`, `tests/e2e/vendor-dashboard-ui.spec.ts`, and `tests/e2e/vendor-financial.spec.ts`. **25 passed**, 0 failed, 0 skipped. Coverage:
  - Design layer loads.
  - No overflow at 1440, 1024, or 390 on 8 screens.
  - Action-center metric links.
  - Booking detail tabs.
  - Wallet summary equals the sum of the outstanding rows.
  - Receipt link signed; a tampered token is refused for an anonymous request.
  - Operations switcher changes `app=`.
  - Growth funnel has no invented stage.
  - axe (WCAG 2 A/AA) finds **no serious or critical violations** on Home, Bookings, or Wallet. The run found and fixed two issues: a progressbar without a name and status-chip contrast.
- Visual review of the after screenshots at 1440, 1024, 390, and 360 for Home, Bookings, Wallet, and Operations.
- `node --check` on `financial.js`, `growth.js`, `vendor-os.js`, and `verification.js`.
- `npx tsc --noEmit` in `lnk-up-marketplace-mobile`: clean.
- `npx tsc --noEmit` in `lnk-up-marketplace-ui`: existing errors only, in files this pass did not touch: organizer API routes, `vendor/find`, `opportunity-card`, `document-upload-form`, and the onboarding stepper.

## Not run

- PHP lint: no PHP CLI on this machine. The pages rendered without fatals in the browser and under Playwright.
- Receipt PDF output: Dompdf is not installed locally (`composer install` is needed in the plugin). The route returns 503 JSON, and the link is hidden until Dompdf exists.
- Expo on a device or simulator, and the Next.js app in live mode.
- Stripe card setup and payments. No charges were made.
- Screen-reader walkthroughs. Only automated axe checks and keyboard focus styles were covered.

## REST changes

- `GET /lnkup/v1/financial/transactions` items now include `payment_status`: the current payment post status, or `removed` when the post is gone. This is an additive field.
- Invoice and transaction `event_title` now use the customer event title from `LNKup_Vendor_Presentation`.

## Files changed in this pass

WordPress plugin:

- New: `includes/class-vendor-ui.php`, `public/css/vendor-os.css`, `public/js/vendor-os.js`.
- Loader: `lnkup-marketplace.php` requires `class-vendor-ui.php`.
- `public/class-vendor-dashboard-views.php`: Home, Action Center, spotlight, invitations, activity, booking cards, booking detail, operations header, trust card label.
- `public/class-shortcodes.php`: Explore header and search, event action column, wallet outstanding groups, documents summary, Saved groups.
- `includes/helpers.php`: event card actions, deadline line, application status key. The v2 card's View button became Details.
- `includes/class-financial-render.php`: server-rendered wallet summary, tabs plus More.
- `includes/class-receipts.php`: receipt document, context, and PDF link guard.
- `includes/class-invoices.php`, `includes/class-transactions.php`: titles and `payment_status`.
- `includes/class-vendor-dashboard-data.php`: past-event balance group in `today_priorities()`.
- `includes/class-vendor-presentation.php`: `event_cta()`, fixture helpers, E2E tour-state fix.
- `includes/class-vendor-readiness.php`: item `owner`.
- `includes/class-operations-render.php`: readiness grouped by owner.
- `includes/class-growth-recommendations.php`: city dedupe, fixture exclusion on production, honest category copy.
- `includes/class-growth-render.php`: Spending tab label.
- `includes/class-organizer-recruit-render.php`: live referral terms.
- `public/js/financial.js`: invoice drawer, reports, reconciled transactions, tax summary copy, status labels.
- `public/js/growth.js`: KPIs, funnel, spending charts with table fallback, markets, labels.
- `public/js/verification.js`: "Unlocked" heading only when badges exist.

Theme:

- `inc/dashboard.php`: collapsible group buttons, collapse toggle, notifications bell, Invitations link.
- `functions.php`: `LNKUP_THEME_VERSION` 1.8.0.

Expo:

- `lib/theme.ts`, `components/screen.tsx`, `components/request-card.tsx`, `lib/api/vendor-requests.ts`, `app/(vendor)/requests.tsx`.

Tests and tooling:

- `tests/e2e/vendor-os.spec.ts` (new), `tests/e2e/vendor-financial.spec.ts`, `tests/e2e/vendor-dashboard-ui.spec.ts`.
- `scripts/vendor-os-shots.mjs` (new).
- `scripts/e2e/seed.php`: tour stop states.

The working tree also contains uncommitted changes from earlier work: organizer screens, plugin zips, and `test-results/`. They are not part of this pass.

## Deployment

1. Deploy the plugin and theme together. The theme version bump busts the cached `components.css`. `vendor-os.css` and `vendor-os.js` are versioned by file modification time.
2. Run `composer install --no-dev` in `wp-content/plugins/lnkup-marketplace` so receipt PDFs (and the tax-center PDFs) work.
3. On first load, the `lnkup_e2e_tour_state_v1` option runs. It only touches seeded E2E tour events, so it is a no-op on production.

## Rollback

- Visual layer: remove the `includes/class-vendor-ui.php` line from the loader. The body class and `vendor-os.css` and `vendor-os.js` stop loading, and pages fall back to the previous styles. The markup still renders, unstyled by the new layer.
- Full rollback: revert the files listed above. No database schema changed. The only option rows written are `lnkup_e2e_tour_state_v1` and the existing `lnkup_state_alias_v1`.

## Remaining limitations

- Notification center rows still repeat identical seeded notifications. Grouping them belongs in the communications plugin and was not changed.
- Passport is off in this environment, so the Passport overview, completion sections, and reputation progression were not reviewed visually with real data.
- The Dirty South Rodeo card shows a solid blue image. That is the uploaded tour cover itself, not a rendering fallback.
- Invoice and ledger rows for deleted seed payments remain in the database. The UI reconciles them against the payment posts but does not delete them.
- The Next.js app was not restyled in this pass beyond the earlier contract changes. Its existing type errors are outside this pass.

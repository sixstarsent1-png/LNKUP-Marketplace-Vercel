# ChatGPT handoff: where LNKup stands

Last updated: Nov 20, 2026 (Mobile App 3.1 complete).

## 0. Latest: Mobile App 3.1 (Nov 19–20)

All 3.1 stages shipped to production as OTA updates on runtime 1.0.1 (iOS), one per stage: `8590ee6d` (1), `7b18be22` (2), `5d63dcb7` (3a), `36f280ce` (3b), `175c2849` (4a), `9f099682` (4b), `870a7c2d` (5), `8f31415f` (6), `3325da56` (7). Latest `master` commit for the app: `3a17ccb`.

- **Known fixes:** single bookings request on vendor Home; Vendors inbox and Discover virtualized with auto-paging, stale-search cancel, removable filter chips and an honest end of list; Pass and Apply on shared typography, `Button` and `FormField`.
- **Design:** one type scale (`typeScale` in `lib/theme.ts`, incl. `hero`/`large`/`jumbo`/`nano`) used everywhere; input focus ring; reduced-motion button press; skeleton loading on content screens.
- **Vendor:** real event images on cards and detail (`featured_image`); compact Explore header with Near me chip and category chips; "Coming up" bookings on Home; plain-language status line on booking cards; Documents rebuilt (compliance progress, badges, expiry warnings, upload sheet with date picker); Growth on shared components.
- **Organizer:** Home no longer repeats the hero item; formatted event dates; launch shows autosave status; request cards say "booth fee" until approval and "due" only after; cached push preferences.
- **Event day:** booth picker shows Open / Taken / Current; 44pt check-in actions.
- **Functionality sweep:** role-permission matrix (vendor vs organizer routes, other organizer's event) and error-path specs (profile save, checkout handoff, favorites, cash out, slot pricing) — honest errors, rollback, one request per double tap.
- **Performance:** organizer Home and Money reuse caches on tab focus (warm return 6→0 and 4→0 calls); pull-to-refresh still forces.
- **Server fixes (in git, need a WordPress deploy to thelnkup.com):** saved-search delete now persists (`class-saved-searches.php`), roster refusals return 403 instead of 500 (`class-event-roster.php`).
- **Native track:** branch `native-3.2` (version 1.1.0, never OTA): QR camera check-in, photo-library logos, haptics, Stripe native SDK removed, iOS 16.4 target, SDK 57 patch alignment, `expo-doctor` 21/21. See `docs/NATIVE_BUILD_3.2.md`.
- **Tests:** 105 unit tests (14 suites). New Playwright specs: `sweep-roles`, `sweep-errors`; cash-out request/cancel uses organizer B's seeded $25 test-mode balance. Seed also clears leftover E2E saved searches and main-event booths.
- **Still open:** Reviews / Operations / Referrals keep older layouts; Sentry DSN and dark mode need input; event cover upload needs a WordPress endpoint; organizer Money still makes ~14 calls cold (one per event financials).

## 1. Product and stack

LNKup is a two-sided marketplace for the event economy:

- **Vendors** find events, apply, pay booth fees, upload documents, and get event passes.
- **Organizers** create events and vendor slots, review applications, collect fees, cash out, and run event-day check-in.

WordPress is the system of record. Nothing else stores the real data.

| Layer | Path | Role |
|---|---|---|
| Plugin | `wp-content/plugins/lnkup-marketplace/` | All business logic: about 200 REST routes under `lnkup/v1`, 13 custom post types, the financial OS |
| Theme | `wp-content/themes/lnkup-theme/` | Dashboard shell and layout only; no business logic |
| Mobile app | `lnk-up-marketplace-mobile/` | Expo SDK 57, expo-router, React Native 0.86. Calls WordPress REST directly with a Bearer token from `POST lnkup/v1/auth/login`, stored in SecureStore |
| Web app | `lnk-up-marketplace-ui/` | Next.js on Vercel, with a server proxy to `lnkup/v1` |
| Reference only | `app/`, `components/`, `lnkup-app-reference/` | Design mocks; not production |

Rules that always apply:

- **Money:** every payment goes through `LNKup_Financial_Service`. No Stripe calls from UI code, and no Stripe SDK in the mobile app.
- **REST routes:** every route has a `permission_callback`. Errors are returned as `WP_Error`, never echoed HTML.
- **Roles:** `lnkup_vendor`, `lnkup_organizer`, `lnkup_admin`, `administrator`. Vendor and organizer areas never call each other's endpoints.
- **UI copy:** friendly wording. Never show raw errors or Stripe jargon ("Payouts active", not "Stripe Connect active").
- **Screen states:** every screen has loading, empty and error states, and never shows sample data when a live call fails.
- **Testing payments:** use staging accounts and payment test mode. No live charges or production payouts as informal tests.

## 2. Work completed

### Earlier work: Vendor and Organizer Experience 2.0 (WordPress dashboards)

- The organizer lifecycle spec passed 16 of 16. A phone viewport was added to the dashboard consistency tests.
- `ORGANIZER_QA_CHECKLIST.md` was written and the audit matrix updated.
- That work changed desktop files that are still uncommitted, including `public/class-shortcodes.php` and `public/js/marketplace.js`. The old event gallery picker was replaced by a shared `data-image-upload` uploader.

### Mobile App 3.0 (all 10 stages done)

The full per-screen record is in `docs/MOBILE_AUDIT_INVENTORY.md`. Highlights by area:

- **Honesty and sign-in:**
  - Live failures now show errors, never sample data.
  - Accounts with no role get a "no access" screen instead of a login loop.
  - Sessions expire properly.
  - Error messages are in plain language.
- **Navigation:** deep links and push taps go through one queue (`lib/navigation/pending-destination.ts`). It checks the user's role and keeps the destination through sign-in. Push routing lives in `lib/push/notification-routing.ts`.
- **Design system:**
  - `AppText` with Dynamic Type limits.
  - Toast, confirm and prompt sheets replace `Alert`, which does nothing on web.
  - 44-point touch targets, reduced motion, and `EventImage`.
  - `ScreenList`, a virtualized list version of the screen wrapper.
- **Vendor app** (tabs Home, Explore, Bookings, Wallet, Profile):
  - Home leads with a next-step card.
  - Each booking has a timeline screen (`/booking/[id]`).
  - Wallet has Due, History and Receipts tabs.
  - The business profile has an editor with validation.
- **Organizer app** (tabs Home, Events, Vendors, Money, More):
  - Each event has a workspace: Overview, Slots, Applications, Vendors, Payments, Day-of, Packet.
  - Create event is six steps with server drafts.
  - The Vendors inbox has lifecycle filters, an Invites tab and decline reasons.
  - Money shows the server's money categories, payout history with cancel, and the real payout-setup states.
  - More is grouped into sections.
- **Check-in (new):** `app/(organizer)/events/checkin.tsx`. Search and filter the roster, enter a pass code, override with a written reason (event owner or operations staff only), see "already checked in", get "not recorded" when offline, and assign or move booths.
- **Performance:**
  - Short-lived caches for organizer Home, wallet, favorites and readiness, cleared after every write and every payment return.
  - Organizer Home went from 35 requests to 15, and Events from 19 to 2.
- **Narrow WordPress fixes:**
  - Saved searches no longer crash (infinite recursion).
  - The financial health score uses the right database columns.
  - The event list data now includes venue, description and per-status counts. Without them, saving an edit could wipe those fields.
  - New route `POST /organizer/opportunities/{id}/status` to close or reopen a slot.
  - Launch publish supports `save_mode: "draft"`.

## 3. What is deployed

- **WordPress:** the new routes answer on thelnkup.com, so the plugin changes are live.
- **Mobile OTA:** published Nov 17 to EAS channel `production`, iOS only, runtime 1.0.1.
  - Update group: `10275e87-4b07-4231-822b-50055d19e431`.
  - The bundle points at thelnkup.com with the live API on.
  - It was built from uncommitted changes (commit `779bd19*`), so those changes still need committing.
  - Roll back with `eas update:rollback --channel production`.
- **App Store builds:** all production iOS builds are version 1.0.1. There are no Android builds and no preview builds.

## 4. How to test

- **Local WordPress:** wp-env at `http://localhost:8888`. It is slow, about 3–4 seconds per request.
- **Seed the test data:** `npx -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/seed.php`. This writes `scripts/e2e/.env.e2e`.
  - The seed resets the event-day state of the free booking, the booth assignments on the main event, and the one-spot "fresh" opportunity.
  - Test users: `e2e_vendor_a`, `e2e_vendor_b`, `e2e_org_a`, `e2e_org_b`, `e2e_staff_*`, and `e2e_no_role`. Password: `lnkup-e2e-Passw0rd!`.
- **Mobile end-to-end tests:** `powershell -File scripts/e2e/run-mobile.ps1 tests/mobile [--project=iphone-15]`.
  - Runs Expo web on port 8081 against wp-env, on iPhone 15, SE and 15 Pro Max viewports.
  - Specs: baseline, auth-and-honesty, guards-and-actions, layout, vendor, organizer-events, organizer-vendors-money, organizer-checkin, cross-role.
  - Tests that change server data run once, on iPhone 15. Reseed before rerunning them.
- **Unit tests:** `cd lnk-up-marketplace-mobile && npx jest` (101 tests, 12 suites). Typecheck: `npx tsc --noEmit`.
- **Last results:** the full run on all three devices passed 113, skipped 18 and failed 1. That failure (no open booth left from earlier runs) was fixed in the seed, and the check-in spec then passed 6 of 6.
- **Verify scripts:** `node scripts/{money-path,gap-close,find-media-wallet,organizer-vendor-profile-enrichment}-verify.mjs` all pass.

## 5. Open items and next steps

1. **Real-iPhone check of the OTA:** login, push taps, VoiceOver, universal links, and the native checkout browser. None of these have run on a device yet.
2. **Commit** the uncommitted mobile, plugin and desktop changes so git matches what shipped.
3. **P3, test mode only:** the checkout link `redirect-url` returns in simulation mode is signed for the app's token session, so it fails in a browser. Production Stripe links are not affected. Left as is because the signing code is security-sensitive.
4. **P2 remaining:**
   - Raw font sizes on the pass and apply screens.
   - The Vendors inbox and Discover still use paginated scroll views.
   - Vendor Home calls `/applications/vendor` twice.
5. **Next native build** (cannot ship over the air):
   - `expo-camera` for QR scanning and `expo-image-picker`.
   - `expo-haptics`, Sentry, and dark mode.
   - Remove `@stripe/stripe-react-native` and set an explicit iOS deployment target.
   - A server endpoint for uploading event covers and galleries from the app.

## 6. Shell quirks on this machine

- The shell is PowerShell. Quote patterns that contain `"` with single quotes.
- Some files use CRLF line endings, so multi-line text replacements can silently miss.
- Prettier has no project config. Use `--no-semi --print-width 110`.
- Use `npx.cmd` instead of `npx`.

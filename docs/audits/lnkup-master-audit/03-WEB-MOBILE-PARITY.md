# 03 — Web vs mobile parity

Method: for each feature, the plugin route and service were located, then client code was searched for calls to that route (`scripts`-free static scan of `wp-content/plugins/lnkup-marketplace/public/js`, `lnk-up-marketplace-ui`, `lnk-up-marketplace-mobile`). "Live" means the client actually calls the route; it does not prove the screen works on a device.

Legend:
- **Y** = wired
- **P** = partial or read-only
- **N** = no client code
- **G** = wired but behind a default-off server flag
- **M** = only on `origin/master` (not in the production build)

Columns:
- **WP** = WordPress web dashboards (the production web UI)
- **Next** = Next.js app (its production deploy is from July 2026)
- **iOS/Android** = Expo app
- **E2E** = strongest end-to-end evidence

| Feature | Backend | WP | Next | iOS/Android | E2E evidence |
|---|---|---|---|---|---|
| Sign in / sign up | Y | Y | Y | Y (Bearer token, SecureStore) | Expo web Playwright (repo) |
| Vendor profile edit, logo, photos | Y | Y | P (no logo/cover upload route) | Y | jest + Playwright |
| Vendor Passport (vault, history, public) | G | G | **N** | G | PHP `vendor-passport-test` |
| Find / search events | Y | Y | Y | Y | Playwright |
| Map discovery | Y | Y | N | Y (native maps) | — |
| Saved searches, favorites | Y | Y | Y | Y | `matching-alerts-test` |
| Match recommendations, explain | G | G | G | G | `matching-test` |
| Apply (single) | Y | Y | Y | Y | `booking-application-os-test` **ran: 73/73** |
| Application drafts, resume | G | Y | Y (list M) | Y (list M) | `draft-submit-changes-test` (M) |
| Multi-stop tour apply | G | Y | Y | Y | `tour-multi-apply` spec |
| Withdraw / reapply | Y | Y | Y | Y | `reapply-policy-test` **ran: 37/37** |
| Request info / respond | Y | Y | Y | Y | repo J |
| Stripe checkout | Y | Y | Y (BFF) | Y (in-app browser + `native-return`) | PHP checkout **ran: 45/45** (Stripe off); **no real test-mode card run** |
| Event pass / packet | Y | Y | Y | Y | `booking-lifecycle-test` (M) |
| Bookings Center (vendor) | **M** | M | N | Y (`booking/[applicationId]`) | not deployed |
| Vendor wallet, invoices, receipts | Y | Y | Y (all pages M) | Y | `financial-lists-test` **ran: 14/14** |
| Refund request | Y | Y | P (read-only) | P (read-only) | `financial-refund-dispute-test` **ran: 22/22** |
| Documents upload | Y | Y | Y | Y | — |
| Notifications inbox, preferences | Y | Y | Y | Y | comms suites (repo) |
| Push notifications | Y | — | — | Y (`/push/devices`) | **device delivery never verified** |
| Referrals / partner workspace | Y | Y (connector dashboard) | P | Y (`(partner)` group, 8 screens) | `partner-earnings-contract-test` (M) |
| Organizer onboarding v2 | G | G | G | G (continue-setup card) | `organizer-onboarding-flow-test` |
| Create + publish event | Y | Y | Y | Y (`launch.tsx`) | `launch-publish-idempotency-test` (M) |
| Duplicate event | Y | Y | Y | Y | |
| Stripe Connect onboarding | Y | Y | **N** | Y | — |
| Cash out / payouts | Y | Y | Y | Y | `financial-cashout-test` **ran: 22/22** |
| Organizer Money summary | Y | Y | Y | Y | `financial-contract-test` **ran: 49/49** |
| Requests inbox, decide | Y | Y | Y | Y | |
| Review v2 decision route | G | G | Y (M, with fallback) | Y (M, with fallback) | `organizer-review` spec |
| Waitlist offers | G | G | **N** | **N** | `comms-organizer-os-test` (M) |
| Vendor CRM notes / invites | Y | Y | P | Y | |
| CRM lists | Y | Y | Y | **N** | |
| Booth management | Y | Y | **N** | P (assign from check-in) | |
| Booth/parking **edit** | **X on production line** | X | N | N | fixed on M (`route_id`) |
| Floor-plan upload (maps) | **X** | X | N | N | O25 open |
| Event tasks, readiness | G | G | N | **M** (Day-of panel) | `organizer-os-test` |
| Team management | G | G | **N** | **N** | `team-scope-rollup-test` (M) |
| Organizer verification | Y | Y | **N** | **N** | `trust-safety-test` |
| Check-in (manual / list) | Y | Y | Y | Y | `organizer-checkin` spec |
| Check-in camera scan | Y | P (`BarcodeDetector`, not on iPhone Safari) | N | **N** (no `expo-camera`) | — |
| Offline check-in | N | N | N | N | — |
| Event broadcasts to vendors | G | Y | N | Y (`event-comms/[eventId]`) | |
| Incidents / support requests | Y | Y | P (vendor support hook) | P (incidents only) | |
| External events (claim, labels) | Y | Y | Admin only | **N** | |
| Portfolio: tours, templates, recurrence (organizer) | G | G | **N** | **N** | `portfolio-os-test` |
| Public tour page | G | G | Y (vendor) | Y (vendor) | |
| Organizer Growth CRM (staff) | G | wp-admin | N | N | org-growth suites |
| Admin ops queue | **M** | M | M (`/admin/ops`) | N | `ops-overview-test` (M) |
| Admin money/events/users | Y (wp-admin) | Y | **Fake data** | N | static: `app/admin/money/page.tsx` |

## Gaps by category

1. **Web but not mobile:**
   - team management
   - organizer verification
   - organizer tour/series tools
   - waitlist offers
   - CRM lists
   - external-event claim
   - booth management outside check-in
   - support requests
   - event tasks (production line)
2. **Mobile but not web (Next.js):**
   - Stripe Connect onboarding
   - map discovery
   - Passport
   - incidents

   The WordPress web UI has all of these, so this is a Next.js gap only.
3. **Inconsistent data:** the Next.js admin Money/Events/Users and vendor More pages show sample numbers. Before master's Phase 2D fix, both apps added up their own wallet totals instead of reading `money.metrics`. That fix is on master only.
4. **Responses read incorrectly:** none confirmed on H. The Next.js typecheck reports 18 type errors on H (for example `lib/api/vendor-financial.ts` L191/250/422 and `lib/api/saved-searches.ts` L99-100 reading properties the types don't have). These were fixed on master by PR #7.
5. **Loading/error states:** Expo live mode never falls back to sample data (`lib/api/fixture-honesty.ts::withFixtureFallback`). Errors reach the screen. Good.
6. **Deep links the app can't open:**
   - Production `/.well-known/assetlinks.json` returns **404**, so Android App Links aren't verified.
   - Production `apple-app-site-association` (served at the root; `/.well-known/` path is 404) lists only `/vendors` and `/organizers`. Event and opportunity links (`/opportunities/?apply=`, `/lnkup_event/...`) open in Safari, not the app.
7. **Placeholder data in mobile:** none in live mode.
8. **Built but not reachable from navigation:** Premium listings (`LNKup_Premium_Listings::purchase` is never called), financing and insurance placeholders, escrow (no routes).
9. **Needs a new native build:**
   - camera QR scanning (`expo-camera`)
   - any change to `app.json` intent filters (the `/opportunities` filter already added in code)
   - the version bump
10. **Shipped OTA but needs native:** none found. The repo's STORE_RELEASE.md warns correctly.

    The bigger issue is the other direction: the **App Store binary is 1.0** (Apple lookup, current version 2026-10-14), while updates are published for runtime **1.0.1** (`runtimeVersion.policy: appVersion`). Unless a 1.0.1 binary is in the store, store users aren't receiving the Mobile 3.x JavaScript. Confirm in the EAS dashboard.

# Mobile Vendor OS 2.0: audit

Scope: the vendor side of `lnk-up-marketplace-mobile` (Expo SDK 57, React Native 0.86, expo-router 57, React 19.2). Organizer screens were checked only for shared-component compatibility. The web dashboard audit for the same initiative is in [`docs/vendor-experience-v2/AUDIT.md`](../vendor-experience-v2/AUDIT.md).

## Method

- Read every route under `app/(vendor)`, `app/opportunity`, `app/apply`, `app/booking`, `app/pass`, `app/tour`, `app/storefront`, and the shared `components/` and `lib/` code they import.
- Traced each screen's data to its `lnkup/v1` handler in `wp-content/plugins/lnkup-marketplace`.
- Called every vendor endpoint as `e2e_vendor_a` against local wp-env (`localhost:8888`) and compared the JSON with what the screens render.
- Rendered the app on Expo web at 360x800, 390x844, 393x852 and 430x932 (`tests/mobile/vendor-v2-shots.spec.ts`, label `before`). The baseline is in `screenshots/before/`.

The 15 user-supplied screenshots come from production (thelnkup.com). The local dataset does not contain the broken records they show; for example, all 6 local applications resolve to a live event. Those symptoms were traced through code, not reproduced with production rows. See `DATA_INTEGRITY.md`.

## Platform facts

| Item | Value |
|---|---|
| Expo / RN / React | ~57.0.13 / 0.86.2 / 19.2.3 |
| Router | expo-router ~57.0.13, typed routes |
| Runtime version | `policy: appVersion` (1.0.1). The OTA updates in this release stay JavaScript-only. |
| Data layer | Hand-built `lib/api/request-cache.ts` (shared in-flight requests, short time-to-live caching, subscribers). No react-query. |
| Images | `expo-image` via `components/ui/event-image.tsx` (memory and disk cache) |
| Sheets | Home-grown `components/ui/bottom-sheet.tsx` |
| Maps | `react-native-maps` 1.27 |
| Payments | `@stripe/stripe-react-native` 0.64 via server-prepared checkout (`/financial/checkout/*`) |
| Auth | Bearer token from `/auth/login`, kept in SecureStore (`lnkup_wp_session`); legacy Basic auth fallback |
| Not installed | expo-haptics, @gorhom/bottom-sheet, FlashList. Each would require a new binary, so none were added. |

## Screen inventory and classification

Classification keys: **P** = functional and polished, **R** = functional, needs refinement, **V** = visually underdeveloped, **D** = data inconsistent, **B** = broken.

| Screen | Route | Backend source | Before | Main issues found |
|---|---|---|---|---|
| Home | `app/(vendor)/index.tsx` | `/applications/vendor`, `/marketplace/sections/*`, `/notifications/inbox`, readiness, favorites, `/comms/inbox/count` | R, D | Old payment obligations shown first. Confirmed bookings with no date counted as "Coming up". Recommendations sit low on the page. Three different next-step cards. |
| Explore | `app/(vendor)/find.tsx` | `/marketplace/search` (`per_page=20`), match rails, map pins | R, D | One card per opportunity, so the same event repeats. `total` counts opportunities but the copy says opportunities as if they were events. The tall stacked header delays results. Load-more uses a button rather than infinite scroll. |
| Event detail | `app/opportunity/[opportunityId].tsx` | `/marketplace/compare`, apply-context | V | Placeholder hero is too tall. Shows a single opportunity only, with no vendor-option selector. Fee is printed with `$${fee}`. Attendance isn't labelled as organizer-provided. Deadline chip has no label. Hand-built header. |
| Apply | `app/apply/[opportunityId].tsx` | apply-context, `/apply-with-profile`, drafts | R | Works. Drafts and the profile prefill exist. |
| Bookings | `app/(vendor)/requests.tsx` | `/applications/vendor` | R, D | Event details unavailable is a server string. Past bookings are mixed with current ones. Payment state is not separated from booking state. |
| Booking detail | `app/booking/[applicationId].tsx` | full `/applications/vendor` list | R, D | Loads the whole list to show one booking. The timeline has no timestamps. |
| Event pass | `app/pass/[applicationId].tsx` | `/packets/pass/:id` | R, D | Ended events still look like active credentials. Uses React Native `Image` for the QR code. |
| Wallet | `app/(vendor)/wallet.tsx` | financial summary, dashboard, activity, invoices, receipts, disputes | D | History comes from `/financial/activity`, an event log, so it shows "Payment past due" style rows. Every receipt shows status `unknown`. Raw timestamps. "View pass" appears next to amounts due. |
| Profile tab | `app/(vendor)/more.tsx` (tab labelled "Passport") | `/applications/vendor`, `/vendor/profile` | R | A payment prompt sits in the profile. Private email is shown inside the public preview. Business tools are buried in a long list. |
| Edit profile | `app/(vendor)/profile.tsx` | `/vendor/profile` | P | (none) |
| Vendor Passport | `app/(vendor)/passport.tsx` | `/passport/me`, `/me/*`, reputation | R | Uses React Native `Image`. Completion, verification and reputation are mixed together. |
| Documents | `app/(vendor)/documents.tsx` | `/documents/*` | R | (none) |
| Saved | `app/(vendor)/favorites.tsx` | `/marketplace/favorites` | R, D | Opportunity IDs only, so closed opportunities disappear silently. |
| Saved searches | `app/(vendor)/saved-searches.tsx` | `/marketplace/saved-searches` | R | (none) |
| Growth | `app/(vendor)/growth.tsx` | `/growth/dashboard`, reputation | R | Web relabelled spend as investment in Vendor OS 2.0. Mobile copy still needs checking. |
| Operations | `app/(vendor)/operations.tsx` | `/operations/vendor-homes` | V | Thin list. No separation of vendor tasks from organizer tasks. |
| Invitations | `app/(vendor)/invitations.tsx` | `/vendor/invites` | V | Image is mapped but not rendered. |
| Notifications | `components/notifications-screen.tsx` | local plus `/notifications/inbox` | R | Repeats are not grouped. |
| Reviews / Referrals | `reviews.tsx`, `referral-invites-screen.tsx` | reputation, organizer-recruit | R | (none) |
| Tours | `app/tour/*` | `/tours/:id` | R | (none) |

Defects visible in the local baseline screenshots (`screenshots/before/390x844-*.png`):

- Home: a React runtime toast reports "Encountered two children with the same key". The next-step list renders duplicate keys when two payments share a label.
- Wallet: the "Amount due" stat tile truncates `$229.01` to `$229....` at 390pt.
- Explore: the title, subtitle, search row, filter row, category row and list/map row fill about 45% of the first screen before any result appears.
- The 5th tab is labelled "Passport" but opens the account and profile hub. The baseline tab-switching metric failed because no "Profile" tab exists.

No screen was **B** (broken) on local data. **D** screens show wrong information when production records have the gaps traced in `DATA_INTEGRITY.md`.

## Shared code findings

- **Duplicated components:**
  - `StatusPill` wraps `StatusBadge`, and both are used.
  - Three header implementations.
  - Three next-step cards (`NextStepCard`, `NextStepsCard`, `HomeHeroCard`), each building its own pay label.
  - The pay CTA label is built in five places.
- **Caching:**
  - Request cache keys are per username but not per role.
  - A workspace switch does not clear the cache. Sign-out does.
- **List rendering:**
  - Only Explore, Bookings and Notifications are virtualized (FlatList).
  - Wallet history and Saved render with `.map` inside a ScrollView. They are capped at 20 rows, so this is acceptable.
- **Mobile logic duplicating server rules:**
  - "Amount due" is computed on the client from bookings.
  - `isUpcomingBooking` treats undated bookings as upcoming.

## Journeys traced

| Journey | Result before |
|---|---|
| Explore, event, apply | Works, but the same event appears once per category. |
| Bookings, booking, pass | Works for live events. A past event still offers the pass as if active. |
| Wallet, history | Shows event-log rows instead of ledger transactions. |
| Wallet, receipt | Every receipt shows status "unknown". |
| Home, coming up | Undated paid bookings appear as upcoming. |
| Workspace switch | Vendor cache survives a switch. Data stays per user, so another account's data doesn't leak, but stale vendor lists can flash. |

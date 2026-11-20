# LNKup Mobile App 3.0 — Audit Inventory

App: `lnk-up-marketplace-mobile` (Expo SDK 57, expo-router, React Native 0.86, fully native screens — no webviews). Bundle `com.lnkup.marketplace`, App Store ID 6795865859. Auth: `POST lnkup/v1/auth/login` Bearer token in SecureStore.

Release constraint for 3.0: **JavaScript-only (OTA)**. Anything needing a new native module is listed under "Next native build".

Runtime verification: Expo web (`react-native-web`) against local wp-env (`http://localhost:8888`), Playwright `playwright.mobile.config.ts` with iPhone 15 / SE / 15 Pro Max viewports (Chromium). Device-only behaviour (push delivery, universal links, the native in-app browser, the native document picker, VoiceOver) is marked **Device check**.

Severity: **P0** crash / security / money / lost data / broken critical journey · **P1** major screen failure, wrong state, unusable flow · **P2** slow, inconsistent, layout · **P3** cosmetic.

Status: **Fixed (runtime)** = verified by a Playwright mobile spec against wp-env · **Fixed (unit)** = verified by jest · **Device check** = code change done, needs an iOS build to confirm · **Open** = not done.

How to rerun: `npm run e2e:seed` (or `npx -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/seed.php`), then `powershell -File scripts/e2e/run-mobile.ps1 tests/mobile [--project=iphone-15]`. Unit: `cd lnk-up-marketplace-mobile && npx jest`. Types: `npx tsc --noEmit`.

## Request counts and load time — before vs after (cold load, iPhone 15 viewport)

Each route is a full page load (empty in-memory cache), so cross-tab cache reuse does not show here. wp-env answers each REST call in ~3–4 s, which dominates the times. Source: `scripts/e2e/reports/mobile-after-{vendor,organizer}.json` (after) and the original baseline run below.

| Route | Before: calls / ms | After: calls / ms | Change |
|---|---|---|---|
| Vendor Home | 22 / 14 412 | 14 / 16 107 | Duplicate `verification/vendor` removed; tutorial modal gone |
| Explore (`find`) | 3 / 3 770 | 3 / 1 353 | Virtualized list; stale searches cancelled |
| Bookings (`requests`) | 1 / 5 925 | 1 / 3 199 | Virtualized list |
| Wallet | 7 / 4 009 | 7 / 4 753 | 10 s cache shared with Bookings invalidation |
| Event passes (`operations`) | 2 / 11 071 | 1 / 1 335 | |
| Vendor notifications | 6 / 11 006 | 4 / 1 425 | Shared readiness cache; virtualized list |
| Saved searches | 2 / 4 223 (**HTTP 500**) | 3 / 3 645 | Server recursion fixed (B1) |
| Referrals | 2 / 3 732 (POST on load) | 2 / 4 057 (GET only) | Code created on tap |
| Organizer Home | 35 / 19 760 | 15 / 15 364 | Server counts; shared Home cache dedupes the notification composer |
| Events | 19 / 9 857 | 2 / 3 984 | No roster call per event |
| Money | 13 / 14 772 | 14 / 9 463 | Adds payout history; drafts excluded from per-event calls |
| Organizer notifications | 8 / 10 141 | 8 / 8 948 | |

No console errors or failed REST calls on any measured route after 3.0 (`errors: 0`, all routes settled).

## Backend defects found through the app

| # | Defect | Root cause | Sev | Fix | Status |
|---|---|---|---|---|---|
| B1 | Saved searches `GET` → HTTP 500 "Allowed memory exhausted"; browser reports CORS | `get_for_user → enrich_row → count_new_matches → run → get_by_id → enrich_row …` infinite recursion | P0 | `run()` reads the row via new `find_row()`; only `get_by_id()` enriches | Fixed (runtime) |
| B2 | Every organizer financial dashboard call logs `Unknown column 'reasons'`, recomputes the health score each time | `LNKup_Financial_Health_Score::store()` wrote `reasons`/`created_at`; schema has `reasons_json`/`risk_level` | P1 | Insert/select match the dbDelta schema | Fixed (runtime) |
| B3 | Event edit could wipe venue/description: invite options lacked them, so a save sent empty strings | `get_invite_options_for_organizer` returned only id/title/date | P0 | `include_all` adds venue, address, times, description, image, listing status, per-status counts (one SQL query) | Fixed (runtime) |
| B4 | No way to close/reopen one vendor slot from the app | No route | P1 | `POST /organizer/opportunities/{id}/status` (owner-checked; other organizer → 403) | Fixed (runtime) |
| B5 | Launch drafts lived only on the device | `publish()` had no draft mode | P1 | `save_mode: "draft"` creates the event unpublished | Fixed (runtime) |
| B6 | Test-mode payment simulation link from `redirect-url` is signed for the app's token session, so it fails when opened in a browser cookie session | `wp_create_nonce` binds to the session token | P3 | Not changed (nonce scheme is security-sensitive; production returns a Stripe Checkout URL with no nonce). The journey spec requests the link from the browser session. | Open (test mode only) |

## Screen inventory

Columns: **Design** after 3.0 (A polished · B acceptable · C dated) · **Tests** = automated coverage.

### Shared / auth

| Route | Feature | Bugs found | Sev | Correction | Tests | Status |
|---|---|---|---|---|---|---|
| `app/index.tsx` | Role redirect | No-role account looped login ↔ vendor | P0 | `no-access` screen | `auth-and-honesty` | Fixed (runtime) |
| `app/login.tsx` | Sign in | Unlabeled inputs; dev copy ("Live API: on") in production; narrow `returnTo` | P1 | Labels, `__DEV__`-only copy, pending destination | `auth-and-honesty` | Fixed (runtime) |
| Deep links / `returnTo` | All | Destination lost through login (3 causes) | P1 | Pending-destination queue with role check | `auth-and-honesty`, unit | Fixed (runtime) |
| `app/apply/[opportunityId].tsx` | Apply | Terms checkbox, category chips and submit had no role/state | P2 | Roles + checked/selected state | `cross-role` | Fixed (runtime); type scale still raw (P2 open) |
| `app/opportunity/[opportunityId].tsx` | Detail | Favorite unguarded; uncached images; Apply shown after applying | P1 | Guard, `EventImage`, "You applied" → booking | `vendor`, `guards-and-actions` | Fixed (runtime) |
| `app/pass/[applicationId].tsx` | Pass, pay | Raw font sizes | P2 | Dynamic Type caps via `AppText` | `cross-role` | Partly fixed |
| `app/stripe-redirect.tsx`, `lib/payments/stripe-checkout.ts` | Checkout return | Web checkout never invalidated bookings → stale "Payment due" | P1 | Invalidate on return and after handoff | `cross-role` (web); Device check (native) | Fixed (runtime web) |
| Root `_layout.tsx` | Providers | `StripeProvider` mounted with a placeholder key | P1 | Pass-through (no native Stripe) | static | Fixed |
| `withFixtureFallback` | All live reads | **Live failures showed sample data** (Requests, Wallet pay row, Find, Growth, organizer Home, map pins) | P0 | Rethrow when live | `auth-and-honesty`, unit | Fixed (runtime) |
| Push routing | Push taps | No role check; destination lost through login; server `href` (`/requests`) not an app route so info-request taps did nothing; declines opened the list not the booking | P1 | Typed routes win, server href only if a real route, queue checks role | unit (37) | Fixed (unit); Device check (delivery) |
| `components/notifications-screen` | Inbox | Server inbox rows (web links) showed "no longer available"; chips not announced; ScrollView list | P1 | Push-rule fallback, `FilterChipRow`, `ScreenList` | unit, baseline | Fixed (unit + runtime load) |
| `app/(vendor)/_layout.tsx` | Vendor tabs | Badge fetch + 60 s polling ran before the role redirect → organizer-only accounts called `/applications/vendor` (403) | P1 | Gate on vendor role | `organizer-vendors-money` (no failed REST calls) | Fixed (runtime) |
| Error copy | All | Raw "Network request failed", WAF text | P1 | Friendly mapping | unit | Fixed (unit) |
| Session | All | `expires_at` ignored | P2 | Expire on load + foreground | unit | Fixed (unit) |
| `request-cache` | All | Read in flight during a write was stored as the post-write value | P1 | Generation guard | unit | Fixed (unit) |
| Feedback | All | `Alert` (no-op on web; `Alert.prompt` iOS-only) | P1 | Toast / confirm / prompt host; 0 `Alert` calls remain | all specs | Fixed (runtime) |

### Vendor — tabs Home · Explore · Bookings · Wallet · Profile

| Route | Bugs found | Sev | Correction | Tests | Status |
|---|---|---|---|---|---|
| Home | 22 calls; tutorial modal blocked first view; not stage-aware | P2 | Next-step card, event-day card, lifecycle stats | `vendor` | Fixed (runtime) |
| Explore | Load more advanced page on failure; unguarded heart; older search could overwrite newer; ScrollView list | P1 | Page on success, guard, abort stale search, `ScreenList` | `vendor`, `guards-and-actions` | Fixed (runtime) |
| Bookings | Filters not lifecycle-based; no booking detail; UTC date showed previous day | P1 | Lifecycle filters, `booking/[id]` timeline, local dates | `vendor`, `cross-role`, unit | Fixed (runtime) |
| Wallet | Sample dues on failure; iOS-only dispute prompt | P0 | Honest errors, prompt sheet, Due/History/Receipts | `vendor`, `auth-and-honesty` | Fixed (runtime) |
| Profile hub (`more`) | Errors swallowed; sign out / switch unguarded | P1 | Error banner, confirm, single-flight | `guards-and-actions` | Fixed (runtime) |
| Business profile | Edit card vanished on load failure; logo double tap | P1 | Editor with validation, dirty guard | `vendor` | Fixed (runtime) |
| Documents | Parallel uploads | P1 | One upload at a time | — | Fixed (code) |
| Favorites | "Remove" toggled (could re-save) | P1 | `setVendorFavorite(id, wanted)` | `guards-and-actions` | Fixed (runtime) |
| Growth | Sample data on failure | P0 | Honest errors | `auth-and-honesty` | Fixed (runtime) |
| Event passes | 11 s | P2 | — | baseline | Fixed (1.3 s) |
| Saved searches | Server 500 (B1); delete spinner on the wrong button | P1 | B1, per-action busy state | `guards-and-actions` | Fixed (runtime) |
| Referrals | Created a code on every load | P1 | Create on tap | baseline | Fixed (runtime) |

### Organizer — tabs Home · Events · Vendors · Money · More

| Route | Bugs found | Sev | Correction | Tests | Status |
|---|---|---|---|---|---|
| Home | 35 calls; payment due computed on device (disagreed with Money); "Create your public profile" on fetch failure; raw intelligence keys; sample data on failure | P0 | Server counts, honest unknown, friendly labels | `organizer-events` | Fixed (runtime) |
| Events | N+1 roster calls; status from date only | P1 | Server counts + listing status, Live/Drafts/Past | `organizer-events` | Fixed (runtime) |
| Event workspace | `local-…` IDs; fields could not be cleared; spots below held; no sections | P1 | Overview/Slots/Applications/Vendors/Payments/Day-of/Packet | `organizer-events`, `guards-and-actions` | Fixed (runtime) |
| Create event | Device-only drafts; hidden fields; Back lost edits | P1 | 6 steps, autosave, server draft (B5) | `organizer-events` | Fixed (runtime) |
| Vendors inbox | Fixed reject reason; intel race; invite without event; old Approved queue | P1 | Decline reason, keyed intel, invite sheet, Invites tab, lifecycle filters, deep-linkable filter | `organizer-vendors-money`, `cross-role` | Fixed (runtime) |
| Request cards | Identical "Approve <vendor>" labels for different bookings | P2 | Category in action labels | `cross-role` | Fixed (runtime) |
| Vendor profile | Dead Instagram row; no actions | P2 | Decision panel, notes, links | — | Fixed (code) |
| Money | Ignored `money_categories`; no payout history/cancel; device-side Connect state; shared spinner; "send a reminder" with no action; cash out blocked in treasury mode (desktop allows it); drafts crowded real events out of "By event" | P1 | Server categories with hints, payout history + cancel, real Connect states with plain-language requirements, per-button busy, treasury rule matches server | `organizer-vendors-money`, unit | Fixed (runtime) |
| Check-in *(new)* | No mobile check-in | P1 | Roster with counts/search/filters, typed pass code, owner/ops override with reason, already-checked-in state, "not recorded" when offline, booth assign/move | `organizer-checkin`, unit | Fixed (runtime) |
| Check-in roster mapping | Checked-in vendors vanished from the list and from "confirmed" (server moves them to a `checked_in` group) | P1 | Read every server group; booked = status | unit | Fixed (runtime) |
| More | Flat list | P2 | Organization / Event tools / Payments / Notifications / Help / Account | `organizer-vendors-money` | Fixed (runtime) |
| Public profile | Raw buttons; logo double tap; blocking alerts | P2 | Design system, upload guard, inline validation | `organizer-vendors-money` | Fixed (runtime) |
| Packet | Raw inputs | P2 | `PacketForm` in the workspace | `organizer-events` | Fixed (runtime) |

### Design system

| Area | Finding | Sev | Correction | Status |
|---|---|---|---|---|
| Typography | 321 raw `fontSize`; no Dynamic Type limit | P2 | `AppText` caps on all text (codemod, 50 files); type scale tokens | Partly fixed (raw sizes remain in older screens) |
| Feedback | 50 `Alert` calls | P2 | Toast / confirm / prompt host | Fixed |
| Images | RN `Image` | P2 | `EventImage` | Fixed |
| Lists | No `FlatList` | P2 | `ScreenList` on Explore, Bookings, Notifications, Events | Fixed; Vendors inbox/Discover stay paginated scroll views (20 per page) |
| Motion | No reduced motion | P2 | Skeleton / collapsible / press scale honour it | Fixed |
| Accessibility | Few labels/roles; 36–40 pt targets | P2 | 44 pt targets, labels, roles, `aria-selected` on chips | Fixed (web); VoiceOver = Device check |
| Tab bar | Labels clipped on iPhone 15 | P2 | Height fix, no font scaling in tabs | Fixed (runtime) |
| Dark mode, haptics | Not supported | P3 | Next native build | Deferred |

## 3.1 audit (Nov 19, 2026)

Source: fresh screenshots of every route (`scripts/e2e/reports/mobile-shots/v31-before/`), cold metrics (`mobile-v31-before-{vendor,organizer}.json`), and a new warm tab-switch pass (`mobile-v31-before-warm-*.json`). Status is updated as each stage lands.

### Warm tab switching (in-app, cache warm), before 3.1

| Switch | Calls | Notes |
|---|---|---|
| Vendor → Explore | 2 | favorites + search |
| Vendor → Wallet | 6 | first visit; cached after |
| Vendor → Profile | 2 | push preferences + profile edit, every visit |
| Organizer → Home (return) | 6 | every focus forces intel, widgets, checklist, money |
| Organizer → Money (return) | 4 | every focus forces balances, dashboard, activity, payouts |
| Organizer → More | 1 | push preferences, every visit |

### Findings

| # | Screen | Finding | Sev | Stage | Status |
|---|---|---|---|---|---|
| A1 | Vendor Home | `/applications/vendor` requested twice (Home forces a refetch while the tab badge fetch is in flight) | P2 | 1 | Open |
| A2 | Vendors inbox / Discover | Scroll views, manual "Load more", no stale-search cancel, no debounce | P2 | 1 | Open |
| A3 | Pass, Apply | 28 and 21 raw font sizes; hand-rolled buttons and inputs | P2 | 1 | Open |
| A4 | E2E | 18 skips: 14 are writes that run once per seed, 4 are metrics; the approval-failure and session tests write nothing yet skip on SE / Pro Max | P2 | 1 | Open |
| A5 | Explore / Home rails | Cards show a placeholder although the server sends `featured_image`; the card only reads `gallery` | P1 | 3 | Open |
| A6 | Explore | Search, filters, near me and view toggle take half the first screen before any result | P2 | 3 | Open |
| A7 | Growth, Reviews, Documents, Operations, Referrals | Older styling: raw font sizes, spinner panels instead of skeletons, plain-text statuses, oversized stacked buttons | P2 | 2–3 | Open |
| A8 | Documents | Status is plain text with no badge or expiry date | P2 | 3 | Open |
| A9 | Organizer Home | Hero card repeats the first "Needs attention" item | P2 | 4 | Open |
| A10 | Organizer Home, Money | Every return to the tab forces 4–6 requests | P2 | 7 | Open |
| A11 | Events list | Dates shown raw (`2027-01-04`) | P2 | 4 | Open |
| A12 | Vendors request card | "$125 due" shown on requests still under review; nothing is due before approval | P1 | 4 | Open |
| A13 | Profile hubs (vendor and organizer More) | Push preferences reloaded on every visit | P3 | 7 | Open |
| A14 | Opportunity detail | 23 raw font sizes | P2 | 2 | Open |

### Interaction inventory (per screen: tap targets / domain calls in code)

Counted from the code (`onPress` handlers and `fetch|submit|update|create|delete|cancel|invite|review|assign|check|pay|upload|save|publish|set*` calls). Stage 6 verifies each against WordPress.

| Screen | Tap handlers | Domain calls | Raw font sizes |
|---|---|---|---|
| Organizer event workspace | 19 | 53 | 3 |
| Organizer launch | 16 | 35 | 4 |
| Organizer Home | 13 | 28 | 3 |
| Explore (`find`) | 13 | 35 | 11 |
| Pass | 10 | 23 | 28 |
| Vendor profile editor | 9 | 27 | 3 |
| Organizer vendor profile | 9 | 25 | 4 |
| Apply | 8 | 23 | 21 |
| Opportunity detail | 7 | 24 | 23 |
| Organizer Money | 7 | 37 | 3 |
| Wallet | 7 | 25 | 0 |
| Vendor Home | 6 | 25 | 1 |
| Organizer profile | 6 | 22 | 0 |
| Check-in | 5 | 30 | 0 |
| Documents | 5 | 26 | 12 |
| Growth | 5 | 11 | 21 |
| Vendors inbox (in components) | — | 55 | 7 (Discover) |

## Next native build (not possible over the air)

`expo-camera` (QR scanning on the check-in screen; typed pass codes work now), `expo-image-picker` (camera roll + cropping for logos and event covers), `expo-haptics`, Sentry (`@sentry/react-native`), removing `@stripe/stripe-react-native` and its config plugin, dark mode (`userInterfaceStyle`), explicit iOS deployment target (`expo-build-properties`), and a server endpoint for event cover/gallery upload from the app.

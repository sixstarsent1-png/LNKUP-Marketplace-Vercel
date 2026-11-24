# Mobile Vendor OS 2.0: results, release and rollback

Everything below was run locally against wp-env (`localhost:8888`) and Expo web (`localhost:8081`) with the E2E seed. Device-only behaviour was not tested on hardware: native maps, camera and file pickers, push delivery, universal links, Stripe PaymentSheet and safe-area insets on physical notches.

## Before and after

Screenshots are in `screenshots/before/` (56) and `screenshots/after/` (68, including the new detail screens), at 360x800, 390x844, 393x852 and 430x932. The `after` run asserts no horizontal overflow and no "Event details unavailable" on every screen and viewport (`tests/mobile/vendor-v2-shots.spec.ts`).

| Screen | Before (390x844) | After (390x844) |
|---|---|---|
| Home | "LNKUP" eyebrow and title, a large pay hero, a duplicate "Your next steps" list, a React duplicate-key error toast, and recommendations below the fold | Search prompt; one "Needs action" list with each event named; next-event spotlight with countdown and pass; grouped Recommended rail on the first screen; no errors |
| Explore | Header stack about 45% of the screen; one card per opportunity; "N opportunities" counted options; Details and Apply buttons on every card | Pinned compact search, filter and map controls with category chips; one card per event; "17 events · 19 vendor options" from server totals; the whole card opens the event |
| Event detail | Single opportunity; tall placeholder hero; "5/5" stat; unlabelled deadline chip; Submit-style CTA | Event identity; vendor-option radio group with its own price, "N of N spaces available" and "Apply by …"; organizer-estimated attendance labelled; action reflects the vendor's existing application |
| Bookings | All / Needs action / Applications / Upcoming / Past by status only | Action needed / Applications / Upcoming / Past (+ Needs review) by `event_state` and `payment_context`; past-event invoices labelled; pass only when the credential is active |
| Wallet | `$229....` truncated tile; History from the activity event log; receipts "unknown" | Dark summary with the full amount and booth spend labelled as spend; Due split into confirm-your-booth versus past-event invoices; History from the ledger, grouped by date, with a detail screen; Documents show event, date, amount and real status, with a receipt screen |
| Profile | Tab labelled "Passport"; pay prompt; signed-in email inside the public card | Tab "Profile"; business identity (logo or initials, category, place, completion) with public actions; grouped tools; signed-in account details only under Account |

## Tests run

| Suite | Result |
|---|---|
| `scripts/e2e/php/mobile-vendor-contract-test.php` (new) | 44 assertions, all passed |
| `booking-application-os-test`, `financial-lists-test`, `financial-contract-test`, `financial-security-test`, `comms-lifecycle-test`, `comms-engine-test` | financial-lists, financial-security and comms suites passed. B-14 (booking OS) and the contract parity fixture fail for reasons unrelated to this change; see `DATA_INTEGRITY.md`. |
| Mobile Jest (`npm test`) | 29 suites, 242 tests passed (19 new in `__tests__/vendor-os-v2.test.ts`) |
| `npm run typecheck` | Clean |
| `scripts/ui-polish-verify.mjs` | Passed. It had 4 failures at HEAD: 3 stale snippets plus the retired `OpportunityCard` entry. |
| `tests/mobile/vendor-os-v2.spec.ts` (new) | 7 passed: journeys B, F, H, I and N, ownership checks, and Home with no stale pay prompt or duplicate-key error |
| `tests/mobile/vendor.spec.ts` (updated for the redesign) | 8 passed. The "opportunity you applied to" test now finds the opportunity from the vendor's own bookings; its hardcoded default ID 57 is a WordPress page in this database. |
| `tests/mobile/baseline.spec.ts` (vendor routes, warm tabs) | Passed after the change; the warm-tab test failed before it because no tab was labelled "Profile" |
| `tests/mobile/layout.spec.ts` (vendor and organizer tabs) | 2 passed: no overflow or clipped labels in either workspace |
| `tests/mobile/sweep-errors.spec.ts` | 2 passed (profile save, organizer cash out). 3 failed because their hardcoded seed IDs don't exist in this database (application 64 and opportunity 58 are missing; event 56 is a page). They need `PLAYWRIGHT_*` IDs from a fresh seed. |

Journeys from the brief that were not automated: A (registration), D (draft resume across restart), E (organizer approval, then a test-mode payment), J (packet acknowledgement), K (check-in scan), L (document reuse), O (workspace switch with an organizer account), P (push). The existing apply-draft, checkout and check-in specs under `tests/e2e` cover the WordPress side of D, E and K. They were not re-run here.

## Performance (local wp-env, so noisy)

API requests per cold route load, from `scripts/e2e/reports/mobile-v2-{before,after}-vendor.json`:

| Route | Before | After |
|---|---|---|
| Home | 15 requests, 2 page errors | 14 requests, 0 errors |
| Explore | 9 | 3 (match rails removed, grouped search) |
| Wallet | 7 | 6 (summary and dashboard were the same handler) |
| Profile hub | 3 | 5 (adds readiness for completion) |

Warm tab switches after the change take 90–166 ms with 0–5 requests (`mobile-v2-after-warm-vendor.json`). Wall-clock route times varied run to run by several seconds on unchanged endpoints; for example `/vendor/profile` alone took about 2.7s. They are not presented as an improvement or a regression. Direct timings: `/applications/vendor` 1.0–2.9s, grouped search 1.8–2.2s, ungrouped search 2.3s.

## Release

**What ships how:**

| Change | Delivery |
|---|---|
| WordPress plugin (`lnkup-marketplace`) and `lnkup-communications` | Normal plugin deploy. Additive routes and fields; no schema changes and no migrations. |
| Mobile app | JavaScript-only. OTA-compatible with runtime 1.0.1; no new native modules or config. |

**Order:**

1. Deploy the plugins to staging and run `wp eval-file .../mobile-vendor-contract-test.php` there.
2. On staging, run `wp lnkup bookings repair-events --dry-run`. Review the relinked count, the snapshot count and the unrecoverable IDs.
3. Run `wp lnkup bookings repair-events` (it only writes `_lnkup_event_id` for relinks and `_lnkup_event_snapshot`).
4. Repeat steps 1–3 on production.
5. Publish the OTA to `preview` (`npm run update:preview`) and check the screens in a preview build.
6. Publish the production OTA (`npm run update:production`) only after approval.

**Compatibility:** Old app builds don't send `group_by` and ignore the new fields. The changes to existing responses are:

- `event_name` is now resolved for more bookings. Fewer bookings show the "Event details unavailable" placeholder; unrecoverable ones still do.
- Receipt and ledger `event_title` is empty instead of the placeholder when unrecoverable. The web dashboard already falls back to the receipt number.
- City and state on index rows are display-normalized.

## Rollback

- **Mobile:** republish the previous OTA to the channel. No local data migrations were added; request caches are memory-only.
- **Plugins:** redeploy the previous plugin build. The new routes disappear, and old app builds never used them.
- **Data:** the repair command's writes are additive and harmless after a rollback (`_lnkup_event_snapshot` is ignored by old code; relinked `_lnkup_event_id` values point at the opportunity's own event). No statuses, payments, invoices or receipts are modified.

## Changed files (this initiative)

**Backend:**

- New: `includes/class-vendor-booking-contract.php`, `includes/class-event-groups.php`.
- Updated:
  - Plugin core: `lnkup-marketplace.php` (loader and init), `includes/helpers.php`.
  - Discovery and marketplace: `includes/class-discovery.php`, `includes/class-marketplace-rest.php`.
  - Bookings: `includes/class-applications-rest.php`, `includes/class-vendor-dashboard-data.php`.
  - Financial: `includes/class-receipts.php`, `includes/class-transactions.php`, `includes/class-financial-rest.php`.
  - Passes: `includes/class-packets.php`.
  - Communications plugin: `lnkup-communications/includes/class-comms-context.php`.

**Mobile:**

- New routes: `app/wallet/transaction/[id].tsx`, `app/wallet/receipt/[id].tsx`.
- New components:
  - `components/marketplace/{event-card,event-rail,vendor-event-detail}.tsx`
  - `components/vendor/{action-center,upcoming-spotlight}.tsx`
  - `components/wallet/transaction-row.tsx`
- New libraries:
  - `lib/api/{event-groups,vendor-transactions}.ts`
  - `lib/vendor/{booking-lifecycle,event-group-presentation}.ts`
  - `lib/notifications/group-notifications.ts`
- Rewritten screens:
  - `app/(vendor)/{index,find,requests,wallet,more,operations,favorites}.tsx`
  - `components/{filter-sheet,wallet-summary}.tsx`
- Updated:
  - Routes: `app/event/[eventId].tsx`, `app/opportunity/[opportunityId].tsx`, `app/apply/[opportunityId].tsx`, `app/booking/[applicationId].tsx`, `app/pass/[applicationId].tsx`, `app/(vendor)/{_layout,invitations,passport}.tsx`.
  - Components: `components/{screen,request-card,map-pin-preview,next-steps-card,notifications-screen}.tsx`, `components/ui/event-image.tsx`.
  - API and data: `lib/api/{marketplace,map-pins,types,vendor-requests,vendor-wallet,vendor-pass,vendor-operations}.ts`, `lib/data/fixtures.ts`.
  - Other: `lib/vendor/{vendor-stage,booking-timeline}.ts`, `lib/hooks/use-account-actions.ts`, `lib/navigation/pending-destination.ts`, `lib/analytics/mobile-events.ts`, `scripts/ui-polish-verify.mjs`.
- Deleted: `components/opportunity-card.tsx`, `components/match-rails.tsx`.

**Tests and docs:**

- Tests: `scripts/e2e/php/mobile-vendor-contract-test.php`, `tests/mobile/{vendor-os-v2,vendor-v2-shots}.spec.ts`, `tests/mobile/vendor.spec.ts`, `__tests__/vendor-os-v2.test.ts`.
- Docs: `docs/mobile-vendor-v2/*`, `docs/SYSTEM_OVERVIEW.md` §14.3.

The working tree also has uncommitted changes from other workstreams (web Vendor and Organizer OS, location, marketing). They are not part of this initiative. Commit this change set selectively.

## Remaining limitations

- **Production data:** the screenshot symptoms come from production rows that weren't available locally. The repair dry run on staging or production is the step that confirms how many bookings get relinked or snapshotted.
- **Native-module features not shipped:** haptics, a native bottom-sheet library, FlashList, screen-brightness boost, and Apple Wallet passes. Each needs a binary build.
- **Pricing on event detail:** shows the organizer booth fee and states that marketplace fees appear before payment. A full fee-engine breakdown per option needs a server field that doesn't exist yet.
- **Saved favorites:** still per vendor option (no migration). A saved option that closes drops out of the list, as before.
- **Not changed in this pass:** saved-search editing, collections, organizer follows and profitability reporting.
- **Partially automated:** the stepped application UI keeps the existing single-screen form with autosaved drafts, review terms and idempotent submit. The new success screen and fee labelling were added. A multi-step wizard wasn't built.

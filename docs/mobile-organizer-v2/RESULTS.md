# Organizer Mobile OS 2.0 — Results

All 14 stages are implemented. JavaScript only (OTA on runtime 1.0.1), with no camera scanner and online-only check-in. Plugin changes are additive. Nothing has been published: no plugin deploy, no OTA, no store build.

## Measured (local, iPhone 15 profile, `e2e_org_a`)

| Route | Before | After |
| --- | --- | --- |
| Home cold load | 23 requests, still loading at 22.1 s | 5 requests, settled in 3.0 s |
| Money cold load | 14 requests (8 one-per-event), 14.7 s | 6 requests, 2.3 s |
| Events cold load | 3 requests | 2 requests |
| Warm tab switch to Money | 1 request | 4 requests (balances, payouts, portfolio money — no per-event calls) |

Sources: `scripts/e2e/reports/mobile-org-v2-{before,after}-organizer.json` and `-warm-organizer.json`. Screenshots: `screenshots/before/` (2 viewports, 13 screens) and `screenshots/after/` (4 viewports × 18 screens). The after capture also asserts no developer text, no raw `YYYY-MM-DD` dates and no horizontal overflow on every screen at every viewport.

## What changed

**Plugin (`lnkup-marketplace`)**
- New `LNKup_Organizer_Mobile_REST` (`includes/class-organizer-mobile-rest.php`):
  - `GET /organizer/home-summary`, `GET /organizer/mobile/events`.
  - `GET /organizer/events/{id}/operations-summary`, `GET /organizer/events/{id}/score`.
  - `GET /operations/events/{id}/checkin-summary`, `GET /organizer/applications/{id}/review`.
  - `GET /organizer/mobile/invites`, `POST /crm/invites/{id}/remind|cancel`.
  - `GET /financial/organizer-payouts/preview` (read-only).
- `LNKup_Organizer_Payouts`:
  - The instant fee rate now comes from the `organizer_instant_fee_rate` setting. The default stays 0.015, and an admin field accepts a percent.
  - The provider and fee logic is shared by cash-out and preview.
  - Payout rows add `reference`, `source` and `updated_at`.
- `LNKup_Financial_Reports::for_event()` adds `outstanding_receivables` and `outstanding_invoice_count` (open invoices only). `pending_revenue` is unchanged.
- `LNKup_Vendor_Invites`: cancel, remind (rate-limited), organizer rows and overview, a self-invite guard, and `_lnkup_responded_at`.
- Self-exclusion: `lnkup_get_discoverable_vendors` and match candidates exclude the organizer's own vendor account.
- `duplicate_event`: a copy in a new city doesn't keep the source event's `lnkup_market` terms. `scripts/audit-event-locations.php --fix-markets` cleans older copies; it runs as a dry run by default.
- Staff access:
  - The CRM roster and check-in / no-show routes accept staff with the matching per-event capability.
  - `/organizer/events/{id}/summary` accepts `view_roster` staff and returns money only with `view_financials`.
  - This is a small, additive edit to the in-progress web `class-organizer-presentation.php`.
- `LNKup_Event_Score::compute` exposes its `weights`.

**Mobile (`lnk-up-marketplace-mobile`)**
- **Home:** one summary call; scoped KPIs; state-driven quick actions (check-in shows only on the event day or the day before); a single launch-progress model; Action Center rows that open native screens; next / upcoming events with media; recruitment gaps; a "pick up where you left off" row.
- **Events:**
  - The list has server search, filters, sorts and a row / card toggle.
  - Event detail has a compact header, event switcher and five tabs (old `?tab=` links still work); publishing in the app uses a checklist that follows the server's publication rule; event score factors are explained; duplicating confirms the date and location.
- **Review:** new `vendors/review/[applicationId]` with a track record, booking, answers, document statuses (no files), server-written approval consequences, Approve / Waitlist / Ask / Decline, server-confirmed results, and "Review next". Queue rows open it. The vendor profile gains Waitlist and the same approval modal.
- **Discovery and CRM:** "No completed events on LNKup yet" instead of 0, short category names, a category focus in Find vendors, Invitations 2.0 (counts, detail, remind, cancel), and "Save to a list".
- **Money:**
  - Overview, Activity, Payouts and Invoices segments. Current balances, settling money and unpaid booth fees are labelled separately.
  - Cash out has its own screen (server fee preview, revalidation before sending, a single `client_token`, a "requested" state). Payout detail shows the reference and a timeline. Event financials show open invoices and a payment reminder through event communications.
- **Operations:** a workspace with lifecycle mode, KPIs, tasks marked with who owns them (organizer or vendor), packet tracking, readiness, logistics and team.
- **Check-in:**
  - Rebuilt around finding the vendor, with the pass code entered in a sheet.
  - Counts use eligible vendors as the denominator, and blocked vendors are listed separately.
  - Result sheets cover each outcome, and the override still requires a written reason.
  - `NetworkBanner` and write actions are disabled while offline; nothing is queued.
- **Booths and logistics:** booth list, assign queue, booth creation (duplicates refused by the server), read-only map with an exact web handoff, load-in / parking, and a packet overview with what vendors see.
- **Tours:** list, summary and stop switcher; platform-fee fields are not mapped.
- **More:** Organization HQ, a Team screen, notification device state (developer text removed), and organizer referral terms. Vendor copy is unchanged.

## Tests

| Suite | Result |
| --- | --- |
| PHP `scripts/e2e/php/organizer-mobile-contract-test.php` | 82 assertions pass |
| PHP regression (cash-out, security, fee privacy, invites, matching, onboarding, organizer OS waves) | pass, except the known items below |
| Expo Jest | 31 suites / 268 tests pass (new `organizer-os-v2.test.ts`, 23 tests) |
| `npm run typecheck`, `ui-polish-verify` | pass |
| Playwright `organizer-os-v2.spec.ts` (journeys A, B, D–P, S–V) | pass (J skips when the seed has nothing to cash out) |
| Playwright organizer specs updated for the new screens | pass; three check-in override tests skip because the seeded booking no longer requires documents |
| Full mobile Playwright suite, iPhone 15 (93 tests) | 83 passed, 5 skipped, 5 failed on the first full run. Four were fixed (cash-out error path for the new flow, stale IDs, the vendor heart label and a duplicate "You applied" match) and rerun green. The remaining failure, `sweep-errors` "failed checkout handoff", needs `PLAYWRIGHT_PENDING_PAYMENT_APPLICATION_ID`, which the seed doesn't export (default `64` is stale). |

`playwright.mobile.config.ts` now loads the seeded IDs from `scripts/e2e/.env.e2e`. Several organizer and guard specs had been running against stale default IDs (56, 57, 59, 60, 66).

Known failures that predate this work:
- `sweep-errors` "failed checkout handoff": a stale default application ID (see above).
- `organizer-activation-test`: `status_label` comes from the uncommitted web discovery change.
- The financial-contract parity fixture (`pending_release` and instant payout keys).
- `booking-application-os-test` B-14.

## Release

1. Deploy the plugin first. Every route is additive, and old app builds keep using `/organizer/portfolio`, `/organizer/onboarding` and the old Money / check-in calls.
2. Run `wp eval-file scripts/audit-event-locations.php` (dry run) and review the output before `--fix-markets`.
3. Publish a preview OTA to the preview channel and smoke test Home, review, cash-out preview and check-in on devices.
4. Publish the production OTA only with approval.

Screens fall back to the previous calls when a route is missing: Home, Events, Invites and Check-in all check for it.

## Rollback

- **Mobile:** republish the previous OTA update group. The new routes are unused by older bundles.
- **Plugin:** revert the release. Removing `class-organizer-mobile-rest.php` disables only the new routes.
  - The fee setting defaults to the old 1.5%.
  - `pending_revenue`, payout rows and invite statuses keep their meaning.
  - Cancelled invites keep their `cancelled` status, which older code treats as closed.

## Limitations (by decision)

- No QR scanner (no native modules over OTA). Check-in is lookup-first plus a typed pass code.
- Online-only check-in. The roster stays readable offline, and nothing is queued.
- Map editing, bulk tour creation, staff management, reports and exports stay on the web, with exact links.
- Document files aren't viewable in the app. The workspace / documents routes are behind flags that are off; the review screen shows statuses only.

## Observations for other teams

- `/portfolio/tours/{id}/summary` returns `platform_fees` and `organizer_net_est` to organizers, and the web portfolio shows a "Platform fees" figure. The organizer fee-privacy filter only covers `/financial/*`. Mobile doesn't map these fields, and server policy was left unchanged.
- The web `financial.js` still has its own instant-fee copy; it could read `organizer_instant_fee_rate`.

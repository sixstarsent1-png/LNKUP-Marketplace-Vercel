# Organizer Mobile OS 2.0 — Audit

Baseline captured on Nov 24, 2026 against local WordPress (`http://localhost:8888`) and Expo web (`http://localhost:8081`), user `e2e_org_a`. Screenshots: `screenshots/before/` (390x844 and 360x800). Metrics: `scripts/e2e/reports/mobile-org-v2-before-organizer.json` and `mobile-org-v2-before-warm-organizer.json`.

## Route inventory

| Route | File (lines) | Requests on cold load | Classification |
| --- | --- | --- | --- |
| Home `/(organizer)` | `index.tsx` (825) | 23, not settled after 22 s | Native daily — rebuild on one summary call |
| Events `/(organizer)/events` | `events/index.tsx` (184) | 3 | Native daily — add search, filters, media |
| Event detail `/(organizer)/events/[eventId]` | `events/[eventId].tsx` (696) | per tab | Native daily — 7 sub-tabs collapse to 5 |
| Check-in `/(organizer)/events/checkin` | `events/checkin.tsx` (433) | roster + booths | Native daily — rebuild lookup-first |
| Event comms `/(organizer)/event-comms/[eventId]` | `event-comms/[eventId].tsx` (285) | comms history | Native |
| Vendors `/(organizer)/vendors` | `vendors/index.tsx` (544) | 2 | Native daily — queue rows + review screen |
| Find vendors `/(organizer)/vendors/find` | `vendors/find.tsx` (337) | match | Native |
| Vendor profile `/(organizer)/vendors/[profileId]` | `vendors/[profileId].tsx` (716) | profile + notes | Native |
| Money `/(organizer)/money` | `money.tsx` (689) | 14 (8 are one-per-event financials) | Native daily — segments, cash-out flow |
| Launch `/(organizer)/launch` | `launch.tsx` (523) | 1 | Native — create/duplicate |
| Packet `/(organizer)/packet` | `packet.tsx` (32) | form | Native |
| More `/(organizer)/more` | `more.tsx` (267) | 2 | Native — Organization HQ |
| Profile, notifications, notification settings, referrals | thin wrappers (5–257) | 1–3 | Native |

Missing native destinations (daily tasks today routed to web or absent): application review screen, cash-out confirmation with fee preview, payout detail, event financials, operations workspace, booth/map/packet/logistics views, tours.

## Screenshot-by-screenshot root causes

| Screen | What is wrong | Root cause |
| --- | --- | --- |
| Home | "Setup 83% complete" while Next steps shows a different percentage | Two models: `/organizer/onboarding` (6 steps) and `/organizer/activation` (6 different stages); a third list in `/crm/launch-checklist` |
| Home | "Continue on LNKup web" for a setup step | No native setup destination |
| Home | "Requires attention" lists raw draft titles (`E2E Draft 1795518371696`) and repeats the same action | Client builds the list from launch-checklist rows, not the presentation `action_queue()` |
| Home | 23 requests, unsettled | Each card fetches its own endpoint |
| Events | Upcoming order differs from Home | `/crm/events/invite-options` sorts by creation; Home slices 3 unsorted |
| Event detail | Raw `2027-03-13` date in hero | No shared date formatter on organizer screens |
| Check-in | "0 of 1" with an unpaid vendor counted as expected | Denominator is booked vendors; server rule is `LNKup_Checkins::evaluate()` |
| Check-in | QR icon on a manual pass-code button | Decorative scan affordance with no scanner |
| Money | "Pending" means Stripe settlement; event "pending" means unpaid invoices | Same word for two different money states; `for_event()` fallback also counts refunded/void payments |
| Money | "about 1.5%" instant fee | Hardcoded copy; real rule applies only to LNKup-sent payouts, and Connect payouts carry Stripe's fee |
| Money | 8 per-event financial calls | N+1 in "By event" |
| More | Entries duplicate tabs; `@username` shown as identity | No Organization HQ grouping |
| Notification settings | "EAS project ID" text | Developer copy in `components/push-preferences-card.tsx` |
| Referrals | "credit toward booth fees" | Organizers earn a percentage of the platform fee, paid as credit |
| Discover | Organizer's own vendor account can appear | No self-exclusion in discovery, match candidates or invites |
| Invites | No way to remind or cancel | No endpoints |

## Baseline test suites

- Organizer Jest suites in `lnk-up-marketplace-mobile/__tests__` pass (see RESULTS for counts after the change).
- Organizer Playwright specs (`organizer-events`, `organizer-checkin`, `organizer-vendors-money`, `cross-role`, `guards-and-actions`, `sweep-errors`) assert on text and test IDs that this work renames; they are updated in Stage 13.
- Known failures that predate this work: `booking-application-os-test` B-14, the financial-contract parity fixture (`pending_release` and instant payout keys), `sweep-errors` stale IDs 56/58/64, and `organizer-activation-test` "card exposes only public listing fields" (`status_label` added by the uncommitted web Organizer OS 2.0 discovery change).

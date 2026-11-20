# Organizer Experience 2.0 — QA Checklist (42 scenarios)

Environment: local `@wordpress/env` on `http://localhost:8888`, seeded by `scripts/e2e/seed.php`. Payments run in **simulation / Stripe test mode only** — no live charges or production payouts are used for QA.

```bash
npm run e2e:env && npm run e2e:seed
npx wp-env run cli wp eval-file wp-content/lnkup-e2e/probe-organizer.php   # 46 server probes
npx wp-env run cli wp eval-file wp-content/lnkup-e2e/lifecycle.php         # 16-step cross-role path
npm run e2e:run -- tests/e2e/organizer-*.spec.ts tests/e2e/dashboard-consistency.spec.ts --project=chromium
```

Evidence key:

- **Spec**: a Playwright spec in `tests/e2e/`.
- **Probe**: a check in `scripts/e2e/probe-organizer.php`.
- **Lifecycle**: a step in `scripts/e2e/lifecycle.php`.
- **Static**: the code path was read end to end but not executed.
- **Manual**: the scenario needs a person or an external account and was not run here.

Last run: 2026-11-16. Probes 46/46, lifecycle 16/16, organizer specs all passing (see `ORGANIZER_AUDIT_MATRIX.md`).

| # | Scenario | Expected result | Evidence | Result |
|---|---|---|---|---|
| 1 | Register organizer | Account gets `lnkup_organizer` role and lands on the organizer dashboard | Manual (seed creates organizers; no registration spec) | Not run |
| 2 | Complete organization profile | Profile view saves and Home welcome shows the organization name | Spec: `dashboard-consistency` (profile renders); `organizer-home` (welcome) | Pass (render only; save not automated) |
| 3 | Connect payment account | Payout setup starts in Stripe **test mode**; Financial shows "Finish payout setup" until complete | Spec: `organizer-launch-checklist` (payout step links to Financial); onboarding itself Manual | Partial (needs Stripe test keys) |
| 4 | Create an event | Event and its vendor slot are created together and linked | Spec: `organizer-event-create` publishes; Lifecycle 1 | Pass |
| 5 | Save event draft | Event saved as draft, applications not opened | Spec: `organizer-event-create` › saves a draft | Pass |
| 6 | Publish event | Event and slot published and discoverable | Spec: `organizer-event-create`; Lifecycle 2 | Pass |
| 7 | Create initial vendor opportunity | Slot has `_lnkup_event_id` set to the event | Spec: `organizer-event-create`; Lifecycle 2 | Pass |
| 8 | Add a second opportunity | Second slot attached to the same event; replay does not duplicate | Probe: Create Event replay; seed multi-slot event; Spec: partial failure + retry | Pass (wizard multi-slot entry not separately asserted) |
| 9 | Configure booth pricing | Price stored on the slot and used for the payment | Lifecycle 6 and 8 (payment created at slot price, paid) | Pass |
| 10 | Configure vendor categories | Category stored and used for eligibility | Lifecycle 3 (`Food Vendor` application) | Pass |
| 11 | Set application deadline | Deadline saved on the slot | Static (`[data-applications-settings]` → onboarding save) | Static only |
| 12 | Edit event information | Saved changes persist; logistics change detected | Probe: venue change notifies booked vendors; unchanged save does not | Pass |
| 13 | Preview public vendor opportunity | "Preview as vendor" opens the public slot | Spec: `organizer-workspace` › Opportunities tab | Pass |
| 14 | Search vendor directory | Search narrows results; private profiles hidden | Spec: `organizer-crm` › vendor search; Probe: discover q / private | Pass |
| 15 | Invite a vendor | Invite recorded once; duplicate surfaced | Spec: `organizer-crm` › invite twice; Probe: duplicate invite 409 | Pass |
| 16 | Vendor receives invitation | Invited vendor can respond; others cannot | Probe: wrong account refused; responds once | Pass |
| 17 | Vendor applies | Application created as `submitted` | Lifecycle 3 and 3b; Spec: `apply-with-profile` | Pass |
| 18 | Organizer receives application | Appears under "To review" | Spec: `organizer-workspace` › drawer; `organizer-info-request` | Pass |
| 19 | Organizer reviews vendor profile | Drawer shows profile, history, and decisions | Spec: `organizer-workspace` › application drawer | Pass |
| 20 | Request missing documentation | Status becomes "More info needed"; vendor notified | Spec: `organizer-info-request` › organizer asks; Probe; Lifecycle 4 | Pass |
| 21 | Vendor submits requested information | Reply returns application to review; history kept | Spec: `organizer-info-request` › vendor replies; Probe; Lifecycle 5 | Pass |
| 22 | Organizer approves vendor | Spot held; full slot refuses further approvals | Lifecycle 6, 6b, 7; Probe: approve on full slot | Pass |
| 23 | Correct payment request is generated | One payment at slot price; status `payment_required` | Lifecycle 6 | Pass |
| 24 | Vendor pays successfully | Payment `paid`, booking confirmed (simulation / test mode) | Lifecycle 8 (simulation) | Pass (simulation; real Stripe test card Manual) |
| 25 | Organizer Financial Center updates | Categories gross, fees, refunds, net, pending, available, in transit, paid out | Spec: `organizer-financial`; Probe: money categories | Pass |
| 26 | Payment appears in ledger | Event Financials tab shows collected and outstanding | Spec: `organizer-workspace` › Financials tab | Pass |
| 27 | Vendor packet unlocks | Packet published for paid vendor | Lifecycle 9; Spec: `organizer-checkin` › packet stage | Pass |
| 28 | Organizer assigns booth | Booth on vendor pass; moving frees old booth; unbooked refused | Lifecycle 10; Probe: booth move / not_booked | Pass |
| 29 | Organizer updates event instructions | Booked vendors notified once | Probe: venue change notifies (notified=2, repeat=0) | Pass |
| 30 | Vendor receives updated information | Notification delivered to booked vendors | Probe (notification count); vendor inbox UI Static | Pass (server); UI Static |
| 31 | Vendor checks in using authorized pass | Paid vendor checks in once; unpaid gated | Lifecycle 11; Probe: unpaid gated | Pass |
| 32 | Organizer sees checked-in status | Scan page counts and lookup update | Spec: `organizer-checkin` › counts and lookup | Pass |
| 33 | Organizer initiates an authorized refund | Refund via Financial Service; capped; single pending | Lifecycle 12; Probe: refund suite (7 checks); Spec: foreign refund refused | Pass |
| 34 | Inventory adjusts per booking policy | Cancel releases the spot; waiting vendor can be approved | Lifecycle 13 and 14 | Pass |
| 35 | Organizer views payout status | Payout list and status with friendly copy | Spec: `organizer-financial` › tabs, no jargon; Probe: cashout replay | Pass (real payout Manual in test mode) |
| 36 | Organizer reviews event analytics | Funnel applications → check-ins, owner-scoped | Spec: `organizer-home` › funnel; Probe: funnel monotonic | Pass |
| 37 | Organizer invites vendor to a future event | "Reinvite to an event" / Rebook from past event | Static (CRM profile reinvite, `data-rebook-vendors`) | Static only |
| 38 | Organizer duplicates event configuration safely | Replay-safe; closed slots stay closed; no bookings copied | Probe: duplicate replay / closed slots | Pass |
| 39 | Organizer logs out and back in | Log out in profile menu; login returns to dashboard | Spec: `dashboard-consistency` › top bar; `wp-session` login helper in every spec | Pass |
| 40 | Organizer reopens the iOS application | Cached requests/events/money load; badge resumes in foreground | Static (`tsc --noEmit` clean; verify scripts pass) | Static only (no device run) |
| 41 | Unauthorized organizer attempts another event | 403 on tickets, financials, refunds, invites, info requests | Spec: `organizer-security` (11); Probe (tenant + staff checks) | Pass |
| 42 | Duplicate taps / simultaneous requests | No duplicate events, bookings, payments, refunds or payouts | Probe: Create Event replay, double approve refund, cashout replay, duplicate replay; Spec: partial failure retry; payment lock Static | Pass (true concurrent HTTP race not automated) |

## Mobile web

`dashboard-consistency` › "organizer on a phone" (390×844): Home, Events, Applications, Financial, Vendors, Operations, Create, event workspace, requests tab and check-in all render one header, no leftover loading text, and no horizontal scroll — **10/10 pass**.

## Not covered here

- Stripe Connect onboarding and real test-card charges need Stripe test keys in wp-env (scenarios 3, 24, 35).
- Native app scenarios (40) are typechecked and script-verified only; run on a device or simulator before release.
- Floor-plan image upload on Maps fails for organizers (no `upload_files` capability); the failure is shown as a toast. A dedicated upload endpoint is still needed.

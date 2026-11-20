# Vendor Experience 2.0 — QA Checklist

Run on **staging or `localhost:8888` only**, with Stripe **test-mode** keys and a webhook signing secret. Never use live charges as QA data.

Status legend: **Auto** = covered by a Playwright spec (spec › test) · **Manual** = run by hand and record result · **Native** = run on an iOS device/simulator build of `com.lnkup.marketplace`.

Result column: fill with `Pass`, `Fail (link to issue)`, or `Blocked (reason)` and the date. "Not run" means no environment was available yet — it is **not** a pass.

## Environment

| Item | Value |
|---|---|
| WordPress URL | `PLAYWRIGHT_BASE_URL` (default `http://localhost:8888`) |
| Vendor A | `PLAYWRIGHT_VENDOR_USER` / `PLAYWRIGHT_VENDOR_PASS` (has a profile, a pending payment, one uploaded document) |
| Vendor B | owns `PLAYWRIGHT_FOREIGN_DOCUMENT_ID`, `PLAYWRIGHT_FOREIGN_PAYMENT_ID`, and a succeeded test intent `PLAYWRIGHT_FOREIGN_PAYMENT_INTENT_ID` |
| Organizer | `PLAYWRIGHT_ORGANIZER_USER` / `PLAYWRIGHT_ORGANIZER_PASS` (owns the events below) |
| Fixtures | `PLAYWRIGHT_VENDOR_PENDING_PAYMENT_ID`, `PLAYWRIGHT_FRESH_OPPORTUNITY_ID` (open, A never applied), `PLAYWRIGHT_FREE_BOOTH_PAYMENT_ID` (approved $0 booth for A), `PLAYWRIGHT_MULTI_SLOT_OPPORTUNITY_ID` |

Commands:

```bash
npx playwright test tests/e2e/vendor-security.spec.ts tests/e2e/vendor-journey.spec.ts --project=chromium --project=mobile
cd lnk-up-marketplace-mobile && npm run typecheck && npm run phase26:verify
```

## Scenarios

| # | Scenario | Type | How / spec | Expected | Result |
|---|---|---|---|---|---|
| 1 | New vendor registration | Manual | Sign up as vendor from `/opportunities/?apply=ID` "Sign up" | Lands back on the opportunity with the apply modal open | Not run |
| 2 | Returning vendor login | Auto | vendor-journey › dashboard deep links survive the login redirect | Full `?view=…` query preserved | Not run |
| 3 | Profile completion | Manual | Fill each Profile section; reload; restart browser | Values persist; getting-started item disappears | Not run |
| 4 | Business image upload | Manual | Upload logo in Profile → Business Basics | Logo shows on Home welcome and apply snapshot; another vendor's image ID cannot be set via REST | Not run |
| 5 | Marketplace discovery | Auto | vendor-journey › event card images…; marketplace-v2.spec | Cards show image (or category fallback), date, city, fee label, status | Not run |
| 6 | Search and filters | Auto | vendor-journey › document filters…; › typing a search… | Filters restore after reload/back; ≤1 history entry per search | Not run |
| 7 | Save opportunity | Manual | Tap ♥ signed in; tap ♥ signed out | Signed in toggles; signed out goes to signup with return URL | Not run |
| 8 | Select booth | Auto | vendor-journey › events with several open slots… | Booth chooser shows; switching keeps one selected | Not run |
| 9 | Apply while logged out | Auto | vendor-journey › apply from a card keeps the opportunity… ; apply-with-profile.spec | Login link carries `apply=ID` | Not run |
| 10 | Apply while logged in | Auto | apply-with-profile.spec › vendor can submit… | Success state; card button becomes "Application: Submitted" | Not run |
| 11 | Reopen a submitted application | Manual | Close browser, log back in, open Requests | Same status; no duplicate | Not run |
| 12 | Receive approval | Manual | Organizer approves | Vendor Home Next step = pay; Requests → Needs action; payment-required email once | Not run |
| 13 | Receive rejection | Manual | Organizer rejects | Card shows Declined; Apply not offered again | Not run |
| 14 | Request additional information | Manual | — | **Not implemented** (no "info requested" status exists). Track as enhancement | N/A |
| 15 | Complete paid checkout | Manual | Pay with `4242…` in test mode | Paid only after webhook/verified confirm; packet unlocks | Not run |
| 16 | Handle failed checkout | Manual | Pay with `4000 0000 0000 0002` | Error inline; stays Payment required; retry possible | Not run |
| 17 | Free booth confirm | Auto | vendor-security › a free booth confirms… | Confirmed with $0; no marketplace fee; no "payment required" email | Not run |
| 18 | Access confirmed booking | Manual | Requests → Confirmed → Open pass | Pass unlocked, timeline at Confirmed | Not run |
| 19 | Retrieve event packet | Manual | Operations → pass | Packet visible only for own paid booking | Not run |
| 20 | Upload document | Manual | Documents → Upload (PDF < 10 MB) | Progress bar, "Pending review", file not reachable by direct URL | Not run |
| 21 | Update expiring document | Manual | Replace an expiring document | New file shows "Pending review" and its expiry; the card offers Replace | Not run |
| 22 | View organizer communication | Manual | Organizer broadcast | Appears in Home Recent activity / inbox. **Two-way messaging not implemented** | Not run |
| 23 | Notification deep link | Native | Tap `payment_required` push | Opens `/pass/{id}` | Not run |
| 24 | Use check-in QR | Manual | Organizer scans vendor pass | First scan checks in; second returns "already checked in" | Not run |
| 25 | Review completed event | Manual | After event, organizer + vendor reviews | One review per booking | Not run |
| 26 | Referral sharing | Manual | Wallet → Referral credits → Copy | "Copied!" or fallback text; link works | Not run |
| 27 | Log out and back in | Manual | Web + native | No stale data from previous account (native request cache cleared) | Not run |
| 28 | Close/reopen iOS app | Native | Kill app mid-apply, reopen | Session restored; submitted application visible | Not run |
| 29 | Slow / intermittent network | Auto + Native | vendor-journey › a failing search clears skeletons…; › wallet balance header…; native offline banner | No infinite spinners; retry offered | Not run |
| 30 | Organizer-side consistency | Manual | Organizer Applications + Financial after 10–17 | Same statuses/amounts; no duplicate records | Not run |

## Security regressions (automated)

All in `tests/e2e/vendor-security.spec.ts`:

- Anonymous `wp/v2/lnkup_*` private types are not routable (11 types).
- `sim_` intent cannot mark a payment paid (unless simulation is intentionally enabled).
- A succeeded intent from another booking is refused (`payment_mismatch`).
- Credits cannot be applied to another vendor's payment.
- Another vendor's document cannot be downloaded; anonymous download refused.
- Unsigned webhook payloads rejected.
- Three parallel apply requests create exactly one application.
- Native checkout return bounces to `lnkup://stripe-redirect` and normalizes unknown states.

## Native-only checks

| Check | Expected | Result |
|---|---|---|
| Pay from Pass → Stripe sheet → success | Sheet closes on its own; Pass shows "Confirming your payment…" then "Payment received" once server reports paid | Not run |
| Cancel in Stripe sheet | Returns to Pass, "Payment cancelled" | Not run |
| Double-tap Apply / Pay | One request | Not run |
| Logged-out deep link to `/apply/ID` | Login returns to the same Apply screen | Not run |
| Switch tabs Home ↔ Requests ↔ More | One `/applications/vendor` request per 15s window (check proxy log) | Not run |
| App backgrounded 2+ min | No badge polling while backgrounded; refresh on foreground | Not run |
| Keyboard on Apply message / Profile fields | Inputs stay visible above keyboard | Not run |
| Android link `https://thelnkup.com/opportunities/?apply=ID` | Opens opportunity in app (after new build) | Not run |

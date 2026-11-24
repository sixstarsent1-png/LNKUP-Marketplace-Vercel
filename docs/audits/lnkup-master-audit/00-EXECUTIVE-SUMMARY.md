# LNKup master technical audit — executive summary

> **Snapshot note:** this audit read `redesign/event-product` at `461f3e3` plus its then-uncommitted 12.34.0 work. While the audit ran, that work was committed as `aaa6e7e` (12.34.0, geographic supply/demand, partner cities). A further commit, `d5ecd6e` (Engagement OS phases 1–2: comms policy layer, invite expiry, event fulfillment, Find Vendors sections; 23 files, +1,472 lines), landed and is **not covered** here. The branch is now 6 ahead / 39 behind `origin/master`. Production still serves plugin 12.33.4.

Date: 2026-11-23. Scope: the whole first-party repository (both WordPress plugins, theme, Expo app, Next.js app, tests, CI) plus read-only checks of production. No application code was changed and nothing was committed.

Reports in this folder:

| File | Contents |
|---|---|
| 01-ARCHITECTURE-INVENTORY.md | Architecture map, counts, flags, which code is in production |
| 02-FEATURE-COMPLETION-MATRIX.md | 20-system scorecard with capability checklists |
| 03-WEB-MOBILE-PARITY.md | Feature-by-feature parity matrix |
| 04-MISSING-AND-PARTIAL-FEATURES.md | Consolidated gap inventory (IDs A–E) |
| 05-FINANCIAL-SECURITY-FINDINGS.md | Money and security, labelled verified / in-code / potential |
| 06-TECHNICAL-DEBT.md | Ranked debt |
| 07-PRIORITIZED-ROADMAP.md | P0–P3 with dependencies and Cursor session plan |
| 08-TOP-25-ACTIONS.md | Ranked next actions |
| 09-TEST-EVIDENCE.md | Every command run and its result |

## Overall assessment

LNKup's code is far more complete than its production state. The WordPress plugin alone has:

- 464 REST routes, 16 custom post types and 125+ tables
- a real Stripe financial layer with signed webhooks for 23 event types
- a server-side matching engine
- an internal organizer-acquisition CRM
- tours/series, Vendor Passport, trust & safety, partner programs
- a separate Communications engine with Expo push and an inbox

Most of the 20 systems are 70–90% built in code. The 18 financial and booking test suites I ran passed with zero failures. Expo typechecks clean and its 200 unit tests pass.

**Readiness for scaling independent organizers: not yet.** The blockers are mostly release and verification, not missing features.

1. **Production is a mix of two diverged code lines.** The plugin is 12.33.4 from this branch; the theme is from `origin/master`. Both lines call themselves 12.33.x. As a result, master's Phase 1–7 work and Bookings Center aren't live. That includes the money fixes, the booth-edit fix, draft-event hiding, the funnel and the ops queue.
2. **The automated deploy has never worked** (missing SSH secret). Every release has been a manual upload.
3. **Twelve systems sit behind default-off flags**, and production flag values aren't recorded anywhere.
4. **Money has never been run against Stripe.** Every financial test runs with Stripe off.
5. **The App Store has version 1.0**, while over-the-air updates target 1.0.1, so store users likely don't have Mobile 3.x. Android isn't on Google Play.
6. **The default City Connector commission (10% of booth price, uncapped) exceeds LNKup's own take** on a typical booth.

## What we have built (and works, with evidence)

- **Booking core:** apply, transitions, capacity locks, duplicate protection, withdraw/reapply, request-info. Verified by tests run here: 73/73 and 37/37.
- **Money core:**
  - Fee engine ($5.99 vendor checkout fee + 6% organizer commission + card processing)
  - Checkout and Stripe binding
  - Signed webhooks with replay and ordering guards
  - Refunds, disputes, cash-out, reconciliation/repair

  14 financial suites passed here, with Stripe off.
- **Data protection:** private records hidden from the public API. Vendor document PDFs no longer listed. The private uploads folder returns 403 in production.
- **Organizer fee privacy (12.33.3):** 25/25.
- **Expo app:** 53 screens, live data with honest error states (never sample data in live mode), SecureStore tokens, push device registration, partner workspace.
- **Public site:** redesigned homepage and Find Events (117 live cards, no console errors); event pages have proper JSON-LD.

## What is partially built

| Area | What's partial |
|---|---|
| Invoices | Can disagree with the final charge (04 C1) |
| Event-day | Manual/list check-in works; camera scanning doesn't work on iPhones (no Safari `BarcodeDetector`, no `expo-camera`); no offline mode |
| Communications | Engine complete; every flow off; no email provider configured; the legacy sender handles production |
| Analytics | Organizer intelligence and growth analytics exist; the marketplace funnel is master-only; the Next.js admin Money/Events/Users pages show **fake data** |
| SEO | Good event markup; no `og:image`; city pages 404; demo "fitness trainer" pages and products indexed |
| Support | Only event-day vendor→organizer requests and static help articles; no way to raise a ticket with LNKup |
| Enterprise | Brands, teams (API only), custom questions, embed; no integrations API |

## What is broken (production line)

1. Editing a booth or parking spot from operations fails ("Event not found"). Fixed on master only.
2. Map floor-plan upload fails for organizers (no `upload_files`).
3. City pages `/markets/{city}/` return 404.
4. Android App Links aren't verified (`assetlinks.json` 404); iOS links cover only vendor and organizer profiles.
5. Next.js typecheck fails on this line (18 errors); its production deploy is from July.
6. Mixed-content CSS error on the homepage (leftover ThemeIsle/Otter plugin).

## What we have not built

- Platform support ticketing
- User "report a listing"
- Account restriction/suspension
- Booking modifications
- Offline check-in
- Camera scanning in the app
- Organizer integrations API/webhooks
- Vendor win-back flows
- Premium listings checkout (code exists but is never called)
- Financing and insurance (placeholders)
- Partner anti-fraud beyond the same-user check

## What exists on web but not mobile

- Team management
- Organizer verification
- Organizer tour/series tools
- Waitlist offers
- CRM lists
- External-event claims
- Booth management outside check-in
- Support requests
- Event tasks/readiness (on this line)

The Next.js app separately lacks Stripe Connect onboarding, Passport, maps and booth tools. See 03.

## End-to-end journeys — first missing or unverified step

| Journey | First weak step |
|---|---|
| Vendor: register → profile → verify → discover → apply → approve → pay → book → docs → pass → check-in → history | **Pay**: never run against Stripe test mode. On iPhone, **check-in by camera** isn't possible |
| Organizer: register → org setup → **Stripe onboarding** → event → opportunity → publish → review → payment → booths → ops → reporting | **Stripe Connect onboarding** never exercised. **Booth editing** broken in production. Onboarding v2 behind a flag |
| Multi-city tour: org → tour → stops → opportunities → multi-stop apply → per-stop approvals → payments → per-stop check-in → tour reporting | **Tour creation**: Portfolio OS flag off. Per-stop readiness rollup master-only |
| Referral: link → target registers → organizer activates → qualifying booking → commission → approval → payout | **Commission terms**: the default rule is unprofitable. **Payout** is manual by design |
| Support: issue → case → assign → investigate → resolve → notify | **Issue submitted**: no user-facing support ticket exists |

## What we should prioritize

See 07 and 08.

1. **Release hygiene (P0):** unify the branches, fix the deploy pipeline, record flags.
2. **Prove money on staging with Stripe test mode,** and fix the partner commission default.
3. **Ship a new iOS binary,** then Android.
4. **Event-day and security quick wins:** booth edit, iPhone scanning, token revocation, user list, invoice alignment, email provider.
5. **A small independent-organizer pilot** using the already-built onboarding, drafts, Organizer OS and Communications flows.
6. **Then the growth stack:** Organizer Growth, matching, Passport, retention.

## What is ready for production (verified)

- Public discovery
- Single-event apply and approval
- Booking integrity rules
- Webhook security
- Refund/dispute/cash-out logic (in tests)
- Private record and document protection
- Expo vendor and organizer core screens

**Not verified as production-ready:**
- Anything involving a real Stripe charge or payout
- Push delivery
- Device deep links
- Every flag-gated system

## Coverage checklist (for continuing this audit)

| Area | Covered | Depth |
|---|---|---|
| Git state, branches, worktrees, CI, deploy history | Yes | Full |
| Production version mapping | Yes | Asset and file probes |
| Plugin inventory, routes, CPTs, tables, flags | Yes | Counts by static scan |
| Systems 1–20 | Yes | Key classes, routes, clients, flags; deeper on 1, 5, 8, 9, 11, 14, 15 |
| Security: public routes, secrets, auth tokens, documents, XSS sinks | Yes | Static + production probes |
| Builds/tests | PHP lint, Expo tsc + jest, Next tsc, 18 PHP suites | Run |
| Not covered | Full Playwright suites; per-file review of the 22 REST classes using `$request['id']`; N+1 query profiling; the uncommitted 12.34.0 matching/market work (listed in 01 only) | Next session |

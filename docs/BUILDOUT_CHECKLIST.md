# Post-redesign buildout: completion matrix and running checklist

The single running checklist for the post-redesign buildout. Each phase closes here with what shipped, the evidence, and what is still open. WordPress owns business state; Next.js (`lnk-up-marketplace-ui`) and Expo (`lnk-up-marketplace-mobile`) are clients.

## Evidence types

Each one proves something different, so they are listed separately:

| Code | Meaning |
|---|---|
| **U** | A unit or integration test passed (PHP `scripts/e2e/php`, Expo jest). |
| **J** | An in-app journey passed (Playwright against wp-env, Next.js dev, or Expo web). |
| **P** | A preview build worked (Vercel preview or EAS preview). |
| **R** | A rollout was verified (flag on for real accounts, checked on staging or production). |

Statuses: **Verified end to end**, **Built, needs verification**, **Built, rollout gated**, **Partial client support**, **Missing**, **Blocked by an external dependency**.

## Release baseline (Phase 0, 2026-11-22)

| Item | Value |
|---|---|
| Integration branch | `integrate/os-workstreams` ([PR #9](https://github.com/sixstarsent1-png/LNKUP-Marketplace-Vercel/pull/9)), master `0da2497` + redesign ([PR #6](https://github.com/sixstarsent1-png/LNKUP-Marketplace-Vercel/pull/6)) + the previously uncommitted workstreams |
| Marketplace plugin | 12.33.0 (production `lnkup_db_version` 12.32.0) |
| Communications plugin | 1.2.0 (DB 1.0.0) |
| Theme | 1.6.1 |
| Expo | 1.0.1, `runtimeVersion` policy `appVersion`, EAS channels development / preview / production |
| wp-env for this work | `http://localhost:8894` (override file, local only) |
| PHP lint | 391 files, 0 failures |
| Expo typecheck | 0 errors |
| Next.js `tsc` | 18 errors, all already on master; fixed by [PR #7](https://github.com/sixstarsent1-png/LNKUP-Marketplace-Vercel/pull/7) (CI gates, Vercel install) |
| Still in the old dirty checkout only | zip artifacts, `test-results/` deletions. Nothing else. |

## Completion matrix

Short paths: `P/` = `wp-content/plugins/lnkup-marketplace/`, `C/` = `wp-content/plugins/lnkup-communications/`, `N/` = Next.js app, `E/` = Expo app.

### A. Organizer to published opportunity

| Step | WordPress service / route | WP UI | Next.js | Expo | Flags | Evidence | Status | Missing step |
|---|---|---|---|---|---|---|---|---|
| Guided setup, resume | `LNKup_Organizer_Onboarding_Flow`, `GET/PATCH organizer/onboarding` | `organizer-start.js` | `N/app/organizer/onboarding` | `E/components/continue-setup-card.tsx` | `organizer_onboarding_v2_enabled` | U, J (WP) | Built, rollout gated | One next-step resolver across clients (Phase 4) |
| Create event + booth + publish | `LNKup_Organizer_Onboarding::create_event_from_request`, `POST organizer/launch/publish` | Create Event | `N/app/organizer/launch` | `E/app/(organizer)/launch.tsx` | `platform_prelaunch_mode`, `trust_publish_gate` | U, J (WP) | Partial client support | Next.js sends no `client_token`; opportunity rows created outside the lock (Phase 2A) |
| Draft / closed visibility | `_lnkup_event_status`, `organizer/opportunities/{id}/status` | dashboard | yes | yes | none | U | Built, needs verification | Check `wp/v2` exposure of draft events |
| Tour stop placement | `includes/portfolio/*`, `portfolio/*` | `portfolio.js` | vendor view only | none | `portfolio_os_enabled`, `portfolio_os_user_ids` | U, J (WP) | Built, rollout gated | Organizer tour tools are WP-only by design (Phase 3) |

### B. Vendor to accepted booking

| Step | WordPress service / route | WP UI | Next.js | Expo | Flags | Evidence | Status | Missing step |
|---|---|---|---|---|---|---|---|---|
| Find / save / return to intent | marketplace feed, saved searches | `marketplace-v2.js` | yes | `pending-destination.ts` | none | J (WP, Expo web) | Built, needs verification | Next.js login return is client-only |
| Draft save, resume, submit | `LNKup_Application_Drafts`, `applications/drafts*` | apply wizard | `N/app/api/applications/drafts/*` | `E/lib/api/apply.ts` | `apply_wizard_enabled` (`LNKup_Rollout`) | U, J | Partial client support | Apps do not list drafts for cross-device resume (Phase 2B) |
| Changes before submit | draft submit re-check | apply wizard | generic error | generic error | none | U | Partial client support | Only booth price is re-checked; no structured change codes (Phase 2B) |
| Request info / respond | `request-info`, `respond-info` | review | `N/app/vendor/requests` | `vendor-requests.ts` | none | U, J | Built, needs verification | none known |
| Review v2 decision, waitlist offer | `LNKup_Application_Review(_REST)`, `LNKup_Waitlist_Offers` | `organizer-review.js` | legacy roster bulk | legacy roster bulk | `application_review_v2_enabled`, `waitlist_offers_enabled` | U, J (WP) | Built, rollout gated | Apps do not call the v2 decision route (Phase 3) |
| Withdraw, reapply, offer expiry, capacity | `LNKup_Applications` (`WITHDRAWABLE`, `reapply_state`, `opportunity_full`), cron `lnkup_waitlist_offers_expire` | yes | yes | yes | none | U | Built, needs verification | Journey check (Phase 2B) |
| Multi-stop per-stop results | `LNKup_Application_Groups`, `tours/{id}/apply*` | `tour-apply` | `N/app/vendor/tours/[id]` | `E/app/tour/apply/[tourId].tsx` | `tour_multi_apply_enabled` | U, J | Built, rollout gated | Retry path for invalid stop (Phase 2B) |

### C. Checkout to event-day entry

| Step | WordPress service / route | WP UI | Next.js | Expo | Flags | Evidence | Status | Missing step |
|---|---|---|---|---|---|---|---|---|
| Checkout, confirm, webhook | `LNKup_Financial_Service::finalize_payment`, `LNKup_Stripe_Webhooks` (claim, `INSERT IGNORE`) | `checkout.js` | payment BFF | `stripe-checkout(.native).ts` | `test_mode`, `stripe_connect_enabled`, `ach_enabled` | U | Built, needs verification | Test-mode journey (Phase 2C) |
| Return, cancel, processing, retry | `financial/checkout/*`, `native-return` | yes | ad hoc `?payment=` | `stripe-redirect.tsx` | none | U, J | Partial client support | No shared return-state helper; return URLs unsigned but never trusted (Phase 2C) |
| Invoice and receipt | `LNKup_Invoices`, `LNKup_Receipts` | wallet | wallet invoices | `vendor-wallet.ts` | none | U | Partial client support | Lists sorted by creation, capped at 25, no paging (Phase 2D) |
| Pass and check-in | `LNKup_Packets`, `LNKup_Checkins`, `operations/qr/scan` (needs `event_id`) | operations | `N/app/vendor/pass/[id]` | `E/app/pass`, `(organizer)/events/checkin.tsx` | none | U, J | Built, needs verification | QR passes unsigned (Trust F-6) |
| Refund effects | `LNKup_Financial_Service::refund` | Money | read-only | read-only | none | U | Built, needs verification | No refund from `partially_paid` |

### D. Money and reconciliation

| Step | WordPress service / route | WP UI | Next.js | Expo | Flags | Evidence | Status | Missing step |
|---|---|---|---|---|---|---|---|---|
| Organizer money contract | `organizer_money_contract` | `financial.js` (Unavailable) | `organizer-financial.ts` | `money.tsx` | none | U (Expo `money-contract.test.ts`) | Built, needs verification | No `transfers` metric |
| Vendor wallet totals | `vendor_money_contract` | wallet | client-side totals | client-side `?? 0` totals | none | none | Partial client support | Both apps total requests themselves (Phase 2D) |
| Reconcile and repair | `LNKup_Financial_Reconciliation`, `LNKup_Financial_Repair` (dry run, token) | wp-admin | none | none | none | U, report 2026-11-22 | Built, needs verification | No admin REST route; detector counted voided holds (fixed) |

### Organizer management, tours, event day

| Area | WordPress | Next.js | Expo | Flags | Status | Missing step |
|---|---|---|---|---|---|---|
| Review queue, history, bulk | `application-review-rest` | none | none | `application_review_v2_enabled` | Built, rollout gated | App clients (Phase 3) |
| CRM notes, lists, invites, history | `organizer-crm-rest` | partial | notes, invites | none | Partial client support | Owner-only; team roles cannot use CRM (Phase 3) |
| Teams and event scope | `LNKup_Org_Teams`, `staff_can` | none | role gate ignores team members | `trust_org_teams` | Built, rollout gated | Scope does not reach CRM or Portfolio |
| Tours, series, templates | `portfolio/*` | vendor side | none | `portfolio_os_*` | Built, rollout gated | Rollup ignores tasks, readiness, documents |
| Tasks, readiness, documents | `class-event-tasks*`, `class-event-readiness`, `class-organizer-documents` | none | none | `organizer_ops_v2_enabled`, `organizer_document_review_enabled` | Built, rollout gated | App field views; document review also needs private uploads protected |
| Booths, load-in, check-in | `class-booths`, `class-loadin-schedule`, `class-checkins` | none | check-in, booths | none | Partial client support | none for field use |

### Acquisition, discovery, communications, analytics

| Area | WordPress | Clients | Flags | Status | Missing step |
|---|---|---|---|---|---|
| Activation milestones | `LNKup_Organizer_Activation` | Expo home | none | Built, needs verification | Single next step; house vs independent outside Growth |
| Organizer Growth, supply intel | `organizer-growth/*` | wp-admin | `organizer_growth_*` | Built, rollout gated | Unfilled categories not shown to organizers |
| Matching rails, saved searches | `matching/*`, `LNKup_Rollout` | both apps | matching flags | Built, rollout gated | Funnel attribution only for recommendations |
| External events | `class-external-events*` | dropped by both apps | `include_external` default off | Partial client support | Label and claim path in apps |
| Lifecycle messages | `C/class-comms-registry.php`, delegation | inbox, push | `flow_*` | Built, rollout gated | Waitlist offer, tasks, documents, review notices bypass Comms; waitlist offer may double-send |
| Funnel analytics | `class-marketplace-analytics`, `class-funnel-analytics` | Next.js events only | none | Missing | No opportunity view to check-in chain; no server apply start; no test exclusion |

### Internal operations and partnerships

| Area | WordPress | Clients | Status | Missing step |
|---|---|---|---|---|
| Ops overview | separate queues (trust, comms health, match health, partner reviews, reconciliation) | Admin Lite: external events, documents, connectors | Missing | One deduplicated queue; stuck-application detector |
| Partner programs and ledger | `includes/partnerships/*`, `LNKup_Attribution_Arbiter` | Expo `(partner)` workspace, web dashboard | Verified (Phase 6; balances over 50 rows fixed) | Payouts stay manual until KYC and 1099 |
| Private document sharing | `document_vault_sharing`, `class-document-migration.php`, `LNKup_Private_Uploads_Probe` gate | none | Blocked by an external dependency | SiteGround NGINX Direct Delivery serves `/uploads/lnkup-private/` (hosting owner); the probe must read `protected` on staging |

## Phase 1 results: release and preview checks

### Vercel previews
- **Cause (from the build logs of PR #7 and PR #9):** the Vercel project builds from the repo root (`rootDirectory: null`). The Next.js builder reads the root `package.json`, finds no `next` dependency, and stops with `NEXT_NO_VERSION`. Changing the install command (PR #7) does not help.
- **Fix:** an owner action, not code. In Vercel, set Project Settings → Root Directory to `lnk-up-marketplace-ui`. `lnk-up-marketplace-ui/vercel.json` then applies (`npm ci`, `npm run build`). The stale July `lnk-up-marketplace-ui/pnpm-lock.yaml`, which was missing `react-leaflet`, is removed so only `package-lock.json` decides the install.
- **Evidence:** a local `npm run build` in `lnk-up-marketplace-ui` succeeds (Next.js production build, all routes compiled). No preview has passed yet; that waits on the Root Directory setting.

### GitHub quality gates
- Fixed separately in [PR #7](https://github.com/sixstarsent1-png/LNKUP-Marketplace-Vercel/pull/7): pnpm setup, a CI web server, strict `tsc`, and the 18 Next.js type errors. Both gates pass there. Until PR #7 merges, this stack fails the gates at the install step.

### Playwright (WordPress)
- **Timeout causes:**
  1. A plain `npx playwright test` ignored `scripts/e2e/.env.e2e` and ran with unlimited workers. More than two workers against one wp-env container causes forced re-logins and timeouts.
  2. A stale `PLAYWRIGHT_BASE_URL` in the shell pointed tests at another site while they used this site's fixture IDs.
- **Fix:** both configs now load the seed file through `tests/support/seed-env.ts`, warn when the shell's base URL differs from the seeded one, and default to 2 local workers.
- **Seed gaps that only showed on a fresh site:**
  - No booths on the main event.
  - `PLAYWRIGHT_PENDING_PAYMENT_APPLICATION_ID` was not exported.
  - The free booth needed a required document for the check-in gate once per-event document review is on.
- **Spec drift fixed:**
  - Vendor payments tabs are `role="tab"` and grouped under the Wallet section.
  - Quick chips have been hidden since `864b1fe`.
  - Phase-1a assertions skip on the seeded phase `1c`.
  - Classic applications view lives at `&mode=classic`.
  - Core WordPress loads its own `login.css` under `SCRIPT_DEBUG`.
  - Explore More is a collapsed `<details>`.
- **Result** (wp-env :8894, Chromium, 2 workers), 13 core journey files:
  - First run: 65 passed, 7 failed, 1 skipped.
  - Rerun of the 4 changed files after the fixes: 29 passed, 2 failed (vendor payments tabs), 2 skipped.
  - Vendor financial after the Wallet-section fix: 3 passed.
  - Organizer integrity + vendor security after the seed changes: 21 passed, 2 skipped.
- **Suite ordering:** the WordPress and mobile suites share one seed and consume one-spot fixtures. Reseed between them: run `seed.php`, `seed-portfolio.php`, `seed-organizer-os.php`, then `seed-onboarding.php`.

### Expo
- **Jest:** 21 suites, 177 tests passed.
- **Typecheck:** 0 errors.
- **Playwright on Expo web** (iPhone 15 profile, `playwright.mobile.config.ts`):
  - First full run: 60 passed, 6 failed, 3 did not run.
  - After fixes and a reseed, the failing files: 35 passed, 1 failed (booth assignment, fixed by the booth seed).
  - Check-in file: 6 passed.
  - **Covered:** role entry, sign-in persistence across reload, sign-out, role-guarded routes, the cross-role apply → approve → test-mode pay journey, check-in and its gate, failed-request handling, notification routing (jest).
- **Bug fixed:** browser builds of the app could not submit an application draft. WordPress' REST CORS allow-list did not include `Idempotency-Key`, so the browser blocked the request (shown as "Service unavailable"). `LNKup_Mobile_Auth` now adds the header. Native builds were not affected, because CORS does not apply to them.
- **Use a real install:** a junctioned `node_modules` makes Metro bundle another worktree's `app/`. Use `npm ci` inside the worktree.
- **Device-only checks, not covered here:** push delivery and tap-through from a locked device, the Stripe in-app browser return on iOS and Android, camera QR scanning, universal links, SecureStore across app reinstall, and backgrounding during checkout.
- **OTA vs native:** this stack adds no native modules and does not change `app.json`, `eas.json` or the app version. With `runtimeVersion` = app version (1.0.1), its Expo changes can ship as an OTA update on the 1.0.1 channel. Any new native module or version bump needs an EAS build and store submission first.

### Private uploads (Phase 27 steps 5 and 8)
- **Gate:** `LNKup_Private_Uploads_Probe` writes a token file into `uploads/lnkup-private/`, requests its public URL without credentials, records `protected` / `exposed` / `unknown`, and deletes the file.
- **Enforcement:**
  - Turning on `document_vault_sharing` and starting the private document migration both require a fresh `protected` result.
  - An `exposed` result switches sharing off at runtime and stops a running migration batch.
  - Passport admin → System health shows the result and has a re-check button.
- **Evidence:**
  - `private-uploads-probe-test.php` passes 16 checks using faked responses (exposed, unreachable, protected, regression after rollout).
  - A direct request to a synthetic file on wp-env (Apache) returns 403.
  - The probe from inside wp-env reports `unknown` (the container cannot reach its own published port), so sharing stays off there.
- **Still blocked:** production remains blocked until SiteGround NGINX Direct Delivery is turned off and the probe reads `protected` on staging and then production. Owner: hosting. Document Vault sharing and the migration stay gated.

### Deployable revisions and flag dependencies
| Component | Revision |
|---|---|
| Marketplace plugin | 12.33.0 (this stack) |
| Communications plugin | 1.2.0 |
| Theme | 1.6.1 |
| Next.js | this stack; deploys only after the Root Directory fix |
| Expo | 1.0.1 binary; this stack is OTA-eligible |

| Feature | Flag(s) | Depends on |
|---|---|---|
| Apply wizard, multi-stop | `apply_wizard_enabled`, `tour_multi_apply_enabled` (`LNKup_Rollout`) | none |
| Review v2, waitlist offers | `application_review_v2_enabled`, `waitlist_offers_enabled`, `organizer_os_pilot_user_ids` | waitlist needs review v2 |
| Event tasks, readiness | `organizer_ops_v2_enabled` | none |
| Organizer document review | `organizer_document_review_enabled` | `document_vault_sharing`, which needs the private uploads probe to pass |
| Teams | `trust_org_teams` | none |
| Portfolio OS | `portfolio_os_enabled`, `portfolio_os_user_ids` | none |
| Guided onboarding | `organizer_onboarding_v2_enabled` | none |
| Matching | matching flags (`LNKup_Rollout`) | `matching_reputation_signals` waits on Passport soak |
| Communications flows | `flow_*` (`LNKup_Rollout`) | live sender configuration |

## Phase 2 results: booking journey and financial integrity

### A. Organizer to published opportunity
- **Next.js publish was not retry-safe.**
  - Fixed: Next.js publish now sends a `client_token` (one per draft, kept across failed attempts, cleared after success).
  - Fixed: `LNKup_Organizer_Onboarding::create_opportunity_for_event` holds a lock per row token, so two in-flight publishes cannot both create a slot.
- **Draft events were public on `wp/v2`.** Draft events (`_lnkup_event_status = draft`, stored as published posts) appeared in public `wp/v2/lnkup_event` lists and single GETs. They are now hidden from everyone except the owner and admins.
- **Evidence:**
  - `launch-publish-idempotency-test.php`: 21/21 (retried publish, held lock, draft visibility).
  - `organizer-activation-test`, `portfolio-os-test`, `upsert-race-test`, `organizer-onboarding-flow-test` pass.

### B. Vendor to accepted booking
- **Already built, now covered by tests:**
  - Price change returns `409 lnkup_price_changed`; new required questions return `422 lnkup_answers_required`.
  - Deadline, full and closed refusals return 400 with the block code, and the draft stays open and submits once the organizer reopens (`draft-submit-changes-test.php`: 18/18).
  - Multi-stop submit returns a result row per stop, retries idempotently per stop, and keeps the group draft until every stop succeeds.
- **Fixed in both apps:** those block codes now close the form with specific copy ("Applications closed", "This booth is full", "Invite only") instead of a generic error (Expo `applyBlockFor`, jest 12/12; Next.js apply modal).
- **Added in Next.js:** a "Finish your applications" list on Vendor Requests (`GET /api/applications/drafts`) with Resume and Discard. Expo's equivalent is in PR #10.
- **Fixed sign-in return:** Next.js sign-in from Apply and Save now returns to the same opportunity (`/vendor/find?opportunityId=…&apply=1`). Redirects stay limited to safe relative `/vendor` and `/organizer` paths.
- **Fixed in Next.js:** the Sign out button on the vendor, organizer and admin More pages and on vendor Profile had no handler.
- **Browser check** (Next.js dev on :3005, live API against :8894):
  - Signing in through the apply link lands on the opportunity and opens the apply modal (15–20 s in dev).
  - The drafts list shows the draft saved from the app; Resume opens it with the saved message.
  - Signed-out pages show the sign-in prompt.
  - A hydration warning on `/login` was observed and not investigated.
- **Regression:** `booking-application-os-test`, `reapply-policy-test`, `organizer-os-test` pass. `apply-draft-resume`, `apply-with-profile` and `tour-multi-apply` specs: 17/17.

### C. Checkout to event-day entry
- **Already built:**
  - Next.js `usePaymentReturn` reconciles payment returns from the server.
  - Expo `stripe-redirect` hands off to the Pass screen, which re-reads status from the server.
  - Return URLs are unsigned but never trusted for booking state.
- **Evidence:**
  - All 14 financial PHP suites pass (webhook replay and ordering, superseded intents, refunds, chargebacks, cashouts).
  - New `booking-lifecycle-test.php` (29/29): apply → approve (payment required, slot held) → test-mode finalize → paid → replay is a no-op and settles once → receipt and invoice equal the payment total in cents → newest invoice is first for the organizer → pass unlocks → check-in refused at another event and accepted at the assigned one → full refund marks the payment refunded, cancels the booking, locks the pass, frees the slot, reverses the ledger once, and the invoice reads refunded.

### D. Money and reconciliation
- **Missing invoices:** invoices were sorted by creation date and capped at 25. They now sort by `updated_at` (an older booking paid today comes first) and page with `page` / `per_page` up to 100, with `X-WP-Total` / `X-WP-TotalPages`. Receipts page the same way. The Next.js wallet routes read every page (with a `truncated` flag), and Expo requests 100.
- **Payments missing from vendor totals:** `partially_paid`, `partially_refunded` and `disputed` payments were left out of the vendor money contract. They now count, so `paid` + `refunded` equals what the vendor paid (`docs/finance/MONEY_DEFINITIONS.md` updated).
- **Wallet totals:** both vendor wallets now read `money.metrics` instead of adding up requests.
  - A figure the server did not confirm renders "Unavailable"; a confirmed $0 stays $0.
  - Next.js previously showed $0 for missing values and hid a real $0.
  - Expo jest 6/6. `vendor-money-lists-test.php`: 23/23.
- **Read-only reconciliation** (wp-env test data, organizer `e2e_org_a`, Stripe off):
  - 0 issues. WordPress paid gross $212.26 across 2 payments.
  - Contract: platform fees $17.99, net $194.27, pending $178.60, reserve $9.40.
  - No repair was needed.

## Phase 3 results: organizer management, tours, event day

### Team access
- **Event-scoped CRM routes** (roster, roster bulk, check-in, no-show, applicant intel, compare) now admit organization team members. Each event is still checked with `staff_can()`, and intel and compare read the event owner's CRM records.
- **Organization-wide CRM** (lists, invites, tags, notes, imports) stays owner-only. It is keyed by the owner's user ID, so a shared team CRM would be a separate change.
- **Tours:** `GET /portfolio/tours/{id}`, `/summary` and `/applications` admit team members whose membership covers the tour, through `LNKup_Capabilities::tour_staff_can()`.
  - Event-only members are not tour viewers.
  - Tour money is removed for roles without `view_financials` (`money_hidden: true`).
  - Editing a tour stays owner-only.
- **Fixed:** team-only reviewers lost their "Review" link when the redesign moved Applications into More. Team members now get it in the primary nav.
- **Evidence:** `team-scope-rollup-test.php` passes 30/30 checks: one-event reviewer, one-event ops staff, tour finance, tour ops, another organizer, the owner. `organizer-team` spec 3/3.

### Tour overview
- **Per stop**, each stop in `LNKup_Portfolio_Reporting::summary()` now reports:
  - `operations`: readiness %, next actions, open and overdue tasks, outstanding documents, checked-in count. A value is `null` when its feature is off.
  - `data_warnings`: `no_event_date`, `no_vendor_slots`, `unlimited_capacity`, `paid_without_payment_record`.
- **Tour tasks** add overdue tasks, outstanding documents and stops with incomplete data.
- **Money** still comes only from payment records, counted once by payment ID, and the existing stop-vs-tour reconciliation flag is kept.

### Event day and surface split
- **Fixed:** editing a booth or parking spot from organizer operations returned "Event not found". WordPress resolves `$request['id']` from the JSON body (the booth) before the URL (the event). `LNKup_Operations_REST` now reads `{id}` from the URL in all 37 places. The same pattern exists elsewhere and is not yet audited.
- **Review decisions:** both apps use `POST /applications/{id}/decision` (review workspace: per-event team permissions, decision history) and fall back to roster bulk on `lnkup_feature_off`.
  - Fixed: the Next.js route reported success when the bulk route refused a decision.
- **Expo Day-of tab:** a Readiness and tasks panel shows readiness %, next actions and open tasks, and lets staff mark a task done. It is hidden when Organizer OS is off (jest 4/4).
- **Division of work:**
  - Mobile covers field work: decisions, info requests, check-in, booth assignment, readiness, tasks.
  - LNKup web stays the home for templates, recurrence, documents, bulk work, readiness marks and task editing, and the mobile panel says so.
  - Waitlist offers are not yet in either app.

### Fixtures
| Scenario | Fixture |
|---|---|
| Single-event organizer | `seed.php` (`e2e_org_a`, `e2e_org_b`) |
| Multi-city tour | `seed.php` (`PLAYWRIGHT_TOUR_ID`) |
| Recurring series | `seed-portfolio.php` (`PLAYWRIGHT_SERIES_ID`, 4 weekly dates, idempotent) |
| One-event team account | `seed-organizer-os.php` (`e2e_reviewer`) |
| Interrupted setup | `seed-onboarding.php` (`e2e_org_onb`); the resume-in-another-session spec passes |

- **Regression:** `portfolio-os-test`, `organizer-os-test`, `organizer-os-wave2-test`, `trust-safety-test` pass. `organizer-integrity` 5/5, `organizer-review`, `portfolio-os` pass. Expo typecheck 0, jest 24 suites.

## Phase 4 results: acquisition, discovery, communications, analytics

### Communications
- **Fixed double send:** a waitlist offer sent the vendor two messages in both modes ("approved" or "payment due", plus "a spot opened up").
  - Core now skips the generic approval and payment-due emails for an offer (`LNKup_Applications::approve_for_waitlist_offer()`).
  - The Communications listener skips `v_application_approved` when the approval came from an offer. Payment reminders before the offer deadline are still scheduled.
  - The vendor now gets the offer message plus the invoice document.
- **Organizer OS sends are claimable:** the six raw `LNKup_Notifications::send()` calls in waitlist offers, reviewer assignment, event tasks and organizer documents now go through `send_keyed()` (keys listed in `LNKup_Notifications::KEYED_MESSAGES`), so Communications can claim them.
  - New triggers: `v_waitlist_offer`, `o_waitlist_offer_expired`, `o_review_assigned`, `o_task_assigned`.
  - They reuse existing link and push types, so app routing is unchanged.
  - Organizer-side adapters decline when the engine could not deliver (for example a one-event reviewer without an organizer account), so core still emails them.
  - `task_digest` and `organizer_document_update` stay plain email until a flow covers them.
- **Evidence:** `comms-organizer-os-test.php` (flows on and off; organizer-account vs team-only recipients). All 12 comms suites pass, including `comms-schema-test`.

### Organizer progress
- **`organizer/activation` now returns `next_step`.** During setup it is the setup step; after activation it is the most useful ongoing action, in this order:
  1. Applicants waiting for review.
  2. A draft event to finish.
  3. A category nobody has applied for on an upcoming booth.
- **`unfilled_categories`** lists those gaps per booth, using canonical category labels.
- **WordPress Get started** shows the ongoing step after setup. Expo Home already shows Communications next steps (`NextStepsCard`).
- **House vs independent** comes from `org_growth_house_organizer_ids` and is shared with the funnel.
- **Evidence:** `organizer-next-step-test.php`. `organizer-activation-test` and the Organizer Growth runner pass.

### Analytics
- **`GET /admin/analytics/funnel` (manage-all):** views → apply started → submitted → approved → paid → checked in, with drop-off per step, days to first paid booking, repeat vendors, and open vs filled slots. It returns the requested scope plus a house vs independent comparison.
- **Sources and counting rules** are returned with the numbers:
  - Paid comes only from payment records, and a later refund does not undo it.
  - Apply start comes from draft rows (`_lnkup_draft_started_at`) plus direct submits.
  - An `apply_started` analytics event is now also recorded when a draft is first saved.
- **One exclusion list** (`LNKup_Analytics_Exclusions`) removes test and demo accounts as vendors and as organizers: `analytics_excluded_user_ids`, users flagged `_lnkup_test_account`, and Communications test recipients.
- **Evidence:** `opportunity-funnel-test.php`.

### Discovery
- **Verified unchanged:** saved-search alerts (`matching-alerts-test`), reapply rules (`reapply-policy-test`), match funnel (`match-funnel-test`).
- **Not built:** an external-listing label and claim path in Next.js and Expo. External listings stay off by default (redesign decision), and the apps never show them, so nothing is mislabeled. WordPress keeps the opt-in filter and claim route.

## Phase 5 results: internal operations workspace

- **One queue for staff:** `LNKup_Ops_Overview` gathers exceptions from seven sources into `GET /admin/ops/queue` (manage-all). Each item has a severity, age, owner, the affected records, and a link to the screen where the decision is made. Items are sorted by severity, then age.
  - **Applications:** review waiting more than 5 days, approved but past the payment deadline, info request unanswered for 7 days. Grouped per event and kind, so one busy event is one item.
  - **Payments:** reconciliation errors, reconciliation not run in 3 hours, webhooks unprocessed after 15 minutes.
  - **Events:** readiness below 100% within 14 days.
  - **Trust:** pending organizer verification, disputed claims and open cases, as counts and links only.
  - **Communications, matching and partners:** failing health checks, stale matching jobs, commissions waiting for a decision, and held payouts.
- **Excluded:** test and demo accounts, using the same exclusion list as the funnel.
- **Dismiss, snooze, restore:** `POST /admin/ops/queue/state`.
  - A dismissed item comes back when it changes, for example a higher count or severity.
  - A snooze lasts 1 hour to 14 days.
  - Every change writes a Trust audit row with the note. State is stored in `{prefix}lnkup_ops_item_state`.
- **Screens:**
  - wp-admin **LNKup → Operations queue**: admin-post with a nonce and a manage-all check.
  - Next.js Admin Lite `/admin/ops`, linked from Admin → More. Its BFF `/api/admin/ops` uses the same `ENABLE_OPS_ANALYTICS` gate and admin session as other Admin Lite pages. Loading, empty and error states.
- **Privacy:** the queue returns no document names, trust case summaries, evidence or fingerprints. The queue sends no push notifications.
- **Evidence:** `ops-overview-test.php` (43 checks: organizer and vendor 403, grouping, exclusion, dismiss until changed, snooze expiry, restore, audit rows, privacy), in the Organizer Growth runner. The wp-admin page renders without notices.

## Phase 6 results: partnerships and rollout plan

- **Verified unchanged:** partner ownership, approval, codes, attribution conflicts (`LNKup_Attribution_Arbiter`), one commission per payment, refund reversal and clawback, hold → approve → paid, and suspension (`run-partnerships-php.ps1`, `comms-partners-test`).
- **Fixed: partner balances stopped at 50 commissions.**
  - `balances_for_connector()` and `count_by_connector()` summed the ledger list, which returns the 50 newest rows. A partner with more history saw low approved, paid and reversed totals.
  - Both now read every row. Per-referral approved earnings in the funnel also read every row and use the net amount, so a partly refunded commission no longer shows in full.
- **Fixed: "Become eligible for first payout"** counted held and unapproved commissions toward the minimum, while the step said approved only. It now uses approved commissions.
- **Ledger history is paged:**
  - `/connector/earnings` takes `page`/`per_page` and returns `ledger_total` and `ledger_has_more`.
  - Balances, the next payable date and the estimate cover every commission, whatever the page.
  - The estimate now counts each payment once.
  - The WordPress dashboard and the Expo Earnings screen have "Show older commissions". The web filter also gained "Under review" (`on_hold`).
- **Program terms:** Expo Earnings links to the program's own terms (`terms_url`) when set. Lead status, terms summary and resources were already available in the apps or on the web dashboard.
- **Meaning of each state in the apps:**
  - Estimated is a projection and never payable.
  - Pending covers the refund hold plus LNKup review.
  - Under review means an admin hold.
  - Approved means payable.
  - Paid and reversed are final.
  - Verified against the REST contract the app reads.
- **Tour attribution rollup (admin only):** `GET /partners/tours/{id}/attribution`.
  - Per stop and per partner: paid bookings, attributed bookings, booth value, and commission by state.
  - Each payment counts once, even with two commission rows.
  - Refunded bookings stay counted and their commission shows as reversed. Unpaid bookings and events outside the tour are left out.
  - Organizers get 403.
- **Payouts stay manual.** The KYC and 1099 dependency is in [ROLLOUT_PLAN.md](ROLLOUT_PLAN.md).
- **Evidence:**
  - `partner-earnings-contract-test.php`, added to the partnerships runner. Its checks cover 66 commissions across all states, paging, the payout step, the tour rollup and permissions.
  - Expo `partner-home.test.ts`: 15/15.
  - `connector-program.spec.ts`: 52 passed, 10 skipped. Its interest page check now asserts on the visible "Charlotte" text, since the hidden hero stamp also matches.

## Running checklist

- [x] **Phase 0:** integration branch, bootstrap files tracked, lint, activation, typechecks ([PR #9](https://github.com/sixstarsent1-png/LNKUP-Marketplace-Vercel/pull/9))
- [x] **Phase 1:** matrix, release checks, private-upload probe. Open: Vercel Root Directory (project owner), SiteGround Direct Delivery (hosting), PR #7 merge.
- [x] **Phase 2:** booking journey and financial integrity. Open: Expo drafts list ships with PR #10; live Stripe test-mode card run needs staging keys.
- [x] **Phase 3:** organizer management, tours, event day. Open: shared team CRM, waitlist offers in the apps, `$request['id']` audit outside operations.
- [x] **Phase 4:** acquisition, discovery, communications, analytics. Open: external listings in the apps; `task_digest` and document updates through Communications; no live sends in this pass.
- [x] **Phase 5:** internal operations workspace. Open: Admin Lite needs `ENABLE_OPS_ANALYTICS` on the Vercel project (project owner).
- [x] **Phase 6:** partnerships and rollout plan ([ROLLOUT_PLAN.md](ROLLOUT_PLAN.md)). Open: payouts stay manual until KYC and 1099 (finance owner).
- [ ] **Phase 7:** demonstrations and handoff

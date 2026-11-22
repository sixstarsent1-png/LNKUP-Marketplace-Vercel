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
| Partner programs and ledger | `includes/partnerships/*`, `LNKup_Attribution_Arbiter` | Expo `(partner)` workspace, web dashboard | Built, needs verification | Tour attribution rollup; payouts stay manual |
| Private document sharing | `document_vault_sharing`, `class-document-migration.php` | none | Blocked by an external dependency | SiteGround NGINX Direct Delivery serves `/uploads/lnkup-private/` (hosting owner) |

## Running checklist

- [x] **Phase 0:** integration branch, bootstrap files tracked, lint, activation, typechecks ([PR #9](https://github.com/sixstarsent1-png/LNKUP-Marketplace-Vercel/pull/9))
- [ ] **Phase 1:** matrix, release checks, private-upload probe
- [ ] **Phase 2:** booking journey and financial integrity
- [ ] **Phase 3:** organizer management, tours, event day
- [ ] **Phase 4:** acquisition, discovery, communications, analytics
- [ ] **Phase 5:** internal operations workspace
- [ ] **Phase 6:** partnerships and rollout plan
- [ ] **Phase 7:** demonstrations and handoff

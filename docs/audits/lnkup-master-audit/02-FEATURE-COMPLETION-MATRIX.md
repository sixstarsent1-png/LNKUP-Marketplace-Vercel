# 02 — 20-system completion scorecard

## How to read the numbers

- **Built %** = share of the capabilities listed for that system (taken from the audit brief) that have an end-to-end implementation in code: server logic plus at least one usable interface. Built = 1, Partial = 0.5, Missing = 0. It does **not** count files or lines.
- **Verified** = the strongest evidence that the code works:
  - **R** = I ran it in this audit
  - **T** = repo test suites exist and their recorded results pass on wp-env, Expo jest or Playwright; I did not re-run all of them
  - **S** = static reading only
- **Live** = present on the production plugin line (H) *and* not behind a default-off flag:
  - **Yes** = live
  - **Gated** = off by default; production value unknown
  - **No** = the code is not on the production line
- Statuses use the brief's vocabulary.

## Summary scorecard

| # | System | Built % | Status | What exists | What's missing | Main blocker | Verified | Live | Priority |
|---|---|---|---|---|---|---|---|---|---|
| 1 | Financial OS & Stripe | 94% | IMPLEMENTED, UNTESTED against Stripe | Fee engine, checkout, signed webhooks (23 types), ledger, invoices, receipts, refunds, disputes, Connect, cash-out, reconciliation/repair | Invoice total follows fee changes; Next.js Connect onboarding; a real Stripe test-mode run | Production line lacks master's money fixes; no Stripe test-mode journey ever run | T (wp-env, Stripe off), R (14 PHP suites, see 09) | Yes (partial fixes) | P0 |
| 2 | Organizer Acquisition OS | 90% | FLAGGED OFF | Full wp-admin CRM: leads, pipeline, tasks, import + rollback, dedupe, scoring, campaigns (`wp_mail`), spend, supply intel, analytics | Assignment rules or round-robin; sending through Communications (it uses raw `wp_mail`); activation linked to milestones | `organizer_growth_enabled` off; nobody confirmed using it | T | Gated | P1 |
| 3 | Onboarding, migration, tours/series | 80% | PARTIALLY IMPLEMENTED (gated) | Onboarding v2 flow, create/publish with retries, CSV vendor import, templates, recurrence, tours with stops, public tour pages, multi-stop apply | Organizer tour tools in either app; existing-contact migration beyond CSV; tour-level docs/tasks rollup on production line | `portfolio_os_enabled`, `organizer_onboarding_v2_enabled`, `tour_multi_apply_enabled` off | T | Gated | P1 |
| 4 | Matching & discovery | 85% | FLAGGED OFF | Server-side engine: eligibility, weighted score, distance, explain, vendor index, recruitment shortlist, saved searches, favorites, alerts | Recommendations visible without flags; reputation signals | `matching_enabled` off | T | Gated (saved searches and favorites live) | P1 |
| 5 | Organizer management | 70% | PARTIALLY IMPLEMENTED | Requests inbox, review v2 queue + reviewers + bulk, waitlist offers, booths, roster, tasks, readiness, teams, staff matrix, multi-event | Visual booth layout upload (broken), teams in any app, tasks/readiness in apps (production line), shared team CRM | Review v2, teams, ops v2 all flagged off; floor-plan upload broken | T | Mixed | P1 |
| 6 | Vendor Passport & reputation | 75% | FLAGGED OFF (core profile live) | Profile, completeness, verification records, vault, expiry, participation history, badges, reviews + responses, public profile, privacy | Document sharing (blocked by flag); Next.js client | `passport_enabled` off; vault sharing needs probe (M only) | T | Gated | P2 |
| 7 | Communications & engagement | 85% | FLAGGED OFF (legacy sends live) | Engine, Action Scheduler queue, inbox, email, Expo push + receipts, 83 triggers, preferences, suppression, campaigns, click tracking, health | Email provider/webhook (plain `wp_mail`); payout messages; several Organizer OS sends bypass it on production line | Every `flow_*` off; no delivery provider configured | T | Gated | P1 |
| 8 | Mobile app experience | 75% | PARTIALLY IMPLEMENTED | 53 screens, three role groups, live data with honest errors, push registration, drafts, check-in list, money, partner workspace | Camera QR, offline check-in, Android store release, iOS links for events, Android App Links, App Store users on current JS | App Store binary 1.0 vs OTA runtime 1.0.1; Android not published | R (tsc 0, jest 200/200) | Partly (store 1.0) | P0 |
| 9 | Referral, affiliate, partnership | 85% | IMPLEMENTED, UNTESTED in production | Programs, applications, versioned commission rules, attribution arbiter, ledger states, reversals, connector dashboard, Expo partner workspace | Automated payouts (manual by design); anti-fraud beyond same-user check; sane default economics | Default City Connector rule pays 10% of booth GMV, uncapped | T | Yes | P0 (economics) |
| 10 | Analytics & intelligence | 55% | PARTIALLY IMPLEMENTED | Organizer intelligence, growth analytics, supply intel, match funnel, financial reports | Marketplace funnel and test-account exclusion (M only); Next.js admin Money/Events/Users are fake | Funnel not deployed; mock admin pages | S/T | Partial | P1 |
| 11 | Marketing, SEO & distribution | 55% | PARTIALLY IMPLEMENTED | Rank Math + `class-seo.php`, Event JSON-LD, canonical, sitemaps, referral links, embed button | City pages (404 live), `og:image` on events, sitemap hygiene, vendor/organizer sitemaps | Demo pages indexed; market pages off | R (production) | Partly | P1 |
| 12 | Enterprise & integrations | 45% | PARTIALLY IMPLEMENTED | Brands, teams/memberships, custom application questions, embed card, CSV exports, templates | Outbound webhooks/API keys, enterprise reporting, multi-org switching UI | Teams flagged off; no integration API | S | Gated | P3 |
| 13 | Booking & application experience | 80% | PARTIALLY IMPLEMENTED | Apply, server drafts + resume, questions, change detection (409/422), withdraw/reapply, multi-stop apply, fees shown, checkout, timeline, pass, refund requests | Bookings Center (M only); booking modifications; Expo drafts list; drafts flag off | `apply_wizard_enabled` off; Bookings Center not deployed | T | Mixed | P1 |
| 14 | Event-day ops & check-in | 70% | PARTIALLY IMPLEMENTED | Passes, QR tokens (hash index), web scan, manual lookup, locks, duplicate-scan message, roster, booths, load-in, packets, broadcasts, incidents | Camera scan on iPhone (web and app), offline queue, signed QR | No `expo-camera`; `BarcodeDetector` unsupported in Safari | T + S | Yes | P1 |
| 15 | Trust, safety & compliance | 65% | PARTIALLY IMPLEMENTED | Organizer verification (domain), event authenticity, claims, cases + notes + transitions, audit log + verify, publish gate, private docs folder, Stripe chargebacks | User "report listing", account restriction/suspension, token revocation on password reset, fraud signals | Publish gate + teams off; no reporting path | T + R (production probes) | Mixed | P0 (security items) |
| 16 | Retention | 55% | FLAGGED OFF | Retention automations (no-applications nudge, win-back, cadence), rebook vendors, saved-search digests, recommendations | Vendor win-back, loyalty, customer-success console, churn dashboard | All flows off | S/T | Gated | P2 |
| 17 | Support & resolution | 30% | PARTIALLY IMPLEMENTED | Event-day vendor→organizer requests, static help articles, refund + dispute flows, trust cases (staff) | Platform support tickets, assignment, SLA, resolution history for users, support analytics | Not built | S | Partly | P1 |
| 18 | Monetization & pricing | 60% | PARTIALLY IMPLEMENTED | Fee engine (checkout fee + commission + rules), promo codes, credits, subscriptions (Stripe Billing) | Premium listings purchase (dead code), financing + insurance (placeholders), fee/profit reporting | Unclear product decision | S | Yes (fees) | P2 |
| 19 | Public website & UX | 75% | PARTIALLY IMPLEMENTED | Redesigned home, Explore/Find, event pages, organizer/vendor public pages, sign-up/log-in, responsive | City pages, social images, demo-page cleanup, mixed content, M-only Explore UX not deployed | Mixed deploy | R (production) | Yes | P1 |
| 20 | Admin command center | 55% | PARTIALLY IMPLEMENTED | 25 wp-admin screens, reconciliation, comms health, trust queue, partner reviews, Admin Lite (Next.js) | One ops queue (M only), feature-flag overview, background job monitor; Next.js admin uses fake data | Fragmented; ops queue not deployed | S/T | Partial | P2 |

**Overall:** the codebase is unusually complete for its stage. Most systems are 70–90% built in code. The weak points are not missing code. They are:

1. **Release control.** Two diverged lines, manual deploys, a stale web app and an old App Store binary.
2. **Default-off flags.** Production values are unknown, so twelve systems can't be called live.
3. **Missing proof against real services.** No Stripe test-mode journey, device push, iPhone scanning or App Links.
4. **A few real defects and risks.** Listed in 04 and 05.

---

## Feature checklists by system

Legend:
- **B** = built
- **P** = partial
- **N** = not implemented
- **X** = broken
- **G** = built but flag-gated
- **M** = present only on master (not on the production line)

### 1. Financial OS & Stripe — 16/17

| Capability | Status | Evidence |
|---|---|---|
| Stripe Connect, organizer onboarding, connected accounts | B | `includes/financial/class-stripe-connect.php` (`map_account_status`, `account.updated` handler); WP `financial.js`, Expo `lib/api/organizer-financial.ts`; **no Next.js client** |
| Vendor payment flow, Payment Intents, Checkout Sessions | B | `class-checkout.php`, `class-stripe-provider.php`, `class-financial-service.php::finalize_payment` |
| Webhooks | B | `class-stripe-webhooks.php`: 23 event types; signed with a 300s tolerance (`class-stripe-client.php::verify_webhook`); claim/`INSERT IGNORE` replay guard; a late "failed" event can't undo `paid` (L369-379) |
| Booking confirmation | B | `finalize_payment` → pass unlock; `booking-lifecycle-test.php` on M only |
| Ledger, organizer earnings, vendor history | B | `LNKup_Transactions`, `organizer_money_contract`, `vendor_money_contract` |
| Invoices | P | Total frozen at creation (`class-invoices.php` L184-198); `refresh_pending_fees` (`class-payments.php` L339) re-prices the payment at checkout without updating the invoice. Paging/sort fixes partly M only |
| Refunds, disputes | B | `LNKup_Refunds`, `LNKup_Financial_Disputes`; chargeback events handled |
| Payouts | P | Organizer cash-out exists; partner payouts manual; payout notifications stay off until reconciliation sign-off |
| Fee configuration | B | `class-fee-engine.php` (see 18) |
| Reconciliation, repair | B | `LNKup_Financial_Reconciliation`, `LNKup_Financial_Repair` (wp-admin only) |
| "Processed payments not appearing / $0 invoices / wrong organizer totals" | **Mostly fixed in code; fix not fully deployed** | M fixed vendor totals missing `partially_paid`/`partially_refunded`/`disputed` and paging of invoices/receipts (BUILDOUT Phase 2D); **H lacks the receipt paging and the money-contract change** (diff `HEAD..origin/master` `class-financial-service.php`, `class-receipts.php`). $0 invoices for free booths are suppressed from email (`class-invoices.php` L48). The invoice/charge mismatch above remains on both lines |

### 2. Organizer Acquisition OS — 9/10
Code: `includes/organizer-growth/*` (17 classes), REST `/growth/*` (`class-org-growth-rest.php`), UI `admin/js/organizer-growth.js` (1,621 lines). Gate: `can_use()` needs the manage capability **and** `organizer_growth_enabled`.

| Capability | Status | Evidence |
|---|---|---|
| Lead CRM, imports (preview, run, rollback), dedupe/merge | G | routes L92-151 |
| Lead scoring | G | `/scoring/config`, `class-org-lead-scoring.php` |
| Outreach tracking, campaigns | G | `class-org-outreach.php` (approve, send through `wp_mail`, logged per recipient), `/growth/unsubscribe` public |
| Acquisition pipeline, tasks with assignee | G | `/pipeline`, `/tasks?assigned_user_id` |
| Sales assignment rules | P | per-task assignee only; no routing |
| Onboarding tracking, activation, first booking milestone | G | `LNKup_Organizer_Activation`, `org_growth_activation_paid_bookings` |
| City Connector integration | G | `/growth/connector/supply` (partner permission) |
| Referral attribution | B | `LNKup_Attribution_Arbiter` |
| Acquisition analytics (cohorts, at-risk, spend) | G | `/analytics*`, `/spend*` |
| House vs independent organizers (Six Stars) | G | `org_growth_house_organizer_ids` |

Verdict: a working internal sales platform in code, with tests. It's off by default and wp-admin only.

### 3. Onboarding, migration, tours/series — 12/15

| Capability | Status | Evidence |
|---|---|---|
| Registration, progressive onboarding, resume | G | `LNKup_Organizer_Onboarding_Flow`, `/organizer/onboarding` (production returns 401 when logged out, so the route is live); clients: WP, Next.js, Expo |
| Organization profile | B | `/organizer/profile`, logo, cover |
| Event creation wizard, publish | B | `/organizer/launch/publish`; row lock + `client_token` (M hardens further) |
| CSV vendor import | B | `class-vendor-import.php`, `vendor-import.js` (WP only) |
| Existing-contact migration | P | CSV plus outreach; no third-party import (Eventbrite/POSH are *event* sources, not contacts) |
| Templates, recurring events, bulk creation | G | `/portfolio/templates*`, `LNKup_Portfolio_Recurrence::generate` (idempotency key) |
| Multi-city tours, stops, series, shared config, per-stop overrides | G | `class-portfolio-tours.php` (stops are real events; cancel/archive rules) |
| Multi-stop vendor applications | G | `LNKup_Application_Groups`, `tour_multi_apply_enabled`; Next.js + Expo vendor side |
| Tour dashboards | P | `LNKup_Portfolio_Reporting::summary`; per-stop readiness/tasks is M only; organizer tour tools are WP only |

### 4. Matching & discovery — 12/14

| Capability | Status | Evidence |
|---|---|---|
| Vendor↔event matching, scores, eligibility | G | `class-match-engine.php::eligibility()` L97, `score()` L150; weights in `class-match-settings.php` |
| Geographic filtering, preferred cities | G | `distance_miles` (haversine) L483; preferred-city factor L281 |
| Organizer vendor search, recruitment, invites | B/G | `class-vendor-directory.php`, `/match/organizer/events/{id}/vendors`, shortlist; WP, Next.js, Expo |
| Saved searches, saved opportunities | B | WP, Next.js, Expo all wired |
| Tour matching | G | `matching_tours_enabled` |
| Relevant-event alerts | G | comms `flow_discovery*` (off) |
| Uses real data | Yes | server-side scoring over a vendor index table, not front-end filtering |
| Reputation signals in matching | N/G | `matching_reputation_signals` waits on Passport |

### 5. Organizer management — 10.5/15

| Capability | Status | Evidence |
|---|---|---|
| Vendor CRM (notes, lists, invites, tags) | B | `organizer-crm-rest`; Expo has no lists |
| Application dashboard, review | B | Requests inbox (WP, Next.js, Expo) |
| Review v2 queue, reviewer assignment, bulk | G | `/applications/review/queue`, `/reviewers`, `/bulk/*` |
| Waitlists, waitlist offers | B/G | waitlist status live; offers gated; not in apps |
| Booth inventory, assignment | B | `class-booths.php` (ownership check L66); WP + Expo check-in; **no Next.js** |
| Booth/parking **editing** from operations | X (production line) | `class-operations-rest.php` reads `$request['id']`, which a JSON body `id` overrides → "Event not found". Fixed on M (`route_id`) |
| Visual booth layout / floor-plan upload | X | `public/js/maps.js` L391 posts to `wp/v2/media`; organizers don't have `upload_files`; ORGANIZER_AUDIT_MATRIX O25 "Open" |
| Event tasks, readiness | G | `class-event-tasks*`, `class-event-readiness`; apps only on M |
| Team management, role permissions | G | `/org/team*`; `staff_can()`; **no app UI** |
| Multi-event management | B | |
| Event operational reporting | P | intelligence funnel and comparison |

### 6. Vendor Passport & reputation — 10.5/14
Already existed before the expansion: profile, verification badge, participation history, reviews. Added (`includes/passport/*`, all gated by `passport_enabled`): document vault, expiry tracking, verification records, privacy, public projection, review responses, history v2, reputation summary. Document vault **sharing** is gated by `document_vault_sharing`, and on master also by the private-uploads probe. Clients: WP `passport.js`, Expo `(vendor)/passport.tsx`; **no Next.js**.

### 7. Communications — 15/18
`lnkup-communications` (README architecture verified in code): dispatch → Action Scheduler → deliver (inbox, `wp_mail`, Expo push with receipts at `class-comms-channel-push.php` L21-22). 83 triggers, preferences, suppression (hashed), campaigns, signed click tracking, health, CLI. Push devices: Expo `lib/api/push.ts` → `/push/devices`.

- **Partial:** delivery logs exist, but there's no email provider webhook (`email_provider` empty by default).
- **Partial:** failed-message handling covers push receipts only.
- **Partial:** several Organizer OS messages bypass Communications on the production line (fixed on M with `send_keyed`).
- **Status:** event-driven listeners exist for applications, payments, documents, events, operations, partners, passport and account. Every flow is off by default, so production currently relies on the legacy `LNKup_Notifications` emails and `LNKup_Push_Service`.

### 8. Mobile app — see 03 for the parity matrix
Built: auth (password and Bearer token, SecureStore), registration, role routing, vendor and organizer homes, discover, search, map (native), public profiles, events, tours (vendor side), apply + drafts, booking detail, Stripe checkout (in-app browser + native return), documents, notifications, push, referrals, partner workspace, check-in (list/manual), money, settings.

Missing or partial:
- camera QR (N)
- offline check-in (N)
- team (N)
- event tasks (N on H)
- CRM lists (N)
- organizer tour tools (N)
- waitlist offers (N)
- external-event claim (N)

**Native vs OTA:** the App Store binary is version 1.0, while updates target runtime 1.0.1 (`app.json` `runtimeVersion.policy = appVersion`). Any new native module (for example `expo-camera`) needs an EAS build and store submission.

### 9. Referral & partnership — 13/15
`includes/partnerships/*`; versioned rules in `class-commission-rules.php`:
- **City Connector v1:** basis `booth_gmv`, rate `connector_booking_share_percent` default **10**, cap default **0 (none)**, 12 months, 30-day hold (L36-50).
- **Organizer recruit:** basis `platform_fee`, 20% (L84-95).
- **Affiliate and strategic partner:** rate 0 until an admin publishes terms.

Ledger states: estimated, pending, under review, approved, paid, reversed. Reversals and clawback exist.
- **Anti-fraud:** only same-user self-referral (`class-organizer-recruit-referrals.php` L428) plus refund/dispute/failed-payment flags (`class-fraud-flags.php`).
- **Payouts:** manual.

### 10. Analytics — 8/14
- Built: organizer intelligence (funnel, comparison, rebook), growth analytics, supply/demand by city, match funnel, financial reports, CSV exports.
- **M only:** marketplace funnel `/admin/analytics/funnel` (`class-opportunity-funnel.php`) and test-account exclusion (`class-analytics-exclusions.php`). Not on production.
- **Fake data:** Next.js `app/admin/money/page.tsx` (hardcoded transactions L12-17; "revenue = GMV × 0.1" L22, which isn't the fee model), `app/admin/events`, `app/admin/users`. Both lines.

### 11. SEO — verified on production
- Event pages: title, canonical, `index`, JSON-LD `Event` + `Place` + `PostalAddress`. Good.
- **No `og:image`.**
- Sitemaps include WooCommerce demo products (`/product/fitness-trainer-product-1..3/`), demo pages (`/fitness-trainer-shop/`, `/my-services/`, both indexable, 200), and app pages (`notification-settings`, `event-communications`, `connector-dashboard`, dashboards, log-in).
- City pages `/markets/{slug}/` return **404**.

### 12–20
See the summary row and 04 for the defects. Evidence for each is in 04 and 05.

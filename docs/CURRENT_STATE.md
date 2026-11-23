# LNKup Marketplace Platform — Current State

Complete inventory of the LNKup vendor opportunity marketplace monorepo.

For the full architecture reference, see [SYSTEM_OVERVIEW.md](SYSTEM_OVERVIEW.md) (includes **App Roadmap Phases 1–26** in §14.2). For the connected organizer → vendor → money product narrative, see [E2E_MARKETPLACE_JOURNEY.md](E2E_MARKETPLACE_JOURNEY.md). For app vs WordPress parity gaps, see [APP_GAP_ANALYSIS.md](APP_GAP_ANALYSIS.md). For design tokens, components, surfaces, and copy guidelines, see [UI_UX.md](UI_UX.md).

**MVP scope:** Vendor-only marketplace layer — not attendee ticketing. Ticketing stays on Posh, Eventbrite, Universe, etc.

**Plugin version:** 12.33.4  
**Theme version:** 1.6.1

### v12.33.4 (Vendor Profile editor width)

- **Fix:** the vendor Profile editor rendered in a 280px column at 1024px+ because the theme's `.lnkup-profile-layout` grid (`280px 1fr`) applied to the single-column `--stack` layout. `vendor-profile.css` resets it (`.lnkup-profile-layout.lnkup-profile-layout--stack { display: block }`), gives the progress card and sections one 56rem column, keeps section titles and status pills on one row, uses two-column field grids from 640px, and adds `scroll-margin-top` for the "Still to finish" links. Plugin CSS only; theme unchanged.

### v12.33.3 (Organizers no longer see LNKup platform fees)

- **Money shows earnings, not fees:** organizer Money / Financial show **Earnings** (after LNKup fees and refunds), Collected (after fees, before refunds), Refunds, Pending, Available, In transit and Paid out. "Gross collected", "Platform fees" and "Net earnings" are gone on mobile and the website. Per-event collected, revenue by event / month and the organizer report (`organizer_report( $user_id, true )`) are after fees. Definitions: `docs/finance/MONEY_DEFINITIONS.md`.
- **API:** `LNKup_Organizer_Fee_Privacy` (`rest_request_after_callbacks`) strips fee breakdown fields from every organizer response under `lnkup/v1/financial/*`; admin and vendor (`role=vendor`) responses are unchanged. Instant cash-out `fee_amount` stays.
- **Unchanged on purpose:** Tax Center (raw `organizer_report()`, gross and fees), Portfolio "Platform fees" KPI, "Platform fee X% per booking" on Publish event.
- **Tests:** new `organizer-fee-privacy-test.php`; shared `money-contract-v1.json` fixture updated (no `platform_fees`); financial and other PHP suites pass; Expo Jest 191/191.

### v12.33.2 (Broken app fixes: "&amp;" text, receipts, vendor switching, event passes, Home insights)

- **"&" no longer shows as `&amp;` / `&#038;`:** organizer-saved titles were stored kses-encoded and `get_the_title()` added `&#038;` / `&#8217;`. New `lnkup_plain_title()` / `lnkup_plain_text()` (decode, keep `<` `>` encoded) replace `get_the_title()` across `includes/` JSON payloads; `LNKup_Plain_Titles` stops storing `&amp;` for LNKup post types and a one-time migration (`lnkup_plain_titles_migrated`, run by cron after upgrade) decodes existing titles and rebuilds the marketplace index. Expo `wpFetchJson` and Next `wpFetchJson` also decode entities in every JSON string except `html` / `*_html` fields (covers Communications-plugin text until that plugin is redeployed).
- **Receipts / invoices open from the app:** `view_url` / `pdf_url` are now signed for one hour (`LNKup_Signed_Links`, HMAC over type, id, user, expiry). `/financial/receipts/{id}/view` and `/financial/invoices/{id}/pdf` accept a logged-in owner or a valid signature; ownership checks still run. The app fetches a fresh signed URL on tap. Fixes the same dead links on the web app and dashboard.
- **Vendor profile switching (mobile):** `vendors/[profileId]` is keyed per vendor (stack `getId` + keyed body) with stale-response guards, so opening another vendor never shows the previous one; Find vendors opens a profile on tap with a separate select checkbox. Web drawer aborts superseded profile requests.
- **Event passes crash fixed:** booth and load-in objects from `/operations/vendor-homes` are formatted to text; pass unlock reads `qr_pass` / `packet_status` correctly.
- **Organizer Home insights:** the card now shows `recommended_actions` and event health from `weak_events` / `top_events` (it read keys the server never sends).
- **Tests:** new `text-and-links-test.php` (22), Expo Jest 190/190 (new decode, operations, insights tests); directory, settings, matching, passport, trust, organizer, booking and financial suites pass.

### v12.33.1 (Organizer Vendors: settings save, Discover speed, Find Vendors gating)

- **Settings save fix:** `LNKup_Admin::sanitize_settings()` (registered on `admin_init` for `lnkup_settings`) now only runs for the main LNKup Settings form (`option_page=lnkup_settings_group`). Before, it ran on every `lnkup_settings` write in wp-admin and rebuilt from the old values, so Matching Rules switches (and Passport / Trust & Safety settings saved from wp-admin) reverted on save. WP-CLI saves were unaffected.
- **Matching Rules form:** blank threshold boxes are ignored on save instead of read as 0 (which forced the ranking-change note and dropped invite caps to 1); the boxes show the current values (40 / 5 / 100 / 50).
- **Discover timeout fix:** `lnkup_get_discoverable_vendors()` sorts by score / rating / reliability with a per-row lookup instead of an EXISTS-OR-NOT-EXISTS meta clause. On production the old query ran past 90 s with the default "Highest score" sort, so `/crm/vendors?scope=discover` failed on the website ("Could not load vendors") and in the app ("Service unavailable").
- **`/crm/vendors` discover:** card-only profiles (`LNKup_Vendor_CRM_Profile::get_card()`, no per-organizer history, documents, notes or contact details), `html=0` for app callers, 2-minute per-organizer cache cleared on favorite / preferred toggles, and a friendly 503 (`lnkup_directory_unavailable`) instead of a hang or fatal.
- **Find Vendors gating:** new `GET /match/organizer/status` (`find_vendors_enabled`); the mobile "Recruit for an event" banner and the web app "Find Vendors" tab only show when it is on, instead of linking to "Coming soon".
- **Tests:** new `vendor-directory-test.php`, `match-settings-blank-test.php`, `settings-sanitize-test.php`; matching, passport, trust-safety, organizer and financial suites pass; Expo Jest 179/179.

### v12.33.0 (Matching Phases 8–9: tuning tools and staged rollout)

Runbook: [MATCHING_ROLLOUT.md](MATCHING_ROLLOUT.md) · Reference: [MATCHING_DISCOVERY.md](MATCHING_DISCOVERY.md) §11.

- **Staged rollout** for matching, apply and Communications discovery/recruitment flags: internal allowlist → 5% → 25% → 50% → everyone, stable per-user buckets, gated stage changes with override notes and history, `wp lnkup rollout status|set`, Marketplace Intelligence → "Rollout & health". Flags remain the kill switch; cron/compute stay global.
- **Funnel fixes:** client event allowlist + rate limit on `/marketplace/track/event`; impressions record opportunities, band, position and rules version; clicks from WordPress, Next.js and Expo rails; `match_rec_application` attribution; dashboard click count fixed (`match_click`).
- **Quality report** on Marketplace Intelligence → Overview by band, section, rules version and audience.
- **Versioned matching rules** with required notes, `rules_version` on results, preview before save (simulator, no writes) and one-click revert; flag toggles no longer trigger full rebuilds.
- **Matching health alerts** (stale queue, stalled backfill, failing runs) as admin notices and a throttled email.
- **Tests:** new `rollout-test` 32, `match-tuning-test` 31, `match-funnel-test` 24; matching, matching-alerts, reapply-policy, booking-application-os, comms, trust and organizer-activation suites pass; Expo Jest 163/163, Expo and Next `tsc` clean for touched files.

### Phase 27 — Vendor Passport & Reputation OS (passport schema 1; flags off by default)

Architecture: [PHASE_27_VENDOR_PASSPORT.md](PHASE_27_VENDOR_PASSPORT.md) · Release: [PHASE_27_RELEASE_CHECKLIST.md](PHASE_27_RELEASE_CHECKLIST.md).

**Production (thelnkup.com, 2026-11-22):** on — `passport_enabled`, `passport_history_v2`, `review_responses`, `passport_verification_v2`. Off — `document_vault_sharing` and the document migration (private uploads are still served publicly by SiteGround's NGINX Direct Delivery), `matching_reputation_signals` and `flow_passport` (waiting ~24 h).

- **Live immediately (27A hardening):** public reputation reads never write; numeric reputation routes need sign-in; slug-only public lookups honor privacy; private review notes go only to their author/staff; reported reviews stay visible until moderated; expired/pending documents are not "ready"; evidence-based completion; review disputes and unconfirmed flags never lower scores; legacy public document migration tool.
- **Behind flags:** Passport dashboard view + `/passport/*` REST + `/vendors/{slug}` (`passport_enabled`), verification records & requests (`passport_verification_v2`), Document Vault sharing (`document_vault_sharing`), achievements (`passport_history_v2`), vendor responses (`review_responses`), matching signals (`matching_reputation_signals`), notifications (`flow_passport`).
- **New tables:** `lnkup_vendor_verification`, `lnkup_document_share`, `lnkup_review_index`. Admin: LNKup → Vendor Passport & Trust.
- **Clients:** Expo `/(vendor)/passport` + organizer Passport panel + Documents shares; Next.js review responses and indicator-based public mapper.

### Organizer Onboarding v2 — guided setup (no schema change; no plugin version bump)

Architecture: SYSTEM_OVERVIEW §9.21. Off by default: `organizer_onboarding_v2_enabled` (LNKup → Settings → "Organizer guided setup (v2)").

- **One onboarding model:** `LNKup_Organizer_Onboarding_Flow` stores only the organizer's choices (user meta `_lnkup_onboarding_state`: formats, current step, skipped steps, dismissed, started/completed). Step status always comes from `LNKup_Organizer_Activation`. Steps: account → organization → format → first event → payouts → share. Format and payouts can be skipped.
- **REST:** `GET/PATCH lnkup/v1/organizer/onboarding` (404 when the flag is off; writes are self-only).
- **Signup:** organizer signup asks for organization name, city/state and an optional category (web form, `/auth/register`, Expo). These fields are required only on the web form with the flag on, but are saved whenever they are sent. New organizers land on **Get started**, and organizers with unfinished setup or draft events/tours are sent back there at login.
- **Get started:** format cards route tours/series/recurring to Tours & Series when Portfolio OS is on (otherwise to Create Event with a note), a resume-drafts card, cover/Instagram/TikTok/service cities/category on the organization step, inline payout status with a "Finish payout setup" link, tour public links and an invite CTA in Share, and "Setup complete" copy. `?view=start&step=` focuses a step.
- **Cleanup:** with the flag on, the Overview shows a compact setup banner instead of the old checklist. The legacy organizer welcome/tour modal (`lnkup_organizer_welcome_seen`) was removed. `LNKup_Activation_Emails` skips the organizer daily drip whenever the Comms `flow_onboarding` or `flow_activation` flow is on, and skips the organizer welcome when `flow_onboarding` is on.
- **Growth/Comms:** lead activity `onboarding_format_selected` / `onboarding_completed`. Comms `organizer_onboarding_step` messages stop once guided setup is complete or dismissed.
- **Apps:** the Next.js `/api/organizer/onboarding` BFF reads and writes WP state (falls back to its cookie when the flag is off). Expo shows a **Continue setup** card on organizer home.
- **Tests:** `scripts/e2e/php/organizer-onboarding-flow-test.php`, Connect "verifying" case in `organizer-activation-test.php`, `tests/e2e/organizer-onboarding.spec.ts` (seed: `scripts/e2e/seed-onboarding.php`).

### Organizer Portfolio OS — tours, recurring series, templates (portfolio schema 1; no plugin version bump)

Architecture: SYSTEM_OVERVIEW §9.24. Off by default: `portfolio_os_enabled` (everyone) or `portfolio_os_user_ids` (early-access organizer IDs) under LNKup → Settings → Vendor applications.

- **Built on the existing tour model:** `lnkup_tour` (types `tour`, `series`, new `recurring`) groups ordinary `lnkup_event` stops through `_lnkup_tour_id`. No parallel series CPT. Every stop keeps its own opportunities, applications, inventory, payments, packets and check-ins.
- **Stops:**
  - Created in bulk through `create_event_from_request()` with client token `tour{ID}_{stop key}`, so a resubmitted table never duplicates a stop.
  - Draft stops are unpublished events, so the marketplace index, public pages and `show_closed` never show them.
  - Publishing checks readiness first: date, confirmed venue and address, city/state, time zone, at least one booth type, deadline. A stop without a venue stays a draft.
- **Defaults and overrides:**
  - Tour defaults (`_lnkup_tour_defaults`) define booth types, prices, capacities, documents, questions (`LNKup_Application_Questions`) and the deadline offset once.
  - Stop overrides (`_lnkup_stop_overrides`) always win.
  - Publishing creates one opportunity per booth type and stores `_lnkup_booth_type_key` on it.
  - Bulk price and capacity edits are previewed first. Live stops change only with `confirm_published`, through `update_opportunity_pricing()`, which refuses a capacity below the booths already held. Existing payments keep their amounts.
- **Recurring series:**
  - Generation is capped at 52 dates per run and needs an end date or a count.
  - Dates use the series' IANA time zone, so 10:00 local stays 10:00 across a daylight-saving change.
  - Each date has the key `o{Ymd}`, so generating twice never duplicates a date.
  - Single dates can be skipped, restored, moved or cancelled. "Change remaining" removes only untouched future drafts.
- **Templates:** `lnkup_event_template` stores immutable versions in `wp_lnkup_template_versions`. Tours and stops record the version they were built from. Templates contain configuration only.
- **Brands:** `lnkup_brand` (optional) groups tours under one event identity.
- **Background jobs:** batches of more than 10 stops run in `wp_lnkup_portfolio_jobs` in chunks of 10 through WP-Cron. Polling the job status resumes a stalled job.
- **Reporting:** tour totals come only from each stop's `lnkup_payment` posts, each payment counted once, with refunds from the ledger. Gross, platform fees, pending and refunds are reported separately, along with a `reconciled` check against the per-stop totals. Historical or imported payments are never included.
- **Deletion safety:**
  - Stops with applications can only be cancelled, through `LNKup_Event_Status`, which notifies vendors.
  - A tour with applications can only be archived.
  - Deleting a tour that never had applications detaches its stops and keeps them as standalone events.
  - Every change is written to `wp_lnkup_portfolio_audit`.
- **Multi-stop applications and tour interest:** the multi-stop apply flow reuses the existing `LNKup_Application_Groups` (`/tours/{id}/apply`). The new `wp_lnkup_tour_interest` table records "interested in the whole tour". It is never an application. Organizers invite interested vendors or past applicants to specific live stops through `LNKup_Vendor_Invites`.
- **Organizer UI:** dashboard view `?view=tours` (`public/js/portfolio.js`, `public/css/portfolio.css`).
  - Portfolio list with filters, templates and brands.
  - Setup flow: identity, dates (table, spreadsheet paste or schedule builder), booths and questions, pricing, then review and publish.
  - Tour dashboard tabs: Overview, Stops, Booths & questions, Pricing, Applications (cross-stop bulk actions), Vendors (interest and invites), Settings (audit log).
  - The Events page links to it when the flag is on.
- **Public pages:** `/tours/{slug}/` and `/series/{slug}/` (theme `page-templates/tour-public.php`, plugin fallback in `public/templates/`). They show published, upcoming stops only, a city filter, per-stop apply links, the existing multi-stop apply link and the interest toggle. Discovery cards show a "Part of {tour}" link.
- **REST:** `lnkup/v1/portfolio/*` (about 45 routes; organizer + flag + tour ownership; stop routes also verify the stop belongs to the tour and organizer). `GET /applications/organizer` accepts optional `tour_id` / `event_ids[]`.
- **Tests:**
  - `scripts/e2e/php/portfolio-os-test.php`: 117 assertions pass on wp-env. It covers the 7-stop tour with an Atlanta override, multi-stop apply (approve 2, reject 1, pay 2), reconciliation, the monthly series with a skip and a move, DST, templates, the 14-date background job and cross-organizer denial.
  - `tests/e2e/portfolio-os.spec.ts`: 3 pass on Chromium after `scripts/e2e/seed-portfolio.php`.

### Partner and referral notifications (lnkup-communications, `flow_partners`, off by default)

Details: [PARTNERSHIP_OS.md](PARTNERSHIP_OS.md#partner-and-referral-notifications-lnkup-communications-flag-flow_partners-off-by-default).

- New `partner` recipient role (active City Connector / affiliate / strategic contact accounts) and `partnerships` category; partner messages go only to the owning account and are email only for now.
- Triggers for application approval, referral registered and milestones, commission recorded/approved, daily-grouped payout records, lead progress, referral-invite joins and credits, plus admin alerts for new applications and open partner reviews.
- Commission notices re-read the ledger at send time and are cancelled on refund; reversed, held or conflicted commissions are never announced. Applicant emails come from the marketplace mailer with suppression checks and audit entries.
- New core hooks: `lnkup_partner_referral_attributed`, `lnkup_partner_referral_milestone`, `lnkup_org_lead_stage_changed`, `lnkup_org_lead_outcome_changed`.
- Tests: `comms-partners-test.php` (41 assertions) plus all comms, partnership and Organizer Growth suites pass on wp-env.

### Partnership OS Stages 4–5 — Affiliate and Strategic Partner programs (12.30.0+, partner schema 1.1.1)

Details: [PARTNERSHIP_OS.md](PARTNERSHIP_OS.md#stages-45-affiliate-and-strategic-partner-programs-plugin-12300-schema-111).

- **Generalized partner accounts:** `lnkup_connectors.program` (`city_connector` | `affiliate` | `strategic_partner`); affiliates and partner contacts get the new `lnkup_partner` role, `AFF-` / `PTR-` codes and the same attribution, ledger, arbiter and dashboard. City Connector behavior, ranks and the 10% GMV rule are unchanged.
- **Earning gated by published terms:** affiliate and strategic partner rules seeded as drafts; referrals tracked, nothing earned and no rates shown until an admin publishes a rule. Strategic organizations can select an agreement rule that never replaces the program rule.
- **Applications:** public `/affiliate-program/` and `/partner-with-lnkup/` pages (terms from the published rule, FAQ, disclosures), `POST /partners/apply` with honeypot + hashed-IP rate limit; admin Applications tab with audited Submitted → Under review → Info needed → Approved / Declined / Suspended / Closed.
- **Strategic Partners tab** on the existing `lnkup_org_partners` table: pipeline stages, owner, follow-up, agreement rule, contacts, performance; dedupe by domain/name and links the existing CRM lead.
- **Public copy fix:** `/connector-interest/` no longer hardcodes "10% commission"; it renders the published rule.
- **Tests:** `scripts/e2e/php/partnerships-programs-test.php` passes on wp-env alongside the Stage 1–3 suite; Playwright additions in `tests/e2e/partnerships.spec.ts`.

### Organizer Management OS — rollout prep (no schema change)

- Review workspace: rubric editor (PUT `events/{id}/rubric`) and waitlist reorder (`opportunities/{id}/waitlist/reorder`) screens in `organizer-review.js`.
- Reviewer assignment email (`LNKup_Application_Review::notify_reviewer`).
- Pilot allowlist `organizer_os_pilot_user_ids` (admin settings field): Organizer OS flags on for listed owners and their teams (`LNKup_Feature_Flags::organizer_os()`).
- Staff nav items are always primary tier (never hidden under More).
- Organizer specs pass with flags on, apart from failures that also happen with flags off; specs that test the classic requests page use `mode=classic`.
- Enable / rollback checklist: `docs/ORGANIZER_OS_ROLLOUT.md`.

### Organizer Management OS — Wave 2 (event readiness; Organizer OS schema 2; no version bump)

Architecture: SYSTEM_OVERVIEW §9.25. New features are off by default: `organizer_ops_v2_enabled` and `organizer_document_review_enabled` (LNKup → Settings → Vendor applications). Document review also needs Passport `document_vault_sharing`.

- **Event tasks:** assignees, due dates, priority, comments, and organizer task templates with due dates counted back from the event date.
  - Event staff see the tasks; `edit_ops` manages them; assignees can mark their own done. Assigning a task never grants access.
  - Assignment email, a daily due/overdue digest (`lnkup_event_tasks_due`), and team audit entries.
  - New **Tasks** view and an event **Tasks** tab; the operations lifecycle bar shows open and overdue counts.
- **Event readiness:** 11 checks computed from event records.
  - Each item is shown as *verified* (system), *confirmed* (by a person, only where the system cannot decide), *incomplete*, or *not applicable*. A failing check is never overridden by a manual confirmation.
  - Countdown, percent and next steps in an event **Readiness** tab.
- **Organizer document review:** per-event request, accept, ask for a replacement, or waive for each required document type.
  - Expired documents show as expired even after acceptance.
  - Decisions never change LNKup's platform verification.
  - Files open only for the event owner, through the vendor's Passport share.
  - New `review_documents` team capability (Admin, Event Manager, Vendor Manager).
  - Wired into the roster's "Documents needed" group, the readiness check, the Wave 1 review drawer, and the classic "remind vendor" button.
- **Overview:** "Needs attention" (only real, non-zero counts), upcoming event cards with readiness and next step, recent activity and quick actions. Also available as `GET organizer/overview` for the apps later.
- **Tables:** `lnkup_event_tasks`, `lnkup_event_task_comments`, `lnkup_task_templates`, `lnkup_app_document_reviews`.
- **Tests:**
  - New `scripts/e2e/php/organizer-os-wave2-test.php` (59 assertions) passes.
  - The organizer-os, booking-application-os, trust-safety, reapply-policy, organizer-activation, matching, financial-security, financial-checkout, comms-organizer, portfolio-os and vendor-passport suites still pass.
  - Playwright `organizer-readiness`, `organizer-tasks` and `organizer-documents`: 10/10 (chromium). Fixtures: `scripts/e2e/seed-organizer-os.php on|off`.

### Organizer Management OS — Wave 1 (foundation and review; no version bump)

Audit: [ORGANIZER_MANAGEMENT_OS_AUDIT.md](ORGANIZER_MANAGEMENT_OS_AUDIT.md) · Architecture: SYSTEM_OVERVIEW §9.22. New features are off by default: `application_review_v2_enabled`, `waitlist_offers_enabled` (LNKup → Settings → Vendor applications) and the existing `trust_org_teams`.

- **Integrity fixes (always on):**
  - `LNKup_Capabilities::user_owns_post()` checks only the requested meta key, so a vendor no longer counts as the organizer of their own application.
  - `LNKup_Booths::save()` never writes an assignment. Editing a booth keeps its vendor, and assigning goes through the locked `assign()`.
  - Broadcast `food_vendors` / `food_trucks` / `retail` audiences filter by category. New `GET operations/events/{id}/broadcasts/preview` returns a recipient count.
  - Approved documents past their expiration date read as `expired` (not acceptable) in verification, readiness and the vendor document list. A daily `lnkup_documents_expire` cron fires `lnkup_document_expired` once per document.
  - Load-in assignment runs under `GET_LOCK`.
  - `operations/qr/scan` requires `event_id` and `check_in` on that event at the route, is rate limited, and answers "not valid for this event" for passes from other events.
  - Payments that land for an application not holding a spot are booked only if the slot still has room. Otherwise the existing `paid_after_close` review flag applies.
  - The operations snapshot only seeds booths, load-in and parking when capacity or schedule changed.
- **Performance:**
  - Roster and operations snapshot memoize the readiness checklist per request and prime application meta.
  - The vendor directory loads CRM relationships in one query.
  - QR passes are looked up through the new indexed `wp_lnkup_pass_tokens` (backfilled by cron, kept in sync on write).
- **Teams:**
  - `LNKup_Org_Teams` gains **Vendor Manager** and **Application Reviewer** roles and the `review_applications` / `score_applications` capabilities, plus `event_ids_for()`.
  - Team members without an organizer account can open the organizer dashboard (theme filter `lnkup_require_roles_allowed`) and get a staff workspace: My work, Review and Team, plus roster/check-in/ops pages their grants allow. Invite links now work for non-organizers.
  - New `GET org/team/audit` and a Team activity panel.
- **Review workspace** (`LNKup_Application_Review`, `LNKup_Application_Review_REST`, `public/js/organizer-review.js`):
  - A cross-event queue grouped over the canonical statuses, with server pagination and filters (event, tour, slot, assigned to me, category, missing documents, search).
  - A table or board view, and a review drawer with answers, documents, history with you, reviewers and a scoring rubric.
  - Advisory weighted scores, where reviewers see others' scores only after submitting their own.
  - Previewed bulk decisions using a single-use token, re-checked per application on apply, run as a background job above 50.
  - The Overview "waiting for review" link opens the queue, and a Classic view link keeps the old Requests page available.
- **Waitlist offers** (`LNKup_Waitlist_Offers`):
  - Waitlist position and reordering.
  - Offering a spot approves under the inventory lock with a 2–168 hour payment deadline. It never oversells and never charges automatically.
  - The hourly `lnkup_waitlist_offers_expire` cron voids unpaid offers and returns the vendor to the waitlist through the new `LNKup_Applications::return_to_waitlist()`. This is the only path from `payment_required` to `waitlisted`; the transitions table is unchanged.
  - Organizers can withdraw an offer.
- **New tables** (`LNKup_Organizer_OS_Tables`, option `lnkup_organizer_os_schema` = 1): `lnkup_pass_tokens`, `lnkup_app_review_assignments`, `lnkup_review_rubrics`, `lnkup_app_reviews`, `lnkup_waitlist_offers`.
- **Tests:**
  - New `scripts/e2e/php/organizer-os-test.php` (78 assertions) passes.
  - `booking-application-os`, `trust-safety`, `reapply-policy`, `organizer-activation`, `matching`, `financial-security`, `financial-checkout`, `financial-refund-dispute`, `comms-organizer` and `upsert-race` suites still pass.
  - New Playwright specs `organizer-review`, `organizer-team`, `organizer-waitlist` and `organizer-integrity`: 20 passed, 1 skipped (chromium, one worker). The skip is the booth-edit check, because `seed.php` clears booth assignments; the PHP suite covers that case.
  - Fixtures for the specs: run `scripts/e2e/seed-organizer-os.php on` after `seed.php`, and `off` afterwards so the classic Requests specs see the classic page.
  - The existing organizer Playwright specs were not re-run.

### Organizer Acquisition Phase 6 — Vendor Supply Intelligence (organizer-growth schema 1.2.0)

Architecture: SYSTEM_OVERVIEW §9.24. No plugin version bump; the snapshot table migrates through `lnkup_org_growth_schema`.

- **Daily supply snapshot** per city and vendor category: vendors based there, vendors who serve it (cities served, states served, service radius), active and verified vendors, recent applicants and paid vendors, vendors with saved searches for the city, open booths, live organizers and open organizer leads. National travellers are reported separately.
- **Signals:** Recruit organizers / Recruit vendors / Balanced / Not enough data, from configurable thresholds (Settings tab).
- **Organizer Growth → Vendor Supply tab:** filters, 7-day change, city drawer (categories, categories with vendors but no booths, 90-day trend, open leads), CSV export, Rebuild now.
- **Lead scoring** "existing vendor market" now counts active vendors who serve the city instead of every published profile. The lead drawer shows a supply card.
- **Connectors:** "Vendor supply in your cities" panel on My Cities (`GET connector/supply`, aggregates only, small counts shown as "<3").
- **Tests:** new `org-supply-intel-test.php` (62/62), added to `run-org-growth-php.ps1`. All 7 organizer-growth suites pass, as do `matching`, `matching-alerts`, `partnerships` and `partnerships-programs`. Playwright `organizer-growth.spec.ts` passes 12/12, including the 2 new Vendor Supply cases. The connector panel is covered by the PHP route tests; no browser test was run for it.

### Organizer Acquisition Phase 7 — Analytics & Activation Automations (organizer-growth schema 1.1.0 + lnkup-communications 1.2.0)

Architecture: SYSTEM_OVERVIEW §9.23. The plugin version is unchanged (12.32.0); the spend table migrates through `lnkup_org_growth_schema`.

- **Spend ledger:** Organizer Growth → **Spend** tab (form + CSV import). Entries are voided with a reason, never deleted, and audited.
- **Analytics tab:** monthly cohorts (30/60/90 days), median/p75 time to publish, first paid booking and activation, conversion by nine dimensions, per-organizer productivity, cost per acquired organizer and an at-risk list (with "Create follow-up task"). Every view splits house and independent organizers and exports to CSV.
- **Cost per acquisition** is entered spend plus approved/paid organizer-referral commissions net of reversals. It shows **Unavailable** when a channel has no cost records and **Commissions only** when no spend was entered; nothing is estimated. Overview cost cards appear once cost exists.
- **Activation automations** (flag `flow_activation`, off by default): `o_setup_profile`, `o_setup_payouts`, `o_publish_draft` and `o_first_booking`. They are driven by the activation model, re-checked before sending, and limited to one daily nudge with a shared 3-day cooldown. Attribution comes from the new first-completion stage timestamps. `no_applications_days` is configurable, and `o_publish_next` links saved setups.
- **Fixes found by the tests:** `POST growth/spend` now also accepts form-encoded bodies, and the cost table shows its empty state when there are no channels (the total row no longer forces a table).
- **Tests:** new `org-growth-analytics-test.php` (66/66) and `comms-activation-test.php` (35/35), added to `run-org-growth-php.ps1` / `run-comms-php.ps1`. All organizer-growth (6), communications (8) and financial (13) PHP suites pass. Playwright `organizer-growth.spec.ts` passes 10/10 (3 new) and `organizer-start.spec.ts` 4/4.

### v12.32.0 (Applications — Phase 5: multi-stop apply, drafts and re-apply parity)

Reference: [MATCHING_DISCOVERY.md](MATCHING_DISCOVERY.md) §10.

- **REST apply fix:** `POST applications/apply-with-profile` (and AJAX `lnkup_apply`) now pass `answers`, `document_ids`, `profile_overrides` and `Idempotency-Key`, so booths with required organizer questions can be applied to from Next.js and Expo. Error statuses are stable (409 duplicate / previously_declined, 422 missing answers with `fields`). A same-key retry returns the original application instead of a 429.
- **apply-context** adds `questions`, `vendor_documents`, `draft`, `previous_application`, `tour_id` and `features {drafts, tour_multi_apply}`.
- **Re-apply policy:** withdrawn applications can always re-apply. A declined vendor is blocked (`previously_declined`) until the organizer clicks "Let them re-apply" (`allow_reapply`), invites them again, or the booth changes (fee, deadline, categories, event date → `_lnkup_material_changed_at`). Enforced in apply, drafts, tour multi-apply, matching and the `can_apply_again` flag on cards and tour stops.
- **Housekeeping:** withdraw decrements the application count; tour multi-apply defaults each stop to the vendor's matching category; tour panel shows per-stop Apply when multi-apply is off; tour detail uses the newest application per slot.
- **Next.js:** BFF routes for drafts, withdraw and tour apply; apply modal with questions, setup notes, autosave drafts, price-change and declined states; multi-stop apply on the tour page; Withdraw / Apply again on vendor requests.
- **Expo:** questions, draft autosave/resume and declined copy on Apply; new `tour/apply/[tourId]` multi-stop screen; Withdraw on the booking screen; "Apply again" CTAs.
- **Tests:** `reapply-policy-test.php` 37/37; booking-application-os, matching, matching-alerts, trust-safety, organizer-activation, financial and comms suites pass; Playwright `apply-draft-resume.spec.ts` 5/5 (tour spec skips without its seed); Expo Jest 158/158 and `tsc` clean; Next.js `tsc` clean for all touched files.

### v12.29.0 (Organizer Acquisition wrap-up + Phase 4 Onboarding & Activation)

Architecture: SYSTEM_OVERVIEW §9.21. Stripe end-to-end check: [finance/STRIPE_TEST_MODE_RUNBOOK.md](finance/STRIPE_TEST_MODE_RUNBOOK.md).

- **Refunded-fee backfill:** reconciliation issue `refunded_fee_not_recorded`, repair item `sync_refunded_fee` (reporting only, token-gated, audited); Reconciliation page lists every organizer with refunded bookings still to fix.
- **Stripe test-mode runbook** + read-only `scripts/e2e/php/org-growth-stripe-check.php <payment_id> paid|partial|full` (refuses live keys).
- **Organizer Growth → Settings readiness panel:** postal address, house IDs, Communications loaded, daily cron, fee backfill, outreach flag.
- **Activation model** (`LNKup_Organizer_Activation`, `GET organizer/activation`) shared by the launch checklist (same shape; free booths and multi-event fixed), the new **Get started** view, Communications next-step nudges, the Expo home card and Organizer Growth.
- **Share & embed:** publish returns the direct `?apply=` link; public card route + `public/js/embed.js` Apply button.
- **Saved event setups** (`organizer/setups`, `setup_id` on create/publish) copy booth types, prices, requirements and application questions; `duplicate_event()` now copies questions.
- **Vendor import consent:** invites need a consent basis (stored on the job); `past_applicants` preset.
- **Tests:** 25 PHP suites pass (new: `organizer-activation-test` 69, `financial-fee-backfill-test` 16), Playwright `organizer-start.spec.ts` 4/4 and `organizer-growth.spec.ts` 7/7, mobile Jest 143/143, `tsc` clean.

### Trust, Safety & Compliance OS — Stages 1–3 (Trust schema v1)

Audit: [TRUST_SAFETY_AUDIT.md](TRUST_SAFETY_AUDIT.md) · Roadmap, flags and rollback: [TRUST_SAFETY_ROADMAP.md](TRUST_SAFETY_ROADMAP.md).

- **Claims:** external event claim links are bound to the invited email/domain; second claimants get `claim_disputed` + a case, never the listing; rate limits; admin approve/reject.
- **Foundation:** hash-chained `wp_lnkup_audit_log` (daily integrity check, document downloads logged, financial audit IPs hashed); `wp_lnkup_trust_cases` queue fed by fraud flags, chargebacks and rejected payment confirmations; wp-admin **LNKup → Trust & Safety**.
- **Organizers:** verification status machine + domain control (email code / DNS TXT); separate payout / domain / LNKup-review indicators; organizer **Team & Trust** view with organization teams (flag `trust_org_teams`).
- **Listings:** `_lnkup_authenticity_status`; flag `trust_publish_gate` enforced in discovery, apply and checkout; payout-account swaps and organizer changes open cases; duplicate scan opens cases only.
- **Tests:** `scripts/e2e/php/trust-safety-test.php` (65 assertions) and `trust-admin-render-check.php` (11) pass on wp-env; `tests/e2e/trust-safety.spec.ts` 9 passed / 1 skipped (no admin user seeded); `organizer-security.spec.ts` passes with 4 new cross-tenant checks.

### v12.27.0 + lnkup-communications 1.1.0 (Matching alerts — Phase 6)

Reference: [MATCHING_DISCOVERY.md](MATCHING_DISCOVERY.md) §9.

- **Match-driven alerts through Communications:** match alerts now require the engine's cached recommendation and are re-checked at send time; new triggers `v_tour_match` (one grouped message per tour), `v_weekly_matches`, `v_saved_search_match`, `v_saved_deadline`, `v_invited_to_apply`, `o_open_spots`, `o_new_matching_vendors`; new flags `flow_discovery_tours`, `flow_discovery_weekly`, `flow_recruitment_updates` (off by default).
- **Core:** `first_matched_at` on match results; `LNKup_Match_Service` alert helpers; saved-search scan paged by Action Scheduler; favorite deadline reminders pass the opportunity ID so Communications can validate them.
- **Measurement:** observed `match_alert_application` events and per-trigger counts on Marketplace Intelligence → Overview.
- **Mobile:** tour push routing, open-spots routing, new inbox types and labels.
- **Tests:** `matching-alerts-test.php` 36/36; comms suites and `matching-test.php` pass; Expo routing Jest 49/49; mobile typecheck clean.

### v12.28.0 (Referral, Affiliate & Partnership OS — Foundation)

Full audit, rules, routes, tests and rollout: [PARTNERSHIP_OS.md](PARTNERSHIP_OS.md).

- **Unified model:** program registry, versioned immutable commission rules (City Connector v1 = existing 10% of booth GMV, grandfathered for every approved connector; v2 = share of LNKup fees, draft until published), rule + basis stamped on every new reward.
- **Attribution arbiter:** earliest referral wins across programs; conflicts within 72h go to a review queue; one exclusive commission per payment (organizer side first). Ships in `shadow` mode.
- **Ledger hardening:** hold-gated, atomic approve / mark-paid (with payout reference), hold/release, proportional connector reversals on partial refunds, one clawback review per paid reward, unique reward idempotency key.
- **Admin:** LNKup → Partnerships & Referrals (overview, pipeline, rules, ledger + CSV, reviews, territories, settings). **Connector dashboard:** Organizer Leads (submits into Organizer Growth, duplicate-private) and My Cities.
- **Tests:** `scripts/e2e/php/partnerships-test.php` (116 assertions), `tests/e2e/partnerships.spec.ts` (10 Chromium tests) pass on wp-env; Organizer Growth and all 14 financial PHP suites still pass.

### v12.25.0 (Organizer Acquisition OS — Phases 0–3)

Audit, routes, test evidence and deployment notes: [ORGANIZER_ACQUISITION_AUDIT.md](ORGANIZER_ACQUISITION_AUDIT.md). Architecture: SYSTEM_OVERVIEW §9.20.

- **Money integrity (Stage 0):** vendor-referral credits only pay `program = vendor_referral`; every refund path (admin, Stripe Dashboard webhook, dispute credit) fires `lnkup_payment_refund_synced` once per increase; connector rewards/ranks and organizer-recruit rewards reverse through `LNKup_Referral_Reversals` (recruit credit clawed back proportionally, spent credit and paid connector rewards flagged once for review); `wp_lnkup_platform_fees.refunded_platform_fee` + `sum_net_platform_fees()`; lost chargebacks forfeit the fee; organizer-recruit fee fallback reads the JSON snapshot.
- **New milestone hook:** `lnkup_event_first_live` (events are inserted published before slots exist, so `lnkup_event_published` is not a usable milestone).
- **Organizer Growth admin** (wp-admin → LNKup → Organizer Growth, flag off by default): overview (house vs independent, net fees, funnel, sources, 12-week trend; CAC shown as unavailable), lead CRM (filters, saved views, bulk actions, CSV export), Kanban pipeline, lead drawer (activity, tasks, score explanation, account linking, merge review, known events), CSV import with dry run + rollback, settings + scoring weights.
- **Outreach Studio:** 12 seeded templates with declared required facts + fallbacks; campaigns exclude suppressed / unverified / recently contacted / registered leads with reasons; second-admin approval above a threshold; per-campaign daily cap; signed one-click unsubscribe, `List-Unsubscribe` headers and sender postal address; manual logging for calls, DMs, contact forms and meetings; follow-up tasks with daily digest. Plain outreach campaigns now also skip suppressed addresses.
- **Tests:** `scripts/e2e/run-org-growth-php.ps1` (184 assertions) and `tests/e2e/organizer-growth.spec.ts` (7 Playwright tests) pass on wp-env; the 13 existing financial PHP suites still pass.

### lnkup-communications 1.0.0 (Communications & Engagement OS) + lnkup-marketplace seam hooks

Full reference: [COMMUNICATIONS_AUDIT.md](COMMUNICATIONS_AUDIT.md), [COMMUNICATIONS_MATRIX.md](COMMUNICATIONS_MATRIX.md), `wp-content/plugins/lnkup-communications/README.md`. Every flow flag is off by default, and core keeps sending until a flow is enabled.

- **New plugin `lnkup-communications`:**
  - One engine for push (Expo tickets + receipts, `DeviceNotRegistered` cleanup), email (`wp_mail`, branded responsive HTML + plain text, RFC 8058 one-click unsubscribe) and the in-app inbox (reuses `wp_lnkup_inbox_index`).
  - Delivery runs on bundled Action Scheduler 4.2.0, with dedupe, send-time revalidation of canonical state, cancel keys, retries with backoff, quiet hours, frequency caps, daily/weekly digests and retention purge.
- **Flows (55 triggers):** application lifecycle; payments/refunds/disputes from verified hooks only; documents; operations; critical event cancellation; countdowns in event timezone; onboarding (new accounts only); discovery via the core match engine; organizer recruitment and retention; organizer event communications; admin campaigns; health alerts.
- **lnkup-marketplace seam edits (additive):**
  - `lnkup_pre_send_notification` delegation filter on every legacy send.
  - `LNKup_Lifecycle_Hooks` meta watcher, firing `*_status_changed` for payments, events, opportunities, documents and invites.
  - New hooks: `lnkup_refund_synced`, `lnkup_chargeback_closed`, `lnkup_connect_account_status_changed`, `lnkup_organizer_payout_status_changed`, `lnkup_checkin_recorded`, `lnkup_application_event_completed`, `lnkup_document_uploaded`, `lnkup_event_logistics_changed`.
  - Vendor withdraw (`POST /applications/{id}/withdraw`) and organizer cancel/postpone (`POST /organizer/events/{id}/status`).
  - Filters `lnkup_event_workspace_actions` and the theme's `lnkup_dashboard_nav_items`.
  - Fixes: document `expiring` status (was reported as `expired`), and external-event vendor lookup now uses `_lnkup_owner_user_id`.
- **wp-admin → LNKup → Communications:** overview, automated flows (pause/test), template editor with live preview, campaigns (segment preview, test send, confirm count, pause/cancel), delivery logs, analytics (attribution window, correlation only), settings/flags, system health.
- **Mobile (OTA-eligible JavaScript):**
  - Categorized, paginated inbox with server deep links and open tracking.
  - Full notification settings screen.
  - Contextual push pre-prompt; no longer prompts at sign-in.
  - Android channels, next-steps cards, organizer event communications screen.
  - **Native change still needed:** FCM `google-services.json` and a new EAS build for Android delivery.
- **Web (Next.js):** BFF proxies `/comms/preferences` and `/comms/inbox`, with a category × channel preferences form and inbox category tabs.
- **Tests:**
  - 7 wp-env PHP suites (`npm run test:comms:php`).
  - `tests/e2e/communications.spec.ts` (11 tests).
  - Mobile Jest: 129 tests.
  - The financial suites still pass (`financial-repair-test` failed once and passed on two reruns).

### v12.24.0 (Matching & Discovery OS — Phases 1–4)

Full reference: [MATCHING_DISCOVERY.md](MATCHING_DISCOVERY.md). All features behind flags (off by default); privacy fixes always on.

- **Engine:** `LNKup_Match_Engine` (pure, explainable): hard eligibility first, then weighted relevance (category 30 · geography 25 · requirements 20 · preferences 10 · dates 10 · signals 5); missing data is dropped and rescaled (cold start); popularity never scored.
- **Data:** vendor matching preferences, `lnkup_tour` CPT, derived tables `lnkup_match_*` and `lnkup_recruitment_*`; incremental recompute queue + nightly rebuild.
- **Vendor discovery:** recommendation sections on WordPress, Next.js and Expo; Matching Preferences on all three; tour pages; Discovery radius filtering now in SQL before pagination, real distance sort, FULLTEXT search, `sort=recommended`.
- **Organizer:** Find Vendors (public cards, reasons, shortlist, previewed batch invites with suppression reasons), recruitment pipeline, deterministic recruitment assistant, "event published" prompt, Tours & series manager.
- **Privacy:** organizer contact/doc access only after the vendor applied or accepted an invite; discovery opt-out; invite caps; admin-only platform contact export; reliability route ownership check.
- **Admin:** LNKup → Marketplace Intelligence (rules, category groups, data quality, why matched, job health).
- **Fix:** vendor profile section saves no longer blank the business description.

### v12.22.0 (Financial reconciliation — ledger integrity, money contract, repair)

Deliverables, file list and test results: [finance/README.md](finance/README.md). Root causes: [finance/ROOT_CAUSE.md](finance/ROOT_CAUSE.md). Definitions: [finance/MONEY_DEFINITIONS.md](finance/MONEY_DEFINITIONS.md).

- **Ledger:** `entry_type` widened to `varchar(32)` (every `connect_settlement` insert used to fail silently); unique `idempotency_key`; insert-first atomic balance updates; per-payment `GET_LOCK` around finalize; settle recovery via `_lnkup_settled_at` (automatic only after `lnkup_fin_auto_recovery_since`).
- **Pure reads:** money GETs no longer reconcile; `POST /financial/sync`, Cash Out and the locked hourly cron (`lnkup_financial_hourly`) do.
- **Correctness:** late `payment_failed` cannot unpay; fee snapshot kept at settle; platform recovery credits net not gross; cancelled destination cashouts release processing; Checkout Session intent lookup; async Checkout / payout cancel/update / dispute-closed webhooks.
- **Money contract v1** (`money` on organizer dashboard/wallet/balances, vendor dashboard): integer cents, source, `synced_at`, unavailable instead of $0, payout state + Cash Out reason. Web, Next.js and mobile mappers read it (mobile not published).
- **Lists:** transactions newest-paid-first with paging headers; ledger paging with payment/payout references; one row per booking.
- **Admin:** read-only `admin/financial/reconcile` + `ledger-audit`, approval-gated `admin/financial/repair` (dry run → token → offsets, never deletes), wp-admin → LNKup → Reconciliation.

### v12.21.0 (Organizer Experience 2.0 — tenancy, capacity, money, Requests, operations)

Findings, root causes, and verification status: [ORGANIZER_AUDIT_MATRIX.md](ORGANIZER_AUDIT_MATRIX.md). QA run sheet: [ORGANIZER_QA_CHECKLIST.md](ORGANIZER_QA_CHECKLIST.md). Local runtime: `@wordpress/env` + `scripts/e2e/seed.php` + `scripts/e2e/probe-organizer.php` (46 probes).

- **Tenant isolation:** operations, map, support, incident, booth, parking, load-in, sponsor, refund-calculate and intelligence routes check the event the row belongs to; another organizer gets 403/404.
- **Staff matrix:** `LNKup_Capabilities::staff_can()` + `LNKup_Event_Staff::capability_matrix()` (operations, coordinator, finance, parking, load-in, power, medical, security). Financials are owner/finance only; staff can no longer add staff; `organizer_owns_event()` means owner (or manage-all) only.
- **Capacity:** new `LNKup_Opportunity_Inventory` derives `_lnkup_spots_remaining` from applications holding a spot (approved / payment_required / paid / completed). Approve takes a per-opportunity lock and returns 409 `opportunity_full`; a one-time backfill corrects existing counts (logged). Declined/cancelled bookings void their unpaid payment; checkout refuses non-pending payments. Optional unpaid-hold expiry via `payment_hold_expiry_days` (off by default).
- **Payment status fix:** the meta sanitizer used to rewrite `processing` (ACH in flight) and `partially_refunded` to `pending`, making them payable again. Both are now kept.
- **Refunds:** one open request per payment, claim-before-refund (double approve → 409), amount capped at the remaining balance, `partially_refunded` status, credit refunds grant credit only (no card refund), all card refunds through `LNKup_Financial_Service::refund()`.
- **Cash out:** per-organizer lock, `standard|instant` allow-list, conditional cancel, optional `client_token` replay protection.
- **Idempotent create:** Create Event, opportunity rows and Duplicate Event accept a `client_token`; a retry returns the original record. When the event saves but slots fail, web and app show "Your event was saved — Retry vendor slots" instead of a green toast. Duplicate keeps closed slots closed and never copies bookings.
- **Request More Information:** new `info_requested` status; organizer asks (web card, drawer, app sheet), vendor replies on Requests (web and app); history on the application; notifications both ways.
- **Requests inbox:** To review · Needs info · Approved · Awaiting payment · Confirmed · Waitlisted · Declined · Missing docs; counts update in place; drawer gains Decide, Q&A and history with you.
- **Home / workspace:** organization welcome, Action Center (incl. events with no open spots), server-rendered recent activity, event cards scoped to the events shown; workspace tabs Overview · Opportunities · Applications · Confirmed · Financials · Operations · Check-in (sticky), Events highlighted in the nav, Applications link keeps the event.
- **Editing logistics:** a date, time, venue or address change notifies booked vendors and re-syncs listings; fees never change.
- **Inventory UI:** each slot shows open · held · paid · waitlisted, "Spot open — review waitlist", and Preview as vendor.
- **Financial Center:** Gross collected · Platform fees · Refunds · Net · Pending · Available to cash out · In transit · Paid out (each explained; pending ≠ available) plus a By-event table. Dispute dialogs replace `prompt()`/`alert()`.
- **Operations / check-in:** booths are one-per-vendor-per-event and only for approved vendors; a paid vendor's booth change sends a pass update. Event-day status can no longer set approval/payment states; owner/operations overrides need a reason and are written to the operations log; duplicate scans say "already checked in". Scan page shows Expected · Checked in · Not yet · Exceptions and a vendor lookup, and opens for check-in staff.
- **Packets:** Draft / Ready / Published label derived from pass completeness and payment unlocks.
- **Intelligence:** Applications → Approvals → Payments → Confirmed → Check-ins funnel and an owner-scoped event comparison. Past events offer "Rebook N vendors".
- **Vendors / Lists / Invites:** load from the sidebar (`?view=`), duplicates surfaced, invites bound to the invited vendor and answerable once; discover excludes private profiles and searches name OR city.
- **JS hygiene:** `crmFetch`, `mapFetch` check JSON and status; no remaining `alert`/`prompt`/`confirm` in organizer CRM, maps or financial (new `LNKupUI.askText()` dialog).
- **Native organizer:** shared cache for events, requests and Money; tab badge subscribes and polls only in the foreground; review re-reads server status and surfaces bulk failures; Ask for more info sheet; publish and cash out send a `client_token` and handle partial success; no demo events in live mode; push routing for `info_requested`, `organizer_event_needs_attention` and event-scoped requests; Money no longer re-derives "collected" from activity.

### v12.20.0 (Vendor Experience 2.0 — security, payments, Home, Find, Requests)

Full findings and verification status: [VENDOR_AUDIT_MATRIX.md](VENDOR_AUDIT_MATRIX.md). QA run sheet: [VENDOR_QA_CHECKLIST.md](VENDOR_QA_CHECKLIST.md).

- **Data exposure:** only `lnkup_event` and `lnkup_opportunity` stay in core `wp/v2` REST; applications, payments, documents, packets, profiles, CRM records, reviews, and their meta are served only by `lnkup/v1`. Vendor document attachments are hidden from `wp/v2/media` and attachment pages; new uploads go to `uploads/lnkup-private/` (deny rules) and must be PDF/JPG/PNG under 10 MB. Existing files were flagged, not moved.
- **Payments:** `confirm_checkout` no longer accepts `sim_` IDs unless simulation is explicitly enabled (test mode with no live Stripe, or `LNKUP_ALLOW_PAYMENT_SIMULATION`). Stripe intents must carry this payment's `payment_post_id` and at least the amount due. Reconcile and meta backfill use the same binding. Missing Stripe keys return a friendly `payments_unavailable` instead of silently simulating.
- **Free booths:** a $0 amount due finalizes through the Financial Service on approval, prepare, or redirect checkout — no Stripe call, no vendor fee, no "payment required" email.
- **Credits / promos:** credits apply only to the owner's pending payment and only the uncovered remainder (locked); one promo redemption per payment.
- **Integrity:** application transitions follow an allowed-from map (409 `invalid_transition`); apply is locked per vendor + opportunity; throttle set only after success.
- **Vendor ops:** vendor home and weather ownership read `_lnkup_owner_user_id` (new `LNKup_Capabilities::vendor_owns_profile()`).
- **Native checkout:** hosted checkout returns via `GET /financial/checkout/native-return` → `lnkup://stripe-redirect`; the auth session closes itself and the Pass polls server status.
- **Home:** welcome with logo, one Next step, linked metrics, upcoming event, recommendations, recent activity.
- **Requests:** All · Needs action · In review · Confirmed · Past events; timeline through Event day / Completed; free booths skip Payment.
- **Find / Apply:** one status vocabulary on cards; cards show the vendor's application status; booth chooser; progress steps; filters restore from URL; infinite scroll works; guest ♥ goes to signup.
- **Reliability:** checkout, wallet, growth, operations, documents, and marketplace calls clear loaders and show retryable errors.
- **Nav / tokens:** Home / Find events labels from `lnkup_copy`; storefront icon for Profile; missing `--lnkup-*` tokens defined; reduced-motion support.

### v12.18.86 (Invite name and one payment)

- Overview invites use the event post title, date, and city. If the saved notice does not match an invite, sent and opened invites for that vendor are shown instead.
- Vendor Wallet and organizer Money list one row per event. The amount is the overall total. Installments for that event add up.

### v12.18.85 (Invite name and Find filters)

- The vendor Overview invite names the event, date, and city. If the invite only has a slot, that name is shown.
- Find filters are grouped into Where, When, Fee, Documents, and More. Short fields sit two across from 640px, and checkboxes wrap with space between them.

### v12.18.84 (Money and wallet screens)

- Organizer Money and vendor Wallet show one overall price per application. Installments add up. A duplicate charge is listed once.
- Money, Revenue, Planning, and Account stay above Overview, Wallet, Cash out, and Payouts. Those buttons stay above the figures and the month chart.
- Revenue by month uses paid payment totals, one amount per application.
- Wallet ledger marks money in green and money out red. Cash out is a card with Standard and Instant (1.5% fee).
- Invoice View opens the invoice page. Payment methods are card rows. Receipts are cards with View.

### v12.18.83 (Vendor sell and location)

- Organizer vendor cards show category pills, or a short products line, and a city. Cities served fills in when the home city is empty. Missing facts say “Category not listed.” and “Location not listed.”
- Organizer request rows and an event’s Vendors tab show the same sell and location line under the vendor name.

### v12.18.82 (Profile, event switcher, Money, and Vendors)

- The vendor Profile tab stacks every section in one centered 48rem column. Two-across fields stay inside each card from 640px.
- An event screen with more than one event has a switcher beside the title. It keeps the current tab.
- Organizer Money opens with Available, Pending, Collected, and Paid out, a payout-status line, the month chart, and recent payment activity. Revenue, Planning, and Account stay under that.
- Vendors is a two-column gallery from 768px and three columns from 1200px. Each card keeps the logo or initials, name, city, categories, event count, and Invite.

### v12.18.81 (Profile edit and share)

- The workspace bar has Edit profile, Copy link, and View for organizers and vendors.
- Organizer Edit profile opens the existing profile form on Events. Vendor Edit profile opens the profile page. Copy link and View use the public profile URL.
- The vendor profile header also has Copy link next to Preview.

### v12.18.80 (Organizer list, slots, and More)

- Overview titles the pending rows Recent requests. Event cards keep Open and Requests, and Duplicate uses the existing copy form.
- The cross-event slot list leaves the Events page. Slot name, fee, spots, and open or closed stay on that event’s Slots tab.
- More sits on the top right of the workspace bar and opens the existing sidebar. The phone bar is the five primary links.
- Vendor cards show city, categories, and a completed-event count.

### v12.18.79 (Vendor profile width)

- The vendor Profile tab uses the dashboard width. Business Basics stays a 42rem card. From 768px the other sections sit in two columns, and Photos spans both.

### v12.18.78 (Organizer event pages)

- The organizer dashboard shows one section at a time. Overview is the default.
- Open on an event goes to that event’s screen: Overview, Slots, Requests, and Vendors. Edit still opens the existing form.
- The Requests inbox stays in the nav. An event name there opens that event’s Requests tab.

### v12.18.77 (Vendor Wallet and Profile)

- Wallet opens with Balance and a payments list of event, city, amount, and Paid or Due. Pay, Wallet, Extras, and Reports stay under that list.
- Profile opens as one card: logo, business name, city, and category pills, then a photo grid. The other sections keep their save buttons. The strength sidebar is gone.

### v12.18.76 (Vendor Overview, Find, and Requests)

- Vendor Overview is a bordered title, a four-cell count, one next action, and up to three request rows. With no applications, it shows Find an event.
- Vendor Requests uses the same title and pill filters. Pay now stays on the row.
- Find events is a search row and a city field, then a two-column gallery. Category, sort, and the other filters stay behind Filters. View opens the public event.

### v12.18.75 (Dashboard look)

- Public vendor and organizer profiles use a wide rounded cover, a square mark, and the name and city on one row. Invite and Share sit on that row. The public vendor page no longer shows the score rail, metrics, or reliability sidebar.
- Dashboard content is centered. Lime fills leave the dashboard. Status is a dot and a label. Cards stay flat with an 8px border.

### v12.18.74 (Dashboard screen bodies)

- Vendor Requests is one row list: event, slot, fee, and status. Filters are Applied, In review, Pay now, and Booked. Pay now stays on a pending payment.
- Organizer Requests is the same kind of row. Accept and Decline stay on new requests.
- Event cards keep the image, status, date, and city, plus vendor slots and spots open. Open and Requests stay. Revenue and Operations leave the card.
- Vendors opens on search and a card with name, city, categories, and Invite. Discover, import, and filters stay behind the expand control.
- Vendor Wallet first shows the balance and Pay tabs. Wallet, Extras, and Reports stay on the section buttons.

### v12.18.73 (Secondary pages and Money)

- Lists, Invites, Insights, Launch, Event operations, Documents, Growth, Referral Invites, and Reviews open with a one-line title. Their lists and actions stay.
- Organizer Money first shows Overview, Wallet, Cash out, and Payouts. Revenue, Planning, and Account stay on the section buttons.
- The public vendor page keeps About, photos, and events. Setup, power, water, and payment fields stay off that page. Invite stays for a signed-in organizer.

### v12.18.72 (Lists, event workspace, Money, Find, profiles)

- Requests, slots, and vendors use one bordered list. Accept and Decline stay on new requests. Pay stays on payment due.
- The event page has a facts column with the public link and Copy. Edit still uses the existing form.
- Money opens on balances, payout status, the month chart, and recent activity. Other sections stay in the section nav.
- Find cards open the public event. Public profiles use the shared header. Empty and error states share one pattern.
- The dashboard title under the workspace bar is visually hidden. More stays in the sidebar.

### v12.18.71 (Type, workspace bar, public pages)

- The theme uses Geist and Geist Mono. Dashboard cards are flat 8px borders, and status is a dot plus a label.
- The dashboard has a real top bar with the workspace name and the open event. Phone navigation stays the bottom bar.
- The public header is Events, Organizers, and Vendors. Find cards open the public event. Vendor profile edit is stacked sections, without documents on that page.

### v12.18.70 (Public event slots)

- The public event page titles the list Vendor slots. Fee, spots open, and the free-to-apply line stay.
- Organizer profile cards keep View vendor options and Apply for {slot}, which opens the same `?apply=` link.

### v12.18.69 (Vendors, vendor Requests, and Wallet)

- Organizer Vendors is a title, search, and Invite.
- Vendor Requests drops the tracking paragraph. Pay stays on payment-due rows.
- Vendor Wallet drops the payments essay and keeps the balance and payment list.

### v12.18.68 (Events, Requests, and Money)

- Events is a title, Live / Drafts / Completed, and Launch event. Public profile stays a small secondary action.
- Requests drops the extra review paragraph. Accept and Decline stay on new requests.
- Money opens on the existing balances, payout status, and chart.

### v12.18.67 (Quieter dashboard homes)

- Organizer Overview is one upcoming event, one waiting row, four counts, and two requests.
- Vendor home is a greeting, Applied / In review / Pay now / Booked, one next action, and a short request list.
- The event page adds a Vendors tab for accepted and booked vendors. Accept and decline stay on new requests.

### v12.18.66 (Dashboard shell from lnkupdashboards)

- Organizer and vendor dashboards use a top navigation bar: Overview, Events, Requests, Money, Vendors, and Overview, Find, Requests, Wallet, Profile.
- Public event and organizer profile cards use the same flat 8px border treatment.

### v12.18.65 (Invite drawer and revenue charts)

- Invite vendor opens a full-height drawer with the event form inside it.
- The organizer revenue dashboard stays JSON when a chart value cannot be encoded, and month bars fall back to paid booth payments.
- A cashout that does not save does not debit the wallet. A failed Connect transfer puts the amount back.

### v12.18.64 (Apply Sign up navigates)

- Sign up and Log in inside the apply window leave the page. After signup, the visitor returns to that same slot.

### v12.18.63 (Guest apply from an organizer profile)

- Guests see Log in and Sign up. Dashboard is only for a signed-in vendor or organizer.
- A public event or apply link stays on the public page, not inside the vendor dashboard.
- Organizer profile cards include Apply for a slot. Logged-out visitors sign up or log in and return to that same application.

### v12.18.62 (Organizer and vendor home)

- Organizer home opens with the command center, the existing stat grid, two upcoming events, and two pending requests.
- Vendor home opens with a greeting, a KPI row, and one next action.

### v12.18.61 (Organizer events match the app example)

- Events uses a header, Live / Drafts / Completed tabs, and a two-column card with Manage event, View requests, and Operations.
- The event edit page shows request counts and tabs for overview, vendor slots, and requests.

### v12.18.60 (Per-event edit page)

- Edit event opens `organizer-dashboard/?event={id}&edit=1` with the listing form and that event’s vendor slots. The Events list no longer hides a full editor on every card.

### v12.18.59 (Public storefront template)

- The organizers page uses the theme Public Storefront template, so the WordPress title “Organizers” is not printed around the profile.
- Vendor slots across events are full-width cards, with the event name and city on one line and each slot on a row that uses the dashboard width.

### v12.18.58 (Public profile layout)

- Organizer profile cards use the full width when an event has no photo. The organization name sits below the cover, and the WordPress “Organizers” title is hidden on that page.

### v12.18.57 (Public organizer profile and event page)

- The public organizer profile is a storefront: cover, logo, upcoming events as one card each, then about and past events.
- The public event page leads with vendor opportunities, keeps the street address private when hidden, and says applying is free.

### v12.18.56 (Vendor slot list layout)

- Vendor slots across events sits under the event cards. Slots are grouped by event, with the name, price, spots, and Save on one row.

Organizer baseline (ORG-00–04): [ORG_BASELINE.md](ORG_BASELINE.md). The cross-event vendor-slot list is in 12.18.55. Local WordPress at `http://localhost:8888` was not running when that baseline was written.

### v12.18.55 (Vendor slots across events)

- Events shows every vendor slot in one list, with the event name, city, and date. Name, price, and spots save from that row. Close, reopen, and remove work the same way as on a single event card. A filter narrows the list when there are several events.

### v12.18.54 (Organizer event command)

- Edit event saves photos, indoor or outdoor, and vendor amenities.
- Vendor slots can be renamed. A slot with requests can be closed without deleting those requests. The extra fee table is no longer on the Events page.
- Review requests and Find vendors are on the vendor slots panel.

### v12.18.53 (Public event details and duplicate venue)

- The public event page shows category, attendance, indoor or outdoor, vendor amenities, tickets, organizer about, and each slot’s category, deadline, and spots filled.
- Duplicate asks for city, state, venue, and address, prefilled from the original, so the copy can use a different place.

### v12.18.52 (Vendor slots on the event card)

- **Vendor slots** on the event card lists each slot so you can change price and spots, add another, or remove one that has no vendor requests. Removing the last open slot returns the event to Draft.

### v12.18.51 (Edit the whole event)

- **Edit event** sits next to Manage and opens the full listing: title, description, category, date, times, attendance, ticketing link, and venue.

### v12.18.50 (Edit events and organizer profile)

- **Edit event** saves the title, description, date, times, venue, address, and attendance that are already on the event.
- Organizers can set an organization name and about text. The public profile shows that above their live events.

### v12.18.49 (Public event page)

- **Share event** copies one public link. That page shows the event details and every open vendor slot, with Apply on each slot.

### v12.18.48 (Empty vendor roster)

- Manage with no vendors hides bulk check-in actions and explains that the roster fills after vendors apply. Next steps are Review requests, Discover vendors, and Publish when the event is still a draft.

### v12.18.47 (Publish drafts, requests, and public links)

- Event cards show **Live** when a vendor slot is open and **Draft** when none is. **Publish** on a draft card adds the slot that takes it live.
- Vendor requests: event dropdown on small screens, a sticky “showing requests for” line, and status counts for the selected event. Event cards link to **Review requests**.
- Discover and Vendor lists sit together in the sidebar, with clearer copy and a Discover shortcut from lists.
- **Share event** copies the public apply link. **Your public profile** is `/organizers/?organizer={slug}` with live events.

### v12.18.46 (Duplicate events and vendor slots)

- Events command: **Duplicate** copies the event, vendor slots (fees, spots, documents), and venue map onto a new name and date. Applications, payments, and roster are not copied.
- **Add vendor slot** on the event card. Preset vendor type or a custom type (saved as a vendor category). Multiple rows in one save.
- Create wizard: custom type on each slot row. Closing vendor applications no longer blocks publish on an empty vendor type.

**WordPress deploy:** GitHub Action `deploy-wordpress.yml` rsyncs plugin + theme to SiteGround over SSH. Do not clone this monorepo into `public_html`. See [SITEGROUND_DEPLOY.md](SITEGROUND_DEPLOY.md).

### v12.18.43 (P2/P3 QA)

- Mobile: page overflow contained (Financial tables, compare bar, dashboard header wrap, dashboards / Find Events / profile). Dead card images fall back to category JPGs.
- Organizer Financial: unsynced destination balances show ledger Available; dashboard-fail shows Retry instead of a sticky “Balance is syncing” block.
- Map empty states: organizer Open Map Builder + Upload; vendor “coming soon”.
- Event Pass pending booth: next step, organizer contact, optional event-date timing (no invented ETA).
- Apply modal: category mismatch / incomplete profile link to Update profile.
- Dashboard hashes `#events` / `#applications` / `#financial` scroll with `scroll-margin` and pause observer fight.
- Find Events: stronger external cards, “Apply on LNKup” badge, **LNKup listings only** filter.

### v12.18.42 (P1 QA: readiness / docs / list / find-events)

- Event readiness: unpaid booth fee shows **Needs Payment** + blocker **Collect booth payment**. Checklist pending label is **Payment due**. $0 fee is treated as paid (not Needs Payment).
- Vendor requests: docs chip/filter/Review docs follow document context. No **Docs pending** when none are required.
- Find Events List: ID-level CSS forces a single column of full-width rows (no 2-col grid clip).
- `/find-events/` 301s to the opportunities page (query string kept) unless a real `find-events` page exists.

### v12.18.39 (In-app signup + privacy URL)

- `POST /lnkup/v1/auth/register` creates the same vendor/organizer account as web `/sign-up/` and returns a mobile Bearer token. Web form still uses the shared `LNKup_Registration::register_user()`.
- Native **Create free account** opens in-app signup (`app/signup.tsx`) instead of Safari. Sign in and Create account link Privacy / Terms at `https://thelnkup.com/privacy` and `/terms`.
- Operator must set App Store Connect Privacy Policy URL to `https://thelnkup.com/privacy`.

### v12.18.38 / theme 1.4.19 (Find Events category photos)

- Cards still prefer organizer `_lnkup_featured_image_id` and Eventbrite `image_url`.
- Missing photos use one realistic JPG per seeded `lnkup_vendor_category` (Food Truck is an actual food truck). Unknown categories use a general market photo. Dark `cat-*.svg` diagrams are no longer live fallbacks.
- `lnkup_format_index_row()` puts the resolved URL on `featured_image` so JS rails match PHP cards. Alt is `{category} in {city}`.

### v12.18.37 (Content Studio UI polish)

- **Builder sheet:** Sticky header with duration/audience chips and a real Close button (`lnkup-btn--outline` + ×). Hook, script, caption, on-screen, and shot list are grouped rows with Copy on the heading. Footer: Open Referral Link, Download Shot List, Mark as Planned.
- **Publish form:** Platform and audience `<select>`s (`instagram` / `tiktok` / `linkedin` / `facebook`; never `instagram_reels`). Link placeholder and hint follow the selected host; pasted URLs can set the platform. LinkedIn hosts allowed on the server; YouTube stays allowed but is not in the select.
- **Performance:** Empty state with a “Submit a published link” cue. Four cards (Published posts, Tracked clicks, Registrations, Bookings) plus a muted attribution disclaimer. Mission and post rows use human labels (`Instagram · Organizers · 30s`, Planned).

### v12.18.36 (Connector launch plan + Content Studio)

- **Launch plan:** Get Started is a three-phase plan (Set Up / Promote / Activate). Rank 1 uses Phase 1 only. Legacy `reviewed_targets` migrates to `reviewed_earnings`. Growth styles (`relationship`, `video`, `community`, `event`) persist on the connector row and drive next-best-action.
- **Content Studio:** Private CPTs `lnkup_cn_template` / `lnkup_cn_post`, seeded video + Story templates, REST `/connector/studio*`. Optional `cc=` on referral URLs logs `content_click` metadata only. Publishing a video never creates commission.
- **UI:** Specific CTAs (no generic Continue), growth-style cards, Resources grouped by type, bottom nav Share → Create. Theme 1.4.18.
- **Tests:** `node scripts/connector-studio-verify.mjs` + launch-plan / studio cases in `tests/e2e/connector-program.spec.ts`.

### v12.18.35 (City Connector ranks)

- **Ranks:** Five-tier City Connector → Regional Partner in `LNKup_Connector_Ranks`. Cached columns on `lnkup_connectors` plus idempotent `lnkup_connector_booking_attr` (`UNIQUE payment_id`). Qualifying booth GMV waits for the payout hold; refunds/chargebacks/test payments are excluded. Rank never raises the 10% commission and never auto-decreases.
- **Bonuses:** One-time pending `connector_rank_bonus_{rank}` rows in `lnkup_referral_rewards` with `idempotency_key`. Admin approve / mark-paid / reverse only — never auto-paid.
- **Dashboard:** Overview rank card + progress rows, `?view=ranks` roadmap, one-time celebration, opt-in city leaderboard (no dollars). Theme 1.4.17 adds Ranks to the connector sidebar.
- **REST:** `GET /connector/rank`, `/rank/roadmap`, `/rank/history`, `POST /rank/acknowledge`, `GET /connector/leaderboard`; admin recalc/override/reverse + attribution disputes.
- **Tests:** `node scripts/connector-ranks-verify.mjs` + unauth 401s in `tests/e2e/connector-program.spec.ts`.

### v12.18.34 (City Connector command center)

- **SPA dashboard:** In-dashboard `?view=` switching via `history.pushState` (no full reload). Desktop keeps sidebar + in-page tabs. Mobile (≤767px) uses a connector bottom nav: Home, Referrals, Share (opens sheet), Earnings, More.
- **Share sheet:** Organizer and vendor links, QR, copy / native share, Instagram deep link, outreach scripts, live-region “Referral link copied.” Marks `copied_link` / `copied_vendor_link` / `sent_intro`.
- **Overview:** Compact “Grow LNKup in [City]” hero, unmixed KPIs, backend `next_best_action()`, funnel conversion % only when the from-step count ≥ 5, activity timeline from `GET /connector/referrals`.
- **Referrals / Earnings / Profile:** Client-side referral search and filters; ledger status filter + organizer display name; optional `bio` column; WordPress avatar; next payout is earliest `available_at` or “After LNKup approval.” Cap copy still appears only when `share_cap > 0`.
- **Tests:** `tests/e2e/connector-program.spec.ts` share sheet, filters, calculator POST, viewports 320/375/768/1024/1440.

### v12.18.33 (City Connector Onboarding and Earnings Education)

- **Rewards:** Organizer-primary share of booth GMV (`_lnkup_vendor_spot_fee` × `connector_booking_share_percent`, default 10%). Term starts at the organizer’s first completed booking. 30-day hold via `available_at`. Refunds reverse unpaid rewards (`lnkup_refund_completed`). Per-transaction cap setting defaults to 0 (off). Manual approve / mark-paid unchanged. No Stripe payout from dashboard JS.
- **Dashboard IA:** Overview, Get Started, My Referrals, Earnings, Resources, Profile. New connectors default to Get Started. Legacy `?view=` aliases kept.
- **REST:** `/connector/onboarding`, `/onboarding/step`, `/funnel`, `/earnings`, `/resources`, `/terms`, `POST /calculator`, `POST /profile`.
- **Settings:** share %, cap, term months, hold days, minimum payout, vendor-referral reward flag, terms/support URLs.
- **Tests:** `tests/e2e/connector-program.spec.ts` + `node scripts/connector-earnings-verify.mjs`.

### Launch day (2026-08-02)

- **Marketplace open:** `platform_prelaunch_mode` default `false`; `platform_launch_date` `2026-08-02`; one-time option `lnkup_launch_day_20260802_applied` flips existing installs on deploy.
- **Mobile app release:** `app_release_datetime` `2026-08-16 12:00:00` + `app_release_tz` `America/New_York` — homepage app-release bar until that moment (does not gate web browse/apply).
- **Editable homepage:** Appearance → Landing Page (`lnkup_landing`); post-launch CTA defaults; featured rails removed from home.

### Mock app UI (App Phases 1–6 — `lnk-up-marketplace-ui/`)

The canonical **Next.js app** for vendor/organizer/admin dashboards lives in [`lnk-up-marketplace-ui/`](../lnk-up-marketplace-ui/). **App Phase 2+ is app-only** — vendor More hub, secondary routes, live BFF integration. WordPress visual port is **deferred** (production website UI is considered done).

**Roadmap:** [SYSTEM_OVERVIEW.md §14.2](SYSTEM_OVERVIEW.md#142-app-roadmap-phases-126) · **Queued 22–26:** [PHASES_22_26_ROADMAP.md](PHASES_22_26_ROADMAP.md) · **App phases 22–26:** [PHASE_22_PRODUCTION_LAUNCH_HARDENING.md](PHASE_22_PRODUCTION_LAUNCH_HARDENING.md) · **Organizer baseline:** [ORG-00–04](ORG_BASELINE.md) · **E2E journey:** [E2E_MARKETPLACE_JOURNEY.md](E2E_MARKETPLACE_JOURNEY.md) · **Gaps:** [APP_GAP_ANALYSIS.md](APP_GAP_ANALYSIS.md)

```bash
cd lnk-up-marketplace-ui
pnpm install   # or npm install
pnpm dev       # http://localhost:3000 — role picker (Vendor / Organizer / Admin)
npx next build
```

- **24 app routes** (vendor/organizer/admin + secondary vendor screens)
- **5-item nav** per role (`lib/nav.ts` + `components/app-shell.tsx`); vendor mobile uses **More** as 5th slot
- **Panel states:** append `?empty=1` or `?error=1` on core routes for QA — see [`docs/PHASE_2_APP_QA.md`](PHASE_2_APP_QA.md)
- **Production mock:** https://lnkup-marketplace-ui.vercel.app
- **Do not use** root `pnpm dev` — that serves the legacy `app/` template

### Native mobile (Expo — Native Phases 1–10)

Canonical **iOS/Android** app: [`lnk-up-marketplace-mobile/`](../lnk-up-marketplace-mobile/). Design IA from [`lnkup-app-reference/`](../lnkup-app-reference/) (web mock only). Calls WordPress REST directly with Application Passwords in SecureStore — **not** the Next.js cookie BFF.

```bash
cd lnk-up-marketplace-mobile
npm install
cp .env.example .env
npx expo start
```

- **Native Phase 1 built:** login, vendor Home / Find / Requests
- **Native Phase 2 built:** read-only Wallet + Pay handoff (`Linking`), Event Pass (locked/unlocked), Apply from Find
- **Native Phase 3 built:** organizer lite — Home / Events / Vendors requests (approve/waitlist/reject) / Money (read-only) / More; role routing + dual-role switch
- **Native Phase 4 built:** Expo push device register + prefs; WP Expo Push on key events; EAS profiles/scripts (operator runs `eas init` / build / submit). Depth inventory: [PUSH_NOTIFICATIONS_DEPTH.md](PUSH_NOTIFICATIONS_DEPTH.md)
- **Activation drip (Phase 2):** welcome + finish-profile / find-events / apply (vendors) and create-event / review-vendors (organizers), including pre-tutorial backfill — [PHASE_ACTIVATION_EMAILS.md](PHASE_ACTIVATION_EMAILS.md)
- **Retention push (Phase 3):** saved search, favorites, invites, deadlines, profile match — [PHASE_RETENTION_PUSH.md](PHASE_RETENTION_PUSH.md)
- **Push prefs + badge (Phase 4):** category toggles + app icon unread badge — [PHASE_PUSH_PREFS_BADGE.md](PHASE_PUSH_PREFS_BADGE.md)
- **Native Phase 5 built:** vendor Profile readiness + Documents list/upload via WP REST; profile field edits remain WP handoff
- **Native Phase 6 built:** vendor More hub — Favorites, Growth lite, Reviews lite, Operations → Pass
- **Native Phase 7 built:** composed notification center (vendor + organizer); SecureStore mark-read; Home unread bell + More entry
- **Native Phase 8 built:** Find discovery polish — filters, sort, near-me (`expo-location`), saved-search CRUD; map view deferred
- **Native Phase 9 built:** organizer Launch lite — 3-step publish via `POST /organizer/launch/publish`, AsyncStorage draft, Home/Events/More CTAs
- **Native Phase 10 built:** store release hardening — privacy/support URLs, encryption flag, EAS submit scaffolding, [`STORE_RELEASE.md`](../lnk-up-marketplace-mobile/STORE_RELEASE.md); submit remains operator-run
- **Tab UI/UX polish (OTA-safe, app 1.0.1):** shared primitives in `components/ui/` (`Button`, `FilterChip`/`FilterChipRow`/`SegmentedControl`, `StatusBadge` + `statusToneColors`, `ListRow`/`ListGroup`, `StatTile`/`StatGrid`, `SectionHeader`, `InlineBanner`, `Skeleton*`, `FormField`, `CollapsibleSection`, `BottomSheet`, `PressableScale`). Both tab bars use `components/tab-bar.tsx` (safe-area bottom inset, `gray500` inactive tint, a11y badge labels). All 10 tabs use skeleton first paint, 44pt targets, pressed feedback, and the same empty/error/refresh-banner pattern. Application statuses are colored via `vendorRequestStatusTone` / `organizerRequestStatusTone`. Organizer Vendors is split into `components/organizer-vendors/`. Event detail collapses pricing/edit into sections with the review CTA up top. Money copy now says "Manage payouts" / "Opening payouts…" (no Stripe wording). JS-only, no new native deps — ship with `npm run update:preview` then `update:production`.
- **App Store readiness:** Theme seeds `/privacy`, `/terms`, `/contact`; in-app account deletion (Guideline 5.1.1(v)) via `POST /lnkup/v1/account/delete` + More → Delete account; ASC paste pack [`ASC_APP_REVIEW_PACKAGE.md`](ASC_APP_REVIEW_PACKAGE.md); verify `node scripts/app-store-readiness-verify.mjs`
- **Referral Invites (organizer recruit):** Program `organizer_recruit` — vendors/organizers invite outside organizers; **20% of LNKup platform fees for 12 months** from attribution; REST `/referrals/organizer-recruit*`; WP hubs + Expo/Next hard-push CTAs; docs [`PHASE_REFERRAL_INVITES.md`](PHASE_REFERRAL_INVITES.md); verify `node scripts/referral-invites-verify.mjs`
- **Brand assets:** Official LNKup logo (`lnk-up-marketplace-mobile/assets/brand/lnkup-logo.png`) → store icons via `npm run icons`
- **Architecture:** [SYSTEM_OVERVIEW.md §14.3](SYSTEM_OVERVIEW.md#143-native-mobile-architecture)
- **No Stripe SDK** in mobile; payments and deep organizer ops open WP URLs in the browser
- **WP inbox REST** — `GET/POST /lnkup/v1/notifications/inbox*` persists push events and shared mark-read; clients still compose live domain items and merge `readIds`. Push vs inbox depth: [PUSH_NOTIFICATIONS_DEPTH.md](PUSH_NOTIFICATIONS_DEPTH.md)
- **Plugin:** v12.18.25 — marketplace open (launch day) + app release settings (`app_release_datetime` / `app_release_tz`); push + account deletion + organizer recruit referrals (organizer or vendor invites via sign-up) + organizer UX routes
- **Theme:** v1.4.9 — app-release bar, editable landing defaults post-launch
- **EAS:** linked as `@darkero12/lnkup-marketplace`

### Phase 14 stabilization snapshot (Jul 2026)

- Phase 14A/14B/14C feature work is implemented and two-week stabilization execution is complete.
- New specs added and passing:
  - `tests/e2e/lnkup-app-phase14a-journey-handoffs.spec.ts`
  - `tests/e2e/lnkup-app-phase14b-organizer-loop.spec.ts`
  - `tests/e2e/lnkup-app-phase14c-wallet-polish.spec.ts`
  - `tests/e2e/lnkup-app-phase14-analytics.spec.ts`
- Focused regression sweep across Phases 9/13/vendor-phase3 is green after suite-drift selector alignment.
- Phase 14 quality gate workflow added: `.github/workflows/phase14-quality-gate.yml`.
- Execution record and go/no-go memo: [PHASE_14_TWO_WEEK_EXECUTION_LOG.md](PHASE_14_TWO_WEEK_EXECUTION_LOG.md).
- Live Stripe sandbox end-to-end verification still requires authenticated WP + Stripe test credentials in operator environment.

### App Phases 15–21 snapshot (Jul 2026)

| Phase | Theme | Status |
|-------|--------|--------|
| 15 | Admin Lite / Internal Ops | Built |
| 16 | Checkout return reliability | Built |
| 17 | Organizer CRM depth | Built |
| 18 | Vendor readiness / reliability | Built |
| 19 | Intelligence / experimentation | Built |
| 20 | Mobile organizer intelligence parity | Built |
| 21 | QA gate hardening + web on-site tutorials | Built |

- Consolidated gate: [PHASE_15_20_QA_GATE.md](PHASE_15_20_QA_GATE.md) · CI: `.github/workflows/phase15-20-quality-gate.yml`
- Playbook: [PHASE_21_RELEASE_PLAYBOOK.md](PHASE_21_RELEASE_PLAYBOOK.md)
- **Phase 22 Built:** [PHASE_22_PRODUCTION_LAUNCH_HARDENING.md](PHASE_22_PRODUCTION_LAUNCH_HARDENING.md) · audit [artifacts/phase22-live-api-audit.md](../artifacts/phase22-live-api-audit.md)
- **Phase 23 Built:** [PHASE_23_MOBILE_TUTORIAL_PARITY.md](PHASE_23_MOBILE_TUTORIAL_PARITY.md) · verify `cd lnk-up-marketplace-mobile && npm run phase23:verify` · First-run **tutorial popups** (vendor + organizer) walk incomplete steps; checklist remains after `popupSeen`; Skip all dismisses both
- **Phase 24 Built:** [PHASE_24_NATIVE_MAP_DISCOVERY.md](PHASE_24_NATIVE_MAP_DISCOVERY.md) · verify `cd lnk-up-marketplace-mobile && npm run phase24:verify`
- **Phase 25 Built:** [PHASE_25_ADMIN_LITE_DEPTH.md](PHASE_25_ADMIN_LITE_DEPTH.md) · verify `cd lnk-up-marketplace-ui && npm run phase25:verify`
- **Phase 26 Built:** [PHASE_26_FINANCIAL_OS_EXPANSION.md](PHASE_26_FINANCIAL_OS_EXPANSION.md) · verify `node scripts/phase26-verify.mjs` + `cd lnk-up-marketplace-mobile && npm run phase26:verify` · Session ACH finalize gate + webhook signature harden (secret set ⇒ no test_mode bypass)
- **Organizer event import Built:** [PHASE_EXTERNAL_EVENT_IMPORT.md](PHASE_EXTERNAL_EVENT_IMPORT.md) · verify `node scripts/phase-external-event-import-verify.mjs`
- **Organizer vendor profile enrichment Built:** [ORGANIZER_VENDOR_PROFILE_ENRICHMENT.md](ORGANIZER_VENDOR_PROFILE_ENRICHMENT.md) · verify `node scripts/organizer-vendor-profile-enrichment-verify.mjs`
- **Find / Launch media / Wallet invoices:** Marketplace search no longer fatals on typed `q` (analytics + history hardened). Optional event gallery (`_lnkup_gallery_ids`) on Create Event → WP/app detail. Invoice list includes `pdf_url` + View links. Verify `node scripts/find-media-wallet-verify.mjs`
- **Organizer UX wave Built:** Edit event after create (WP + apps); booth pricing by vendor type (multi-opportunity fee rows); Expo Home polish; Discover city/category/verified/sort filters; in-app Cash out + Stripe Express login-link (replaces primary “Manage on LNKup”); packet weather rain/heat merge + recommendations. Verify `node scripts/organizer-ux-wave-verify.mjs`
- **Queued 22–26:** Complete — [PHASES_22_26_ROADMAP.md](PHASES_22_26_ROADMAP.md)

### v12.18.19 (Getting Started + Knowledge Base)
- **Organizer welcome:** One first-run welcome modal (parity with vendor); Launch checklist “Getting started” eyebrow + optional dismiss.
- **Knowledge Base:** Static Help panels for vendor (`?view=help`) and organizer (`#help`); More nav Help entry; “Show getting started checklist again” resets dismiss without reopening welcome.
- **Theme 1.4.6:** Help icon + More nav links for vendor and organizer.

### v12.18.7 (Backfill platform payments when Available stays $0)
- **Root cause:** 12.18.6 migrate only moves wallet `available_balance`. If the booth payment never created a ledger row (UI had shown platform Stripe by mistake), claim stayed $0.
- **Backfill:** `backfill_platform_payments_to_connect_settlement()` creates `connect_settlement` for paid platform charges (no destination account) missing ledger.
- **Reconcile:** Organizer wallet/Money load runs reconcile + migrate + backfill (1-minute lock).
- **Admin Recover / Cash Out:** Should show organizer-owed after deploy when paid `lnkup_payment` posts exist for that organizer.

### v12.18.6 (Connect $0 after Connect onboarding)
- **Treasury → Connect settlement migrate:** When an organizer becomes destination-ready, stranded wallet `available_balance` is converted to `connect_settlement` so Cash Out / admin Recover can Transfer master → Connect.
- **Settlement truth on settle:** `persist_payment_settlement_meta( …, 'settle' )` does not flip `platform_treasury` → `destination` just because Connect is ready now; destination only if `_lnkup_stripe_destination_account` was stamped at create.

### v12.18.5 (Mixed platform balance split)
- **Financial Center:** Live main Stripe Available/Pending split into organizer-owed (ledger stranded) vs LNKup fee remainder.
- **Recover stranded:** Admin Transfer platform → organizer Connect for destination ledger claims.
- **Platform payout:** Admin Standard payout capped at fee remainder only (cannot invade organizer-owed).
- **Cash Out copy:** When Available is $0, clarify main Stripe mix is not withdrawable there.

### v12.18.4 (Platform stranded funds recovery)
- **Available = Connect + recovery:** Destination cashout Available is Connect available plus ledger-capped platform recoverable (`min(ledger_claim − connect, platform_available)`). Never shows uncapped platform fees.
- **Transfer-then-Payout:** When Connect cannot cover cashout but ledger-owed platform funds can, `transfer_platform_to_connect` moves the shortfall then Connect payout proceeds.
- **Cash Out UI:** Notes when Available includes LNKup-held funds that move on cash out.

### v12.18.3 (Connect vs platform Balance read)
- **Balance::retrieve fix:** Stripe PHP `Balance::retrieve( $opts )` takes one argument; prior `retrieve( [], $opts )` ignored `stripe_account` and showed **platform** Available while Connect payouts failed (“card balance too low”).
- **Platform/Connect guard:** If Connect available cannot cover cashout but platform balance can, return `funds_on_platform` with a clear message (do not call doomed Connect payout).

### v12.18.2 (Instant available + manual schedule cashout)
- **Instant preflight:** Connect balance parse includes `instant_available` / `instant_source_types`; Instant cashout gates on Instant balance and tells organizers to use Standard when Instant is $0.
- **Force manual schedule:** On-demand cashout always sets Connect payout interval to `manual`, then re-fetches balance before `Payout::create`.
- **Richer failures:** Requested + Available + Instant + source splits + Connect account suffix.
- **Cash Out UI:** Shows Instant available when Instant is enabled.

### v12.18.1 (Opaque cashout reason fix)
- **Live WP_Error:** `process_payout` returns `true|WP_Error`; `request_cashout` surfaces the Stripe/Connect message directly (no longer depends on re-reading an empty `failure_reason`).
- **failure_reason TEXT:** `lnkup_organizer_payouts.failure_reason` widened from `varchar(255)` so long Card/Bank + “tried sources” messages persist; failure metadata stores `failure_code` / `attempted` / `source_types`.

### v12.17.2 (Connect payout source_type harden)
- **Accumulate source_types:** `retrieve_connect_balance` sums Card/Bank cents across preferred-currency available items.
- **Never omit source_type:** When breakdown is empty but total available covers the amount, cashout probes `bank_account` then `card` (Instant: card then bank).
- **Retry:** On Stripe insufficient / “too low” / `balance_insufficient`, retry the next balance bucket; failure messages include split + attempted sources; audit/error_log record attempts.

### v12.17.1 (Cashout error surfacing)
- **In-panel cashout errors:** Money → Cash Out shows REST `message` (+ code/status) above the form instead of opaque `alert('Cashout failed')`; form stays filled; DevTools gets full payload.
- **financial.js/css cache-bust:** Enqueued with `lnkup_asset_version()` (`filemtime`) so deploys invalidate stale scripts.
- **Fetch harden:** Parse JSON when body looks like JSON even if Content-Type is wrong; never empty error strings; `err.payload` attached.
- **REST audit:** `organizer_cashout` guarantees non-empty `WP_Error` message, `error_log`, and `LNKup_Financial_Audit::log( 'organizer_cashout_failed', ... )`.
- **Connect source_type:** Destination cashout picks a funded Stripe balance bucket (`bank_account` preferred for Standard, `card` for Instant) instead of omitting `source_type` (Stripe defaults to card → “card balance too low”). Cash Out shows Card/Bank split when multiple sources exist; insufficient errors include the split.

### v12.17.0 (Review Queue V2)
- **Review tab triage:** Dedicated table with preview thumb + excerpt, vendor fit column (tier colors), readiness badges, matched vendor terms tooltip.
- **Sort & filter:** Default sort by `vendor_fit_score` DESC; min vendor fit, ready-to-publish-only, city filters; KPI strip (pending, ready, missing email, avg fit).
- **Bulk actions:** Reject selected; approve ready selected (email + valid image + event type required); capped at 50 IDs per request.
- **Backend:** `review_readiness()`, `review_queue_stats()`, extended `list_admin()` order/filter args.
- **Tests:** `tests/php/external-events-review-queue-test.php`; E2E review tab KPI/sort/bulk smoke.

### v12.16.0 (Vendor-Focused Auto Sync)
- **Vendor relevance gate:** Auto sync requires vendor-related terms in full title/description by default (`external_sync_vendor_filter_enabled`); customizable vendor term list; `skipped_vendor_filter` KPI on Auto sync tab.
- **vendor_fit_score:** Computed on import/refresh via `score_vendor_relevance()`; shown in Review queue as "Vendor fit: N".
- **Full description parsing:** Eventbrite `parse_url()` passes full `description` for filter, email extraction, and scoring (not 280-char excerpt only).
- **Additional keywords:** Optional AND filter renamed/clarified in admin; vendor filter is separate.
- **Sync timeout hardening:** `set_time_limit(300)`, `max_fetch_seconds` budget (default 240), fetch delay 1s, HTTP timeout 12s with retry, partial-run messaging.
- **Tests:** `tests/php/external-events-vendor-sync-test.php`; extended email extract + E2E auto sync vendor fields.

### v12.15.0 (Organizer Email Gap Workflow)
- **Description mining:** `LNKup_External_Events::extract_email_from_text()` scans event descriptions (including Eventbrite parser fallback before excerpt truncation) and auto-fills `organizer_email` on import, create, and re-fetch when scrape omits it.
- **Admin alert:** When a vendor applies without organizer email, `LNKup_Notifications::send_external_event_missing_email_admin()` emails LNKup admin (respects `notify_admin`) with a link to add the email.
- **Deferred notify:** `flush_pending_organizer_notifications()` sends the first organizer outreach when email is added later; marks all pending interests `organizer_notified`.
- **Admin UI:** Published events with vendor interest show inline email form, **Save email** / **Save & notify organizer**, pending-count badge, `needs_email=1` filter, analytics KPI `pending_organizer_notify`.
- **Tests:** `tests/php/external-events-email-extract-test.php`; E2E needs-email admin smoke in `external-events.spec.ts`.

### v12.14.0 (External Events Auto Sync — Phase 13C)
- **Auto sync:** `LNKup_External_Events_Sync` — scrape Eventbrite city browse pages for launch markets, import new events (`pending_review`), refresh stale rows via `merge_refetch_fields()`.
- **Cron:** `lnkup_external_events_sync` (daily or twice daily when enabled); separate from 15-minute follow-up cron.
- **Admin:** LNKup → External Events → **Auto sync** tab — enable, city checkboxes, limits, keyword filter, run now, last-run stats.
- **Provider:** `discover_event_urls()` on Eventbrite source; launch-city browse URL map for 10 markets.
- **Image fix:** Reject event page URLs stored as `image_url`; admin review warning + card illustration fallback.
- **REST:** `POST /external-events/provider-sync` supports `mode` (`full|discovery|refresh`) and optional `market`.
- **Tests:** `eventbrite-city-browse.html` fixture; extended parser smoke test + E2E auto sync tab.

### v12.13.0 (External Event Discovery V2 — Demand Engine, Phase 13B)
- **Schema V2:** `workflow_status`, demand score/metrics, email counters, activity/emails/duplicates tables; V1 rows backfilled to `published`.
- **Demand engine:** `LNKup_External_Events_Demand`, `Demand_Score`, threshold follow-ups (`Followup` + `Cron`), timeline logging.
- **Admin:** Review queue with inline edit (organizer email, image, event type), re-fetch from URL, email-on-file column, missing-field warnings; analytics KPI for published events missing organizer email.
- **Import:** Eventbrite URL parse enriches image (JSON-LD, og:image, __NEXT_DATA__), event type mapping, best-effort organizer email; **admin must add organizer email at review** for vendor-interest outreach when scrape omits it.
- **Claim landing:** `/claim-event/?token=` + `[lnkup_external_claim_landing]`; email CTAs use premium landing + open/click tracking.
- **Find Events:** Demand badges, score ring, category breakdown, include external / high demand filters, card motion.
- **REST:** Dashboard, analytics, providers, demand stats, import-url, merge, timeline, recommendations, publish/reject.
- **E2E:** Extended `tests/e2e/external-events.spec.ts`.

### v12.12.0 (External Event Discovery — Phase 13A)
- **SQL tables:** `lnkup_external_events`, `lnkup_external_event_interest`, `lnkup_external_event_claim_tokens`.
- **Admin:** LNKup → External Events — single form, CSV import, list filters, hide/claim actions.
- **Find Events:** Mixed grid with `.lnkup-event-card--external` — LNKup-style type badge, organizer row, category chips, themed illustration fallback when no remote image; legal disclaimer; vendor interest modal.
- **Organizer claim:** Sign-up `?claim_external_event=TOKEN` → claim REST → `lnkup_event` CPT; pending vendor interest panel on organizer dashboard.
- **REST:** `lnkup/v1/external-events/*` (search, interest, claim, admin import, organizer pending interests).
- **E2E:** `tests/e2e/external-events.spec.ts` + `tests/fixtures/external-events-sample.csv`.

### v12.11.1 (Financial dashboard + organizer UI fixes)
- **Sponsor payments:** `LNKup_Sponsor_Payments::total_revenue()` JOINs invoices (fixes `organizer_user_id` unknown column on `/financial/dashboard` JSON parse error).
- **Sponsor schema alignment:** `create_invoice()` / `record_payment()` use treasury columns (`sponsor_invoice_id`, `total_amount`, `paid_at`).
- **Organizer UI:** Event card v4 media column layout; intelligence expand panel load (no duplicate hero); hero title dedupe.

### Vendor Premium UI/UX Catch-up (Jul 2026)
- **Vendor home:** Decluttered command center — wallet/due strip, single upcoming hero, trust slot (profile ring lives in executive hero), compact quick actions, discovery rails without duplicate Upcoming subnav.
- **Operations:** Mission header surface, readiness ring shell, overview cards on secondary surfaces, support history via `LNKupUI` empty/error (no infinite “Loading…”).
- **Documents / Reviews:** Visible compliance hero + elevated doc cards; review score primary surface; dual-CTA empty states; review cards use surface + micro-lift.
- **Design system:** `.lnkup-vendor-section` rhythm, vendor hero title dedupe, due-strip styles in `vendor-dashboard.css` / theme `components.css`.

### Organizer UI catch-up (Next IA reference) (Jul 2026)
- **Overview `#overview`:** Next-like rhythm — hero → launch checklist → attention/priority first → payout strip → **6 focused KPIs** (live events, requests, approved, paid, payment due, open opportunities) → compact actions → apps snapshot / activity. Favorites/preferred/disputes demoted to quiet secondary chips. Trust strip deferred until hydrated (no permanent “Loading trust…”).
- **Nav IA:** Primary shell = Home (`#overview`) · Events (`#events`) · Vendors (`#applications`, also-active `#vendors`) · Money (`#financial`) · More. Discover vendors moved under More.
- **Sections:** Events / applications / vendors dual-CTA empties; event + request + vendor preview cards on primary surfaces; Approve / Waitlist / Decline hierarchy preserved.
- **Activity:** `LNKupUI.panelEmpty` / `panelError` on CRM activity body (no infinite loading).
- **Files:** `class-shortcodes.php`, `organizer-crm.js`, `verification.js`, `organizer-crm.css`, theme `inc/dashboard.php` + `components.css`.
- **Cashout fix:** Destination cashout uses Stripe Connect available (`organizer_cashout_available`) — see v12.2.2.

### v12.11.0 (Organizer Premium UI/UX Overhaul)
- **Command center:** Executive hero with checklist ring, 10 KPI strip, payout strip, quick-action row, apps snapshot, activity feed, readiness mini-cards.
- **Events & requests:** Gradient event card fallback, capacity bar thresholds, progressive disclosure on app cards.
- **CRM & discover:** Premium intel profile layout, contained timeline, verified filter, `panelEmpty` on lists/invites.
- **Financial / ops / intel / launch:** Organizer balance hero dedupe, wizard step gating, ops executive hero, intel score ring summary.
- **Design system:** Organizer section rhythm, launch checklist states, wizard custom inputs, hero title dedupe via `:has()`.

### v12.10.0 (Vendor Premium UI/UX Polish Pass)
- **Public vendor storefront:** Airbnb-style hero, gallery lightbox, metrics row, trust strip, sidebar reliability — `/vendors?vendor={slug}`.
- **Vendor home:** Executive hero, Action Center, business health KPIs with count-up, action tiles, deduped metrics, growth/ops previews.
- **Payments/Growth:** Premium invoice cards, deduped balance hero, `panelEmpty` on tabs, growth KPI stat cards, funnel visual, sticky tabs.
- **Design system:** Spacing tokens, status badge map, `animateKpiCards` / `mountSparkline`, array-aware `lnkup_empty_state()`.

### v12.9.0 (Premium Craftsmanship Pass)
- **UI-only:** Surface tier system (`.lnkup-surface--hero/primary/secondary/quiet`), typography utilities, shared chart/motion helpers in `LNKupUI`.
- **Executive heroes:** `lnkup_executive_hero()` on vendor apps, documents, payments, growth, reviews, operations; organizer events, vendor requests, financial, discover vendors.
- **Polish:** Vendor profile trust strip + section completion dots; marketplace card hierarchy; organizer event card v4 refinements; premium empty states with dual CTAs.
- **Theme:** `components.css` — compliance ring, executive hero, filter bar blur, table headers, empty-state icon.

### v12.8.0 (City Connector Program)
- **Tables:** `wp_lnkup_connectors`, `wp_lnkup_connector_events`; treasury `lnkup_referral_*` extended with `program=city_connector`, funnel columns, reward workflow fields.
- **Role:** `lnkup_connector` with dashboard caps; admin approval creates WP user + referral code.
- **Attribution:** `LNKup_Connector_Attribution` — cookie `lnkup_connector_ref`, signup meta, parallel with outreach; vendor `LNKup_Referral_Credits` unchanged for vendor program codes.
- **Tracking:** `LNKup_Connector_Tracking` — profile, application, payment revenue, event/opportunity milestones.
- **Rewards:** `LNKup_Connector_Rewards` — manual-by-default; admin approve/mark paid; optional `platform_credit` mode.
- **REST:** `lnkup/v1/connector/*`, `GET /referrals/resolve`, admin connector routes.
- **Public:** `/connector-interest/` interest form; city market CTA → interest page with `?market=`.
- **Dashboard:** `/connector-dashboard/` theme shell + `[lnkup_connector_dashboard]` + `connector.js/css`.
- **Admin:** LNKup → Connectors (pending/active/rewards); connector reward settings in LNKup Settings.
- **Tests:** `tests/e2e/connector-program.spec.ts`; extended `market-pages` + `outreach-invite` specs.

### v12.7.0 (City Market Landing Pages)
- **Registry:** `LNKup_Market_Pages` — option-backed city registry (10 launch cities), rewrite `/markets/{slug}/`, real SQL stats, discovery-backed opportunities.
- **Theme:** `page-templates/market-landing.php` + `template-parts/market/*` (hero, status, categories, opportunities, CTAs, FAQ).
- **SEO:** Rank Math title/description, robots, OG image, canonical, JSON-LD WebPage/ItemList, sitemap URLs.
- **Registration:** `?market=` + `?interest=connector` → `_lnkup_market_interest`, `_lnkup_signup_source`, `_lnkup_signup_city_page`.
- **Admin:** LNKup → City Markets — global toggle, per-market status/goals/SEO/featured taxonomies, flush rewrites.
- **Cross-links:** Market pages → `/opportunities/?city=&state=`; Find Events chip when city matches registry.
- **Tests:** `tests/e2e/market-pages.spec.ts`.

### v12.6.0 (Organizer Vendor CSV Import)
- **Import service:** `LNKup_Vendor_Import` — CSV parse/map/preview/run, prospect staging, duplicate modes, list/tag/invite integration.
- **Tables:** `lnkup_crm_import_job`, `lnkup_organizer_vendor_prospect`, `lnkup_vendor_list_prospect`.
- **REST:** `lnkup/v1/crm/import/*` (template, upload, preview, run, status) + `GET /crm/prospects`.
- **CRM UI:** 4-step import wizard (`vendor-import.js`), **Import vendors** CTA, **Imported** directory scope.
- **Outreach:** `mark_registered` links prospects → CRM relationship + list migration on vendor signup.
- **Admin:** `crm_import_max_rows_organizer`, `crm_import_max_file_size_mb`, `crm_import_allow_invite`.
- **Tests:** `tests/e2e/vendor-import.spec.ts` + `tests/fixtures/vendor-import-sample.csv`.

### v12.5.0 (Saved Searches & Opportunity Alerts)

- **Service:** `LNKup_Saved_Searches` — full saved search schema (alerts, frequency, pause, limits, visibility filtering).
- **REST:** `GET/POST /marketplace/saved-searches`, `PATCH/DELETE /{id}`, `POST /{id}/run` with vendor-only permissions.
- **UI:** Save-search modal on Find Events (no `prompt`); Saved Searches section on Favorites page (`saved-searches.js`).
- **Cron (1c):** Incremental daily/weekly digests via `created_after`; instant alerts on publish; favorite deadline reminders at 48h/24h.
- **Email:** Rich digest with opportunity cards, view-all and manage-alerts links.
- **Admin:** Saved search alert settings (limit, frequency, max matches per email).
- **Tests:** `saved-searches.spec.ts`.

### v12.4.0 (Trust & Verification)

- **Verification service:** `LNKup_Verification` orchestrates vendor/organizer trust levels, context-aware document requirements, and badge aggregation from profile strength, documents, reputation, Connect, and launch checklist data.
- **REST:** `GET /verification/vendor`, `/verification/vendors/{id}`, `/verification/organizer`, `/verification/organizers/{id}`, `POST /verification/admin/manual`.
- **Admin:** LNKup → Verification — document queue, vendor profile approval, organizer verified toggle.
- **Document modes:** Opportunities default to `none`; organizers can choose platform defaults or custom required documents (`_lnkup_document_mode`).
- **UI:** Vendor home trust card, organizer trust strip, application review badges, opportunity card organizer trust chips.
- **Tests:** `verification-trust.spec.ts`.

### v12.2.3 (financial dashboard JSON + balance display)

- **Dashboard REST:** Removed auto-reconcile from `/financial/dashboard` hot path; isolated report/payout failures with try/catch; UTF-8-safe REST sanitization.
- **Balances route:** `GET /financial/organizer-balances` — slim payload for hero/overview (Stripe Connect + `display_balances`).
- **Reports:** `organizer_report()` guards legacy `LNKup_Payouts` queries; health score uses lightweight SQL instead of full report recursion.
- **Frontend:** Wallet-first balance loading; dashboard optional for revenue KPIs; JSON parse errors log response body snippet.

### v12.2.2 (organizer financial balance display)

- **Dashboard:** `/financial/dashboard` no longer zeroes balances when reports layer throws; always attaches Stripe Connect + `display_balances`.
- **Balances:** Shared `organizer_balance_payload()` for dashboard, wallet, and fallback states.
- **Frontend:** Balance hero and Overview merge `/organizer-wallet` (live Stripe) with dashboard; destination Connect pending/available shown consistently.
- **Cashout (destination):** `organizer_cashout_available()` gates POST `/financial/organizer-wallet/cashout` on **live Stripe Connect available** (same ceiling as Money UI). Ledger no longer forces destination `available` to $0; cashout errors return HTTP 400 with message; UI disables form when max is $0 / settling / Stripe unsynced.
- **Cashout execution:** `request_cashout` returns `payout_failed` (HTTP 400) when Stripe `Payout::create` fails — no false “Cashout requested”. `connect_payout_schedule` applied on Connect account create; default **manual** for in-app cashout; `ensure_payout_schedule()` on refresh/cashout. Instant option hidden unless `connect_instant_payouts_enabled`.
- **Cashout preflight:** Live balance + currency (settings, not hardcoded USD), external bank/debit check, Instant eligibility, optional `source_type`. Failed payouts expose `failure_reason` in REST + Money Payouts UI.
- **Payments:** Auto-reconcile on dashboard load; `payment_intent.processing` webhook; Sync payment button for stuck pending transactions.
- **Fees:** Vendor checkout fee $5.99 settings-first model; pending payment fee refresh at checkout (v12.1.7 carryover).

### v12.2.1 (weather integration polish)

- **Activator:** Weather snapshots table + daily cron scheduled on plugin activation.
- **Frontend:** `weather.js` loads before operations/intelligence; poll hydrates ops panel from cached snapshot DTO; vendor ops home shows weather note; intelligence insights render weather block.
- **UI:** Insights tab SSR weather block; compact readiness card test IDs; script dependency order fixed.
- **Tests/docs:** `weather-intelligence.spec.ts`; SYSTEM_OVERVIEW §9.6.1 cron + assets tables.

### v12.2.0 (Event Weather Intelligence — OpenWeather)

- **Weather module:** `LNKup_Weather_Service`, OpenWeather provider, risk engine, snapshots table, REST (`/weather/*`), daily cron, render helpers.
- **Modes:** Live forecast (0–7d), extended (8–16d), historical risk (17+d), graceful unavailable states.
- **UI:** Organizer Weather Readiness card; Event Operations weather panel + Insights block; intelligence DTO `weather`; vendor packet note when enabled.
- **Settings:** LNKup → Settings → Weather Intelligence — enable toggle, OpenWeather API key (server-side only), units, cache TTL, historical toggle, vendor packet note.
- **Frontend:** `weather.js` / `weather.css` — refresh/retry, panel hydration from cached snapshot DTOs.

### v12.1.7 (vendor checkout fee + payment finalize + intelligence fix)

- **Fee engine:** Vendor checkout fee resolves from `vendor_checkout_fee` settings ($5.99 default); legacy `default_fee` aliases synced on migration; global flat SQL fee rules deactivated.
- **Pending payments:** Fees recalculate at checkout, on settings save, and when admin loads (deferred upgrade).
- **Checkout finalize:** Stripe 3DS return URL auto-confirms; stuck pending payments finalize when Stripe intent already succeeded; safer reconcile guard.
- **Event Intelligence:** Organizer expand panel no longer stuck on skeleton — 8s fetch timeout, in-flight dedupe, `lnkup-intel-expand` hook, single retry handler, REST `dashboard()` array guards.

### v12.1.6 (operations command center UX enhancement)

- **Operations nav architecture:** Organizer event command center now uses 5 grouped section pills (Command, Logistics, Maps, Field, Intel) plus sticky sub-tabs with `ops_tab` deep-link restore.
- **Overview cleanup:** Compliance Alerts and Live Feed moved into the Overview panel; added logistics snapshot stats and quick section jumps.
- **Panel consistency:** Added shared panel shells, per-panel headers, and stats strips for logistics/field/intel tabs.
- **Loader and mobile UX:** Unified skeleton/empty/error behavior for REST tabs, cached panel loading, mobile card rendering for table data, and readiness filter chips.
- **Cross-module coordination:** New `lnkup-ops-panel-open` event syncs Operations with Financial/Insights/Maps lazy loading.

### v12.1.5 (vendor UX + ops prefill)

- **Vendor Payments:** Financial REST honors `?role=vendor` for dual-role users; hero KPIs align with transactions (Amount due, Total spent, Credits); 4-section nav (Pay / Wallet / Extras / Reports); subscriptions tab hidden; mobile transaction cards.
- **Referral Credits:** Schema-aligned treasury inserts; `?ref=` cookie capture; share link + rewards history in financial UI.
- **Growth Markets:** Fixed API key mismatches in Markets tab; KPI strip, trending chips with Find Events deep links, relationship cards, ROI bars, mobile layout.
- **Find Events:** Renamed filters (All categories / All subtypes); attendance + market toolbar wired to live search; subtype hint text.
- **Event Operations:** `bootstrap_from_event()` on create + first command-center load; `GET /operations/events/{id}/defaults`; slide-over add forms replace `window.prompt`.

---

## Monorepo Layout

```
lnk-up-marketplace-platform/
├── app/                              # Next.js 16 reference UI (v0 template)
├── components/                       # React/shadcn components (reference)
├── package.json                      # Next.js app (pnpm, React 19, Tailwind 4)
├── README.md                         # Setup instructions
├── docs/CURRENT_STATE.md             # This file
└── wp-content/
    ├── themes/lnkup-theme/           # WordPress theme (production UI)
    └── plugins/lnkup-marketplace/    # Marketplace logic plugin
```

---

## 1. Next.js Reference Frontend (Design Source)

Runnable v0 template kept for design comparison. **Not wired to WordPress** — static/mock UI only.

**Stack:** Next.js 16.2, React 19, Tailwind 4, shadcn/ui, Recharts, Lucide icons.

### Routes (`app/`)

| Area | Pages |
|------|-------|
| Landing | `/` (`page.tsx`) |
| Vendor dashboard | `/dashboard`, `/dashboard/find-events`, `/dashboard/applications`, `/dashboard/documents`, `/dashboard/payments`, `/dashboard/profile`, `/dashboard/reviews` |
| Organizer dashboard | `/organizer`, `/organizer/create`, `/organizer/import`, `/organizer/applications`, `/organizer/vendors`, `/organizer/payouts` |
| Admin | `/admin`, `/admin/events`, `/admin/vendors`, `/admin/organizers`, `/admin/analytics` |

### Components (`components/`)

- **Landing:** `hero`, `how-it-works`, `categories`, `featured`, `site-header`, `site-footer`
- **Dashboard:** `shell`, `stat-card`, `status-badge`, `app-status-badge`
- **Brand:** `logo`
- **UI:** 22 shadcn primitives (button, card, table, dialog, sidebar, chart, etc.)
- **Shared:** `event-card.tsx`

**Run locally:**

```bash
pnpm install && pnpm dev
```

---

## 2. WordPress Theme — `wp-content/themes/lnkup-theme`

Classic PHP theme (not block/FSE). Black/white/lime accent matching the v0 design.

### Templates

| File | Purpose |
|------|---------|
| `front-page.php` | Landing page — composes all landing partials |
| `page.php` | Default page with site header/footer |
| `index.php` | Fallback → `page.php` |
| `header.php` / `footer.php` | Minimal HTML shell |

### Page Templates

| Template | Role gate | Shortcode |
|----------|-----------|-----------|
| `page-templates/vendor-dashboard.php` | `lnkup_vendor`, `lnkup_admin`, `administrator` | `[lnkup_vendor_dashboard]` |
| `page-templates/organizer-dashboard.php` | `lnkup_organizer`, `lnkup_admin`, `administrator` | `[lnkup_organizer_dashboard]` |
| `page-templates/full-width.php` | None | Raw page content |

### Landing Sections (`template-parts/landing/`)

- `site-header.php` — sticky nav, mobile menu, login/dashboard CTAs
- `hero.php` — hero + CTA + trust bullets
- `how-it-works.php` — 4-step onboarding
- `categories.php` — 13 vendor category chips
- `featured.php` — featured events grid (plugin data or fallback)
- `trust.php` — stats / trust messaging
- `final-cta.php` — bottom conversion banner
- `site-footer.php` — multi-column footer

### Theme Modules (`inc/`)

| File | Role |
|------|------|
| `setup.php` | `title-tag`, thumbnails, HTML5, nav menu registration, page template labels |
| `enqueue.php` | Google Fonts (Sora + Inter), CSS, JS |
| `template-tags.php` | Logo, asset URLs, page URLs, dashboard detection |
| `dashboard.php` | Login/role guards, sidebar nav, dashboard shell markup |

### Assets

| Asset | Purpose |
|-------|---------|
| `assets/src/main.css` | Tailwind v4 source + design tokens |
| `assets/dist/lnkup.css` | Compiled Tailwind (enqueued) |
| `assets/dist/components.css` | Hand-written BEM styles (header, hero, dashboard, forms, plugin UI) |
| `assets/js/theme.js` | Mobile nav toggle |
| `assets/js/dashboard.js` | Dashboard sidebar toggle |
| `assets/images/events/*.svg` | 5 event illustration SVGs |

**Build CSS:**

```bash
cd wp-content/themes/lnkup-theme && npm install && npm run build
```

### Theme Gaps (intentional / not built)

- No `single.php`, `archive.php`, `404.php`, `theme.json`
- Nav menus registered but templates use hardcoded links
- No Customizer, widget areas, or block patterns
- No `screenshot.png` or `languages/` folder

---

## 3. WordPress Plugin — `wp-content/plugins/lnkup-marketplace`

All marketplace business logic lives here. Bootstrap: `lnkup-marketplace.php`.

### PHP Classes

| Class | File | Purpose |
|-------|------|---------|
| `LNKup_Activator` | `includes/class-activator.php` | Activation, pages, sample data |
| `LNKup_Roles` | `includes/class-roles.php` | Roles, registration role picker |
| `LNKup_Post_Types` | `includes/class-post-types.php` | 7 CPT registrations |
| `LNKup_Taxonomies` | `includes/class-taxonomies.php` | 3 taxonomies + term seeding |
| `LNKup_Meta` | `includes/class-meta.php` | Meta schema + admin meta boxes |
| `LNKup_Capabilities` | `includes/class-capabilities.php` | Ownership checks, `user_has_manage_all()` |
| `LNKup_Security` | `includes/class-security.php` | Nonces, MIME allowlist, sanitization |
| `LNKup_Applications` | `includes/class-applications.php` | Apply, approve, reject, waitlist |
| `LNKup_Payments` | `includes/class-payments.php` | Payment records, fee calculation |
| `LNKup_Packets` | `includes/class-packets.php` | Vendor packet lock/unlock, QR pass |
| `LNKup_Stripe` | `includes/class-stripe.php` | Checkout API, test simulation, webhook |
| `LNKup_Documents` | `includes/class-documents.php` | Upload, download, verification |
| `LNKup_Notifications` | `includes/class-notifications.php` | HTML email via `wp_mail` |
| `LNKup_Ajax` | `includes/class-ajax.php` | Logged-in AJAX handlers |
| `LNKup_Shortcodes` | `public/class-shortcodes.php` | Frontend shortcode renderers |
| `LNKup_Admin` | `admin/class-admin.php` | Admin menu + settings |
| Helpers | `includes/helpers.php` | Currency, queries, event card HTML |

### Custom Post Types (7)

| Slug | Label | Public | Notes |
|------|-------|--------|-------|
| `lnkup_vendor_profile` | Vendor Profiles | No | Business profile per vendor |
| `lnkup_event` | Events | Yes | Organizer events |
| `lnkup_opportunity` | Opportunities | Yes | Vendor slots per event |
| `lnkup_application` | Applications | No | Vendor applications |
| `lnkup_document` | Documents | No | Compliance uploads |
| `lnkup_payment` | Payments | No | Payment records |
| `lnkup_packet` | Vendor Packets | No | Post-payment venue info |

### Taxonomies (3)

| Slug | Attached To | Seeded Terms |
|------|-------------|--------------|
| `lnkup_vendor_category` | vendor profiles, opportunities | 13 (Food Vendor, Food Truck, etc.) |
| `lnkup_event_type` | events | 12 (Festival, Rodeo, etc.) |
| `lnkup_market` | events, opportunities | Hierarchical, no seed |

### Roles & Capabilities

| Role | Key capabilities |
|------|------------------|
| `lnkup_vendor` | `manage_own_profile`, `view_opportunities`, `submit_applications`, `manage_own_documents`, `view_own_payments` |
| `lnkup_organizer` | `manage_own_events`, `manage_own_opportunities`, `review_applications`, `manage_own_packets`, `view_own_payments` |
| `lnkup_admin` | `manage_all`, `verify_documents`, `manage_settings` |
| `administrator` | Receives all LNKup caps on activation |

Registration form adds Vendor/Organizer dropdown; `user_register` assigns selected role.

### Shortcodes (8)

| Shortcode | Purpose |
|-----------|---------|
| `[lnkup_vendor_dashboard]` | Vendor overview stats + quick links |
| `[lnkup_organizer_dashboard]` | Organizer overview stats |
| `[lnkup_opportunities_feed]` | Public browse/apply feed |
| `[lnkup_vendor_profile_form]` | Vendor business profile CRUD |
| `[lnkup_organizer_events]` | Event list + opportunity creation |
| `[lnkup_applications_manager]` | Organizer application review |
| `[lnkup_documents_manager]` | Vendor document uploads |
| `[lnkup_payments_dashboard]` | Payment history + checkout |

### AJAX Actions (logged-in only)

| Action | Purpose |
|--------|---------|
| `lnkup_apply` | Submit vendor application |
| `lnkup_save_profile` | Create/update vendor profile |
| `lnkup_upload_document` | Upload compliance document |
| `lnkup_review_application` | Organizer approve/waitlist/reject |
| `lnkup_create_event` | Create/import event |
| `lnkup_create_opportunity` | Create opportunity for event |
| `lnkup_download_document` | Secure document download |
| `lnkup_export_vendors` | CSV export for event |
| `lnkup_create_checkout` | Start Stripe or simulation checkout |
| `lnkup_simulate_payment` | Test-mode payment completion |

**REST:** `POST /wp-json/lnkup/v1/stripe-webhook` — handles `checkout.session.completed`

### Application Workflow

```
Vendor applies → submitted
Organizer opens → under_review
Organizer approves → payment_required (Payment CPT created)
Vendor pays (Stripe or simulation) → paid → packet unlocked
```

**Application statuses:** `submitted`, `under_review`, `approved`, `payment_required`, `paid`, `waitlisted`, `rejected`, `completed`, `cancelled`

**Payment statuses:** `pending`, `paid`, `failed`, `refunded`, `disputed`

**Document types:** `certificate_of_insurance`, `health_permit`, `business_license`, `w9`, `sales_tax_certificate`, `other`

### Admin UI

| Page | Capability | Content |
|------|------------|---------|
| **LNKup** (top-level) | `lnkup_manage_all` | Stats: vendors, organizers, events, applications, GMV, revenue, pending docs |
| **Settings** (submenu) | `lnkup_manage_settings` | Fees, Stripe keys, test mode, packet lock, default documents, notifications |
| Native CPT screens | WP defaults | All 7 CPTs editable in wp-admin |

### Plugin Settings (`lnkup_settings` option)

| Key | Default |
|-----|---------|
| `vendor_checkout_fee` | `5.99` |
| `default_fee` | `5.99` (legacy alias, synced with `vendor_checkout_fee`) |
| `application_fee_flat` | `5.99` (legacy alias) |
| `organizer_commission_percent` | `6` |
| `fee_payer_mode` | `vendor` |
| `stripe_public_key` / `stripe_secret_key` | empty |
| `test_mode` | `true` |
| `default_packet_lock` | `true` |
| `default_documents` | COI + business license |
| `notify_vendor` / `notify_organizer` / `notify_admin` | `true` |
| `support_email` | site admin email |

Pending payments recalculate vendor checkout fee + organizer commission from these settings at checkout (not only at application approval).

### Activation Behavior

On first activate (`includes/class-activator.php`):

1. Register roles, CPTs, taxonomies + seed taxonomy terms
2. Create 4 pages with shortcodes + theme templates
3. Seed 5 sample events + opportunities (Charlotte, Atlanta, Miami, Raleigh, Greensboro)
4. Create demo user `lnkup_demo_organizer` (`lnkup_organizer` role)
5. Set front page to `home` if none configured
6. Flush rewrite rules

| Page slug | Title | Shortcode |
|-----------|-------|-----------|
| `vendor-dashboard` | Vendor Dashboard | `[lnkup_vendor_dashboard]` |
| `organizer-dashboard` | Organizer Dashboard | `[lnkup_organizer_dashboard]` |
| `opportunities` | Find Events | `[lnkup_opportunities_feed]` |
| `vendor-profile` | Vendor Profile | `[lnkup_vendor_profile_form]` |

**Deactivation:** flush rewrites only (data persists).

**Uninstall:** deletes settings options only — not CPT posts or roles.

---

## 4. Production Issues & Fixes Applied

### Issue 1: White screen on plugin activation (fixed)

Hardening across bootstrap, meta registration, shortcodes, activator, and admin error notices:

- PHP 7.4+ guard with admin notice
- Safe `file_exists()` requires
- Removed PHP 7.4-incompatible arrow functions in shortcodes
- Boolean meta `default` + `rest_sanitize_boolean`
- Money fields as `type => string`
- Activator try/catch + safer demo seeding
- Activation error transient shown in admin

### Issue 2: Memory exhaustion on thelnkup.com (fixed in repo)

**Symptom:** `Allowed memory size of 805306368 bytes exhausted` in `wp-includes/functions.php`

**Cause:** Infinite recursion in `map_meta_cap` — called `user_can('lnkup_manage_all')` which re-entered the same filter.

**Fix in `includes/class-capabilities.php`:**

- Removed broken `map_meta_cap` filter
- Added `LNKup_Capabilities::user_has_manage_all()` — checks `$user->allcaps` directly
- Replaced all `user_can('lnkup_manage_all')` calls across 7 plugin files

**Deploy:** Upload updated plugin to production (`thelnkup.com`) to take effect.

---

## 5. WordPress Setup Checklist

1. Copy/link `wp-content/themes/lnkup-theme` and `wp-content/plugins/lnkup-marketplace` into WordPress
2. Activate **LNKup Marketplace** plugin
3. Activate **LNKup Theme**
4. Build theme CSS (`npm run build` in theme dir)
5. Confirm **Settings → Reading** uses static front page
6. Configure Stripe under **LNKup → Settings** (or use test-mode simulation)
7. Assign `lnkup_vendor` / `lnkup_organizer` roles to test users

---

## 6. What Is NOT Built Yet

These exist in the Next.js reference but have no WordPress equivalent yet (or are placeholder-only):

- Admin analytics dashboard (beyond basic LNKup overview stats and Financial Center)
- Live Stripe Connect onboarding (payout abstraction + manual mark-paid exists)
- Vendor reviews system (reputation reviews exist; separate from payment disputes)
- Event import (Eventbrite / Posh / RSS) — **Built** for organizer Create Event + admin URL import; see [PHASE_EXTERNAL_EVENT_IMPORT.md](PHASE_EXTERNAL_EVENT_IMPORT.md)
- Attendee ticketing
- Block theme / FSE support
- WP nav menu integration in theme header/footer
- Full `map_meta_cap` ownership mapping for CPT edit/delete (currently uses ownership helpers in plugin logic only)
- CI/CD pipeline

### Phase 9 Financial Platform (v9.0.0) — Built

| Component | Notes |
|-----------|--------|
| SQL ledger (`lnkup_transactions` + 16 related tables) | Dual-write from `lnkup_payment` CPT |
| Payment provider abstraction | `LNKup_Stripe_Provider`, `LNKup_Manual_Provider` |
| Invoices, receipts, wallet, credits, refunds, disputes, payouts | Full service layer + audit log |
| Installments + deposits | Opportunity meta-driven; checkout partial amounts |
| Promo codes | Validate/redeem at checkout |
| Vendor financial hub | `?view=payments` with lazy REST tabs |
| Organizer `#financial` panel | Revenue, payouts, refunds, disputes, reports |
| Admin Financial Center | `LNKup → Financial` wp-admin submenu |
| Event Financials tab | Inside event operations console |
| REST API | `lnkup/v1/financial/*` |
| E2E tests | `vendor-financial.spec.ts`, `organizer-financial.spec.ts` |

W-9 / 1099 **summary PDFs** are built (`GET /financial/tax-center/w9/pdf` and `/1099/pdf` via Dompdf; not official IRS forms). Placeholder-only: email receipts, fraud ML. Apple/Google Pay are **operator flags** (Dashboard domains). ACH is **built** on PI + Checkout Session (processing ≠ unlock; Session finalizes only when `payment_status=paid`) — see [PHASE_26_FINANCIAL_OS_EXPANSION.md](PHASE_26_FINANCIAL_OS_EXPANSION.md) / [FINANCIAL.md](../wp-content/plugins/lnkup-marketplace/FINANCIAL.md).

### Phase 10 Vendor Growth Platform (v10.0.0) — Built

| Component | Notes |
|-----------|--------|
| Growth SQL tables | Snapshots, benchmarks, goals, ROI, profit estimates, milestones, recommendations |
| Vendor Growth Dashboard | `?view=growth` with lazy REST tabs (`growth.css` / `growth.js`) |
| KPI strip | Lifetime revenue, vendor score, growth rate, profile strength, etc. |
| Revenue analytics | Over time, by month, by event/city/organizer/category/season |
| Application funnel | Vendor-scoped approval/rejection/waitlist analytics |
| Match scores | `LNKup_Growth_Match` on opportunity cards + REST |
| Goals + achievements | CRUD goals; badge progress from `LNKup_Vendor_Badges` |
| Profile strength | 17-item checklist score |
| Rule-based recommendations | `lnkup_vendor_recommendations` with dismiss |
| Benchmarks | Marketplace percentiles (no private vendor data exposed) |
| ROI + profit tracking | Private worth-it ratings; organizers cannot see |
| Organizer relationships | Top organizers, repeat bookings, favorites |
| Customer journey | Milestone timeline |
| Business calendar | Events, deadlines, payments, goals, document expirations |
| CSV exports | Revenue, events, performance (PDF placeholder) |
| Admin Growth Center | `LNKup → Growth` marketplace aggregates only |
| REST API | `lnkup/v1/growth/*` |
| E2E tests | `vendor-growth.spec.ts` |

AI-ready placeholders: revenue forecast endpoint returns null; recommendation `source` field for future AI swap.

---

## 7. File Counts Summary

| Area | Files (approx.) |
|------|-----------------|
| Plugin PHP + JS + templates | 21 source files |
| Theme PHP + CSS + JS | ~30 source files (excl. node_modules) |
| Next.js app routes | 24 pages |
| React components | 34 files |

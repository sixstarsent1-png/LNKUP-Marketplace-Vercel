# Engagement OS — Implementation Ledger and Report

Engagement, communications, matching and growth work built on the existing **lnkup-communications** plugin (now 1.3.0, DB schema 1.1.0) and the **lnkup-marketplace** matching OS (12.24–12.34). Started and delivered Nov 23, 2026.

Status labels used below: **Verified** (code + automated test in this repo), **Needs improvement**, **Partial**, **Duplicated**, **Broken**, **Missing**, **Deprecated**, **Needs production check** (works in local tests; no production delivery evidence).

Related: [COMMUNICATIONS_MATRIX.md](COMMUNICATIONS_MATRIX.md), [COMMUNICATIONS_AUDIT.md](COMMUNICATIONS_AUDIT.md), [COMMUNICATIONS_ROLLOUT.md](COMMUNICATIONS_ROLLOUT.md), [MATCHING_DISCOVERY.md](MATCHING_DISCOVERY.md).

## 1. Executive summary

Most of the requested system already existed: a queued, deduplicated, re-validated notification engine with preferences, unsubscribe, quiet hours, digests, Expo receipts and an admin area; a rules-based match engine with event candidates, a recruitment pipeline, batch invites and tours. Nothing was rebuilt. This pass closed the verified gaps:

- **Policy:** weekly push cap, per-category caps, cross-channel cooldown and measurement holdouts; defaults now 1 push/day, 3 push/week, 2 promotional emails/week.
- **Organizer fulfillment:** a per-event slot view (capacity, held, paid, remaining by category, review backlog, invite outcomes, deadline, matching vendors, ranked actions) on WordPress, Next.js and Expo.
- **Find Vendors:** New near you, Previously booked, Saved and Recently viewed sections on all three surfaces.
- **New journeys:** invitation accepted/declined, new vendors near your event (public vendor cards, never contact details), deadline-based open-spots, published event without slots, expiring invitations, unfinished applications, opportunity-led vendor reactivation (14/30/60 days, never without a real match), first-event congratulations.
- **Next best action:** next steps now include invitations, saved applications, live recommendations, slot actions and slot-less events, ranked and cached, and they disappear when the action is done.
- **Invitations:** scheduled expiry, vendor list and accept/decline by ID (WordPress, Next.js, Expo).
- **Admin:** template drafts, publish, version history and restore; per-user communication timeline; suppression list; audit logging of settings, flags, templates, timeline views and suppression removals; treated-vs-holdout comparison; outcome attribution for new triggers.
- **Notification center:** a WordPress notification center page, Invitations and Account tabs on every surface, notification-type sync with drift tests (web + mobile), mobile route fallbacks, badge sync on launch/foreground, web unread polling and per-tab mark-all.

Every new flow ships **off** behind a flag (`flow_vendor_engagement` is new; the rest reuse `flow_recruitment_updates`, `flow_activation`, `flow_discovery`, `flow_post_event`).

## 2. Existing infrastructure audit (before this work)

| Area | Status | Evidence |
|---|---|---|
| Notification engine (dispatch, dedupe, send-time revalidation, cancel keys) | Verified · Needs production check | `LNKup_Comms_Engine`, `LNKup_Comms_Context::check()`, `comms-engine-test.php` |
| Background processing (Action Scheduler, retries 5m/30m/2h/6h) | Verified · Needs production check | `LNKup_Comms_Queue`; real cron runner in COMMUNICATIONS_ROLLOUT Step 3 |
| Priorities | Verified | `LNKup_Comms_Registry::PRIORITIES` |
| Frequency caps | Partial → **Verified** | Was promotional-only per channel; now weekly push + per-category caps |
| Cross-channel cooldown | Missing → **Verified** | `LNKup_Comms_Frequency::in_cooldown()` |
| Quiet hours + user timezone | Verified (push only) | `quiet_seconds_remaining()` |
| Digests | Verified | `LNKup_Comms_Auto_Digests` |
| Preferences (13 categories × email mode × push) | Verified | `/comms/preferences`, web, Expo, Next.js |
| One-click unsubscribe, suppression list, bounce webhook | Verified · Needs production check | `LNKup_Comms_Suppression` |
| Expo receipts + DeviceNotRegistered cleanup, multi-device | Verified | `LNKup_Comms_Channel_Push::check_receipts()` |
| Android FCM | Missing (credentials needed) | No `google-services.json` |
| Admin area | Verified | `LNKup_Comms_Admin` |
| Template version history / drafts | Missing → **Verified** | `template_versions` table |
| Per-user timeline | Partial → **Verified** | Admin → User timeline |
| Suppression list viewer | Missing → **Verified** | Admin → Suppression list |
| Audit log for settings/templates | Missing → **Verified** | `LNKup_Comms_Admin_Ops::audit()` → Trust audit log |
| Holdouts | Missing → **Verified** | `holdout_percent`, `holdout_triggers` |
| Attribution (observed) | Verified; extended | `LNKup_Comms_Analytics::attributed()` |
| Matching engine, candidates, pipeline, batch invites, tours | Verified | MATCHING_DISCOVERY §1–§12 |
| Event slot fulfillment | Missing → **Verified** | `LNKup_Event_Fulfillment` |
| Find Vendors sections | Missing → **Verified** | `section=` on `/match/organizer/events/{id}/vendors` |
| Real-time "new vendors near your event" with cards | Missing → **Verified** | `o_new_vendors_nearby` |
| Invite expiry | Broken → **Verified** | `LNKup_Vendor_Invites::expire_overdue()` (twice daily) |
| Invite accepted/declined → organizer | Missing → **Verified** | `o_invite_response` |
| Vendor accept/decline UI | Missing → **Verified** | WP center, `/vendor/invitations`, Expo `(vendor)/invitations` |
| Draft application reminders | Missing → **Verified** | `v_application_draft` |
| Vendor inactivity journey | Missing → **Verified** | `v_inactive` |
| First-event-completed vendor message | Partial → **Verified** | `v_event_thanks:first` |
| Published event without slots | Partial → **Verified** | `o_event_no_slots` + next step |
| Next best action | Partial → **Verified** | `LNKup_Comms_Engagement::next_steps()` |
| WP dashboard notification center | Missing → **Verified** | `[lnkup_notification_center]`, `/notification-center/` |
| Notification type drift | Broken → **Verified** | Fixture + Jest drift test (Expo) + `npm run check:comms-types` (Next.js) |
| Inbox tab mapping for shared types | Broken → **Verified** | First definition wins; admin triggers excluded (`organizer_vendor_paid` and `organizer_payout_setup` now Payments, `system` Event updates) |
| Expo badge sync on launch/foreground | Partial → **Verified (code + Jest)** · Needs real-device check | `push-bootstrap.native.tsx` |
| Next.js unread refresh | Partial → **Verified (typecheck)** | `use-notification-count.ts` polls 60s + focus |
| Organizer home per-slot queries | Needs improvement → **Verified** | `LNKup_Organizer_Overview::upcoming()` reads cached fulfillment |
| Android/iOS deep link on cold start | Verified (Jest) · Needs real-device check | `getLastNotificationResponseAsync` |

## 3. Existing functionality preserved

The engine contract (`dispatch` → `deliver`), dedupe keys, cancel keys, priorities, existing triggers and templates, flags, rollout cohorts, delegation from lnkup-marketplace, matching rules and weights, fairness rules, privacy rules (`public_vendor_card`, contact unlock), batch invite caps, financial notifications (still gated by `fin_payout_notifications`) and every existing REST response shape. Existing suites pass unchanged except two expectation updates that reflect intended behavior: inbox tabs now include Invitations and Account, and the mobile router falls back to the notifications screen instead of returning nothing for unknown types.

## 4. Duplicates consolidated

- Core notification types list existed inline in `types_for_tab()`; now `LNKup_Comms_Registry::core_types()` feeds the inbox filter and the type export.
- Organizer home capacity/paid counts were computed per slot in `LNKup_Organizer_Overview`; it now reuses `LNKup_Event_Fulfillment` (one grouped query, cached).
- `related_vendor_users()` was recomputed on every candidate/count call; it is now memoized per request and cached 10 minutes (flushed on application submit and invite status change).
- Template edits had no history; drafts and publishes now share one validation path (`validate_fields()`).

## 5. Issues found and resolved

| Issue | Fix |
|---|---|
| Invites stayed "sent" forever after expiring | Twice-daily `lnkup_vendor_invites_expire` sweep; lazy expiry also fires the status hook |
| `lnkup_vendor_invite_status_changed` fired twice (meta watcher 4 args + explicit 2 args); a 2-arg listener reads the *old* status from the watcher | New listeners read the new status from either signature; engine dedupe keeps one message |
| Shared notification types landed in the wrong inbox tab (last definition won; admin triggers claimed `system`) | First definition wins; admin-only triggers skipped |
| Mobile: 11 server types unknown (rendered "Update"), 42 had no fallback route; push taps with no route did nothing | Types added, routes added, role-based fallback; drift test |
| Next.js mapped every server-only type to "Update" | `COMMS_SERVER_TYPES` + labels; drift script |
| Next.js unread count never refreshed | 60s polling while visible + focus refresh, prefers server count |
| Next.js organizer vendors page ignored `?tab=` | Honors `tab=find|invites|…` |
| Next-steps cache could miss records inserted before owner meta | Also flushes on `added_post_meta` for owner keys |
| Passport "See invitations" opened Find Events | Opens the invitations screen |

## 6. New capabilities

See §7 (triggers), §8–§9 (journeys), §10 (matching), §11 (admin), §15–§16 (clients).

## 7. Notification trigger registry (new and changed)

All flags start **off**. Every message is validated when scheduled and again right before sending.

| Trigger | Recipient | Channels | Priority / category | Flag | When | Send-time check | Deep link |
|---|---|---|---|---|---|---|---|
| `o_invite_response` (`:accepted` / `:declined`) | Organizer | inbox, push | normal / recruitment | `flow_recruitment_updates` | Vendor accepts or declines | Invite status still matches | `/(organizer)/vendors?tab=invites` |
| `o_new_vendors_nearby` | Organizer | inbox, email, push | promotional / recruitment | `flow_recruitment_updates` | Hourly; ≥3 new discoverable matches for a live event with open spots; 72h cooldown per event; first run records a baseline | Event live, upcoming, open spots, count > 0 | `/(organizer)/vendors/find?event={id}` |
| `o_open_spots:deadline` | Organizer | all | normal / recruitment | `flow_recruitment_updates` | Daily, 7 and 2 days before the application deadline while spots remain | Open spots remain | Find Vendors |
| `o_open_spots` (changed) | Organizer | all | normal | same | 14/7 days before the event; now includes "Still open: 4 Food, 2 Clothing" | same | same |
| `o_event_no_slots` | Organizer | all | normal / onboarding | `flow_activation` | Daily; published ≥1 day, upcoming, no published opportunity (day 1 and day 4) | Still no published opportunity, not cancelled/past | `/(organizer)/events/{id}` |
| `v_invite_expiring` | Vendor | all | normal / opportunities | `flow_discovery` | Daily; open invite expiring in 2–3 days | Invite still open, opportunity open, not applied | `/(vendor)/invitations` |
| `v_application_draft` | Vendor | all | normal / applications | `flow_vendor_engagement` | Daily; 24h and 72h after the last edit (within 8 days), once ≤3 days before the deadline; per draft revision | Draft open and owned, opportunity open, not applied | Opportunity |
| `v_inactive` | Vendor | all | promotional / opportunities | `flow_vendor_engagement` | Daily batch; idle 14/30/60 days; once per segment per activity date; **only** with live recommendations | No new activity since, still has recommendations | `/(vendor)/find` |
| `v_event_thanks:first` | Vendor | inbox, email | normal | `flow_post_event` | First paid/completed booking's event completes | — | `/(vendor)/find` |

Inbox tabs: vendor `all, applications, payments, events, opportunities, invitations, account`; organizer `all, applications, payments, events, operations, invitations, account`. Invitation types (`discovery_invite`, `discovery_invite_expiring`, `organizer_invite_response`) go to Invitations; onboarding and account categories go to Account. Full export: `wp lnkup-comms export-types`.

## 8. Organizer lifecycle journey map

| Journey | State signal | Message(s) | Stops when |
|---|---|---|---|
| A. Incomplete profile | Activation stage | `o_setup_profile` (existing) | Stage complete |
| B. No event | No events | `o_welcome`, `o_onboarding` day 1/3/7 (existing) | Event created / guided setup done |
| C. Draft event | `_lnkup_event_status = draft` with slots | `o_publish_draft` (existing) | Published |
| D. Published without slots | Published, 0 published opportunities | **`o_event_no_slots`**, next step "Add vendor opportunities" | A slot is published |
| E. Published, no applications | Live 5+ days, 0 applications | `o_no_applications` (existing), **fulfillment actions** (find vendors, share link) | First application |
| — Matching vendors appear | New discoverable matches | **`o_new_vendors_nearby`** (cards), weekly `o_new_matching_vendors` | Cooldown / no open spots |
| — Deadline approaching | Deadline in 7/2 days, spots open | **`o_open_spots:deadline`** | Spots filled / deadline passed |
| F. Unreviewed applications | Pending > 48h | `o_pending_review` (existing), fulfillment action, next step | Reviewed |
| — Invite answered | Accept / decline | **`o_invite_response`** | — |
| G. Completed event | Completion signal | `o_event_completed`, `o_publish_next`, `o_reactivation` (existing) | Next event created |

## 9. Vendor lifecycle journey map

| Journey | State signal | Message(s) | Stops when |
|---|---|---|---|
| A. Incomplete profile | First missing profile item | `v_welcome`, `v_onboarding` (existing) | Profile complete |
| B/C. Never explored / never applied | No applications | `v_onboarding` "Apply to your first event"; `v_opportunity_match`, `v_weekly_matches` (existing) | First application |
| D. Incomplete application | Open server draft | **`v_application_draft`**, next step "Finish your application" | Submitted, opportunity closed, deadline passed |
| — Invited | Open invite | `v_invited_to_apply` (existing), **`v_invite_expiring`**, next step, Invitations tab/screen | Accepted, declined, expired |
| E. Approved, unpaid | `payment_required` + payable payment | `v_payment_reminder` (existing, financial-state based) | Paid / voided |
| F. Missing documents | Verification code `missing` | `v_documents_needed` (existing) | Documents accepted |
| G. First event completed | First paid/completed booking | **`v_event_thanks:first`** | — |
| H. Inactive | Last activity 14/30/60 days, live matches exist | **`v_inactive`** with up to 3 real opportunities | Any new activity |

"Activity" = applications or drafts touched, marketplace analytics events (searches, views, saves) or clicks on LNKup messages (`LNKup_Comms_Engagement::vendor_last_activity()`).

## 10. Marketplace matching architecture

Unchanged engine (hard eligibility → weighted relevance → explainable reasons; MATCHING_DISCOVERY §2). Added:

- **Fulfillment** (`includes/matching/class-event-fulfillment.php`): per opportunity and category; "filled" = `LNKup_Opportunity_Inventory::HOLD_STATUSES` (approved, payment_required, paid, completed). A submitted application never fills a slot. Payment numbers come from application status maintained by the financial flow; no parallel payment state. Cached 5 minutes; flushed on application status/submit, opportunity/event save and invite changes. REST `GET /organizer/events/{event_id}/fulfillment` (owner, staff with `view_roster`, or manage-all).
- **Sections**: `new_nearby` (first matched in 14 days), `previously_booked` (paid/completed with this organizer), `saved` (CRM favorite/preferred + organizer lists), `recently_viewed` (last 20 profiles opened through `/crm/vendors/{id}`). Every section stays inside the event's eligible, discoverable-or-related pool and uses the public card.
- **Index**: `match_results (event_id, first_matched_at)`.
- **Nearby alerts** read cached match results only; scans run hourly/daily in Action Scheduler batches, never on page load.

## 11. Administrative capabilities (LNKup → Communications)

- **Settings:** weekly push cap, per-category caps, cross-channel cooldown hours, holdout % (max 50) and holdout trigger checklist (engagement categories only).
- **Automated flows:** new "Vendor journeys" and "Invitation reminders" flows; recruitment updates list the new triggers.
- **Templates:** Save as draft (not used for sending), Save and publish, Publish draft, version history with Restore (creates a new version), Reset to default (recorded). Unknown variables are rejected for drafts and publishes.
- **User timeline:** by user ID or email: preferences, devices, email suppressions, scheduled journeys, suppression reasons, last 50 messages with per-channel delivery and engagement. Every view is audit-logged.
- **Suppression list:** counts by reason, hashed-address search by email, removal with a mandatory note (audit-logged).
- **Analytics:** holdout comparison (messaged vs holdout outcome rate, difference, small-sample flag) and "Action completed" for the new triggers.
- **Audit log:** settings diffs (secrets excluded), flag changes (CLI), trigger pause/resume, template publish/reset, timeline views, suppression removals → marketplace Trust audit log (`entity_type = communications`).

## 12. Database changes and migrations

| Change | Where | Migration |
|---|---|---|
| `wp_lnkup_comms_template_versions` (id, template_key, fields, status, note, created_by, created_at, published_at) | lnkup-communications | `LNKUP_COMMS_DB_VERSION` 1.0.0 → 1.1.0 (`dbDelta` on boot) |
| `match_results` index `event_first_matched (event_id, first_matched_at)` | lnkup-marketplace | `LNKup_Match_Tables::create_tables()` on the next marketplace version bump |
| Event meta `_lnkup_comms_nearby_since`, `_lnkup_comms_nearby_sent_at` | events | none (written by the hourly job) |
| User meta `lnkup_recent_vendor_views` (max 20 profile IDs) | organizers | none |
| Option `lnkup_comms_pages_created` now stores a version (2) | lnkup-communications | creates `/notification-center/` once |
| Settings keys `cap_promo_push_weekly`, `cap_category_*`, `cooldown_hours`, `holdout_percent`, `holdout_triggers`, flag `flow_vendor_engagement` | `lnkup_comms_settings` | defaults merge; saved values kept |

No data is dropped or rewritten.

## 13. API changes (`lnkup/v1`)

| Route | Method | Permission |
|---|---|---|
| `/organizer/events/{event_id}/fulfillment` | GET | event owner, staff `view_roster`, manage-all |
| `/match/organizer/events/{event_id}/vendors?section=` | GET | unchanged (owner); new enum `recommended, new_nearby, previously_booked, saved, recently_viewed`; unknown → 400 |
| `/vendor/invites?scope=open|all` | GET | vendor role; own invites only; no tokens |
| `/vendor/invites/{id}/respond` | POST `{accept}` | vendor role; other vendors get 404 |
| `/comms/admin/templates/{key}/versions` | GET | comms admin |
| `/comms/admin/templates/{key}/draft` · `/publish` | POST | comms admin |
| `/comms/admin/template-versions/{id}/restore` | POST | comms admin |
| `/comms/admin/users/{id}/timeline` | GET | comms admin (audit-logged) |
| `/comms/admin/suppressions` · `/{id}` | GET · DELETE (`note` required) | comms admin |
| `/comms/admin/analytics` | GET | adds `holdouts` |
| `/comms/inbox`, `/comms/inbox/count` | GET | tabs now include `invitations`, `account` |
| `/comms/next-steps` | GET | cached; new step keys `invitations`, `draft_{id}`, `opportunities`, `slots_{event}`, `fill_{event}`; priority may be `low` |

Next.js BFF: `GET /api/notifications/inbox/count`, `POST /api/notifications/inbox/read-all`, `GET /api/vendor/invites`, `POST /api/vendor/invites/[id]/respond`, `GET /api/organizer/events/[id]/fulfillment`; `/api/match/organizer/events/[id]/vendors` forwards `section` and `per_page`.

WP-CLI: `wp lnkup-comms export-types [--file=]`.

## 14. Email and push template inventory (new)

`o_open_spots:deadline`, `o_new_vendors_nearby`, `o_invite_response:accepted`, `o_invite_response:declined`, `o_event_no_slots`, `v_invite_expiring`, `v_application_draft`, `v_inactive`, `v_event_thanks:first`; `o_open_spots` gained an optional "Still open: {{category_summary}}" paragraph. Email list items can now carry an image, a badge (e.g. Verified) and a second action link (used for vendor cards: "Invite to event"). Copy positions LNKup as vendor recruitment and booking, never ticketing; it states real counts and dates only, with no invented urgency.

## 15. Web application changes

**WordPress:** `/notification-center/` (`[lnkup_notification_center]`, `public/js/comms-center.js`): role switcher for dual-role users, tabs with unread counts, Today/Earlier grouping, mark all as read per tab, load more, loading/empty/error states, and on the vendor Invitations tab, invitation cards with Accept & apply / Decline. The sidebar "Notifications" item now opens the center (settings link inside). Organizer home: "Vendor slots to fill" card (categories, top action, three recommended vendors). Find Vendors: section select with section-specific empty states.

**Next.js:** inbox tabs with unread badges and "Mark all as read"; Invitations entry link; unread count polls every 60s while visible and on focus; `/vendor/invitations`; Find Vendors section select; organizer home "Vendor slots to fill" card; vendors page honors `?tab=`; `lib/api/comms-types.ts` + `npm run check:comms-types`.

## 16. Mobile application changes (Expo)

New `(vendor)/invitations` screen (accept → apply flow, decline with confirmation); Invitations/Account tabs and an "Open invitations" entry; 14 server types added with labels; routes for every server type with a role-based fallback (inbox taps keep the "no longer available" toast); app badge refreshed at launch and when the app returns to the foreground; Find Vendors section chips; `FillSlotsCard` on organizer home and event overview; `lib/api/vendor-invites.ts`, fulfillment client in `lib/api/match.ts`; `__tests__/notification-type-drift.test.ts` against `__tests__/fixtures/comms-types.json`.

## 17. Security and privacy assessment

- Organizer emails and cards use `public_vendor_card()` only (name, logo, category, city/state, verified, completed events, reasons). Tests assert no email or phone appears. Non-discoverable vendors stay hidden unless already related.
- Vendor invite endpoints never return the invite token; responses go by ID with an ownership check and return 404 for other users.
- Fulfillment is owner/staff only (other organizers and vendors get 403).
- Admin timeline/suppressions are admin-only; the suppression list stores SHA-256 hashes and never shows raw addresses; webhook secret never reaches the audit log.
- Holdouts never apply to critical/high, payment, document, application or operations messages.
- No new Stripe calls; payment-related copy reads canonical application/payment state.
- All new REST routes have `permission_callback`; inputs sanitized; template variables validated before save or publish.

## 18. Performance assessment

- No page-load marketplace scans. Fulfillment is one grouped query per event plus 5-minute cache; next steps cached 5 minutes per user; `related_vendor_users()` cached 10 minutes.
- Organizer home dropped N×M per-slot `COUNT` queries in favor of cached fulfillment.
- Hourly nearby job: ≤500 events, each one indexed count query (new `(event_id, first_matched_at)` index); cards fetched only when the threshold is met.
- Daily jobs are bounded: invites 500, drafts 300, events 300; inactivity pages 200 vendors per Action Scheduler job.

## 19. Automated test results (local wp-env, Nov 23, 2026)

| Suite | Result |
|---|---|
| `comms-policy-test.php` (new) | 23/23 |
| `comms-engagement-test.php` (new) | 42/42 |
| `comms-admin-test.php` (new) | 34/34 |
| `comms-admin-render-test.php` (new) | 10/10 |
| `invite-expiry-test.php` (new) | 23/23 |
| `event-fulfillment-test.php` (new) | 27/27 |
| `matching-test.php` (+8 Find Vendors section checks) | all passed |
| `matching-alerts-test.php` | all passed |
| comms engine, lifecycle, preferences, push, organizer, security, activation, partners, schema, lint | all passed during this pass (see §20 for the final rerun) |
| Playwright `engagement.spec.ts` (new) + `communications.spec.ts` | 18/18 (chromium) |
| Expo Jest (27 suites incl. new `engagement`, `notification-type-drift`) | 210/210; `tsc --noEmit` clean |
| Next.js `npm run check:comms-types` | 82 types, 8 tabs in sync; `tsc` clean for every changed file (pre-existing errors elsewhere unchanged; the project has no ESLint config) |

### 35-scenario matrix

Evidence: **A** = automated in this repo; **E** = covered by an existing suite; **P** = rule/code in place, not separately automated; **O** = needs real-device or production verification.

| # | Scenario | Evidence |
|---|---|---|
| 1 | Organizer registers, no event | E (onboarding suites) |
| 2 | Organizer creates event before reminder | P (`organizer_onboarding_step` validity) |
| 3 | Publish event with open slots | A (fulfillment, recruit prompt) |
| 4 | Relevant vendor registers near event | A (`o_new_vendors_nearby`) |
| 5 | Irrelevant vendor registers | A (matching-test wrong category; no-match reactivation) |
| 6 | Organizer receives recommendations | A (nearby alert, sections, home card) |
| 7 | Organizer invites vendor | E (batch invite) |
| 8 | Vendor receives and opens invitation | A (list, accept/decline, REST, UI tab) · O (push on device) |
| 9 | Vendor registers, incomplete profile | E |
| 10 | Vendor completes profile before reminder | P (`vendor_onboarding_step`) |
| 11 | Vendor receives relevant opportunity | E (matching-alerts) |
| 12 | Vendor saves opportunity | E (`v_saved_deadline`) |
| 13 | Vendor starts and abandons application | A (draft reminder) |
| 14 | Vendor completes application before reminder | A (cancelled on submit) |
| 15 | Approval notification | A (high priority delivered through cooldown) |
| 16 | Payment completed before reminder | E (reminder cancelled on paid) |
| 17 | Event reaches full capacity | A (fulfillment "full", open-spot validity) |
| 18 | Application deadline expires | A (expired excluded; draft suppressed on close) |
| 19 | Event cancelled | E (critical cancellation) |
| 20 | Organizer completes event | E |
| 21 | Vendor completes event | A (`v_event_thanks:first`) |
| 22 | User disables engagement emails | E (preferences, weekly skip) |
| 23 | User disables push | E (`preference_off`) |
| 24 | Multiple mobile devices | E (push multi-device) · O |
| 25 | Push token becomes invalid | E (DeviceNotRegistered) · O |
| 26 | Email temporarily fails | P (same retry path as push outage, which is automated) · O |
| 27 | Duplicate jobs for one notification | A (double invite hook → one message; engine dedupe) |
| 28 | Queued recommendation becomes stale | A (reactivation, slot-less, draft, expiring invite suppressed) |
| 29 | Tap from terminated app | E (Jest pending destination) · O |
| 30 | Accessing another account's object | A (invites 404, fulfillment 403, timeline 403, candidates 403) |
| 31 | Multiple events or a tour | A (fulfillment per event) · E (tour matching) |
| 32 | Vendor serves multiple markets | E (matching-test preferred cities) |
| 33 | Vendor with organizer and vendor roles | P (role switcher in WP center, role param on inbox/count) |
| 34 | No relevant opportunities | A (no reactivation without matches; empty states) |
| 35 | Existing automation overlaps new one | A (cooldown + holdout policy); nearby vs weekly count use separate markers |

No delivery was attempted to real users. All sends in tests are captured (`pre_wp_mail`) or stubbed (Expo).

## 20. Remaining production verification

- **Local environment at hand-off:** another session's uncommitted Meta-pixel work in this working tree lists `includes/meta/class-meta-dataset-quality.php`, `includes/meta/class-meta-alerts.php` and `admin/class-meta-admin.php`, which do not exist yet. `lnkup_require()` then stops the marketplace plugin from booting, so a final rerun of every suite in this tree fails at boot (routes and lifecycle hooks missing). All suites passed earlier in this pass before that work appeared; rerun `npm run test:comms:php` and the matching suites once those files exist.
- Real-device push on iOS and Android (Android needs FCM V1 credentials and `google-services.json`).
- Production email: SPF, DKIM and DMARC alignment; provider bounce webhook; a dedicated email provider before promotional volume (SiteGround ~400–800/hour).
- Flag-by-flag enablement with checkpoints (§21).
- Expo typed routes regenerate on the next `expo start`; `/(vendor)/invitations` is cast until then.

## 21. Deployment

1. Deploy **lnkup-communications 1.3.0** (creates `template_versions`, the `/notification-center/` page; all new flows off).
2. Deploy **lnkup-marketplace** with these changes in the next version (the version bump runs `lnkup_maybe_upgrade_db()`, which adds the match index). The working tree's 12.35.0 bump belongs to the concurrent Meta work; coordinate one version for the release.
3. Theme unchanged.
4. Confirm the real cron runner (COMMUNICATIONS_ROLLOUT Step 3) so `lnkup_comms_hourly/daily` and `lnkup_vendor_invites_expire` run.
5. Review Settings: caps (new defaults apply only where nothing was saved), cooldown, holdout (leave 0 until volume allows).
6. Enable one at a time, 24–48h apart, watching Delivery logs and System health: `flow_recruitment_updates` → `flow_activation` → `flow_discovery` (invite expiry reminders) → `flow_vendor_engagement` → holdouts.
7. Ship Next.js and Expo builds; new screens hide or show empty states when flags are off.

## 22. Rollback

- Turn the flag off (`wp lnkup-comms flag <flag> --off --cancel-queued`); queued messages are suppressed as `flag_off:<flag>`.
- Caps/cooldown/holdout: set back in Settings (cooldown 0, holdout 0).
- Template edits: Restore an earlier version or Reset to default.
- Deactivating lnkup-communications returns all messaging to lnkup-marketplace.
- Derived data (`template_versions`, match index, nearby meta, recently-viewed meta) can be dropped safely; nothing canonical depends on it.

## 23. Modified files

**lnkup-communications:** `lnkup-communications.php`, `includes/class-comms-{settings,frequency,engine,registry,context,templates,engagement (new),admin-ops (new),next-steps,analytics,cli,schema}.php`, `includes/automations/class-comms-{automations,auto-matching,auto-engagement (new)}.php`, `includes/listeners/class-comms-listen-operations.php`, `includes/rest/class-comms-{rest,admin-rest}.php`, `includes/templates/catalog.php`, `templates/email/layout.php`, `admin/class-comms-admin.php`, `admin/css/comms-admin.css`, `public/class-comms-shortcodes.php`, `public/js/comms-center.js` (new), `public/css/comms.css`.

**lnkup-marketplace:** `lnkup-marketplace.php` (loader/init lines only), `includes/class-vendor-invites.php`, `includes/class-organizer-crm-rest.php`, `includes/class-organizer-overview.php`, `includes/matching/class-{event-fulfillment (new),match-service,match-rest,match-tables}.php`, `public/class-organizer-dashboard-views.php`, `public/js/find-vendors.js`, `public/css/organizer-crm.css`.

**Expo:** `app/(vendor)/{invitations (new),_layout,passport}.tsx`, `app/(organizer)/{index,vendors/find,events/[eventId]}.tsx`, `components/{fill-slots-card (new),notifications-screen,push-bootstrap.native}.tsx`, `lib/api/{match,comms,vendor-invites (new)}.ts`, `lib/notifications/types.ts`, `lib/push/notification-routing.ts`, `__tests__/{engagement,notification-type-drift (new),notification-routing,comms-inbox}.test.ts`, `__tests__/fixtures/comms-types.json`.

**Next.js:** `app/api/notifications/inbox/{route,count/route (new),read-all/route (new)}.ts`, `app/api/vendor/invites/{route,[id]/respond/route}.ts` (new), `app/api/organizer/events/[id]/fulfillment/route.ts` (new), `app/api/match/organizer/events/[id]/vendors/route.ts`, `app/vendor/invitations/page.tsx` (new), `app/organizer/{page,vendors/page}.tsx`, `components/notifications/comms-inbox-section.tsx`, `components/organizer/{fill-slots-card (new),find-vendors-panel}.tsx`, `lib/api/{comms,comms-types (new),comms-types.json (new),match,notifications,vendor-invites (new)}.ts`, `lib/hooks/{use-comms-inbox,use-notification-count,use-match}.ts`, `scripts/check-comms-types.mjs` (new), `package.json`.

**Tests / scripts / docs:** `scripts/e2e/php/{comms-policy,comms-engagement,comms-admin,comms-admin-render,invite-expiry,event-fulfillment}-test.php` (new), `_comms_helpers.php`, `matching-test.php`, `run-comms-php.ps1`, `tests/e2e/{engagement (new),communications}.spec.ts`, this document, COMMUNICATIONS_MATRIX, MATCHING_DISCOVERY, CURRENT_STATE.

## 24. Commit status

Committed in focused commits on the current branch (`aaa6e7e` pre-existing 12.34 work, then `d5ecd6e`, `f33f317`, `9e67c3b`, `d66bf20`, `a80c608`, `7343a51`, and the final docs/version commit). Not committed by this work: the concurrent session's Meta-pixel changes (`includes/meta/`, `class-auth-rest.php`, `class-financial-service.php`, `class-registration.php`, `marketplace-v2.js`, the 12.35.0 bump and loader lines in `lnkup-marketplace.php`, etc.), duplicate `.zip` artifacts, `docs/audits/` and `test-results/`.

## 25. Remaining enhancement opportunities

- Per-category caps and the cooldown window as per-category overrides (currently one value each).
- Email open tracking is intentionally absent; clicks, inbox reads and push taps are the engagement signals.
- Incremental impact needs volume: enable holdouts (10–20%) on `v_inactive`, `o_new_vendors_nearby` and `v_opportunity_match` once weekly sends exceed a few hundred per arm.
- Organizer one-to-one messaging (only event broadcasts exist).
- Vendor "review this organizer" prompt after events.
- Vendor availability is still months/blackouts only; no calendar sync.
- Preferred cities and travel radius live in matching preferences, not notification settings; link them from the settings page.
- Web push (browser) is not implemented; the web relies on inbox, email and unread polling.

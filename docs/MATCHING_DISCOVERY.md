# Matching & Discovery OS (v12.24.0, Phases 1–4)

Rules-based, explainable matching between vendors and open vendor opportunities, built on canonical WordPress data (CPTs + post meta). Every table added here is a derived index or cache and can be rebuilt.

All features ship **off**. Turn them on in wp-admin → LNKup → Marketplace Intelligence → Matching Rules:

| Flag | Enables |
|---|---|
| `matching_enabled` | Master switch (engine, cron recompute, legacy recommendation delegation) |
| `matching_vendor_discovery_enabled` | Vendor recommendation sections (WordPress Find Events, Next.js `/vendor/find`, Expo Explore) |
| `matching_organizer_discovery_enabled` | Organizer Find Vendors, recruitment prompt/assistant, batch invites |
| `matching_tours_enabled` | Tours & series (organizer manager, tour panel, tour sections) |

Privacy fixes (§5) are always on.

## 1. Data model

**New vendor profile meta** (`lnkup_vendor_profile`): `_lnkup_zip`, `_lnkup_geo_lat` / `_lnkup_geo_lng` (city centroid, 2 decimals, never returned to organizers), `_lnkup_primary_category`, `_lnkup_willing_to_travel_national`, `_lnkup_preferred_event_types`, `_lnkup_preferred_markets`, `_lnkup_available_months`, `_lnkup_blackout_dates`, `_lnkup_discoverable` and `_lnkup_accept_invites` (stored `'1'`/`'0'`; missing = yes). Existing fields now used for matching: `_lnkup_cities_served`, `_lnkup_states_served`, `_lnkup_service_radius_miles`, `_lnkup_preferred_fee_max`, `_lnkup_indoor_outdoor_preference`, power/water/food-truck setup fields.

**New event meta:** `_lnkup_tour_id`, `_lnkup_tour_stop_order`, `_lnkup_recruit_prompt` (set when an event is first published).

**New CPT** `lnkup_tour` (meta `_lnkup_organizer_user_id`, `_lnkup_tour_type` = `tour|series`). Stops are existing events; inventory, applications and payments stay per event/opportunity.

**New tables** (`LNKup_Match_Tables`, created by `lnkup_maybe_upgrade_db()`):

| Table | Purpose |
|---|---|
| `lnkup_match_vendor_index` | One row per vendor profile: status, discoverable, accept_invites, city/state/zip, centroid, radius, served markets, preferences, completeness |
| `lnkup_match_vendor_categories` / `lnkup_match_opportunity_categories` | Normalized `(object, term_id, group_key)` joins for indexed category matching |
| `lnkup_match_results` | Cached pair results: score, confidence, recommended flag, suppression reasons, factor breakdown, reasons |
| `lnkup_match_dirty` | Incremental recompute queue |
| `lnkup_recruitment_pipeline` | Organizer × event × vendor stage (`identified → shortlisted → invited → opened → applied → approved → paid`, or `declined`) |
| `lnkup_recruitment_jobs` | Batch invite jobs with counts and suppression reasons |

`lnkup_marketplace_index` gains `tour_id` and a `(lat, lng)` key.

## 2. Matching logic (`LNKup_Match_Engine`, pure functions, `tests/php/match-engine-test.php`)

**Hard eligibility** — any failure excludes the pair; it is never scored:

1. Opportunity open, event published.
2. Event date ≥ today; application deadline ≥ today.
3. `spots_remaining > 0`.
4. Invite-only opportunities require an open invite (vendor side).
5. Vendor account active (vendor role, no deletion request).
6. If the opportunity lists categories, the vendor must share a category or a category group. Opportunities without categories are open to all. Vendors without categories are not blocked (factor has no data).
7. No existing application by the vendor for the opportunity.
8. Organizer side: vendor discoverable, or already related to that organizer (applied to their event / accepted their invite).

**Weighted relevance** (defaults, admin-configurable): category 30, geography 25, requirements 20, preferences 10, dates 10, signals 5.

| Factor | Scoring |
|---|---|
| Category | primary 1.0 · other exact 0.85 · same group 0.6 · open-to-all 0.7 |
| Geography | selected city / home city 1.0 · within radius 0.9→0.5 linear (default radius 50 mi) · served state 0.6 · national travel 0.4 · otherwise 0 |
| Requirements | share of required documents already approved; power, water, food-truck compatibility when the vendor needs them |
| Preferences | fee ≤ max budget; indoor/outdoor; preferred event types |
| Dates | available months; blackout dates; existing paid booking that day |
| Signals | paid booking with this organizer 1.0 · follows organizer 0.8 · saved the opportunity 0.6 |

**Cold start:** a factor without data is dropped and the remaining weights are rescaled. `confidence` = share of weight with data.

**Recommended only when** eligible **and** score ≥ `match_min_score` (40) **and** confidence ≥ 40% **and** geography is not a hard 0 (`outside_market`). Suppression reasons are stored for diagnostics.

**Fairness:** trending, application counts, organizer size, payment volume and organizer identity are never scored. Ordering: score, then soonest date, then trending only as a final tiebreaker. Organizer candidate ties rotate deterministically per event (`CRC32(profile_id-event_id)`).

**Explanations:** every reason comes from a factor that fired (e.g. "In Charlotte, one of your selected markets"). Vendors see a band (Strong / Good / Possible), never the raw score. Organizers see organizer-phrased reasons without private data.

## 3. Freshness and jobs (`LNKup_Match_Cron`)

- Profile save, preference save, category change, event/opportunity save, index removal and application submit/transition mark entities dirty.
- `lnkup_match_process_queue` (single event): 25 vendors + 5 opportunities per run, 20 s budget, reschedules until empty; also geocodes up to 10 vendor city centroids (Nominatim, cached; never on page load).
- `lnkup_match_backfill`: pages through all vendor profiles (200/run), then queues every open opportunity. Queued on upgrade and from the admin "Rebuild" button.
- `lnkup_match_daily` (04:30): prunes results for closed opportunities, requeues open opportunities.
- A vendor with no computed results gets one bounded computation (500 candidates) on first visit.
- **Action-time revalidation:** every recommendation page, candidate page, invite preview and invite send re-checks eligibility on live data; `LNKup_Opportunity_Inventory` remains the final spot check on approval.

## 4. REST API (`lnkup/v1`)

| Route | Method | Permission | Notes |
|---|---|---|---|
| `/match/vendor/recommendations?section=` | GET | vendor role | `recommended, preferred_markets, new, deadlines, previous_organizers, tours, series`; `html=1` adds rendered cards |
| `/match/vendor/opportunities/{id}/explain` | GET | vendor role | band, reasons, friendly block messages; legacy `/growth/match` shape |
| `/match/vendor/preferences` | GET/POST | vendor role + owns profile | partial updates; returns completeness + option lists |
| `/match/organizer/events/{event_id}/vendors` | GET | organizer + owns event | public vendor cards, reasons, pipeline stage; filters: opportunity, category, city, state, verified, radius, exclude_applied, stage |
| `/match/organizer/events/{event_id}/recruitment` | GET | organizer + owns event | readiness checklist, deterministic suggestions, pipeline counts |
| `/match/organizer/events/{event_id}/recruitment/dismiss-prompt` | POST | organizer + owns event | hides the "published" prompt |
| `/match/organizer/recruitment/shortlist` | POST | organizer + owns `event_id` in body | add/remove; optional list mirror |
| `/match/organizer/invites/preview` | POST | organizer + owns event | recipients, suppressed (with labels), template preview, limits |
| `/match/organizer/invites/batch` | POST | organizer + owns event | queues a job; sends revalidate each recipient |
| `/match/organizer/invites/jobs/{id}` | GET | job owner | progress + suppression counts |
| `/tours` | GET/POST | organizer | list mine / create |
| `/tours/{id}` | GET | public (published only; owner sees drafts) | per-stop availability, fee range, `matches_me` |
| `/tours/{id}` | PATCH | tour owner | PATCH semantics |
| `/admin/match/diagnose` | GET | manage-all / `lnkup_manage_settings` | "Why was this vendor matched?" |
| `/admin/match/health` | GET | manage-all / `lnkup_manage_settings` | queue, counts, last runs |

Flags off → routes return `{ enabled: false, items: [] }` (writes return 403 `lnkup_feature_disabled`), never 500.

`/marketplace/search` gains `tour_id`, `organizer_id`, `preferred_markets`, `previous_organizers`, real radius filtering before `LIMIT`, Haversine `closest` sort, FULLTEXT search (falls back to `LIKE` for short words/zero hits), and `sort=recommended` using cached matches.

## 5. Privacy and abuse controls

- `LNKup_Capabilities::organizer_can_view_vendor_contact()`: email, phone, owner name and compliance documents are only returned when the vendor applied to one of the organizer's events or accepted their invite (or manage-all). Favoriting/listing/inviting does not unlock them.
- Organizer candidate cards (`LNKup_Match_Service::public_vendor_card()`) never include contact, documents, applications, payments or precise location.
- Vendors who opt out of discovery are hidden from Find Vendors, the CRM Discover scope, the public directory, list adds and the CRM profile route for unrelated organizers.
- `contact_rows_for_platform()` is admin-only; `LNKup_Vendor_Lists::add_vendor()` validates the profile; `/intelligence/vendors/{id}/reliability` requires a relationship.
- Invites to vendors without a relationship respect `_lnkup_accept_invites`, a per-vendor weekly cap (5) across all organizers and a per-organizer daily cap (100); batches max 50. Applied to both batch jobs and the existing `/crm/invites` route.

## 6. Surfaces

- **WordPress:** Find Events explore tabs (Recommended for You, Near Your Cities, New, Deadlines, Previously Worked With, Tours, Recurring Markets) with band chips and first reason; `?tour_id=` tour panel; Vendor Profile → Matching Preferences (progressive, completeness meter, discovery/invite toggles); Organizer Vendors → Find Vendors (assistant, select, shortlist, preview, send); Organizer Home → "{Event} is published" prompt; Organizer Events → Tours & series manager.
- **Next.js:** `lib/api/match.ts`, BFF `app/api/match/**`, `app/api/tours/[id]`; `/vendor/find` match rails; `/organizer/vendors` Find Vendors tab; `/vendor/tours/[id]`.
- **Expo:** `lib/api/match.ts`; Explore match rails; Profile → Matching preferences; `(organizer)/vendors/find`; `tour/[tourId]`. No client-side scoring.
- **Admin:** LNKup → Marketplace Intelligence (Overview, Matching Rules + change history, Category Groups, Data Quality, Why Matched?, Automation Health, Supply & Demand placeholder).

## 7. Tests

| Suite | Command | Result (Nov 22, 2026) |
|---|---|---|
| Engine unit tests | `php tests/php/match-engine-test.php` (or copy into the wp-env cli container) | 39/39 |
| WordPress integration | `npx -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/matching-test.php` | 48/48 |
| Playwright | `npm run e2e:run -- tests/e2e/matching.spec.ts --project=chromium` | 13/13 |
| Expo Jest | `cd lnk-up-marketplace-mobile && npx jest __tests__/match.test.ts` | 4/4 |
| Matching alerts (Phase 6) | `npx -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/matching-alerts-test.php` | 36/36 |
| Comms regression | `comms-{schema,engine,lifecycle,preferences,push,organizer,security}-test.php` | all pass |
| Expo routing Jest | `npx jest __tests__/notification-routing.test.ts __tests__/pending-destination.test.ts` | 49/49 |
| Typecheck | `npx tsc --noEmit` (mobile clean; Next.js has only pre-existing errors outside matching files) | — |

## 8. Deployment and rollback

1. Deploy plugin 12.24.0 (migration creates tables, seeds category groups after taxonomies register, queues the backfill).
2. Confirm wp-cron runs (`lnkup_match_backfill`, `lnkup_match_process_queue`).
3. Review Category Groups and Data Quality; then enable `matching_enabled`, then the sub-flags one at a time.
4. Ship Next.js and Expo builds (new screens hide themselves when flags are off).

**Rollback:** turn flags off (legacy `LNKup_Recommendations` / `LNKup_Growth_Match` behavior returns). Derived tables can be dropped safely. The privacy fixes should stay.

## 9. Phase 6: matching alerts (plugin 12.27.0, lnkup-communications 1.1.0)

All alerts are sent by **lnkup-communications**, which owns queueing, dedupe, preferences, consent, one-click unsubscribe, quiet hours, caps and history. The marketplace plugin only answers "is this still a match?". Every message is re-checked at send time, so nothing goes out after the vendor applies, the opportunity fills or closes, or the vendor stops matching.

| Trigger | Who | When | Flag | Send-time check |
|---|---|---|---|---|
| `v_opportunity_match` | Vendor | New opportunity the engine recommends (cached `eligible = 1`) | `flow_discovery` | `match_still_recommended` |
| `v_organizer_new_opportunity` | Vendor | Organizer they worked with posts again | `flow_discovery` | `opportunity_still_eligible` |
| `v_tour_match` | Vendor | Tour published; **one** message listing only the stops they match | `flow_discovery_tours` | `tour_has_matching_stop` |
| `v_weekly_matches` | Vendor | Monday top 5, only for vendors who got no opportunity message that week | `flow_discovery_weekly` | — (digest) |
| `v_saved_search_match` / `v_saved_deadline` / `v_invited_to_apply` | Vendor | Delegated from core `LNKup_Notifications` | `flow_discovery` | saved/invite still open |
| `o_open_spots` | Organizer | 14 and 7 days out while spots remain | `flow_recruitment_updates` | `event_has_open_spots` |
| `o_new_matching_vendors` | Organizer | Weekly count of newly matching, discoverable vendors | `flow_recruitment_updates` | `event_has_new_matches` |

- **Data:** `wp_lnkup_match_results.first_matched_at` records when a pair first became recommended (never overwritten), which drives the organizer "new matching vendors" count.
- **Helpers (`LNKup_Match_Service`):** `is_recommended()`, `is_eligible()`, `alert_reasons()`, `discoverable_match_count()`, `tour_matches_for_vendor()`.
- **Tours:** stops of a published tour are skipped by the per-opportunity fan-out; `LNKup_Comms_Auto_Matching::process_tour()` pages vendors 200 at a time and remembers announced stops in user meta, so a later stop triggers at most one follow-up.
- **Messaging rules:** opportunity alerts are promotional (2 push/day, 3 emails/week, default weekly email mode); the email explains "Why it matches" from the engine reasons. Opted-out (non-discoverable) vendors still get vendor-side alerts; organizers only see counts of discoverable vendors.
- **Saved searches:** the scan now pages `lnkup_vendor` users that have `lnkup_saved_searches` meta (200 per Action Scheduler batch) instead of loading every vendor.
- **Mobile:** push data carries `tourId`; `discovery_tour_match` opens `/tour/{id}`, `organizer_open_spots` opens Find Vendors for the event. Category settings already cover opportunities (email mode + push).
- **Measurement (observed, not causal):** when a vendor applies within 14 days of opening or clicking a discovery alert for that opportunity, event or tour, `match_alert_application` is tracked. Marketplace Intelligence → Overview shows per-trigger created, sent, suppressed, opened, clicked and applied-after counts for 30 days.
- **Rollout:** enable `matching_enabled`, then in Communications turn on `flow_discovery`, `flow_discovery_tours`, `flow_discovery_weekly` and `flow_recruitment_updates` one at a time. Turning a flag off stops new sends; queued messages fail their flag check and are dropped.

## 10. Not built in this pass

- Phase 5: multi-stop application flow, application drafts/prefill, re-apply after withdrawal.
- Phase 7: geographic supply & demand, Organizer Acquisition OS feed (that OS does not exist yet).
- Phase 8–9: tuning and staged rollout.
- `/marketplace/track/event` still accepts any event type (multiple clients send free-form names).
- Vendor centroids depend on Nominatim; profiles whose city cannot be located score geography by city/state strings only.

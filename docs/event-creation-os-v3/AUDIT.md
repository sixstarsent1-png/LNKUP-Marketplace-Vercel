# Event Creation OS 3.0 — Audit (Phase 0)

Audited 2026-11-24 against plugin 12.38.0 (commit `291d80a`, Organizer Experience OS 2.0), lnkup-communications 1.4.0, theme 1.8.0, Expo SDK 57 / expo-router 57 / React Native 0.86.2.

## Git state at start

- HEAD `291d80a` (Organizer OS 2.0). 93 uncommitted paths belong to other sessions (vendor/mobile v2, location, event groups, zips).
- Creation-relevant files with **uncommitted foreign edits**: `public/class-organizer-dashboard-views.php`, `public/class-shortcodes.php`, `public/css/organizer-os.css`, `public/js/organizer-os.js`. This work adds new files and touches those only with single-line integration hooks staged by hunk.
- Committed and clean: `class-organizer-onboarding.php`, `class-organizer-launch-rest.php`, `includes/location/*`, `includes/portfolio/*`, `includes/external-sources/*`, `helpers.php`.

## Creation and editing route inventory

| Entry | Route / handler | Backend | Notes |
|---|---|---|---|
| Create event (web) | `?view=create` → `LNKup_Organizer_Dashboard_Views::render_create()` → `LNKup_Shortcodes::render_create_panel()` | AJAX `lnkup_create_event` → `LNKup_Ajax::handle_create_event` → `LNKup_Organizer_Onboarding::create_event_from_request` + `create_opportunities_for_event` | 4-step legacy wizard (Basics, Date & location, Vendors, Review), `marketplace.js`, `organizer-create.js`, `location-field.js`; not on the OS 2.0 design system |
| Import from ticketing URL | Collapsed panel inside create | `POST /external-events/organizer/import-url` (preview only, 3 s rate limit) | Fills the legacy form |
| Add to existing event | Create tab | AJAX `lnkup_create_opportunity` | |
| Launch (REST, app + Next.js) | `POST /organizer/launch/publish` (`save_mode=draft` supported, may return `partial`) | same service | Used by Expo `launch.tsx` and Next.js `/organizer/launch` |
| Edit event | `?view=events&event=ID&tab=edit` (`render_event_edit_view`) | AJAX `lnkup_update_event` / `PATCH /organizer/events/{id}` → `update_event_from_request` (logistics change detection + vendor notification) | |
| Opportunity pricing/status | `POST /organizer/opportunities/{id}/pricing|status` | `update_opportunity_pricing`, `set_opportunity_listing_status` | |
| Duplicate | Duplicate dialog (`organizer-os.js`) / `POST /organizer/events/{id}/duplicate` | `duplicate_event` (requires date + city/state or keep_location, client token; copies slots and questions; never applications/payments) | Creates a live event immediately |
| Saved setups | `GET|POST /organizer/setups` | `LNKup_Organizer_Setups` (user meta, max 20) | Applied via `?setup=` |
| Tours, series, recurring (flag `portfolio`) | `?view=tours`, `/portfolio/tours/*` | `LNKup_Portfolio_Tours`, `_Recurrence` (52 cap, timezone-aware, `o{Ymd}` keys), `_Config` (tour defaults + stop overrides), `bulk/preview|apply`, `attach`, `save-as-template` | Stops are `post_status=draft` events |
| Templates | `/portfolio/templates` | `LNKup_Portfolio_Templates` (CPT `lnkup_event_template`, versioned, 50/organizer) | |
| Brands | `/portfolio/brands` | `LNKup_Portfolio_Brands` (CPT `lnkup_brand`) | |
| Onboarding | `GET|PATCH /organizer/onboarding` | `LNKup_Organizer_Onboarding_Flow` (formats single/recurring/series/tour/company) | |
| Mobile create | `app/(organizer)/launch.tsx` (6 steps) | `/organizer/launch/publish`, AsyncStorage draft | |
| Mobile duplicate | `components/organizer/duplicate-event-sheet.tsx` | `/organizer/events/{id}/duplicate` | |
| Mobile tours | `app/(organizer)/tours.tsx` | `/portfolio/*` | |
| External claim | `/external-events/{id}/claim` | `LNKup_External_Events` | 30-day claim tokens |

## Findings

| # | Area | Finding | Severity | Action (phase) |
|---|---|---|---|---|
| F1 | Security | `POST /external-events/organizer/import-url` falls through to `LNKup_Page_Extractor::fetch_html()` (plain `wp_remote_get`, 3 redirects, no private-IP block) for any http(s) URL → **SSRF** | High | `LNKup_Safe_Fetch`, switch the extractor (6) |
| F2 | Drafts | "Draft" = published `lnkup_event` with `_lnkup_event_status=draft` and no slots; no autosave; mobile drafts only on-device | High (UX, data) | Server builder drafts with revision + autosave (1, 2, 13) |
| F3 | UI | Create view uses the legacy wizard markup, not `LNKup_OS_UI`; dense two-column form, no preview, no inventory summary | Medium | Builder on OS 2.0 (2–5) |
| F4 | Duplicate | Duplicate creates a live event immediately; copies `_lnkup_external_source_url`-adjacent meta; no component choice | Medium | Duplicate → builder draft with mandatory review (11) |
| F5 | Fees | No organizer-facing fee preview route; form shows commission % only | Medium | `builder/fee-preview` via `LNKup_Fee_Engine::calculate` (4) |
| F6 | Publish gate | Publishing is not gated on Stripe Connect; free events legitimately publish without Connect | Info | Checklist: Connect required only for paid options, with handoff (4, 5) |
| F7 | Instant booking | `_lnkup_instant_approval` meta exists but has thin enforcement | Info | Builder exposes review / invite-only / closed; instant shown only where supported (4) |
| F8 | Templates | Two template systems: user-meta setups and versioned template CPT | Medium | One library UI, setups shown with convert (11) |
| F9 | Titles | No metadata concatenation on save; imported titles cleaned at display by `lnkup_display_event_title` | Low | Normalize at import time (6) |
| F10 | Location | `LNKup_Location_Service` validates city/state (strict for REST); venues are free text | Info | Builder uses strict mode + conflict detection (1) |
| F11 | Media | No event upload endpoint; `lnkup_image_upload_field` + `LNKup_Event_Media::resolve` | Info | Builder upload via WP media (`media_handle_upload`) (2) |
| F12 | Sources | One `_lnkup_external_source_url` / `_lnkup_ticketing_url` per event | Medium | `lnkup_event_sources` table, multiple sources (6) |

## Journey traces (current)

- **First event (web):** 4 steps, all fields on one wide form; publish creates event + slots; no preview, no fee breakdown, success returns to the event page.
- **URL import:** paste in a collapsed panel → preview → fills form; any host reachable (F1).
- **Duplicate:** dialog asks name/date/location, creates live event.
- **Tour:** Tours & Series workspace (flag) — separate UI from create.

## Tests before this work

`tests/e2e/organizer-event-create.spec.ts`, `organizer-launch-checklist.spec.ts`, `portfolio-os.spec.ts`, `organizer-os.spec.ts`; PHP `portfolio-os-test.php`, `organizer-os-test.php`. Before screenshots: `screenshots/before/` (create, events, tours, start at 1440 and 390; no page overflow).

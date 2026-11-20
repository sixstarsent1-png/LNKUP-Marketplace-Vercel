# ORG-00 through ORG-04 baseline

Recorded 2026-09-29 from the code in this repo. WordPress at `http://localhost:8888` was not running, so browser fixtures were not captured and no payment or invite was sent.

The cross-event list **Vendor slots across events** is already in plugin **12.18.55**. This note does not add an editor, a bulk commit, or a Financial Service change.

## ORG-00 — Capability inventory

### Versions

| Item | Code | Docs before this note |
| --- | --- | --- |
| Plugin | `12.18.55` in `lnkup-marketplace.php` (`LNKUP_VERSION`) | `12.18.55` in `CURRENT_STATE.md` |
| Theme | `1.4.22` in `wp-content/themes/lnkup-theme/functions.php` (`LNKUP_THEME_VERSION`) and `style.css` | `1.4.19` in `CURRENT_STATE.md` |
| Playwright | `playwright.config.ts` base URL `http://localhost:8888` | Same |

`CURRENT_STATE.md` still pointed the roadmap at “Next organizer flow: Organizer event command” and “Next app: Phase 22”. The organizer event command shipped in 12.18.54. Phase 22–26 feature docs are marked built; the Phase 22 gate on this machine was already No-Go. This note is the organizer baseline. It does not change Fee Engine rates or the lime theme. Older PDF copy that mentions a $19.99 success fee or an orange accent is not production settings.

### Status

**Verified in code**

- Live vs Draft comes from an open vendor slot (`LNKup_Organizer_Onboarding::sync_event_listing_status()`).
- Edit event saves listing, venue, photos, indoor/outdoor, and amenity flags.
- Each event card has a vendor-slot panel: rename, price, spots, close, reopen, remove, and add.
- Public event page is `/opportunities/?event={id}`. Organizer public profile is `/organizers/?organizer={slug}`.
- Duplicate asks for city, state, venue, and address, then copies slots onto new opportunity IDs. Applications and payments are not copied.
- Events renders `render_portfolio_vendor_slots()` above the event cards. The filter is `#lnkup-portfolio-slot-filter` in `marketplace.js`.

**Shipped but not browser-checked**

- The portfolio list, the per-event slot panel, edit event, public page, profile, and duplicate. Nothing was listening on port 8888 when this note was written (`http://localhost:8888` timed out). Save on a slot row now looks up `[data-slot-row]` or `tr[data-opportunity-id]` so the button is not treated as the row. That path has not been clicked.

**Not built**

- Bulk preview and idempotent commit (ORG-13 / ORG-14).
- Request pagination. The organizer dashboard loads applications and the card opportunity query with `posts_per_page` 50 in `render_organizer_dashboard()`. The portfolio list queries the organizer’s slots with `posts_per_page` -1, so it is not the same cap.
- Discover vendors and Vendor Lists are sidebar tier `secondary` in `wp-content/themes/lnkup-theme/inc/dashboard.php`.
- List summaries before a list is opened.
- Organizer profile logo, city, social links, and an editable public slug.
- Next.js and Expo copies of this WordPress organizer flow.

## ORG-01 — Isolated browser test

Not reached.

What is missing on this machine:

- Nothing is listening on `http://localhost:8888`.
- No Local, Laragon, or XAMPP install under the usual paths.
- `php`, `wp`, and `docker` are not on `PATH`. A Docker config folder exists at `%USERPROFILE%\.docker`, and Docker Desktop is not running a site.
- This repo does not contain a WordPress core or a compose file for port 8888. Starting a new stack was out of scope.

No test organizer or vendor was signed in. No Stripe charge and no invite were sent. Stripe mode on a live host was not read.

To finish ORG-01 later: start the existing WordPress the Playwright config already targets, confirm the organizer dashboard is plugin 12.18.55, and use a test organizer plus a test vendor with Stripe test keys only.

## ORG-02 — Fixtures and recordings

Not captured. ORG-01 did not produce a site, so the 0, 1, 3, and 10 event accounts were not created and no screenshot was taken.

Click path to record when the site is up, for an organizer who already has three events:

1. Open the organizer dashboard Events section.
2. Use **Vendor slots across events** and filter to a slot whose event is not the first card.
3. Change the slot name, booth price, and spots, then Save.
4. Open that event’s **Vendor slots** panel and confirm the same name, price, and spots.
5. Open **Review requests** for one of the three events and note whether the list stops at 50.

The 0-event case should show Launch event and no portfolio list (`render_portfolio_vendor_slots()` returns empty when the organizer has no slots). Full Playwright coverage stays ORG-28.

## ORG-03 — Three risks

Verdicts are from reading the service code. They were not exercised in a browser. `LNKup_Financial_Service` was not changed.

### Processing vs succeeded — tested behavior

`payment_intent.processing` is handled by `LNKup_Stripe_Webhooks::handle_payment_intent_processing()`, which calls `LNKup_Financial_Service::mark_payment_processing()` only. That method sets the payment to processing and does not call `finalize_payment()`, `mark_paid()`, or `lnkup_payment_completed`.

`payment_intent.succeeded` calls `finalize_payment()`. `stripe_intent_is_paid()` is true only when the Stripe status is `succeeded`. `confirm_checkout()` finalizes only for a `sim_` intent or when `stripe_intent_is_paid()` is true. `reconcile_payment()` treats `processing` as still processing and returns `payment_processing` instead of unlocking the packet.

Residual, not this risk: if reconcile runs with no `pi_` intent id and the local status is not already paid, it still calls `finalize_payment()`. That path is not the processing webhook.

### Readiness vs document mode — tested behavior

`LNKup_Vendor_Readiness::checklist()` builds document rows from `lnkup_get_opportunity_required_documents()` on the application’s `_lnkup_opportunity_id`.

That helper in `includes/helpers.php`:

- `none` returns an empty list, so the checklist adds no document rows.
- `platform_defaults` returns `default_documents` from settings.
- any other mode uses the opportunity’s `_lnkup_required_documents` list.

The checklist does not ignore the opportunity and always use global defaults. This was not clicked through for the three modes.

### One paid vendor moves the event — scoped defect

`LNKup_Event_Lifecycle::on_application_status()` listens on `lnkup_application_status_changed`. When the new status is `paid` and the event stage is `planning`, `applications_open`, `approvals`, or `payments`, it sets the whole event to `vendor_packets_released`.

Policy for a later operations fix: one vendor’s payment must not move the event stage. This slice does not change that method.

## ORG-04 — Slot mutation audit

Canonical writes are on `LNKup_Organizer_Onboarding`. AJAX in `class-ajax.php` checks the `lnkup_opportunity` nonce and `lnkup_manage_own_opportunities`. REST is `lnkup/v1` on `LNKup_Organizer_Launch_REST` with `organizer_permission`.

| Write | Entry | Objects touched |
| --- | --- | --- |
| Create slot | AJAX `lnkup_create_opportunity`. REST `POST /organizer/events/{id}/opportunities`. Create panel “Add to existing event” and the card add form. `create_opportunity_for_event()`. | New `lnkup_opportunity`. Meta `_lnkup_event_id`, `_lnkup_organizer_user_id`, spots, fee, deadline, `_lnkup_opportunity_status` `open`, documents, vendor category. Copies event gallery onto the slot. Syncs marketplace index. Calls `sync_event_listing_status()` on `lnkup_event`. Does not create an application or payment. |
| Rename, price, spots | AJAX `lnkup_update_opportunity_pricing` (sends `slot_title`). Used by the portfolio list and the event-card slot row. REST `POST`/`PATCH /organizer/opportunities/{id}/pricing` updates fee and spots only and does not pass a title. `update_opportunity_pricing()`. | Same `lnkup_opportunity` post (title and `lnkup_vendor_category` when a title is sent). Meta `_lnkup_vendor_fee`, `_lnkup_spots_total`, `_lnkup_spots_remaining`. Remaining is total minus already filled. Refuses a total below filled spots. Resyncs the marketplace index. Does not rewrite applications, payments, or packets. |
| Close or reopen | AJAX `lnkup_set_opportunity_status`. `set_opportunity_listing_status()`. | Meta `_lnkup_opportunity_status` `open` or `closed`. Closed deletes the marketplace index row and keeps applications. Open syncs the index again. Then `sync_event_listing_status()`. |
| Remove | AJAX `lnkup_delete_opportunity`. `delete_opportunity_for_event()`. | Refuses when any `lnkup_application` has `_lnkup_opportunity_id`. Otherwise `wp_trash_post` on the opportunity, deletes the index row, and syncs event listing status. Payments and packets are not queried. |
| Duplicate event | AJAX `lnkup_duplicate_event` and REST `POST /organizer/events/{id}/duplicate`. `duplicate_event()` then `duplicate_event_opportunities()`. | New `lnkup_event` and new `lnkup_opportunity` IDs. Copies fee, document mode, required documents, notes, gallery, packet lock. Resets spots remaining to the full total, forces status `open`, and sets a new deadline. Copies vendor category and market terms. Syncs each new slot into the index. Does not copy applications, payments, roster, or ops booth assignments. |
| Publish from the wizard | REST `POST /organizer/launch/publish` and the create-event AJAX path. | Creates the event, then slots through `create_opportunity_for_event()`. |

`render_opportunities_table()` still builds the old “Edit fee & spots” table and is not called from the dashboard. `render_create_opportunity_panel()` is also unused. The live “Add to existing event” form is `render_create_opportunity_form()` inside `render_create_panel()`.

Referenced objects for any later editor: `lnkup_opportunity`, parent `lnkup_event` via `_lnkup_event_id`, `lnkup_application` via `_lnkup_opportunity_id`, payments and packets that point at the application, and the marketplace index row keyed by opportunity ID. No second mutation service was added.

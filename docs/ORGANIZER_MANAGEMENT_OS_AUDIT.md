# Organizer Management OS — Audit (Wave 1)

Audit date: 2026-11-22. Plugin `LNKUP_VERSION` **12.29.0** (working tree, uncommitted changes present). All paths are relative to `wp-content/plugins/lnkup-marketplace/` unless stated.

This audit was done in two passes. The first (read-only, against an earlier snapshot) reported the plugin at 12.25.0 and several gaps. The second pass re-read the current code before any change. Where the two disagree, this document records the current state and says so.

## 1. Existing functionality (verified in code)

| Area | Status | Where |
|---|---|---|
| Application state machine | Working | `includes/class-applications.php` — `TRANSITIONS`, `can_transition()`, `transition()`; statuses sanitized by `LNKup_Security::sanitize_application_status()` |
| Approval capacity lock | Working | `LNKup_Opportunity_Inventory::with_lock()` (`GET_LOCK('lnkup_inv_{opp}')`), `has_room()`, `HOLD_STATUSES` |
| Late payments after close | Working (fixed before this wave) | `paid` is not reachable from `rejected`/`cancelled`; `LNKup_Payments::mark_paid()` flags `_lnkup_needs_review = paid_after_close` |
| Info requests | Working | `request_info()`, `respond_info()`, `_lnkup_info_requests` |
| Per-event roster bulk actions | Working | `includes/class-event-roster.php` `bulk_action()`, `POST /crm/events/{id}/roster/bulk` |
| Organizer CRM | Working | `LNKup_Organizer_CRM`, `LNKup_Vendor_Directory`, notes/tags/lists/invites/flags; tables `lnkup_organizer_vendor_rel`, `lnkup_organizer_vendor_tag`, `lnkup_vendor_list_item`, `lnkup_crm_activity` |
| Organization teams | Working, behind flag `trust_org_teams` (off) | `includes/trust/class-org-teams.php` (`LNKup_Org_Teams`), `/org/team*`, `/org/invites/accept`, `/org/memberships` in `includes/trust/class-trust-rest.php`; Team & Trust view (`?view=team`) |
| Unified audit log | Working | `includes/trust/class-audit-log.php` (`LNKup_Audit_Log`, hash-chained) |
| Per-event staff | Working | `LNKup_Event_Staff`, table `lnkup_event_staff`, `LNKup_Capabilities::staff_can()` |
| Booths / maps | Working | `LNKup_Booths` (`lnkup_booths`), `LNKup_Maps` (`lnkup_maps*`) |
| Check-in / QR | Working | `LNKup_Checkins` (`GET_LOCK('lnkup_checkin_{app}')`, `evaluate()` enforces `check_in`), `/operations/qr/scan` |
| Broadcasts | Working (one audience bug) | `LNKup_Broadcasts`, plus `lnkup-communications` plugin (`/comms/*`) |
| Intelligence | Working | `LNKup_Organizer_Intelligence`, `/intelligence/*` |
| Tours / series | Working behind `matching_tours_enabled` | `includes/matching/class-tours.php`, CPT `lnkup_tour` |

## 2. Missing capabilities

- Reviewer assignment and structured scoring on applications.
- Waitlist order and time-boxed waitlist offers (only a "spot open" notification exists: `lnkup_waitlist_spot_available`).
- Review-specific team roles (reviewer, vendor manager) and a `review_applications` capability.
- Staff without the `lnkup_organizer` role cannot load the organizer dashboard shell, even with a team membership.
- Recurrence, task records, organizer-side document review (deferred to later waves).

Implementation and data model: Wave 1 in [SYSTEM_OVERVIEW.md §9.22](SYSTEM_OVERVIEW.md), Wave 2 in §9.25; changelog in [CURRENT_STATE.md](CURRENT_STATE.md).

**Wave 2 status (2026-11-22):** the task-record and organizer-side document review gaps in section 2 are addressed behind flags. Event tasks with records and templates, a per-event readiness checklist, per-event organizer document review built on Passport shares, and a redesigned Overview are in place. Organizer review decisions are per event and never change platform verification.

## 3. Broken workflows (fixed in this wave unless noted)

1. `LNKup_Booths::save()` writes `application_id` with no lock, no booked-status check and no one-booth-per-vendor rule. Updating a booth without `application_id` in the body also **clears an existing assignment**, including a paid vendor's.
2. `LNKup_Broadcasts::resolve_audience()` sends `food_vendors`, `food_trucks` and `retail` to all paid vendors (no category filter).
3. Approved documents past `_lnkup_expiration_date` still report `expiring` (acceptable) in `LNKup_Verification` and `LNKup_Documents::status_for_vendor()`.
4. `LNKup_Loadin_Schedule::assign()` checks `max_slots` without a lock.
5. A payment that lands for a `waitlisted` application moves it to `paid` without an inventory check (`paid` is reachable from `waitlisted`).

## 4. UI/UX problems

- Requests view has no reviewer, scoring or waitlist-offer affordances; approval needs the full roster card.
- Team & Trust view is only usable when `trust_org_teams` is on and offers four roles.

## 5. Duplicate business logic

- Two `dashboard_widgets` endpoints (`/crm/dashboard/widgets`, `/operations/dashboard/widgets`).
- Parking in `lnkup_parking` and `lnkup_parking_zones`.
- Booth position in `lnkup_booths.pos_x/pos_y` and `lnkup_map_elements` (synced by `sync_booth_position`).
- Check-in via `/crm/applications/{id}/check-in` and `/operations/applications/{id}/checkin`.
- Not duplicated by this wave: the plan proposed new organization tables; `LNKup_Org_Teams` already provides them, so it is extended instead.

## 6. Data integrity concerns

- Booth save clearing assignments (above).
- `bootstrap_from_event()` runs on every operations snapshot GET; load-in windows have no unique key, so concurrent first loads can seed duplicate windows.

## 7. API inconsistencies

- `/operations/qr/scan` permission is "logged in"; event scoping happens later in `LNKup_Checkins::evaluate()`. Invalid tokens return 404 before any staff check, which allows token probing. Fixed in this wave: `event_id` is required, `check_in` is checked at the route, scans are rate limited, and wrong-event passes return the same 404 as unknown ones.
- `LNKup_Capabilities::user_owns_post()` also matched `_lnkup_vendor_user_id` / `_lnkup_organizer_user_id`, regardless of the meta key requested. Every caller passes `_lnkup_organizer_user_id` (or `_lnkup_owner_user_id` on a vendor profile), and applications carry both keys, so a vendor counted as the "organizer" of their own application in `handle_organizer_action()`, `request_info()` and the application intel permission. Most of those paths are also gated by organizer-only capabilities, but the fallback is wrong. Fixed in this wave: the helper checks only the requested key.
- `/intelligence/vendors/{id}/reliability` — already scoped to vendors with a relationship (fixed before this wave).

## 8. Performance issues

- `LNKup_Event_Operations::snapshot()` loads application IDs without meta priming, then runs `LNKup_Vendor_Readiness::checklist()` up to three times per application (KPIs, readiness summary, compliance).
- `LNKup_Event_Roster::get()` calls `LNKup_Vendor_Readiness::refresh()` twice for paid rows and `LNKup_Vendor_Reliability_Intel::for_profile()` per row while rendering.
- `LNKup_Checkins::scan_qr()` looks up `_lnkup_qr_pass` by unindexed `meta_value`.

## 9. Role-permission vulnerabilities

- QR scan token probing (above).
- No other unauthenticated write routes found in the organizer modules reviewed; every route inspected has a `permission_callback`.

## 10. Reuse opportunities

- `LNKup_Org_Teams` + `LNKup_Audit_Log` for teams and audit.
- `LNKup_Applications::transition()` for every status change (bulk, waitlist offers).
- `LNKup_Opportunity_Inventory::with_lock()` / `HOLD_STATUSES` for offers: an offer is an approval with a deadline, so it holds a spot through the existing model.
- `LNKup_Applications::void_pending_payment()` for expiring offers (already closes open Stripe charges via the Financial Service provider).
- `LNKup_Notifications::send()` for offer notices.

# Organizer data integrity

Every number on an organizer screen comes from one documented source. This file lists each figure, where it is computed, its scope, and how it reconciles with the same figure elsewhere. No financial records are changed by this pass.

## Presentation contracts (`LNKup_Organizer_Presentation`)

`includes/class-organizer-presentation.php` — read-only, no new storage.

### `event_summary( $event_id )`

| Field | Source | Notes |
| --- | --- | --- |
| `title` | `LNKup_Vendor_Presentation::customer_event_title()` | Strips repeated date / venue / organizer suffixes from `post_title`. Never the application title. |
| `date`, `date_label`, `days_until` | `_lnkup_event_date` | `days_until` is null for past or undated events. |
| `venue` | `_lnkup_venue_name` (hidden when `_lnkup_venue_hidden`) | |
| `place` | `lnkup_display_place( _lnkup_city, _lnkup_state )` | Normalizes "Atlanta, GEORGIA" → "Atlanta, GA", "Ellenwood, GA, GA" → "Ellenwood, GA". |
| `publication` | `LNKup_Organizer_Onboarding::sync_event_listing_status()` → `live` / `draft` | Separate from chronology. |
| `chronology` | event date vs. today → `upcoming` / `today` / `completed` / `undated` | |
| `applications_open` | any opportunity on the event with open spots (`event_has_open_slot`) | |
| `capacity` | see below | |
| `money` | see below | |
| `image` | `LNKup_Vendor_UI::event_image()` (Event Media fallback chain) | |

**Capacity** — same statuses the workspace already counts (`render_event_workspace`, shortcodes ~4736):

| Key | Definition |
| --- | --- |
| `total` | Sum of `_lnkup_total_spots` (fallback `_lnkup_spots`) over the event's `lnkup_opportunity` posts. |
| `confirmed` | Applications with status `paid` or `completed`. |
| `held` | Applications with status `approved` or `payment_required` (approved, not yet paid — the spot is held). |
| `waitlisted` | Applications with status `waitlisted`. |
| `pending` | Applications with status `submitted` or `under_review`. |
| `open` | `max(0, total − confirmed − held)`. |

The inventory guard uses the same numbers: a booth type's capacity cannot be lowered below `held + confirmed` for that opportunity.

**Money (event scope)** — `lnkup_payment` posts with `_lnkup_event_id = event`:

| Key | Definition |
| --- | --- |
| `collected_gross` | Booth amount (`LNKup_Financial::payment_booth_amount`) of payments with status `paid`. Gross booth fees, before LNKup fees and refunds. |
| `outstanding` | Booth fee of applications in `approved` / `payment_required` with no paid payment. |

### `portfolio_rows( $user_id, $args )`

One `event_summary` per owned event (`_lnkup_organizer_user_id`), plus `tour` (tour title from `_lnkup_tour_id`). Filters: `publication`, `chronology`, `applications` (`open` / `closed`), `tour`, `market`, `from`, `to`. Sorted by date ascending for upcoming, descending for completed.

### `action_queue( $user_id )`

Grouped by reason. Each row keeps event context so the same vendor in two events stays as two rows; only the same application collapses.

| Group | Source | Action |
| --- | --- | --- |
| `review` | applications `submitted` / `under_review` | Review → application drawer |
| `payment` | applications `approved` / `payment_required` with no paid payment | Invoice / Send reminder |
| `documents` | booked applications where `LNKup_Verification::get_application_document_context()` code is `pending` or `missing` | Review documents |
| `booths` | paid applications with no booth in `LNKup_Booths::get_for_event()` | Assign booth |
| `packet` | upcoming events with confirmed vendors and no published packet | Prepare packet |

### `financial_summary( $user_id )`

Wraps `LNKup_Financial_Service::organizer_money_contract()` — the same contract the Money page uses — so Home and Money show the same figure with the same label.

## Financial reconciliation

| Figure | Where shown | Source | Gross / net | Scope | Date range |
| --- | --- | --- | --- | --- | --- |
| Collected (after LNKup fees) | Home metric, Money Overview | `organizer_money_contract()['metrics']['collected']` = `LNKup_Financial::organizer_collected_revenue()` − `LNKup_Financial_Reports::organizer_platform_fees()` | Net of LNKup platform fees, before refunds and Stripe processing | All owned events | All time |
| Gross booth fees | Event workspace Money, Portfolio table "Collected" column | `LNKup_Organizer_Presentation::event_summary()['money']['collected_gross']` | Gross booth amount | One event | All time |
| Refunds | Money Overview | contract `refunds` | — | All events | All time |
| Net earnings | Money Overview | `collected − refunds` | Net | All events | All time |
| Pending | Money Overview flow strip | destination: Stripe Connect `pending` balance; ledger mode: wallet pending | Net | Connected account | Live (`synced_at`) |
| Available | Money Overview, cash-out drawer | destination: Stripe Connect `display.available`; ledger: wallet available | Net | Connected account | Live |
| Instant available | Cash-out drawer | `LNKup_Financial_Service::instant_available_cents()` from Stripe `instant_available` | Net | Connected account | Live |
| In transit | Money flow strip | destination: Connect payouts `in_transit`; ledger: wallet processing | Net | Connected account | Live |
| Paid out | Money flow strip | destination: Connect payouts paid; ledger: LNKup cashouts paid | Net | Connected account | All time |

**Before 2.0:** Home's "Collected booth fees" used `lnkup_organizer_collected_booth_total()` (gross booth amount of `paid` payment posts) while Money's "Collected" was net of LNKup fees. Both were correct; neither said which it was. On the seeded data they differ by the platform fee.

**After 2.0:** Home uses `financial_summary()` → the same `collected` metric as Money, labeled "Collected after LNKup fees · all time". Event-scope and portfolio tables show "Gross booth fees", labeled as such.

### Event Outstanding ($594.42 vs $90.00, fixed in 2.1)

On event 968 the workspace Overview showed Outstanding $90.00, but the event Money tab showed $594.42.

- **Overview** used `LNKup_Organizer_Presentation::event_money()['outstanding']`: the booth fee of each application that is `approved` or `payment_required` right now. That is one application (1756) at $90.00.
- **Money tab** used `LNKup_Financial_Reports::for_event()['pending_revenue']`. That is the sum of `payment` ledger rows with status `pending`. There were six such rows of $99.07: the checkout amount including fees, not the booth fee. Five of them belong to applications that no longer exist (seed data that was deleted and recreated), so their pending checkouts were never closed.

**Fix:** the event Money tab now reads `event_money()`, the same source as Overview: "Collected (gross)", "Outstanding", "Paid vendors". It also lists the approved, unpaid applications behind the Outstanding figure. `for_event()` is still used for refunds. The orphaned ledger rows are left in place (no deletion of records). They no longer reach any organizer figure.

## Readiness denominators (Operations)

| Metric | Numerator | Denominator |
| --- | --- | --- |
| Confirmed ready | vendors with readiness tier `ready` | confirmed (paid/completed) vendors for the event |
| Booths assigned | confirmed vendors with a booth row | confirmed vendors |
| Documents approved | confirmed vendors with document context `approved` / `not_required` | confirmed vendors |
| Packet viewed | confirmed vendors with packet view recorded | confirmed vendors with packet access (paid) |
| Checked in | check-ins for the event | confirmed vendors |

Unpaid vendors cannot open the packet, so packet alerts are suppressed for them.

## Location integrity

**Root cause:** `LNKup_Organizer_Onboarding::insert_duplicate_event()` copied city, state, lat/lng, zip, address lines, market, region, neighborhood, and geocode status/override from the source. Overrides applied only when present in the request.

**Fix:**

1. The duplicate dialog requires city and state (the REST route rejects a duplicate without them unless `keep_location=1` is sent explicitly).
2. When city or state differ from the source, the copy clears `_lnkup_lat`, `_lnkup_lng`, `_lnkup_zip`, `_lnkup_address_line1`, `_lnkup_address_line2`, `_lnkup_market`, `_lnkup_region`, `_lnkup_neighborhood`, `_lnkup_venue_name`, `_lnkup_venue_address`, `_lnkup_geocode_status`, and `_lnkup_geocode_manual_override` before applying the new location, then runs the existing geocoder.
3. `scripts/audit-event-locations.php` (dry run) lists events whose `_lnkup_duplicated_from` source has the same city/state while the copy's title names a different city. Report only. Corrections are explicit, per event, through the event edit form.

## Weather states

| Provider status | Organizer copy | Admin (`lnkup_manage_all`) |
| --- | --- | --- |
| `unconfigured` | "Weather isn't available for this event." (no Retry button) | Original configuration message |
| `no_date` / `no_location` | "Add a date and location to see the forecast." | Original provider message |
| `error` (no cached snapshot) | "Weather is temporarily unavailable." | Original message |
| event ≤ 7 days out | Live forecast (`live_forecast`) | same |
| event 8–16 days out | Extended forecast (`extended_forecast`) | same |
| event > 16 days out | Historical risk for the date (`historical_risk`), labeled as such | same |

Implementation: `LNKup_Weather_Service::for_viewer()` runs on every `for_event()` / `refresh_event()` result, so REST, rendered cards and the vendor packet note all receive the safe copy. The original text is kept in `admin_message` only for `lnkup_manage_all` users. The cached transient keeps the raw DTO.

## Titles and places

Organizer screens route titles through `LNKup_Organizer_Presentation::title()` and places through `lnkup_display_place()`. Raw `post_title` is removed from: event switcher, shell breadcrumb, Home welcome subtitle, Home action rows, Operations cards, Events table/cards, Applications table event column.

# Data integrity: root causes and fixes

Each symptom in the user's screenshots was traced from the screen, through the mobile mapper and the REST handler, to storage. Production rows were not available locally (all 6 local applications resolve correctly), so each row below names the code path proven to produce the symptom. Fixes are covered by `scripts/e2e/php/mobile-vendor-contract-test.php` (42 assertions).

| Symptom (screenshot) | Root cause | Fix |
|---|---|---|
| "Event details unavailable" on bookings (5–6) and receipts (11) | `LNKup_Vendor_Dashboard_Data::application_event_meta` read only the application's own `_lnkup_event_id`. That value is copied once at apply time and never repaired. If the event was later deleted, re-created or relinked, or the opportunity had no event at apply time, the title fell back to the placeholder. No snapshot existed. | `LNKup_Vendor_Booking_Contract::resolve_event()` tries the live event, then the opportunity's event, then a stored `_lnkup_event_snapshot`. Snapshots are written when an application is submitted or changes status, and refreshed when the event is saved. `wp lnkup bookings repair-events [--dry-run]` relinks applications to their opportunity's event and backfills snapshots, then lists anything unrecoverable. Each item reports `event_resolution`: `live`, `opportunity`, `snapshot` or `unrecoverable`. Nothing is invented. |
| Booking images and organizer missing on mature bookings | The booking item took its image and organizer from `lnkup_get_opportunity_card_data()`, which needs a marketplace index row. Closed, full and past opportunities are not indexed, and that lookup also re-synced (deleted) the row during a GET. | Booking presentation now resolves media with `LNKup_Event_Media::resolve()` and the organizer's public name from the event directly. The index is no longer touched from `/applications/vendor`. |
| Past events shown as "Coming up" and pass offered (5, 14) | All "past" logic compared the start date only. Mobile `isUpcomingBooking` also treated bookings with no date as upcoming. | `event_state()` returns `upcoming`, `active`, `completed`, `cancelled`, `postponed` or `unknown`, using `_lnkup_event_end_date` (falling back to the start date) in the event's timezone. `application_event_has_passed` and the comms `event_is_past` use the end date. Mobile buckets by `event_state`; `unknown` goes to Needs review, never Upcoming. |
| "Pay now to confirm your spot" for an August event in November (14) | Payment-due lists had no event-state check. | `payment_context` returns `has_balance`, `past_event_invoice` and `collectible`. Mobile labels these "Outstanding invoice · past event" and never promises participation. Balances stay payable while the payment record is open. Nothing is written off. |
| Receipts show status "unknown" (11) | `LNKup_Receipts` stores every row as `issued`. The mobile mapper accepted only `open`, `paid`, `void` and `refunded`. | Receipt payloads add `payment_status` (read from the payment post; `paid` when the receipt exists without one) and `document_status: issued`. The mobile mapper reads `payment_status`. |
| History tab full of "Payment past due" rows (10) | Mobile History read `/financial/activity`, an event log written by notification and lifecycle hooks, not the money ledger. | History reads `/financial/transactions?role=vendor` (the ledger), keeps only real money movement (paid, completed, refunded, failed), and hides `pending` invoice rows and `removed` rows from deleted seed payments. New `GET /financial/transactions/{id}` (vendor-owned) returns stored invoice line items and the receipt, with nothing recalculated. |
| Same event repeated once per vendor category (1–3); "82 opportunities" (4) | `/marketplace/search` returns one row per opportunity, and `total` counted opportunities. | `group_by=event` groups opportunities by event before paginating. `total_events` and `total_options` are both returned. Category filters narrow the options, so "From" uses only matching options. The default response is unchanged for existing app builds. |
| "Atlanta, Ga" (Explore) | `lnkup_format_index_row` passed the raw index city and state through. | It now applies `lnkup_display_label` and `lnkup_display_state` and adds `place`. Stored meta is not rewritten. |
| Organizer shown as account login (e.g. `org_sd2eD`) | Index cards used the user's `display_name`. | Grouped summaries and bookings use `_lnkup_organizer_public_name`, falling back to the display name. |

## State dimensions (single definition)

`LNKup_Vendor_Booking_Contract::state_dimensions()` maps stored statuses. Stored statuses are never changed.

| Dimension | Values |
|---|---|
| `application_state` | draft, submitted, reviewing, info_requested, approved, waitlisted, declined, withdrawn |
| `booking_state` | none, pending_confirmation, confirmed, completed, cancelled |
| `payment_state` | not_required, unpaid, overdue, processing, partially_paid, paid, refunded, disputed, void |
| `event_state` | upcoming, active, completed, cancelled, postponed, unknown |
| `credential_state` | locked, active, ended, revoked (pass unlock rules unchanged) |

## Count definitions (Bookings tab)

| Segment | Definition |
|---|---|
| Action required | Payment due (unpaid or overdue and collectible), information requested, or a draft |
| Applications | Submitted, reviewing, info requested, waitlisted, approved-unpaid on an upcoming event |
| Upcoming | `booking_state` confirmed and `event_state` upcoming or active |
| Past | `event_state` completed or cancelled, or the application was declined or withdrawn |
| Needs review | `event_state` unknown (no date) or `event_resolution` unrecoverable |

Web and mobile counts can differ for legitimate reasons. The web Requests tab groups by `application_request_bucket()` (in-review, pay-now, booked, completed), while mobile groups by lifecycle segment. Both now read `event_state` from the same helper, so a past event is past on both.

## Pre-existing test failures (not caused by this work)

- `booking-application-os-test` B-14 and `financial-contract-test` fail, and the cause is unrelated to this work. B-14 runs as a guest, and `LNKup_Access_Policy` scrubs capacity fields for guests; this change didn't touch gating or capacity. The contract fixture predates the organizer `pending_release` and instant cash-out keys. Neither test was re-run without this change.

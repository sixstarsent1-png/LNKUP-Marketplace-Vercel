# Bookings Center (Stage 8) and Recovery Reminders (Stage 9)

Plugin 12.33.0, built on the Booking & Application OS foundation ([BOOKING_APPLICATION_OS_FOUNDATION.md](BOOKING_APPLICATION_OS_FOUNDATION.md)). WordPress remains the system of record; nothing here computes money in the browser. Integrated on branch `integrate/bookings-center`, stacked on `feature/organizer-os-portfolio` (PR #2), which carries the foundation; the original work is on `booking-center-reminders` (from the WIP snapshot `07810a4`).

## Stage 8: My Bookings

### Service: `includes/class-vendor-bookings.php` (`LNKup_Vendor_Bookings`)

- `query( $vendor_id, $args )`: one prepared SQL list query, one count, and one aggregate for all bucket counts. Joins the application to its event (date, city, tour) and the newest payment row. Excludes drafts and in-review requests. Page size max 50.
- Buckets:

| Bucket | Rule |
|---|---|
| `upcoming` | pending payment or paid, event today or later |
| `pending_payment` | `approved` / `payment_required` with an unpaid payment (`pending`, `failed`, `partially_paid`, `processing`) |
| `confirmed` | `paid`, event today or later |
| `past` | `completed`, or `paid` with the event date passed |
| `cancelled` | `cancelled` / `rejected`, not refunded |
| `refunded` | payment `refunded` / `partially_refunded` and the booking is no longer active |
| `all` | everything from approval onward (in-review requests stay on Requests) |

- `row()`: card DTO (event, organizer, artwork, date, venue, booth, statuses, documents code, pass availability, check-in, next action, tour, links). No readiness call (it writes meta on read).
- `detail()`: full booking for its owner only (otherwise `null`). Payment block reads only the payment record (total, credit, card charged, amount due, refunds, invoice, receipts, refund rows). Readiness checklist (once, paid/completed only), pass instructions, support link, timeline.
- `timeline()`: milestones from `_lnkup_status_history`, payment `_lnkup_paid_at`, refunds, `_lnkup_checked_in_at`, `_lnkup_event_completed_at`. States `done | current | upcoming | stopped`, groups `application | payment | readiness | attendance`. No invented timestamps: legacy applications only get stored meta times.

### REST (`lnkup/v1`)

| Method | Route | Permission | Notes |
|---|---|---|---|
| GET | `/bookings/vendor` | vendor | Query `bucket, tour_id, city, from, to, page, per_page`. Returns `items, total, page, pages, per_page, counts, filters, filter_options{cities,tours}`. |
| GET | `/bookings/vendor/{id}` | vendor + ownership | 404 for anything the caller does not own. |

Mobile contract: `app/booking/[applicationId].tsx` can switch from fetching the whole request list to `/bookings/vendor/{id}` (not changed in this stage).

### Web UI

- `?view=bookings` (vendor dashboard): bucket tabs with counts, city / tour / date filters (auto-submit, `public/js/vendor-bookings.js`), server-rendered cards, `paginate_links` paging, empty and error states.
- `?view=bookings&booking=ID`: timeline, event, booking (answers), payment & receipts, documents & readiness, instructions & pass, support. Pay / Open pass / Withdraw reuse the existing handlers.
- Theme: "My Bookings" in the vendor sidebar (primary tier). On the phone bottom bar Bookings replaces Wallet (five slots; Wallet stays in the sidebar and payments are reachable from each booking).
- Requests cards link to the booking detail ("Booking details") once an application is past review.

## Stage 9: Recovery reminders

### Core hooks (`includes/class-application-drafts.php`)

- `lnkup_application_draft_saved( $draft_id, $opp_id, $user_id, $revision )` after every save.
- `lnkup_application_draft_closed( $draft_id, $state )` on discard, daily expiry and opportunity deletion.
- `_lnkup_draft_updated_at_gmt` (UTC twin of the local save time) for scheduling.

### Unfinished application reminders (comms)

- Trigger `v_application_draft_reminder`: vendor, `applications` category (transactional; vendors can mute the category), normal priority, inbox + email + push, flag `flow_application_drafts` (off by default), link `draft_resume`, validity `draft_still_open`, expires 1 day after its send time.
- Listener `includes/listeners/class-comms-listen-drafts.php`: every save cancels `draft:{id}` and schedules 24h and 72h after the save (window includes the revision). A reminder that would land after the application deadline is not scheduled. Submit / discard / expiry cancel; event cancellation is covered by `cancel_event_messages` (messages carry the event).
- Validity `draft_still_open`: draft open, owned by the recipient, not saved again since scheduling, slot still open (deadline, capacity, event), vendor has no active application.
- Links: app `/apply/{opp}`; web Find Events `?apply={opp}&resume=1`. Templates `:d24` and `:d72` (the second says it is the last reminder).

### Payment reminders

- Deadline windows: 7 days (only when the deadline is more than 8 days out), 3 days, 24 hours, and a final 6-hour notice; all high priority (send through quiet hours) and show the actual deadline.
- One source per deadline: core invoice reminders (7d / 3d / 24h) are claimed without sending when the application has its own payment deadline; only the past-due invoice notice still sends.
- `processing` (bank) payments are not "due" (`PAYABLE_PAYMENT` already excluded it); cancellation on paid / rejected / cancelled / withdrawn / event cancelled was already wired and is now covered by tests.
- The comms automations screen lists "Unfinished applications".

## Testing (wp-env, chromium)

| Suite | Result |
|---|---|
| `scripts/e2e/php/booking-center-test.php` (new, in `test:apps:php`) | 29/29 |
| `scripts/e2e/php/comms-recovery-test.php` (new, in `test:comms:php`) | 30/30 |
| `npm run test:comms:php` (8 suites) | all passed after updating the lifecycle assertion to 3 reminders for a 7-day deadline |
| `npm run test:apps:php` | all passed |
| `tests/e2e/vendor-bookings.spec.ts` (new) | 6/6 |

### Integration run on `feature/organizer-os-portfolio` + this commit (12.33.0, fresh seed)

| Suite | Result |
|---|---|
| `npm run test:apps:php` (5 suites) | all passed |
| `npm run test:comms:php` (9 suites, incl. `comms-activation-test` from PR #2 and `comms-recovery-test`) | all passed |
| `npm run test:fin:php` (14 suites) | all passed |
| `money-path-verify`, `phase26-verify`, `gap-close-verify` | passed |
| Playwright chromium: vendor-bookings, apply-draft-resume, tour-multi-apply, apply-with-profile, vendor-journey, checkout-embedded, communications, vendor-dashboard-ui, vendor-event-pass | 58 passed, 3 skipped, 2 failed (see below) |

The two Playwright failures are not caused by this change:
- `vendor-dashboard-ui` "mobile bottom navigation and sidebar toggle work" times out closing the vendor welcome dialog while the dashboard tour modal is open on top of it. It fails the same way on the base snapshot without this commit (onboarding modal stacking from the base branch).
- `checkout-embedded` "checkout summary API returns bundled booth total" hit the known wp-env `reauth=1` login bounce; it passed (2/2) when re-run on its own.

## Deployment

1. Deploy plugin 12.33.0 and the communications plugin (new listener file; no schema change).
2. Turn on `flow_application_drafts` in Communications settings after checking one test draft on staging (two scheduled messages appear in the queue).
3. Rollback: turn the flag off (scheduled draft reminders are suppressed at send time by the flag check) and remove the My Bookings nav item if needed; `?view=bookings` keeps working.

## Limitations

- Expo screens still use the request list for booking detail; switch to `/bookings/vendor/{id}` in the mobile stage.
- Booking modifications (date / booth change requests) and organizer-side unpaid queues are later stages.
- Draft reminders respect category preferences but not a separate "marketing" opt-out, because they are operational for an application the vendor started.

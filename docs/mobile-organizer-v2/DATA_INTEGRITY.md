# Organizer Mobile OS 2.0 — Data integrity

Each rule is enforced on the server and covered by `scripts/e2e/php/organizer-mobile-contract-test.php`.

| Rule | Implementation |
| --- | --- |
| One setup percentage | Home uses only `LNKup_Organizer_Activation` (`setup` in `/organizer/home-summary`). `/organizer/onboarding` stays for old builds. |
| Check-in arrivals count only eligible vendors | `checkin-summary` runs `LNKup_Checkins::evaluate($app, '')` per booking. `expected` = ready + checked in; unpaid, document-blocked and invalid bookings are reported separately. `arrival_pct = checked_in / expected`. |
| Receivables are open invoices only | `for_event()` adds `outstanding_receivables` (pending, failed, partially paid, overdue on bookings that are not rejected or cancelled). Refunded and void are excluded. `pending_revenue` is unchanged for old builds. |
| No hardcoded payout fee | `LNKup_Organizer_Payouts::instant_fee_rate()` reads `organizer_instant_fee_rate` (default 0.015, admin field "LNKup instant cash-out fee (%)"). Both the cash-out path and the preview use it. |
| Preview never moves money | `GET /financial/organizer-payouts/preview` only reads; the test asserts the payout table row count is unchanged. Fee shown as `fee_amount` (organizer fee-privacy filter keeps it). |
| Payouts never deduplicated by amount/date | Rows keep their local id, add `reference` (`LNK-PO-{id}`), `source` and `updated_at`. |
| No cross-organization access | Every route checks ownership or `staff_can`; invite remind/cancel return 404 for other organizers. |
| Staff see only what their role allows | Roster, event summary, operations and check-in summaries accept `staff_can`; money appears only with `view_financials`. |
| No self-recruitment | Discovery query, match candidates and `Vendor_Invites::create` exclude the organizer's own vendor account. |
| Invite history kept | Cancel sets status `cancelled` and `_lnkup_cancelled_at`; nothing is deleted. Reminders are rate-limited (2 days) and counted. Responses record `_lnkup_responded_at`. |
| Cloned events in a new city | `duplicate_event` copies `lnkup_market` terms only when city and state are unchanged. `scripts/audit-event-locations.php --fix-markets` removes stale source markets from older copies (dry run by default; never infers a city from a title). |

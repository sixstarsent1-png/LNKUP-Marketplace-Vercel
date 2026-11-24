# Organizer Mobile OS 2.0 — Competitive research (public sources only)

Patterns below are taken from the public marketing and help pages of each product. No private accounts, scraped data or non-public material was used, and no feature claims beyond what those pages describe are made here. Treat them as interaction patterns to weigh, not as verified benchmarks.

| Product (public pages) | Pattern relevant to LNKup organizers | What LNKup takes from it |
| --- | --- | --- |
| Stripe Dashboard app ([stripe.com/dashboard](https://stripe.com/dashboard)) | Balance first; payouts listed with status and arrival; payout detail shows the destination and reference | One honest balance summary, payout detail with reference, no jargon on the main surface |
| Square Dashboard ([squareup.com](https://squareup.com/us/en/point-of-sale/software)) | Instant transfer shows the fee before confirming | Fee preview step from the server before any cash-out |
| Shopify mobile ([shopify.com/mobile](https://www.shopify.com/mobile)) | Home lists the next actions with counts; orders open in a focused detail screen | Action Center with counts, dedicated review screen with a decision bar |
| Eventeny ([eventeny.com](https://www.eventeny.com)) | Vendor applications, booth assignment and check-in for markets and festivals | Organizer workflows stay event-scoped: applications, booths, packets, check-in |
| Marketspread ([marketspread.com](https://www.marketspread.com)) | Market vendor recruitment and applications | Recruitment from an underfilled category, invites with status |
| Eventbrite Organizer app ([eventbrite.com/organizer](https://www.eventbrite.com/organizer/)) | Day-of check-in with live counts | Event-day mode with arrival counts (attendee ticketing itself is out of scope for LNKup) |

## Decisions

- No camera scanner in this release (JavaScript-only OTA). Check-in is lookup-first with manual pass-code entry; no decorative scan button.
- Online-only check-in with an honest network banner. Competitor offline modes are not copied until LNKup has a secure queued implementation.
- Fees are shown only from the server preview; no hardcoded rates in the app.

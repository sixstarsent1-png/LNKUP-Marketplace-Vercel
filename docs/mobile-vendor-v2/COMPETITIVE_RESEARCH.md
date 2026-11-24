# Competitive research (public sources only)

All findings come from public marketing pages, App Store and Play Store listings, help-center articles and product videos. We had no authenticated access to any competitor app, so nothing here claims hands-on use of signed-in features. No assets, layouts or branding were copied; patterns were translated into LNKup's own components.

## Reference matrix

| Platform | Mobile workflow | Design principle | Applicable LNKup feature | What we built |
|---|---|---|---|---|
| POSH | Home feed with large image cards, category chips, saved events | Imagery first; one tap to details | Explore and Home rails | One grouped event card per event. The whole card is tappable, with a single save action. Compact horizontal rails on Home. |
| Eventbrite | Location picker above the feed; filters in a sheet; "This weekend" collections | Location is context, not a form field | Explore header | Compact sticky header: location chip, search field, filter button with active count. Collections only appear when they have inventory. |
| TicketLeap | Purchase confirmation, receipt, event-support links | Every payment has a receipt and a support route | Wallet transactions and receipts | Transaction detail and receipt screens built from stored ledger and receipt rows, with booking and support links. |
| Faire | B2B catalog showing minimums and pricing on the card; business verification | Business decision data up front | Opportunity cards and event detail | "From $X" per event (or per category when filtered), open spaces, "Apply by" date, verified-organizer badge, organizer-provided attendance labelled as such. |
| Eventeny | Vendor applications, booth maps, vendor check-in | Event-day logistics in one place | Event preparation and pass | Preparation workspace: readiness split by vendor tasks and organizer-pending tasks, booth card, venue map, pass. |
| Marketspread | Reusable vendor profile across markets; recurring participation | Apply once, reuse everywhere | Apply flow and Passport | Existing Passport prefill and document reuse surfaced in the stepped application review. |
| Airbnb | Trips tab: upcoming vs past, arrival details, contextual support | Reservation identity is always recognizable | Bookings and booking detail | Upcoming, Past and Needs review sections. Header always shows the event (live or snapshot). Timeline uses real timestamps. |
| Stripe / Square | Balance hierarchy; transaction list grouped by date; detail with line items | Ledger rows, never notifications, in history | Wallet | History reads `/financial/transactions`, shows only real money movement, grouped Today / This week / month. Reminders stay in notifications. |
| Apple Wallet | Full-screen credential, brightness, state when expired | Credential state must be honest | Event pass | Ended and revoked passes hide the QR code. Brightness boost is deferred because it needs a native module. |
| Linear / modern iOS apps | Grouped settings rows, restrained color | Hierarchy through grouping, not color | Profile | Your Business / Your Activity / Grow / Account groups; lime used only for state and primary emphasis. |

## Patterns deliberately not adopted

- Social feeds and follower counts (POSH), because they don't serve vendor business decisions.
- Attendee ticketing, ticket transfer and admission QR codes. LNKup passes are vendor credentials only.
- "Trending" labels without a documented activity metric.

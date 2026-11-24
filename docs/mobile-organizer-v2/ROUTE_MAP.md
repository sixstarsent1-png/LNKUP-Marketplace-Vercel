# Organizer Mobile OS 2.0 — Route map

Tabs stay Home, Events, Vendors, Money, More. New screens are stack routes inside the existing groups so deep links and the vendor app are unaffected.

| Screen | Route | Data source |
| --- | --- | --- |
| Home command center | `/(organizer)` | `GET /organizer/home-summary` (fallback: previous calls) |
| Events portfolio | `/(organizer)/events` | `GET /organizer/mobile/events?q&chronology&publication&applications&tour` |
| Event detail (Overview, Vendors, Inventory, Money, Operations) | `/(organizer)/events/[eventId]` | `/organizer/events/{id}/summary`, existing tab sources |
| Operations workspace | `/(organizer)/events/operations?eventId=` | `GET /organizer/events/{id}/operations-summary` |
| Check-in | `/(organizer)/events/checkin?eventId=` | `GET /operations/events/{id}/checkin-summary`, `POST /operations/applications/{id}/checkin`, `POST /operations/qr/scan` (manual code) |
| Booths, map, logistics, packet (tabs) | `/(organizer)/events/booths?eventId=&tab=` | `/operations/events/{id}/booths|loadin|parking`, `/maps/events/{id}`, `/packets/event/{id}/completeness|pass-details` |
| Application review | `/(organizer)/vendors/review/[applicationId]?filter=&eventId=` | `GET /organizer/applications/{id}/review` (new); decisions via `/crm/events/{id}/roster/bulk` and `/applications/{id}/request-info` |
| Team | `/(organizer)/team` | `/org/team` (read-only), `/operations/events/{id}/staff` |
| Requests / Discover / Invites | `/(organizer)/vendors?segment=` | `/applications/organizer`, `/crm/vendors`, `GET /organizer/mobile/invites` |
| Invite remind / cancel | (sheet) | `POST /crm/invites/{id}/remind`, `POST /crm/invites/{id}/cancel` |
| Money (Overview, Transactions, Payouts, Invoices) | `/(organizer)/money` | existing balances + `/financial/organizer-payouts` |
| Cash out | `/(organizer)/money/cashout` | `GET /financial/organizer-payouts/preview`, existing cash-out POST with `client_token` |
| Payout detail | `/(organizer)/money/payout?id=` | `/financial/organizer-payouts` row |
| Event financials | `/(organizer)/money/event?eventId=` | `/financial/events/{id}/financials` (adds `outstanding_receivables`) |
| Tours | `/(organizer)/tours` and `/(organizer)/tours?tourId=` | `/portfolio/tours`, `/portfolio/tours/{id}/summary` (platform-fee fields not mapped) |
| Organization HQ | `/(organizer)/more` | profile, `/org/team` (flagged), push preferences, referrals |

All new routes are additive. Old builds keep using `/organizer/portfolio`, `/organizer/onboarding` and `/organizer/activation`, which are unchanged.

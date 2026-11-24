# Competitive research (public sources only)

No authenticated dashboards were accessed. Every observation below comes from public marketing pages, help centers, and API documentation. Where a product's organizer UI is not publicly documented, the cell says so instead of guessing.

## Matrix

| Product | Category | Pattern we take | Public source type | Where it lands in LNKup |
| --- | --- | --- | --- | --- |
| POSH | Event ticketing | Event "command center" home with a next-event focus and quick actions | Marketing pages | Home spotlight |
| Eventbrite | Ticketing | Event list as a sortable table with status filter (draft / live / past); event dashboard tabs | Help center articles | Events portfolio table, publication vs. chronology filters |
| TicketLeap | Ticketing | Duplicate event requires a new date before saving | Help center | Duplicate dialog (date + location required) |
| Eventeny | Vendor applications | Vendor application review with status tabs, booth inventory by type, bulk approve | Marketing + help center | Applications saved views, Inventory table |
| Marketspread | Market vendor management | Booth map with assign-from-roster, recurring market seasons | Marketing pages | Booth assign drawer (both directions), Tours & Series |
| Cvent | Enterprise events | Event-scoped workspace with persistent header and tab bar; role-based team access | Product pages, docs | Workspace header, Team & Permissions |
| Stripe Dashboard | Payments | Balance flow: incoming → pending → available → in transit → paid out; payout detail drawer; instant vs. standard payout fee disclosure | Stripe docs (Connect, Balances, Instant Payouts) | Money flow strip, cash-out drawer |
| Ramp | Spend | Transactions table with separate status columns and a right-side detail drawer | Marketing pages, docs | Transactions table + drawer |
| HubSpot | CRM | Saved views on a records table; multi-select tray with bulk actions | Knowledge base | Applications saved views, Vendors multi-select tray |
| Linear | Issue tracking | Grouped sidebar, keyboard-first, compact top bar with breadcrumb | Public docs, changelog | Grouped organizer nav, compact top bar |
| Airtable | Database | Grid ↔ gallery view toggle persisted per user | Support docs | Events table/grid toggle (`localStorage`) |
| Figma | Design | Canvas editor: top toolbar, layers left, inspector right | Help center | Map Builder editor layout |

## Principles we adopt

1. **One home for each job.** Each job (review, invite, pay out, assign booths) has one primary surface; other screens link into it with filters applied.
2. **Counts open their filtered destination.** Every metric is a link, and the destination shows the same number.
3. **Money flows left to right.** Received → pending → available → in transit → paid out, each with its source and sync time.
4. **Drawers for detail, pages for work.** Review, edit and assign in drawers; keep the list visible behind them.
5. **Tables first for operators, cards for discovery.** Portfolio, applications, inventory, transactions and booths are tables (with a card fallback under 768px). Vendor discovery stays as cards.
6. **Say what a number means.** Labels carry gross/net and scope ("Collected after LNKup fees · all time").

## What we do not copy

- Attendee ticketing, seat maps, and box office features (out of scope).
- Proprietary visual styles; LNKup keeps its black / white / lime system.
- Any claim about competitors' internal data models.

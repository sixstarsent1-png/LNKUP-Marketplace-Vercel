# Organizer Mobile OS 2.0 — Design system

Builds on Mobile Vendor OS 2.0 (`docs/mobile-vendor-v2/DESIGN_SYSTEM.md`): black, white and lime accent, rounded cards, 44 pt touch targets, loading, empty and error states on every panel.

## Reused vendor components

`Screen` / `ScreenList`, `EventImage` (with `fallbackUri`), `StickyActionBar`, `TransactionRow`, `ActionCenter` styling, `SegmentedControl`, the filter-sheet pattern.

## New organizer components (`components/organizer/`)

| Component | Purpose |
| --- | --- |
| `OrgHeader` | Organization logo (`ProfileMark`), public name, notifications bell |
| `KpiGrid` | 2-column KPI tiles, each with a scope label ("all time", "upcoming events") |
| `QuickActionGrid` | State-driven shortcuts; check-in only within a day of an event |
| `EventRow` | Compact event row with `EventImage`, formatted date, place, publication chip |
| `ApplicationRow` | Vendor, event, category, applied date, fee, status, document indicator |
| `InviteRow` | Vendor, event, sent date (separate from event date), status, remind/cancel |
| `PayoutRow` | Amount, method, status, reference |
| `NetworkBanner` | Online / offline / reconnecting state; disables writes while offline |

## Rules

- Dates: `formatShortDate` / `formatEventDate` only; no ISO strings on screen.
- Money: show server values with their scope label. Never compute fees in the app.
- Status: publication (Draft / Published) is separate from chronology (Upcoming / Today / Completed).
- Vendors badge = awaiting review (submitted + under review), the same statuses as Home's server count (`awaitingReviewCount` / `PENDING_STATUSES`). Info-requested applications are listed under "Needs info" and are not counted until the vendor replies and the status returns to review.
- Copy: no "Stripe Connect", "EAS", "settlement: destination" or other configuration text.

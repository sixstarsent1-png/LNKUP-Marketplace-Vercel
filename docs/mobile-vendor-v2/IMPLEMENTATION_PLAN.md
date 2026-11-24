# Implementation plan

The work runs in the stage order below (see `AUDIT.md` and `DATA_INTEGRITY.md` for the findings each stage addresses).

## Constraints

- **Over-the-air only.** Every mobile change is JavaScript-only, so it ships as an OTA update on runtime 1.0.1 (`runtimeVersion.policy: appVersion`). No native modules were added: no haptics, @gorhom/bottom-sheet or FlashList.
- **Additive REST.** Every backend change is additive. App builds already in users' hands keep calling `/marketplace/search` without `group_by` and get the old response.
- **System of record.** WordPress stays the system of record. Mobile never computes fees, payability or pass eligibility on its own when the server provides them.
- **Organizer screens.** Not redesigned. Shared components keep their existing props.

## Stages

| Stage | Scope | Backend | Mobile |
|---|---|---|---|
| 0 | Audit, research, baseline | (none) | Screenshots `before/`, metrics `scripts/e2e/reports/mobile-v2-before-*.json` |
| 1 | Data integrity | `LNKup_Vendor_Booking_Contract` (event resolution, snapshot, `event_state`, state dimensions, `payment_context`, `credential_state`, timeline, repair CLI); receipts `payment_status` and event context; ledger event context and `GET /financial/transactions/{id}`; `GET /applications/vendor/{id}`; display place on index rows; comms end-date check | (none) |
| 2 | Design system | (none) | Remove the repeated "LNKup" eyebrow, compact screen headers, Profile tab label, shared payment CTA and lifecycle helpers, `EventImage` fallback chain and portrait backdrop |
| 3 | Grouping and imagery | `group_by=event` on search and sections, `GET /marketplace/events/{id}` (`LNKup_Event_Groups`) | `EventGroup` mapper with a local fallback, `EventCard` variants, per-option favorites behind event cards, vendor event detail |
| 4 | Home | (none) | Lifecycle layout, consolidated Action Center, next-event spotlight, grouped rails |
| 5 | Explore | (none) | Compact sticky header, debounced search, category chips, grouped infinite list, accurate counts, kept tab state |
| 6 | Event detail and application | (none) | Option selector, context-aware CTA, labelled attendance and deadlines; existing stepped apply flow kept |
| 7 | Bookings | (none) | Action needed / Applications / Upcoming / Past / Needs review segments; single-booking endpoint with real timeline |
| 8 | Wallet | (none) | Due / History / Documents, ledger history, transaction and receipt detail |
| 9 | Event operations and pass | (none) | Pass `credential_state` handling, preparation split |
| 10 | Profile and Passport | (none) | Profile hub regrouped, private identity separated, cache cleared on workspace switch |
| 11 | Secondary | (none) | Growth labels, notifications grouping and routing, invitations imagery |
| 12 | Hardening | PHP contract test | Jest, Playwright screenshots `after/`, metrics, release notes |

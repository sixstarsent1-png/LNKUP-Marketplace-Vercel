# Implementation record

1. Presentation contracts in `includes/class-vendor-presentation.php`, wired through `application_event_meta()`, state display, and application REST.
2. Vendor nav groups and a five-item mobile bar. Existing `?view=` values unchanged.
3. Home: lifecycle greeting, one invitation module, compact Getting Started, grouped activity.
4. Explore cards: category summary instead of a slot price list. Images already contain portrait flyers via `lnkup-media--fit-contain`.
5. Bookings: one status, past events leave upcoming counts, withdraw hidden after the event date, pay remains when the payment is still collectible.
6. Wallet: one hero summary from uncapped CPT totals, one tab row, receipt document plus PDF, SetupIntent confirm then sync.
7. Operations: real event titles in the selector, next future event as default, readiness pending labels, weather copy.
8. Growth: event investment label, 90-day empty chart explanation, market reasons without false proximity, no hardcoded season.
9. Next.js More page uses the live profile when the live API is on. Expo and Next booking mappers prefer `place`.

Not done in this pass: native device runs, live card charges, a new ledger, organizer dashboard redesign, self-reported sales profitability.

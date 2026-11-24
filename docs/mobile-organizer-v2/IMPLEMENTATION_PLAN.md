# Organizer Mobile OS 2.0 — Implementation plan

Stages follow the approved plan. JavaScript-only (OTA on runtime 1.0.1), no camera scanner, online-only check-in.

| Stage | Scope | Ships as |
| --- | --- | --- |
| 0 | Audit, baseline screenshots and metrics | docs |
| 1 | `LNKup_Organizer_Mobile_REST` + data fixes (receivables, fee setting, invites, self-exclusion, clone markets, staff permissions, score factors) | plugin |
| 2 | Organizer components, date formatting, badge definition | OTA |
| 3 | Home on `home-summary` | OTA |
| 4 | Events portfolio, event detail, in-app publish, launch/duplicate | OTA |
| 5 | Application queue and review screen | OTA |
| 6 | Discovery cards, recruitment, invitations | OTA |
| 7 | Money segments, cash-out with preview, payout detail, event financials | OTA |
| 8 | Operations workspace | OTA |
| 9 | Check-in rebuild | OTA |
| 10 | Booths, map viewer, packets, logistics | OTA |
| 11 | Tours | OTA |
| 12 | Organization HQ, team, notification states, referral terms | OTA |
| 13 | Jest, Playwright, after screenshots, metrics, RESULTS | tests + docs |

Release order: plugin first (all routes additive), then a preview OTA. Production OTA only with approval.

## Capability matrix

| Capability | Classification |
| --- | --- |
| Review/approve/waitlist/decline applications | Native daily |
| Check-in (lookup + manual code) | Native daily |
| Home action center, KPIs | Native daily |
| Events list, event detail, publish | Native daily |
| Invites (send, remind, cancel) | Native daily |
| Cash out with fee preview, payout detail | Native daily |
| Event financials, receivables | Native |
| Booth assign/create, packet edit/preview, load-in/parking views | Native |
| Map viewing | Native (read-only) |
| Tours summary and stop switching | Native |
| Intelligence score with factors | Summary |
| Team view (`trust_org_teams`) | Summary |
| Map editing, bulk tour creation, advanced financial reports, CSV exports | Desktop-first (exact deep link) |

## Cross-initiative dependencies

- **Web Organizer OS 2.0 (uncommitted):** the mobile REST class calls `LNKup_Organizer_Presentation`; only two small edits were made there (staff permission on `/organizer/events/{id}/summary`, money gated by `view_financials`).
- **Mobile Vendor OS 2.0:** components reused, vendor screens untouched.
- **Financial OS:** all money reads go through existing financial services; no Stripe calls from the app.

# Phase 17 Release Checklist

Gate reference: [PHASE_15_20_QA_GATE.md](PHASE_15_20_QA_GATE.md)

## CRM Contract and BFF
- Confirm organizer BFF routes are returning deterministic response shapes:
  - `GET /api/organizer/requests`
  - `GET /api/organizer/vendors`
  - `GET /api/organizer/vendors/[id]`
  - `GET /api/organizer/lists`
  - `GET /api/organizer/invites`
- Confirm route errors normalize to organizer-safe messages for auth, forbidden, not found, and upstream unavailable scenarios.

## Organizer Vendor Operations
- Verify queue chips on organizer vendors page reflect live request totals.
- Verify discover tab supports search and sort without spinner lock.
- Verify list and invite tabs show live snapshots when API is available.
- Verify event workspace request tab supports selection + bulk approve/waitlist actions.

## Profile Drilldown
- Verify vendor profile drawer includes:
  - profile overview/reputation
  - timeline activity cards
  - note snapshots
- Verify drawer gracefully degrades when timeline/notes endpoints are unavailable.

## Telemetry
- Confirm event tracking for:
  - `organizer_vendor_directory_viewed`
  - `organizer_vendor_profile_opened`
  - `organizer_review_action_attempted`
  - `organizer_review_action_succeeded`
  - `organizer_review_action_failed`
  - `organizer_list_action_clicked`
  - `organizer_invite_action_clicked`
- Validate metadata remains non-PII.

## E2E Smoke
- `organizer/vendors` renders queue chips and discover search input.
- Profile drawer test renders timeline and notes from BFF payload.
- Event workspace fallback test shows web handoff card when review is unavailable.

## Rollout Guardrails
- Stage first and monitor organizer request decision throughput for 24h.
- Watch support reports tagged request-review and vendor-profile.
- If CRM upstream reliability degrades, keep read surfaces and handoff review actions to web dashboard.


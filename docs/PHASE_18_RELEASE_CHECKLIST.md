# Phase 18 Release Checklist

Gate reference: [PHASE_15_20_QA_GATE.md](PHASE_15_20_QA_GATE.md)

## Contract Alignment
- Verify shared readiness contract fields are present across web and mobile:
  - `readinessTier`
  - `riskBucket`
  - `missingItems[].documentUrgency`
  - `blockers`
- Confirm contract mapping remains read-only and uses WordPress as canonical source.

## BFF Reliability
- Validate `GET /api/vendor/readiness` returns deterministic payload and `code`.
- Validate organizer and vendor role boundaries still rely on existing auth/capability checks.
- Validate fallback-safe error behavior for auth required, forbidden, not found, and upstream unavailable.

## Vendor UX Verification
- Vendor home shows risk bucket and explicit blockers when at risk.
- Vendor readiness card surfaces expiring/expired urgency chips.
- Mobile profile and pass screens show readiness tier/risk and urgency labels.

## Organizer Visibility Verification
- Organizer vendors queue shows concise at-risk count.
- Organizer request cards expose document/readiness blockers.
- Organizer profile drawer shows readiness blockers from CRM intel when available.

## Escalation Messaging
- Notification composition uses `documentUrgency` metadata, not string heuristics.
- Expired document reminders escalate to urgent severity.
- Event-day reminder behavior remains unchanged for booked/passed flows.

## Telemetry Checks
- Confirm event ingestion for:
  - `vendor_readiness_blocker_viewed`
  - `vendor_readiness_action_clicked`
  - `organizer_vendor_risk_viewed`
- Confirm metadata remains non-PII.

## Smoke Tests
- Vendor at-risk path renders blockers and action CTA.
- Expiring document urgency appears in readiness UI and notifications.
- Organizer triage view shows at-risk signal.

## Rollout Safety
- Stage deployment first and monitor readiness completion rates for 24h.
- Track support reports for “missing docs”, “day-of blocked”, and “pass readiness”.
- If upstream readiness data regresses, keep app fallback copy and route users to documents/profile while preserving role-safe behavior.


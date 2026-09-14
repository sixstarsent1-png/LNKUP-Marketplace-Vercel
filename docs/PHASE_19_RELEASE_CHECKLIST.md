# Phase 19 Release Checklist

Gate reference: [PHASE_15_20_QA_GATE.md](PHASE_15_20_QA_GATE.md)

## Scope Verification
- Confirm `ops` and organizer payloads include deterministic `code` values (`ok`, `*_partial`) and no schema drift.
- Confirm diagnostics payload includes segmented funnel data by `role`, `city`, or `category`.
- Confirm experiment contract includes status (`draft`, `active`, `paused`, `completed`) and decision status (`ship`, `iterate`, `stop`).
- Confirm recommendations map issue -> action -> expected metric shift with confidence + decision metadata.

## API / BFF Validation
- `GET /api/ops/analytics/funnel` returns funnel and deterministic fallback response on upstream failure.
- `GET /api/ops/analytics/summary` returns diagnostics, experiments, recommendations, and partial reasons when degraded.
- `GET /api/organizer/home` preserves organizer summary while safely degrading checklist/financial submodules.
- Validate fallback messages remain product-safe and non-technical.

## Organizer + Ops UX Checks
- Ops Analytics page shows experiment diagnostics section with:
  - active experiment badge/status/variant,
  - guardrail chips,
  - top recommendation card.
- Organizer home shows intelligence panel with:
  - active experiment metadata,
  - drop-off diagnostics,
  - recommendation action CTA.
- Verify role gates remain intact; unauthorized users never see organizer intelligence payload.

## Telemetry Checks (Non-PII)
- Validate event emission for:
  - `organizer_experiment_viewed`
  - `organizer_experiment_recommendation_opened`
  - `organizer_experiment_recommendation_action_clicked`
  - `organizer_experiment_decision_recorded`
- Confirm metadata only includes segment, funnel stage, variant, confidence, and decision fields (no PII).

## Test Plan
- Run E2E: `tests/e2e/lnkup-app-phase19-intelligence-experiments.spec.ts`
- Validate existing analytics and organizer flows still pass:
  - `tests/e2e/lnkup-app-phase7-analytics.spec.ts`
  - `tests/e2e/lnkup-app-phase14-analytics.spec.ts`

## Rollout Guardrails
- Start with `ENABLE_OPS_ANALYTICS=true` environments already used for QA/internal ops.
- Monitor partial-data rates (`*_partial`) and upstream intelligence availability.
- If fallback usage spikes, route investigation to WordPress intelligence endpoints before UI rollback.

## Troubleshooting
- If experiment cards are missing: inspect `/api/ops/analytics/summary` `experiments` and `recommendations`.
- If organizer panel disappears: inspect `/api/organizer/home` for auth/403 and summary payload shape.
- If analytics tracking fails: verify `/api/analytics/track` and event whitelist in `lib/analytics/lnkup-events.ts`.

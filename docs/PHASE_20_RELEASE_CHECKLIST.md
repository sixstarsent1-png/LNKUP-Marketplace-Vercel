# Phase 20 Release Checklist

Gate reference: [PHASE_15_20_QA_GATE.md](PHASE_15_20_QA_GATE.md)

## Scope Verification
- Confirm mobile organizer home payload includes `intelligence` with `diagnostics`, `experiments`, and `recommendations`.
- Confirm experiment statuses are deterministic: `draft`, `active`, `paused`, `completed`.
- Confirm recommendation decisions are deterministic: `ship`, `iterate`, `stop`.
- Confirm recommendation cards include issue, action, expected metric shift, confidence, and decision metadata.

## API + Contract Validation
- Verify `fetchOrganizerHome()` handles partial intelligence payloads without crashing.
- Verify mapper defaults return empty arrays when intelligence fields are unavailable.
- Verify fixture parity in `MOCK_ORGANIZER_HOME` when live API is disabled.
- Verify no new mobile write paths were added for financial or Stripe operations.

## Organizer Mobile UX Validation
- Organizer Home shows:
  - active experiment card,
  - guardrail chips,
  - top recommendation with clear action CTA.
- Recommendation action routes to in-app organizer screens when supported.
- Unsupported deep actions continue to hand off to LNKup web URLs.

## Telemetry Validation (Non-PII)
- Confirm events emit on mobile organizer flow:
  - `organizer_experiment_viewed`
  - `organizer_experiment_recommendation_opened`
  - `organizer_experiment_recommendation_action_clicked`
  - `organizer_experiment_decision_recorded`
- Confirm metadata contains only segment/stage/variant/confidence/decision identifiers.
- Confirm blocked PII keys are sanitized and never sent.

## Automated Validation
- Run consolidated mobile verification command:
  - `npm run phase20:verify`
- Run mobile type safety check:
  - `cd lnk-up-marketplace-mobile && npm run typecheck`
- Spot-check organizer home behavior in Expo web/native preview:
  - `cd lnk-up-marketplace-mobile && npm run web`
  - sign in as organizer, verify intelligence section render and action navigation.

## Rollout Guardrails
- Roll out with existing env gating (`EXPO_PUBLIC_LNKUP_LIVE_API`) and monitor fallback behavior.
- If intelligence payload shape varies across environments, maintain app stability via default mappers and fix upstream contract serialization.
- If telemetry noise spikes, confirm source tags and sanitize metadata before adjusting event payload composition.

## Troubleshooting
- Missing intelligence section:
  - inspect `/intelligence/organizer/summary` response and mapper output.
- Recommendation CTA not routing:
  - verify `funnelStage` mapping and fallback URL handling.
- Event track failures:
  - verify `/marketplace/track/event` accessibility/auth and mobile network availability.

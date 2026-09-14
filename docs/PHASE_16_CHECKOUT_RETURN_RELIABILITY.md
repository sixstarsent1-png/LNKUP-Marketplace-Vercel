# Phase 16 RFC — Checkout Return Reliability + Recovery

## Why this phase

Phase 14 solved handoffs; this phase hardens the failure/recovery edges without embedding Stripe in the app.

## Scope

- Improve post-checkout return reconciliation (`success`, `cancelled`, `processing`).
- Add idempotent resume logic for interrupted return flows.
- Tighten webhook-lag handling and user-visible “processing” windows.
- Improve receipt visibility and retry guidance.

## Out of scope

- No Stripe SDK or Payment Element in app.
- No payout/refund writes from app.

## Key deliverables

- Unified `payment_return_state` machine shared by requests/pass/wallet views.
- Retry-safe status fetch wrappers with jittered backoff.
- One analytics chain per checkout (`handoff_clicked` -> `return_landed` -> `confirmed` or `abandoned`).

## Success metrics

- Higher payment return completion rate.
- Lower “paid but not visible” support tickets.
- Faster median time from return to paid-state confirmation.


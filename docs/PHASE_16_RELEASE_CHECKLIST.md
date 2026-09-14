# Phase 16 Release Checklist

Gate reference: [PHASE_15_20_QA_GATE.md](PHASE_15_20_QA_GATE.md)

## Scope Verification
- Confirm return states are unified across `vendor/requests`, `vendor/wallet`, and `vendor/pass/[applicationId]`.
- Confirm URL params (`payment`, `applicationId`, `pay`, `paymentId`) are normalized and cleared after capture.
- Confirm no payment processing logic moved out of WordPress Financial OS.

## Functional Checks
- Success return: `?payment=success` reconciles to paid and shows pass/receipt CTA.
- Processing return: delayed webhook keeps processing banner, shows last/next retry, and manual retry works.
- Cancelled return: `?payment=cancelled` shows retry-payment CTA.
- Timeout path: unresolved payment emits timeout UX and does not hard-fail the page.

## API/BFF Checks
- `GET /api/vendor/requests/[id]/payment` returns deterministic `code` values:
  - `payment_processing`
  - `payment_confirmed`
  - `payment_state_unknown`
- Missing `paymentId` returns `202` with `payment_processing`.
- Auth/forbidden behavior remains normalized via existing error helpers.

## Telemetry Checks
- Validate event emission for:
  - `payment_return_landed`
  - `payment_return_reconcile_started`
  - `payment_return_confirmed`
  - `payment_return_timeout`
  - `payment_return_manual_retry`
- Validate metadata includes only non-PII fields (`payment`, `duration_bucket`, `manual_retry`).

## Rollout Guardrails
- Deploy to staging first and monitor return funnel conversion for 24h.
- Track support tickets tagged “paid but pending” pre/post deploy.
- Roll back by disabling live API toggle if upstream instability occurs.

## Troubleshooting
- If processing banner never resolves, inspect `/api/vendor/requests/[id]/payment` response `code` and `detail.status`.
- If return banner does not appear, verify query includes `payment` and `applicationId` or `pay`.
- If analytics appears missing, inspect `/api/analytics/track` requests and event allowlist.


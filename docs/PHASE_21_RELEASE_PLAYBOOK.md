# Phase 21 Release Playbook

## Objective
Operationalize Phase 15-20 release confidence using one deterministic quality gate and a standard incident response path.

Primary gate document: [PHASE_15_20_QA_GATE.md](PHASE_15_20_QA_GATE.md)

## Preflight
- Confirm repository dependencies are installed:
  - `npm ci`
  - `cd lnk-up-marketplace-mobile && npm ci`
- Confirm Playwright browsers installed:
  - `npx playwright install --with-deps chromium`
- Confirm env prerequisites for app and mobile QA contexts.

## Standard Gate Run
1. `npm run lint`
2. `npm run phase20:verify`
3. `npx playwright test tests/e2e/lnkup-app-phase16-return-reliability.spec.ts tests/e2e/lnkup-app-phase17-organizer-crm-depth.spec.ts tests/e2e/lnkup-app-phase18-readiness-reliability.spec.ts tests/e2e/lnkup-app-phase19-intelligence-experiments.spec.ts tests/e2e/lnkup-app-phase21-tutorials.spec.ts --project=lnkup-app`
4. `npx playwright test tests/e2e/lnkup-app-phase14-analytics.spec.ts --project=lnkup-app`
5. `npm run phase15-20:report`

## Tutorial Verification (Phase 21)
- Vendor home (`/vendor`) shows `vendor-home-tutorial` with step CTAs when tutorial is incomplete.
- Organizer home (`/organizer`) shows `organizer-home-tutorial` with step CTAs when tutorial is incomplete.
- Tutorial lifecycle events are tracked: `tutorial_viewed`, `tutorial_step_clicked`, `tutorial_step_completed`.

## Next phase
- Execute [PHASE_22_PRODUCTION_LAUNCH_HARDENING.md](PHASE_22_PRODUCTION_LAUNCH_HARDENING.md)
- Queued roadmap: [PHASES_22_26_ROADMAP.md](PHASES_22_26_ROADMAP.md)

## Decision Rubric
- **Go**
  - All required commands succeed.
  - No critical/high blockers in report output.
- **Conditional Go**
  - Only medium/low blockers with explicit mitigation and owner.
- **No-Go**
  - Any command fails.
  - Any critical/high blocker present.

## Incident Matrix

| Signal | Likely Cause | Immediate Action | Escalation |
|---|---|---|---|
| Playwright fails with browser executable missing | Browser not installed in runner | Run `npx playwright install --with-deps chromium` | CI workflow owner |
| Organizer intelligence tests skip/fail broadly | Feature/env gating mismatch | Verify `ENABLE_OPS_ANALYTICS`, live API flags, auth context | App platform owner |
| Payment return tests fail | BFF/payment state drift | Inspect `/api/vendor/requests/[id]/payment` code/status payloads | Financial/BFF owner |
| Readiness tests fail | Contract mapping regression | Validate `readinessTier`, `riskBucket`, `blockers`, urgency fields | Vendor reliability owner |
| Mobile verify fails | Missing parity snippets or type drift | Run `npm run phase20:verify` in mobile and inspect output | Mobile owner |
| Analytics event checks fail | Event whitelist/mapping drift | Verify allowed events and metadata sanitization paths | Analytics owner |

## Troubleshooting Checklist
- Check `test-results/` for `error-context.md` files.
- Inspect `artifacts/phase15-20-gate-report.md` and `.json`.
- Validate route contracts in release checklist docs for phases 16-20.
- Re-run failing suite in isolation before patching:
  - `npx playwright test <spec> --project=lnkup-app`

## Rollback Guidance
- If gate fails after deploy, revert to last known good build and disable newly introduced gated surfaces where applicable.
- Keep Financial OS boundaries unchanged; do not introduce emergency mobile/web Stripe write behavior.
- Record blocker, owner, mitigation ETA, and retest command in release notes.

## Post-Release Monitoring (24h)
- Monitor payment-return conversion and timeout rates.
- Monitor organizer request throughput and review action completion.
- Monitor readiness blocker completion rate and false-positive reports.
- Monitor intelligence fallback/partial-data frequency.
- Monitor mobile organizer intelligence interaction events for expected baseline volume.

# Phase 15-20 QA Gate

> **Superseded for cutover:** use [PHASE_15_22_QA_GATE.md](PHASE_15_22_QA_GATE.md) (includes Phase 21 tutorials + Phase 22 launch smoke). This file remains for historical Phase 15–20 command matrix.

## Purpose
Provide one deterministic go/no-go gate for App Phases 15-20 (Admin Lite through Mobile Organizer Intelligence Parity), with repeatable commands, artifact expectations, and blocker classification.

## Required Environment
- Node 20+
- Dependencies installed with `npm ci`
- Playwright browsers installed (`npx playwright install --with-deps chromium`)
- App runtime available at `PLAYWRIGHT_APP_URL` (defaults to `http://localhost:3000`)
- Optional live API envs for non-mock smoke:
  - `NEXT_PUBLIC_LNKUP_LIVE_API`
  - `WORDPRESS_API_URL`
  - `SESSION_SECRET`

## Gate Commands

### 1) Static safety
```bash
npm run lint
cd lnk-up-marketplace-mobile && npm run typecheck
```

### 2) Phase suites (web app)
```bash
npx playwright test tests/e2e/lnkup-app-phase16-return-reliability.spec.ts --project=lnkup-app
npx playwright test tests/e2e/lnkup-app-phase17-organizer-crm-depth.spec.ts --project=lnkup-app
npx playwright test tests/e2e/lnkup-app-phase18-readiness-reliability.spec.ts --project=lnkup-app
npx playwright test tests/e2e/lnkup-app-phase19-intelligence-experiments.spec.ts --project=lnkup-app
npx playwright test tests/e2e/lnkup-app-phase21-tutorials.spec.ts --project=lnkup-app
```

### 3) Cross-check analytics regression
```bash
npx playwright test tests/e2e/lnkup-app-phase14-analytics.spec.ts --project=lnkup-app
```

### 4) Phase 20 mobile verification track
```bash
cd lnk-up-marketplace-mobile && npm run phase20:verify
```

### 5) Deterministic summary report
```bash
node scripts/qa/phase15-20-gate-report.mjs
```

## Minimum Pass Criteria
- All required commands exit with `0`.
- No failed Phase 16-20 suite in `lnkup-app` project.
- No failed mobile verification check.
- No TypeScript failures in mobile.
- Report output generated at:
  - `artifacts/phase15-20-gate-report.md`
  - `artifacts/phase15-20-gate-report.json`

## Allowed Skips
- Environment-gated tests may skip when a route is intentionally hidden by feature flags.
- Any skip must appear in report output with one of:
  - `env_gated`
  - `feature_disabled`
  - `unsupported_runtime`

## Blocker Severity Taxonomy
- `critical`:
  - payment return reliability regressions (Phase 16)
  - organizer review/action regressions (Phase 17)
  - role-gate/capability leaks
- `high`:
  - readiness risk or blocker mapping regressions (Phase 18)
  - intelligence partial-data handling failures (Phase 19/20)
- `medium`:
  - telemetry event regression without UX break
  - flaky test requiring rerun but with eventual pass
- `low`:
  - cosmetic copy/state mismatch not affecting actionability

## Go/No-Go Rule
- **Go**: no `critical`/`high` blockers and all required commands pass.
- **Conditional Go**: only `medium`/`low` blockers with documented mitigation.
- **No-Go**: any `critical` blocker, or any required command fails.

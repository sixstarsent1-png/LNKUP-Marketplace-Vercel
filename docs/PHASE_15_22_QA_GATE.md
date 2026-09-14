# Phase 15–22 QA Gate

## Purpose
Deterministic go/no-go gate for App Phases 15–22 (Admin Lite through Production Launch Hardening), including tutorials and launch smoke.

Supersedes the Phase 15–20-only framing while keeping the same artifact paths for CI continuity.

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
npx playwright test \
  tests/e2e/lnkup-app-phase16-return-reliability.spec.ts \
  tests/e2e/lnkup-app-phase17-organizer-crm-depth.spec.ts \
  tests/e2e/lnkup-app-phase18-readiness-reliability.spec.ts \
  tests/e2e/lnkup-app-phase19-intelligence-experiments.spec.ts \
  tests/e2e/lnkup-app-phase21-tutorials.spec.ts \
  tests/e2e/lnkup-app-phase22-launch-smoke.spec.ts \
  --project=lnkup-app
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
npm run phase15-20:report
# or
npm run phase22:report
```

## Minimum Pass Criteria
- All required commands exit with `0`.
- No failed Phase 16–22 suite in `lnkup-app` project.
- No failed mobile verification check.
- No TypeScript failures in mobile.
- Report output generated at:
  - `artifacts/phase15-20-gate-report.md`
  - `artifacts/phase15-20-gate-report.json`
- Live-API audit present: `artifacts/phase22-live-api-audit.md`

## Operator cutover (Phase 22)
- Vercel: [PHASE_22_OPERATOR_VERCEL.md](PHASE_22_OPERATOR_VERCEL.md)
- Expo: [PHASE_22_OPERATOR_EXPO.md](PHASE_22_OPERATOR_EXPO.md)
- Release checklist: [PHASE_22_RELEASE_CHECKLIST.md](PHASE_22_RELEASE_CHECKLIST.md)

## Allowed Skips
- Environment-gated tests may skip when a route is intentionally hidden by feature flags.
- Any skip must appear in report output with one of:
  - `env_gated`
  - `feature_disabled`
  - `unsupported_runtime`

## Blocker Severity Taxonomy
- `critical`: payment return (16), organizer review (17), role-gate leaks
- `high`: readiness (18), intelligence partial-data (19/20), dishonest live KPI bleed (22)
- `medium`: telemetry without UX break; flaky then pass
- `low`: cosmetic copy not affecting actionability

## Go/No-Go Rule
- **Go**: no `critical`/`high` blockers and all required commands pass; operator checklists signed.
- **Conditional Go**: only `medium`/`low` blockers with documented mitigation.
- **No-Go**: any `critical` blocker, or any required command fails.

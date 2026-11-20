# Phase 22 Release Checklist

Gate references:
- [PHASE_22_PRODUCTION_LAUNCH_HARDENING.md](PHASE_22_PRODUCTION_LAUNCH_HARDENING.md)
- [PHASE_15_22_QA_GATE.md](PHASE_15_22_QA_GATE.md)
- [PHASE_21_RELEASE_PLAYBOOK.md](PHASE_21_RELEASE_PLAYBOOK.md)
- Audit: [artifacts/phase22-live-api-audit.md](../artifacts/phase22-live-api-audit.md)

## Scope
- [x] Live-API residual audit complete
- [x] Priority surfaces wired, gated, or documented intentional
- [x] Admin summary no longer bleeds mock KPIs under live flag
- [x] Demo notices on growth, profile, admin money/users/events/overview fixtures
- [x] Critical-path BFF codes (`admin_summary_partial`, `admin_health_degraded`, existing 16–21)
- [x] Docs synced (SYSTEM_OVERVIEW §14.2, CURRENT_STATE, gap analysis) — Phase 22 Built on completion
- [x] QA gate includes Phase 21 tutorials + Phase 22 launch smoke
- [x] Vercel operator checklist published
- [x] Expo operator checklist published

## Commands
```bash
npm run lint
cd lnk-up-marketplace-mobile && npm run phase20:verify && cd ..
npx playwright test \
  tests/e2e/lnkup-app-phase16-return-reliability.spec.ts \
  tests/e2e/lnkup-app-phase17-organizer-crm-depth.spec.ts \
  tests/e2e/lnkup-app-phase18-readiness-reliability.spec.ts \
  tests/e2e/lnkup-app-phase19-intelligence-experiments.spec.ts \
  tests/e2e/lnkup-app-phase21-tutorials.spec.ts \
  tests/e2e/lnkup-app-phase22-launch-smoke.spec.ts \
  --project=lnkup-app
npx playwright test tests/e2e/lnkup-app-phase14-analytics.spec.ts --project=lnkup-app
npm run phase22:report
```

## Go / No-Go
- **Go:** all required commands pass; no critical/high blockers; operator checklists signed for cutover
- **Conditional Go:** only medium/low blockers with owner + mitigation ETA
- **No-Go:** any critical blocker, missing prod env, or gate failure

## Monitoring (24h post-cutover)
- Payment return conversion / timeout rate
- Tutorial view → step click conversion (web)
- Intelligence / readiness partial-data rate
- Admin Lite health check freshness / partial reasons
- Mobile OTA adoption / crash-free sessions
- Support tickets tagged “demo numbers shown as live”

## Gate run — 2026-09-29

**Decision: No-Go.** Do not promote Vercel or publish a production Expo update from this run.

| Check | Result |
|-------|--------|
| `npm run lint` | Fail. `eslint` is not installed for the root `eslint .` script. |
| `npm run phase20:verify` | Fail. `app/login.tsx` line 97: `"/signup"` is not in the Expo typed-route union (`TS2322`). |
| Playwright `lnkup-app` specs in the command block above | Not run. Nothing was listening on `http://localhost:3000`, and the dev server did not start in this environment. |
| `npm run phase22:report` | Wrote `artifacts/phase15-20-gate-report.md`. That report is **no_go** because 28 older Playwright failure contexts are still in `test-results`. It does not record this run’s lint or typecheck. |

Operator checklists stay unsigned:

- [PHASE_22_OPERATOR_VERCEL.md](PHASE_22_OPERATOR_VERCEL.md): env names and production smokes were not confirmed. `vercel env ls` did not return a listing. Secret values were not read.
- [PHASE_22_OPERATOR_EXPO.md](PHASE_22_OPERATOR_EXPO.md): production channel, live-API flag, WordPress URL, OTA publish, and device smokes were not confirmed. `phase20:verify` is not green.

Mitigation before a retry: install or wire root ESLint, fix the `/signup` typed route, start `lnk-up-marketplace-ui` on port 3000, rerun the Playwright list, then sign the operator lists from the Vercel and Expo dashboards.

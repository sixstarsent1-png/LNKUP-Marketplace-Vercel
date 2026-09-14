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

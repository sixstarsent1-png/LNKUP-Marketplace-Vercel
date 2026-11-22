# Phase 27 Release Checklist — Vendor Passport & Reputation OS

## Before deploy
- [ ] `node scripts/phase27-verify.mjs`
- [ ] `cd lnk-up-marketplace-mobile && npm run phase27:verify && npx jest`
- [ ] Local wp-env: `npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/vendor-passport-test.php` → `ALL PASSED`
- [ ] Regression suites: `matching-test`, `matching-alerts-test`, `booking-application-os-test`, `trust-safety-test`, `comms-*`, `portfolio-os-test`, `organizer-os-test`
- [ ] Playwright: seed `vendor-passport-seed.php`, then `PLAYWRIGHT_PASSPORT_SLUG=demo-tacos npx playwright test tests/e2e/vendor-passport.spec.ts`
- [ ] Nginx/IIS hosts: deny `/wp-content/uploads/lnkup-private/` at the web server (see PHASE_27_VENDOR_PASSPORT.md §6)
- [ ] Back up the database (new tables are additive; no data is deleted)

## Deploy (WordPress)
- [ ] Ship `lnkup-marketplace` + `lnkup-communications`; DB upgrade runs on `init` (creates 3 tables)
- [ ] Load wp-admin once (runs `LNKup_Passport::maybe_migrate` backfills); confirm **Vendor Passport & Trust → System health → Data migration: Complete**
- [ ] Visit Settings → Permalinks once if `/vendors/{slug}` 404s (rewrite flush is automatic on first load)
- [ ] 27A fixes are live immediately: spot-check `GET /wp-json/lnkup/v1/reputation/vendors/1` → 401 anonymous; public vendor page by slug still renders

## Staged enablement (wp-admin → LNKup → Vendor Passport & Trust → Settings)
1. [ ] `passport_enabled` (dashboard Passport view, `/passport/*` routes, theme nav)
2. [ ] `passport_history_v2` (achievements on Passport)
3. [ ] `review_responses`
4. [ ] `passport_verification_v2` (vendor verification requests)
5. [ ] `document_vault_sharing` (apply-flow reuse + organizer shares)
6. [ ] `matching_reputation_signals` (after reviewing match diagnostics)
7. [ ] Communications → Automations → enable `flow_passport`
8. [ ] Document migration: review dry run, then **Start migration**; watch the log until "complete"

## Mobile (Expo)
- [ ] No new native modules → OTA update is sufficient (`npm run update:preview`, then production)
- [ ] Verify Profile tab → **Vendor Passport**; push deep link `/(vendor)/passport?tab=reviews`
- [ ] Organizer vendor detail shows the Passport panel (indicators, confirmed events, tour invites)

## Next.js (Vercel)
- [ ] Deploy `lnk-up-marketplace-ui` (public reputation mapper, review response route)
- [ ] Existing unrelated typecheck errors are pre-existing (not introduced by Phase 27)

## Rollback
- [ ] Turn Passport flags and `flow_passport` off; pause the document migration
- [ ] Code rollback is safe: tables/meta are additive and legacy meta stays in sync

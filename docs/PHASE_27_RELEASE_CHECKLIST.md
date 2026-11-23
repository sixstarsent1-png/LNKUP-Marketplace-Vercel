# Phase 27 Release Checklist — Vendor Passport & Reputation OS

Production run: 2026-11-22 (thelnkup.com, SiteGround). Notes in *italics* record what happened.

## Before deploy
- [x] `node scripts/phase27-verify.mjs`
- [x] `cd lnk-up-marketplace-mobile && npm run phase27:verify && npx jest` *(163/163, `tsc` clean)*
- [x] Local wp-env: `npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/vendor-passport-test.php` → `ALL PASSED`
- [x] Regression suites: `matching-test`, `matching-alerts-test`, `booking-application-os-test`, `trust-safety-test`, `comms-*`, `portfolio-os-test`, `organizer-os-test` *(all 37 PHP suites pass after `wp-env clean all`)*
- [x] Playwright: seed `vendor-passport-seed.php`, then `PLAYWRIGHT_PASSPORT_SLUG=demo-tacos npx playwright test tests/e2e/vendor-passport.spec.ts` *(20/20, Chrome + iPhone WebKit)*
- [x] Fresh install creates the Passport, Matching and Trust tables *(was broken; fixed in PR #4)*
- [ ] Nginx/IIS hosts: deny `/wp-content/uploads/lnkup-private/` at the web server (see PHASE_27_VENDOR_PASSPORT.md §6) *(SiteGround: a probe file was served with 200 because NGINX Direct Delivery bypasses `.htaccess`. Turn it off in Site Tools → Speed → Caching, then re-probe. Blocks steps 5 and 8.)*
- [x] Back up the database *(`~/lnkup-backups/thelnkup-pre-p27-flags-20261122T192453Z.sql.gz` on the server, outside the web root)*

## Deploy (WordPress)
- [x] Ship `lnkup-marketplace` + `lnkup-communications` *(deployed 18:56 UTC from the committed tree; production already had `lnkup_db_version` 12.32.0, so the upgrade routine did not run — tables came from `LNKup_Passport::maybe_migrate`)*
- [x] Load wp-admin once; confirm **Data migration: Complete** *(`lnkup_passport_migration_version` = 1)*
- [x] `/vendors/{slug}` returns 200; legacy `?vendor=` returns 301
- [x] 27A fixes are live: `GET /wp-json/lnkup/v1/reputation/vendors/1` → 401 anonymous; public vendor page by slug renders without email or score
- [x] *Ops: 8 GB `wp-content/debug.log` had hit the account file-size limit. Rotated to `debug-2026-11-22.log`; `WP_DEBUG_LOG` and `WP_DEBUG_DISPLAY` set to false (errors go to `php_errorlog`, 403 over HTTP); stale `lnkup-marketplace-12.24.0-escrowfix-*` plugin folder moved to `~/lnkup-backups/`.*

## Staged enablement (wp-admin → LNKup → Vendor Passport & Trust → Settings)
Do not run `wp cache flush` between steps: a full object-cache flush made cold homepage requests take 20–55 s; the flag changes themselves did not change response times.
1. [x] `passport_enabled` *(19:42 UTC)*
2. [x] `passport_history_v2` *(19:43 UTC)*
3. [x] `review_responses` *(19:43 UTC)*
4. [x] `passport_verification_v2` *(19:44 UTC; anonymous GET/POST `/passport/me/verification` → 401)*
5. [ ] `document_vault_sharing` *(blocked: private uploads still public)*
6. [ ] `matching_reputation_signals` (after reviewing match diagnostics) *(wait ~24 h after step 4)*
7. [ ] Communications → Automations → enable `flow_passport` *(wait ~24 h after step 4)*
8. [ ] Document migration: review dry run, then **Start migration**; watch the log until "complete" *(blocked: private uploads still public)*

## Mobile (Expo)
- [ ] No new native modules → OTA update is sufficient (`npm run update:preview`, then production)
- [ ] Verify Profile tab → **Vendor Passport**; push deep link `/(vendor)/passport?tab=reviews`
- [ ] Organizer vendor detail shows the Passport panel (indicators, confirmed events, tour invites)

## Next.js (Vercel)
- [ ] Deploy `lnk-up-marketplace-ui` (public reputation mapper, review response route) *(Vercel preview builds currently fail on every open PR — investigate before relying on it)*
- [x] Existing unrelated typecheck errors are pre-existing (not introduced by Phase 27)

## Rollback
- Turn Passport flags and `flow_passport` off; pause the document migration. On production: `wp eval-file ~/lnkup-backups/p27-set-flags.php off` (every flag not listed is saved off).
- Code rollback is safe: tables/meta are additive and legacy meta stays in sync.

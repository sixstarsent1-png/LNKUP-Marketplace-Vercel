# 09 — Test and verification evidence

All commands were run on 2026-11-23 against this checkout (`redesign/event-product` @ `461f3e3` + uncommitted changes) unless noted. Nothing touched production state.

## Commands executed in this audit

| Check | Command / method | Result |
|---|---|---|
| PHP syntax, both plugins + theme | `php -l` on every first-party `.php`, PHP 8.1 from the existing local wp-env CLI image, source mounted **read-only** | **582 files, 0 failures** |
| PHP 8-only syntax vs "Requires PHP 7.4" | grep for `?->`, `match(`, union/`mixed` types, `str_*` | none, except `str_contains` family (polyfilled by WordPress 5.9+) |
| Expo typecheck | `npx tsc --noEmit` in `lnk-up-marketplace-mobile` | **0 errors** |
| Expo unit tests | `npx jest --ci` | **25 suites, 200 tests passed** |
| Next.js typecheck | `npx tsc --noEmit -p lnk-up-marketplace-ui` | **18 errors** (e.g. `lib/api/saved-searches.ts` L99-100, `lib/api/vendor-financial.ts` L191/250/422, `lib/hooks/use-marketplace-rails.ts` L161). Master's PR #7 fixed these; they are not on this line |
| PHP integration suites | `wp eval-file` in the local wp-env that mounts this checkout (port 8892). Its database had **no Stripe keys**, and the selected scripts make no outbound HTTP calls | **18/18 suites passed, 0 failures**, listed below |
| Committed secrets | `git grep` HEAD for Stripe/AWS/Google/Slack keys and private keys | none |
| CI history | `gh run list` | Deploy workflow: 2 runs, 2 failures (missing SSH key). Quality gates: pass on `ci/fix-quality-gates` and `phase/1-release-checks`, fail on several feature branches |
| Vercel | GitHub deployments API | Previews succeed for every push; last **Production** deployment 2026-07-01 |
| App Store | `itunes.apple.com/lookup?id=6795865859` | Version 1.0, current version released 2026-10-14 |
| Play Store | `play.google.com/store/apps/details?id=com.lnkup.marketplace` | 404 |

### PHP suites (local wp-env, Stripe off)

| Suite | Checks passed |
|---|---|
| financial-schema-check | 6 |
| financial-reconciliation-test | 25 |
| financial-sync-test | 37 |
| financial-contract-test | 49 |
| financial-lists-test | 14 |
| financial-repair-test | 29 |
| financial-cron-test | 9 |
| financial-security-test | 12 |
| financial-side-effects-test | 12 |
| financial-checkout-test | 45 |
| financial-refund-dispute-test | 22 |
| financial-cashout-test | 22 |
| financial-phase2-test | 41 |
| financial-fee-backfill-test | ALL PASSED |
| booking-application-os-test | 73 |
| organizer-fee-privacy-test | 25 |
| reapply-policy-test | 37 |
| text-and-links-test | 22 |

## Production checks (read-only, unauthenticated)

| Probe | Result |
|---|---|
| Asset versions | plugin 12.33.4, theme 1.6.1 + `experience.css` |
| M-only plugin files | `explore-ux.js` 404, `vendor-bookings.js` 404 |
| `wp/v2` private types | application, payment, document, vendor_profile, tour → 404 |
| `wp/v2/media` | 417 total; PDF 0; JPEG 289; PNG 123 |
| `wp/v2/users` | 200, 14 users |
| `/uploads/lnkup-private/<nonexistent>` | 403 (deny active) vs `/uploads/2026/11/<nonexistent>` 404 |
| `/markets/{charlotte,atlanta,houston}/` | 404 |
| Event page SEO | canonical, index, JSON-LD Event/Place/PostalAddress; **no og:image** |
| Sitemaps | demo products and pages, author sitemap, app pages |
| Deep-link files | `/.well-known/assetlinks.json` 404; `/.well-known/apple-app-site-association` 404; root AASA 200 (vendors/organizers only) |
| Find Events | 117 cards, no low-contrast buttons, no console errors |
| Home | mixed-content error (ThemeIsle CSS over http) |
| XML-RPC | blocked at host |

## Repo evidence relied on (not re-run here)

| Source | Claim | Reliability note |
|---|---|---|
| `docs/BUILDOUT_CHECKLIST.md` (M) | 54 PHP suites / 2,037 checks pass; Playwright web 437 pass / 121 fail / 96 skip; Expo web journeys | Recorded on `phase/6-partners-rollout`, not on the production line. The 121 failures are explained as stale specs and mock-mode specs run against live mode |
| `docs/ORGANIZER_AUDIT_MATRIX.md` | Organizer fixes "Fixed (runtime)" | wp-env runtime, not production |
| `docs/VENDOR_AUDIT_MATRIX.md` | Vendor fixes | **32 rows still "pending runtime", 0 marked runtime-verified, on both lines.** The `booking-application-os-test` and financial suites run here cover much of that ground (apply locks, transitions, checkout binding, credits), but the matrix was never updated |

## Not tested (blocked or out of scope)

| Area | Why | Needed |
|---|---|---|
| Stripe test-mode card payments, Connect onboarding, payouts | No Stripe keys in any local env; no staging site | Staging WordPress with Stripe test keys + Connect test account |
| Push delivery and tap-through | Device-only | Physical iOS/Android + EAS build |
| iOS in-app checkout return, universal links, camera | Device-only | Physical devices |
| Email delivery | No provider configured; local env has no mail | Provider + seed inbox |
| Full Playwright suites | Need a seeded, isolated wp-env per line; suites consume one-spot fixtures | Run per line after reseeding (`seed.php`, `seed-portfolio.php`, `seed-organizer-os.php`, `seed-onboarding.php`) |
| Production feature-flag values | Need wp-admin | Owner to export settings |

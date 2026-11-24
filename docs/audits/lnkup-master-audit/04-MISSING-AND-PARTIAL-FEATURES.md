# 04 — Missing, partial and broken features (consolidated inventory)

Each item names the system, current state, exact work, dependencies, risk, priority (P0–P3), effort (S/M/L/XL) and evidence. "H" = production plugin line (`redesign/event-product`), "M" = `origin/master`.

## A. Release and integration (affects everything)

| ID | System | Feature | Current state | Missing implementation | Depends on | Risk | Pri | Effort | Evidence |
|---|---|---|---|---|---|---|---|---|---|
| A1 | All | One production revision | H plugin + M theme live; both lines labelled 12.33.x | Merge H's 4 commits (12.33.1–4: fee privacy, plain titles, signed links, decode-entities) into M, or rebase. Re-version (12.35.0). Deploy plugin + theme + comms together from one tag | — | Phases 1–7 fixes not live; regressions on next manual upload | P0 | M | `git rev-list --left-right --count HEAD...origin/master` = 4/39; production asset probes (01) |
| A2 | All | Automated WordPress deploy | Workflow failed both runs: `ssh-private-key argument is empty` | Add `SG_SSH_KEY`, `SG_SSH_HOST`, `SG_SSH_PORT`, `SG_SSH_USER`, `SG_WP_ROOT` secrets; add a staging target; deploy from tags only | A1 | Manual zips drift (already happened) | P0 | S | `gh run list --workflow "Deploy WordPress to SiteGround"` |
| A3 | All | Record production flag state | 40+ flags, all default off; live values unknown | Add a read-only "Flags" admin screen / `wp lnkup flags list` that prints every flag from `LNKup_Feature_Flags`, `LNKup_Rollout`, Match, Passport, Trust, Org Growth and Comms settings; snapshot it into docs on each release | — | Nobody can say what is live | P0 | S | 01 flag table |
| A4 | Mobile | App Store users on current JS | Store binary 1.0 (2026-10-14); OTA runtime 1.0.1 | Confirm runtimes in EAS; build and submit a 1.0.1 (or 1.1.0) binary; then OTA | A1 | Users run October JS | P0 | M | Apple lookup id 6795865859; `app.json` `runtimeVersion.policy appVersion` |
| A5 | Mobile | Android release | Not on Play Store; submit track internal/draft | Complete Play listing, data-safety form, production track | A4, B5 | No Android users | P1 | M | Play 404; `eas.json` |
| A6 | Next.js | Production web app current | Last Production deploy 2026-07-01 | Decide whether Next.js is a product surface. If yes, promote a build to Production and set `ENABLE_OPS_ANALYTICS` etc.; if no, unpublish it | — | Stale app at a public URL | P1 | S | GitHub deployments API |

## B. Broken today (production line)

| ID | System | Feature | Current state | Fix | Risk | Pri | Effort | Evidence |
|---|---|---|---|---|---|---|---|---|
| B1 | Organizer mgmt | Edit booth / parking from operations | Fails with "Event not found" when the JSON body has an `id` | Ship M's `LNKup_Operations_REST::route_id()` (37 call sites) and audit other REST classes for `$request['id']` with JSON bodies | Event-day setup breaks | P0 | S (M has fix) | `git diff HEAD origin/master -- includes/class-operations-rest.php` |
| B2 | Organizer mgmt | Floor-plan upload (maps) | Posts to `wp/v2/media`; organizers lack `upload_files` | Owner-checked `POST /maps/{id}/floor-plan` (image only, size cap, event ownership); update `maps.js` | Visual layouts unusable | P1 | S | `public/js/maps.js` L391; ORGANIZER_AUDIT_MATRIX O25 |
| B3 | SEO | City market pages | `/markets/{charlotte,atlanta,houston}/` → 404 | Check `market_pages_enabled`; flush rewrites; add market URLs to sitemap (code exists: `append_market_sitemap_urls`) | Organic city traffic lost | P1 | S | production curl |
| B4 | SEO/brand | Demo content indexed | `/fitness-trainer-shop/`, `/my-services/` (index, 200) + 3 demo products in sitemap; dashboards and settings pages in page sitemap | Delete demo pages/products; noindex + exclude app pages; drop `product-sitemap`, `author-sitemap` | Brand and crawl quality | P1 | S | production sitemaps |
| B5 | Mobile | Android App Links / iOS event links | `/.well-known/assetlinks.json` 404; AASA only `/vendors`, `/organizers` | Serve `assetlinks.json` (SHA-256 of the signing cert); serve AASA at `/.well-known/` with `/opportunities/*` and `/lnkup_event/*` paths | Shared links open in browser | P1 | S | production curl |
| B6 | Analytics | Next.js admin Money / Events / Users | Hardcoded sample data, "revenue = GMV × 0.1" | Wire to `/admin/summary`, financial and user routes, or remove pages | Staff decisions on fake numbers | P1 | M | `lnk-up-marketplace-ui/app/admin/money/page.tsx` L12-22 |
| B7 | Public site | Mixed content | `http://…/themeisle-gutenberg/post-v2-77-*.css` blocked on home | Remove the Otter/ThemeIsle block styles or force https | Console error, possible style loss | P3 | S | browser console |
| B8 | Next.js | Type errors | 18 `tsc` errors on H | Bring PR #7 fixes to the production line (A1) | Build break risk | P2 | S | 09 |

## C. Financial and data integrity

| ID | System | Feature | Current state | Missing implementation | Risk | Pri | Effort | Evidence |
|---|---|---|---|---|---|---|---|---|
| C1 | Financial | Invoice equals charged amount | Invoice total frozen at creation; payment re-priced at checkout | In `LNKup_Payments::refresh_pending_fees`, also update the pending invoice's `subtotal`, `total_amount` and items; add a test where fees/discount/method change between approval and pay | Invoice ≠ charge; `partially_paid` invoices for full payments | P1 | S | `class-invoices.php` L184-198; `class-payments.php` L339-349 |
| C2 | Financial | Vendor money contract completeness + receipt paging | Fixed on M, missing on H | Ship with A1 | Vendor totals wrong | P0 (via A1) | — | BUILDOUT Phase 2D; diff |
| C3 | Financial | Processing guard | `mark_payment_processing` skips only `paid` | Use the same settled list as the failed-event guard (`paid`, `refunded`, `partially_refunded`, `disputed`, …) | Late ACH event flips state | P2 | S | `class-financial-service.php` ~L124 |
| C4 | Financial | Real Stripe test-mode journey | Never run (local env has no keys; BUILDOUT says needs staging keys) | Staging site with Stripe test keys + Connect test account; run apply → approve → pay → webhook → refund → payout | Unknown live behavior | P0 | M | 09 |
| C5 | Partnerships | Commission economics | City Connector default = 10% of booth GMV, uncapped, 12 months | Confirm live settings; change basis to `platform_fee` or set a cap; publish terms | LNKup loses money on attributed bookings ($300 booth: LNKup ≈ $23.99, connector $30) | P0 | S | `class-commission-rules.php` L36-50 |
| C6 | Analytics | Marketplace funnel + test exclusion | M only | Ship with A1 | Wrong activation metrics | P1 | — | `class-opportunity-funnel.php` (M) |
| C7 | Financial | Docs vs code reserve | FINANCIAL.md recommends reserve 5%; code default 0 | Record the live value; align docs | Payout expectations | P3 | S | FINANCIAL.md L172; `class-fee-engine.php` L384 |

## D. Security and privacy

| ID | Feature | Current state | Fix | Pri | Effort | Evidence |
|---|---|---|---|---|---|---|
| D1 | App sessions survive password reset | 90-day Bearer tokens in user meta; no revocation on reset/change | Hook `after_password_reset` and `profile_update` (password changed) → delete `_lnkup_mobile_auth_tokens`; add "sign out other devices" | P1 | S | `class-mobile-auth.php` L21; no hooks |
| D2 | Public user list | `wp/v2/users` → 200, 14 users | Restrict `/wp/v2/users` to logged-in users with `list_users`; remove author sitemap | P1 | S | production |
| D3 | Draft events on public REST | Hide filter M only | Ship with A1 | P1 | — | `rest_lnkup_event_query` (M `class-post-types.php` L17) |
| D4 | Document migration safety | Probe gate M only; production folder currently returns 403 | Ship probe with A1 before enabling `document_vault_sharing` or the migration; then migrate pre-12.20 documents out of `/uploads/YYYY/MM/` | P1 | M | 05 |
| D5 | Unescaped names in HTML | `external-events.js` L350, `marketplace-compare.js` L55 | Use existing escape helpers | P2 | S | static |
| D6 | Sign-up abuse | Only failed registrations count toward the limit | Count successes (e.g. 5/IP/hour); add honeypot to `/connector/interest`, `/partners/apply` | P2 | S | `class-auth-rest.php` L152-189 |
| D7 | Password trimmed on app login | `trim($password)` | Don't trim passwords | P3 | S | `class-auth-rest.php` L103 |
| D8 | Unsigned QR passes | Static random token | Signed tokens for new passes (keep old valid) | P2 | M | `class-pass-tokens.php`; Trust F-6 |
| D9 | Referral fraud | Same-user check only | Flag same email domain/phone/card fingerprint, organizer↔vendor self-booking; hold rules | P2 | M | `class-organizer-recruit-referrals.php` L428 |

## E. Product gaps (not built or partial)

| ID | System | Feature | Current state | Work needed (FE / BE / Mobile / DB) | Pri | Effort |
|---|---|---|---|---|---|---|
| E1 | Check-in | Camera scanning on iPhone | Web uses `BarcodeDetector` (no Safari); app has no camera | Mobile: add `expo-camera` scanner → `POST /operations/qr/scan` (needs native build). Web: JS QR fallback library | P1 | M |
| E2 | Check-in | Offline check-in | Error toast only | Mobile: queue scans locally, sync with idempotency; BE: accept timestamped batch | P2 | L |
| E3 | Support | Platform support tickets | Only event-day vendor→organizer requests + static help | BE: `support_cases` (reuse trust cases model) with categories (payment, refund, account, listing), assignment, SLA, notes; FE: "Get help" on web + app; comms triggers | P1 | L |
| E4 | Trust | Report a listing / user | No route | BE: `POST /reports`, feeds trust cases; FE/mobile: "Report" on event, organizer and vendor pages | P2 | M |
| E5 | Trust | Account restriction | Only partner suspension | BE: `_lnkup_account_status` (active/restricted/suspended) enforced in auth + apply + publish; admin UI | P2 | M |
| E6 | Org mgmt | Teams in apps | API only, flag off | Next + Expo team screens; turn `trust_org_teams` on for pilots | P2 | M |
| E7 | Org mgmt | Waitlist offers in apps | WP only, flagged | Expo/Next offer action + vendor accept screen | P2 | M |
| E8 | Booking | Booking modifications (change booth/date) | Not built | BE: modification request + price delta via Financial Service; UI both sides | P3 | L |
| E9 | Booking | Bookings Center | M only | Ship with A1; verify `vendor-bookings.js` live | P1 | — |
| E10 | Portfolio | Organizer tour tools in apps | WP only by design | Read-only tour overview in Expo organizer | P3 | M |
| E11 | Monetization | Premium listings, financing, insurance | Dead code / placeholders | Decide: remove from UI and code, or build checkout through Financial Service | P3 | S (remove) |
| E12 | Integrations | Organizer webhooks / API keys | Not built | Only if enterprise demand appears | P3 | L |
| E13 | SEO | Social images | No `og:image` on event pages | Use event cover (raster) via `lnkup_seo_raster_attachment_url` | P2 | S |
| E14 | Admin | One operations queue + flag overview | Ops queue M only; no flag screen | Ship M queue (A1) + A3 | P1 | S |
| E15 | Comms | Email deliverability | Plain `wp_mail` on SiteGround; no bounce webhook | Configure a provider (SES/Postmark/SendGrid) + `email_webhook_secret`; set `company_address` | P1 | S |
| E16 | Retention | Vendor win-back, loyalty | Organizer-side only | Vendor flows in comms automations | P3 | M |
| E17 | Acquisition | Sends through Communications | Outreach uses raw `wp_mail` | Route campaigns through the comms engine for suppression and tracking | P2 | M |

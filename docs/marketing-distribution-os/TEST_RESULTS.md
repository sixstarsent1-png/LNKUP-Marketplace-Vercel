# Test results

Environment: wp-env (WordPress 6.x, PHP 8.x) on Docker for Windows at `http://localhost:8888`, seeded with `scripts/e2e/seed.php`. Mail is captured and Expo push is stubbed in PHP suites; ad platform HTTP is intercepted. No message reached a real person and nothing was sent to Meta, Google or TikTok.

Final regression run: 2026-11-24, after the Phase 9 commit.

## PHP suites (`wp eval-file wp-content/lnkup-e2e/php/<suite>.php`)

| Suite | Checks | Result | Covers |
|---|---|---|---|
| `seo-policy-test` | 43 | Pass | Index policy, schema, sitemaps, URLs, Rank Math filters, diagnostics |
| `market-registry-test` | 60 | Pass | Market status/health, category eligibility, digests, alerts, demand, OG images, event notices, follow tour |
| `attribution-test` | 60 | Pass | Touch sanitizing, tracked links (open redirect, dedupe, bots, ownership), taxonomy, retention, GA4/TikTok gating and payloads, revenue attribution (duplicate fee rows, refunded fee net) |
| `organizer-marketing-test` | 35 | Pass | Workspace permissions (owner, team, other organizer), links, performance, embeds, local QR |
| `marketing-campaigns-test` | 43 | Pass | Marketing role caps, suppression bridge, segments, approval (two-person, regrowth), scheduling, experiments, exclusions, failure state |
| `distribution-test` | 29 | Pass | External interest → leads, claim notifications (dedupe), claim funnel, acceptance card/link, connector links (referral forwarding), partner pages |
| `retention-content-test` | 35 | Pass | Market alert + tour stop messages (dedupe), consent history, resources hub, checklist enforcement, cohort interest |
| `app-links-test` | 49 | Pass | Flag gating, AASA paths, resolver (events, markets, opportunities, tours, `/l/`), payload privacy, native share links, App Store tokens |
| `marketing-command-center-test` | 43 | Pass | Access per role, every tab renders for admin and marketing role, revenue gating, KPIs vs stored rows, filters, CSV safety, diagnostics, cohorts |
| Comms suites (13: schema, engine, lifecycle, preferences, push, organizer, activation, security, partners, policy, engagement, admin, admin render) | 414 | Pass | Regression for the shared communications engine |
| `org-growth-test` | 129 | Pass | Regression (leads, spend, cohort lead source) |
| `partnerships-programs-test` | — | Pass after update | Expected view lists now include Share & Track when `tracking_links` is on (intended Phase 6 behavior) |
| `tests/php/meta-integration-wp.php` | 64 | Pass | Regression (Meta outbox, consent, admin) |
| `tests/php/meta-measurement-test.php` | 95 | Pass | Regression (Meta unit) |
| `public-website-test` | 34 | **1 failure, not from this work** | "every card has open spots" fails because the concurrent session's uncommitted `class-public-discovery.php` now hides spot counts for gated visitors |

## Playwright (`tests/e2e/marketing-os-journeys.spec.ts`)

16/16 passed (desktop Chrome + iPhone 13). Env: `PLAYWRIGHT_ORGANIZER_USER/PASS`, `PLAYWRIGHT_VENDOR_USER/PASS`, `PLAYWRIGHT_ADMIN_USER/PASS`.

Regression specs run: `meta-measurement`, `market-pages`, `public-pages`, `app-promo` (18 passed, 12 skipped by their own env gates; one app-promo login bounced with `reauth=1` under 2 workers and passed when re-run serially — known wp-env parallel-login flake).

## Mobile

- Jest: 28 suites, 223 tests passed (includes new `deep-links.test.ts`).
- `tsc --noEmit`: passed at the Phase 8 commit; the current working tree has 2 errors in `components/request-card.tsx` from the concurrent session's uncommitted edits.

## Journeys A–J

| Journey | Browser (Playwright) | Server (PHP) | Not run (why) |
|---|---|---|---|
| A Organic vendor acquisition | Market page → event pages: robots/schema consistency, canonical, first/last-touch cookies | Registration snapshot, application + booking credited to touch, revenue by source | Live Google search; real Stripe payment (conversion rows created by fixtures) |
| B Organizer acquisition | Organizer creates a tracked link in the Marketing view; click counted | Link ownership, performance | Full organizer signup/onboarding UI (covered by existing onboarding specs, not re-run) |
| C City Connector distribution | — | Connector link forwards existing referral code; link credits signups/applications | Commission payout (existing program, unchanged) |
| D External event claim | — | Interest → lead → claim → interested vendors notified once → funnel to booking | — |
| E Social distribution | Visitor opens `/l/` → event page with OG title/image; link-preview bot counted as bot | Bot/dedupe counting | Real Facebook/Instagram preview fetch |
| F Mobile deep linking | — | Resolver + public event payload + share links | Device run (needs store build); Jest covers mapping and attribution |
| G Campaign management | Command Center campaigns/integrations/SEO tabs | Segment → preview counts → approval → schedule → only segment members messaged → results | Real delivery (mail captured) |
| H Privacy protection | Signed-out REST refused; vendor refused organizer links and Command Center; `/l/` cannot redirect off-site | Payload allowlists, role caps, BOLA checks | — |
| I Financial attribution | — | Platform fee per payment once, refunded fee netted, commissions subtracted, test conversions excluded, duplicate Purchase diagnostic | Live Stripe refund webhook |
| J SEO lifecycle | Sitemap lists events with lastmod, no opportunity singles; unknown event 404 | Upcoming → indexable; cancelled/past/incomplete → noindex + out of sitemap; reschedule → EventRescheduled | Search engine crawl |

## Edge cases from the specification

| Case | Where tested |
|---|---|
| Duplicate webhooks / duplicate Purchase | Revenue attribution counts one fee row per payment; Command Center diagnostic for payments with >1 Purchase |
| Repeated tracking clicks | Same browser/day de-duplicated; bots separate |
| Duplicate referral codes | Connector links reuse the existing code (no new enrollment) |
| Failed background jobs | Campaign batch exception → `failed`; ads outbox attempts/`failed`; Command Center diagnostics |
| Missing event images | OG generator fallback; diagnostics "events without an uploaded image" |
| Empty and large markets | Thin market noindex; market health counts; category threshold |
| Unpublished opportunities / cancelled events | Links fall back to feed; cancelled event shows status, no apply options |
| Multiple organizers | Ownership tests across two organizers |
| Mobile responsive layouts | Playwright iPhone 13 project; screenshot checks for market, guide and cohort pages |

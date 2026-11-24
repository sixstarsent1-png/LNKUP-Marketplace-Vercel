# Marketing, SEO & Distribution OS — Audit (Phase 0)

Date: 2026-11-24. Method: read-only code exploration of the monorepo, journey tracing from UI to REST to service to storage, plus local verification on wp-env (`http://localhost:8888`). Statuses follow the classification in the brief. "Verified" means exercised by a test or by hand locally, not only that code exists.

## Architecture confirmed

| Layer | Path | Role | Notes |
|---|---|---|---|
| System of record | `wp-content/plugins/lnkup-marketplace/` (12.36.3) | All business logic, REST `lnkup/v1`, CPTs, SQL tables | Confirmed |
| Communications | `wp-content/plugins/lnkup-communications/` (1.3.0) | Engagement OS, email (wp_mail), Expo push, inbox, campaigns | Confirmed; no SMS |
| Theme | `wp-content/themes/lnkup-theme/` (1.7.0) | Public site (Design System 5.0), dashboard shell | Confirmed |
| Native app | `lnk-up-marketplace-mobile/` | Expo, WordPress REST with Application Passwords | Confirmed |
| Web app | `lnk-up-marketplace-ui/` | Next.js on `lnkup-marketplace-ui.vercel.app`, proxies production WordPress | **Not on thelnkup.com**; many routes mock data. Out of SEO scope |
| Legacy UI | repo root `app/`, `components/` | Design mock | Not production |

Concurrent-session work (Meta measurement, event media, financial updates, app media) was verified and committed first (commits `458301a`, `a787643`, `d525f9a`, `92a7718`).

## Implementation inventory

| Feature | Existing files | Status | Issues | Proposed action | Dependencies |
|---|---|---|---|---|---|
| SEO metadata core | `includes/class-seo.php` (`LNKup_SEO`) | Implemented, needs enhancement | Works with Rank Math and fallback; context copy for home, sign-up, feed, organizer, passport, market, event; fallback prints duplicate robots tag; no canonical on market pages in fallback | Keep as single producer; add contexts (tour, organizer pretty URL, resources, market category); fix robots/canonical | Rank Math optional |
| Indexability rules | `lnkup_seo_should_noindex`, `exclude_sitemap_post` | Partially implemented | Never noindexes past, cancelled, unclaimed or non-authentic events; app pages `notification-settings`, `event-communications` indexable | New `LNKup_Index_Policy` used by all SEO paths | Event status, authenticity |
| Event URLs | CPT `lnkup_event` (`class-post-types.php`) | Implemented, needs enhancement | `/lnkup_event/{slug}/` raw slug | Rewrite slug `events` + 301 | Rewrite flush |
| Opportunity singles | CPT `lnkup_opportunity` | Broken (thin) | Public, indexable, renders near-empty `page.php` | 301 to event | — |
| Organizer profiles | `LNKup_Organizer_Onboarding::organizer_public_url` | Implemented, needs enhancement | `?organizer=` query URL, not in sitemap | `/organizers/{slug}/` + 301 | — |
| Tours / series | `portfolio/class-portfolio-public.php` | Implemented, needs enhancement | Reachable at both `/tours/` and `/series/`; title only, no canonical/JSON-LD/sitemap | Canonical prefix, 301, schema, sitemap | Portfolio OS flag |
| Vendor passports | `passport/class-passport-public.php` | Fully implemented and verified | Not in any sitemap | Add to sitemap when public | Passport privacy |
| Event JSON-LD | `class-seo.php::filter_json_ld` | Partially implemented | Date only, no endDate/image/organizer, postponed → EventScheduled, emitted for past/unclaimed | Rebuild from visible data; no offers (booth fee ≠ admission) | Event media, timezone helper |
| Sitemaps | Rank Math hooks only | Partially implemented | Core sitemaps unfiltered (dashboards, all events, taxonomies, users); markets lastmod always now; passports/tours/organizers missing | Core provider + Rank Math filters with policy and real lastmod | Index policy |
| Taxonomy archives | `class-taxonomies.php` | Broken (thin) | Public default archives `/lnkup_market/x/` etc. | noindex + sitemap exclusion | — |
| Market pages | `class-market-pages.php`, `template-parts/market/*`, admin | Implemented, needs enhancement | 10 seeded prelaunch markets; no coordinates/nearby/status; rewrite never auto-flushed; hero JPG ~1.9 MB page weight | Registry fields, computed status, page rework, versioned flush | Match market snapshots |
| City + category pages | — | Not implemented | Category links go to noindexed filtered feed | Eligibility-gated `/markets/{city}/{category}/` | Market registry |
| Market waitlist / alerts | `_lnkup_market_interest` at sign-up | Partially implemented | No notify-me | Saved search for city (logged in) or sign-up with `alert=1` | Saved searches |
| Supply/demand intelligence | `matching/class-match-market.php`, `organizer-growth/class-org-supply-intel.php` | Backend-only | Snapshots run only when marketplace phase ≥ 1c | Remove phase gate; surface in Command Center | Cron |
| Public event page | `LNKup_Shortcodes::render_public_event` | Implemented, needs enhancement | No status banner (cancelled/postponed); authenticity not checked for CTAs; no waitlist CTA; attendance not labeled organizer-provided; "more in city" not date-filtered | Status-aware CTAs and banners, external disclosure, follow tour | Authenticity, waitlist flag |
| Event imagery | `includes/media/*` (`LNKup_Event_Media`) | Fully implemented and verified | — | Reuse for OG images and graphics | — |
| OG images | `filter_og_image` | Implemented, needs enhancement | Uses event artwork; no text overlay; default is site icon | Generated 1200×630 PNG endpoint | GD |
| First-party attribution | `includes/meta/class-acquisition-attribution.php`, `lnkup-attribution.js` | Backend-only | No gclid/ttclid/utm_id/ref ids; apps never send attribution; only consumer is conversion registry | Extend capture, registration payload, reporting | Consent |
| Consent | `includes/meta/class-tracking-consent.php` | Fully implemented and verified | Opt-out (US), GPC honored | Reuse for every tracker | — |
| Conversion registry / Meta | `includes/meta/*` | Fully implemented and verified | Client event allowlist hardcoded | Add `Lead`; reuse event_id for GA4/TikTok | Meta flags |
| Internal analytics | `class-marketplace-analytics.php`, `matching/class-match-funnel.php` | Implemented, needs enhancement | No retention; JSON text LIKE scans; Next.js proxy shares one IP rate limit | Taxonomy adapter, retention + daily rollups | — |
| GA4 / Ads / TikTok / Search Console | — | Not implemented | — | Env-gated, consent-aware integrations; Search Console via service account | Credentials |
| Revenue attribution | `financial/class-platform-fees.php`, conversion registry | Not implemented (join missing) | GMV computed three ways | Report joining Purchase conversions to platform fees, rewards, spend | Financial OS |
| Vendor referral | `financial/class-referral-credits.php` | Fully implemented | `?ref=` 30-day cookie | Reuse | — |
| Organizer recruit referral | `financial/class-organizer-recruit-referrals.php` | Fully implemented | — | Reuse | — |
| City Connector | `class-connector*.php`, `connector.js` | Implemented, needs enhancement | Click logging not deduped/bot-filtered; QR via api.qrserver.com | Tracking links, local QR, digests, assets | Partnership OS |
| Partnership OS | `partnerships/*` | Fully implemented | Arbiter in shadow mode | Partner landing pages use partner records | — |
| QR generation | `api.qrserver.com` in connector, operations, passport, app promo, maps | Broken (privacy) | **Check-in pass tokens sent to third party** | Local QR library everywhere | — |
| Embed widget | `class-public-embed.php`, `embed.js` | Implemented, needs enhancement | Single card only; apply_url untracked | List widgets + tracking code | Tracking links |
| Organizer share tools | `class-organizer-dashboard-views.php`, `class-organizer-activation.php` | Partially implemented | Copy link + embed snippet only; no tracking, graphics, copy | Marketing view | Tracking links |
| External events + interest | `class-external-events*.php`, `external-sources/*` | Fully implemented | Interest does not create organizer leads | Auto-upsert lead with real demand | Org leads |
| Claim engine | `LNKup_External_Events::claim`, `trust/class-event-claims.php` | Fully implemented | — | Reuse; notify interested vendors (verify) | Trust |
| Organizer Acquisition OS | `organizer-growth/*`, `class-outreach.php` | Fully implemented | Lead source typed by hand; spend manual | Feed sources from attribution / demand | — |
| Comms engine | `lnkup-communications/includes/*` | Fully implemented and verified | All flows off by default | Reuse as delivery layer | — |
| Staff campaigns | `class-comms-campaigns.php`, `class-comms-audiences.php` | Implemented, needs enhancement | No scheduling, no saved segments, no approval, limited filters | Extend (no new engine) | Action Scheduler |
| Suppression | comms `suppressions`, `lnkup_contact_suppression`, outreach unsubscribe | Duplicate functionality | Three lists, not cross-checked | Bridge | — |
| SMS | — | Not implemented | — | Integrations tab shows "not configured" | Provider |
| Saved searches / alerts | `class-saved-searches.php` | Fully implemented | User-meta storage | Reuse for market alerts | — |
| Favorites / follow organizer | `class-vendor-personalization.php` | Fully implemented | No follow-tour | Add `tours` type | — |
| Opportunity waitlist | `class-waitlist-offers.php` | Implemented (flagged) | — | Surface CTA when flag on | — |
| Resources / blog | — | Not implemented | No single/archive templates | Resources hub on core posts | Theme |
| Organizer cohort | — | Not implemented | — | Cohort landing + lead capture | Org leads |
| Marketing role | — | Not implemented | Everything uses `lnkup_manage_all` | `lnkup_marketing` role / `lnkup_manage_marketing` | Roles |
| Command center | 27 separate admin pages | Duplicate/fragmented | No marketing overview | One Marketing page linking existing ones | — |
| App links | `class-app-links.php` | Partially implemented | AASA only `/vendors`, `/organizers`; Android fingerprints empty unless set | Add paths | — |
| Expo deep links | `app/+native-intent.ts`, `pending-destination.ts` | Implemented, needs enhancement | No public event/market screen; signup ignores ref | Event screen, link resolver, referral capture | REST |
| App promo | `class-app-promo.php` | Fully implemented | ct token only | Configurable pt/mt | — |

## Journey traces (current state)

- **Organic vendor (search → market → event → apply → pay):** market page shows up to 6 live cards → event page (`/lnkup_event/...`) → Apply links to `/opportunities/?apply=ID` → login/sign-up keeps `redirect` → application REST → checkout → Stripe webhook → `lnkup_payment_completed` → conversion registry `Purchase` with attribution snapshot. **Gap:** no report joins this to source; event URL not canonical-friendly.
- **Organizer acquisition:** `/for-organizers/` → sign-up `role=organizer` → onboarding → create event → publish → `EventPublished` conversion. **Gap:** no tracked share links for the first vendor recruitment.
- **Connector:** `/sign-up/?ref=CODE&cc=` → connector cookie → registration → `wp_lnkup_referrals` → payment → rewards. **Gap:** click inflation (no dedupe), third-party QR.
- **External claim:** external listing → vendor interest → outreach email with token → claim → trust review → event. **Gap:** no organizer lead auto-created from demand.
- **Mobile:** universal link `/opportunities?apply=N` → `/opportunity/N`; others pass through. **Gap:** events, markets, tours, tracking links not handled.

## Security and privacy findings

1. Check-in pass tokens and passport links are sent to `api.qrserver.com` (third party) — fix in Phase 4.
2. Connector click endpoint accepts unlimited clicks (no dedupe) — tracking links will dedupe.
3. Three suppression lists — bridged in Phase 5.
4. No marketing-staff role; staff currently need `lnkup_manage_all` (financial access) — Phase 5 role.
5. Core sitemap exposes users list and dashboards when Rank Math is inactive — Phase 1.

## Baseline (lab, wp-env on Docker for Windows)

See `perf-baseline.json`. TTFB dominates locally (4–8 s, environment-bound). Front-end time after TTFB 100–400 ms. Page weight: home 312–340 KB, event 144–502 KB, **market 1.95 MB** (unoptimized hero), feed 379–480 KB. CLS ≤ 0.022 everywhere.

## Tests executed during the audit

- `tests/php/meta-measurement-test.php` 95/95, `meta-integration-wp.php` 64/64, `event-media-test.php` 37/37, `event-media-integration-wp.php` 41/41.
- `tests/e2e/meta-measurement.spec.ts` 4/4 (Pixel test mode, Meta script stubbed), `event-media.spec.ts` 3/3, public website suites 84 passed / 22 skipped, comms PHP suites 15/15, mobile Jest 190/190.

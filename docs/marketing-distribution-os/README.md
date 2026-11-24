# Marketing, SEO & Distribution OS — Documentation Index

Built on the existing WordPress system of record (plugin `lnkup-marketplace` 12.37.0, `lnkup-communications` 1.4.0, theme `lnkup-theme`). Every feature ships behind a flag that defaults to off (`LNKup_Marketing_Flags`), so a deploy changes nothing until a flag is turned on.

## The 20 engineering deliverables

| # | Deliverable | Document |
|---|---|---|
| 1 | Complete codebase audit | [AUDIT.md](AUDIT.md) |
| 2 | Updated architecture documentation | [ARCHITECTURE.md](ARCHITECTURE.md) |
| 3 | Existing vs newly implemented feature matrix (with per-module report) | [FEATURE_MATRIX.md](FEATURE_MATRIX.md) |
| 4 | Database changes and migrations | [DATABASE.md](DATABASE.md) |
| 5 | REST API changes | [REST_API.md](REST_API.md) |
| 6 | SEO URL and canonical strategy | [SEO_URL_STRATEGY.md](SEO_URL_STRATEGY.md) |
| 7 | Market and landing page eligibility rules | [ELIGIBILITY_RULES.md](ELIGIBILITY_RULES.md) |
| 8 | Analytics event taxonomy | [ANALYTICS_TAXONOMY.md](ANALYTICS_TAXONOMY.md) |
| 9 | Attribution model | [ATTRIBUTION_MODEL.md](ATTRIBUTION_MODEL.md) |
| 10 | Campaign integration | [CAMPAIGNS.md](CAMPAIGNS.md) |
| 11 | Organizer marketing toolkit | [ORGANIZER_TOOLKIT.md](ORGANIZER_TOOLKIT.md) |
| 12 | Mobile deep linking | [DEEP_LINKS.md](DEEP_LINKS.md) |
| 13 | Security and permissions review | [SECURITY_REVIEW.md](SECURITY_REVIEW.md) |
| 14 | Test results | [TEST_RESULTS.md](TEST_RESULTS.md) |
| 15 | Performance comparisons | [PERFORMANCE.md](PERFORMANCE.md) |
| 16 | Remaining limitations | [LIMITATIONS.md](LIMITATIONS.md) |
| 17 | Changed file inventory | [CHANGED_FILES.md](CHANGED_FILES.md) |
| 18 | Uncommitted change inventory | [UNCOMMITTED_CHANGES.md](UNCOMMITTED_CHANGES.md) |
| 19 | Deployment checklist | [DEPLOYMENT_CHECKLIST.md](DEPLOYMENT_CHECKLIST.md) |
| 20 | Rollback instructions | [ROLLBACK.md](ROLLBACK.md) |

Also: [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md) (phase order) and the lab performance snapshots `perf-baseline.json` (before) and `perf-after.json` (after).

## Phase commits

| Phase | Commit | Scope |
|---|---|---|
| 0 | `458301a`, `a787643`, `d525f9a`, `92a7718`, `6c06850`, `4714243` | Concurrent session work committed by area; audit and architecture docs |
| 1 | `1fbfd58` | Index policy, clean URLs + 301s, schema, sitemaps, SEO diagnostics |
| 2 | `d16909f` | Market registry, city + category and weekly digest pages, event notices, follow tour, OG images |
| 3 | `12f2fca` | Attribution parameters, tracked links, analytics taxonomy, GA4/TikTok/Ads (gated), revenue attribution |
| 4 | `0e3ed5c` | Organizer Marketing workspace, local QR codes, list embeds |
| 5 | `ebd0c78` | Campaign approval + scheduling, saved segments, filters, experiments, suppression bridge, marketing role |
| 6 | `b946420` | External demand leads, claim notifications, connector Share & Track, partner pages, acceptance share |
| 7 | `a12a631` | Market alert and tour stop messages, consent records, resources hub, cohort pages |
| 8 | `6d68155` | App deep links v2, public event screen, referral capture at signup, tracked native share |
| 9 | `2ea217f` | Marketing Command Center, Search Console (credential-gated) |
| 10 | see [UNCOMMITTED_CHANGES.md](UNCOMMITTED_CHANGES.md) | Journeys spec, docs, version bumps |

## What needs external credentials or production configuration

Implemented and tested locally, but **not verified against the real provider** (no credentials in this environment):

- Google Search Console data (service account in `wp-config.php`).
- GA4 Measurement Protocol, Google Ads conversion labels, TikTok Events API (only send from the production host or in test mode; dispatch was tested with intercepted HTTP).
- iOS universal links and Android App Links for the new paths (need the store build with the new `+native-intent` and, for Android, the signing fingerprint).
- App Store campaign attribution (`pt` provider token from App Store Connect).
- Real email/push delivery of the new messages (tests capture mail and stub Expo; no message reached a real person).

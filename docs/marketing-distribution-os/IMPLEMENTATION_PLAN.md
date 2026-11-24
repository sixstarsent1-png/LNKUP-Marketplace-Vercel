# Marketing, SEO & Distribution OS — Implementation Plan

Dependency order. Each phase is independently testable, flag-gated and committed separately.

| Phase | Scope | Depends on | Flag |
|---|---|---|---|
| 0 | Commit verified concurrent work; audit; baselines | — | — |
| 1 | `LNKup_Index_Policy`; `/events/`, `/organizers/{slug}/`, tour canonical prefix with 301s; Event/ProfilePage/Breadcrumb schema; sitemap providers with real lastmod; metadata fixes; SEO diagnostics | 0 | `seo_index_policy` |
| 2 | Market registry fields + computed status; market page rework; alerts/demand; city+category pages with eligibility; weekly digests; event page status/CTA/external disclosure; follow tour; OG image endpoint | 1 | `market_category_pages` |
| 3 | Attribution params + app/web registration payload; tracking links + daily aggregates; analytics taxonomy, retention, rollups; GA4/TikTok/Ads gated; revenue attribution report | 0 | `tracking_links`, `ga4_enabled`, `tiktok_enabled` |
| 4 | Organizer Marketing view: tracked links, local QR (and qrserver removal), graphics templates, copy templates, list embeds, performance + recommendations | 3 | `organizer_marketing` |
| 5 | Campaign scheduling, approval, saved segments, filters, experiments, suppression bridge, marketing role | 3 | `campaign_scheduling` |
| 6 | Connector toolkit, partner landing pages, external interest → organizer leads, claim notifications, acceptance share | 3, 4 | `tracking_links` |
| 7 | Market alert + followed-tour triggers with dedupe; consent source; resources hub + editorial checklist; cohort landing | 2, 5 | `resources_hub` |
| 8 | AASA/intent paths; Expo event/market/tour/link routes; referral capture; tracked share; app promo tokens | 1, 3 | `deep_links_v2` |
| 9 | LNKup → Marketing command center (overview, SEO, markets, campaigns, distribution, content, integrations, diagnostics) | 1–8 | — |
| 10 | PHP suites, Playwright journeys A–J, security/performance checks, deliverable docs, version bumps | all | — |

Attribution instrumentation (Phase 3) lands before the acquisition features that depend on it (Phases 4–6).

Rollout: enable flags one at a time in production after the Command Center diagnostics are clean for that area.

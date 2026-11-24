# Marketing, SEO & Distribution OS — Feature Matrix

Existing = before this work (see AUDIT.md). Status: **Delivered** = implemented and covered by executed tests; **Delivered, needs credentials** = implemented and tested with mocks, not verified against the real provider.

| Area | Existing | Delivered | Status | Evidence |
|---|---|---|---|---|
| Indexability policy | Partial (prelaunch, page slugs, market flags) | One policy for robots, sitemap and schema | Delivered | `seo-policy-test` |
| Canonical URLs (events, organizers, tours) | Raw CPT slug, query URLs, duplicate tour prefixes | `/events/`, `/organizers/{slug}/`, tour canonical + 301s keeping campaign params | Delivered | `seo-policy-test`, Journey J |
| Structured data | Basic Event, market ItemList, passport LocalBusiness | Event (status, reschedule, no offers), Breadcrumbs, Organization/ProfilePage, Article | Delivered | `seo-policy-test`, Journey A |
| Sitemaps | Rank Math page sitemap + markets | `lnkup` core provider with real lastmod; Rank Math filtered by policy | Delivered | `seo-policy-test`, Journey J |
| SEO diagnostics | None | REST + Command Center → SEO | Delivered | `seo-policy-test`, Command Center test |
| Search Console | None | Read-only page performance | Delivered, needs credentials | Not connected state tested |
| Market registry + status | 10 seeded markets, manual launch status | Computed status, live health, daily snapshots, registry fields | Delivered | `market-registry-test` |
| City + category pages | None | Staff-enabled, ≥3 open events, noindex otherwise | Delivered | `market-registry-test` |
| Market alerts / demand | Sign-up market interest only | Per-event market alerts, anonymous demand taps | Delivered | `market-registry-test`, `retention-content-test` |
| Weekly digests | None | `/markets/{city}/this-week/` | Delivered | `market-registry-test` |
| Event page conversion states | Apply/save/follow organizer | Status notices, external disclosure, guest CTAs, similar-events alert | Delivered | `market-registry-test` |
| Follow tour | None | Favorites type `tours`, tour stop messages | Delivered | `market-registry-test`, `retention-content-test` |
| OG images | Event artwork, site icon default | Generated 1200×630 fallback for events and markets | Delivered | `market-registry-test`, Journey E |
| Attribution capture | UTM, fbclid, landing (web only) | Click IDs, codes, market, device; app payload | Delivered | `attribution-test`, Jest |
| Tracking links | Connector referral links only | `/l/{code}` with daily aggregates, bot/dedupe, conversion credit | Delivered | `attribution-test`, Journeys B/E/H |
| Analytics taxonomy + retention | Ad-hoc names, no retention | 20 mapped events, v1 contract, rollup + opt-in purge | Delivered | `attribution-test` |
| GA4 / TikTok / Google Ads | None | Consent- and host-gated tags + server events | Delivered, needs credentials | `attribution-test` (HTTP intercepted) |
| Revenue attribution | None (join missing) | GMV vs net platform fee, refunds, commissions, spend, CSV | Delivered | `attribution-test`, Command Center test |
| Organizer marketing toolkit | Copy link + single embed | Links, graphics, copy, embeds, performance + recommendations | Delivered | `organizer-marketing-test`, Journey B |
| Local QR | Third-party api.qrserver.com | Vendored generator (PHP + JS) | Delivered | `organizer-marketing-test` |
| Campaign scheduling / approval / segments / experiments | Immediate send, ad-hoc audience | Two-person approval, schedule, saved segments, 7 filters, A/B | Delivered | `marketing-campaigns-test`, Journey G |
| Suppression unification | Three lists | Outreach → comms bridge | Delivered | `marketing-campaigns-test` |
| Marketing role | None | `lnkup_marketing` (no financial/document access) | Delivered | `marketing-campaigns-test`, Command Center test |
| Connector toolkit | Links, studio templates, remote QR | Share & Track forwarding existing codes | Delivered | `distribution-test` |
| Partner landing pages | None | `/with/{slug}/` for approved partners | Delivered | `distribution-test` |
| External demand → leads | Manual | Leads, claim notifications, claim funnel | Delivered | `distribution-test` |
| Acceptance share loop | None | Opt-in share card + vendor tracked link | Delivered | `distribution-test` |
| Lifecycle alerts (market, tour) | Saved searches, follow organizer | Deduped per-event and per-stop messages, consent source | Delivered | `retention-content-test` |
| Resources hub / cohort | None | `/resources/`, `/guides/`, editorial checklist, `/cohort/` | Delivered | `retention-content-test` |
| Deep links (events, markets, tours, links) | Vendors, organizers, opportunities | Resolver, public event screen, signup attribution, tracked native share | Delivered (device run pending) | `app-links-test`, Jest |
| App promotion tokens | `ct` only | Configurable `pt`/`mt` | Delivered, needs credentials | `app-links-test` |
| Marketing Command Center | Fragmented admin pages | One page, 8 tabs, filters, exports | Delivered | `marketing-command-center-test`, Journey G |

## Per-module report

| Module | Existed before | Was incomplete | Enhanced | Newly implemented | Reused | Tests executed | Unresolved |
|---|---|---|---|---|---|---|---|
| SEO | Rank Math, basic schema, market pages | Raw CPT URLs, schema on noindex pages, `offers` from booth fees, no lastmod | Schema, canonicals, Rank Math filters | `LNKup_Index_Policy`, `LNKup_SEO_URLs`, `LNKup_Sitemaps`, diagnostics, Search Console client | Rank Math, core sitemaps, marketplace index | seo-policy (43), Journey J | GSC credentials |
| Markets | Market pages, seeded markets | Manual status, heavy template, no category pages | Market template (DS 5.0), admin fields | Registry status/health, category + digest pages, alerts, demand, OG images | `LNKup_Market_Pages`, saved searches | market-registry (60), Journey A | Category pair suggestions |
| Attribution & analytics | Meta first-party touch, conversion registry | No click IDs/codes, no link tracking, no retention, no revenue join | Touch model, registration payload | Tracking links, taxonomy, retention, ad platforms, revenue report | Conversion registry, Meta consent, Organizer Growth spend | attribution (60), Journeys E/H/I | Live ad accounts |
| Organizer toolkit | Copy link, embed | Third-party QR, no per-channel tracking | Embed (list) | Marketing workspace, local QR | Tracking links, team capabilities | organizer-marketing (35), Journey B | — |
| Campaigns | Comms campaigns (immediate) | No approval, no segments, three suppression lists | Audiences, campaign runner | Workflow, segments, experiments, suppression bridge, role | Comms engine, templates, Action Scheduler | marketing-campaigns (43), Journey G | Real delivery verification |
| Distribution | Connector program, external events, partner orgs | Manual demand follow-up | Connector dashboard | Leads, claim notifications, partner pages, acceptance share | Organizer Growth leads, connector codes, partner records | distribution (29) | — |
| Retention & content | Saved searches, follow organizer | No market/tour alerts, no hub | Saved-search runner coordination | Lifecycle messages, consent records, resources hub, cohorts | Comms registry, posts, Organizer Growth | retention-content (35) | Market alert digest variant |
| Mobile | Profile/opportunity links, pending destination | No event/market/tour/link routes, signup ignored `ref` | `+native-intent`, signup, share | Resolver, public event screen, attribution store, share links | Pending destination, auth payload | app-links (49), Jest 223 | Device verification |
| Command Center | Separate admin pages | No unified view | — | Marketing admin page | All of the above | command-center (43), Journey G | — |

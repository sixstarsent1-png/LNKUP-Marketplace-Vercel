# Marketing, SEO & Distribution OS — Architecture

The OS is an orchestration layer over existing systems. It owns very little data: URL and indexing policy, tracking links and their daily aggregates, saved campaign segments, and market registry fields. Everything else is read from the system that already owns it.

```mermaid
flowchart LR
  traffic[SearchSocialAdsPartners] --> pages[EventMarketTourLandingPages]
  links[TrackingLinks] --> pages
  pages --> attrib[AcquisitionAttribution]
  attrib --> signup[Registration]
  signup --> apply[ApplicationsBookings]
  apply --> conv[ConversionRegistry]
  conv --> fees[PlatformFees]
  conv --> ads[MetaGA4TikTok]
  fees --> center[MarketingCommandCenter]
  demand[MarketAndExternalDemand] --> leads[OrganizerAcquisitionOS]
  demand --> campaigns[CommsCampaigns]
  campaigns --> comms[CommsEngine]
  policy[IndexPolicy] --> seo[LNKupSEO]
  policy --> sitemaps[SitemapProviders]
```

## Data ownership matrix

| Logical entity | Owner (source of truth) | Storage | New? |
|---|---|---|---|
| Event, opportunity, application, payment | Marketplace CPTs | Posts + meta | No |
| Marketplace index (discovery) | `LNKup_Marketplace_Index` | `wp_lnkup_marketplace_index` | No |
| Market | `LNKup_Market_Pages` | Option `lnkup_city_markets` (+ new fields) | Fields only |
| Market health | `LNKup_Match_Market`, `LNKup_Org_Supply_Intel` | Snapshot tables | No |
| Indexability decision | `LNKup_Index_Policy` | Computed (no storage) | Yes (class) |
| Attribution touch | `LNKup_Acquisition_Attribution` | Cookies `lnkup_ft`/`lnkup_lt`, user meta `_lnkup_acq_*` | No (extended) |
| Conversion event | `LNKup_Conversion_Registry` | `wp_lnkup_conversion_events` | No |
| Analytics event stream | `LNKup_Marketplace_Analytics` | `wp_lnkup_analytics_events` + daily rollup table | Rollup table |
| Tracking link | `LNKup_Tracking_Links` | `wp_lnkup_tracking_links`, `wp_lnkup_tracking_link_daily` | Yes |
| Referral / commission | Referral, Connector, Partnership programs | Existing referral tables | No |
| Campaign | `LNKup_Comms_Campaigns` | `wp_lnkup_comms_campaigns` (+ columns) | Columns |
| Campaign segment | `LNKup_Comms_Segments` | `wp_lnkup_comms_segments` (rules JSON only) | Yes |
| Suppression | comms `suppressions` (+ bridge to outreach) | Existing tables | No |
| Organizer lead | `LNKup_Org_Leads` | `wp_lnkup_org_leads` | No |
| External demand | `LNKup_External_Events` | `wp_lnkup_external_event_interest` | No |
| Platform revenue | `LNKup_Platform_Fees` | `wp_lnkup_platform_fees` | No |
| Ad spend | `LNKup_Org_Growth_Spend` | `wp_lnkup_org_growth_spend` | No |
| Resource article | WordPress core posts | Posts in categories | No |
| Landing / partner / cohort page | `LNKup_Campaign_Landings` | Option `lnkup_campaign_landings` | Fields only |

No second source of truth is introduced for events, users, applications, payments or commissions. High-volume streams (clicks, analytics) go to indexed tables with daily rollups and retention, never post meta.

## Extension points used

- SEO: `LNKup_SEO` filters (Rank Math + fallback), core `wp_sitemaps_*`, `wp_robots`.
- Attribution: `LNKup_Acquisition_Attribution::request_touch`, registration hooks `lnkup_user_registered`.
- Conversions: `lnkup_conversion_registry` filter, `lnkup_conversion_recorded` action.
- Comms: `LNKup_Comms_Registry` triggers, `LNKup_Comms_Audiences`, `LNKup_Comms_Campaigns`, suppression checks.
- Leads: `LNKup_Org_Leads` upsert API.
- Media: `LNKup_Event_Media::resolve()`.
- Apps: `LNKup_App_Links` (AASA / assetlinks), Expo `+native-intent.ts`, `pending-destination`.

## Permissions model

| Actor | Capability | Access |
|---|---|---|
| Platform admin | `lnkup_manage_all` | Everything |
| Marketing staff | `lnkup_manage_marketing` (role `lnkup_marketing`) | Command Center, campaigns, segments (aggregate previews), landing pages, resources; **no** financial records beyond aggregates, no private documents |
| Organizer owner / team | ownership + team `broadcast` | Their own events' links, graphics, embeds, performance |
| City Connector | `lnkup_connector` | Their links, assets, digests, own performance |
| Partner | `lnkup_partner` | Their partner page performance |
| Vendor | `lnkup_vendor` | Alerts, follows, shares |
| Public | none | Public pages, public REST (public fields only), `/l/{code}` |

## Feature flags (all default off)

`seo_index_policy`, `market_category_pages`, `tracking_links`, `organizer_marketing`, `campaign_scheduling`, `ga4_enabled`, `tiktok_enabled`, `resources_hub`, `deep_links_v2`. Stored in `lnkup_settings` via `LNKup_Marketing_Flags`.

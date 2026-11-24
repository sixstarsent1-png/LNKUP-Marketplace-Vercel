# Database changes and migrations

All schema changes are additive (new tables, new nullable/defaulted columns). No existing column, table or meta key was renamed or removed. Migrations run through `dbDelta()` guarded by a schema version option, so they are idempotent and run on the first request after deploy.

## New tables (`lnkup-marketplace`, `LNKup_Marketing_Tables`, option `lnkup_marketing_schema`)

| Table | Purpose | Key columns | Retention |
|---|---|---|---|
| `{prefix}lnkup_tracking_links` | Tracked short links `/l/{code}` | `code` (unique), `target_type` + `target_id` / `target_key`, `owner_user_id`, `owner_type`, `channel`, `campaign`, `utm_*`, `ref_param`, `ref_code`, `status` | Kept; owners archive (max 500 non-archived per owner) |
| `{prefix}lnkup_tracking_link_daily` | Per-link daily aggregates | PK (`link_id`, `day`): `clicks`, `unique_clicks`, `bot_clicks`, `signups`, `applications`, `bookings` | Kept (aggregates, no personal data) |
| `{prefix}lnkup_analytics_daily` | Daily rollup of `lnkup_analytics_events` | PK (`day`, `event_type`): `events`, `users` | Kept; raw rows can be purged after 13 months only when `analytics_retention_purge` is enabled |
| `{prefix}lnkup_ads_outbox` | GA4 / TikTok server-event queue | unique (`platform`, `event_id`), `status`, `attempts`, `last_error`, `is_test` | Rows marked sent; failed after 5 attempts |

No IP address is stored. Repeat-click de-duplication uses a salted hash in a 24-hour transient.

## `lnkup-communications` schema (option `lnkup_comms_db_version` → `1.2.0`)

| Change | Detail |
|---|---|
| `lnkup_comms_campaigns` new columns | `meta` (objective, goal, exclusions, variants), `segment_id`, `submitted_by`, `approved_by`, `approved_at`, `approved_count` |
| New table `lnkup_comms_segments` | `id`, `name`, `role`, `rules` (sanitized filter JSON — never user lists), `status`, `updated_by`, `updated_at` |

## Options (all non-autoloaded unless noted)

| Option | Owner | Content |
|---|---|---|
| `lnkup_settings[<flag>]` | `LNKup_Marketing_Flags` | Nine feature flags (see ARCHITECTURE.md) |
| `lnkup_seo_rewrite_signature` | `LNKup_SEO_URLs` | Rewrite-flush signature (version + flags) |
| `lnkup_city_markets` (existing) | `LNKup_Market_Pages` | Added per-market fields: `lat`, `lng`, `metro`, `nearby`, `content`, `category_pages`, `status_override` (see ELIGIBILITY_RULES.md) |
| `lnkup_analytics_rollup_until` | `LNKup_Analytics_Taxonomy` | Rollup watermark (days up to here are read from the rollup) |
| `lnkup_ads_settings` | `LNKup_Ad_Platforms` | GA4 measurement ID, Ads conversion ID + labels, TikTok pixel ID, test mode (secrets stay in constants) |
| `lnkup_partner_landings` | `LNKup_Partner_Landings` | Partner page copy keyed by slug |
| `lnkup_cohort_landings` | `LNKup_Cohort_Landings` | Cohort page copy and speakers keyed by slug |
| `lnkup_resources_outlines_seeded` | `LNKup_Resources_Hub` | IDs of the outline drafts created once |
| `lnkup_app_campaign` | `LNKup_App_Deep_Links` | App Store `pt` / `mt` campaign tokens |
| `lnkup_android_sha256_fingerprints` (existing, now editable) | `LNKup_App_Links` | Android App Links signing fingerprints |
| `lnkup_marketing_role_version` | `LNKup_Marketing_Role` | Role install version |

## User and post meta

| Key | Object | Content |
|---|---|---|
| `_lnkup_acq_first_touch`, `_lnkup_acq_last_touch`, `_lnkup_acq_registration` | user | Sanitized campaign touches copied at registration (existing keys; payload extended with click-ID flags, `lnk`, `market`, `device`) |
| `_lnkup_market_alerts` | user | Market alert saved-search IDs |
| `_lnkup_marketing_consent` | user | `{state, source, at, history[≤20]}` (sources: registration_default, preferences, unsubscribe, market_alert) |
| `_lnkup_last_login_at` | user | Last login timestamp (inactivity filter) |
| `_lnkup_ga_cid`, `_lnkup_ads_pending` | user | GA client ID; browser events waiting to fire once |
| `_lnkup_seo_previous_start` | event | Previous start date (EventRescheduled) |
| `_lnkup_review_seo`, `_lnkup_review_facts`, `_lnkup_review_legal_needed`, `_lnkup_review_legal` | post (guides) | Editorial checklist |

## Roles and capabilities

- New role `lnkup_marketing` with only `read` + `lnkup_manage_marketing`.
- `lnkup_manage_marketing` added to `administrator` and `lnkup_admin`.

## Cron (WP-Cron, daily)

`lnkup_analytics_retention` (04:20), `lnkup_market_health_daily` (05:40), `lnkup_distribution_daily` (06:15), plus `lnkup_ads_dispatch` (scheduled after a conversion) and `lnkup_comms_campaign_scheduled` (Action Scheduler job per approved campaign).

## Rollback impact

Tables and columns can stay in place when code is rolled back (nothing reads them). See ROLLBACK.md for optional cleanup SQL.

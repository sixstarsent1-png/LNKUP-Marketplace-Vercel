# Deployment checklist

All features default to off. Deploy the code first, verify nothing changed, then enable flags one at a time on staging, then production.

## 1. Before deploy

- [ ] Back up the database (tables are additive, but back up anyway).
- [ ] Confirm versions: `lnkup-marketplace` 12.37.0, `lnkup-communications` 1.4.0.
- [ ] No credentials in the repository: GA4 API secret, TikTok token and the Search Console service account go in the server environment or `wp-config.php` only.
- [ ] Pretty permalinks enabled (required for `/events/`, `/l/`, `/markets/…/…`, `/guides/`).

## 2. Deploy

- [ ] Deploy both plugins (and the theme).
- [ ] Load any page once: `dbDelta` creates the four marketing tables and the comms segment table/columns; the `lnkup_marketing` role is installed.
- [ ] Check WP-Cron or a real cron is running (`lnkup_analytics_retention`, `lnkup_market_health_daily`, `lnkup_distribution_daily`, Action Scheduler).
- [ ] Smoke test with all flags off: homepage, an event page, `/markets/{city}/`, vendor and organizer dashboards, checkout — unchanged.

## 3. Enable on staging, in this order

| Step | Flag / setting | Verify |
|---|---|---|
| 1 | `seo_index_policy` | `/lnkup_event/x/` 301 → `/events/x/`; upcoming event `index`, cancelled `noindex`; Event JSON-LD has no `offers`; `/wp-sitemap.xml` lists `lnkup` sitemaps; Command Center → SEO has no errors |
| 2 | `market_category_pages` | Market status/health in Command Center → Markets; enable one category pair in City Markets and check it renders only with ≥3 open events |
| 3 | `tracking_links` | Create a link in Command Center or the organizer view; `/l/{code}` redirects internally and counts one click |
| 4 | `organizer_marketing` | Organizer dashboard → Marketing loads for an owner; another organizer gets 403 |
| 5 | `campaign_scheduling` | Create a segment, submit a campaign, approve as a second marketing user, schedule to **test recipients only** |
| 6 | `resources_hub` | `/resources/`, a guide at `/guides/{slug}/` with Article schema; non-admin guide without checklist is held as pending |
| 7 | Comms `flow_discovery` | Market alert / tour stop / claim messages reach test accounts only |
| 8 | `ga4_enabled`, `tiktok_enabled` | Only with IDs and secrets set; staging stays off unless ads test mode is deliberately on (GA4 validation endpoint, TikTok test code) |
| 9 | `deep_links_v2` | **Only after** the app build with the new routes is live in the stores; check `/.well-known/apple-app-site-association` lists the new paths |

Assign the `lnkup_marketing` role to marketing staff; finance access remains separate.

## 4. Production

- [ ] Repeat steps 1–7 with real data; watch Command Center → Diagnostics for failed jobs and attribution gaps.
- [ ] Add the Search Console service account (read-only) and property constant.
- [ ] Save the Android signing fingerprint and the App Store provider token in Command Center → Integrations.
- [ ] Record ad spend in Organizer Growth to see ROAS.

## 5. After enabling

- [ ] Submit the sitemap in Search Console; check coverage after a few days.
- [ ] Spot-check that no campaign was sent without approval (Command Center → Campaigns).
- [ ] Review `noindex` counts in SEO diagnostics weekly for the first month.

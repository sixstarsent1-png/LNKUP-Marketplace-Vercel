# Rollback instructions

## Level 1 — turn a feature off (no deploy)

Every feature is behind a flag. Turning a flag off restores previous behavior immediately:

- Command Center → Diagnostics → Feature flags (administrators), or
- `wp eval 'LNKup_Marketing_Flags::set( "tracking_links", false );'`

Turning `seo_index_policy` off flushes rewrites and restores `/lnkup_event/` URLs, the previous robots output and the previous sitemaps. Search engines may briefly see 404s for `/events/` URLs; prefer leaving it on unless it is causing harm. Turning `deep_links_v2` off removes the extra AASA paths (iOS refreshes the file within ~24 hours).

## Level 2 — revert code

Revert phase commits in reverse order (newest first): `2ea217f`, `6d68155`, `a12a631`, `b946420`, `ebd0c78`, `0e3ed5c`, `12f2fca`, `d16909f`, `1fbfd58` (and the Phase 10 commit). Each phase only adds classes and hooks, so reverting one phase leaves earlier phases working. After reverting:

- Flush rewrite rules (`wp rewrite flush`).
- Old code ignores the new tables, columns, options and meta.

The mobile app is independent: older app builds keep working; if `deep_links_v2` is off they never receive the new paths.

## Level 3 — remove data (optional, irreversible)

Only after Level 2 and a backup. None of this is required for the old code to work.

```sql
DROP TABLE IF EXISTS wp_lnkup_tracking_links, wp_lnkup_tracking_link_daily, wp_lnkup_analytics_daily, wp_lnkup_ads_outbox, wp_lnkup_comms_segments;
ALTER TABLE wp_lnkup_comms_campaigns DROP COLUMN meta, DROP COLUMN segment_id, DROP COLUMN submitted_by, DROP COLUMN approved_by, DROP COLUMN approved_at, DROP COLUMN approved_count;
DELETE FROM wp_options WHERE option_name IN ('lnkup_marketing_schema','lnkup_seo_rewrite_signature','lnkup_analytics_rollup_until','lnkup_ads_settings','lnkup_partner_landings','lnkup_cohort_landings','lnkup_resources_outlines_seeded','lnkup_app_campaign','lnkup_marketing_role_version');
DELETE FROM wp_usermeta WHERE meta_key IN ('_lnkup_market_alerts','_lnkup_marketing_consent','_lnkup_last_login_at','_lnkup_ga_cid','_lnkup_ads_pending');
```

Then reset `lnkup_comms_db_version` to `1.1.0` and remove the role: `wp role delete lnkup_marketing` (and `wp cap remove administrator lnkup_manage_marketing`). Market alert saved searches can stay (they are ordinary saved searches).

Do **not** delete `_lnkup_acq_*` user meta or conversion rows; they predate this work and feed Meta measurement.

# Deployment checklist — Event Creation OS 3.0 (plugin 12.40.0)

All Event OS flags default **off**. Deploy code first, smoke-test dashboards with flags off, then enable on staging / production in order.

## 1. Before deploy

- [ ] Back up the production database (additive tables / CPT meta; backup anyway).
- [ ] Note live plugin version and git SHA for rollback (see `ROLLBACK.md`).
- [ ] Confirm no Eventbrite / import credentials are in the repo — only `wp-config.php` / server env.
- [ ] Confirm SiteGround path: `~/www/thelnkup.com/public_html`.

## 2. Deploy (12.40.0)

Preferred (SSH):

```powershell
$env:SG_SSH_HOST = "ssh.thelnkup.com"
$env:SG_SSH_PORT = "18765"
$env:SG_SSH_USER = "<Site Tools user>"
$env:SG_WP_ROOT  = "/home/customer/www/thelnkup.com/public_html"
$env:SG_SSH_KEY  = "$HOME\.ssh\lnkup_siteground"
.\scripts\deploy-siteground.ps1
```

Zip fallback: build with `.\scripts\build-plugin-zip.ps1`, then Plugins → Upload `dist/lnkup-marketplace.zip`, activate, hard-refresh organizer dashboard.

Also sync theme (`lnkup-theme`) and `lnkup-communications` via the same script.

After upload: load any page once so `lnkup_maybe_upgrade_db()` runs (import job tables + draft CPT registration).

## 3. Smoke test with all Event OS flags off

- [ ] Homepage, `/markets/…`, vendor and organizer dashboards, checkout unchanged.
- [ ] Organizer **Create** still uses the legacy form (or `?classic=1` if a flag was left on).
- [ ] No PHP notices in REST JSON; no infinite skeletons on Create / Events.

## 4. Enable flags (staging → production)

| Step | Flag | Verify |
|---|---|---|
| 1 | `event_builder_v3` | Organizer Create / Edit / Duplicate open the builder; `?classic=1` restores legacy |
| 2 | `event_import_v3` | Imports Center + single-URL import with review; no auto-publish |
| 3 | `event_import_bulk` | Multi-URL / CSV / ICS jobs via Action Scheduler |
| 4 | `portfolio_builder_v3` | Recurring / tour / series builders |
| 5 | `event_source_sync` | External Sources panel + change review (organizer edits protected) |
| 6 | `eventbrite_oauth` | **Only** with app credentials in `wp-config`; otherwise leave off |

## 5. Cache

- Purge CDN / page cache for `/organizer-dashboard/*` so `event-builder.js?ver=12.40.0` loads.
- Do not cache `/wp-json/lnkup/v1/organizer/builder/*` or import routes.

## 6. Mobile OTA (optional, after WP is live)

From `lnk-up-marketplace-mobile/`:

```bash
pnpm update:preview    # then
pnpm update:production # when preview is good
```

Native Event OS create/import uses the same builder / import REST; keep flags off until WP smoke is clean.

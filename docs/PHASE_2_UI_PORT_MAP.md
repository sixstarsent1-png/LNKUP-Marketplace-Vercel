# LNKup Phase 2 — UI Port Map

Maps the **Next.js mock** (`lnk-up-marketplace-ui/`) to **WordPress production** (theme shell + plugin shortcodes). Phase 2 ports patterns without replacing business logic, REST routes, or shortcodes.

**Mock reference:** `cd lnk-up-marketplace-ui && pnpm dev`  
**Production:** WordPress + `lnkup-theme` + `lnkup-marketplace` plugin

---

## Architecture

| Layer | Path | Role |
|-------|------|------|
| Mock UI | `lnk-up-marketplace-ui/` | Visual/product reference (5-item nav, panel states) |
| Theme shell | `wp-content/themes/lnkup-theme/inc/dashboard.php` | Sidebar, bottom nav, role gates |
| Plugin UI | `wp-content/plugins/lnkup-marketplace/public/` | Shortcodes, feature CSS/JS, REST consumers |
| wp-admin | `wp-content/plugins/lnkup-marketplace/admin/` | Platform admin (no front-end shell) |

---

## Vendor routes

| Mock route | WP production URL | PHP / shortcode | CSS | JS | Data source | Priority | Notes |
|------------|-------------------|-----------------|-----|-----|-------------|----------|-------|
| `/vendor` | `/vendor-dashboard/` | `[lnkup_vendor_dashboard]` `?view=home` · `class-vendor-dashboard-views.php` | `vendor-dashboard.css` | `vendor-home.js` | `LNKup_Vendor_Dashboard_Data` | P1 | KPI row port deferred |
| `/vendor/find` | `/opportunities/` | `[lnkup_opportunities_feed]` · `class-shortcodes.php` | `marketplace-v2.css` | `marketplace-v2.js` | `GET /lnkup/v1/marketplace/search` · AJAX `lnkup_filter_opportunities` | **P0** | Heart of vendor app |
| `/vendor/requests` | `/vendor-dashboard/?view=applications` | `class-vendor-dashboard-views.php` | `vendor-dashboard.css` | — | Vendor applications query | P1 | Status tabs later |
| `/vendor/pass/[event]` | `/vendor-dashboard/?view=operations` | `vendor-ops.js` · `vendor-event-pass.css/js` | `operations.css` | `vendor-ops.js` | `/lnkup/v1/operations/*` · packets REST | P2 | Deep link per event |
| `/vendor/wallet` | `/vendor-dashboard/?view=payments` | `class-financial-render.php` | financial shell CSS | `financial.js` | `/lnkup/v1/financial/*` | P1 | Label → Wallet in nav |
| `/vendor/profile` | `/vendor-profile/` | `[lnkup_vendor_profile_form]` | profile form CSS | — | Profile meta + AJAX `lnkup_save_profile` | P1 | Documents/Reviews → Profile tabs later |

**Nav grouping (routes unchanged):** Documents, Growth, Reviews, Favorites, Event Operations → sidebar **More**. Favorites also surfaced in Find Events UI.

---

## Organizer routes

| Mock route | WP production | PHP / shortcode | CSS | JS | Data source | Priority | Notes |
|------------|---------------|-----------------|-----|-----|-------------|----------|-------|
| `/organizer` | `/organizer-dashboard/#overview` | `[lnkup_organizer_dashboard]` · `class-shortcodes.php` | `organizer-crm.css` | `organizer-command.js` | SSR + intelligence/fin REST | **P0** | Command center |
| `/organizer/events` | `#events` | same shortcode | `organizer-crm.css` | `organizer-command.js` | Organizer events query | P1 | Launch CTA on Home/Events |
| `/organizer/events/[id]` | `?event={id}` · `&ops=1` · hashes | `render_event_*_view` in shortcodes | `operations.css` | `operations.js` | Event-scoped REST | P2 | 6-tab workspace deferred |
| `/organizer/vendors` | `#vendors` · `#applications` | CRM modules in shortcodes | `organizer-crm.css` | `organizer-command.js` | `/lnkup/v1/crm/*` | P1 | Primary nav → Vendors |
| `/organizer/money` | `#financial` | `LNKup_Financial_Render::organizer_shell()` | fin shell CSS | `financial.js` | `/lnkup/v1/financial/*` | P1 | Label → Money |
| `/organizer/more` | `#lists` · `#invites` · `#operations` · `#intelligence` | hash sections | — | `organizer-command.js` | Various REST | P2 | Sidebar More group |

---

## Admin routes (wp-admin only — Phase 2b)

Mock `/admin/*` has **no** theme dashboard equivalent. Platform admin lives in wp-admin.

| Mock route | WP production | Admin PHP | Priority | Grouping target |
|------------|---------------|-----------|----------|-----------------|
| `/admin` | `admin.php?page=lnkup-marketplace` | `admin/class-admin.php` | P2b | Overview |
| `/admin/users` | Vendor/organizer/connector admin pages | `class-*-admin.php` | P2b | Users |
| `/admin/events` | External events · market pages | `class-external-events-admin.php` | P2b | Events |
| `/admin/money` | Financial admin | `class-financial-admin.php` | P2b | Money |
| `/admin/more` | Verification · settings · disputes | scattered submenus | P2b | More |

---

## Component port matrix

| Mock component | WP target | Port phase |
|----------------|-----------|------------|
| `app-shell` / `lib/nav.ts` | `inc/dashboard.php` + `dashboard.js` | **Phase 2a** (this sprint) |
| `opportunity-card` | `LNKupUI.renderOpportunityCard` + `lnkup-mp-v2__card` | Phase 2a polish |
| `states` / `usePanelState` | `LNKupUI.panelSkeleton/Empty/Error` | Phase 2a |
| `stat-card` | `lnkup_kpi_card()` | Phase 2b |
| `request-card` | vendor applications view | Phase 2b |
| `event-pass-card` | `vendor-event-pass.css/js` | Phase 2b |
| `incoming-request-card` | organizer apps snapshot | Phase 2b |
| `launch-checklist` | `render_organizer_launch_checklist()` | Exists (SSR) |
| Admin mock pages | wp-admin menus | Phase 2b |
| Role picker (`app/page.tsx`) | WP login + registration | Reference only |

---

## Shared assets

| Asset | Path |
|-------|------|
| Theme tokens | `lnkup-theme/assets/src/main.css` · `assets/dist/components.css` (`--lnkup-*`) |
| Panel helpers | `lnkup-marketplace/public/js/lnkup-ui.js` |
| UI copy | `lnkup-marketplace/includes/class-ui-copy.php` |
| Marketplace feed | `marketplace-v2.js` + `marketplace-v2.css` |

---

## Phase 2a deliverables (this sprint)

1. Simplified **5-item** vendor/organizer nav (primary + More secondary) in `dashboard.php`
2. Bottom nav aligned with mock (Vendor: Home/Find/Requests/Wallet; Organizer: Home/Events/Vendors/Money)
3. Find Events hero copy + panel skeleton/error polish
4. Organizer vendors preview error retry (existing `LNKupUI` pattern)

## Explicit exclusions

- No attendee ticketing flows
- No Stripe calls from UI
- No shortcode removal or REST route changes
- No wp-admin menu consolidation (Phase 2b)

---

## QA references

See [PHASE_2_QA.md](./PHASE_2_QA.md) for manual test checklist after deploy.

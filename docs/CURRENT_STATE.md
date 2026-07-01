# LNKup Marketplace Platform — Current State

Complete inventory of the LNKup vendor opportunity marketplace monorepo.

For the full architecture reference, see [SYSTEM_OVERVIEW.md](SYSTEM_OVERVIEW.md). For design tokens, components, surfaces, and copy guidelines, see [UI_UX.md](UI_UX.md).

**MVP scope:** Vendor-only marketplace layer — not attendee ticketing. Ticketing stays on Posh, Eventbrite, Universe, etc.

**Plugin version:** 12.17.0  
**Theme version:** 1.4.5

### Mock app UI (Phase 1 — `lnk-up-marketplace-ui/`)

The canonical **Next.js design mock** for vendor/organizer/admin dashboards lives in [`lnk-up-marketplace-ui/`](../lnk-up-marketplace-ui/). Phase 1 polishes this app only; WordPress visual port is Phase 2.

```bash
cd lnk-up-marketplace-ui
pnpm install
pnpm dev    # http://localhost:3000 — role picker (Vendor / Organizer / Admin)
```

- **10 core screens** with mock data in `lib/data.ts`
- **5-item nav** per role (`lib/nav.ts` + `components/app-shell.tsx`)
- **Panel states:** append `?empty=1` or `?error=1` on core routes for QA
- **Do not use** root `pnpm dev` — that serves the legacy `app/` template

### v12.17.0 (Review Queue V2)
- **Review tab triage:** Dedicated table with preview thumb + excerpt, vendor fit column (tier colors), readiness badges, matched vendor terms tooltip.
- **Sort & filter:** Default sort by `vendor_fit_score` DESC; min vendor fit, ready-to-publish-only, city filters; KPI strip (pending, ready, missing email, avg fit).
- **Bulk actions:** Reject selected; approve ready selected (email + valid image + event type required); capped at 50 IDs per request.
- **Backend:** `review_readiness()`, `review_queue_stats()`, extended `list_admin()` order/filter args.
- **Tests:** `tests/php/external-events-review-queue-test.php`; E2E review tab KPI/sort/bulk smoke.

### v12.16.0 (Vendor-Focused Auto Sync)
- **Vendor relevance gate:** Auto sync requires vendor-related terms in full title/description by default (`external_sync_vendor_filter_enabled`); customizable vendor term list; `skipped_vendor_filter` KPI on Auto sync tab.
- **vendor_fit_score:** Computed on import/refresh via `score_vendor_relevance()`; shown in Review queue as "Vendor fit: N".
- **Full description parsing:** Eventbrite `parse_url()` passes full `description` for filter, email extraction, and scoring (not 280-char excerpt only).
- **Additional keywords:** Optional AND filter renamed/clarified in admin; vendor filter is separate.
- **Sync timeout hardening:** `set_time_limit(300)`, `max_fetch_seconds` budget (default 240), fetch delay 1s, HTTP timeout 12s with retry, partial-run messaging.
- **Tests:** `tests/php/external-events-vendor-sync-test.php`; extended email extract + E2E auto sync vendor fields.

### v12.15.0 (Organizer Email Gap Workflow)
- **Description mining:** `LNKup_External_Events::extract_email_from_text()` scans event descriptions (including Eventbrite parser fallback before excerpt truncation) and auto-fills `organizer_email` on import, create, and re-fetch when scrape omits it.
- **Admin alert:** When a vendor applies without organizer email, `LNKup_Notifications::send_external_event_missing_email_admin()` emails LNKup admin (respects `notify_admin`) with a link to add the email.
- **Deferred notify:** `flush_pending_organizer_notifications()` sends the first organizer outreach when email is added later; marks all pending interests `organizer_notified`.
- **Admin UI:** Published events with vendor interest show inline email form, **Save email** / **Save & notify organizer**, pending-count badge, `needs_email=1` filter, analytics KPI `pending_organizer_notify`.
- **Tests:** `tests/php/external-events-email-extract-test.php`; E2E needs-email admin smoke in `external-events.spec.ts`.

### v12.14.0 (External Events Auto Sync — Phase 13C)
- **Auto sync:** `LNKup_External_Events_Sync` — scrape Eventbrite city browse pages for launch markets, import new events (`pending_review`), refresh stale rows via `merge_refetch_fields()`.
- **Cron:** `lnkup_external_events_sync` (daily or twice daily when enabled); separate from 15-minute follow-up cron.
- **Admin:** LNKup → External Events → **Auto sync** tab — enable, city checkboxes, limits, keyword filter, run now, last-run stats.
- **Provider:** `discover_event_urls()` on Eventbrite source; launch-city browse URL map for 10 markets.
- **Image fix:** Reject event page URLs stored as `image_url`; admin review warning + card illustration fallback.
- **REST:** `POST /external-events/provider-sync` supports `mode` (`full|discovery|refresh`) and optional `market`.
- **Tests:** `eventbrite-city-browse.html` fixture; extended parser smoke test + E2E auto sync tab.

### v12.13.0 (External Event Discovery V2 — Demand Engine, Phase 13B)
- **Schema V2:** `workflow_status`, demand score/metrics, email counters, activity/emails/duplicates tables; V1 rows backfilled to `published`.
- **Demand engine:** `LNKup_External_Events_Demand`, `Demand_Score`, threshold follow-ups (`Followup` + `Cron`), timeline logging.
- **Admin:** Review queue with inline edit (organizer email, image, event type), re-fetch from URL, email-on-file column, missing-field warnings; analytics KPI for published events missing organizer email.
- **Import:** Eventbrite URL parse enriches image (JSON-LD, og:image, __NEXT_DATA__), event type mapping, best-effort organizer email; **admin must add organizer email at review** for vendor-interest outreach when scrape omits it.
- **Claim landing:** `/claim-event/?token=` + `[lnkup_external_claim_landing]`; email CTAs use premium landing + open/click tracking.
- **Find Events:** Demand badges, score ring, category breakdown, include external / high demand filters, card motion.
- **REST:** Dashboard, analytics, providers, demand stats, import-url, merge, timeline, recommendations, publish/reject.
- **E2E:** Extended `tests/e2e/external-events.spec.ts`.

### v12.12.0 (External Event Discovery — Phase 13A)
- **SQL tables:** `lnkup_external_events`, `lnkup_external_event_interest`, `lnkup_external_event_claim_tokens`.
- **Admin:** LNKup → External Events — single form, CSV import, list filters, hide/claim actions.
- **Find Events:** Mixed grid with `.lnkup-event-card--external` — LNKup-style type badge, organizer row, category chips, themed illustration fallback when no remote image; legal disclaimer; vendor interest modal.
- **Organizer claim:** Sign-up `?claim_external_event=TOKEN` → claim REST → `lnkup_event` CPT; pending vendor interest panel on organizer dashboard.
- **REST:** `lnkup/v1/external-events/*` (search, interest, claim, admin import, organizer pending interests).
- **E2E:** `tests/e2e/external-events.spec.ts` + `tests/fixtures/external-events-sample.csv`.

### v12.11.1 (Financial dashboard + organizer UI fixes)
- **Sponsor payments:** `LNKup_Sponsor_Payments::total_revenue()` JOINs invoices (fixes `organizer_user_id` unknown column on `/financial/dashboard` JSON parse error).
- **Sponsor schema alignment:** `create_invoice()` / `record_payment()` use treasury columns (`sponsor_invoice_id`, `total_amount`, `paid_at`).
- **Organizer UI:** Event card v4 media column layout; intelligence expand panel load (no duplicate hero); hero title dedupe.

### v12.11.0 (Organizer Premium UI/UX Overhaul)
- **Command center:** Executive hero with checklist ring, 10 KPI strip, payout strip, quick-action row, apps snapshot, activity feed, readiness mini-cards.
- **Events & requests:** Gradient event card fallback, capacity bar thresholds, progressive disclosure on app cards.
- **CRM & discover:** Premium intel profile layout, contained timeline, verified filter, `panelEmpty` on lists/invites.
- **Financial / ops / intel / launch:** Organizer balance hero dedupe, wizard step gating, ops executive hero, intel score ring summary.
- **Design system:** Organizer section rhythm, launch checklist states, wizard custom inputs, hero title dedupe via `:has()`.

### v12.10.0 (Vendor Premium UI/UX Polish Pass)
- **Public vendor storefront:** Airbnb-style hero, gallery lightbox, metrics row, trust strip, sidebar reliability — `/vendors?vendor={slug}`.
- **Vendor home:** Executive hero, Action Center, business health KPIs with count-up, action tiles, deduped metrics, growth/ops previews.
- **Payments/Growth:** Premium invoice cards, deduped balance hero, `panelEmpty` on tabs, growth KPI stat cards, funnel visual, sticky tabs.
- **Design system:** Spacing tokens, status badge map, `animateKpiCards` / `mountSparkline`, array-aware `lnkup_empty_state()`.

### v12.9.0 (Premium Craftsmanship Pass)
- **UI-only:** Surface tier system (`.lnkup-surface--hero/primary/secondary/quiet`), typography utilities, shared chart/motion helpers in `LNKupUI`.
- **Executive heroes:** `lnkup_executive_hero()` on vendor apps, documents, payments, growth, reviews, operations; organizer events, vendor requests, financial, discover vendors.
- **Polish:** Vendor profile trust strip + section completion dots; marketplace card hierarchy; organizer event card v4 refinements; premium empty states with dual CTAs.
- **Theme:** `components.css` — compliance ring, executive hero, filter bar blur, table headers, empty-state icon.

### v12.8.0 (City Connector Program)
- **Tables:** `wp_lnkup_connectors`, `wp_lnkup_connector_events`; treasury `lnkup_referral_*` extended with `program=city_connector`, funnel columns, reward workflow fields.
- **Role:** `lnkup_connector` with dashboard caps; admin approval creates WP user + referral code.
- **Attribution:** `LNKup_Connector_Attribution` — cookie `lnkup_connector_ref`, signup meta, parallel with outreach; vendor `LNKup_Referral_Credits` unchanged for vendor program codes.
- **Tracking:** `LNKup_Connector_Tracking` — profile, application, payment revenue, event/opportunity milestones.
- **Rewards:** `LNKup_Connector_Rewards` — manual-by-default; admin approve/mark paid; optional `platform_credit` mode.
- **REST:** `lnkup/v1/connector/*`, `GET /referrals/resolve`, admin connector routes.
- **Public:** `/connector-interest/` interest form; city market CTA → interest page with `?market=`.
- **Dashboard:** `/connector-dashboard/` theme shell + `[lnkup_connector_dashboard]` + `connector.js/css`.
- **Admin:** LNKup → Connectors (pending/active/rewards); connector reward settings in LNKup Settings.
- **Tests:** `tests/e2e/connector-program.spec.ts`; extended `market-pages` + `outreach-invite` specs.

### v12.7.0 (City Market Landing Pages)
- **Registry:** `LNKup_Market_Pages` — option-backed city registry (10 launch cities), rewrite `/markets/{slug}/`, real SQL stats, discovery-backed opportunities.
- **Theme:** `page-templates/market-landing.php` + `template-parts/market/*` (hero, status, categories, opportunities, CTAs, FAQ).
- **SEO:** Rank Math title/description, robots, OG image, canonical, JSON-LD WebPage/ItemList, sitemap URLs.
- **Registration:** `?market=` + `?interest=connector` → `_lnkup_market_interest`, `_lnkup_signup_source`, `_lnkup_signup_city_page`.
- **Admin:** LNKup → City Markets — global toggle, per-market status/goals/SEO/featured taxonomies, flush rewrites.
- **Cross-links:** Market pages → `/opportunities/?city=&state=`; Find Events chip when city matches registry.
- **Tests:** `tests/e2e/market-pages.spec.ts`.

### v12.6.0 (Organizer Vendor CSV Import)
- **Import service:** `LNKup_Vendor_Import` — CSV parse/map/preview/run, prospect staging, duplicate modes, list/tag/invite integration.
- **Tables:** `lnkup_crm_import_job`, `lnkup_organizer_vendor_prospect`, `lnkup_vendor_list_prospect`.
- **REST:** `lnkup/v1/crm/import/*` (template, upload, preview, run, status) + `GET /crm/prospects`.
- **CRM UI:** 4-step import wizard (`vendor-import.js`), **Import vendors** CTA, **Imported** directory scope.
- **Outreach:** `mark_registered` links prospects → CRM relationship + list migration on vendor signup.
- **Admin:** `crm_import_max_rows_organizer`, `crm_import_max_file_size_mb`, `crm_import_allow_invite`.
- **Tests:** `tests/e2e/vendor-import.spec.ts` + `tests/fixtures/vendor-import-sample.csv`.

### v12.5.0 (Saved Searches & Opportunity Alerts)

- **Service:** `LNKup_Saved_Searches` — full saved search schema (alerts, frequency, pause, limits, visibility filtering).
- **REST:** `GET/POST /marketplace/saved-searches`, `PATCH/DELETE /{id}`, `POST /{id}/run` with vendor-only permissions.
- **UI:** Save-search modal on Find Events (no `prompt`); Saved Searches section on Favorites page (`saved-searches.js`).
- **Cron (1c):** Incremental daily/weekly digests via `created_after`; instant alerts on publish; favorite deadline reminders at 48h/24h.
- **Email:** Rich digest with opportunity cards, view-all and manage-alerts links.
- **Admin:** Saved search alert settings (limit, frequency, max matches per email).
- **Tests:** `saved-searches.spec.ts`.

### v12.4.0 (Trust & Verification)

- **Verification service:** `LNKup_Verification` orchestrates vendor/organizer trust levels, context-aware document requirements, and badge aggregation from profile strength, documents, reputation, Connect, and launch checklist data.
- **REST:** `GET /verification/vendor`, `/verification/vendors/{id}`, `/verification/organizer`, `/verification/organizers/{id}`, `POST /verification/admin/manual`.
- **Admin:** LNKup → Verification — document queue, vendor profile approval, organizer verified toggle.
- **Document modes:** Opportunities default to `none`; organizers can choose platform defaults or custom required documents (`_lnkup_document_mode`).
- **UI:** Vendor home trust card, organizer trust strip, application review badges, opportunity card organizer trust chips.
- **Tests:** `verification-trust.spec.ts`.

### v12.2.3 (financial dashboard JSON + balance display)

- **Dashboard REST:** Removed auto-reconcile from `/financial/dashboard` hot path; isolated report/payout failures with try/catch; UTF-8-safe REST sanitization.
- **Balances route:** `GET /financial/organizer-balances` — slim payload for hero/overview (Stripe Connect + `display_balances`).
- **Reports:** `organizer_report()` guards legacy `LNKup_Payouts` queries; health score uses lightweight SQL instead of full report recursion.
- **Frontend:** Wallet-first balance loading; dashboard optional for revenue KPIs; JSON parse errors log response body snippet.

### v12.2.2 (organizer financial balance display)

- **Dashboard:** `/financial/dashboard` no longer zeroes balances when reports layer throws; always attaches Stripe Connect + `display_balances`.
- **Balances:** Shared `organizer_balance_payload()` for dashboard, wallet, and fallback states.
- **Frontend:** Balance hero and Overview merge `/organizer-wallet` (live Stripe) with dashboard; destination Connect pending/available shown consistently.
- **Payments:** Auto-reconcile on dashboard load; `payment_intent.processing` webhook; Sync payment button for stuck pending transactions.
- **Fees:** Vendor checkout fee $5.99 settings-first model; pending payment fee refresh at checkout (v12.1.7 carryover).

### v12.2.1 (weather integration polish)

- **Activator:** Weather snapshots table + daily cron scheduled on plugin activation.
- **Frontend:** `weather.js` loads before operations/intelligence; poll hydrates ops panel from cached snapshot DTO; vendor ops home shows weather note; intelligence insights render weather block.
- **UI:** Insights tab SSR weather block; compact readiness card test IDs; script dependency order fixed.
- **Tests/docs:** `weather-intelligence.spec.ts`; SYSTEM_OVERVIEW §9.6.1 cron + assets tables.

### v12.2.0 (Event Weather Intelligence — OpenWeather)

- **Weather module:** `LNKup_Weather_Service`, OpenWeather provider, risk engine, snapshots table, REST (`/weather/*`), daily cron, render helpers.
- **Modes:** Live forecast (0–7d), extended (8–16d), historical risk (17+d), graceful unavailable states.
- **UI:** Organizer Weather Readiness card; Event Operations weather panel + Insights block; intelligence DTO `weather`; vendor packet note when enabled.
- **Settings:** LNKup → Settings → Weather Intelligence — enable toggle, OpenWeather API key (server-side only), units, cache TTL, historical toggle, vendor packet note.
- **Frontend:** `weather.js` / `weather.css` — refresh/retry, panel hydration from cached snapshot DTOs.

### v12.1.7 (vendor checkout fee + payment finalize + intelligence fix)

- **Fee engine:** Vendor checkout fee resolves from `vendor_checkout_fee` settings ($5.99 default); legacy `default_fee` aliases synced on migration; global flat SQL fee rules deactivated.
- **Pending payments:** Fees recalculate at checkout, on settings save, and when admin loads (deferred upgrade).
- **Checkout finalize:** Stripe 3DS return URL auto-confirms; stuck pending payments finalize when Stripe intent already succeeded; safer reconcile guard.
- **Event Intelligence:** Organizer expand panel no longer stuck on skeleton — 8s fetch timeout, in-flight dedupe, `lnkup-intel-expand` hook, single retry handler, REST `dashboard()` array guards.

### v12.1.6 (operations command center UX enhancement)

- **Operations nav architecture:** Organizer event command center now uses 5 grouped section pills (Command, Logistics, Maps, Field, Intel) plus sticky sub-tabs with `ops_tab` deep-link restore.
- **Overview cleanup:** Compliance Alerts and Live Feed moved into the Overview panel; added logistics snapshot stats and quick section jumps.
- **Panel consistency:** Added shared panel shells, per-panel headers, and stats strips for logistics/field/intel tabs.
- **Loader and mobile UX:** Unified skeleton/empty/error behavior for REST tabs, cached panel loading, mobile card rendering for table data, and readiness filter chips.
- **Cross-module coordination:** New `lnkup-ops-panel-open` event syncs Operations with Financial/Insights/Maps lazy loading.

### v12.1.5 (vendor UX + ops prefill)

- **Vendor Payments:** Financial REST honors `?role=vendor` for dual-role users; hero KPIs align with transactions (Amount due, Total spent, Credits); 4-section nav (Pay / Wallet / Extras / Reports); subscriptions tab hidden; mobile transaction cards.
- **Referral Credits:** Schema-aligned treasury inserts; `?ref=` cookie capture; share link + rewards history in financial UI.
- **Growth Markets:** Fixed API key mismatches in Markets tab; KPI strip, trending chips with Find Events deep links, relationship cards, ROI bars, mobile layout.
- **Find Events:** Renamed filters (All categories / All subtypes); attendance + market toolbar wired to live search; subtype hint text.
- **Event Operations:** `bootstrap_from_event()` on create + first command-center load; `GET /operations/events/{id}/defaults`; slide-over add forms replace `window.prompt`.

---

## Monorepo Layout

```
lnk-up-marketplace-platform/
├── app/                              # Next.js 16 reference UI (v0 template)
├── components/                       # React/shadcn components (reference)
├── package.json                      # Next.js app (pnpm, React 19, Tailwind 4)
├── README.md                         # Setup instructions
├── docs/CURRENT_STATE.md             # This file
└── wp-content/
    ├── themes/lnkup-theme/           # WordPress theme (production UI)
    └── plugins/lnkup-marketplace/    # Marketplace logic plugin
```

---

## 1. Next.js Reference Frontend (Design Source)

Runnable v0 template kept for design comparison. **Not wired to WordPress** — static/mock UI only.

**Stack:** Next.js 16.2, React 19, Tailwind 4, shadcn/ui, Recharts, Lucide icons.

### Routes (`app/`)

| Area | Pages |
|------|-------|
| Landing | `/` (`page.tsx`) |
| Vendor dashboard | `/dashboard`, `/dashboard/find-events`, `/dashboard/applications`, `/dashboard/documents`, `/dashboard/payments`, `/dashboard/profile`, `/dashboard/reviews` |
| Organizer dashboard | `/organizer`, `/organizer/create`, `/organizer/import`, `/organizer/applications`, `/organizer/vendors`, `/organizer/payouts` |
| Admin | `/admin`, `/admin/events`, `/admin/vendors`, `/admin/organizers`, `/admin/analytics` |

### Components (`components/`)

- **Landing:** `hero`, `how-it-works`, `categories`, `featured`, `site-header`, `site-footer`
- **Dashboard:** `shell`, `stat-card`, `status-badge`, `app-status-badge`
- **Brand:** `logo`
- **UI:** 22 shadcn primitives (button, card, table, dialog, sidebar, chart, etc.)
- **Shared:** `event-card.tsx`

**Run locally:**

```bash
pnpm install && pnpm dev
```

---

## 2. WordPress Theme — `wp-content/themes/lnkup-theme`

Classic PHP theme (not block/FSE). Black/white/lime accent matching the v0 design.

### Templates

| File | Purpose |
|------|---------|
| `front-page.php` | Landing page — composes all landing partials |
| `page.php` | Default page with site header/footer |
| `index.php` | Fallback → `page.php` |
| `header.php` / `footer.php` | Minimal HTML shell |

### Page Templates

| Template | Role gate | Shortcode |
|----------|-----------|-----------|
| `page-templates/vendor-dashboard.php` | `lnkup_vendor`, `lnkup_admin`, `administrator` | `[lnkup_vendor_dashboard]` |
| `page-templates/organizer-dashboard.php` | `lnkup_organizer`, `lnkup_admin`, `administrator` | `[lnkup_organizer_dashboard]` |
| `page-templates/full-width.php` | None | Raw page content |

### Landing Sections (`template-parts/landing/`)

- `site-header.php` — sticky nav, mobile menu, login/dashboard CTAs
- `hero.php` — hero + CTA + trust bullets
- `how-it-works.php` — 4-step onboarding
- `categories.php` — 13 vendor category chips
- `featured.php` — featured events grid (plugin data or fallback)
- `trust.php` — stats / trust messaging
- `final-cta.php` — bottom conversion banner
- `site-footer.php` — multi-column footer

### Theme Modules (`inc/`)

| File | Role |
|------|------|
| `setup.php` | `title-tag`, thumbnails, HTML5, nav menu registration, page template labels |
| `enqueue.php` | Google Fonts (Sora + Inter), CSS, JS |
| `template-tags.php` | Logo, asset URLs, page URLs, dashboard detection |
| `dashboard.php` | Login/role guards, sidebar nav, dashboard shell markup |

### Assets

| Asset | Purpose |
|-------|---------|
| `assets/src/main.css` | Tailwind v4 source + design tokens |
| `assets/dist/lnkup.css` | Compiled Tailwind (enqueued) |
| `assets/dist/components.css` | Hand-written BEM styles (header, hero, dashboard, forms, plugin UI) |
| `assets/js/theme.js` | Mobile nav toggle |
| `assets/js/dashboard.js` | Dashboard sidebar toggle |
| `assets/images/events/*.svg` | 5 event illustration SVGs |

**Build CSS:**

```bash
cd wp-content/themes/lnkup-theme && npm install && npm run build
```

### Theme Gaps (intentional / not built)

- No `single.php`, `archive.php`, `404.php`, `theme.json`
- Nav menus registered but templates use hardcoded links
- No Customizer, widget areas, or block patterns
- No `screenshot.png` or `languages/` folder

---

## 3. WordPress Plugin — `wp-content/plugins/lnkup-marketplace`

All marketplace business logic lives here. Bootstrap: `lnkup-marketplace.php`.

### PHP Classes

| Class | File | Purpose |
|-------|------|---------|
| `LNKup_Activator` | `includes/class-activator.php` | Activation, pages, sample data |
| `LNKup_Roles` | `includes/class-roles.php` | Roles, registration role picker |
| `LNKup_Post_Types` | `includes/class-post-types.php` | 7 CPT registrations |
| `LNKup_Taxonomies` | `includes/class-taxonomies.php` | 3 taxonomies + term seeding |
| `LNKup_Meta` | `includes/class-meta.php` | Meta schema + admin meta boxes |
| `LNKup_Capabilities` | `includes/class-capabilities.php` | Ownership checks, `user_has_manage_all()` |
| `LNKup_Security` | `includes/class-security.php` | Nonces, MIME allowlist, sanitization |
| `LNKup_Applications` | `includes/class-applications.php` | Apply, approve, reject, waitlist |
| `LNKup_Payments` | `includes/class-payments.php` | Payment records, fee calculation |
| `LNKup_Packets` | `includes/class-packets.php` | Vendor packet lock/unlock, QR pass |
| `LNKup_Stripe` | `includes/class-stripe.php` | Checkout API, test simulation, webhook |
| `LNKup_Documents` | `includes/class-documents.php` | Upload, download, verification |
| `LNKup_Notifications` | `includes/class-notifications.php` | HTML email via `wp_mail` |
| `LNKup_Ajax` | `includes/class-ajax.php` | Logged-in AJAX handlers |
| `LNKup_Shortcodes` | `public/class-shortcodes.php` | Frontend shortcode renderers |
| `LNKup_Admin` | `admin/class-admin.php` | Admin menu + settings |
| Helpers | `includes/helpers.php` | Currency, queries, event card HTML |

### Custom Post Types (7)

| Slug | Label | Public | Notes |
|------|-------|--------|-------|
| `lnkup_vendor_profile` | Vendor Profiles | No | Business profile per vendor |
| `lnkup_event` | Events | Yes | Organizer events |
| `lnkup_opportunity` | Opportunities | Yes | Vendor slots per event |
| `lnkup_application` | Applications | No | Vendor applications |
| `lnkup_document` | Documents | No | Compliance uploads |
| `lnkup_payment` | Payments | No | Payment records |
| `lnkup_packet` | Vendor Packets | No | Post-payment venue info |

### Taxonomies (3)

| Slug | Attached To | Seeded Terms |
|------|-------------|--------------|
| `lnkup_vendor_category` | vendor profiles, opportunities | 13 (Food Vendor, Food Truck, etc.) |
| `lnkup_event_type` | events | 12 (Festival, Rodeo, etc.) |
| `lnkup_market` | events, opportunities | Hierarchical, no seed |

### Roles & Capabilities

| Role | Key capabilities |
|------|------------------|
| `lnkup_vendor` | `manage_own_profile`, `view_opportunities`, `submit_applications`, `manage_own_documents`, `view_own_payments` |
| `lnkup_organizer` | `manage_own_events`, `manage_own_opportunities`, `review_applications`, `manage_own_packets`, `view_own_payments` |
| `lnkup_admin` | `manage_all`, `verify_documents`, `manage_settings` |
| `administrator` | Receives all LNKup caps on activation |

Registration form adds Vendor/Organizer dropdown; `user_register` assigns selected role.

### Shortcodes (8)

| Shortcode | Purpose |
|-----------|---------|
| `[lnkup_vendor_dashboard]` | Vendor overview stats + quick links |
| `[lnkup_organizer_dashboard]` | Organizer overview stats |
| `[lnkup_opportunities_feed]` | Public browse/apply feed |
| `[lnkup_vendor_profile_form]` | Vendor business profile CRUD |
| `[lnkup_organizer_events]` | Event list + opportunity creation |
| `[lnkup_applications_manager]` | Organizer application review |
| `[lnkup_documents_manager]` | Vendor document uploads |
| `[lnkup_payments_dashboard]` | Payment history + checkout |

### AJAX Actions (logged-in only)

| Action | Purpose |
|--------|---------|
| `lnkup_apply` | Submit vendor application |
| `lnkup_save_profile` | Create/update vendor profile |
| `lnkup_upload_document` | Upload compliance document |
| `lnkup_review_application` | Organizer approve/waitlist/reject |
| `lnkup_create_event` | Create/import event |
| `lnkup_create_opportunity` | Create opportunity for event |
| `lnkup_download_document` | Secure document download |
| `lnkup_export_vendors` | CSV export for event |
| `lnkup_create_checkout` | Start Stripe or simulation checkout |
| `lnkup_simulate_payment` | Test-mode payment completion |

**REST:** `POST /wp-json/lnkup/v1/stripe-webhook` — handles `checkout.session.completed`

### Application Workflow

```
Vendor applies → submitted
Organizer opens → under_review
Organizer approves → payment_required (Payment CPT created)
Vendor pays (Stripe or simulation) → paid → packet unlocked
```

**Application statuses:** `submitted`, `under_review`, `approved`, `payment_required`, `paid`, `waitlisted`, `rejected`, `completed`, `cancelled`

**Payment statuses:** `pending`, `paid`, `failed`, `refunded`, `disputed`

**Document types:** `certificate_of_insurance`, `health_permit`, `business_license`, `w9`, `sales_tax_certificate`, `other`

### Admin UI

| Page | Capability | Content |
|------|------------|---------|
| **LNKup** (top-level) | `lnkup_manage_all` | Stats: vendors, organizers, events, applications, GMV, revenue, pending docs |
| **Settings** (submenu) | `lnkup_manage_settings` | Fees, Stripe keys, test mode, packet lock, default documents, notifications |
| Native CPT screens | WP defaults | All 7 CPTs editable in wp-admin |

### Plugin Settings (`lnkup_settings` option)

| Key | Default |
|-----|---------|
| `vendor_checkout_fee` | `5.99` |
| `default_fee` | `5.99` (legacy alias, synced with `vendor_checkout_fee`) |
| `application_fee_flat` | `5.99` (legacy alias) |
| `organizer_commission_percent` | `6` |
| `fee_payer_mode` | `vendor` |
| `stripe_public_key` / `stripe_secret_key` | empty |
| `test_mode` | `true` |
| `default_packet_lock` | `true` |
| `default_documents` | COI + business license |
| `notify_vendor` / `notify_organizer` / `notify_admin` | `true` |
| `support_email` | site admin email |

Pending payments recalculate vendor checkout fee + organizer commission from these settings at checkout (not only at application approval).

### Activation Behavior

On first activate (`includes/class-activator.php`):

1. Register roles, CPTs, taxonomies + seed taxonomy terms
2. Create 4 pages with shortcodes + theme templates
3. Seed 5 sample events + opportunities (Charlotte, Atlanta, Miami, Raleigh, Greensboro)
4. Create demo user `lnkup_demo_organizer` (`lnkup_organizer` role)
5. Set front page to `home` if none configured
6. Flush rewrite rules

| Page slug | Title | Shortcode |
|-----------|-------|-----------|
| `vendor-dashboard` | Vendor Dashboard | `[lnkup_vendor_dashboard]` |
| `organizer-dashboard` | Organizer Dashboard | `[lnkup_organizer_dashboard]` |
| `opportunities` | Find Events | `[lnkup_opportunities_feed]` |
| `vendor-profile` | Vendor Profile | `[lnkup_vendor_profile_form]` |

**Deactivation:** flush rewrites only (data persists).

**Uninstall:** deletes settings options only — not CPT posts or roles.

---

## 4. Production Issues & Fixes Applied

### Issue 1: White screen on plugin activation (fixed)

Hardening across bootstrap, meta registration, shortcodes, activator, and admin error notices:

- PHP 7.4+ guard with admin notice
- Safe `file_exists()` requires
- Removed PHP 7.4-incompatible arrow functions in shortcodes
- Boolean meta `default` + `rest_sanitize_boolean`
- Money fields as `type => string`
- Activator try/catch + safer demo seeding
- Activation error transient shown in admin

### Issue 2: Memory exhaustion on thelnkup.com (fixed in repo)

**Symptom:** `Allowed memory size of 805306368 bytes exhausted` in `wp-includes/functions.php`

**Cause:** Infinite recursion in `map_meta_cap` — called `user_can('lnkup_manage_all')` which re-entered the same filter.

**Fix in `includes/class-capabilities.php`:**

- Removed broken `map_meta_cap` filter
- Added `LNKup_Capabilities::user_has_manage_all()` — checks `$user->allcaps` directly
- Replaced all `user_can('lnkup_manage_all')` calls across 7 plugin files

**Deploy:** Upload updated plugin to production (`thelnkup.com`) to take effect.

---

## 5. WordPress Setup Checklist

1. Copy/link `wp-content/themes/lnkup-theme` and `wp-content/plugins/lnkup-marketplace` into WordPress
2. Activate **LNKup Marketplace** plugin
3. Activate **LNKup Theme**
4. Build theme CSS (`npm run build` in theme dir)
5. Confirm **Settings → Reading** uses static front page
6. Configure Stripe under **LNKup → Settings** (or use test-mode simulation)
7. Assign `lnkup_vendor` / `lnkup_organizer` roles to test users

---

## 6. What Is NOT Built Yet

These exist in the Next.js reference but have no WordPress equivalent yet (or are placeholder-only):

- Admin analytics dashboard (beyond basic LNKup overview stats and Financial Center)
- Live Stripe Connect onboarding (payout abstraction + manual mark-paid exists)
- Vendor reviews system (reputation reviews exist; separate from payment disputes)
- Event import flow (AJAX stub exists; no full import UI)
- Attendee ticketing
- Block theme / FSE support
- WP nav menu integration in theme header/footer
- Full `map_meta_cap` ownership mapping for CPT edit/delete (currently uses ownership helpers in plugin logic only)
- CI/CD pipeline

### Phase 9 Financial Platform (v9.0.0) — Built

| Component | Notes |
|-----------|--------|
| SQL ledger (`lnkup_transactions` + 16 related tables) | Dual-write from `lnkup_payment` CPT |
| Payment provider abstraction | `LNKup_Stripe_Provider`, `LNKup_Manual_Provider` |
| Invoices, receipts, wallet, credits, refunds, disputes, payouts | Full service layer + audit log |
| Installments + deposits | Opportunity meta-driven; checkout partial amounts |
| Promo codes | Validate/redeem at checkout |
| Vendor financial hub | `?view=payments` with lazy REST tabs |
| Organizer `#financial` panel | Revenue, payouts, refunds, disputes, reports |
| Admin Financial Center | `LNKup → Financial` wp-admin submenu |
| Event Financials tab | Inside event operations console |
| REST API | `lnkup/v1/financial/*` |
| E2E tests | `vendor-financial.spec.ts`, `organizer-financial.spec.ts` |

Placeholder-only: 1099/W-9 PDF generation, Apple/Google Pay, ACH, email receipts, fraud ML.

### Phase 10 Vendor Growth Platform (v10.0.0) — Built

| Component | Notes |
|-----------|--------|
| Growth SQL tables | Snapshots, benchmarks, goals, ROI, profit estimates, milestones, recommendations |
| Vendor Growth Dashboard | `?view=growth` with lazy REST tabs (`growth.css` / `growth.js`) |
| KPI strip | Lifetime revenue, vendor score, growth rate, profile strength, etc. |
| Revenue analytics | Over time, by month, by event/city/organizer/category/season |
| Application funnel | Vendor-scoped approval/rejection/waitlist analytics |
| Match scores | `LNKup_Growth_Match` on opportunity cards + REST |
| Goals + achievements | CRUD goals; badge progress from `LNKup_Vendor_Badges` |
| Profile strength | 17-item checklist score |
| Rule-based recommendations | `lnkup_vendor_recommendations` with dismiss |
| Benchmarks | Marketplace percentiles (no private vendor data exposed) |
| ROI + profit tracking | Private worth-it ratings; organizers cannot see |
| Organizer relationships | Top organizers, repeat bookings, favorites |
| Customer journey | Milestone timeline |
| Business calendar | Events, deadlines, payments, goals, document expirations |
| CSV exports | Revenue, events, performance (PDF placeholder) |
| Admin Growth Center | `LNKup → Growth` marketplace aggregates only |
| REST API | `lnkup/v1/growth/*` |
| E2E tests | `vendor-growth.spec.ts` |

AI-ready placeholders: revenue forecast endpoint returns null; recommendation `source` field for future AI swap.

---

## 7. File Counts Summary

| Area | Files (approx.) |
|------|-----------------|
| Plugin PHP + JS + templates | 21 source files |
| Theme PHP + CSS + JS | ~30 source files (excl. node_modules) |
| Next.js app routes | 24 pages |
| React components | 34 files |

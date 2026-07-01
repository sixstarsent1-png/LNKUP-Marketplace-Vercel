# Cursor Context — LNK Marketplace

Human-readable context for AI-assisted development. Pair with `.cursor/rules/lnkup-architecture.mdc` (always-applied agent rules).

**Deep references:** [SYSTEM_OVERVIEW.md](SYSTEM_OVERVIEW.md) · [CURRENT_STATE.md](CURRENT_STATE.md) · [UI_UX.md](UI_UX.md)

---

## 1. Project Identity

**LNK Marketplace** (LNKup) is a WordPress-based **two-sided marketplace** for the event vendor economy.

| Persona | What they do |
|---------|--------------|
| **Vendors** | Discover events, apply for booth/slot opportunities, pay fees, upload compliance documents, unlock venue packets, track growth and reputation |
| **Organizers** | Create events and opportunities, review applications, manage vendor relationships, collect payments, run day-of operations, use CRM and intelligence |
| **LNKup platform** | Applications, payments, financial routing, operations, CRM, reputation, growth analytics, organizer intelligence, marketplace fees |

**Not in scope:** Attendee ticketing (stays on Posh, Eventbrite, Universe, etc.). LNKup owns the **vendor layer** only.

**Current version:** Plugin v12.1.0 · Theme v1.0.0 · ~177 PHP files · ~200 REST routes · 70+ SQL tables · 12 Playwright E2E specs.

---

## 2. Production Source of Truth

| Layer | Technology | Status |
|-------|-----------|--------|
| **Production** | WordPress 6.0+ + `lnkup-theme` + `lnkup-marketplace` | Live marketplace |
| **Reference (canonical)** | Next.js in `lnk-up-marketplace-ui/` | Runnable mock app — Phase 1 target |
| **Reference (legacy)** | Next.js in root `app/` + `components/` | Older v0 template; do not expand |

### Reference UI

**`lnk-up-marketplace-ui/`** is the canonical runnable mock (vendor / organizer / admin shells, mock data, panel states). Dev: `cd lnk-up-marketplace-ui && pnpm dev`.

Root `app/` is legacy (marketing landing + older dashboards). Phase 2 ports winning patterns from `lnk-up-marketplace-ui/` into the WordPress theme/plugin — **without** replacing production files in Phase 1.

Unless the user explicitly asks to work on the reference UI, **production feature work belongs in the WordPress plugin and theme**.

---

## 3. Main Paths

```
lnk-up-marketplace-platform/
├── wp-content/
│   ├── plugins/lnkup-marketplace/     # ★ Production business logic
│   │   ├── lnkup-marketplace.php      # Bootstrap (v12.1.0)
│   │   ├── includes/                  # Domain services + REST
│   │   ├── includes/financial/        # Financial OS + Connect treasury
│   │   ├── public/                    # Shortcodes, JS, CSS
│   │   └── admin/                     # wp-admin pages
│   └── themes/lnkup-theme/            # ★ Production UI shell
│       ├── inc/dashboard.php          # Role gates, nav
│       ├── page-templates/            # Dashboard templates
│       └── assets/dist/               # Compiled CSS
├── app/                               # Reference Next.js (NOT production)
├── components/                        # Reference React (NOT production)
├── tests/e2e/                         # Playwright specs (WordPress target)
├── docs/                              # Architecture docs
├── scripts/build-plugin-zip.ps1       # Plugin zip builder
├── package.json                       # Next.js + Playwright (root)
└── playwright.config.ts               # baseURL: localhost:8888
```

**Auto-created WordPress pages:** `vendor-dashboard`, `organizer-dashboard`, `opportunities` (Find Events), `vendor-profile`, `vendors` (directory).

---

## 4. Architecture Rules

| Rule | Detail |
|------|--------|
| **CPT + meta canonical** | Business records live in custom post types and post meta. Do not duplicate domain entities in SQL alone. |
| **SQL for optimization** | Custom tables for search indexes, ledgers, analytics snapshots, audit logs, relational joins. |
| **Financial Service required** | All payments route through `LNKup_Financial_Service`. No direct Stripe from UI/AJAX/REST/random classes. |
| **Fee engine** | Use `LNKup_Fee_Engine` — do not hardcode fees in UI files. |
| **No WooCommerce** | Excluded unless explicitly requested. |
| **No attendee ticketing** | Out of scope. |
| **Theme vs plugin** | Theme = layout/shell/CSS. Plugin = all business logic. |
| **REST security** | Every route needs `permission_callback`. Financial routes are never public. |
| **WP_Error handling** | Always `is_wp_error()` before array access. Never leak PHP notices into JSON. |
| **Role separation** | Vendor dashboard ≠ organizer dashboard. Preserve capability/ownership checks. |
| **Event + opportunity** | Creating an event should also create the first opportunity with `_lnkup_event_id`. |

### Roles

- `lnkup_vendor` — vendor dashboard, applications, payments, growth, reputation, operations
- `lnkup_organizer` — events, applications, CRM, financial, operations, intelligence
- `lnkup_admin` / `administrator` — platform admin, bypass via `LNKup_Capabilities::user_has_manage_all()`

---

## 5. Module Map

### Marketplace Discovery / Find Events

- **Classes:** `LNKup_Marketplace_Index`, `LNKup_Discovery`, `LNKup_Trending`, `LNKup_Recommendations`, `LNKup_Collections`, `LNKup_Marketplace_REST`
- **SQL:** `lnkup_marketplace_index`, `lnkup_analytics_events`
- **Frontend:** `[lnkup_opportunities_feed]` on `/opportunities/`, `marketplace-v2.js`
- **Sync:** Index rebuilds on `save_post_lnkup_opportunity` / `save_post_lnkup_event`

### Application → Payment → Packet

- **Flow:** apply → review → approve → payment_required → paid → packet unlocked
- **Classes:** `LNKup_Applications`, `LNKup_Payments`, `LNKup_Packets`, `LNKup_Documents`, `LNKup_Ajax`
- **Checkout:** `LNKup_Checkout`, `checkout.js`, Stripe Payment Element or test simulation

### Financial OS

- **Service:** `LNKup_Financial_Service` (mandatory entry point)
- **Connect:** `LNKup_Stripe_Connect` — Express accounts, destination charges, organizer wallet, cashouts
- **REST:** `lnkup/v1/financial/*` (~61 routes)
- **Frontend:** `financial.js` — organizer `#financial`, vendor `?view=payments`
- **Docs:** `wp-content/plugins/lnkup-marketplace/FINANCIAL.md`

### Organizer CRM

- **Classes:** `LNKup_Organizer_CRM`, `LNKup_Vendor_Directory`, `LNKup_Vendor_Lists`, `LNKup_Vendor_Invites`, `LNKup_Organizer_CRM_REST`
- **Frontend:** `organizer-crm.js`, organizer dashboard vendor views

### Vendor Reputation

- **Classes:** `LNKup_Vendor_Reputation`, `LNKup_Vendor_Reviews`, `LNKup_Vendor_Badges`, `LNKup_Reputation_REST`
- **CPTs:** `lnkup_review`, reliability metrics in SQL

### Event Operations

- **Classes:** `LNKup_Event_Operations`, `LNKup_Booths`, `LNKup_Parking`, `LNKup_Checkins`, `LNKup_Operations_REST`
- **Frontend:** `operations.js` — vendor event-day panel (not QR-only)

### Organizer Intelligence

- **Classes:** `LNKup_Organizer_Intelligence`, `LNKup_Risk_Engine`, `LNKup_Funnel_Analytics`, `LNKup_Intelligence_REST`
- **Frontend:** `intelligence.js` — event health, risk alerts, funnels

### Interactive Maps

- **Classes:** `LNKup_Maps`, `LNKup_Map_Provider`, `LNKup_Map_REST`
- **Providers:** Leaflet (default), Mapbox/Google optional

### Vendor Growth Platform

- **Classes:** `LNKup_Growth`, `LNKup_Growth_KPIs`, `LNKup_Growth_REST`
- **Frontend:** `growth.js` — `?view=growth`
- **Critical:** `/growth/dashboard` must always return valid JSON with zero-state for new vendors

### Admin / Shortcodes / AJAX

- **Shortcodes:** `LNKup_Shortcodes` — vendor/organizer dashboard shells
- **Admin:** `LNKup_Admin`, Financial Center, Growth Center, Connect admin
- **AJAX:** `LNKup_Ajax` — legacy logged-in actions

---

## 6. Current Priorities

Active focus areas (as of June 2026):

1. **Payment and Stripe-first financial system** — embedded checkout, fee engine, Connect treasury, organizer wallet/cashouts
2. **Organizer dashboard UI/UX** — command-center feel, Financial panel, applications, vendor directory
3. **Vendor dashboard UI/UX** — Find Events, Payments, Growth, Operations, Reputation
4. **Find Events marketplace UX and filtering** — search ranking, filter chips, mobile cards, Apply button behavior
5. **Vendor directory showing current vendors** — Discover Vendors must reflect live vendor profiles
6. **Event intelligence loading bug** — intelligence panel stuck loading when REST errors are swallowed
7. **Growth dashboard 500 error** — `Cannot use object of type WP_Error as array` on `/growth/dashboard`
8. **Event creation should also create an opportunity** — default Create Event UX creates both records
9. **Improve Stripe Connect wording/UI** — user-friendly copy; do not implement full Connect onboarding unless requested

---

## 7. Common Bug Patterns

| Pattern | Symptom | Fix approach |
|---------|---------|--------------|
| REST returns `WP_Error`, JS expects array | 500 or JS crash | Guard with `is_wp_error()` in REST callback; return `WP_Error` with status; JS checks `response.ok` + JSON |
| JS assumes fields exist | `undefined` errors, blank cards | Optional chaining, defaults, empty-state UI |
| Dashboard cards refresh page | Full page reload on click | Use `preventDefault()`, `type="button"`, hash/query nav without form submit |
| Capability checks block valid data | Empty panels for correct user | Trace `LNKup_Capabilities` helpers; verify role + ownership meta |
| Marketplace index not synced | Search misses new events | Trigger index rebuild; check `save_post_*` hooks and deferred migrations |
| Discover Vendors empty | No vendors shown | Check `LNKup_Vendor_Directory` query, profile CPT status, index filters |
| Intelligence loading forever | Spinner never stops | REST error swallowed in JS; add error state, fix backend `WP_Error` |
| Growth API 500 | `WP_Error as array` fatal | Each growth module must convert `WP_Error` to safe fallback data |
| Technical Stripe copy | "Stripe Connect available", "settlement destination" | Replace with "Payouts Active", "Finish payout setup", etc. |
| Connect disabled 500 | Financial panel breaks | Return safe JSON `{ success: false, code: "connect_not_configured" }` |
| `map_meta_cap` recursion | Memory exhaustion | Use `LNKup_Capabilities::user_has_manage_all()` — never `user_can()` inside `map_meta_cap` |

---

## 8. Cursor Prompt Templates

### Bug fix

```
We are in the LNK Marketplace WordPress repo (production: wp-content/plugins/lnkup-marketplace + lnkup-theme).

Bug: [describe symptom]
URL/view: [e.g. organizer dashboard #intelligence, vendor ?view=growth]
Expected: [behavior]
Actual: [behavior]

Trace JS → REST/AJAX → PHP service → CPT/SQL. Fix root cause.
Add is_wp_error guards. Preserve capabilities and response shapes.
Do not touch app/ (reference UI). Summarize files changed.
```

### UI/UX pass

```
LNK Marketplace UI/UX pass on [vendor dashboard Find Events | organizer Financial | etc.].

Design: black/white/lime, premium SaaS, mobile-first.
Add loading, empty, and error states. Hide empty sections.
No technical Stripe jargon. No infinite spinners.
Production code only: wp-content/themes/lnkup-theme + plugin public/css + public/js.
```

### REST/API debugging

```
Debug LNK Marketplace REST route: GET/POST /wp-json/lnkup/v1/[route]

Check: permission_callback, capability/ownership, is_wp_error handling, valid JSON response.
File likely in includes/class-*-rest.php.
Do not echo HTML or PHP notices. Return WP_Error with status code on failure.
```

### Financial system changes

```
LNK Marketplace financial change: [describe]

All logic must route through LNKup_Financial_Service and LNKup_Fee_Engine.
Do not call Stripe from JS/shortcodes. Use includes/financial/ classes.
Organizer uses Financial (#financial); vendor uses Payments (?view=payments).
Preserve Connect safe-fallback JSON when Connect disabled.
See FINANCIAL.md and docs/SYSTEM_OVERVIEW.md §13.
```

### Dashboard feature build

```
Build [feature] for [vendor | organizer] dashboard in LNK Marketplace.

Start from existing shortcode/view in public/class-shortcodes.php or class-vendor-dashboard-views.php.
Extend existing service class in includes/ — do not rebuild from scratch.
Add REST route in class-*-rest.php with permission_callback.
Add JS in public/js/[module].js with safe fetch + loading/empty/error states.
Theme shell only in lnkup-theme — no business logic in theme.
```

### Test/QA pass

```
QA pass for LNK Marketplace after [change description].

WordPress at http://localhost:8888. Playwright specs in tests/e2e/.
Verify: [list views/routes]
Check: no REST permission_callback warnings, no invalid JSON, no WP_Error fatals.
Mobile: sidebar icons, responsive cards, no floating icons.
Summarize what was tested vs assumed.
```

---

## 9. Safe Patch Checklist

Before considering a change complete:

- [ ] Traced full path: **JS → REST/AJAX → PHP service → CPT/SQL storage**
- [ ] Fixed **root cause**, not just symptoms
- [ ] Added **`is_wp_error()` guards** before treating results as arrays
- [ ] Added **loading, empty, and error states** in dashboard UI
- [ ] Preserved **response shapes** where possible (avoid breaking frontend contracts)
- [ ] Preserved **capabilities and ownership** checks
- [ ] Did **not** call Stripe directly from UI layers
- [ ] Did **not** modify `app/` or reference Next.js unless requested
- [ ] Did **not** add WooCommerce or attendee ticketing
- [ ] Did **not** break vendor vs organizer dashboard routing
- [ ] REST routes have **`permission_callback`**
- [ ] No raw errors or PHP notices in JSON responses
- [ ] Summarized: files changed, routes touched, DB changes, test status, assumptions

---

## 10. Testing Guidance

### Playwright E2E

- **Config:** `playwright.config.ts` — `baseURL: http://localhost:8888`
- **Run:** `npx playwright test` or `pnpm test:e2e`
- **Projects:** Desktop Chrome + iPhone 13 (mobile)

### Spec inventory

| Spec | Area |
|------|------|
| `marketplace-v2.spec.ts` | Find Events search, filters, apply |
| `vendor-dashboard-ui.spec.ts` | Vendor dashboard navigation |
| `vendor-financial.spec.ts` | Vendor Payments panel |
| `organizer-financial.spec.ts` | Organizer Financial panel |
| `stripe-connect.spec.ts` | Connect status, safe fallbacks |
| `checkout-embedded.spec.ts` | Embedded checkout flow |
| `vendor-growth.spec.ts` | Growth dashboard, zero-state |
| `vendor-operations.spec.ts` | Vendor operations panel |
| `event-intelligence.spec.ts` | Organizer intelligence |
| `organizer-crm.spec.ts` | CRM, vendor directory |
| `vendor-reputation.spec.ts` | Reputation views |
| `interactive-maps.spec.ts` | Map features |

### Manual smoke checklist

**Vendor:** Overview, Find Events, Applications, Documents, Payments, Growth, Reputation, Operations

**Organizer:** Overview, Events, Applications, Vendor Directory, Lists, Invites, Operations, Intelligence, Create Event, Financial

**API:** `GET /wp-json/lnkup/v1/growth/dashboard` returns 200 JSON; Connect status returns safe JSON when disabled

---

## Related Documents

| Document | Purpose |
|----------|---------|
| [SYSTEM_OVERVIEW.md](SYSTEM_OVERVIEW.md) | Full architecture, API summary, workflows |
| [CURRENT_STATE.md](CURRENT_STATE.md) | Feature inventory, production fixes |
| `.cursor/rules/lnkup-architecture.mdc` | Always-applied agent coding rules |
| `wp-content/plugins/lnkup-marketplace/FINANCIAL.md` | Financial subsystem detail |

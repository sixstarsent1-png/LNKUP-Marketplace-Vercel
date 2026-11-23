# LNKup App Gap Analysis

**App:** `lnk-up-marketplace-ui/` (Vercel)  
**Production WordPress:** `lnkup-marketplace` v12.17.0 + `lnkup-theme` v1.4.5  
**Last updated:** July 2026

Compares the Next.js premium app against WordPress production capabilities. For architecture and the full App Roadmap, see [SYSTEM_OVERVIEW.md §14.2](SYSTEM_OVERVIEW.md#142-app-roadmap-phases-126).

> **Superseded (Nov 2026):** `/vendor/wallet` is live (`app/api/vendor/wallet/*`) and organizer login is built (Phases 14A–C, 16). Use [BUILDOUT_CHECKLIST](BUILDOUT_CHECKLIST.md) for current status; treat the tables below as history.
>
> **Note:** Route-level mock/live tables below may lag Phases 15–21. Refresh during [Phase 22 live-API audit](PHASE_22_PRODUCTION_LAUNCH_HARDENING.md).

---

## A. Executive summary

| Layer | State |
|-------|-------|
| **WordPress plugin** | Feature-rich — ~210 REST routes, maps, operations, financial OS, reputation, external events, CRM, intelligence |
| **Vercel app** | Selectively wired — vendor discovery + apply + payment handoff + Phases 15–21 surfaces; residual mocks remain (Phase 22 audit) |
| **App Phase 7** | Built (`/ops/analytics` + Phase 19 experiments) |
| **App Phases 8–21** | **Built** — see SYSTEM_OVERVIEW §14.2 |
| **App Phases 22–26** | Queued — [PHASES_22_26_ROADMAP.md](PHASES_22_26_ROADMAP.md) |

WordPress remains the **system of record**. The app is not a headless replacement — it is a premium mobile/front-end experience with a BFF proxy.

---

## B. App Phase completion matrix (Phases 1–7)

| App Phase | Name | Status | Implemented | Gaps |
|-----------|------|--------|-------------|------|
| **1** | Core Vendor Shell + Live Discovery | **Built** | 5-item nav, `/vendor/find` live search, apply modal, vendor login BFF, panel states | Organizer/admin auth not in scope |
| **2** | App Shell + Secondary Routes | **Built** | More hub, secondary vendor routes, organizer/admin shells, `usePanelState` QA | WordPress visual port deferred |
| **3** | Approved → Payment → Packet | **Mostly built** | Payment drawer, `payment-link` + `packet` BFF, `/vendor/pass/[id]`, status chips on requests | Wallet still mock; review CTA placeholder; edge-case QA for prelaunch + 3DS return |
| **4** | Organizer Lite + Launch Ops | **Partial** | Live organizer home, events list, vendor requests, financial snapshot, launch checklist, **enriched** vendor profile drawer (gallery/setup/docs/history + Discover → profile + CRM deep-link) | Event workspace mock; Lists/Invites tabs mock; no organizer login flow |
| **5** | PWA + Launch QA Hardening | **Mostly built** | `manifest.webmanifest`, `/qa`, Playwright app specs, error normalization, `/offline` | No service worker (manifest-only by design) |
| **6** | Launch Growth + Vendor Conversion | **Mostly built** | `/vendors`, onboarding, `/markets/[slug]`, referrals, analytics track, recommended/trending rails | Profile save still WP page; no web push/SSO (native Expo push is separate — see [PUSH_NOTIFICATIONS_DEPTH.md](PUSH_NOTIFICATIONS_DEPTH.md)) |
| **7** | Launch Analytics + Optimization | **Built** | `/ops/analytics`, funnel/activation, Phase 19 experiment panels | Residual polish + live audit in Phase 22 |

---

## C. High-priority gaps (cross-phase)

| Gap | App state | WP backend ready? |
|-----|-----------|-------------------|
| Organizer login / role gate | Vendor-only `/login`; organizer routes fall back to mock when unauthenticated | Yes (`/wp/v2/users/me` + capabilities) |
| `/vendor/wallet` | Mock fee breakdown from fixtures | Yes (`/financial/*` with `?role=vendor`) |
| `/vendor/growth`, `/vendor/reviews` | Mock pages | Yes (`/growth/*`, `/reputation/*`) |
| `/vendor/operations` | Mock event list; individual pass can be live | Yes (`/operations/events/*`, `/packets/pass/{id}`) |
| `/vendor/profile` (most tabs) | Static mock; documents panel live | Partial — profile save via WP page/AJAX, not app BFF |
| `/organizer/events/[id]` | Mock workspace | Yes (event-scoped CRM + operations REST) |
| Organizer Discover / Lists / Invites | Mock | Yes (`/crm/*`) |
| `/admin/*` | Admin Lite live/partial; money/users/events demo-gated | Phase 15 Lite + Phase 22 honesty notices; Phase 25 for depth |
| `/ops/analytics` (Phase 7) | Missing | Partial (`/marketplace/analytics` admin-only; `/marketplace/track/event` public) |
| Map discovery UI (Phase 8) | Missing | Yes (`GET /maps/discovery/pins`) |
| Push / SMS notifications | Web: composed inbox only (no web push). Native: Expo push for 7 lifecycle events — [PUSH_NOTIFICATIONS_DEPTH.md](PUSH_NOTIFICATIONS_DEPTH.md) | Email via `wp_mail` + Expo Push (`LNKup_Push_Service`); SMS not built; no WP inbox REST |

---

## D. Route-level mock vs live

Gate: `NEXT_PUBLIC_LNKUP_LIVE_API=true` + server env (`WORDPRESS_API_URL`, `SESSION_SECRET`).

### App pages

| Route | Live when flag on | Notes |
|-------|-------------------|-------|
| `/` | N/A | Role picker only |
| `/login` | Yes | WP Application Password → encrypted session |
| `/vendors` | Partial | Marketing; links to WP registration |
| `/markets/[slug]` | Yes | Composed from marketplace search |
| `/vendor` | Yes | Readiness, requests preview, referrals, rails |
| `/vendor/find` | Yes | Search, apply, favorites, saved searches |
| `/vendor/requests` | Yes | List + payment drawer |
| `/vendor/wallet` | **Mock** | Always fixtures |
| `/vendor/profile` | Partial | Documents/readiness live; other tabs mock |
| `/vendor/documents` | Yes | List + upload BFF |
| `/vendor/growth` | **Mock** | Home readiness card is live |
| `/vendor/reviews` | **Mock** | |
| `/vendor/favorites` | Yes | Favorites + saved searches |
| `/vendor/operations` | **Mock** | Pass deep link can be live |
| `/vendor/pass/[id]` | Yes | Packet preview via BFF |
| `/vendor/onboarding` | Yes | Verification + profile strength |
| `/vendor/more` | N/A | Nav hub |
| `/organizer` | Yes | Home summary BFF |
| `/organizer/events` | Yes | Events list BFF |
| `/organizer/events/[id]` | **Mock** | Links to WP dashboard |
| `/organizer/vendors` | Partial | Requests + Discover → enriched profile drawer live; Lists/Invites mock |
| `/organizer/money` | Partial | Financial snapshot live; tabs placeholder |
| `/organizer/more` | **Mock** | Static menu |
| `/admin/*` | **Mock** | No BFF routes |
| `/qa` | Yes | Gated by `ENABLE_QA_TOOLS` |
| `/offline` | N/A | Static copy |

### BFF routes (all exist; upstream used when live)

| BFF route | WordPress upstream |
|-----------|-------------------|
| `POST /api/auth/login` | `GET /wp/v2/users/me` |
| `GET /api/auth/me` | Session check |
| `POST /api/auth/logout` | Clears session |
| `GET /api/marketplace/search` | `GET /marketplace/search` |
| `GET /api/marketplace/recommended` | `GET /marketplace/sections/recommended` |
| `GET /api/marketplace/trending` | `GET /marketplace/sections/trending` |
| `GET/POST /api/marketplace/favorites` | `GET/POST /marketplace/favorites` |
| `DELETE /api/marketplace/favorites/[id]` | Toggle via POST |
| `GET/POST /api/marketplace/saved-searches` | `GET/POST /marketplace/saved-searches` |
| `DELETE /api/marketplace/saved-searches/[id]` | `DELETE /marketplace/saved-searches/{id}` |
| `GET /api/marketplace/markets/[slug]` | `GET /marketplace/search` (city filter) |
| `GET /api/applications/apply-context/[id]` | `GET /applications/apply-context/{id}` |
| `POST /api/applications/apply` | `POST /applications/apply-with-profile` |
| `GET /api/vendor/requests` | `GET /applications/vendor` |
| `GET /api/vendor/requests/[id]/payment` | `GET /financial/checkout/{id}/summary` |
| `POST /api/vendor/requests/[id]/payment-link` | Summary + `POST /financial/checkout/{id}/redirect-url` |
| `GET /api/vendor/requests/[id]/packet` | `GET /packets/pass/{id}` |
| `GET /api/vendor/documents` | `GET /documents/vendor` |
| `POST /api/vendor/documents/upload` | `POST /documents/upload` |
| `GET /api/vendor/readiness` | `GET /verification/vendor` + `/growth/profile-strength` |
| `GET/POST /api/vendor/onboarding` | Verification + growth + cookie progress |
| `GET /api/vendor/referrals` | `GET /financial/referrals` |
| `POST /api/vendor/referrals/invite` | `POST /financial/referrals/code` |
| `GET /api/organizer/home` | Intelligence summary + CRM widgets + launch checklist + balances |
| `GET /api/organizer/events` | CRM events + roster |
| `GET /api/organizer/requests` | `GET /applications/organizer` |
| `POST /api/organizer/requests/[id]/review` | CRM roster bulk actions |
| `GET /api/organizer/vendors/[id]` | CRM vendor + application intel |
| `GET /api/organizer/financial` | `GET /financial/organizer-balances` or dashboard |
| `POST /api/analytics/track` | `POST /marketplace/track/event` |
| `GET /api/qa/health` | Env/config health |

---

## E. BFF coverage gaps (App Phases 7–14)

Draft mapping of **new BFF routes** needed and WordPress upstream. Verify exact paths during implementation.

| App Phase | Proposed BFF routes | WordPress upstream |
|-----------|---------------------|-------------------|
| **7** | `GET /api/ops/analytics/funnel`, `summary`, `health`, `prelaunch` | `/marketplace/analytics`, `/marketplace/track/event`, compose from applications/search |
| **8** | `GET /api/maps/discovery/pins` | `GET /maps/discovery/pins` |
| **8** | Extend `GET /api/marketplace/search` | Geo filters, external events, saved-search params |
| **9** | `POST /api/auth/login` (organizer role) | Same + capability check |
| **9** | `GET /api/organizer/events/[id]` | CRM event detail + ops summary |
| **9** | `POST /api/organizer/events/create` | Event/opportunity creation REST or handoff URL |
| **10** | `GET /api/vendor/operations` | Compose from vendor applications + `/operations/events/{id}` |
| **10** | Extend `GET /api/vendor/requests/[id]/packet` | Full pass: booth, parking, load-in, QR |
| **11** | `GET/POST /api/vendor/reviews` | `/reputation/reviews/*` |
| **11** | `GET /api/vendor/reputation` | `/reputation/vendor/{id}` |
| **12** | `GET /api/vendor/wallet` | `/financial/wallet`, transactions, invoices (`?role=vendor`) |
| **12** | Extend `GET /api/organizer/financial` | Dashboard tabs: payouts, reserves, transactions |
| **13** | `GET /api/vendor/notifications` | Notification prefs + digest metadata (may need new WP route) |
| **13** | `PATCH /api/marketplace/saved-searches/[id]` | Alert frequency/pause |
| **14** | `GET /api/admin/summary` | `/marketplace/analytics` + external events stats + verification queue counts |

---

## F. WordPress features with no app surface (intentional)

These remain WordPress-only by design — not gaps to close in the app short term:

| WP feature | Location | Why app excludes |
|------------|----------|------------------|
| Full organizer command center | `#overview` … `#intelligence` hashes | Organizer Lite scope; deep ops stay in WP |
| Interactive map editor | `maps.js`, organizer ops | Authoring tool; app gets read-only discovery map (Phase 8) |
| Financial write ops | Cashouts, refunds, Connect onboarding | Financial OS rule — handoff only |
| wp-admin external events queue | Auto sync, review queue, bulk approve | Admin Lite (Phase 14) gets summary only |
| City Connector dashboard | `/connector-dashboard/` | Separate role/program |
| CRM CSV import wizard | `vendor-import.js` | Organizer wp-admin / WP dashboard |
| Platform outreach campaigns | wp-admin Outreach | Admin Lite scope |
| Weather intelligence panels | `weather.js` | Ops/intel WP panels |
| Attendee ticketing | — | Out of scope |

---

## G. Architectural constraints (not gaps)

- All payment, payout, refund, and settlement logic stays in WordPress via `LNKup_Financial_Service`
- No Stripe SDK or PaymentIntents in Next.js
- Prelaunch mode may block `apply` submit while search remains public — not a bug ([LIVE_API_INTEGRATION.md](LIVE_API_INTEGRATION.md))
- Mock data must be labeled when `NEXT_PUBLIC_LNKUP_LIVE_API=false`; never present mock stats as live

---

## H. Recommended sequencing (historical)

Phases 7–14 in this list were the original draft order. **App Phases 8–21 are now built.** Continue with:

1. **Phase 22** — Production Launch Hardening ([PHASE_22_PRODUCTION_LAUNCH_HARDENING.md](PHASE_22_PRODUCTION_LAUNCH_HARDENING.md))
2. **Phases 23–26** — Mobile tutorials → native maps → Admin Lite depth → Financial OS expansion ([PHASES_22_26_ROADMAP.md](PHASES_22_26_ROADMAP.md))

---

## Related documents

| Document | Purpose |
|----------|---------|
| [SYSTEM_OVERVIEW.md §14.2](SYSTEM_OVERVIEW.md#142-app-roadmap-phases-126) | Full App Roadmap Phases 1–26 |
| [PHASES_22_26_ROADMAP.md](PHASES_22_26_ROADMAP.md) | Queued Phases 22–26 |
| [PHASE_22_PRODUCTION_LAUNCH_HARDENING.md](PHASE_22_PRODUCTION_LAUNCH_HARDENING.md) | Next execution plan |
| [LIVE_API_INTEGRATION.md](LIVE_API_INTEGRATION.md) | BFF + auth |
| [PHASE_7_ANALYTICS.md](PHASE_7_ANALYTICS.md) | Phase 7 spec |
| [CURRENT_STATE.md](CURRENT_STATE.md) | WordPress plugin inventory |

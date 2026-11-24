# 01 — Architecture and inventory

Audit date: 2026-11-23. Read-only audit; no application code was changed.

## What was audited (and why there are two code lines)

The repository does not have one current version. Two lines diverged from the 12.32 release (`582e8b3`):

| Line | Where | Plugin version in code | What it holds |
|---|---|---|---|
| **H** — this checkout | `redesign/event-product` @ `461f3e3` (+ uncommitted work, header bumped to 12.34.0) | 12.33.4 committed | 12.33.1–12.33.4 hotfixes (settings save, Discover speed, `&amp;` text, receipts, fee privacy, profile width) |
| **M** — `origin/master` | `6beea4a` | 12.33.0 | Bookings Center (Stage 8), recovery reminders (Stage 9), Phases 1–7 (retry safety, money fixes, team scope, comms, funnel, ops queue, partner fixes), design passes 1–6 |

H was 4 commits ahead of and 39 behind M when the audit started. During the audit two more commits landed on H (`aaa6e7e` 12.34.0, `d5ecd6e` Engagement OS 1–2), so it's now **6 ahead / 39 behind**. `d5ecd6e` isn't covered by this audit. Both lines call themselves "12.33.x" but contain different code.

**Production (thelnkup.com) runs a mix** (verified 2026-11-23):

| Component | Production | Evidence |
|---|---|---|
| Marketplace plugin | **12.33.4 (line H)** | Asset `?ver=12.33.4`; M-only files 404: `public/js/explore-ux.js`, `public/js/vendor-bookings.js` |
| Theme | **1.6.1 from line M** (design pass) | `assets/css/experience.css` loads (exists only on M) |
| Communications plugin | deployed | `lnkup-communications/public/js/comms-event.js` 200 |
| Next.js app | **July 2026 build** | Last Vercel *Production* deployment 2026-07-01 (`5f91d47`); every later deployment is a Preview |
| iOS app | **version 1.0** (App Store, updated 2026-10-14) | Apple lookup API, app id 6795865859 |
| Android app | **not public** | Play Store 404; `eas.json` submit track `internal`, `releaseStatus: draft` |

The automated WordPress deploy (`.github/workflows/deploy-wordpress.yml`) has run twice and failed both times: `ssh-private-key argument is empty` (secret `SG_SSH_KEY` not configured). Production WordPress is therefore deployed by hand (zip uploads), which is how a line-H plugin and a line-M theme ended up live together.

Unless stated otherwise, findings below were checked on **H** (production plugin) and noted when M differs.

## System map

```
                         ┌──────────────── WordPress (system of record) ───────────────┐
                         │ lnkup-marketplace plugin (H: 384 PHP files, ~158k lines)       │
 Stripe ── webhooks ───► │   includes/financial/*  (Financial Service, Fee Engine,       │
 (23 event types)        │     Checkout, Stripe client/provider/Connect, Reconciliation) │
                         │   includes/*  applications, payments, invoices, packets,      │
                         │     check-ins, operations, CRM, intelligence, SEO, markets    │
                         │   includes/{matching,portfolio,passport,trust,partnerships,   │
                         │     organizer-growth,external-sources,weather}/*              │
                         │   16 custom post types + ≥125 custom tables                    │
                         │   464 REST routes under lnkup/v1 (24 public)                   │
                         │ lnkup-communications plugin (143 PHP files, ~29k lines)        │
                         │   engine → Action Scheduler → inbox / wp_mail / Expo push       │
                         │ lnkup-theme (54 PHP files) public site + dashboard shells      │
                         └───────────────┬───────────────────────────────┬──────────────┘
                                         │ cookie session + nonce          │ Bearer token (90 days)
                     WordPress web UI (shortcodes + public/js/*.js)       │
                                         │                                 │
                   Next.js app (lnk-up-marketplace-ui)                Expo app (lnk-up-marketplace-mobile)
                   106 BFF API routes → lnkup/v1                      direct lnkup/v1 via lib/api/wp-client.ts
                   (production deploy stale since July)               (App Store 1.0)
```

Authoritative logic lives in the plugin. Next.js and Expo are clients. One exception found: the Next.js Admin Lite **Money, Events and Users** pages and vendor **More** page render hardcoded sample data with no live mode (see 04 and 06).

## Inventory (actual counts, line H unless noted)

| Item | Count | How counted |
|---|---|---|
| Marketplace plugin PHP files | 384 (M: 386) | `find -name '*.php'` excluding `vendor/` |
| Marketplace plugin PHP lines | ~157,990 (M: ~159,005) | `cat \| wc -l` |
| Communications plugin PHP files | 143 (M: 144), ~29,440 lines | includes bundled Action Scheduler library |
| Theme PHP files | 54 (M: 56): 12 page templates, 27 template parts (M: 29) | |
| Plugin JS files (non-minified) | 48 (M: 50) | `public/js`, `admin/js` |
| REST route registrations | **464** marketplace + **14** communications | unique `register_rest_route(ns, path)` pairs. The older docs' "~200 routes" is out of date |
| Public (no-login) REST routes | 24 | `permission_callback => '__return_true'`; all 24 listed in 05 |
| Custom post types | 16 | `class-post-types.php`: application, brand, dispute, document, event, event_template, opportunity, packet, payment, review, tour, vendor_flag, vendor_invite, vendor_list, vendor_note, vendor_profile |
| Custom tables | ≥125 distinct `{prefix}lnkup_*` names; 173 `CREATE TABLE` statements | lower bound by static scan |
| Scheduled WP-Cron hooks | 25 distinct names; Communications adds Action Scheduler jobs | static scan |
| AJAX actions | 30 marketplace + 1 communications | |
| Stripe webhook event types handled | 23 | `class-stripe-webhooks.php` `switch` |
| Notification triggers | 83 Communications registry triggers; 9 legacy keyed groups + 13 direct `LNKup_Notifications::send()` calls | static |
| wp-admin screens | 1 top-level menu + 25 submenus | `add_menu_page` / `add_submenu_page` |
| Feature flags | 40+ (see below) | |
| Expo app | Expo 57.0.13, React Native 0.86.2, Expo Router 57.0.13, React 19.2.3 (installed versions read from `node_modules`) | |
| Expo screens | 53 screens + 6 layouts; route groups `(vendor)`, `(organizer)`, `(partner)` | `app/**/*.tsx` |
| Expo API modules / distinct API paths | 49 modules / ~131 distinct paths | static scan of `lib/` |
| Next.js app | Next 16.2.6: 44 pages, 106 BFF API routes, 129 components | |
| Automated tests | 93 Playwright specs; 60 PHP integration scripts (`scripts/e2e/php`); 27 Expo jest files; 0 Next.js unit tests | |
| CI workflows | 3: deploy-wordpress (manual, never succeeded), phase14 gate, phase15-20 gate | |

## Feature flags (all **off by default** unless noted)

| Flag system | Flags |
|---|---|
| `LNKup_Feature_Flags` (`includes/class-feature-flags.php`) | `application_review_v2_enabled`, `waitlist_offers_enabled`, `trust_org_teams`, `portfolio_os_enabled` (+ `portfolio_os_user_ids`), `organizer_ops_v2_enabled`, `organizer_document_review_enabled`, `organizer_onboarding_v2_enabled`, `organizer_os_pilot_user_ids` |
| `LNKup_Rollout` (`includes/class-rollout.php`, cohorts and percentages) | `apply_wizard_enabled`, `tour_multi_apply_enabled`, matching discovery and tour flags, comms discovery and recruitment flows |
| `LNKup_Match_Settings` | `matching_enabled` (master switch) + vendor discovery, organizer discovery, tours |
| `LNKup_Passport_Settings` | `passport_enabled` (master) + `document_vault_sharing`, `passport_history_v2`, `passport_verification_v2`, `review_responses`, `matching_reputation_signals` |
| `LNKup_Trust::flag` | `trust_org_teams`, `trust_publish_gate`, `trust_auto_promote_claims`; `trust_claim_review_mode` defaults to `log` |
| `LNKup_Org_Growth` | `organizer_growth_enabled`, `organizer_growth_outreach_enabled` |
| `LNKup_Comms_Settings` | every `flow_*` (activation, countdown, digests, discovery, documents, onboarding, partners, passport, recruitment, retention), `organizer_broadcasts`, `campaigns_enabled`, `fin_payout_notifications` are **off**; `email_channel` and `push_channel` are **on** |
| Market pages | `market_pages_enabled` defaults **on** (but `/markets/{city}/` is 404 in production) |

**Production flag values could not be read** (they need wp-admin access). Every "built, flag-gated" system in this audit is therefore "deployment state unknown" until someone records the live values. That single gap affects 12 of the 20 systems.

## Architectural observations

1. **No single production revision.** Two lines with overlapping version numbers, a broken deploy pipeline, and a partly manual release process. This is the largest structural risk in the project (see 05, P0-1).
2. **Layered, not duplicated, tours.** `LNKup_Portfolio_Tours` explicitly extends the base `LNKup_Tours` model (`includes/portfolio/class-portfolio-tours.php` header); stops stay ordinary `lnkup_event` records.
3. **Two push senders by design.** `LNKup_Push_Service` (marketplace) and the Communications push channel coexist; the `lnkup_pre_send_notification` delegation hook decides which one sends per message key. While Communications flows are off, the legacy sender handles everything.
4. **Two flag mechanisms** (`LNKup_Feature_Flags` and `LNKup_Rollout`) plus four module-specific settings classes. There's no single screen listing what's on.
5. **WooCommerce and leftover theme/demo plugins are active in production** (WooCommerce assets load on every public page; ThemeIsle/Otter stylesheet requested over `http://`). The architecture rules say WooCommerce is out of scope.
6. **The Next.js app is a third client with partial coverage** and a stale production deploy. Treat it as not in production until a Production deployment happens.

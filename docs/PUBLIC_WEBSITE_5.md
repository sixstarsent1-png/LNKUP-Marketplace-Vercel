# LNKup Public Website 5.0 — Implementation Report

Status: implemented and verified locally (wp-env, `http://localhost:8888`). Theme `lnkup-theme` 1.7.0. Plugin changes ship inside `lnkup-marketplace` (version number coordinated with the concurrent session, which holds 12.36.0 uncommitted).

Screenshots live in [`docs/public-website-5/screenshots/`](public-website-5/screenshots/) (`before/`, `competitors/`, `after/`, WebP).

---

## 1. Competitor research findings

Captured at 1440 and 390 wide. Patterns only; no assets, code or layouts were copied.

| Site | What works | Pattern taken |
|---|---|---|
| POSH (home) | Editorial, culture-first hero; poster-style event art; very small nav | Strong headline + restrained nav; let event art carry color |
| Eventbrite (home + organizer page) | Search-first discovery; category and date filters; organizer page with one bold claim, photo, two CTAs, then feature tabs | Role-specific acquisition page; tabbed feature/role switching; "Get started free" as the primary action |
| TicketLeap | Single promise ("done right"), pricing and "how it works" in the top nav | Pricing reachable from nav; one primary CTA per screen |
| Faire | Two-sided marketplace: category pills, featured brands with locations, simple 3-up benefits | Category tiles with counts; featured vendor cards with city; benefit triads |
| Eventeny | "Select your path: I'm a…" role picker in the hero; logo wall of hosted events | Role picker ("Get started → I'm a vendor / I'm an organizer"); proof strip slot (logos only when real) |
| Marketspread (home + vendor page) | Product screenshots in the hero; plain explanation of what vendors manage | Product preview panels (real screenshots when uploaded, labeled illustration otherwise) |
| Luma | Minimal hero, one CTA, playful event art | Restraint: one primary CTA, secondary as a text link |

Not captured: `posh.vip/platform`, Peerspace and `eventeny.com/vendor-management/` timed out in headless capture (network never settled). Notes above for those brands come from the pages that did load.

Things competitors do that LNKup deliberately did **not** copy: headline traffic/volume stats (Eventbrite "91M visitors", Eventeny "36,500+ events") because LNKup has no verified equivalent yet — the redesign shows only live counts instead.

## 2. Current website audit (before)

Evidence: `screenshots/before/before-home-*.webp`, `before-opportunities-*.webp`, `before-mobile-menu-390.webp`.

- Hero: hardcoded "Find your next vendor spot." with a single **City** field (placeholder "Houston"); organizers had no path from the hero.
- Navigation: Events / Organizers / List an event; no vendor or organizer value pages, no pricing, no dropdowns; mobile menu was a plain list with no focus handling.
- Credibility block showed **"20,000+ Vendor community goal / 3,000+ Organizer community goal"** in stat styling, and an admin "custom stats" switch could silently replace live totals with typed numbers.
- Footer default copy claimed "Join thousands of vendors…", and showed dashboard links (Applications, Organizer Home) to anonymous visitors.
- Section kickers numbered "01 / VENDORS … 05 / APP" out of order.
- Hero JPGs ~1.8 MB each with no `srcset`.
- No `/for-organizers/` or `/for-vendors/`; Meta ads could only land on the homepage, `/sign-up/` or city market pages.

## 3. Existing implementation inventory (ledger)

| Capability | State before | Notes |
|---|---|---|
| Marketplace search (`marketplace/search`) | Verified | Reused by the hero and landing cards |
| Autocomplete (`marketplace/suggestions`) | Verified, not exposed on the homepage | Now powers event-keyword suggestions |
| Live stats (`lnkup_get_marketplace_public_stats`) | Poorly presented | Counted users by role and summed GMV; replaced on public pages by listing-based counts |
| City markets (`LNKup_Market_Pages`) | Verified | City cards link to the market page when one is enabled |
| Tours/series (`LNKup_Portfolio_Public`, `LNKup_Tours`) | Needs UI | Now surfaced as tour cards when their public page or tour filter is live |
| Vendor Passport (public profiles) | Needs UI | Featured vendor cards use only public Passports |
| Fee Engine | Verified | Pricing copy, examples and calculator read it live |
| Event media resolver | Partial (logo-as-cover) | Fixed by the concurrent session's `LNKup_Event_Media` work; cards here render through it |
| Sign-up role + return (`?role=`, `?redirect=`) | Verified | Landing CTAs preserve both |
| Meta Pixel / server events / attribution | Verified (uncommitted, other session) | Used via existing hooks only |
| Landing content admin | Partial | Extended (drafts, proof blocks, FAQs); silent stat override removed |
| App promotion | Verified | Kept; suppressed on acquisition landings (see §16) |
| Marketplace index hygiene | **Broken locally** | 9 index rows point at deleted opportunities; existing search still returns them. Public discovery now joins against published posts. **Needs production check.** |
| Find Events feed layout | **Broken locally** | 3 feed cards render 2 px wide on `/opportunities/` at 1280 (reproduced with the new CSS/JS blocked, so it predates this work) |

## 4. Initial design scorecard

See §25 (same table, "Before" column).

## 5. New public design system

`wp-content/themes/lnkup-theme/assets/src/public.css` → `assets/dist/public.css` (`npm run build:public`, 37 KB minified), loaded only on public pages (`lp-body`), every selector prefixed `lp-` so plugin and dashboard CSS is untouched.

- Tokens: ink `#0b0b0c`, lime `#c8f542` (text on lime is `#1a2400`), mist surfaces, muted text `#55585f` (≥ 6:1 on white), radii 8–28 px, fluid type scale (`clamp`), section rhythm, shadows, focus ring.
- Components: buttons (primary/lime/outline/ghost/on-dark), chips, section heads, hero, search with listbox, stats strip, rails (swipe on mobile, grid on desktop), tabs, event/vendor/city/tour cards, category tiles, steps, features, product preview, pricing, breakdown, calculator, FAQ, quotes/logos/case studies, CTA band, app block, empty/skeleton states, footer.
- Motion: reveal-on-scroll only after JS adds `lp-js`; CSS fallback reveals after 2.5 s; `prefers-reduced-motion` disables all motion.
- Fonts trimmed on public pages to Inter 400–700 + Sora 700.
- Numbered kickers removed (`lnkup_theme_section_kicker` ignores the index).

## 6. Homepage redesign

`front-page.php` + `template-parts/home/*`:

1. Hero: editable headline/lead, role-aware search (events for vendors / vendors for events), live quick links (top categories and cities with counts), "I'm a vendor / I'm an organizer", free-to-apply note.
2. Live counts strip (open events, open opportunities, cities, categories) — only non-zero real counts; hidden when fewer than two.
3. Signed-in strip (vendor recommendations or organizer next steps).
4. Live events with tabs: coming up / this weekend / closing soon (empty tabs dropped; empty state when nothing is open).
5. Vendor categories in demand (live counts).
6. Cities booking vendors (links to market pages when enabled).
7. How it works with a vendor/organizer toggle.
8. Two audience panels → landings.
9. Organizer tools with product preview.
10. Tours and series (when public).
11. Vendors on LNKup (≥ 3 public Passports).
12. Proof (logos/testimonials/case studies — only verified admin content).
13. iPhone app, 14. FAQ (live pricing), 15. Closing CTA.

Prelaunch mode keeps the countdown and the locked sneak-peek grid.

## 7. Organizer landing page

`/for-organizers/` (`page-templates/landing-organizers.php`): hero with "List your event free" and either "Book a walkthrough" (only if an https booking link is configured) or "See pricing"; why-switch list + preview; six tool cards; four steps; vendor supply (public vendors or category tiles linking to the directory); pricing card + worked example from `LNKup_Fee_Engine::calculate()`; revenue calculator using the live commission; proof; app; FAQ with schema; closing CTA.

## 8. Vendor landing page

`/for-vendors/` (`page-templates/landing-vendors.php`): hero whose eyebrow/listings narrow to a **validated** city or category; live event cards (falls back to all events with an explanation if the filter has none); embedded search prefilled with the validated context; value list + preview; feature cards (the "alerts" card appears only when the communications discovery flow is enabled); steps; categories and cities; pricing card + checkout example from the Fee Engine (booth + service fee + card processing + tax if any); proof; app; FAQ; closing CTA. `?opportunity={id}` (published, open, with a published event) switches the CTAs to "Sign up to apply to {event}" with the event as the post-sign-up redirect and a "Back to the event" link.

## 9. Navigation enhancements

`template-parts/landing/site-header.php` (used by every public template): Find events (browse, this weekend, closing soon, tours, live top cities), For vendors, For organizers, Organizers directory; Log in; **Get started** role menu; Dashboard for signed-in users. Disclosure buttons with `aria-expanded`/`aria-controls`, arrow-key navigation, Escape, outside click. Mobile: modal drawer (`role="dialog"`, focus trap that also works in WebKit, Escape, scroll lock, focus return), accordion groups, role CTAs pinned at the bottom. Announcement bar (admin-managed, dismissible, optional expiry).

## 10. Marketplace search enhancements

Plain GET forms → shareable URLs the existing pages already parse: `/opportunities/?q=&city=&state=&date_preset=&categories=` and `/vendors/?q=&city=&category=`. Empty fields are dropped from the URL. Keyword combobox uses `marketplace/suggestions` (events, categories) plus live cities; city combobox autocompletes from live cities and sets `state`. ARIA combobox/listbox with `aria-activedescendant`, live result announcements, keyboard selection.

## 11. Event card improvements

`template-parts/public/event-card.php` from `LNKup_Public_Discovery::event_card()`: resolved media (via `lnkup_event_media_html`, so logos/flyers are contained and uploads win), date tile, status chip (Booking vendors / Closing soon), organizer + verified mark, title (whole card clickable), place and date, wanted categories, tour link, "Booths from $X" (or "See booth pricing" when unknown — never guessed), open spot count. Cards skip unpublished events/opportunities and full events.

## 12. Vendor card improvements

`template-parts/public/vendor-card.php`: cover, logo, business name, primary category, city → public Passport. Only Passports whose owners made them public; no contact fields leave the server. Admin can pin vendors (still filtered by privacy).

## 13. Geographic discovery improvements

Live city list with open-event counts (homepage, footer, nav menu, vendor landing), links prefer enabled city market pages, city autocomplete everywhere, city-narrowed vendor landings and campaign variants.

## 14. Event series improvements

Tour/series cards (type, stop count, stops booking vendors) shown on the homepage and in the nav when a tour's public page (Portfolio OS) or tour filter is live.

## 15. Product demonstration components

`lnkup_public_preview()`: uses the admin-uploaded organizer/vendor dashboard screenshot when set; otherwise a schematic window labeled **"Illustration"** with no names, numbers or metrics.

## 16. Registration optimization

- Role-specific entry points everywhere (`?role=vendor|organizer`), return-to-opportunity redirect from the vendor landing, signed-in users see Dashboard instead of sign-up.
- On `/for-organizers/` and `/for-vendors/` (including variants) the iPhone first-visit app sheet and the app download bar are suppressed so ad traffic sees the sign-up CTA first (verified in an Instagram in-app user agent).

## 17. Mobile experience improvements

Rails swipe on phones and become grids on desktop; search fields pair on small screens; header compresses below 400 px; no horizontal overflow at 320 px (tested); 44 px minimum touch targets; drawer with pinned CTAs; QR code hidden on narrow screens.

## 18. Meta integration preservation

No file under `includes/meta/*` was edited. Landing views set `window.lnkupMeta.viewContent = {content_category: organizer_landing|vendor_landing, content_name: <variant or default>, marketplace_role, campaign_market?, vendor_category?}` via `wp_add_inline_script` before the Pixel script, so the existing allow-listed `ViewContent` event fires once with the same event_id in browser and server, under the existing consent/GPC/opt-out rules. No new event names were added because the client event allow-list is hardcoded in the Meta files (the registry filter alone cannot enable new browser events). Registration events remain server-recorded after account creation.

## 19. Attribution verification

`lnkup-attribution.js` loads on every front-end page; E2E confirms a campaign variant visit with UTMs writes the `lnkup_ft` cookie containing the campaign. Unknown/disabled variants 302 to the base landing keeping only `utm_*` and `fbclid`.

## 20. Administrative content management changes

Appearance → Landing Page (`inc/landing-settings.php`): draft → preview (nonce, editors only, `noindex`, `no-cache`, on-page badge) → publish/discard; announcement bar; homepage/organizer/vendor hero copy and photos; dashboard screenshots; walkthrough link (https only); pinned vendors; partner logos (image required); testimonials and case studies that render **only** when marked "real, used with permission"; per-page FAQs; city-page how-it-works; prelaunch goals (labeled as goals). Removed: the custom stats override and fields that no longer render. A one-time migration clears the retired "Join thousands of vendors…" claim.

LNKup → Campaign Landings (`includes/public/class-campaign-landings.php`): create/edit/delete variants (audience, slug, eyebrow, headline, subhead, sign-up label, default city/state/category, hero image, live, indexable).

## 21. SEO changes

- Titles/descriptions for both landings and every variant (Rank Math filters and fallback tags).
- Variants: canonical → base landing and `noindex, follow` unless marked indexable.
- FAQPage JSON-LD for rendered FAQs; homepage `WebSite` + `SearchAction` (Find Events) and `Organization` when Rank Math is inactive.
- Base landing pages are normal pages, so they enter the existing sitemaps.

## 22. Accessibility findings

axe (WCAG 2 A/AA) reports **0 serious/critical** violations on `/`, `/for-organizers/`, `/for-vendors/` (desktop Chrome and iPhone). Keyboard: dropdowns (Enter/arrows/Escape), tabs (arrow/Home/End), combobox, drawer focus trap and return, skip link. One heading per page, labelled landmarks, `aria-live` search/calculator results, reduced-motion respected (tested).

## 23. Performance findings

- Homepage hero: 1.78 MB JPG → 46 KB WebP at 1280 w (18 KB at 640 w) via `<picture>`; category tiles 20–53 KB at 640 w (`npm run images`).
- `landing.js` no longer loads on the open-marketplace homepage; `public.js` (~12 KB, deferred) replaces it.
- Discovery data cached 10 minutes (transients) and flushed on listing/tour/market saves; REST responses send `Cache-Control: public, max-age=300` to anonymous clients.
- Remaining: `components.css` (126 KB) still loads globally for plugin markup; Google Fonts is a third-party request.

## 24. Before-and-after screenshots

- Before: `screenshots/before/before-home-{1440,768,390,320}.webp`, `before-mobile-menu-390.webp`, `before-opportunities-{1440,390}.webp`.
- After: `screenshots/after/{home,for-organizers,for-vendors,opportunities}-{390,1440}.webp` (full page) and `top-*-{320,768,1920}.webp` (first screen).
- Competitors: `screenshots/competitors/`.

## 25. Design scorecard (before → after)

Scored 1–10 against the competitor set. No automatic 10s.

| Category | Before | After | Evidence / what keeps it from higher |
|---|---|---|---|
| Visual identity | 5 | 8 | Consistent ink/lime system and type scale; still relies on two stock-style photos |
| Hero presentation | 5 | 8 | Search-first with role choice and live quick links; hero photo is not LNKup's own event |
| Brand consistency | 5 | 8 | One token set across home, landings, header, footer; plugin pages (feed, sign-up) keep older components |
| Homepage composition | 4 | 8 | Clear order from discovery → proof → audiences → tools → FAQ → CTA; long on mobile |
| Marketplace discovery | 4 | 8 | Live events, categories, cities, tours from real data |
| Search usability | 3 | 8 | Role tabs, autocomplete, dates, shareable URLs; no radius/"near me" yet |
| Event card presentation | 5 | 7 | Date tile, prices, spots, categories; many local events use generated art until organizers upload photos |
| Vendor card presentation | 3 | 6 | New card, but hidden until ≥ 3 public Passports; directory cards unchanged |
| Organizer positioning | 3 | 8 | Dedicated landing, live pricing, calculator, steps |
| Vendor positioning | 5 | 8 | Dedicated landing, context narrowing, return-to-event |
| Product demonstrations | 1 | 5 | Labeled illustrations until real screenshots are uploaded |
| Navigation | 4 | 8 | Dropdowns, role menu, accessible drawer |
| Mobile experience | 5 | 8 | No overflow at 320, rails, compact header, ad landings without app interruptions |
| Interactivity | 3 | 7 | Tabs, autocomplete, calculator, drawer; no map or saved-search from the homepage |
| Social proof | 2 | 4 | Fake/goal numbers removed; verified-only slots exist but are empty |
| Conversion readiness | 4 | 8 | Role CTAs, campaign variants, return URLs, Meta/attribution intact |
| Accessibility | 5 | 8 | axe 0 serious on key pages; keyboard and reduced-motion tests |
| Performance | 3 | 7 | Hero −97%; global `components.css` and external fonts remain |

Actionable next steps for anything below 8: upload real dashboard screenshots and authentic event photos (Product demos, Visual identity, Hero); collect permissioned testimonials/logos (Social proof); encourage vendors to make Passports public (Vendor cards); split `components.css` per template and self-host fonts (Performance); add "near me"/radius to the hero search (Search).

## 26. Functional test results

- `tests/e2e/public-website.spec.ts` — 24 tests (search URL, city autocomplete, tab keyboard, cards, dropdown, role menu, drawer focus/Escape, 320 px overflow, Fee Engine pricing + calculator, unsafe query input, return-to-opportunity, sign-up role + redirect, signed-in vendor and organizer, campaign variant noindex/canonical/attribution, variant fallback, ViewContent tagging, app prompts off on landings, draft preview, reduced motion, FAQ schema, axe × 3).
- `scripts/e2e/php/public-website-test.php` — 34 checks (snapshot shape and caching, counts equal published open listings, no deleted opportunities on cards, featured vendors public-only with no contact fields, context validation, REST permission callbacks and argument validation, pricing equals Fee Engine, variant sanitizing, landing settings sanitizing, retired-claim migration).
- ViewContent tagging was verified with the Pixel temporarily in test mode (no server-event token; Meta script stubbed); settings were restored.

Results of the final run: see §27.

## 27. Regression test results

Final run against wp-env (`PLAYWRIGHT_BASE_URL=http://localhost:8888`), projects `chromium` (Desktop Chrome) and `mobile` (iPhone 13 / WebKit):

| Suite | Result |
|---|---|
| `public-website.spec.ts`, `landing-launch.spec.ts`, `market-pages.spec.ts`, `public-pages.spec.ts` | 106 tests: 84 passed, 22 skipped (conditional on data/flags absent locally), 0 failed |
| `public-website-test.php` | 34 checks, 0 failures |
| Communications + engagement PHP suites (15: schema, engine, lifecycle, preferences, push, organizer, activation, security, partners, policy, engagement, admin, admin-render, invite-expiry, event-fulfillment) | all passed |

`landing-launch.spec.ts` was updated for the new header (dropdown/drawer instead of a flat "Events" link), the renamed "Browse all events" link, and the live-counts rule (the old trust-section assertions targeted removed goal stats); its `login.css` selector now targets the theme file (wp-env serves core's unminified `login.css` too).

Not run here: `marketplace-v2`, `communications`, `engagement` and `meta-measurement` Playwright specs need the seeded users in `scripts/e2e/.env.e2e`, which currently point at a different local environment (port 8892). The concurrent session's `event-media.spec.ts` asserts the old homepage card classes (`.lnkup-event-card__media`); its copy assertions still pass because the live-events section reuses that copy, but the homepage image selector needs updating to `.lp-event__media img` in that spec.

## 28. Modified files

**Plugin (`wp-content/plugins/lnkup-marketplace/`)**
- New: `includes/public/class-public-discovery.php`, `includes/public/class-public-discovery-rest.php`, `includes/public/class-campaign-landings.php`
- Modified: `lnkup-marketplace.php` (3 includes + 3 `init()` calls only)

**Theme (`wp-content/themes/lnkup-theme/`, 1.6.1 → 1.7.0)**
- New: `inc/public-site.php`, `assets/src/public.css`, `assets/dist/public.css`, `assets/js/public.js`, `scripts/optimize-images.mjs`, `page-templates/landing-organizers.php`, `page-templates/landing-vendors.php`, `template-parts/home/*` (11), `template-parts/public/*` (12), `assets/images/**/*-{640,1280}w.webp` (34)
- Modified: `functions.php`, `header.php`, `front-page.php`, `inc/enqueue.php`, `inc/setup.php`, `inc/template-tags.php`, `inc/landing-settings.php`, `assets/js/theme.js`, `package.json`, `template-parts/landing/site-header.php`, `template-parts/landing/site-footer.php`
- Removed (no longer rendered): `template-parts/landing/{hero,categories,for-audiences,trust,get-the-app,final-cta}.php`

**Tests, tooling, docs**
- New: `tests/e2e/public-website.spec.ts`, `scripts/e2e/php/public-website-setup.php`, `scripts/e2e/php/public-website-test.php`, `scripts/capture-public-screens.mjs`, `docs/PUBLIC_WEBSITE_5.md`, `docs/public-website-5/screenshots/**`
- Modified: `tests/e2e/landing-launch.spec.ts`, `package.json` + `pnpm-lock.yaml` (`@axe-core/playwright`), `docs/CURRENT_STATE.md`, `docs/SYSTEM_OVERVIEW.md`

## 29. API changes

New, anonymous, read-only (`permission_callback => __return_true`, schema-validated args, public fields only):

- `GET lnkup/v1/public/discovery` → `{categories[], cities[], city_index[], tours[], stats{}, pricing{}, generated_at}`
- `GET lnkup/v1/public/discovery/cards?set=upcoming|weekend|closing|newest&limit=1..12&city=&state=&category=` → `{set, context, items[]}` (unknown city/category dropped)
- `GET lnkup/v1/public/vendors/featured?limit=1..12` → `{items[]}`

No existing route changed.

## 30. Database changes

No tables or columns. New options: `lnkup_campaign_landings`, `lnkup_campaign_landing_pages` (version flag), `lnkup_landing_draft`, `lnkup_landing_retired_copy_v2`. Transients: `lnkup_public_discovery_v1`, `lnkup_public_vendors_v1`. Two pages created on first admin load: `for-organizers`, `for-vendors` (existing pages are never overwritten).

## 31. Existing functionality preserved

Find Events feed, vendor/organizer directories, Passports, city market pages (still use the shared header/footer and how-it-works), tours, sign-up/login flows, app promotion elsewhere, launch/prelaunch mode, dashboards (untouched shell), Meta Pixel/CAPI/attribution, Fee Engine, event media resolver.

## 32. New functionality implemented

Design System 5.0; Homepage 5.0; `/for-organizers/` and `/for-vendors/`; campaign variants with admin; public discovery service + REST; role-aware search with autocomplete; Event/vendor/city/tour cards; header dropdowns, role menu and accessible drawer; footer rebuild; announcement bar; landing drafts/preview; verified-only proof blocks; FAQs with schema; responsive WebP pipeline.

## 33. Remaining production verification requirements

1. Confirm `/for-organizers/` and `/for-vendors/` are created on first wp-admin load and permalinks flushed (variants 404 otherwise).
2. Check the production marketplace index for orphan rows (local had 9) and reindex if needed.
3. With the Meta Pixel live, use Meta Test Events to confirm one ViewContent per landing view with `content_category` organizer_landing/vendor_landing.
4. Review the Find Events feed card width issue (§3) on production.
5. Confirm Fee Engine settings (commission %, service fee, `fee_payer_mode`) are the intended public prices before ads point at the landings.
6. Lighthouse on production (CDN, caching and fonts differ from wp-env).

## 34. Deployment requirements

Deploy theme `lnkup-theme` 1.7.0 (includes built `assets/dist/public.css` and WebP files) and the plugin build containing `includes/public/*`. After deploy: load wp-admin once as an administrator (creates pages, flushes rewrites), review Appearance → Landing Page and LNKup → Campaign Landings. No migration scripts, no cron.

## 35. Rollback strategy

Redeploy the previous theme (1.6.1) and plugin builds. The new options are inert without the code; the two pages can be set to draft. No data is migrated except the one-time removal of the retired footer claim and the unused custom-stats keys from `lnkup_landing`.

## 36. Commit status

Committed in four groups (see `git log`): public discovery API and campaign landings (plugin); Design System 5.0, header, footer and responsive images (theme); Homepage 5.0, acquisition landings and Landing Settings (theme); tests, tooling and this report. The plugin bootstrap was staged hunk-by-hunk so the concurrent session's uncommitted loader lines were not included.

## 37. Uncommitted changes

Left untouched for the concurrent session: everything under `includes/meta/`, `includes/media/`, `helpers.php`, `class-seo.php`, `class-shortcodes.php`, `class-registration.php`, their other loader/version lines in `lnkup-marketplace.php`, `template-parts/landing/featured.php` (still used in prelaunch mode), the mobile/Next.js changes, `docs/META_MEASUREMENT.md`, `docs/audits/`, `tests/e2e/{event-media,meta-measurement}.spec.ts` and `tests/php/*`. The plugin version constant was not bumped (12.36.0 belongs to that session).

## 38. Remaining improvement opportunities

- Real dashboard screenshots, authentic event photography, permissioned testimonials and logos.
- "Near me" / radius search and a map view on the homepage.
- Per-template CSS splitting for `components.css`; self-hosted fonts.
- Migrate the Find Events feed and sign-up page to Design System 5.0 components.
- A/B testing for anonymous visitors (current `LNKup_Rollout` covers signed-in users only).
- Saved-search prompt on the vendor landing once `flow_discovery` is enabled.

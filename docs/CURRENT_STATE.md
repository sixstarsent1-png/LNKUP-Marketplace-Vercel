# LNKup Marketplace Platform — Current State

Complete inventory of the LNKup vendor opportunity marketplace monorepo.

For the full architecture reference, see [SYSTEM_OVERVIEW.md](SYSTEM_OVERVIEW.md) (includes **App Roadmap Phases 1–26** in §14.2). For the connected organizer → vendor → money product narrative, see [E2E_MARKETPLACE_JOURNEY.md](E2E_MARKETPLACE_JOURNEY.md). For app vs WordPress parity gaps, see [APP_GAP_ANALYSIS.md](APP_GAP_ANALYSIS.md). For design tokens, components, surfaces, and copy guidelines, see [UI_UX.md](UI_UX.md).

**MVP scope:** Vendor-only marketplace layer — not attendee ticketing. Ticketing stays on Posh, Eventbrite, Universe, etc.

**Plugin version:** 12.22.0  
**Theme version:** 1.6.0

### Trust, Safety & Compliance OS — Stages 1–3 (Trust schema v1)

Audit: [TRUST_SAFETY_AUDIT.md](TRUST_SAFETY_AUDIT.md) · Roadmap, flags and rollback: [TRUST_SAFETY_ROADMAP.md](TRUST_SAFETY_ROADMAP.md).

- **Claims:** external event claim links are bound to the invited email/domain; second claimants get `claim_disputed` + a case, never the listing; rate limits; admin approve/reject.
- **Foundation:** hash-chained `wp_lnkup_audit_log` (daily integrity check, document downloads logged, financial audit IPs hashed); `wp_lnkup_trust_cases` queue fed by fraud flags, chargebacks and rejected payment confirmations; wp-admin **LNKup → Trust & Safety**.
- **Organizers:** verification status machine + domain control (email code / DNS TXT); separate payout / domain / LNKup-review indicators; organizer **Team & Trust** view with organization teams (flag `trust_org_teams`).
- **Listings:** `_lnkup_authenticity_status`; flag `trust_publish_gate` enforced in discovery, apply and checkout; payout-account swaps and organizer changes open cases; duplicate scan opens cases only.
- **Tests:** `scripts/e2e/run-trust-php.ps1` (`trust-safety-test.php`, `trust-admin-render-check.php`), `tests/e2e/trust-safety.spec.ts`, cross-tenant additions in `organizer-security.spec.ts`.

### v12.22.0 (Financial reconciliation — ledger integrity, money contract, repair)

Deliverables, file list and test results: [finance/README.md](finance/README.md). Root causes: [finance/ROOT_CAUSE.md](finance/ROOT_CAUSE.md). Definitions: [finance/MONEY_DEFINITIONS.md](finance/MONEY_DEFINITIONS.md).

- **Ledger:** `entry_type` widened to `varchar(32)` (every `connect_settlement` insert used to fail silently); unique `idempotency_key`; insert-first atomic balance updates; per-payment `GET_LOCK` around finalize; settle recovery via `_lnkup_settled_at` (automatic only after `lnkup_fin_auto_recovery_since`).
- **Pure reads:** money GETs no longer reconcile; `POST /financial/sync`, Cash Out and the locked hourly cron (`lnkup_financial_hourly`) do.
- **Correctness:** late `payment_failed` cannot unpay; fee snapshot kept at settle; platform recovery credits net not gross; cancelled destination cashouts release processing; Checkout Session intent lookup; async Checkout / payout cancel/update / dispute-closed webhooks.
- **Money contract v1** (`money` on organizer dashboard/wallet/balances, vendor dashboard): integer cents, source, `synced_at`, unavailable instead of $0, payout state + Cash Out reason. Web, Next.js and mobile mappers read it (mobile not published).
- **Lists:** transactions newest-paid-first with paging headers; ledger paging with payment/payout references; one row per booking.
- **Admin:** read-only `admin/financial/reconcile` + `ledger-audit`, approval-gated `admin/financial/repair` (dry run → token → offsets, never deletes), wp-admin → LNKup → Reconciliation.

### v12.21.0 (Organizer Experience 2.0 — tenancy, capacity, money, Requests, operations)

Findings, root causes, and verification status: [ORGANIZER_AUDIT_MATRIX.md](ORGANIZER_AUDIT_MATRIX.md). QA run sheet: [ORGANIZER_QA_CHECKLIST.md](ORGANIZER_QA_CHECKLIST.md). Local runtime: `@wordpress/env` + `scripts/e2e/seed.php` + `scripts/e2e/probe-organizer.php` (46 probes).

- **Tenant isolation:** operations, map, support, incident, booth, parking, load-in, sponsor, refund-calculate and intelligence routes check the event the row belongs to; another organizer gets 403/404.
- **Staff matrix:** `LNKup_Capabilities::staff_can()` + `LNKup_Event_Staff::capability_matrix()` (operations, coordinator, finance, parking, load-in, power, medical, security). Financials are owner/finance only; staff can no longer add staff; `organizer_owns_event()` means owner (or manage-all) only.
- **Capacity:** new `LNKup_Opportunity_Inventory` derives `_lnkup_spots_remaining` from applications holding a spot (approved / payment_required / paid / completed). Approve takes a per-opportunity lock and returns 409 `opportunity_full`; a one-time backfill corrects existing counts (logged). Declined/cancelled bookings void their unpaid payment; checkout refuses non-pending payments. Optional unpaid-hold expiry via `payment_hold_expiry_days` (off by default).
- **Payment status fix:** the meta sanitizer used to rewrite `processing` (ACH in flight) and `partially_refunded` to `pending`, making them payable again. Both are now kept.
- **Refunds:** one open request per payment, claim-before-refund (double approve → 409), amount capped at the remaining balance, `partially_refunded` status, credit refunds grant credit only (no card refund), all card refunds through `LNKup_Financial_Service::refund()`.
- **Cash out:** per-organizer lock, `standard|instant` allow-list, conditional cancel, optional `client_token` replay protection.
- **Idempotent create:** Create Event, opportunity rows and Duplicate Event accept a `client_token`; a retry returns the original record. When the event saves but slots fail, web and app show "Your event was saved — Retry vendor slots" instead of a green toast. Duplicate keeps closed slots closed and never copies bookings.
- **Request More Information:** new `info_requested` status; organizer asks (web card, drawer, app sheet), vendor replies on Requests (web and app); history on the application; notifications both ways.
- **Requests inbox:** To review · Needs info · Approved · Awaiting payment · Confirmed · Waitlisted · Declined · Missing docs; counts update in place; drawer gains Decide, Q&A and history with you.
- **Home / workspace:** organization welcome, Action Center (incl. events with no open spots), server-rendered recent activity, event cards scoped to the events shown; workspace tabs Overview · Opportunities · Applications · Confirmed · Financials · Operations · Check-in (sticky), Events highlighted in the nav, Applications link keeps the event.
- **Editing logistics:** a date, time, venue or address change notifies booked vendors and re-syncs listings; fees never change.
- **Inventory UI:** each slot shows open · held · paid · waitlisted, "Spot open — review waitlist", and Preview as vendor.
- **Financial Center:** Gross collected · Platform fees · Refunds · Net · Pending · Available to cash out · In transit · Paid out (each explained; pending ≠ available) plus a By-event table. Dispute dialogs replace `prompt()`/`alert()`.
- **Operations / check-in:** booths are one-per-vendor-per-event and only for approved vendors; a paid vendor's booth change sends a pass update. Event-day status can no longer set approval/payment states; owner/operations overrides need a reason and are written to the operations log; duplicate scans say "already checked in". Scan page shows Expected · Checked in · Not yet · Exceptions and a vendor lookup, and opens for check-in staff.
- **Packets:** Draft / Ready / Published label derived from pass completeness and payment unlocks.
- **Intelligence:** Applications → Approvals → Payments → Confirmed → Check-ins funnel and an owner-scoped event comparison. Past events offer "Rebook N vendors".
- **Vendors / Lists / Invites:** load from the sidebar (`?view=`), duplicates surfaced, invites bound to the invited vendor and answerable once; discover excludes private profiles and searches name OR city.
- **JS hygiene:** `crmFetch`, `mapFetch` check JSON and status; no remaining `alert`/`prompt`/`confirm` in organizer CRM, maps or financial (new `LNKupUI.askText()` dialog).
- **Native organizer:** shared cache for events, requests and Money; tab badge subscribes and polls only in the foreground; review re-reads server status and surfaces bulk failures; Ask for more info sheet; publish and cash out send a `client_token` and handle partial success; no demo events in live mode; push routing for `info_requested`, `organizer_event_needs_attention` and event-scoped requests; Money no longer re-derives "collected" from activity.

### v12.20.0 (Vendor Experience 2.0 — security, payments, Home, Find, Requests)

Full findings and verification status: [VENDOR_AUDIT_MATRIX.md](VENDOR_AUDIT_MATRIX.md). QA run sheet: [VENDOR_QA_CHECKLIST.md](VENDOR_QA_CHECKLIST.md).

- **Data exposure:** only `lnkup_event` and `lnkup_opportunity` stay in core `wp/v2` REST; applications, payments, documents, packets, profiles, CRM records, reviews, and their meta are served only by `lnkup/v1`. Vendor document attachments are hidden from `wp/v2/media` and attachment pages; new uploads go to `uploads/lnkup-private/` (deny rules) and must be PDF/JPG/PNG under 10 MB. Existing files were flagged, not moved.
- **Payments:** `confirm_checkout` no longer accepts `sim_` IDs unless simulation is explicitly enabled (test mode with no live Stripe, or `LNKUP_ALLOW_PAYMENT_SIMULATION`). Stripe intents must carry this payment's `payment_post_id` and at least the amount due. Reconcile and meta backfill use the same binding. Missing Stripe keys return a friendly `payments_unavailable` instead of silently simulating.
- **Free booths:** a $0 amount due finalizes through the Financial Service on approval, prepare, or redirect checkout — no Stripe call, no vendor fee, no "payment required" email.
- **Credits / promos:** credits apply only to the owner's pending payment and only the uncovered remainder (locked); one promo redemption per payment.
- **Integrity:** application transitions follow an allowed-from map (409 `invalid_transition`); apply is locked per vendor + opportunity; throttle set only after success.
- **Vendor ops:** vendor home and weather ownership read `_lnkup_owner_user_id` (new `LNKup_Capabilities::vendor_owns_profile()`).
- **Native checkout:** hosted checkout returns via `GET /financial/checkout/native-return` → `lnkup://stripe-redirect`; the auth session closes itself and the Pass polls server status.
- **Home:** welcome with logo, one Next step, linked metrics, upcoming event, recommendations, recent activity.
- **Requests:** All · Needs action · In review · Confirmed · Past events; timeline through Event day / Completed; free booths skip Payment.
- **Find / Apply:** one status vocabulary on cards; cards show the vendor's application status; booth chooser; progress steps; filters restore from URL; infinite scroll works; guest ♥ goes to signup.
- **Reliability:** checkout, wallet, growth, operations, documents, and marketplace calls clear loaders and show retryable errors.
- **Nav / tokens:** Home / Find events labels from `lnkup_copy`; storefront icon for Profile; missing `--lnkup-*` tokens defined; reduced-motion support.

### v12.18.86 (Invite name and one payment)

- Overview invites use the event post title, date, and city. If the saved notice does not match an invite, sent and opened invites for that vendor are shown instead.
- Vendor Wallet and organizer Money list one row per event. The amount is the overall total. Installments for that event add up.

### v12.18.85 (Invite name and Find filters)

- The vendor Overview invite names the event, date, and city. If the invite only has a slot, that name is shown.
- Find filters are grouped into Where, When, Fee, Documents, and More. Short fields sit two across from 640px, and checkboxes wrap with space between them.

### v12.18.84 (Money and wallet screens)

- Organizer Money and vendor Wallet show one overall price per application. Installments add up. A duplicate charge is listed once.
- Money, Revenue, Planning, and Account stay above Overview, Wallet, Cash out, and Payouts. Those buttons stay above the figures and the month chart.
- Revenue by month uses paid payment totals, one amount per application.
- Wallet ledger marks money in green and money out red. Cash out is a card with Standard and Instant (1.5% fee).
- Invoice View opens the invoice page. Payment methods are card rows. Receipts are cards with View.

### v12.18.83 (Vendor sell and location)

- Organizer vendor cards show category pills, or a short products line, and a city. Cities served fills in when the home city is empty. Missing facts say “Category not listed.” and “Location not listed.”
- Organizer request rows and an event’s Vendors tab show the same sell and location line under the vendor name.

### v12.18.82 (Profile, event switcher, Money, and Vendors)

- The vendor Profile tab stacks every section in one centered 48rem column. Two-across fields stay inside each card from 640px.
- An event screen with more than one event has a switcher beside the title. It keeps the current tab.
- Organizer Money opens with Available, Pending, Collected, and Paid out, a payout-status line, the month chart, and recent payment activity. Revenue, Planning, and Account stay under that.
- Vendors is a two-column gallery from 768px and three columns from 1200px. Each card keeps the logo or initials, name, city, categories, event count, and Invite.

### v12.18.81 (Profile edit and share)

- The workspace bar has Edit profile, Copy link, and View for organizers and vendors.
- Organizer Edit profile opens the existing profile form on Events. Vendor Edit profile opens the profile page. Copy link and View use the public profile URL.
- The vendor profile header also has Copy link next to Preview.

### v12.18.80 (Organizer list, slots, and More)

- Overview titles the pending rows Recent requests. Event cards keep Open and Requests, and Duplicate uses the existing copy form.
- The cross-event slot list leaves the Events page. Slot name, fee, spots, and open or closed stay on that event’s Slots tab.
- More sits on the top right of the workspace bar and opens the existing sidebar. The phone bar is the five primary links.
- Vendor cards show city, categories, and a completed-event count.

### v12.18.79 (Vendor profile width)

- The vendor Profile tab uses the dashboard width. Business Basics stays a 42rem card. From 768px the other sections sit in two columns, and Photos spans both.

### v12.18.78 (Organizer event pages)

- The organizer dashboard shows one section at a time. Overview is the default.
- Open on an event goes to that event’s screen: Overview, Slots, Requests, and Vendors. Edit still opens the existing form.
- The Requests inbox stays in the nav. An event name there opens that event’s Requests tab.

### v12.18.77 (Vendor Wallet and Profile)

- Wallet opens with Balance and a payments list of event, city, amount, and Paid or Due. Pay, Wallet, Extras, and Reports stay under that list.
- Profile opens as one card: logo, business name, city, and category pills, then a photo grid. The other sections keep their save buttons. The strength sidebar is gone.

### v12.18.76 (Vendor Overview, Find, and Requests)

- Vendor Overview is a bordered title, a four-cell count, one next action, and up to three request rows. With no applications, it shows Find an event.
- Vendor Requests uses the same title and pill filters. Pay now stays on the row.
- Find events is a search row and a city field, then a two-column gallery. Category, sort, and the other filters stay behind Filters. View opens the public event.

### v12.18.75 (Dashboard look)

- Public vendor and organizer profiles use a wide rounded cover, a square mark, and the name and city on one row. Invite and Share sit on that row. The public vendor page no longer shows the score rail, metrics, or reliability sidebar.
- Dashboard content is centered. Lime fills leave the dashboard. Status is a dot and a label. Cards stay flat with an 8px border.

### v12.18.74 (Dashboard screen bodies)

- Vendor Requests is one row list: event, slot, fee, and status. Filters are Applied, In review, Pay now, and Booked. Pay now stays on a pending payment.
- Organizer Requests is the same kind of row. Accept and Decline stay on new requests.
- Event cards keep the image, status, date, and city, plus vendor slots and spots open. Open and Requests stay. Revenue and Operations leave the card.
- Vendors opens on search and a card with name, city, categories, and Invite. Discover, import, and filters stay behind the expand control.
- Vendor Wallet first shows the balance and Pay tabs. Wallet, Extras, and Reports stay on the section buttons.

### v12.18.73 (Secondary pages and Money)

- Lists, Invites, Insights, Launch, Event operations, Documents, Growth, Referral Invites, and Reviews open with a one-line title. Their lists and actions stay.
- Organizer Money first shows Overview, Wallet, Cash out, and Payouts. Revenue, Planning, and Account stay on the section buttons.
- The public vendor page keeps About, photos, and events. Setup, power, water, and payment fields stay off that page. Invite stays for a signed-in organizer.

### v12.18.72 (Lists, event workspace, Money, Find, profiles)

- Requests, slots, and vendors use one bordered list. Accept and Decline stay on new requests. Pay stays on payment due.
- The event page has a facts column with the public link and Copy. Edit still uses the existing form.
- Money opens on balances, payout status, the month chart, and recent activity. Other sections stay in the section nav.
- Find cards open the public event. Public profiles use the shared header. Empty and error states share one pattern.
- The dashboard title under the workspace bar is visually hidden. More stays in the sidebar.

### v12.18.71 (Type, workspace bar, public pages)

- The theme uses Geist and Geist Mono. Dashboard cards are flat 8px borders, and status is a dot plus a label.
- The dashboard has a real top bar with the workspace name and the open event. Phone navigation stays the bottom bar.
- The public header is Events, Organizers, and Vendors. Find cards open the public event. Vendor profile edit is stacked sections, without documents on that page.

### v12.18.70 (Public event slots)

- The public event page titles the list Vendor slots. Fee, spots open, and the free-to-apply line stay.
- Organizer profile cards keep View vendor options and Apply for {slot}, which opens the same `?apply=` link.

### v12.18.69 (Vendors, vendor Requests, and Wallet)

- Organizer Vendors is a title, search, and Invite.
- Vendor Requests drops the tracking paragraph. Pay stays on payment-due rows.
- Vendor Wallet drops the payments essay and keeps the balance and payment list.

### v12.18.68 (Events, Requests, and Money)

- Events is a title, Live / Drafts / Completed, and Launch event. Public profile stays a small secondary action.
- Requests drops the extra review paragraph. Accept and Decline stay on new requests.
- Money opens on the existing balances, payout status, and chart.

### v12.18.67 (Quieter dashboard homes)

- Organizer Overview is one upcoming event, one waiting row, four counts, and two requests.
- Vendor home is a greeting, Applied / In review / Pay now / Booked, one next action, and a short request list.
- The event page adds a Vendors tab for accepted and booked vendors. Accept and decline stay on new requests.

### v12.18.66 (Dashboard shell from lnkupdashboards)

- Organizer and vendor dashboards use a top navigation bar: Overview, Events, Requests, Money, Vendors, and Overview, Find, Requests, Wallet, Profile.
- Public event and organizer profile cards use the same flat 8px border treatment.

### v12.18.65 (Invite drawer and revenue charts)

- Invite vendor opens a full-height drawer with the event form inside it.
- The organizer revenue dashboard stays JSON when a chart value cannot be encoded, and month bars fall back to paid booth payments.
- A cashout that does not save does not debit the wallet. A failed Connect transfer puts the amount back.

### v12.18.64 (Apply Sign up navigates)

- Sign up and Log in inside the apply window leave the page. After signup, the visitor returns to that same slot.

### v12.18.63 (Guest apply from an organizer profile)

- Guests see Log in and Sign up. Dashboard is only for a signed-in vendor or organizer.
- A public event or apply link stays on the public page, not inside the vendor dashboard.
- Organizer profile cards include Apply for a slot. Logged-out visitors sign up or log in and return to that same application.

### v12.18.62 (Organizer and vendor home)

- Organizer home opens with the command center, the existing stat grid, two upcoming events, and two pending requests.
- Vendor home opens with a greeting, a KPI row, and one next action.

### v12.18.61 (Organizer events match the app example)

- Events uses a header, Live / Drafts / Completed tabs, and a two-column card with Manage event, View requests, and Operations.
- The event edit page shows request counts and tabs for overview, vendor slots, and requests.

### v12.18.60 (Per-event edit page)

- Edit event opens `organizer-dashboard/?event={id}&edit=1` with the listing form and that event’s vendor slots. The Events list no longer hides a full editor on every card.

### v12.18.59 (Public storefront template)

- The organizers page uses the theme Public Storefront template, so the WordPress title “Organizers” is not printed around the profile.
- Vendor slots across events are full-width cards, with the event name and city on one line and each slot on a row that uses the dashboard width.

### v12.18.58 (Public profile layout)

- Organizer profile cards use the full width when an event has no photo. The organization name sits below the cover, and the WordPress “Organizers” title is hidden on that page.

### v12.18.57 (Public organizer profile and event page)

- The public organizer profile is a storefront: cover, logo, upcoming events as one card each, then about and past events.
- The public event page leads with vendor opportunities, keeps the street address private when hidden, and says applying is free.

### v12.18.56 (Vendor slot list layout)

- Vendor slots across events sits under the event cards. Slots are grouped by event, with the name, price, spots, and Save on one row.

Organizer baseline (ORG-00–04): [ORG_BASELINE.md](ORG_BASELINE.md). The cross-event vendor-slot list is in 12.18.55. Local WordPress at `http://localhost:8888` was not running when that baseline was written.

### v12.18.55 (Vendor slots across events)

- Events shows every vendor slot in one list, with the event name, city, and date. Name, price, and spots save from that row. Close, reopen, and remove work the same way as on a single event card. A filter narrows the list when there are several events.

### v12.18.54 (Organizer event command)

- Edit event saves photos, indoor or outdoor, and vendor amenities.
- Vendor slots can be renamed. A slot with requests can be closed without deleting those requests. The extra fee table is no longer on the Events page.
- Review requests and Find vendors are on the vendor slots panel.

### v12.18.53 (Public event details and duplicate venue)

- The public event page shows category, attendance, indoor or outdoor, vendor amenities, tickets, organizer about, and each slot’s category, deadline, and spots filled.
- Duplicate asks for city, state, venue, and address, prefilled from the original, so the copy can use a different place.

### v12.18.52 (Vendor slots on the event card)

- **Vendor slots** on the event card lists each slot so you can change price and spots, add another, or remove one that has no vendor requests. Removing the last open slot returns the event to Draft.

### v12.18.51 (Edit the whole event)

- **Edit event** sits next to Manage and opens the full listing: title, description, category, date, times, attendance, ticketing link, and venue.

### v12.18.50 (Edit events and organizer profile)

- **Edit event** saves the title, description, date, times, venue, address, and attendance that are already on the event.
- Organizers can set an organization name and about text. The public profile shows that above their live events.

### v12.18.49 (Public event page)

- **Share event** copies one public link. That page shows the event details and every open vendor slot, with Apply on each slot.

### v12.18.48 (Empty vendor roster)

- Manage with no vendors hides bulk check-in actions and explains that the roster fills after vendors apply. Next steps are Review requests, Discover vendors, and Publish when the event is still a draft.

### v12.18.47 (Publish drafts, requests, and public links)

- Event cards show **Live** when a vendor slot is open and **Draft** when none is. **Publish** on a draft card adds the slot that takes it live.
- Vendor requests: event dropdown on small screens, a sticky “showing requests for” line, and status counts for the selected event. Event cards link to **Review requests**.
- Discover and Vendor lists sit together in the sidebar, with clearer copy and a Discover shortcut from lists.
- **Share event** copies the public apply link. **Your public profile** is `/organizers/?organizer={slug}` with live events.

### v12.18.46 (Duplicate events and vendor slots)

- Events command: **Duplicate** copies the event, vendor slots (fees, spots, documents), and venue map onto a new name and date. Applications, payments, and roster are not copied.
- **Add vendor slot** on the event card. Preset vendor type or a custom type (saved as a vendor category). Multiple rows in one save.
- Create wizard: custom type on each slot row. Closing vendor applications no longer blocks publish on an empty vendor type.

**WordPress deploy:** GitHub Action `deploy-wordpress.yml` rsyncs plugin + theme to SiteGround over SSH. Do not clone this monorepo into `public_html`. See [SITEGROUND_DEPLOY.md](SITEGROUND_DEPLOY.md).

### v12.18.43 (P2/P3 QA)

- Mobile: page overflow contained (Financial tables, compare bar, dashboard header wrap, dashboards / Find Events / profile). Dead card images fall back to category JPGs.
- Organizer Financial: unsynced destination balances show ledger Available; dashboard-fail shows Retry instead of a sticky “Balance is syncing” block.
- Map empty states: organizer Open Map Builder + Upload; vendor “coming soon”.
- Event Pass pending booth: next step, organizer contact, optional event-date timing (no invented ETA).
- Apply modal: category mismatch / incomplete profile link to Update profile.
- Dashboard hashes `#events` / `#applications` / `#financial` scroll with `scroll-margin` and pause observer fight.
- Find Events: stronger external cards, “Apply on LNKup” badge, **LNKup listings only** filter.

### v12.18.42 (P1 QA: readiness / docs / list / find-events)

- Event readiness: unpaid booth fee shows **Needs Payment** + blocker **Collect booth payment**. Checklist pending label is **Payment due**. $0 fee is treated as paid (not Needs Payment).
- Vendor requests: docs chip/filter/Review docs follow document context. No **Docs pending** when none are required.
- Find Events List: ID-level CSS forces a single column of full-width rows (no 2-col grid clip).
- `/find-events/` 301s to the opportunities page (query string kept) unless a real `find-events` page exists.

### v12.18.39 (In-app signup + privacy URL)

- `POST /lnkup/v1/auth/register` creates the same vendor/organizer account as web `/sign-up/` and returns a mobile Bearer token. Web form still uses the shared `LNKup_Registration::register_user()`.
- Native **Create free account** opens in-app signup (`app/signup.tsx`) instead of Safari. Sign in and Create account link Privacy / Terms at `https://thelnkup.com/privacy` and `/terms`.
- Operator must set App Store Connect Privacy Policy URL to `https://thelnkup.com/privacy`.

### v12.18.38 / theme 1.4.19 (Find Events category photos)

- Cards still prefer organizer `_lnkup_featured_image_id` and Eventbrite `image_url`.
- Missing photos use one realistic JPG per seeded `lnkup_vendor_category` (Food Truck is an actual food truck). Unknown categories use a general market photo. Dark `cat-*.svg` diagrams are no longer live fallbacks.
- `lnkup_format_index_row()` puts the resolved URL on `featured_image` so JS rails match PHP cards. Alt is `{category} in {city}`.

### v12.18.37 (Content Studio UI polish)

- **Builder sheet:** Sticky header with duration/audience chips and a real Close button (`lnkup-btn--outline` + ×). Hook, script, caption, on-screen, and shot list are grouped rows with Copy on the heading. Footer: Open Referral Link, Download Shot List, Mark as Planned.
- **Publish form:** Platform and audience `<select>`s (`instagram` / `tiktok` / `linkedin` / `facebook`; never `instagram_reels`). Link placeholder and hint follow the selected host; pasted URLs can set the platform. LinkedIn hosts allowed on the server; YouTube stays allowed but is not in the select.
- **Performance:** Empty state with a “Submit a published link” cue. Four cards (Published posts, Tracked clicks, Registrations, Bookings) plus a muted attribution disclaimer. Mission and post rows use human labels (`Instagram · Organizers · 30s`, Planned).

### v12.18.36 (Connector launch plan + Content Studio)

- **Launch plan:** Get Started is a three-phase plan (Set Up / Promote / Activate). Rank 1 uses Phase 1 only. Legacy `reviewed_targets` migrates to `reviewed_earnings`. Growth styles (`relationship`, `video`, `community`, `event`) persist on the connector row and drive next-best-action.
- **Content Studio:** Private CPTs `lnkup_cn_template` / `lnkup_cn_post`, seeded video + Story templates, REST `/connector/studio*`. Optional `cc=` on referral URLs logs `content_click` metadata only. Publishing a video never creates commission.
- **UI:** Specific CTAs (no generic Continue), growth-style cards, Resources grouped by type, bottom nav Share → Create. Theme 1.4.18.
- **Tests:** `node scripts/connector-studio-verify.mjs` + launch-plan / studio cases in `tests/e2e/connector-program.spec.ts`.

### v12.18.35 (City Connector ranks)

- **Ranks:** Five-tier City Connector → Regional Partner in `LNKup_Connector_Ranks`. Cached columns on `lnkup_connectors` plus idempotent `lnkup_connector_booking_attr` (`UNIQUE payment_id`). Qualifying booth GMV waits for the payout hold; refunds/chargebacks/test payments are excluded. Rank never raises the 10% commission and never auto-decreases.
- **Bonuses:** One-time pending `connector_rank_bonus_{rank}` rows in `lnkup_referral_rewards` with `idempotency_key`. Admin approve / mark-paid / reverse only — never auto-paid.
- **Dashboard:** Overview rank card + progress rows, `?view=ranks` roadmap, one-time celebration, opt-in city leaderboard (no dollars). Theme 1.4.17 adds Ranks to the connector sidebar.
- **REST:** `GET /connector/rank`, `/rank/roadmap`, `/rank/history`, `POST /rank/acknowledge`, `GET /connector/leaderboard`; admin recalc/override/reverse + attribution disputes.
- **Tests:** `node scripts/connector-ranks-verify.mjs` + unauth 401s in `tests/e2e/connector-program.spec.ts`.

### v12.18.34 (City Connector command center)

- **SPA dashboard:** In-dashboard `?view=` switching via `history.pushState` (no full reload). Desktop keeps sidebar + in-page tabs. Mobile (≤767px) uses a connector bottom nav: Home, Referrals, Share (opens sheet), Earnings, More.
- **Share sheet:** Organizer and vendor links, QR, copy / native share, Instagram deep link, outreach scripts, live-region “Referral link copied.” Marks `copied_link` / `copied_vendor_link` / `sent_intro`.
- **Overview:** Compact “Grow LNKup in [City]” hero, unmixed KPIs, backend `next_best_action()`, funnel conversion % only when the from-step count ≥ 5, activity timeline from `GET /connector/referrals`.
- **Referrals / Earnings / Profile:** Client-side referral search and filters; ledger status filter + organizer display name; optional `bio` column; WordPress avatar; next payout is earliest `available_at` or “After LNKup approval.” Cap copy still appears only when `share_cap > 0`.
- **Tests:** `tests/e2e/connector-program.spec.ts` share sheet, filters, calculator POST, viewports 320/375/768/1024/1440.

### v12.18.33 (City Connector Onboarding and Earnings Education)

- **Rewards:** Organizer-primary share of booth GMV (`_lnkup_vendor_spot_fee` × `connector_booking_share_percent`, default 10%). Term starts at the organizer’s first completed booking. 30-day hold via `available_at`. Refunds reverse unpaid rewards (`lnkup_refund_completed`). Per-transaction cap setting defaults to 0 (off). Manual approve / mark-paid unchanged. No Stripe payout from dashboard JS.
- **Dashboard IA:** Overview, Get Started, My Referrals, Earnings, Resources, Profile. New connectors default to Get Started. Legacy `?view=` aliases kept.
- **REST:** `/connector/onboarding`, `/onboarding/step`, `/funnel`, `/earnings`, `/resources`, `/terms`, `POST /calculator`, `POST /profile`.
- **Settings:** share %, cap, term months, hold days, minimum payout, vendor-referral reward flag, terms/support URLs.
- **Tests:** `tests/e2e/connector-program.spec.ts` + `node scripts/connector-earnings-verify.mjs`.

### Launch day (2026-08-02)

- **Marketplace open:** `platform_prelaunch_mode` default `false`; `platform_launch_date` `2026-08-02`; one-time option `lnkup_launch_day_20260802_applied` flips existing installs on deploy.
- **Mobile app release:** `app_release_datetime` `2026-08-16 12:00:00` + `app_release_tz` `America/New_York` — homepage app-release bar until that moment (does not gate web browse/apply).
- **Editable homepage:** Appearance → Landing Page (`lnkup_landing`); post-launch CTA defaults; featured rails removed from home.

### Mock app UI (App Phases 1–6 — `lnk-up-marketplace-ui/`)

The canonical **Next.js app** for vendor/organizer/admin dashboards lives in [`lnk-up-marketplace-ui/`](../lnk-up-marketplace-ui/). **App Phase 2+ is app-only** — vendor More hub, secondary routes, live BFF integration. WordPress visual port is **deferred** (production website UI is considered done).

**Roadmap:** [SYSTEM_OVERVIEW.md §14.2](SYSTEM_OVERVIEW.md#142-app-roadmap-phases-126) · **Queued 22–26:** [PHASES_22_26_ROADMAP.md](PHASES_22_26_ROADMAP.md) · **App phases 22–26:** [PHASE_22_PRODUCTION_LAUNCH_HARDENING.md](PHASE_22_PRODUCTION_LAUNCH_HARDENING.md) · **Organizer baseline:** [ORG-00–04](ORG_BASELINE.md) · **E2E journey:** [E2E_MARKETPLACE_JOURNEY.md](E2E_MARKETPLACE_JOURNEY.md) · **Gaps:** [APP_GAP_ANALYSIS.md](APP_GAP_ANALYSIS.md)

```bash
cd lnk-up-marketplace-ui
pnpm install   # or npm install
pnpm dev       # http://localhost:3000 — role picker (Vendor / Organizer / Admin)
npx next build
```

- **24 app routes** (vendor/organizer/admin + secondary vendor screens)
- **5-item nav** per role (`lib/nav.ts` + `components/app-shell.tsx`); vendor mobile uses **More** as 5th slot
- **Panel states:** append `?empty=1` or `?error=1` on core routes for QA — see [`docs/PHASE_2_APP_QA.md`](PHASE_2_APP_QA.md)
- **Production mock:** https://lnkup-marketplace-ui.vercel.app
- **Do not use** root `pnpm dev` — that serves the legacy `app/` template

### Native mobile (Expo — Native Phases 1–10)

Canonical **iOS/Android** app: [`lnk-up-marketplace-mobile/`](../lnk-up-marketplace-mobile/). Design IA from [`lnkup-app-reference/`](../lnkup-app-reference/) (web mock only). Calls WordPress REST directly with Application Passwords in SecureStore — **not** the Next.js cookie BFF.

```bash
cd lnk-up-marketplace-mobile
npm install
cp .env.example .env
npx expo start
```

- **Native Phase 1 built:** login, vendor Home / Find / Requests
- **Native Phase 2 built:** read-only Wallet + Pay handoff (`Linking`), Event Pass (locked/unlocked), Apply from Find
- **Native Phase 3 built:** organizer lite — Home / Events / Vendors requests (approve/waitlist/reject) / Money (read-only) / More; role routing + dual-role switch
- **Native Phase 4 built:** Expo push device register + prefs; WP Expo Push on key events; EAS profiles/scripts (operator runs `eas init` / build / submit). Depth inventory: [PUSH_NOTIFICATIONS_DEPTH.md](PUSH_NOTIFICATIONS_DEPTH.md)
- **Activation drip (Phase 2):** welcome + finish-profile / find-events / apply (vendors) and create-event / review-vendors (organizers), including pre-tutorial backfill — [PHASE_ACTIVATION_EMAILS.md](PHASE_ACTIVATION_EMAILS.md)
- **Retention push (Phase 3):** saved search, favorites, invites, deadlines, profile match — [PHASE_RETENTION_PUSH.md](PHASE_RETENTION_PUSH.md)
- **Push prefs + badge (Phase 4):** category toggles + app icon unread badge — [PHASE_PUSH_PREFS_BADGE.md](PHASE_PUSH_PREFS_BADGE.md)
- **Native Phase 5 built:** vendor Profile readiness + Documents list/upload via WP REST; profile field edits remain WP handoff
- **Native Phase 6 built:** vendor More hub — Favorites, Growth lite, Reviews lite, Operations → Pass
- **Native Phase 7 built:** composed notification center (vendor + organizer); SecureStore mark-read; Home unread bell + More entry
- **Native Phase 8 built:** Find discovery polish — filters, sort, near-me (`expo-location`), saved-search CRUD; map view deferred
- **Native Phase 9 built:** organizer Launch lite — 3-step publish via `POST /organizer/launch/publish`, AsyncStorage draft, Home/Events/More CTAs
- **Native Phase 10 built:** store release hardening — privacy/support URLs, encryption flag, EAS submit scaffolding, [`STORE_RELEASE.md`](../lnk-up-marketplace-mobile/STORE_RELEASE.md); submit remains operator-run
- **Tab UI/UX polish (OTA-safe, app 1.0.1):** shared primitives in `components/ui/` (`Button`, `FilterChip`/`FilterChipRow`/`SegmentedControl`, `StatusBadge` + `statusToneColors`, `ListRow`/`ListGroup`, `StatTile`/`StatGrid`, `SectionHeader`, `InlineBanner`, `Skeleton*`, `FormField`, `CollapsibleSection`, `BottomSheet`, `PressableScale`). Both tab bars use `components/tab-bar.tsx` (safe-area bottom inset, `gray500` inactive tint, a11y badge labels). All 10 tabs use skeleton first paint, 44pt targets, pressed feedback, and the same empty/error/refresh-banner pattern. Application statuses are colored via `vendorRequestStatusTone` / `organizerRequestStatusTone`. Organizer Vendors is split into `components/organizer-vendors/`. Event detail collapses pricing/edit into sections with the review CTA up top. Money copy now says "Manage payouts" / "Opening payouts…" (no Stripe wording). JS-only, no new native deps — ship with `npm run update:preview` then `update:production`.
- **App Store readiness:** Theme seeds `/privacy`, `/terms`, `/contact`; in-app account deletion (Guideline 5.1.1(v)) via `POST /lnkup/v1/account/delete` + More → Delete account; ASC paste pack [`ASC_APP_REVIEW_PACKAGE.md`](ASC_APP_REVIEW_PACKAGE.md); verify `node scripts/app-store-readiness-verify.mjs`
- **Referral Invites (organizer recruit):** Program `organizer_recruit` — vendors/organizers invite outside organizers; **20% of LNKup platform fees for 12 months** from attribution; REST `/referrals/organizer-recruit*`; WP hubs + Expo/Next hard-push CTAs; docs [`PHASE_REFERRAL_INVITES.md`](PHASE_REFERRAL_INVITES.md); verify `node scripts/referral-invites-verify.mjs`
- **Brand assets:** Official LNKup logo (`lnk-up-marketplace-mobile/assets/brand/lnkup-logo.png`) → store icons via `npm run icons`
- **Architecture:** [SYSTEM_OVERVIEW.md §14.3](SYSTEM_OVERVIEW.md#143-native-mobile-architecture)
- **No Stripe SDK** in mobile; payments and deep organizer ops open WP URLs in the browser
- **WP inbox REST** — `GET/POST /lnkup/v1/notifications/inbox*` persists push events and shared mark-read; clients still compose live domain items and merge `readIds`. Push vs inbox depth: [PUSH_NOTIFICATIONS_DEPTH.md](PUSH_NOTIFICATIONS_DEPTH.md)
- **Plugin:** v12.18.25 — marketplace open (launch day) + app release settings (`app_release_datetime` / `app_release_tz`); push + account deletion + organizer recruit referrals (organizer or vendor invites via sign-up) + organizer UX routes
- **Theme:** v1.4.9 — app-release bar, editable landing defaults post-launch
- **EAS:** linked as `@darkero12/lnkup-marketplace`

### Phase 14 stabilization snapshot (Jul 2026)

- Phase 14A/14B/14C feature work is implemented and two-week stabilization execution is complete.
- New specs added and passing:
  - `tests/e2e/lnkup-app-phase14a-journey-handoffs.spec.ts`
  - `tests/e2e/lnkup-app-phase14b-organizer-loop.spec.ts`
  - `tests/e2e/lnkup-app-phase14c-wallet-polish.spec.ts`
  - `tests/e2e/lnkup-app-phase14-analytics.spec.ts`
- Focused regression sweep across Phases 9/13/vendor-phase3 is green after suite-drift selector alignment.
- Phase 14 quality gate workflow added: `.github/workflows/phase14-quality-gate.yml`.
- Execution record and go/no-go memo: [PHASE_14_TWO_WEEK_EXECUTION_LOG.md](PHASE_14_TWO_WEEK_EXECUTION_LOG.md).
- Live Stripe sandbox end-to-end verification still requires authenticated WP + Stripe test credentials in operator environment.

### App Phases 15–21 snapshot (Jul 2026)

| Phase | Theme | Status |
|-------|--------|--------|
| 15 | Admin Lite / Internal Ops | Built |
| 16 | Checkout return reliability | Built |
| 17 | Organizer CRM depth | Built |
| 18 | Vendor readiness / reliability | Built |
| 19 | Intelligence / experimentation | Built |
| 20 | Mobile organizer intelligence parity | Built |
| 21 | QA gate hardening + web on-site tutorials | Built |

- Consolidated gate: [PHASE_15_20_QA_GATE.md](PHASE_15_20_QA_GATE.md) · CI: `.github/workflows/phase15-20-quality-gate.yml`
- Playbook: [PHASE_21_RELEASE_PLAYBOOK.md](PHASE_21_RELEASE_PLAYBOOK.md)
- **Phase 22 Built:** [PHASE_22_PRODUCTION_LAUNCH_HARDENING.md](PHASE_22_PRODUCTION_LAUNCH_HARDENING.md) · audit [artifacts/phase22-live-api-audit.md](../artifacts/phase22-live-api-audit.md)
- **Phase 23 Built:** [PHASE_23_MOBILE_TUTORIAL_PARITY.md](PHASE_23_MOBILE_TUTORIAL_PARITY.md) · verify `cd lnk-up-marketplace-mobile && npm run phase23:verify` · First-run **tutorial popups** (vendor + organizer) walk incomplete steps; checklist remains after `popupSeen`; Skip all dismisses both
- **Phase 24 Built:** [PHASE_24_NATIVE_MAP_DISCOVERY.md](PHASE_24_NATIVE_MAP_DISCOVERY.md) · verify `cd lnk-up-marketplace-mobile && npm run phase24:verify`
- **Phase 25 Built:** [PHASE_25_ADMIN_LITE_DEPTH.md](PHASE_25_ADMIN_LITE_DEPTH.md) · verify `cd lnk-up-marketplace-ui && npm run phase25:verify`
- **Phase 26 Built:** [PHASE_26_FINANCIAL_OS_EXPANSION.md](PHASE_26_FINANCIAL_OS_EXPANSION.md) · verify `node scripts/phase26-verify.mjs` + `cd lnk-up-marketplace-mobile && npm run phase26:verify` · Session ACH finalize gate + webhook signature harden (secret set ⇒ no test_mode bypass)
- **Organizer event import Built:** [PHASE_EXTERNAL_EVENT_IMPORT.md](PHASE_EXTERNAL_EVENT_IMPORT.md) · verify `node scripts/phase-external-event-import-verify.mjs`
- **Organizer vendor profile enrichment Built:** [ORGANIZER_VENDOR_PROFILE_ENRICHMENT.md](ORGANIZER_VENDOR_PROFILE_ENRICHMENT.md) · verify `node scripts/organizer-vendor-profile-enrichment-verify.mjs`
- **Find / Launch media / Wallet invoices:** Marketplace search no longer fatals on typed `q` (analytics + history hardened). Optional event gallery (`_lnkup_gallery_ids`) on Create Event → WP/app detail. Invoice list includes `pdf_url` + View links. Verify `node scripts/find-media-wallet-verify.mjs`
- **Organizer UX wave Built:** Edit event after create (WP + apps); booth pricing by vendor type (multi-opportunity fee rows); Expo Home polish; Discover city/category/verified/sort filters; in-app Cash out + Stripe Express login-link (replaces primary “Manage on LNKup”); packet weather rain/heat merge + recommendations. Verify `node scripts/organizer-ux-wave-verify.mjs`
- **Queued 22–26:** Complete — [PHASES_22_26_ROADMAP.md](PHASES_22_26_ROADMAP.md)

### v12.18.19 (Getting Started + Knowledge Base)
- **Organizer welcome:** One first-run welcome modal (parity with vendor); Launch checklist “Getting started” eyebrow + optional dismiss.
- **Knowledge Base:** Static Help panels for vendor (`?view=help`) and organizer (`#help`); More nav Help entry; “Show getting started checklist again” resets dismiss without reopening welcome.
- **Theme 1.4.6:** Help icon + More nav links for vendor and organizer.

### v12.18.7 (Backfill platform payments when Available stays $0)
- **Root cause:** 12.18.6 migrate only moves wallet `available_balance`. If the booth payment never created a ledger row (UI had shown platform Stripe by mistake), claim stayed $0.
- **Backfill:** `backfill_platform_payments_to_connect_settlement()` creates `connect_settlement` for paid platform charges (no destination account) missing ledger.
- **Reconcile:** Organizer wallet/Money load runs reconcile + migrate + backfill (1-minute lock).
- **Admin Recover / Cash Out:** Should show organizer-owed after deploy when paid `lnkup_payment` posts exist for that organizer.

### v12.18.6 (Connect $0 after Connect onboarding)
- **Treasury → Connect settlement migrate:** When an organizer becomes destination-ready, stranded wallet `available_balance` is converted to `connect_settlement` so Cash Out / admin Recover can Transfer master → Connect.
- **Settlement truth on settle:** `persist_payment_settlement_meta( …, 'settle' )` does not flip `platform_treasury` → `destination` just because Connect is ready now; destination only if `_lnkup_stripe_destination_account` was stamped at create.

### v12.18.5 (Mixed platform balance split)
- **Financial Center:** Live main Stripe Available/Pending split into organizer-owed (ledger stranded) vs LNKup fee remainder.
- **Recover stranded:** Admin Transfer platform → organizer Connect for destination ledger claims.
- **Platform payout:** Admin Standard payout capped at fee remainder only (cannot invade organizer-owed).
- **Cash Out copy:** When Available is $0, clarify main Stripe mix is not withdrawable there.

### v12.18.4 (Platform stranded funds recovery)
- **Available = Connect + recovery:** Destination cashout Available is Connect available plus ledger-capped platform recoverable (`min(ledger_claim − connect, platform_available)`). Never shows uncapped platform fees.
- **Transfer-then-Payout:** When Connect cannot cover cashout but ledger-owed platform funds can, `transfer_platform_to_connect` moves the shortfall then Connect payout proceeds.
- **Cash Out UI:** Notes when Available includes LNKup-held funds that move on cash out.

### v12.18.3 (Connect vs platform Balance read)
- **Balance::retrieve fix:** Stripe PHP `Balance::retrieve( $opts )` takes one argument; prior `retrieve( [], $opts )` ignored `stripe_account` and showed **platform** Available while Connect payouts failed (“card balance too low”).
- **Platform/Connect guard:** If Connect available cannot cover cashout but platform balance can, return `funds_on_platform` with a clear message (do not call doomed Connect payout).

### v12.18.2 (Instant available + manual schedule cashout)
- **Instant preflight:** Connect balance parse includes `instant_available` / `instant_source_types`; Instant cashout gates on Instant balance and tells organizers to use Standard when Instant is $0.
- **Force manual schedule:** On-demand cashout always sets Connect payout interval to `manual`, then re-fetches balance before `Payout::create`.
- **Richer failures:** Requested + Available + Instant + source splits + Connect account suffix.
- **Cash Out UI:** Shows Instant available when Instant is enabled.

### v12.18.1 (Opaque cashout reason fix)
- **Live WP_Error:** `process_payout` returns `true|WP_Error`; `request_cashout` surfaces the Stripe/Connect message directly (no longer depends on re-reading an empty `failure_reason`).
- **failure_reason TEXT:** `lnkup_organizer_payouts.failure_reason` widened from `varchar(255)` so long Card/Bank + “tried sources” messages persist; failure metadata stores `failure_code` / `attempted` / `source_types`.

### v12.17.2 (Connect payout source_type harden)
- **Accumulate source_types:** `retrieve_connect_balance` sums Card/Bank cents across preferred-currency available items.
- **Never omit source_type:** When breakdown is empty but total available covers the amount, cashout probes `bank_account` then `card` (Instant: card then bank).
- **Retry:** On Stripe insufficient / “too low” / `balance_insufficient`, retry the next balance bucket; failure messages include split + attempted sources; audit/error_log record attempts.

### v12.17.1 (Cashout error surfacing)
- **In-panel cashout errors:** Money → Cash Out shows REST `message` (+ code/status) above the form instead of opaque `alert('Cashout failed')`; form stays filled; DevTools gets full payload.
- **financial.js/css cache-bust:** Enqueued with `lnkup_asset_version()` (`filemtime`) so deploys invalidate stale scripts.
- **Fetch harden:** Parse JSON when body looks like JSON even if Content-Type is wrong; never empty error strings; `err.payload` attached.
- **REST audit:** `organizer_cashout` guarantees non-empty `WP_Error` message, `error_log`, and `LNKup_Financial_Audit::log( 'organizer_cashout_failed', ... )`.
- **Connect source_type:** Destination cashout picks a funded Stripe balance bucket (`bank_account` preferred for Standard, `card` for Instant) instead of omitting `source_type` (Stripe defaults to card → “card balance too low”). Cash Out shows Card/Bank split when multiple sources exist; insufficient errors include the split.

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

### Vendor Premium UI/UX Catch-up (Jul 2026)
- **Vendor home:** Decluttered command center — wallet/due strip, single upcoming hero, trust slot (profile ring lives in executive hero), compact quick actions, discovery rails without duplicate Upcoming subnav.
- **Operations:** Mission header surface, readiness ring shell, overview cards on secondary surfaces, support history via `LNKupUI` empty/error (no infinite “Loading…”).
- **Documents / Reviews:** Visible compliance hero + elevated doc cards; review score primary surface; dual-CTA empty states; review cards use surface + micro-lift.
- **Design system:** `.lnkup-vendor-section` rhythm, vendor hero title dedupe, due-strip styles in `vendor-dashboard.css` / theme `components.css`.

### Organizer UI catch-up (Next IA reference) (Jul 2026)
- **Overview `#overview`:** Next-like rhythm — hero → launch checklist → attention/priority first → payout strip → **6 focused KPIs** (live events, requests, approved, paid, payment due, open opportunities) → compact actions → apps snapshot / activity. Favorites/preferred/disputes demoted to quiet secondary chips. Trust strip deferred until hydrated (no permanent “Loading trust…”).
- **Nav IA:** Primary shell = Home (`#overview`) · Events (`#events`) · Vendors (`#applications`, also-active `#vendors`) · Money (`#financial`) · More. Discover vendors moved under More.
- **Sections:** Events / applications / vendors dual-CTA empties; event + request + vendor preview cards on primary surfaces; Approve / Waitlist / Decline hierarchy preserved.
- **Activity:** `LNKupUI.panelEmpty` / `panelError` on CRM activity body (no infinite loading).
- **Files:** `class-shortcodes.php`, `organizer-crm.js`, `verification.js`, `organizer-crm.css`, theme `inc/dashboard.php` + `components.css`.
- **Cashout fix:** Destination cashout uses Stripe Connect available (`organizer_cashout_available`) — see v12.2.2.

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
- **Cashout (destination):** `organizer_cashout_available()` gates POST `/financial/organizer-wallet/cashout` on **live Stripe Connect available** (same ceiling as Money UI). Ledger no longer forces destination `available` to $0; cashout errors return HTTP 400 with message; UI disables form when max is $0 / settling / Stripe unsynced.
- **Cashout execution:** `request_cashout` returns `payout_failed` (HTTP 400) when Stripe `Payout::create` fails — no false “Cashout requested”. `connect_payout_schedule` applied on Connect account create; default **manual** for in-app cashout; `ensure_payout_schedule()` on refresh/cashout. Instant option hidden unless `connect_instant_payouts_enabled`.
- **Cashout preflight:** Live balance + currency (settings, not hardcoded USD), external bank/debit check, Instant eligibility, optional `source_type`. Failed payouts expose `failure_reason` in REST + Money Payouts UI.
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
- Event import (Eventbrite / Posh / RSS) — **Built** for organizer Create Event + admin URL import; see [PHASE_EXTERNAL_EVENT_IMPORT.md](PHASE_EXTERNAL_EVENT_IMPORT.md)
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

W-9 / 1099 **summary PDFs** are built (`GET /financial/tax-center/w9/pdf` and `/1099/pdf` via Dompdf; not official IRS forms). Placeholder-only: email receipts, fraud ML. Apple/Google Pay are **operator flags** (Dashboard domains). ACH is **built** on PI + Checkout Session (processing ≠ unlock; Session finalizes only when `payment_status=paid`) — see [PHASE_26_FINANCIAL_OS_EXPANSION.md](PHASE_26_FINANCIAL_OS_EXPANSION.md) / [FINANCIAL.md](../wp-content/plugins/lnkup-marketplace/FINANCIAL.md).

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

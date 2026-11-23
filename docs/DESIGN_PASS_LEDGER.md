# Design pass ledger (Opus, on the redesign baseline)

Branch `design/opus-pass` in the worktree `lnkup-design`, cut from `phase/7-handoff` (f06ecf7). It already contains the redesign commit `f85e5cf` ([PR #6](https://github.com/sixstarsent1-png/LNKUP-Marketplace-Vercel/pull/6)) and the buildout stack (#9, #11–#17). This pass owns presentation, navigation, responsive behavior, accessibility and interaction clarity. It does not change booking, money, inventory, eligibility, permission or API behavior; problems there are listed under Handoffs.

## Environment

| Piece | Where |
|---|---|
| WordPress | `http://localhost:8896` (tests 8897), set by the gitignored `.wp-env.override.json` |
| Data | `seed.php`, `seed-portfolio.php`, `seed-organizer-os.php`, `seed-onboarding.php`, plus `seed-design.php` |
| Next.js | `http://localhost:3006`, live API against 8896 |
| Expo web | `http://localhost:8082` |

`seed-design.php` adds:
- A Six Stars house organizer and an independent organizer.
- Events with a portrait flyer and a long title (Atlanta), a landscape photo (Charlotte), no art and no fee (Raleigh), a sold-out booth (Durham), a free booth, and one unclaimed external listing (Greensboro).

The flyers are synthetic, drawn with GD, and stay in local uploads.

## Redesign baseline and missing work

- **The redesign under review** is the single commit `f85e5cf` on `redesign/event-product-land`.
- **The original working checkout** (`redesign/event-product`, based on `582e8b3`) still differs from `f85e5cf` in 24 UI files. They hold:
  - Feature work: onboarding v2, the review workspace, organizer start, partner screens and match rails. Phase 0 already carried it into `integrate/os-workstreams`.
  - Older copies of files that master later updated (`vendor-bookings.css` and `vendor-bookings.js`, `organizer-crm.js`).
- No redesign UI change exists only in that checkout.

## Files shared with the buildout

62 UI files changed in the buildout stack after `f85e5cf`. These are the ones this pass is most likely to touch:

- **WordPress:**
  - `includes/helpers.php` (card renderer)
  - `public/class-shortcodes.php` (Explore feed, event detail, event workspace)
  - `public/class-organizer-dashboard-views.php`
  - `public/js/marketplace-v2.js`
  - `public/js/organizer-review.js`
  - `includes/portfolio/class-portfolio-public.php`
- **Next.js:**
  - `components/marketplace/opportunity-card.tsx`
  - `components/marketplace/apply-modal.tsx`
  - `app/vendor/wallet/page.tsx`
  - `app/vendor/requests/page.tsx`
- **Expo:**
  - `app/apply/[opportunityId].tsx`
  - `app/(organizer)/events/[eventId].tsx`
  - `app/(partner)/earnings.tsx`

Rule for this pass: on those files, put the visual change in a new partial, stylesheet or component where practical, and note each edit below.

| Shared file | Edit in this pass | Why it could not be isolated |
|---|---|---|
| `includes/helpers.php` | `lnkup_render_homepage_event_card()` now calls `lnkup_render_event_card_from_summary( lnkup_event_card_summary() )` (216 lines replaced by 1). `lnkup_sanitize_card_image_url()` checks same-site URLs before the Eventbrite rules. | Every public card goes through this function. The card logic moved, unchanged in meaning, to `includes/presentation/event-card-summary.php`. The image check fixes same-site uploads being upgraded to https, which broke them on http staging and local sites. |
| `lnkup-marketplace.php` | One line: load `includes/presentation/event-card-summary.php` after `helpers.php`. | Bootstrap file list. |
| `public/class-shortcodes.php` (`render_public_event`) | The hero art uses `lnkup_event_art_html()`. The hero facts include "Apply by …". The phone bar text is computed per event (one open booth: that booth's Apply; several: "From $X · N booth types" with "Choose a spot"). | The markup lives only in this function. No change to which spots can be applied to. |
| `public/js/marketplace-v2.js` | `selectSlot( card, initial )`: the automatic first selection no longer overwrites the bar. | The bar text was set by this function on load. |
| `public/class-organizer-dashboard-views.php` (`render_home_welcome`) | "Next up" uses the display title without a trailing date, and a short date. | One line. |
| `includes/portfolio/class-portfolio-public.php` (`render`) | Stop cards show status, spots left and a link to the stop's event page. They use `lnkup_public_money()` and `lnkup_application_status_label()`. The logged-out "Log in" is a text link. | Stop markup lives only here. Uses the existing `data()` fields. |
| Next.js `components/marketplace/opportunity-card.tsx` | The media area renders `<EventArt>` (new component) instead of `next/image`; the badges over it get a light background. | The card owns its media block. Apply, save and status logic are untouched. |
| Next.js `app/vendor/wallet/page.tsx` | The hero metrics and `heroMoney()` are removed; "1 booking" singular. | The duplicate figures were in this page. Amounts still come from `MoneyStatCard`. |

## Baseline review

Screenshots are in `docs/design-pass/before/` (phone 390 px and desktop 1440 px; `-top` is the first screen of a full-page capture), taken with `node scripts/design/capture.mjs before`. References are in `docs/design-pass/refs/`.

Tags: **[seen]** observed in the browser · **[code]** found only in code · **[n/a]** could not be reached.

### References
- **Eventbrite** [seen]:
  - Search and location sit together at the top.
  - Feed cards lead with the event's own flyer and put short urgency badges ("Almost full") on the art.
  - City pages open with the city name in very large type.
  - The app prompt on phone is a slim bottom bar, not a sheet over the content.
- **Posh** [n/a]: both pages stopped at a Cloudflare bot check in automated browsers.
- **thelnkup.com** [seen]: matches the local homepage, including the App Store sheet that covers half the phone hero.

### Homepage
- **Works** [seen]:
  - "Find your next vendor spot." with a single city search.
  - Real photography.
  - Fee wording on cards (Free, From $X, Fee unavailable).
  - App section only once in the page body.
- **Friction:**
  - [seen] On phone, an App Store sheet covers the hero and search on first load. On other public pages a top app banner appears as well.
  - [seen] The desktop header links sit on different baselines: "List an event" is about 8 px lower than Events and Organizers.
  - [seen] The first screen shows no real event. The right half of the desktop hero is dark photo, and listings start below the fold.
  - [seen] Featured cards without art are a flat teal block with no text.
  - [seen] The search placeholder is "Houston", a city with no data locally, so it suggests a dead end.
- **Change:**
  - Turn the first screen into a split hero: the headline and search on one side, a stack of real listings with their art on the other.
  - Replace the app sheet with one slim, dismissible bar, and never show it on the first screen of the homepage.
  - Align the header links.
  - Use a distinct LNKup fallback for cards without art.
  - Use the first city that actually has listings as the search hint.

### Explore (`/opportunities/`)
- **Works** [seen]:
  - Search and city are up front.
  - The "Has spots" chip.
  - External listings are off by default.
- **Friction:**
  - [seen] "Save this search" and "Clear all" are white text on a near-white surface (contrast failure).
  - [seen] Huge lime-gradient "LNKup" placeholders, each about 300 px square, push the event name below the fold on phone.
  - [seen] Three calls to action compete in the header: the app banner, "Get the app" and "Sign up free".
  - [seen] On phone, the filters form is about 450 px tall before any result.
  - [seen] A lime floating menu button overlaps the cards.
  - [seen] About 80 px of empty space sits above the page title.
- **Change:**
  - Compact search bar.
  - Quick chips beside the results.
  - Advanced filters in a drawer.
  - Readable chip and link colors.
  - The shared card with a compact fallback.
  - Remove the duplicate app call to action.

### Event detail
- **Works** [seen]:
  - Fee, spots and deadline are in the side panel.
  - "Applying is free" is explained.
  - A sticky Apply bar on phone.
- **Friction:**
  - [seen] The "← Find events" back link is invisible (white on light).
  - [seen] The portrait flyer is center-cropped to a wide frame, which cuts off the flyer headline.
  - [seen] The title is set in regular body weight, so the organizer name looks more important than the event.
  - [seen] The phone sticky bar says "Food · Vendor Fee: $350" while the page says "From $150", and both "Explore Vendor Spots" and "Apply" compete as the main action.
  - [seen] The desktop side panel repeats the hero facts.
- **Change:**
  - Show the flyer uncropped (contained, with a blurred backdrop).
  - Display-weight title.
  - One scan block: organizer, date, place, fee range, deadline.
  - A sticky bar that matches the page ("From $150 · 3 booth types · Choose a spot") with one action.

### Tour page (`/tours/{slug}/`)
- **Works** [seen]: a clear series hero ("Multi-city tour", dates count) with "Apply to several dates".
- **Friction:**
  - [seen] The app banner and the App Store sheet both appear.
  - [seen] Logged out, "Log in as a vendor to apply" is a grey button next to the lime one.
- **Change:** stop cards with city, date, status, fee and availability each, and one main action for logged-out visitors.

### Vendor Home and Bookings
- **Works** [seen]:
  - Needs-attention rows with direct "Complete payment" actions.
  - Bookings tabs with counts.
- **Friction:**
  - [seen] Two first-run overlays open at once: "Welcome to LNKup" and the platform tour. The welcome modal opens even for a vendor who already has bookings.
  - [seen] The sidebar has both "Bookings" (with a badge, the requests view) and "My Bookings". The phone bottom bar shows "Bookings" twice.
  - [seen] On a booking row, "Pay $99.07" and "Details" stretch across the whole row.
  - [seen] Each booking shows two chips for one state ("Payment Required" and "Payment due").
- **Change:**
  - Only one first-run overlay at a time.
  - Name the requests view "Requests" in the nav. Routes stay the same.
  - Compact actions on booking rows, and one status chip.

### Organizer Home and the review queue
- **Works** [seen]:
  - Needs attention comes first.
  - Readiness bars on upcoming events.
  - Create event stays visible.
  - The review queue has status tabs with counts.
- **Friction:**
  - [seen] The top bar has "Create event" and the page header has "Launch event": two names for one action.
  - [seen] Readiness cards repeat the event name and date ("E2E Weekly Makers Market — Dec 19, 2026"), and the text wraps awkwardly at 4 columns.
  - [seen] In the queue, "Board view" and "Classic view" are plain text links and don't read as view switches.
- **Change:**
  - One "Create event" label.
  - Card titles without the repeated date.
  - The view switch as a segmented control.

### Expo (web preview)
- **Works** [seen]:
  - Explore has search, quick category chips, a list/map switch and honest fee and "1 left" chips.
  - Organizer Events has status tabs and per-event counts.
- **Friction:**
  - [seen] Sign-in shows an old square logo, Terms and Privacy twice, and a debug line ("Live API: on") to users.
  - [seen] Cards without art use a tall lime block with a category icon.
- **Change:** a sign-in cleanup, plus the same compact fallback and status chips as the web.

### Not reached
- [n/a] Real devices: haptics, native sheets, push taps, VoiceOver.
- [n/a] The Next.js app was not captured for the baseline. It is covered in step 5.

## Step 2: homepage and public navigation

After views are in `docs/design-pass/after/` (`home-*-top`, the `home-*-sN` sections, `signup`, `login`, `city`, `organizers`, `storefront`).

- **First screen:**
  - A live pill: "Booking vendors now in Charlotte, Atlanta +4", built from the cities that have open listings.
  - The headline.
  - One search: the city field with a hint taken from real data, plus "Find spots".
  - Up to four city links with counts, which carry the filter into Explore.
  - A stack of up to three real flyers from events accepting vendors: fanned on desktop, a swipe strip on phone. The flyers are shown whole over a blurred copy, so their text is never cropped.
  - With no flyers available, the photo carries the hero.
- **App promotion has one place per page:**
  - On the homepage: the app section only. The final call to action and the footer no longer repeat the badge there.
  - On other pages: a quiet "Get the app" in the header, and a slim, non-blocking bottom bar on iPhone from the second page of a visit. It replaces the first-visit sheet with a backdrop.
  - The top app banner is gone. Safari's own Smart App Banner is unchanged.
- **Section order:**
  1. Open opportunities (whole rows only).
  2. Browse by city (tiles) and by what you sell.
  3. Tours and series (only when a published tour has an open stop; each stop shows its date, city and open or closed).
  4. How it works.
  5. A new organizer band (list spots, review vendors, get paid and run the day).
  6. Trust (live totals that are still zero are hidden instead of showing "$0.00").
  7. The app.
  8. The closing call to action (no duplicate "Find Events" button).
- **Shared card** (`lnkup_render_event_card_from_summary`):
  - Scan order: event, date and city, fit (categories and organizer), fee chip (Free, a single price, "From $X" for a range, "Fee unavailable", "Fee set by the organizer"), availability ("Only 3 spots left", "24 spots open · 2 booth types"), then the action.
  - The whole card is clickable, and each button's accessible name includes the event.
  - Sold-out and closed events dim their art and say so once.
  - Events without art get an LNKup poster with their initials.
- **Header and footer:**
  - Nav links share one baseline.
  - Ghost buttons on light pages are now ink (they were white on white site-wide).
  - Lime buttons carry dark text.
  - The footer blurb no longer claims "thousands of vendors".
- **Sign up:** a lime check list, and a clear selected state for the role choice.
- **Motion and focus:** a lime focus ring with an ink halo, press scale, card lift and the live-dot pulse, all switched off under reduced motion.
- **Verification:**
  - Phone and desktop captures of the homepage, sign up, log in, the Charlotte city page, organizers and a storefront.
  - Playwright `landing-launch`, `public-pages`, `market-pages`: 19 passed, 10 skipped (Chromium).

## Step 3: Explore, event detail and tours

- **Explore:**
  - A compact search bar: search, city, Search and Filters on desktop; search, then city plus Filters on phone.
  - Date and sort sit as pills in one scrollable row.
  - Quick chips scroll in one row.
  - Active filters are lime chips, next to an ink "Clear all".
  - "Save this search" is a readable text link.
  - The floating menu button (a duplicate of Filters) is gone.
- **Filter drawer** (`public/js/explore-ux.js`):
  - It works as a sheet: dialog role and label, a close button, Escape, backdrop click, focus kept inside, focus back on Filters.
  - Side sheet on desktop, bottom sheet on phone, with the Apply and Clear actions kept in view.
  - The Filters button shows the active count.
- **Return position:**
  - Filters already lived in the URL; the page and scroll position now come back too.
  - Checked with `scripts/design/return-check.mjs`: Explore filtered to Charlotte, then an event, then back. Same URL, `y` 1467 before and after.
- **Empty results:** a bordered panel with "Clear all filters" and, when a city caused it, "Search all cities". Suggestions follow.
- **External listings:** "External listing" badge, "Fee set by the organizer", "Listed from the organizer's site", and only "View event". External listings stay off unless the vendor turns them on (unchanged).
- **Canonical categories and bookable first:** the cards read `lnkup_canonical_category_label()`. Feed order is unchanged.
- **Event detail:**
  - The flyer is shown whole: contain over blur, 4:3 on phone and 4:5 on desktop.
  - A display-weight title.
  - The state pill.
  - Facts as chips, including "Apply by".
  - On desktop the sticky summary card is the one primary action (the hero's duplicate is hidden).
  - Spot cards have a clear selected ring.
  - The phone bar matches the page ("From $150 · 3 booth types" → "Choose a spot"), clears the home indicator, and the app bar never stacks on it.
  - The page starts right under the header (about 100 px of empty space removed).
- **Tour page:**
  - Each stop shows "Accepting vendors" or "Not accepting", its city linking to the stop's event page, each booth's fee and spots left, the deadline, and Apply or the vendor's status.
  - Without a description, the stops fill the width as a grid.
- **Checks:**
  - Captures: `explore`, `explore-city`, `explore-empty`, `explore-sheet`, `event-flyer`, `event-no-art`, `tour` at 390 and 1440 px.
  - Playwright (Chromium): `marketplace-v2` 12 passed, 2 skipped, after two spec updates (the floating menu button is replaced by Filters; Apply links are now named "Apply to {event}").
  - `apply-with-profile`, `apply-draft-resume`, `portfolio-os`, `tour-multi-apply`, `matching`, `vendor-journey`: passed.
  - `external-events` (6) and `saved-searches:83` fail on the `phase/1-release-checks` baseline too (pre-existing).

## Step 4: dashboards

- **Vendor navigation:**
  - Sidebar: Home, Explore, Requests (applications in review, with the badge), Bookings (approved, paid and past), Wallet, Profile.
  - Phone bottom bar: Home, Explore, Bookings, Wallet, Profile. It showed "Bookings" twice before.
  - The page titles follow ("Requests", "Bookings"). "My Bookings" is gone. Routes are unchanged.
- **Vendor Home:**
  - Vendors with applications see the next action and needs-attention rows first, then the next-event strip (readiness and amount due, the pass when it is ready), then stats, then Getting started.
  - New vendors still see setup first.
  - Only one first-run overlay at a time: the platform tour no longer opens on top of the welcome modal.
- **Bookings:**
  - Row actions are sized to their label.
  - One status chip per fact ("Payment required" no longer sits next to "Payment due").
  - The filters are a compact grid (two columns on phone).
- **Wallet:**
  - A missing amount reads "Unavailable" instead of `$0.00`.
  - No installments reads "None scheduled".
  - A failed installment lookup reads "Unavailable".
  - A confirmed `$0.00` still shows as money.
- **Organizer:**
  - One "Create event", in the shell top bar. The welcome header copy is hidden, and the `launch_event` copy now reads "Create event".
  - "Next up" no longer repeats the date ("E2E Weekly Makers Market · Sat, Dec 19").
  - Needs-attention rows are left aligned with an "Open" action.
  - The Applications page title is "Applications".
- **Event workspace applicants:**
  - One row per applicant: select box, vendor and their facts, fee and status on the right, and the decision buttons together under a divider. Accept is last on desktop and first, full width, on phone.
  - "View details" opens the existing applicant drawer.
- **Money:** payout messages no longer name the processor ("Direct payouts are not set up on this marketplace yet.", "Balances updated …", "Refresh"). The API messages are unchanged; the dashboard maps them.
- **Checks:**
  - Captures: `vendor-home`, `vendor-bookings`, `vendor-booking-detail`, `vendor-wallet`, `organizer-home`, `organizer-workspace`, `organizer-applicant`, `organizer-money`.
  - Playwright (Chromium), all 31 `vendor-*` and `organizer-*` specs: 117 passed, 23 failed, 56 skipped, 2 did not run.
    - 21 of the failures also fail on `phase/1-release-checks`.
    - `vendor-journey:123` is a login reauth flake; it passes on rerun.
    - `vendor-operations:112` fails with the `phase/7-handoff` version of the view file too. The cause is the data (the first selectable event is unpaid, so only the pass tab renders), not this pass.
  - `vendor-bookings` follows the "Bookings" back link.

## Step 5: Expo, Next.js and secondary screens

- **Event art, all three apps:**
  - An event without a usable image shows a black poster with the event's initials in lime and a small "LNKUP" mark. This matches the WordPress cards and replaces the flat lime block.
  - A flyer is shown whole over a blurred copy of itself instead of being cropped.
  - Expo: `components/ui/event-image.tsx` takes `title` and `fit`. Explore cards, organizer event cards and the event detail hero use it.
  - Next.js: new `components/marketplace/event-art.tsx`, used by the opportunity card.
- **Expo cards:** one card is one booth, so the fee is that booth's exact price ("$120"), not "From $120".
- **Expo sign in and sign up:** Terms and Privacy Policy appear once, as one sentence with a single link row.
- **Next.js:**
  - Opportunity card badges over the artwork have a light background. The category badge rendered dark-on-dark and looked empty.
  - Wallet: the hero no longer repeats the two money cards with rounded amounts ("$229" over "$229.01"). "1 bookings" reads "1 booking".
- **WordPress secondary screens** (documents, help, Passport, notification settings, referrals):
  - Phone dashboard header is one row: menu, logo, search, account. The workspace name appeared twice, and "More" opened the same sidebar as the menu button.
  - Empty states without an icon no longer show a blank dashed square.
  - Documents empty state: the title is "No documents yet"; the explanation stays in the description.
  - Notification settings on phone: each dropdown is labelled "Email" or "Push" (the column headers are hidden at that width).
  - Referrals inside the dashboard: the payout explanation appears once, in the card that has the actual percentage.
- **Checks:**
  - Expo: `tsc` passes; jest 24 suites, 200 tests pass.
  - Next.js: `tsc` reports the same 18 errors as `phase/7-handoff`. The three in `opportunity-card.tsx` are the existing analytics `eventId` typing. `eslint` cannot run: the app has no `eslint.config.*`.
  - Captures: `expo-explore`, `expo-detail`, `expo-login`, `next-find`, `next-wallet`, `vendor-documents`, `vendor-help`, `vendor-passport`, `vendor-notifications`, `vendor-referrals`.
  - Expo web only reloads code when Metro runs without `CI=1`.

## Handoffs

| Screen | Expected | Contract | Found |
|---|---|---|---|
| Homepage and Explore cards | A booth with no fee set reads "Fee unavailable". | `LNKup_Marketplace_Index::sync_opportunity()` stores `vendor_fee` as `(float) get_post_meta( …, '_lnkup_vendor_fee' )`. | A missing fee is indexed as `0.00`, so the card says "Free" (seen with the Raleigh Sunday Flea fixture). The card cannot tell "free" from "not set" without a nullable fee in the index. |

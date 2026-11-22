# LNKup redesign ledger

Branch: `redesign/event-product` (from `master` at `582e8b3`). Unrelated working-tree files (communications, zips, organizer-growth, test-results) stay unstaged and were not reverted.

WordPress on thelnkup.com is the public site and the live dashboards. Next.js (`lnk-up-marketplace-ui`) and Expo (`lnk-up-marketplace-mobile`) are clients. Root `app/`, root `components/`, and `lnkup-app-reference/` are not production.

## Baseline

| Screen | Owner | Main task | Friction | Change | Check |
| --- | --- | --- | --- | --- | --- |
| Homepage `/` | Theme `front-page.php` + landing partials | Find a vendor spot | App banner, header app button, search, second CTA, app link, and city chips in one viewport. Category select mixed event-specific terms. Featured art was empty. Closing copy still said prelaunch while the marketplace is open. | One city search, opportunities next, canonical category links, app section once, stale prelaunch body replaced only when the marketplace is open. | Observed on thelnkup.com. Implemented in theme. Local browser check recorded below. |
| Explore `/opportunities/` | Plugin shortcode + `marketplace-v2.js` | Scan bookable spots | Tall filter bar. External listings included unless turned off. | External listings off unless the checkbox is on. Quick search, city, date, and sort stay up front. | Confirmed in code. Live `/opportunities/` did not load in the audit browser. |
| Event detail | `render_public_event()` | Apply | Apply bar already fixed to the bottom with a safe-area inset. | Kept. Fee and card language follow the shared card. | Confirmed in code. |
| Vendor dashboard | Theme shell + vendor views | See what needs attention | Nav said Find events and Requests. | Labels are Home, Explore, Bookings, Wallet, Profile. URLs unchanged. | Code. |
| Organizer dashboard | Theme shell + organizer views | Decide applications and money | Home was Overview. Requests was a primary tab. | Home, Events, Vendors, Money, More. Applications stays at `?view=applications`. Create event stays in the shell. | Code. |
| Event workspace `?event=` | `class-shortcodes.php` | Review the queue | Status strip said Requests / Approved / Awaiting / Paid. Tabs said Opportunities and Financials. | Needs review, owes a fee, confirmed. Tabs: Slots, Applications, Confirmed vendors, Money. Operations and check-in links kept. | Code. |
| Next.js shell | `app-shell.tsx` | Know who is signed in | Sidebar always showed Sweet Heat BBQ or CultureWave Events. | Real session name when live API is on. Demo names only when live API is off. | Code. |
| Expo Explore | `app/(vendor)/find.tsx` | Browse spots | Fee `0` was shown as free when the server omitted a fee. | Missing fee is "Fee unavailable". Sample data is labeled when the live API is off. External listings are not requested. | Code. |
| Wallet / Money | Financial OS, Next money cards | Read collected vs available | A missing number rendered as an em dash that looked like a value. | Loading, Unavailable, and a confirmed `$0` are different. | Code. |

## Shared system

Sora for display, Inter for UI, on WordPress, Next.js, and Expo. Lime is the primary action. Success, warning, and danger stay separate. Card radius is 20px. Control radius is 12px. Missing event art is an LNKup placeholder, not a repeated category photo.

## Verification

Checked in the browser on the local WordPress site at `http://localhost:8892` (the running environment; port 8888 was not up):

- Homepage, about 390px wide: headline “Find your next vendor spot.”, one Search action, Events / Organizers / List an event in the header, no app banner in the first viewport. City search for Charlotte opened `/opportunities/?city=Charlotte`. Featured cards showed Fee unavailable, From $80, and Free. Category links and the iPhone section sit lower on the page.
- Explore: heading Explore, external-listings checkbox unchecked, no external cards in the first results, and the “LNKup Opportunities Only” control hidden.
- Organizer home: Home, Events, Vendors, Money, and a persistent Create event action. Applications sits in More.
- Event workspace for E2E Waitlist Fair: event name, date, city, event switcher, Needs review / Owes a fee / Confirmed, and tabs Overview, Slots, Applications, Confirmed vendors, Money, plus Operations and Check-in.

Theme CSS was rebuilt (`assets/dist/lnkup.css`). Headings use Sora at weight 700. Next.js and Expo typechecks still report older errors outside this pass (partner routes, request mappers). The fee and map-pin types introduced here typecheck. Playwright landing and Explore heading specs were updated and the focused rerun passed. A full parallel run against this environment timed out under load.

Not verified: Expo on a device or preview, push taps, VoiceOver, universal links, a production App Store build, and a live charge. No production deploy.

# Mobile deep linking

Native app: `lnk-up-marketplace-mobile` (Expo Router). Auth stays Application Password / token in SecureStore against WordPress REST; nothing here uses the web cookie session.

## Universal links / App Links

| Path | AASA (iOS) | Android `intentFilters` | App route |
|---|---|---|---|
| `/vendors?vendor=`, `/vendors/{slug}` | always / `deep_links_v2` | yes | `/storefront/vendor?vendor=` |
| `/organizers?organizer=`, `/organizers/{slug}` | always / `deep_links_v2` | yes | `/storefront/organizer?organizer=` |
| `/opportunities?apply={id}` | `deep_links_v2` | yes | `/opportunity/{id}` (else Find) |
| `/events/{slug}` | `deep_links_v2` | yes (new) | `/link` → `/event/{id}` |
| `/markets/{city}[/{category}]` | `deep_links_v2` | yes (new) | `/link` → Find with city/state/category |
| `/tours/{slug}`, `/series/{slug}` | `deep_links_v2` | yes (new) | `/link` → `/tour/{id}` |
| `/l/{code}` | `deep_links_v2` | yes (new) | `/link` → the link's target |

The extra AASA paths are served only while `deep_links_v2` is on. **Turn it on only after the app build containing the new `+native-intent` is live in the App Store**, otherwise older builds would open those links and show "not found". Android intent filters ship with the app build itself; Android verification needs the signing SHA-256 fingerprint (Command Center → Integrations, served at `/.well-known/assetlinks.json`).

## Flow

1. `app/+native-intent.ts` → `redirectSystemPath()` saves any campaign/referral parameters (`utm_*`, `gclid`, `ttclid`, `fbclid`, `ref`, `org_ref`, `cc`, `partner`, `lnk`) to SecureStore (30 days) and maps the path with `lib/navigation/deep-link-map.ts`.
2. Slug and link paths open `app/link.tsx`, which calls `GET lnkup/v1/public/resolve?path=`. WordPress resolves slugs (draft, password-protected and authenticity-held events fall back to the feed), counts `/l/` clicks exactly like a web click and returns the link's campaign touch, which the app stores.
3. `hrefForResolved()` picks the screen: event, opportunity, tour, organizer storefront, signup (signed out only), or Find with market filters (organizers go home).
4. `app/event/[eventId].tsx` reads `GET lnkup/v1/public/events/{id}` — the same guest-safe data as the public event page (no fees, no inventory, no hidden venue). Signed-out visitors tapping "Sign up to apply" keep the destination (`/opportunity/{id}`) through sign-up via the existing pending-destination store.
5. `app/signup.tsx` sends the stored touch plus `ref` / `org_ref` / `cc` route parameters as `attribution` (`device: app`) and clears it after a successful signup.

## Tracked native share

`POST lnkup/v1/app/share-link` returns a reusable tracked link (channel `app`, `utm_source=app_share`) when the user may promote the target (event owner/team, booked vendor, connector), otherwise the plain public URL. Used by the public event screen and the organizer event workspace share button.

## App Store campaign tokens

`LNKup_App_Promo::store_url( $context )` adds `ct=lnkup-{context}`; when a provider token is saved (Command Center → Integrations) it also adds `pt` and `mt` so App Store Connect attributes downloads to campaigns. ASO copy guidance is out of scope for code.

## Analytics

Expo allowlist: `deep_opportunity_opened` (link resolved; metadata `route`, `tracked_link`), `share_link_copied`, `apply_clicked`.

## Verification status

| Check | Status |
|---|---|
| Path mapping, resolved routes, attribution capture, payload mapping (Jest `__tests__/deep-links.test.ts`) | Run, passing |
| Resolver, public event payload privacy, share links, AASA paths, store tokens (PHP `app-links-test.php`, 49 checks) | Run, passing |
| TypeScript (`tsc --noEmit`) for the Phase 8 files | Run, passing at commit `6d68155` |
| Opening links on a physical iPhone / Android device | **Not run** — needs a store or TestFlight build with this code and `deep_links_v2` on in production |
| Expo web rendering of `/event/{id}` | Not run in this session |

Known limitation: unclaimed external events (shown on the web with a disclosure) are not opened in the app; the app falls back to Find.

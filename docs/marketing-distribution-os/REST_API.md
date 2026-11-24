# REST API changes (`lnkup/v1`)

Every route has a `permission_callback`. Public routes return only fields already shown to signed-out visitors on the website, are rate-limited per IP and cached briefly. No existing route changed its response shape.

## Public (no sign-in)

| Method | Route | Flag | Notes |
|---|---|---|---|
| POST | `/markets/{slug}/alert` | `market_category_pages` | **Signed in only.** Creates a per-event market alert. Signed-out visitors are sent to sign-up with `?alert=1&market=` and the alert is created after registration |
| POST | `/markets/{slug}/demand` | `market_category_pages` | Anonymous category demand tap (20/hour/IP, no user ID stored) |
| GET | `/public/embeds/events/{id}/opportunities` | `organizer_marketing` | Event's open spaces for organizer websites; no fees or inventory; 300/hour/IP; 5-minute cache |
| GET | `/public/embeds/tours/{id}/stops` | `organizer_marketing` | Upcoming tour stops |
| GET | `/public/resolve?path=` | `deep_links_v2` | Maps a web path or `/l/{code}` to an app route; counts the link click; returns the campaign touch. 120/hour/IP; `no-store` |
| GET | `/public/events/{id}` | `deep_links_v2` | Guest-safe event payload for the app (title, dates, state, description, public venue, image, organizer name/slug, open spaces with status labels) |

## Signed in

| Method | Route | Who | Notes |
|---|---|---|---|
| GET | `/organizer/marketing/context` | Event owner / team with `broadcast` | Events, channels, templates |
| GET, POST | `/organizer/marketing/links` | same | List / create tracked links for own events |
| POST | `/organizer/marketing/links/{id}` | Link owner | Pause / resume / archive |
| GET | `/organizer/marketing/performance` | same | Clicks, signups, applications, bookings per link + recommendations |
| GET | `/connector/marketing/context` | City Connectors / partners | Own markets and toolkit |
| GET, POST | `/connector/marketing/links` | same | Links forward the connector's existing referral code |
| POST | `/connector/marketing/links/{id}` | Link owner | Status |
| POST | `/vendor/acceptance-link` | Vendor with a paid booking | Reusable tracked link to the event (opt-in share card) |
| POST | `/app/share-link` | Any signed-in user | Native share: reusable tracked link when the user may promote the target, otherwise the plain public URL. Rejects non-public types |
| GET | `/marketing/seo/diagnostics` | `lnkup_manage_marketing` | Index policy diagnostics |
| GET, POST | `/comms/marketing/segments`, `/segments/preview`, `/segments/{id}` | `lnkup_manage_marketing` | Saved segments; preview returns counts only |
| GET, POST | `/comms/marketing/campaigns`, `/campaigns/{id}` | `lnkup_manage_marketing` | Campaign drafts |
| POST | `/comms/marketing/campaigns/{id}/{submit|approve|reject|pause|resume|cancel|archive|test|preview}` | `lnkup_manage_marketing` (approver ≠ submitter) | Approval workflow |

## Changed (validation only)

- `POST /marketplace/favorites` accepts type `tours` and validates that the target is public before saving.
- `POST /auth/register` accepts the extended `attribution` payload (`utm_id`, `gclid`, `ttclid`, `ref`, `org_ref`, `cc`, `partner`, `lnk`, `market`, `landing`, `device`); unknown keys are dropped.

## Non-REST endpoints

| Path | Handler |
|---|---|
| `/l/{code}/` | `LNKup_Tracking_Links::handle()` — 302 to the internally resolved target, `X-Robots-Tag: noindex`, never off-site |
| `/markets/{city}/{category}/`, `/markets/{city}/this-week/` | `LNKup_Market_Discovery` |
| `/with/{slug}/` | `LNKup_Partner_Landings` (noindex) |
| `/resources/`, `/guides/{slug}/` | `LNKup_Resources_Hub` |
| `/cohort/{slug}/` | `LNKup_Cohort_Landings` (noindex); interest form posts to `admin-post.php?action=lnkup_cohort_interest` |
| `/.well-known/apple-app-site-association`, `/.well-known/assetlinks.json` | `LNKup_App_Links` |
| `admin-post.php?action=lnkup_marketing_admin` / `lnkup_marketing_export` | Command Center actions and CSV exports (nonce + capability) |

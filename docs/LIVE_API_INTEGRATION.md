# Live API Integration — Next.js App → WordPress

Connects [`lnk-up-marketplace-ui/`](../lnk-up-marketplace-ui/) to production WordPress at **https://thelnkup.com** via a **BFF proxy** (Next.js Route Handlers). The browser never calls WordPress directly.

**Production app:** https://lnkup-marketplace-ui.vercel.app  
**WordPress API base:** `https://thelnkup.com` (set as `WORDPRESS_API_URL` on Vercel)

## No separate connector plugin

The existing `lnkup-marketplace` plugin already exposes `lnkup/v1` REST routes. The Next.js app proxies them server-side — no new WordPress plugin is required.

## Architecture

```
Browser → /api/marketplace/search → https://thelnkup.com/wp-json/lnkup/v1/marketplace/search
Browser → /api/auth/login         → validates via /wp-json/wp/v2/users/me
Browser → /api/applications/apply → POST apply-with-profile (authenticated)
```

## Environment variables

| Variable | Scope | Production value |
|----------|-------|------------------|
| `WORDPRESS_API_URL` | Server | `https://thelnkup.com` |
| `SESSION_SECRET` | Server | 32+ char random string |
| `NEXT_PUBLIC_LNKUP_LIVE_API` | Client | `true` (live mode enabled) |
| `NEXT_PUBLIC_WORDPRESS_SITE_URL` | Client | `https://thelnkup.com` |

Copy [`.env.example`](../lnk-up-marketplace-ui/.env.example) to `lnk-up-marketplace-ui/.env.local`, or run `npx vercel env pull` from that directory.

## WordPress prerequisites

1. Permalinks enabled
2. `GET https://thelnkup.com/wp-json/lnkup/v1/marketplace/search?page=1` returns JSON with `items` (via BFF; direct browser curl may return 403 depending on hosting/WAF)
3. Marketplace index has opportunities
4. Vendor user with profile + **Application Password** (Users → Profile → Application Passwords)

Verify auth:

```bash
curl -u "vendor_user:xxxx xxxx xxxx xxxx xxxx xxxx" \
  "https://thelnkup.com/wp-json/wp/v2/users/me"
```

## Prelaunch vs public browse (important)

WordPress treats **search** and **apply** differently when the marketplace is not yet publicly open:

| Endpoint | `lnkup_can_browse_marketplace()` check | Current behavior |
|----------|----------------------------------------|------------------|
| `GET /marketplace/search` | No | Returns opportunities (BFF verified: 18 items) |
| `GET /applications/apply-context/{id}` | Yes | Returns `403 marketplace_closed` until launch |
| `POST /applications/apply-with-profile` | Yes | Same until launch |

When prelaunch-blocked, apply context returns a message like *"Applications open on August 1, 2026."* The app shows this in the Apply modal. After marketplace launch (or admin prelaunch preview for admins), vendors can apply normally.

To open applications before launch: enable marketplace launch in WP admin, or use admin prelaunch preview (admins only).

## Vendor login flow

1. Open `/login` in the app (or **Sign in** in vendor header when live API is on)
2. Enter WordPress username + application password
3. Session stored in encrypted httpOnly cookie
4. Apply modal uses session for context + submit

Registration link: `https://thelnkup.com/sign-up/?role=vendor` (or `?role=organizer`)

## Organizer signup / join

Organizer Join CTAs open WordPress signup with `?role=organizer`. After account creation, organizers land on the organizer dashboard (Getting Started welcome + launch checklist).

## API routes (Next.js)

| Route | WordPress target |
|-------|------------------|
| `GET /api/marketplace/search` | `GET /lnkup/v1/marketplace/search` |
| `GET /api/applications/apply-context/[id]` | `GET /lnkup/v1/applications/apply-context/{id}` |
| `POST /api/applications/apply` | `POST /lnkup/v1/applications/apply-with-profile` |
| `GET /api/vendor/requests` | `GET /lnkup/v1/applications/vendor` (auth required) |
| `POST /api/auth/login` | Validates `GET /wp/v2/users/me` |
| `GET /api/auth/me` | Session check |
| `POST /api/auth/logout` | Clears session |

## Verified (go-live)

- Production BFF search: `GET https://lnkup-marketplace-ui.vercel.app/api/marketplace/search` → 200, live opportunities
- Auth me (logged out): `GET /api/auth/me` → `{"authenticated":false}`
- Logout: `POST /api/auth/logout` → `{"ok":true}`
- Apply context (prelaunch): `GET /api/applications/apply-context/193` → `403 marketplace_closed`

Full apply submit requires a vendor Application Password login after marketplace is open.

## Troubleshooting

| Symptom | Check |
|---------|--------|
| Search returns 500 | `WORDPRESS_API_URL` on Vercel; WP plugin active |
| HTML instead of JSON | Wrong URL or permalinks |
| Login fails | Application password (spaces optional); user is vendor |
| Apply 403 `marketplace_closed` | Marketplace not launched yet — expected until launch date |
| Apply 403 other | Vendor role; profile complete |
| Empty results | Index sync; opportunities published |
| Direct WP curl 403 | May be WAF/geo; BFF server-side calls still work |

## Mock vs live

- `NEXT_PUBLIC_LNKUP_LIVE_API=false` — uses `lib/data.ts` (demo without WP)
- `NEXT_PUBLIC_LNKUP_LIVE_API=true` — Find Events + Apply hit BFF → WordPress

## Security notes

- Application passwords are stored encrypted in httpOnly cookies server-side
- Never expose `WORDPRESS_API_URL` or `SESSION_SECRET` to the client
- Rotate application passwords if compromised

## Native clients (Expo)

The iOS/Android app in [`lnk-up-marketplace-mobile/`](../lnk-up-marketplace-mobile/) does **not** use this cookie BFF.

| Concern | Web (`lnk-up-marketplace-ui`) | Native (`lnk-up-marketplace-mobile`) |
|---------|------------------------------|--------------------------------------|
| Auth | Encrypted httpOnly `lnkup_wp_session` cookie | Application Password in Expo SecureStore |
| API path | Browser → `/api/*` BFF → WP | Device → WP `lnkup/v1` + `wp/v2` directly |
| Env | `WORDPRESS_API_URL` (server), `NEXT_PUBLIC_LNKUP_LIVE_API` | `EXPO_PUBLIC_WORDPRESS_API_URL`, `EXPO_PUBLIC_LNKUP_LIVE_API` |

Same upstream Application Password model and marketplace/search + applications/vendor routes. Direct WP calls may hit WAF 403 from some networks — if that blocks mobile in production, add a thin native token/proxy later; do not force the cookie session onto Expo.

Architecture: [SYSTEM_OVERVIEW.md §14.3](SYSTEM_OVERVIEW.md#143-native-mobile-architecture).

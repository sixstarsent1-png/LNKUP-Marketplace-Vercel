# Phase 5 — Launch QA & PWA Hardening

Production Next.js app: `lnk-up-marketplace-ui/` (Vercel). WordPress plugin remains source of truth for business logic and Financial OS.

## Required environment variables

| Variable | Required | Notes |
|----------|----------|-------|
| `WORDPRESS_API_URL` | Yes (live) | REST base, e.g. `https://thelnkup.com/wp-json/lnkup/v1` |
| `NEXT_PUBLIC_WORDPRESS_SITE_URL` | Yes | Public WP site for profile links |
| `NEXT_PUBLIC_LNKUP_LIVE_API` | Yes | `true` to use BFF + WP; `false` for mock fixtures |
| `SESSION_SECRET` | Yes (live) | Encrypts session cookie |
| `ENABLE_QA_TOOLS` | Optional | `true` enables `/qa`, `/ops/analytics`, and `/api/qa/health` |
| `ENABLE_OPS_ANALYTICS` | Optional | `true` enables `/ops/analytics` without `/qa` |
| `OPS_WP_USERNAME` | Optional | Server-only WP user for live ops analytics |
| `OPS_WP_APP_PASSWORD` | Optional | Application Password for ops analytics (never client-exposed) |

## PWA (manifest-only)

- `public/manifest.webmanifest` — install metadata, no service worker
- Icons: `public/icons/icon-192.png`, `public/icons/icon-512.png`, `public/apple-touch-icon.png`
- Regenerate icons: `cd lnk-up-marketplace-ui && node scripts/generate-pwa-icons.mjs` (requires `sharp`)
- Theme color: `#0c0d10`, `viewportFit: cover` for notched devices

## Deploy (Vercel)

```bash
cd lnk-up-marketplace-ui
npx vercel deploy --prod
```

Rollback: promote previous deployment in Vercel dashboard.

## WordPress plugin checklist

Upload/update on thelnkup.com:

- `wp-content/plugins/lnkup-marketplace/` (applications organizer route, payment redirect-url, etc.)
- Verify REST: `GET /wp-json/lnkup/v1/marketplace/search`, `GET .../applications/organizer` (auth)

## Manual route matrix

### Vendor

| Route | Panel states |
|-------|----------------|
| `/vendor` | Home |
| `/vendor/find` | Search + apply |
| `/vendor/requests` | `?empty=1` `?error=1` |
| `/vendor/profile` | Readiness |
| `/vendor/favorites` | Saved opportunities |
| `/vendor/wallet` | Payments summary |
| `/vendor/pass/[id]` | Locked / unlocked pass |
| `/login` | Auth |

### Organizer

| Route | Panel states |
|-------|----------------|
| `/organizer` | Home + checklist |
| `/organizer/events` | `?empty=1` `?error=1` |
| `/organizer/vendors` | Requests + profile drawer |
| `/organizer/money` | Financial snapshot |
| `/organizer/more` | Links |

### Global

| Route | Purpose |
|-------|---------|
| `/` | Role picker |
| `/not-found` | 404 |
| `/offline` | Offline copy (no SW) |
| `/qa` | Internal QA (gated) |
| `/ops/analytics` | Launch analytics command center (gated) |

## Playwright

```bash
# All app specs (Phases 1–5 + role switch)
pnpm test:e2e:app

# Mobile subset (Phase 5 @mobile)
pnpm test:e2e:app:mobile

# Both projects
playwright test --project=lnkup-app --project=lnkup-app-mobile
```

Set `PLAYWRIGHT_APP_URL` if not using `http://localhost:3000`.

## Mock vs live surfaces

When `NEXT_PUBLIC_LNKUP_LIVE_API=false`, fixtures in `lib/data.ts` and `lib/api/organizer-mock.ts` drive:

- Vendor requests, readiness, documents, favorites, saved searches, payment handoff, packet
- Organizer home, events, requests, financial, vendor profile

## Financial OS boundary

- **No** Stripe SDK or PaymentIntents in Next.js
- Checkout URLs come from WordPress `LNKup_Financial_Service` via BFF redirect-url
- Organizer/vendor payment UIs are handoff + read-only snapshots only

## Build & lint

```bash
cd lnk-up-marketplace-ui
pnpm lint
npx next build
```

## Security checks (Phase 5)

- BFF never returns `appPassword`, session blobs, or `client_secret`
- Shared errors: `lib/api/app-error.ts`, `normalize-wp-error.ts`, `parse-api-error.ts`
- QA tools disabled in production unless `ENABLE_QA_TOOLS=true`

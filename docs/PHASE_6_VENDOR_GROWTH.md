# Phase 6: Launch Growth + Vendor Conversion

Next.js app (`lnk-up-marketplace-ui`) vendor acquisition, onboarding, recommendation rails, city markets, referrals, and analytics instrumentation.

## Routes

| Route | Purpose |
|-------|---------|
| `/vendors` | Public vendor conversion landing |
| `/vendor/onboarding` | Stepper; WP read + session progress only |
| `/vendor` | Growth dashboard (next action, rails, referrals) |
| `/vendor/find` | Search + explore rails |
| `/markets/[slug]` | City market pages (registry + search compose) |
| `/login?intent=vendor` | Vendor sign-in with redirect preservation |

## BFF endpoints

| Route | WordPress upstream |
|-------|-------------------|
| `POST /api/analytics/track` | `POST /marketplace/track/event` |
| `GET /api/marketplace/recommended` | `GET /marketplace/sections/recommended` |
| `GET /api/marketplace/trending` | `GET /marketplace/sections/trending` |
| `GET /api/marketplace/markets/[slug]` | Composed via `GET /marketplace/search?city=` |
| `GET/POST /api/vendor/onboarding` | `/growth/profile-strength`, `/verification/vendor`, session cookie |
| `GET /api/vendor/referrals` | `GET /financial/referrals` |
| `POST /api/vendor/referrals/invite` | `POST /financial/referrals/code` |

## Environment

| Variable | Role |
|----------|------|
| `NEXT_PUBLIC_LNKUP_LIVE_API=true` | Live WordPress BFF |
| `NEXT_PUBLIC_WORDPRESS_SITE_URL` | Registration + referral share links |
| `WORDPRESS_API_URL` | BFF upstream base |
| `SESSION_SECRET` | Session + onboarding progress cookie |

## Mock vs live

When `NEXT_PUBLIC_LNKUP_LIVE_API` is unset/false:

- Onboarding uses `vendor-onboarding-mock.ts`
- Rails use `marketplace-sections-mock.ts`
- Markets use `buildMockMarketPage()`
- Referrals use `mockVendorReferrals`
- Analytics BFF returns `{ success: true, mocked: true }`

## City markets registry

Mirrors `LNKup_Market_Pages::default_markets()` plus `nashville` and `virginia-beach`. See `lib/api/markets.ts`.

## Analytics events

Client: `trackLnkupEvent()` → `POST /api/analytics/track` (fire-and-forget, `keepalive`).

Instrumented surfaces: `/vendors`, `/`, login, onboarding, opportunity save/apply, apply modal, payment drawer, vendor pass.

## Tests

```bash
cd lnk-up-marketplace-ui && npm run dev
# separate terminal
npx playwright test lnkup-app-vendor-phase6.spec.ts --project=lnkup-app
npx playwright test lnkup-app-*.spec.ts --project=lnkup-app
```

## Build & deploy

```bash
cd lnk-up-marketplace-ui && npm run build
npx vercel deploy --prod
```

Build uses `next build --webpack` (monorepo Turbopack root workaround).

## Out of scope

Native app, push notifications, SSO, embedded Stripe, admin analytics, AI recommendations, WP profile-save REST (adapter-only).

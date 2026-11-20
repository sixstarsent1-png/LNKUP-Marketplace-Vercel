# LNKup Marketplace UI

Next.js app for the LNKup vendor opportunity marketplace. **Mock mode** uses local data; **live mode** connects to production WordPress via a BFF proxy.

**Live:** https://lnkup-marketplace-ui.vercel.app

## Quick start

```bash
cd lnk-up-marketplace-ui
npm install
cp .env.example .env.local   # optional — for live API
npm run dev
```

Open http://localhost:3000 and pick **Vendor**, **Organizer**, or **Admin**.

```bash
npx next build   # production build check
```

## Live API mode

Set in `.env.local` or Vercel project env:

| Variable | Purpose |
|----------|---------|
| `WORDPRESS_API_URL` | Production WordPress base URL (server only) |
| `SESSION_SECRET` | Encrypts login session (min 16 chars) |
| `NEXT_PUBLIC_LNKUP_LIVE_API` | `true` = live search + apply |
| `NEXT_PUBLIC_WORDPRESS_SITE_URL` | Links to WP registration / app passwords |

**Vendor sign-in:** `/login` with WordPress username + [Application Password](https://wordpress.org/documentation/article/application-passwords/).

With live API on, `/vendor/find` loads real opportunities; **Apply** uses WordPress eligibility and submit. Logged-out users can browse; apply prompts sign-in.

See [`docs/LIVE_API_INTEGRATION.md`](../docs/LIVE_API_INTEGRATION.md) for architecture and troubleshooting.

## Route map

### Vendor

| Route | Screen |
|-------|--------|
| `/vendor` | Home |
| `/vendor/find` | Find Events |
| `/login` | Vendor sign-in (live API) |
| `/vendor/requests` | Applications |
| `/vendor/wallet` | Wallet & payouts |
| `/vendor/profile` | Business profile (6 tabs) |
| `/vendor/more` | More hub |
| `/vendor/documents` | Documents & compliance |
| `/vendor/growth` | Growth & reputation |
| `/vendor/reviews` | Organizer reviews |
| `/vendor/favorites` | Saved events |
| `/vendor/operations` | Event operations list |
| `/vendor/pass/[eventId]` | Event pass (deep link) |

**Mobile nav (5):** Home · Find · Requests · Wallet · More  
**Desktop:** Profile in sidebar; More as secondary link + hub on mobile.

### Organizer

| Route | Screen |
|-------|--------|
| `/organizer` | Command center |
| `/organizer/events` | Events list |
| `/organizer/events/[eventId]` | Event workspace |
| `/organizer/vendors` | Vendors (requests, discover, lists) |
| `/organizer/money` | Financial center |
| `/organizer/more` | Settings |

### Admin

| Route | Screen |
|-------|--------|
| `/admin` | Platform overview |
| `/admin/users` | Users |
| `/admin/events` | Events |
| `/admin/money` | Money |
| `/admin/more` | More |

## Mock data

When `NEXT_PUBLIC_LNKUP_LIVE_API` is not `true`, all data lives in [`lib/data.ts`](lib/data.ts). No backend required.

## Panel states (QA)

Pages using [`lib/use-panel-state.ts`](lib/use-panel-state.ts) support query overrides:

- `?empty=1` — empty state
- `?error=1` — error state with retry

Example: `/vendor/find?error=1`

## Stack

- Next.js 16 (App Router)
- Tailwind CSS 4
- shadcn/ui + Base UI
- Lucide icons

## Monorepo note

The parent repo’s root `app/` folder is a **legacy** launcher. Always develop in this directory (`lnk-up-marketplace-ui/`).

WordPress production (`wp-content/plugins/lnkup-marketplace`) is separate; live integration uses existing `lnkup/v1` REST routes via BFF.

See [`docs/PHASE_2_APP_QA.md`](../docs/PHASE_2_APP_QA.md) and [`docs/LIVE_API_INTEGRATION.md`](../docs/LIVE_API_INTEGRATION.md).

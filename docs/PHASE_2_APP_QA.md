# LNKup Phase 2 — App QA Checklist

Use after changes to `lnk-up-marketplace-ui/` (not WordPress).

## Build

```bash
cd lnk-up-marketplace-ui
npm install   # if needed
npx next build
```

## Role picker (`/`)

- [ ] Vendor, Organizer, Admin cards navigate to correct home routes

## Vendor — primary nav

Mobile bottom: **Home · Find · Requests · Wallet · More**  
Desktop sidebar: Home, Find Events, Requests, Wallet, Profile + dashed **More** link

- [ ] `/vendor` — home KPIs, quick actions
- [ ] `/vendor/find` — subtitle, search, skeleton via `?error=1` / empty via filters
- [ ] Explore More rails (Saved / Trending) hidden when search or category chip active
- [ ] `/vendor/requests` — tabs, request cards
- [ ] `/vendor/wallet` — balance, fee breakdown
- [ ] `/vendor/profile` — 6 tabs, settings

## Vendor — More hub

- [ ] `/vendor/more` — work + account groups
- [ ] `/vendor/documents` — compliance score, document list
- [ ] `/vendor/growth` — vendor score, milestones
- [ ] `/vendor/reviews` — organizer reviews
- [ ] `/vendor/favorites` — saved opportunity cards
- [ ] `/vendor/operations` — active events → pass link
- [ ] `/vendor/pass/[eventId]` — event pass card; back goes to operations
- [ ] More nav item highlights on all secondary routes + pass

## Organizer

- [ ] `/organizer` — command center, panel states
- [ ] `/organizer/events`, `/organizer/events/[eventId]`
- [ ] `/organizer/vendors` — Requests / Discover / Approved / Lists / Invites tabs
- [ ] `/organizer/money` — 6 tabs
- [ ] `/organizer/more` — settings menu

## Admin

- [ ] `/admin` — KPIs, queues
- [ ] `/admin/users`, `/admin/events`, `/admin/money`, `/admin/more`

## Panel state QA

On any page using `usePanelState`:

- [ ] Default loads content
- [ ] `?empty=1` shows empty state
- [ ] `?error=1` shows error + retry

## Live API (when `NEXT_PUBLIC_LNKUP_LIVE_API=true`)

Prerequisites: `WORDPRESS_API_URL`, `SESSION_SECRET`, vendor Application Password on production WP.

- [ ] `GET {WORDPRESS_API_URL}/wp-json/lnkup/v1/marketplace/search` returns JSON
- [ ] `/vendor/find` loads real opportunities (loading → cards)
- [ ] Search debounce + category chips update results
- [ ] Empty/error states when WP returns no items or BFF fails
- [ ] Logged out: **Sign in** in header; Apply shows login CTA
- [ ] `/login` — valid app password → redirect; invalid → error message
- [ ] Logged in: Apply modal loads context; submit creates application
- [ ] Logout clears session; apply blocked until sign-in again
- [ ] `NEXT_PUBLIC_LNKUP_LIVE_API=false` — mock `lib/data.ts` still works

## Deploy

Push to GitHub; Vercel project `lnkup-marketplace-ui` builds from `lnk-up-marketplace-ui/` root.

Promote preview to production when ready:

```bash
vercel promote <deployment-url> --yes
```

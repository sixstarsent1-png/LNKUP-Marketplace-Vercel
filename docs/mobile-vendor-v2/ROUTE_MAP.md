# Vendor route map

Paths are expo-router file routes under `lnk-up-marketplace-mobile/app`. Every route listed requires a vendor session unless marked public.

## Tabs (`app/(vendor)/_layout.tsx`)

| Tab | Route | File | Primary data |
|---|---|---|---|
| Home | `/(vendor)` | `index.tsx` | `/applications/vendor`, `/marketplace/sections/recommended?group_by=event`, `/marketplace/sections/trending?section=closing_soon&group_by=event`, readiness, inbox count |
| Explore | `/(vendor)/find` | `find.tsx` | `/marketplace/search?group_by=event`, `/marketplace/map-pins` |
| Bookings | `/(vendor)/requests` | `requests.tsx` | `/applications/vendor` |
| Wallet | `/(vendor)/wallet` | `wallet.tsx` | financial summary and dashboard, `/financial/transactions?role=vendor`, invoices, receipts, disputes |
| Profile (was labelled "Passport") | `/(vendor)/more` | `more.tsx` | `/vendor/profile`, `/applications/vendor` |

Hidden vendor routes (`href: null`): `profile`, `documents`, `favorites`, `growth`, `reviews`, `passport`, `operations`, `notifications`, `invitations`, `notification-settings`, `saved-searches`, `referrals`.

## Stack routes

| Route | File | Notes |
|---|---|---|
| `/event/[eventId]?option=<oppId>` | `app/event/[eventId].tsx` | Signed-in vendors get `VendorEventDetail` (grouped options and contextual CTA, `GET /marketplace/events/{id}`). Everyone else gets the existing guest-safe public event view (deep links, Phase 8). |
| `/opportunity/[opportunityId]` | `app/opportunity/[opportunityId].tsx` | Kept for existing links. When the opportunity belongs to an event it replaces itself with `/event/{eventId}?option={opportunityId}`; otherwise it shows the single-opportunity screen. |
| `/apply/[opportunityId]` | `app/apply/[opportunityId].tsx` | Existing application flow (drafts, profile prefill, idempotent submit) |
| `/booking/[applicationId]` | `app/booking/[applicationId].tsx` | `GET /applications/vendor/{id}` (timeline with real timestamps) |
| `/pass/[applicationId]` | `app/pass/[applicationId].tsx` | `GET /packets/pass/{id}`; honours `credential_state` |
| `/wallet/transaction/[id]` | `app/wallet/transaction/[id].tsx` | New. `GET /financial/transactions/{id}` |
| `/wallet/receipt/[id]` | `app/wallet/receipt/[id].tsx` | New. `GET /financial/receipts/{id}`; opens the signed receipt view or PDF |
| `/tour/[tourId]`, `/tour/apply/[tourId]` | `app/tour/*` | Unchanged |
| `/storefront/vendor`, `/storefront/organizer` | `app/storefront/*` | Public storefronts (unchanged) |

## Deep links

Associated domain `thelnkup.com`, scheme `lnkup`. `app/+native-intent.ts` maps web paths to app routes (Phase 8 deep links). The new routes take numeric IDs only. Protected routes redirect to `/login` with `returnTo` and resume after sign-in through `lib/navigation/pending-destination.ts`.

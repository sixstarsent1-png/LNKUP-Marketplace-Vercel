# LNKup Marketplace — Native Mobile (Expo)

Expo React Native app for **iOS** and **Android**. Design IA follows [`lnkup-app-reference/`](../lnkup-app-reference/); WordPress (`lnkup/v1`) remains the system of record.

**Native Phases 1–10 (this folder):** vendor core loop + More hub + notification center + Find discovery (list + **map**); organizer lite + Launch publish; Expo push; EAS linked; **store release hardening** (operator submit). Deep packet/payouts editors stay deferred / WP handoff.

## Architecture

```
Expo app → WordPress REST (Basic auth in SecureStore)
         → Push devices, documents upload, favorites toggle
         → Notification feed composed client-side (SecureStore mark-read)
         → Marketplace search filters + saved-searches CRUD
         → Organizer launch publish (AsyncStorage draft)
```

Unlike the Vercel web app, native does **not** use the Next.js cookie BFF.

## Setup

```bash
cd lnk-up-marketplace-mobile
npm install
cp .env.example .env
npx expo start
```

### Mock mode

Leave `EXPO_PUBLIC_LNKUP_LIVE_API=false` and tap **Mock as vendor** or **Mock as organizer**.

### Live API

```bash
EXPO_PUBLIC_WORDPRESS_API_URL=https://thelnkup.com
EXPO_PUBLIC_LNKUP_LIVE_API=true
```

Sign in with WordPress username + **password** (same account credentials as thelnkup.com).

**WordPress deploy required** for mobile docs download / profile edit / ops batch / roster reject reason: ship plugin changes including `GET /lnkup/v1/documents/{id}/file`, `GET|POST /lnkup/v1/vendor/profile`, `GET /lnkup/v1/operations/vendor-homes`, and roster `$extra` → `handle_organizer_action`. Also confirm production includes invite-options `include_all`.

## Vendor tabs

Home · Find · Requests · Wallet · **More**

### Find discovery (Phase 8)

| Capability | Status |
|------------|--------|
| Text + filters sheet | City, state, category, fee band, verified, sort |
| Near me | `expo-location` → lat/lng/radius; city fallback on deny |
| Saved searches | List / create / run / delete via WP REST |
| Map / list toggle | Built (Phase 24 — `react-native-maps` + `/marketplace/map-pins`) |

## Organizer tabs

Home · Events · Vendors · Money · **More**

### Launch (Phase 9)

| Route | Status |
|-------|--------|
| `/(organizer)/launch` | 3-step Event → Opportunity → Review; `POST /organizer/launch/publish` |
| Draft | AsyncStorage on device (not web BFF cookies) |
| Entry | Home “Launch event”, checklist create CTA, Events header/empty, More row |

Deep work (packet, payouts, invites) remains WordPress `Linking`.

## EAS + store release (Phase 10)

**Linked:** [@darkero12/lnkup-marketplace](https://expo.dev/accounts/darkero12/projects/lnkup-marketplace)

```bash
npm run build:preview
npm run build:production
```

Store submit (`npm run submit:ios` / `submit:android`) is **operator-only** — credentials stay out of the repo. Full checklist: **[STORE_RELEASE.md](./STORE_RELEASE.md)**.

Privacy / support URLs (also in `app.json` → `extra`):

- Privacy: https://thelnkup.com/privacy
- Support: https://thelnkup.com/contact

Brand icons come from the official logo (`assets/brand/lnkup-logo.png`):

```bash
npm run icons
npm run doctor
```

## Docs

- [SYSTEM_OVERVIEW.md](../docs/SYSTEM_OVERVIEW.md) §14.3
- [LIVE_API_INTEGRATION.md](../docs/LIVE_API_INTEGRATION.md)
- [STORE_RELEASE.md](./STORE_RELEASE.md)

## Out of scope (through Phase 10)

Deep packet/payouts editors, automated store submit / committed ASC-Play credentials, WP notification inbox REST, email/SMS prefs editor, Growth writes, organizer review create, ops support tickets, embedded Stripe.

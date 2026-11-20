# LNKup Mobile - Store Release Checklist

Operator-run guide for App Store / Play submission. **Do not commit Apple/Google credentials or service-account JSON.** Automated `eas submit` from CI is out of scope.

**Target public release:** **2026-08-16 12:00 PM America/New_York (EDT)**  
**EAS project:** [@darkero12/lnkup-marketplace](https://expo.dev/accounts/darkero12/projects/lnkup-marketplace)  
**Bundle / package:** `com.lnkup.marketplace`  
**User-facing version:** `expo.version` in [`app.json`](./app.json) (currently `1.0.0`)

> Web marketplace is open as of **2026-08-02**. The homepage app-release bar uses WordPress settings `app_release_datetime` / `app_release_tz` and does **not** gate browse/apply on the web.

---

## 1. Preconditions

- [ ] Expo account with access to the linked EAS project
- [ ] Apple Developer Program + App Store Connect app for `com.lnkup.marketplace`
- [ ] Google Play Console app for `com.lnkup.marketplace`
- [ ] Local EAS CLI: `npm i -g eas-cli` (or use `npx eas-cli`)
- [ ] Logged in: `eas login`
- [ ] Privacy / support / terms pages live (defaults in `app.json` -> `extra`):
  - Privacy: `https://thelnkup.com/privacy`
  - Support: `https://thelnkup.com/contact`
  - Terms: `https://thelnkup.com/terms`
- [ ] Confirm those URLs resolve (or update `extra.*` before production build)
- [ ] App Store Connect → App Information → Privacy Policy URL is `https://thelnkup.com/privacy`
- [ ] Create account is in-app (not Safari). Plugin includes `POST /lnkup/v1/auth/register`
- [ ] Theme + plugin deployed (legal page seeder + `POST /lnkup/v1/account/delete`); visit WP Admin once after theme deploy so pages seed
- [ ] In-app account deletion works: More → Delete my account… → type DELETE (Apple 5.1.1(v))
- [ ] ASC paste pack reviewed: [`docs/ASC_APP_REVIEW_PACKAGE.md`](../docs/ASC_APP_REVIEW_PACKAGE.md)
- [ ] Confirm brand icons (`npm run icons` if mark SVGs changed)

---

## 2. Brand assets

Canonical logo: [`assets/brand/lnkup-logo.png`](./assets/brand/lnkup-logo.png) (LNKup wordmark - black / white / orange `#F58220`).

```bash
npm run icons
```

| Asset | Path | Notes |
|-------|------|-------|
| App icon | `assets/images/icon.png` | From official logo, 1024--1024 |
| Splash | `assets/images/splash-icon.png` | Transparent logo only; screen fill is `#000000` (no baked black box) |
| Android adaptive FG | `assets/images/android-icon-foreground.png` | 512--512 |
| Android adaptive BG | `assets/images/android-icon-background.png` | Solid black |
| Android monochrome | `assets/images/android-icon-monochrome.png` | Silhouette from logo |
| Favicon | `assets/images/favicon.png` | 48--48 |

Re-run `npm run icons` after replacing `assets/brand/lnkup-logo.png`. Do not use Expo starters or the old geometric mark.

**Store listing (outside repo):**

- iOS: 6.7" and 6.1" screenshots (vendor Home / Find / Pass; organizer Home / Launch)
- Android: phone screenshots + optional 1024--500 feature graphic
- Brand: black / white / orange accent - no Stripe UI in screenshots

---

## 3. Versioning

| Field | Where | Who bumps |
|-------|--------|-----------|
| User-facing `version` | `app.json` `expo.version` + `package.json` `version` | Operator before a marketing release (e.g. `1.0.0` -> `1.0.1`) |
| iOS `buildNumber` | EAS remote | Automatic (`cli.appVersionSource: remote` + production `autoIncrement`) |
| Android `versionCode` | EAS remote | Automatic (same) |

Do **not** hand-edit build numbers in-repo when using remote auto-increment.

---

## 4. Config hygiene (pre-build)

- [ ] `npx expo-doctor` / `npm run doctor`
- [ ] `npx tsc --noEmit`
- [ ] Review `npx expo config --type public` - location + push permissions expected
- [ ] `ios.config.usesNonExemptEncryption` / `ITSAppUsesNonExemptEncryption` = `false` (HTTPS / OS crypto only)
- [ ] Location usage string present (Near me - Phase 8)
- [ ] Prefer interactive `eas submit` prompts, or a **gitignored** [`eas-submit.local.json`](./eas-submit.local.json.example) (copy from example). Never commit real Apple IDs or `.p8` keys.
  - iOS: `appleId`, `ascAppId`, `appleTeamId`, **or** ASC API key path + Key ID + Issuer ID
  - Android: `serviceAccountKeyPath` (Play API JSON; gitignored), `track` (`internal` -> `production`)
- [ ] ASC checklist template: [`ASC_SETUP.local.md.example`](./ASC_SETUP.local.md.example) -> copy to gitignored `ASC_SETUP.local.md`
- [ ] One-shot local script: `powershell -File scripts/push-ios.ps1` (interactive terminal required)

**Do not** put empty-string placeholders in committed `eas.json` submit fields - current EAS CLI rejects them. Keep `submit.production.ios` as `{}` and supply values at submit time.

---

## 5. Build

```bash
cd lnk-up-marketplace-mobile

# Internal / TestFlight-style distribution
npm run build:preview

# Store candidates (live API env baked in)
npm run build:production
```

Preview and production set `EXPO_PUBLIC_LNKUP_LIVE_API=true` and `EXPO_PUBLIC_WORDPRESS_API_URL=https://thelnkup.com`.

Build profiles publish to EAS Update channels: `preview` / `production` (`runtimeVersion` policy = `appVersion`).

---

## 5b. EAS Update (OTA)

JS/asset hotfixes (no native module changes):

```bash
npm run update:preview      # channel preview + live API env baked in
npm run update:production   # channel production + live API env baked in
```

**Important:** OTA does **not** use `eas.json` `build.env`. The `update:preview` / `update:production` scripts bake `EXPO_PUBLIC_LNKUP_LIVE_API=true` and `EXPO_PUBLIC_WORDPRESS_API_URL` via `cross-env` (do **not** pass empty `--environment production` unless those vars exist in the Expo dashboard — that can wipe local env). Fallback: `app.json` `extra.liveApi` defaults to `"true"`.

### OTA vs native modules (crash risk)

OTA can only update JS/assets. **Never** ship a top-level `import` of a **new** native module (`expo-network`, `expo-sharing`, `@react-native-community/datetimepicker`, etc.) via OTA until a store binary that includes that native code has shipped.

- Safe: lazy `require()` inside `try/catch`, or omit the feature from layouts until rebuild.
- Unsafe: `import * as Network from "expo-network"` in a tab `_layout` — older binaries crash on cold start when Live API routes users into tabs.

After adding native deps/plugins to `app.json` / `package.json`, run `eas build --profile production` (+ submit) before relying on those APIs in production OTAs.

Requires a store/preview binary built **after** `expo-updates` was added (channel + updates URL embedded). Older TestFlight builds will not pull OTAs.

When native code or `expo.version` changes, bump version, `eas build --profile production`, then submit. OTA alone cannot ship native changes.

**Smoke after production OTA:** cold start with a saved session → Home loads; login shows `Live API: on`.

---

## 6. Submit (operator only)

```bash
# After ASC / Play credentials are configured locally
npm run submit:ios
npm run submit:android
```

Or: `eas submit --platform ios --profile production --latest` (same for android).

**Never** run submit from an unattended agent with production credentials in this repo.

---

## 7. App Store / Play review notes

Full paste pack: [`docs/ASC_APP_REVIEW_PACKAGE.md`](../docs/ASC_APP_REVIEW_PACKAGE.md).

Suggested reviewer text:

> LNKup is a vendor opportunity marketplace. Sign in with a WordPress username and password (or use mock login only in non-production builds).  
> **Account deletion (Guideline 5.1.1(v)):** More → Delete my account… → type DELETE → Confirm. User is signed out immediately; hard delete within 30 days.  
> **Location:** optional Near me on Find Events - when allowed, the app sends lat/lng/radius to marketplace search.  
> **Push:** Expo push tokens register with WordPress for marketplace notifications; toggle in Profile / More.  
> **Payments:** booth fee checkout opens in the system browser to WordPress / Stripe Checkout. Native Stripe may be linked for Connect return flows; primary card entry is browser-based.  
> **Deep organizer ops** (packet editors, invites, maps) open the WordPress dashboard in the browser.

---

## 8. Privacy / data safety answers

| Data | Purpose | Notes |
|------|---------|--------|
| Account (username) | Auth | Account password stored in SecureStore on device |
| Location (optional) | Near-me search | Foreground only; not required to use the app |
| Push token | Marketplace alerts | Registered via `/lnkup/v1/push/*`; cleared on logout |
| Marketplace content | Core product | Applications, events, documents via WordPress REST |
| Account deletion | App Store / privacy | In-app More → Delete account → `POST /lnkup/v1/account/delete` |

Point store privacy policy field at `extra.privacyPolicyUrl`. Support URL -> `extra.supportUrl`.

---

## 9. Rollback

1. In [EAS builds](https://expo.dev/accounts/darkero12/projects/lnkup-marketplace/builds), identify the last good production build.
2. Re-submit that artifact from ASC / Play, or promote the previous Play track release.
3. For a hotfix: bump `expo.version`, `eas build --profile production`, then submit.

---

## Related

- [`docs/ASC_APP_REVIEW_PACKAGE.md`](../docs/ASC_APP_REVIEW_PACKAGE.md) - ASC listing + review notes
- [`README.md`](./README.md) - setup + architecture
- [`docs/SYSTEM_OVERVIEW.md`](../docs/SYSTEM_OVERVIEW.md) --14.3 - Native Phase 10
- [`docs/LIVE_API_INTEGRATION.md`](../docs/LIVE_API_INTEGRATION.md) - WP auth / WAF notes


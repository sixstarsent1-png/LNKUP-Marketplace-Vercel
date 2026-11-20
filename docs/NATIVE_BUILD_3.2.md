# Native build 3.2 (branch `native-3.2`) — needs a new App Store binary

Nothing on this branch can ship over the air. It changes native modules and native configuration, and the version is **1.1.0**, so its runtime (`appVersion` policy) is `1.1.0`. Production phones on 1.0.1 never receive updates published for 1.1.0, and 1.0.1 updates keep flowing from `master` until the new binary is approved.

## What changed

| Area | Change |
|---|---|
| QR check-in | `expo-camera`; `components/pass-scanner.tsx` adds "Scan pass QR" to the organizer check-in screen. Typed pass codes still work. |
| Photos | `expo-image-picker`; vendor and organizer logo upload use the photo library with square crop (`lib/native/image-picker.ts`), falling back to the document picker. |
| Haptics | `expo-haptics`; success and error toasts tap (`lib/native/haptics.ts`). |
| Stripe | `@stripe/stripe-react-native` and its config plugin removed. The app never charged cards natively; checkout stays on WordPress / Stripe Checkout. |
| iOS target | `expo-build-properties` with `ios.deploymentTarget: 16.4` (SDK 57 minimum). |
| Config | Removed `newArchEnabled` (not a valid key in SDK 57; the new architecture is always on). Camera and photo-library permission copy updated (the old camera text described Stripe card scanning). |
| Versions | `npx expo install --fix` aligned 19 SDK 57 patch versions. `expo-doctor`: 21/21 checks pass. |

All three native features are loaded with a lazy `require` in a `try`, so if any of this code is merged to `master` early, older binaries simply hide the feature instead of crashing.

## Not done (needs your input)

- **Sentry**: needs a DSN and organization/project slugs. Then `npx expo install @sentry/react-native`, add its config plugin, and wrap the root layout.
- **Dark mode**: needs dark theme tokens across `lib/theme.ts` and screen audits before switching `userInterfaceStyle` to `automatic`.
- **Event cover / gallery upload from the app**: needs a WordPress endpoint (organizer-owned event media upload with `permission_callback`); the image picker is ready for it.

## Release steps

1. `git checkout native-3.2 && cd lnk-up-marketplace-mobile && npm ci`
2. `npx tsc --noEmit && npx jest && npx expo-doctor`
3. `eas build --platform ios --profile production` (version 1.1.0, build number auto-increments)
4. TestFlight: check camera scan at a test event, logo upload from Photos, haptics, push, deep links.
5. Submit for review. After approval, merge `native-3.2` into `master` and publish future OTAs for runtime 1.1.0.

# Phase 24 Release Checklist

Gate references:
- [PHASE_24_NATIVE_MAP_DISCOVERY.md](PHASE_24_NATIVE_MAP_DISCOVERY.md)
- [PHASES_22_26_ROADMAP.md](PHASES_22_26_ROADMAP.md)
- Web parity: [PHASE_8_DISCOVERY.md](PHASE_8_DISCOVERY.md)

## Scope

- [x] `fetchMapPins` → `GET /marketplace/map-pins` + mock fixtures
- [x] `react-native-maps` installed + app plugin
- [x] Find List / Map toggle
- [x] Loading / empty / error map states
- [x] Pin preview → opportunity / apply routes
- [x] Near me permission-denied still uses city fallback
- [x] Non-PII `map_view_opened` / `map_pin_clicked`
- [x] `phase24:verify` + docs status sync

## Commands

```bash
cd lnk-up-marketplace-mobile
npm run phase24:verify
```

## Manual smoke (device / simulator)

1. Find → **Map** toggle shows map (or web pin-list fallback).
2. Pins load; tap pin → preview with View / Apply.
3. Filters city/state refresh pins.
4. Near me allowed → list sorts closest; map client-filters by radius when coords set.
5. Near me denied → city fallback UI (existing).
6. Empty / error copy points back to List view.
7. Pull to refresh reloads map pins in map mode.

## Go / No-Go

- **Go:** `phase24:verify` passes; map toggle works on a native build.
- **No-Go:** blank map with no empty/error state, or analytics sending lat/lng.

## Operator note (Android)

For production Android map tiles, configure Google Maps API key via Expo `react-native-maps` plugin (`androidGoogleMapsApiKey`) and rebuild the binary.

## Next

Phase 25 — Admin Lite Depth.

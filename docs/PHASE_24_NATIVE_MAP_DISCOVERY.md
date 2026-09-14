# Phase 24 — Native Map Discovery

Production mobile: `lnk-up-marketplace-mobile/` (Expo)  
WordPress remains system of record. Web map discovery (Phase 8) stays in `lnk-up-marketplace-ui/`.

**Status:** Built (executed)  
**Depends on:** Phase 8 discovery APIs, Phase 22/23 mobile baseline  
**Roadmap:** [SYSTEM_OVERVIEW.md §14.2](SYSTEM_OVERVIEW.md#142-app-roadmap-phases-126) · Queue: [PHASES_22_26_ROADMAP.md](PHASES_22_26_ROADMAP.md)  
**Release checklist:** [PHASE_24_RELEASE_CHECKLIST.md](PHASE_24_RELEASE_CHECKLIST.md)

---

## Goal

Ship map discovery on Expo Find Events: list/map toggle, pins from existing `lnkup/v1` marketplace map APIs, loading/empty/error states, and non-PII map analytics. Reuse Near me permission + city fallback already on Find.

---

## Non-goals

- New opportunity system of record
- Map editor (stays WordPress)
- Stripe / payment writes in mobile
- Full filter parity on pins (WP pins support city/state/bbox only)

---

## Deliverables

### 1) Pins client

| Asset | Path |
|-------|------|
| API + types + mock | `lib/api/map-pins.ts` |
| Endpoint | `GET /lnkup/v1/marketplace/map-pins` (same as web BFF) |

City/state forwarded to WP. Near-me `lat`/`lng`/`radiusMiles` applied **client-side** (haversine) because the pins route has no radius param.

### 2) Map UI

| Asset | Path |
|-------|------|
| Native MapView | `components/discovery-map.native.tsx` (`react-native-maps`) |
| Web fallback pin list | `components/discovery-map.tsx` |
| Pin preview card | `components/map-pin-preview.tsx` |
| Find list/map toggle | `app/(vendor)/find.tsx` |

States: loading, empty (no pins), error (retry), permission-denied via existing `NearMeButton` city fallback.

### 3) Telemetry (non-PII)

- `map_view_opened` — pin count + has_city/state/near_me flags (never lat/lng)
- `map_pin_clicked` — `opportunity_id` only

### 4) Native config

- Dependency: `react-native-maps` (Expo SDK 57 compatible)
- Plugin: `react-native-maps` in `app.json`
- iOS uses Apple Maps by default (no Google key required)
- Android production tiles may need a Google Maps API key via Expo plugin options (operator-owned; not committed)

### 5) Verify

```bash
cd lnk-up-marketplace-mobile
npm run phase24:verify
```

---

## Assumptions

- Dev client / EAS rebuild required after adding `react-native-maps` (not Expo Go–only unless matching native module is present).
- Map mode does not replace list search; Near me still drives `/marketplace/search` for the list path.
- Analytics only fire when live API is enabled.

---

## Next

Phase 25 — Admin Lite Depth ([PHASES_22_26_ROADMAP.md](PHASES_22_26_ROADMAP.md)).

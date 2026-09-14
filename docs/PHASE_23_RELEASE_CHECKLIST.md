# Phase 23 Release Checklist

Gate references:
- [PHASE_23_MOBILE_TUTORIAL_PARITY.md](PHASE_23_MOBILE_TUTORIAL_PARITY.md)
- [PHASES_22_26_ROADMAP.md](PHASES_22_26_ROADMAP.md)
- Web parity: [PHASE_21_RELEASE_PLAYBOOK.md](PHASE_21_RELEASE_PLAYBOOK.md)

## Scope

- [x] Tutorial contract step IDs aligned with web Phase 21
- [x] SecureStore dismiss / viewed progress (`lnkup_tutorial_progress_v1`)
- [x] Vendor home guided checklist + push CTA → profile
- [x] Organizer home guided checklist + push CTA → more
- [x] Non-PII tutorial telemetry allowlist
- [x] `phase23:verify` script + docs status sync

## Commands

```bash
cd lnk-up-marketplace-mobile
npm run phase23:verify
```

## Manual smoke (device / simulator)

1. Vendor home (incomplete profile / no applications): tutorial visible with step CTAs.
2. Tap a step → navigates to Find / Profile / Requests / Pass or Wallet.
3. Tap **Enable push alerts** → Profile with `PushPreferencesCard`.
4. Dismiss → tutorial gone after reload; SecureStore key set.
5. Organizer home (no events or pending reviews): tutorial visible.
6. Organizer push CTA → More with push preferences.
7. Complete all steps (or dismiss) → checklist hidden.

## Go / No-Go

- **Go:** `phase23:verify` passes; manual smoke OK on vendor + organizer.
- **No-Go:** tutorial missing on incomplete accounts, or push CTA lands off preference card.

## Next

Phase 24 — Native Map Discovery.

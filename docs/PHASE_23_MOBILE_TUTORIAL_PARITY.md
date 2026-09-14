# Phase 23 — Mobile Tutorial Parity + Push Onboarding

Production mobile: `lnk-up-marketplace-mobile/` (Expo)  
WordPress remains system of record. Web tutorials (Phase 21) stay in `lnk-up-marketplace-ui/`.

**Status:** Built (executed)  
**Depends on:** Phase 21 (web tutorials), Phase 22 (launch hardening)  
**Roadmap:** [SYSTEM_OVERVIEW.md §14.2](SYSTEM_OVERVIEW.md#142-app-roadmap-phases-126) · Queue: [PHASES_22_26_ROADMAP.md](PHASES_22_26_ROADMAP.md)  
**Release checklist:** [PHASE_23_RELEASE_CHECKLIST.md](PHASE_23_RELEASE_CHECKLIST.md)

---

## Goal

Mirror Phase 21 web guided checklists on Expo vendor and organizer homes, with SecureStore dismiss/viewed state, non-PII telemetry, and a push-preference CTA into existing preference cards.

---

## Non-goals

- Redesigning web tutorials
- Stripe / payment writes in mobile
- Redesigning push infrastructure (reuse `PushPreferencesCard`)

---

## Deliverables

### 1) Shared tutorial contract (aligned with web)

| Asset | Path |
|-------|------|
| Step IDs + shapes | `lib/tutorials/tutorial-contract.ts` |
| Vendor builder | `lib/tutorials/build-vendor-tutorial.ts` |
| Organizer builder | `lib/tutorials/build-organizer-tutorial.ts` |
| SecureStore progress | `lib/tutorials/tutorial-progress.ts` (`lnkup_tutorial_progress_v1`) |
| Hook | `lib/tutorials/use-role-tutorial.ts` |
| UI | `components/guided-tutorial-checklist.tsx` |

**Vendor steps:** `profile_readiness` → `discover_opportunity` → `submit_application` → `complete_payment_pass`  
**Organizer steps:** `launch_event` → `review_requests` → `track_payments`

### 2) Home surfaces

| Role | Route | testID | Push CTA → |
|------|-------|--------|------------|
| Vendor | `/(vendor)` | `vendor-home-tutorial` | `/(vendor)/profile` (`PushPreferencesCard`) |
| Organizer | `/(organizer)` | `organizer-home-tutorial` | `/(organizer)/more` (`PushPreferencesCard`) |

Checklist hides when dismissed (SecureStore) or all steps complete.

### 3) Telemetry (non-PII)

Allowlisted in `lib/analytics/mobile-events.ts`:

- `tutorial_viewed`
- `tutorial_step_clicked`
- `tutorial_step_completed`
- `tutorial_push_cta_clicked`

Metadata: `role`, `step_id`, counts — no email/phone/coords/secrets.

### 4) Verify

```bash
cd lnk-up-marketplace-mobile
npm run phase23:verify
```

---

## Assumptions

- Progress keys are per role + user id (fallback: session username).
- Completion is derived from live home data (requests, readiness, organizer stats), not stored step-by-step in SecureStore.
- Analytics only fire when live API is enabled.

---

## Next

Phase 24 — Native Map Discovery ([PHASES_22_26_ROADMAP.md](PHASES_22_26_ROADMAP.md)).

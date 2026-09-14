# Phase 14B — Organizer Loop Completion

Production Next.js app: `lnk-up-marketplace-ui/` (Vercel). WordPress remains source of truth for approvals and financial settlement.

**Status:** Built + two-week stabilization complete  
**Parent narrative:** [E2E_MARKETPLACE_JOURNEY.md](E2E_MARKETPLACE_JOURNEY.md)  
**Depends on:** Phase 9 (launch/workspace), Phase 13 (notifications), Phase 14A (deep share links recommended)  
**Execution log:** [PHASE_14_TWO_WEEK_EXECUTION_LOG.md](PHASE_14_TWO_WEEK_EXECUTION_LOG.md)  
**Next:** [Phase 14C — Wallet Polish](PHASE_14C_WALLET_POLISH.md)

---

## Goal

Make the **organizer side of the marketplace loop** completable inside the app: discover launch entry points, review and approve vendors, share targeted links, and see money counts update after vendors pay — without requiring WordPress dashboard for routine operations.

**Financial OS rule (unchanged):** Approval triggers `payment_required` via existing WP application workflow; no Stripe or payout writes in Next.js.

---

## Problem statement

| Today | Impact |
|-------|--------|
| Launch wizard not in main nav | Organizers miss in-app event creation |
| One opportunity per launch | Can’t add vendor slots to existing events in app |
| Approval partially in app / partially WP | Organizer bounces to WP for review |
| Share links are generic find filters | Lower conversion (addressed in 14A; 14B wires share panel UX) |
| Money counts feel disconnected from approvals | Organizer doesn’t see approval → payment → collection chain |

---

## Deliverables

### 1. Launch in main navigation

| Surface | Change |
|---------|--------|
| Organizer sidebar (`lib/nav.ts`) | Add **Launch event** → `/organizer/launch` (icon: rocket or plus-calendar) |
| Organizer home | Primary CTA hierarchy: **Launch event** above secondary links |
| Event workspace empty state | “Add another opportunity” when 14B.2 ships |

**Copy:** “Launch event” / “Create vendor opportunity” — not “Create in WordPress”.

### 2. In-app vendor approval (default path)

**Routes:**

| Route | Change |
|-------|--------|
| `/organizer/vendors` | Approve / reject actions on request cards |
| `/organizer/events/[id]` | Requests tab: live queue with approve/reject |
| BFF | `POST /api/organizer/applications/[id]/review` → WP `POST /lnkup/v1/applications/{id}/review` (or existing AJAX parity) |

**Request body (normalized):**

```json
{
  "action": "approve" | "reject",
  "note": "optional organizer message"
}
```

**Post-approve behavior:**

- Application status → `payment_required` (WP canonical)
- Phase 13 composer emits vendor notification + home retention card
- Organizer card shows “Awaiting payment” with amount if available
- Event workspace request count updates on refetch

**Permission:** Organizer must own event (`LNKup_Capabilities::organizer_owns_event`).

**UI states:** loading per card, error toast, optimistic disable (no double-submit).

### 3. Event workspace — live request queue

| Tab | Content |
|-----|---------|
| Requests | Sort: pending → approved awaiting payment → paid; filter by status |
| Row actions | Approve, reject, view vendor profile (read-only) |
| Empty | “Share your link to get applications” + copy deep link (14A) |

**BFF aggregation:** Extend `GET /api/organizer/events/[id]` with application list or dedicated `GET /api/organizer/events/[id]/requests`.

**WP sources:** CRM roster, applications by event/opportunity meta.

### 4. Share panel — deep links (with 14A)

| Control | Behavior |
|---------|----------|
| Copy vendor link | Uses `opportunity_url` from share API (14A) |
| Preview | Shows event name, fee, spots remaining |
| QR (optional) | Encode deep link for print/social |

**Analytics:** `share_link_copied`, `organizer_share_panel_viewed`.

### 5. Add opportunity to existing event

**User story:** Organizer published one booth type at launch; wants to add “Food truck” row without re-running full wizard.

| Surface | Behavior |
|---------|----------|
| Event workspace | **Add opportunity** button |
| Flow | Short form: title, fee, spots, categories → publish |
| BFF | `POST /api/organizer/events/[id]/opportunities` → WP `POST /organizer/events/{id}/opportunities` |

**WP prerequisite:** Implement write route noted as “Not implemented” in [PHASE_9_ORGANIZER_LAUNCH.md](PHASE_9_ORGANIZER_LAUNCH.md). Until WP ships, show handoff: “Add opportunities in WordPress” with `handoff_required` pattern (never fake success).

### 6. Money tab real-time-ish updates

| Surface | Behavior |
|---------|--------|
| `/organizer/money` | Refetch on focus / interval when `paymentRequiredCount > 0` |
| Organizer home | Retention card: “{n} vendors need to pay” → Money tab |
| Event Payments tab | After vendor pays (14A return + webhook), counts update on next fetch |

**No websockets required** — refetch on navigation, tab focus, and pull-to-refresh (mobile).

### 7. Publish failure recovery (launch wizard)

| Failure | UX |
|---------|-----|
| WP 4xx/5xx on publish | Preserve cookie draft; show retry + support |
| `handoff_required` | Explain WP step needed; link to WP create |
| Network error | Offline-friendly message; draft intact |

---

## Acceptance criteria

- [ ] Launch event visible in organizer nav + home CTA
- [ ] Organizer can approve/reject from `/organizer/vendors` and event Requests tab
- [ ] Approve triggers vendor `payment_required` visible in app within one refetch
- [ ] Share panel uses deep opportunity link (14A contract)
- [ ] Event workspace shows live request queue with statuses
- [ ] Add opportunity: live when WP route exists; handoff when not
- [ ] Organizer money/home counts reflect pending payments
- [ ] All review routes have session auth + ownership checks
- [ ] Mock mode covers approve → vendor payment_required path
- [ ] Playwright: organizer approve → vendor sees payment required (mock)
- [ ] `npx next build` passes

---

## Mock vs live

| Mode | Behavior |
|------|----------|
| `LIVE_API=false` | Approve updates fixture application status; share returns mock deep URLs |
| `LIVE_API=true` | BFF proxies WP review + share + opportunities endpoints |

---

## Out of scope (14B)

- WP-backed cross-device launch drafts
- Full CRM port (notes, flags, mass invite)
- Maps editor, packet editing
- Financial writes (payouts, reserves)
- Instant-approval / express purchase flags

---

## QA checklist

1. New organizer finds Launch in sidebar → publishes → lands on workspace
2. Copy share link → vendor applies (14A deep link)
3. Organizer approves in app → vendor requests show payment required
4. Vendor pays (14A) → organizer money collected increases
5. Add opportunity: success or explicit WP handoff
6. Reject application → vendor sees rejected status; no payment CTA
7. Wrong-role user cannot approve another organizer’s event

---

## Files likely touched (implementation reference)

| Layer | Paths |
|-------|-------|
| WP plugin | `class-organizer-launch-rest.php`, applications review REST, `POST .../opportunities` |
| BFF | `app/api/organizer/applications/[id]/review`, events requests + opportunities routes |
| UI | `lib/nav.ts`, `app/organizer/page.tsx`, `app/organizer/vendors/page.tsx`, `app/organizer/events/[id]/page.tsx`, `app/organizer/launch/page.tsx` |
| Notifications | `lib/api/notifications.ts` composer (approval → vendor alert) |
| Tests | `tests/e2e/lnkup-app-phase14b-organizer-loop.spec.ts` (new) |

---

## Implementation snapshot (Jul 2026)

- Organizer desktop nav now includes a persistent `Launch event` entry.
- Event workspace requests tab now shows an in-app per-event request queue with review actions.
- Organizer review actions on request cards remain in-app on organizer surfaces, with live/mock parity.
- Opportunities tab now includes an in-app `Add opportunity` CTA plus WP handoff fallback CTA.
- New regression coverage added in `tests/e2e/lnkup-app-phase14b-organizer-loop.spec.ts` and passing.

### Verification status

- Organizer nav launch entry verified in E2E.
- Event workspace requests queue and add-opportunity CTA verified in E2E.
- In-app approve flow UI update verified in E2E (mock-safe path).

# App Phase 4 — Organizer Lite + Launch Ops

Next.js app (`lnk-up-marketplace-ui/`) lightweight organizer experience without porting the full WordPress dashboard.

**Status:** Partial  
**WordPress:** System of record — organizer deep ops stay in WP dashboard  
**Related:** [APP_GAP_ANALYSIS.md](APP_GAP_ANALYSIS.md) · [PHASE_2_UI_PORT_MAP.md](PHASE_2_UI_PORT_MAP.md)

---

## Goal

Create an **Organizer Lite** live experience so organizers can see their events, review vendor requests, inspect vendor profiles, and understand launch operations — without porting the full WordPress command center into Next.js.

---

## Architecture rules

- WordPress remains system of record
- No Stripe or Financial OS write operations in Next.js
- Event creation may **hand off** to WordPress dashboard or use safe BFF proxies
- Do not port wp-admin or full hash-based organizer CRM

---

## Deliverables

### 1. Organizer command center — `/organizer`

| BFF | WordPress upstream |
|-----|-------------------|
| `GET /api/organizer/home` | `/intelligence/organizer/summary`, `/crm/dashboard/widgets`, `/crm/launch-checklist`, `/financial/organizer-balances` |

- KPI strip, launch checklist, pending requests snapshot, financial snapshot
- Panel states: `?empty=1`, `?error=1`

### 2. Events list — `/organizer/events`

| BFF | WordPress upstream |
|-----|-------------------|
| `GET /api/organizer/events` | CRM events + roster metadata |

- Event cards with capacity, status, link to workspace
- **Gap:** full event workspace still mock

### 3. Vendor requests — `/organizer/vendors` (Requests tab)

| BFF | WordPress upstream |
|-----|-------------------|
| `GET /api/organizer/requests` | `GET /applications/organizer` |
| `POST /api/organizer/requests/[id]/review` | CRM roster bulk approve/reject/waitlist |
| `GET /api/organizer/vendors/[id]` | CRM vendor profile + application intel |

- Request cards, reject dialog, vendor profile drawer

### 4. Financial snapshot — `/organizer/money`

| BFF | WordPress upstream |
|-----|-------------------|
| `GET /api/organizer/financial` | `/financial/organizer-balances` or `/financial/dashboard` |

- Read-only balances and revenue snapshot
- Link to full WordPress Financial center for cashouts/Connect
- **Gap:** six-tab money UI mostly placeholder

### 5. Event workspace — `/organizer/events/[eventId]` (partial)

**Current:** Mock detail view with link to WordPress organizer dashboard (`?event={id}`).

**Target (Phase 9):** Live event-scoped summary, applications, launch checklist, ops handoff.

### 6. Secondary tabs (mock)

| Tab | Status |
|-----|--------|
| Discover vendors | Mock — `lib/data.ts` |
| Lists | Mock |
| Invites | Mock |

WP equivalents: `#vendors`, `#lists`, `#invites` in organizer dashboard.

---

## Live vs mock summary

| Route | Live API |
|-------|----------|
| `/organizer` | Yes |
| `/organizer/events` | Yes |
| `/organizer/vendors` (Requests) | Yes |
| `/organizer/vendors` (Discover/Lists/Invites) | Mock |
| `/organizer/money` | Partial |
| `/organizer/events/[id]` | Mock |
| `/organizer/more` | Mock |

---

## Gaps (see APP_GAP_ANALYSIS)

- **Organizer auth:** `/login` is vendor-focused; organizer session/role gate not fully wired
- **Event workspace:** needs Phase 9
- **Create event / launch wizard:** handoff to WP `#create` or new BFF in Phase 9
- **CRM lists/invites:** mock until later phase

---

## Acceptance criteria (partial)

- [x] Organizer home loads from BFF when live API on
- [x] Events list from WordPress
- [x] Vendor requests review actions work
- [x] Vendor profile drawer from CRM REST
- [x] Financial snapshot read-only
- [ ] Event workspace live
- [ ] Organizer login flow
- [ ] Discover/Lists/Invites live

**Next:** [App Phase 5 — PWA + Launch QA](PHASE_5_LAUNCH_QA.md)

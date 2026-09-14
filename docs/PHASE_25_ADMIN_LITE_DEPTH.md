# Phase 25 — Admin Lite Depth

Production app: `lnk-up-marketplace-ui/` (Vercel)  
WordPress remains system of record.

**Status:** Built (executed)  
**Depends on:** Phase 15 Admin Lite, Phase 19 intelligence contracts  
**Roadmap:** [SYSTEM_OVERVIEW.md §14.2](SYSTEM_OVERVIEW.md#142-app-roadmap-phases-126) · Queue: [PHASES_22_26_ROADMAP.md](PHASES_22_26_ROADMAP.md)  
**Release checklist:** [PHASE_25_RELEASE_CHECKLIST.md](PHASE_25_RELEASE_CHECKLIST.md)

---

## Goal

Deepen Phase 15 ops cockpit with queue drilldowns, safer confirmed actions (or explicit WP handoffs), ops alert → Phase 19 recommendation linkage, and deterministic BFF `code` / partial meta — without becoming wp-admin or adding financial writes.

---

## Non-goals

- Full CRM/ops write surface in Next.js
- Financial writes / Money live port
- Replacing wp-admin for imports, merges, rewards, bulk tools

---

## Deliverables

### 1) WordPress thin REST (queue truth)

| Route | Purpose |
|-------|---------|
| `GET /external-events/admin/queue` | Pending review list via `list_admin` |
| `GET /external-events/admin/{id}` | Admin detail (unpublished ok) |
| `GET /verification/admin/summary` | Pending document counts |
| `GET /verification/admin/queue` | Pending document items |
| Existing | `GET /admin/connectors`, connector approve/pause/reject, external publish/reject, verification manual |

### 2) BFF contract fixes + depth

| BFF | Code | Notes |
|-----|------|-------|
| `GET /api/admin/summary` | `ok` \| `admin_summary_partial` | Connectors → `/admin/connectors`; verification → `/verification/admin/summary`; EE KPIs nested under `kpis` |
| `GET /api/admin/connectors` | `ok` \| `admin_connectors_partial` | Returns `items[]` + stats |
| `GET /api/admin/external-events` | `ok` \| `admin_external_events_partial` | Returns `items[]` + KPIs |
| `GET /api/admin/verification` | `ok` \| `admin_verification_partial` | Returns `items[]` + stats |
| `GET /api/admin/health` | `ok` \| `admin_health_degraded` | Probes connectors / EE / verification |
| Detail + action routes | `ok` \| `handoff_required` \| `admin_action_failed` | Confirmed safer actions |

### 3) UI drilldowns

| Page | Actions in-app | Handoff |
|------|----------------|---------|
| `/admin/connectors` + `/[id]` | approve / pause / reject | Rewards / program settings |
| `/admin/external-events` + `/[id]` | publish / reject | Import, sync, merge, notify |
| `/admin/verification` + `/[id]` | document approve / reject | Organizer tabs, file preview |
| `/admin` | Ops alerts → linked recommendations | `/ops/analytics` |

### 4) Verify

```bash
cd lnk-up-marketplace-ui
npm run phase25:verify
```

---

## Next

Phase 26 — Financial OS Expansion is **Built**. See [PHASE_26_FINANCIAL_OS_EXPANSION.md](PHASE_26_FINANCIAL_OS_EXPANSION.md).

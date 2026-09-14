# Phase 15 — Admin Lite / Internal Ops

Production app: `lnk-up-marketplace-ui/` (Vercel). WordPress remains system of record.

**Status:** Built  
**Depends on:** Phase 14 stabilization complete  
**Roadmap source:** `SYSTEM_OVERVIEW.md` §14.2 (Phase 15)

---

## Goal

Ship an env-gated admin cockpit that gives internal operators trustworthy read visibility for:
- marketplace KPIs,
- external-events queue health,
- verification queue,
- connector approvals,
- API/data freshness health.

No wp-admin replacement and no financial writes.

---

## Contracts (locked)

### `GET /api/admin/summary`

Returns:

```json
{
  "meta": {
    "live": true,
    "mocked": false,
    "partial": false,
    "stale": false,
    "source": "wordpress",
    "updatedAt": "2026-07-30T00:00:00.000Z",
    "lastUpdatedAt": "2026-07-30T00:00:00.000Z",
    "unavailableReasons": []
  },
  "kpis": {
    "activeVendors": 0,
    "activeOrganizers": 0,
    "liveEvents": 0,
    "applications": 0,
    "paidBookings": 0,
    "verificationQueue": 0,
    "openDisputes": 0
  },
  "queue": {
    "externalEventsPendingReview": 0,
    "verificationPending": 0,
    "connectorsPendingApproval": 0
  },
  "handoff": {
    "wpAdminUrl": "",
    "externalEventsUrl": "",
    "verificationUrl": "",
    "connectorsUrl": ""
  }
}
```

### `GET /api/admin/health`

Returns:

```json
{
  "meta": {
    "live": true,
    "mocked": false,
    "partial": false,
    "stale": false,
    "source": "wordpress",
    "updatedAt": "2026-07-30T00:00:00.000Z",
    "lastUpdatedAt": "2026-07-30T00:00:00.000Z"
  },
  "checks": [
    {
      "id": "marketplace_analytics",
      "label": "Marketplace analytics",
      "status": "pass",
      "message": "",
      "checkedAt": "2026-07-30T00:00:00.000Z"
    }
  ]
}
```

### `GET /api/admin/external-events`

Returns:

```json
{
  "meta": { "live": true, "mocked": false, "partial": false, "stale": false, "source": "wordpress", "updatedAt": "", "lastUpdatedAt": "" },
  "stats": { "pendingReview": 0, "imported24h": 0 },
  "handoffUrl": ""
}
```

### `GET /api/admin/verification`

Returns:

```json
{
  "meta": { "live": true, "mocked": false, "partial": false, "stale": false, "source": "wordpress", "updatedAt": "", "lastUpdatedAt": "" },
  "stats": { "queueCount": 0, "approved24h": 0 },
  "handoffUrl": ""
}
```

### `GET /api/admin/connectors`

Returns:

```json
{
  "meta": { "live": true, "mocked": false, "partial": false, "stale": false, "source": "wordpress", "updatedAt": "", "lastUpdatedAt": "" },
  "stats": { "pendingApprovals": 0, "activeConnectors": 0 },
  "handoffUrl": ""
}
```

---

## Access and safety

- `/admin/*` is env-gated by `ENABLE_QA_TOOLS` or `ENABLE_OPS_ANALYTICS`.
- All `/api/admin/*` routes require authenticated admin capability.
- Unauthorized responses:
  - `401` for signed-out users,
  - `403` for non-admin users.
- If WordPress routes are unavailable, return `meta.partial=true` + `unavailableReasons`.

---

## Build sequence (five slices)

1. Guardrails: env gates + admin session checks.
2. Summary: `/api/admin/summary` + `/admin` overview cards.
3. Drilldowns: external-events, verification, connectors pages + APIs.
4. Resilience: health route + partial/stale contract behavior + handoff URLs.
5. Verification: lint/build pass and follow-up E2E admin smoke.

---

## Files added/updated

- `lnk-up-marketplace-ui/lib/admin/phase15.ts`
- `lnk-up-marketplace-ui/lib/admin/access.ts`
- `lnk-up-marketplace-ui/app/api/admin/*`
- `lnk-up-marketplace-ui/app/admin/external-events/page.tsx`
- `lnk-up-marketplace-ui/app/admin/verification/page.tsx`
- `lnk-up-marketplace-ui/app/admin/connectors/page.tsx`
- `lnk-up-marketplace-ui/app/admin/page.tsx`
- `lnk-up-marketplace-ui/app/admin/layout.tsx`
- `lnk-up-marketplace-ui/app/admin/more/page.tsx`
- `lnk-up-marketplace-ui/lib/nav.ts`


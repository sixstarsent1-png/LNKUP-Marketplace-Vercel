# Phase 25 Release Checklist

Gate references:
- [PHASE_25_ADMIN_LITE_DEPTH.md](PHASE_25_ADMIN_LITE_DEPTH.md)
- [PHASE_15_ADMIN_LITE_INTERNAL_OPS.md](PHASE_15_ADMIN_LITE_INTERNAL_OPS.md)
- [PHASES_22_26_ROADMAP.md](PHASES_22_26_ROADMAP.md)

## Scope

- [x] Fix live BFF mappings (connectors, external events KPIs, verification summary)
- [x] Deterministic `code` + partial meta on admin queue BFFs
- [x] Queue item lists + detail pages for connectors / external events / verification
- [x] Safer confirmed actions where REST exists; handoff cards otherwise
- [x] Ops alert → recommendation linkage on `/admin`
- [x] Health probes expanded
- [x] Docs + `phase25:verify`

## Commands

```bash
cd lnk-up-marketplace-ui
npm run phase25:verify
```

## Manual smoke

1. Enable ops/admin gates; open `/admin` — queue counts + alerts panel.
2. Connectors queue → detail → confirm Approve (mock or live).
3. External events queue → detail → Publish / Reject confirm.
4. Verification queue → detail → Approve / Reject confirm.
5. Partial/unavailable reasons surface when WP endpoints fail.
6. Handoff cards open wp-admin pages.

## Go / No-Go

- **Go:** verify passes; drilldowns work in mock; live mappings no longer invent zero from wrong paths.
- **No-Go:** silent mock bleed under live, or write actions without confirmation.

## Next

Phase 26 — Financial OS Expansion is **Built**. See [PHASE_26_FINANCIAL_OS_EXPANSION.md](PHASE_26_FINANCIAL_OS_EXPANSION.md).

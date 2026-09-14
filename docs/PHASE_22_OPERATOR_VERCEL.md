# Phase 22 — Operator checklist: Vercel (web)

Complete before promoting `lnk-up-marketplace-ui` to production.

Set the Vercel project **Root Directory** to `lnk-up-marketplace-ui`. Do not set **Output Directory** to `.next` — that treats the App Router and `/api` BFF as static files.

| Check | Required | Done |
|-------|----------|------|
| Root Directory = `lnk-up-marketplace-ui` (Output Directory empty) | Yes | [ ] |
| `NEXT_PUBLIC_APP_URL` = production app URL (no trailing slash) | Yes | [ ] |
| `NEXT_PUBLIC_LNKUP_LIVE_API=true` | Yes for live | [ ] |
| `WORDPRESS_API_URL` points at production WP | Yes | [ ] |
| `SESSION_SECRET` ≥ 16 characters | Yes | [ ] |
| `NEXT_PUBLIC_WORDPRESS_SITE_URL` for handoffs | Yes | [ ] |
| `ENABLE_OPS_ANALYTICS` / admin gates match intended audience | Confirm | [ ] |
| Deploy succeeds (`npx vercel deploy --prod` or CI) | Yes | [ ] |
| Smoke `/vendor` (tutorial or home loads) | Yes | [ ] |
| Smoke `/organizer` (tutorial or home loads) | Yes | [ ] |
| Smoke payment return path (`?payment=success` or processing) | Yes | [ ] |
| Admin Lite `/admin` shows Live/Partial without silent mock KPI bleed | Yes | [ ] |
| Demo notices visible on `/vendor/growth`, `/admin/money` when fixtures shown | Yes | [ ] |

## Commands
```bash
cd lnk-up-marketplace-ui
# confirm env in Vercel dashboard, then:
npx --yes vercel env ls
```

## Rollback
- Set `NEXT_PUBLIC_LNKUP_LIVE_API=false` only for emergency demo; prefer previous deployment promote.
- Do not introduce Stripe writes in the Next.js app as a hotfix.

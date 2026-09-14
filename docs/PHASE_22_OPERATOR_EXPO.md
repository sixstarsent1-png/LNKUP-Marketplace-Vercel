# Phase 22 — Operator checklist: Expo (mobile)

Complete before / after production OTA or store cutover for `lnk-up-marketplace-mobile`.

| Check | Required | Done |
|-------|----------|------|
| Production EAS channel `production` | Yes | [ ] |
| `EXPO_PUBLIC_LNKUP_LIVE_API=true` in production builds / OTA | Yes | [ ] |
| `EXPO_PUBLIC_WORDPRESS_API_URL` = production WP | Yes | [ ] |
| `npm run phase20:verify` green | Yes | [ ] |
| OTA published after app code cutover (`npm run update:production -- --auto`) | Yes | [ ] |
| Store submit credentials / `eas submit` operator-owned | Per STORE_RELEASE | [ ] |
| Privacy / support URLs current in `app.json` / STORE_RELEASE | Confirm | [ ] |
| Smoke: vendor login → Find → Requests | Yes | [ ] |
| Smoke: organizer home intelligence card (if dual-role) | Confirm | [ ] |

## Commands
```bash
cd lnk-up-marketplace-mobile
npm run phase20:verify
npm run update:production -- --auto --message "Phase 22 launch hardening"
```

See also: [lnk-up-marketplace-mobile/STORE_RELEASE.md](../lnk-up-marketplace-mobile/STORE_RELEASE.md)

## Rollback
- Republish previous OTA group from Expo dashboard.
- Keep Financial OS in WordPress; never add Stripe SDK to Expo as a hotfix.

# Phase 4 — Push category prefs and app badge

Server-backed per-category push toggles plus app icon badge synced to the composed inbox unread count.

## Categories

User meta `lnkup_push_categories` (JSON). Defaults all `true`.

| Key | Push types |
|-----|------------|
| `applications` | `application_*`, `payment_required`, `organizer_new_vendor_request` |
| `payments` | `payment_paid`, `organizer_payment_received` (+ legacy) |
| `packets` | `packet_unlocked`, `system` with `/pass/` href |
| `discovery` | `discovery_*` |
| `account` | `activation_*`, `review_requested`, other `system` |

Master `pushEnabled` (`lnkup_push_enabled`) still short-circuits all push.

## API

`GET|PATCH /lnkup/v1/push/preferences`

```json
{
  "pushEnabled": true,
  "categories": {
    "applications": true,
    "payments": true,
    "packets": true,
    "discovery": true,
    "account": true
  }
}
```

PATCH accepts `pushEnabled` and/or partial `categories`.

## Mobile

- [`lib/api/push.ts`](../lnk-up-marketplace-mobile/lib/api/push.ts) — preferences shape
- [`components/push-preferences-card.tsx`](../lnk-up-marketplace-mobile/components/push-preferences-card.tsx) — master + 5 category switches
- [`lib/push/notifications.ts`](../lnk-up-marketplace-mobile/lib/push/notifications.ts) — `syncAppBadge` / `clearAppBadge`, `shouldSetBadge: true`
- Home + notifications screens sync badge from unread; mark-all clears badge
- `PushBootstrap` refreshes badge on foreground notification receive

## Out of scope

WP inbox REST, SMS, per-email category prefs, external outreach push.

## Related

- [PUSH_NOTIFICATIONS_DEPTH.md](PUSH_NOTIFICATIONS_DEPTH.md)
- [PHASE_RETENTION_PUSH.md](PHASE_RETENTION_PUSH.md)

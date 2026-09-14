# Phase 3 — Retention emails and push

Push parity + branded CTA polish for discovery/retention notifications that were previously email-only.

## Covered methods

| Method | Push type | Mobile href |
|--------|-----------|-------------|
| `send_saved_search_match` | `discovery_saved_search` | `/(vendor)/saved-searches` |
| `send_favorite_deadline_reminder` | `discovery_favorite_deadline` | `/(vendor)/favorites` |
| `send_favorite_organizer_new_event` | `discovery_favorite_organizer` | `/(vendor)/find` |
| `send_deadline_approaching` | `discovery_deadline` | `/(vendor)/find` |
| `send_almost_full` | `discovery_almost_full` | `/(vendor)/find` |
| `send_profile_match` | `discovery_profile_match` | `/(vendor)/find` |
| `send_vendor_invite` | `discovery_invite` | `/(vendor)/find` |

Emails reuse `email_paragraphs` / `email_cta_block`. Saved-search digests keep opportunity list cards.

## Gates (unchanged)

- `notify_vendor`
- `LNKup_Saved_Searches::alerts_enabled()` for saved search
- `deadline_reminder_favorites` for favorite deadline reminders
- Push also requires device registration + `lnkup_push_enabled`

## Code

- [`includes/class-notifications.php`](../wp-content/plugins/lnkup-marketplace/includes/class-notifications.php)
- [`lnk-up-marketplace-mobile/lib/push/notifications.ts`](../lnk-up-marketplace-mobile/lib/push/notifications.ts)

## Out of scope

WP inbox REST, per-category prefs, SMS, app badge, external-event / platform outreach push, activation drip changes.

## Related

- [PUSH_NOTIFICATIONS_DEPTH.md](PUSH_NOTIFICATIONS_DEPTH.md)
- [PHASE_ACTIVATION_EMAILS.md](PHASE_ACTIVATION_EMAILS.md) (Phase 2)

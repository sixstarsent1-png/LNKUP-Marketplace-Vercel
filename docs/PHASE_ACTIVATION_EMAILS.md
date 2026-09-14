# Phase — Activation emails and push

Checklist-driven welcome and setup drips for vendors and organizers (including users who signed up before in-app tutorials).

WordPress remains the system of record. Delivery uses `LNKup_Notifications` (email) + `LNKup_Push_Service` (Expo).

## Cadence

### Vendor

| Step | When | Condition | CTA |
|------|------|-----------|-----|
| Welcome | Register / role assign | Once; skip if registered &gt;1 day | Profile |
| Profile | Daily cron, ≥2 days | Strength &lt; 70; not sent | Vendor profile |
| Find events | Daily cron, ≥5 days | 0 apps and 0 opportunity favorites; not sent | Find Events |
| Apply | Daily cron, ≥10 days | 0 apps; not sent | Find Events |

### Organizer

| Step | When | Condition | CTA |
|------|------|-----------|-----|
| Welcome | Register / role assign | Once; skip if registered &gt;1 day | Create event |
| Create event | Daily cron, ≥3 days | 0 owned events; not sent | Dashboard `#create` / Launch |
| Review vendors | Daily cron, ≥7 days | ≥1 event; pending apps or 0 invites; not sent | Applications / Vendors |

**Backfill:** Existing incomplete accounts are included by the daily job (any registration age). Welcome is not backfilled.

**Batch:** One drip email max per user per daily run. Default 100 users/day (`activation_daily_batch`). Users marked `lnkup_activation_vendor_done` / `lnkup_activation_organizer_done` are skipped.

## Settings

| Key | Default |
|-----|---------|
| `activation_emails_enabled` | true |
| `activation_daily_batch` | 100 |
| Also requires | `notify_vendor` / `notify_organizer` |

Admin: LNKup Settings → Email Notifications.

## Code

| Piece | Path |
|-------|------|
| Orchestrator | `includes/class-activation-emails.php` |
| Templates + push | `includes/class-notifications.php` (`send_vendor_*` / `send_organizer_*`) |
| Cron | `LNKup_Marketplace_Cron::run_daily()` → `LNKup_Activation_Emails::run_daily()` |
| Mobile deep links | `lnk-up-marketplace-mobile/lib/push/notifications.ts` |

## Dedupe meta

- `lnkup_activation_welcome_sent`
- `lnkup_activation_profile_sent` / `find_events_sent` / `apply_sent`
- `lnkup_activation_create_event_sent` / `org_review_sent`
- `lnkup_activation_vendor_done` / `organizer_done`

## Out of scope

- Per-category prefs, WP inbox REST, SMS, long multi-week drips, last-login inactivity tracking

## Related

- [PUSH_NOTIFICATIONS_DEPTH.md](PUSH_NOTIFICATIONS_DEPTH.md)
- Vendor checklist: `LNKup_Vendor_Getting_Started::PROFILE_READY_MIN` (70)

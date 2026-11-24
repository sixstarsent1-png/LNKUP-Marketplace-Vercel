# Rollback — Event Creation OS 3.0 (12.40.0)

## Fast path (flags only)

Turn off every Event OS flag in `lnkup_settings` (or LNKup settings UI):

- `event_builder_v3`
- `event_import_v3`
- `event_import_bulk`
- `eventbrite_oauth`
- `event_source_sync`
- `portfolio_builder_v3`

Create / Edit return to the legacy UI. Draft CPTs and import job rows stay in the DB harmlessly.

## Plugin rollback

1. Re-upload the previous zip (12.39.0) or rsync the prior tag / SHA.
2. Purge CDN for organizer dashboard assets.
3. Leave Event OS flags off until the next ship.

Do **not** drop import tables or draft posts unless support explicitly requests a cleanup — they are additive and unused when flags are off.

## Mobile

Roll back the Expo OTA channel to the previous update if create/import screens misbehave after a flag enable. WordPress remains the system of record.

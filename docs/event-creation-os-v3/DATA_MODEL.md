# Data model

Authoritative records stay where they are: `lnkup_event` and `lnkup_opportunity` posts + meta, portfolio CPTs (`lnkup_tour`, `lnkup_event_template`, `lnkup_brand`). New storage holds only creation state and external-source bookkeeping.

## Builder draft (`lnkup_event_draft`, private CPT)

| Field | Storage |
|---|---|
| Owner | `post_author` + `_lnkup_organizer_user_id` |
| Title | `post_title` (or "Untitled event") |
| State | `_lnkup_builder_state` JSON (≤ 200 KB, validated by `LNKup_Event_Builder_Schema`) |
| Revision | `_lnkup_builder_revision` (int, increments on every save; PATCH must send the current revision) |
| Method / step | inside state |
| Edit target | `_lnkup_builder_source_event` (Edit 2.0) |
| Published event | `_lnkup_builder_event_id` after publish (draft is then trashed) |

State shape (abridged):

```json
{
  "method": "single|recurring|tour|series|import|duplicate|template|bulk",
  "step": "info|options|applications|payments|publish",
  "event": { "name": "", "event_type": "", "brand_id": 0, "summary": "", "description": "",
    "attendance": { "mode": "unknown|estimate|range", "value": 0, "min": 0, "max": 0 },
    "format": "outdoor|indoor|both|tba",
    "date": "Y-m-d", "end_date": "", "start_time": "", "end_time": "", "timezone": "America/New_York", "date_tba": false,
    "venue_name": "", "venue_address": "", "city": "", "state": "", "country": "US", "postal_code": "",
    "venue_tba": false, "venue_hidden": false, "market_city": "", "location_confirmed": false,
    "featured_image_id": 0, "flyer_id": 0, "gallery_ids": [] },
  "sources": [ { "url": "", "provider": "", "primary": true, "mode": "link|import" } ],
  "options": [ { "key": "", "name": "", "category": "", "fee": 0, "spots": 0, "width_ft": 0, "depth_ft": 0,
    "amenities": [], "description": "", "deadline": "", "power": false } ],
  "applications": { "mode": "review|invite|closed", "questions": [], "documents": { "mode": "none|platform_defaults|custom", "items": [] } },
  "payments": { "deposit": { "enabled": false, "type": "percent|fixed", "value": 0 }, "cancellation_note": "", "deadline": { "mode": "fixed|relative", "date": "", "days_before": 7 } },
  "publish": { "when": "now|schedule", "at": "", "applications": "open|closed" },
  "provenance": { "event.name": "imported|edited|template" },
  "import": { "provider": "", "external_event_id": "", "original_url": "" }
}
```

## `{prefix}lnkup_event_sources`

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| event_id | bigint | LNKup event (0 while only on a draft) |
| draft_id | bigint | builder draft |
| organizer_id | bigint | scope |
| provider | varchar(32) | registry key |
| external_event_id / external_occurrence_id / external_series_id | varchar(100) | |
| url | varchar(500) | original |
| url_hash | char(40) | sha1 of canonical URL |
| is_primary | tinyint | one per event |
| mode | varchar(10) | link / import / account |
| ownership_state | varchar(20) | attested / verified_account / verified_domain / conflict / pending_review |
| availability | varchar(20) | ok / broken / unknown |
| last_sync_at / last_attempt_at | datetime | |
| source_version | varchar(64) | provider changed timestamp or content hash |
| provenance | text JSON | field → imported value snapshot |
| pending_changes | text JSON | detected changes awaiting review |
| created_at / updated_at | datetime | |

Unique: (`url_hash`, `organizer_id`), (`provider`, `external_event_id`, `external_occurrence_id`, `organizer_id`). Index: `event_id`.

## `{prefix}lnkup_import_jobs` / `{prefix}lnkup_import_job_items`

Jobs: id, organizer_id, user_id, method (urls/csv/ics/eventbrite), status (queued/processing/completed/completed_warnings/failed/cancelled), totals (items, created, linked, failed, skipped), file_name, options JSON, created/updated. Items: id, job_id, row_key (idempotency, unique per job), input JSON, status (pending/ready/created/linked/skipped/failed), draft_id, event_id, error, attempts.

## `{prefix}lnkup_provider_connections`

id, organizer_id, provider, account_label, external_org_id, token (sodium-encrypted), refresh_token (encrypted), expires_at, scopes, status, last_sync_at, created/updated. Unique (organizer_id, provider). Tokens never leave the server.

## Event meta added

`_lnkup_builder_draft_id` (origin draft), `_lnkup_event_format`, `_lnkup_attendance_mode`, `_lnkup_attendance_min/_max`, `_lnkup_event_timezone`, `_lnkup_event_end_date`, `_lnkup_market_city` (marketing market when different from the physical city), `_lnkup_ownership_state`.

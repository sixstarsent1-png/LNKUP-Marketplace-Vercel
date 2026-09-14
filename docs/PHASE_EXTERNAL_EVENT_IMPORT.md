# Organizer Event Import (Eventbrite / Posh / RSS)

**Status:** Built  
**Layer:** WordPress plugin (`lnkup-marketplace`) — system of record  
**Apps:** Handoff only (no Next.js/Expo import UI in this slice)

---

## Goal

Let organizers paste an Eventbrite event URL, Posh event URL, or RSS/Atom feed URL on **Create Event** and prefill title, description, date/time, city/state, and venue. Admin External Events import uses the same providers for discovery rows.

---

## Mechanisms

| Source | How | Notes |
|--------|-----|--------|
| Eventbrite | HTML / JSON-LD scrape (existing) | No OAuth in this slice |
| Posh | HTML / JSON-LD / Open Graph scrape | **No public REST API or native RSS** from Posh |
| RSS / Atom | WordPress `fetch_feed()` | Picks next upcoming item (or `?item=N`) |

Attendee ticketing stays on the external platform. LNKup only imports **listing metadata**.

---

## Organizer flow

1. Organizer opens Create Event → **Import from ticketing URL**.
2. `POST /lnkup/v1/external-events/organizer/import-url` (preview only; rate-limited).
3. JS prefills `#lnkup-create-event-form` and stores `ticketing_url`.
4. Organizer completes Vendors step and publishes → `lnkup_event` with `_lnkup_ticketing_*` and `_lnkup_external_source_url`.

Organizer import does **not** create admin `pending_review` external rows.

---

## Admin flow

- **LNKup → External Events → Import:** paste Eventbrite / Posh / RSS URL → creates pending-review SQL row (unchanged review → claim path).
- Providers list: `manual`, `csv`, `eventbrite`, `posh`, `rss`, `google` active; Universe / Humanitix / city calendars still planned.
- **Google Search ingest:** Custom Search JSON API + city × keyword combinator. New rows stay `pending_review` (`source_platform = google`). Configure under **Auto sync** (CSE key, cx, templates, daily budget). Do not scrape Google SERP HTML.

---

## Key paths

| Path | Role |
|------|------|
| `includes/external-sources/class-external-source-posh.php` | Posh provider |
| `includes/external-sources/class-external-source-rss.php` | RSS provider |
| `includes/external-sources/class-external-sources.php` | Registry + detect |
| `includes/external-sources/class-external-source-google.php` | Google Search discovery |
| `includes/class-search-query-combinator.php` | City × keyword templates |
| `includes/class-search-client-google-cse.php` | Official CSE JSON client |
| `includes/class-page-extractor.php` | Generic HTML / JSON-LD extract |
| `includes/class-external-events-rest.php` | `organizer/import-url` + admin `import-url` |
| `public/js/marketplace.js` | Real import preview (no fake timeout) |
| `public/class-shortcodes.php` | Import panel copy |

---

## Non-goals / follow-ons

- Eventbrite private REST / OAuth
- Universe / Humanitix providers
- Posh discovery cron
- Auto-create opportunity on import
- Full app/mobile import UI
- Scraping Google SERP HTML (use Custom Search JSON API only)

---

## Verify

```bash
node scripts/phase-external-event-import-verify.mjs
```

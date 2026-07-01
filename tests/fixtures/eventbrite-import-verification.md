# Eventbrite URL import — verification

Parser: `LNKup_External_Source_Eventbrite` in `wp-content/plugins/lnkup-marketplace/includes/external-sources/class-external-source-eventbrite.php`

Uses **HTML fetch + JSON-LD** (with `__NEXT_DATA__` fallback). oEmbed is not used.

## Fixture

`eventbrite-event-page.html` — sample page with JSON-LD `Event` and `__NEXT_DATA__` blocks for local parser checks.

## Manual admin test

1. wp-admin → LNKup → External Events → **Import** tab
2. Paste a public Eventbrite event URL (`https://www.eventbrite.com/e/...`)
3. Submit **Import URL (pending review)**
4. Expect success notice and row in **Review queue** with title, city, date, image (or warning), event type, and organizer email field (add email before approve for outreach)
5. **Approve & publish** after filling organizer email if missing
6. Find Events → card shows image/illustration, event type badge, organizer name, category chips
7. Paste a bogus URL → expect **Eventbrite event not found** or **Could not extract event data**, not a generic parse error

## Parser smoke test

`php tests/php/eventbrite-parser-test.php` (requires WordPress bootstrap or stub classes)

## REST preview

```http
POST /wp-json/lnkup/v1/external-events/import-url
Content-Type: application/json

{"url":"https://www.eventbrite.com/e/your-event-slug-tickets-123456789012","preview":true}
```

Requires admin auth (`lnkup_manage_all`).

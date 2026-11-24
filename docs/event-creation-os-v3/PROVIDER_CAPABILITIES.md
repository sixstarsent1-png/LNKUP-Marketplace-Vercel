# Provider capabilities (verified)

Levels: **A** authorized account integration via official API · **B** direct URL import from permitted structured metadata (Schema.org JSON-LD / Open Graph) · **C** link attachment, metadata extraction best-effort · **D** manual / file import.

LNKup never uses undocumented endpoints, never logs in as the organizer and never imports attendee prices, ticket inventory or attendee data.

| Provider | Level in LNKup | Official API | Auth | What LNKup imports | Limitations | Status |
|---|---|---|---|---|---|---|
| Eventbrite | A + B | Yes (v3: users/me/organizations, organizations/{id}/events, events/{id}?expand=venue) | OAuth 2 (app client ID/secret) | Title, summary/description, start/end with timezone, venue/address, image, status, series IDs | Needs `LNKUP_EVENTBRITE_CLIENT_ID/SECRET`; rate limits per app | A: built, credential-gated (not verified live). B: JSON-LD |
| POSH | B/C | No public event API | — | JSON-LD/OG when present: title, image, dates, venue | Third-party-link events are listings; data entered manually by organizer on Posh | URL import + link |
| Eventeny | C | Tickets API only (Pro/Premium) | API key | OG/JSON-LD metadata | No event/vendor API for import | Link + metadata |
| TicketLeap | C | Legacy read-only, no HTTPS | — | OG/JSON-LD metadata | No supported integration | Link + metadata |
| Luma | B (A possible) | Yes (`/v1/calendars/events/list`) | Per-calendar API key, Luma Plus | JSON-LD/OG via URL | Account import needs organizer's paid key; not built this round | URL import |
| Marketspread, Ticket Tailor, Humanitix, Partiful, DICE, Tixr, Fever, Shotgun, Resident Advisor, Ticketmaster, AXS, SeatGeek, Eventzilla, Cvent, Whova, Meetup, Facebook Events | C | Varies; not integrated | — | JSON-LD/OG if published by the page | Facebook/Meetup often require login → link only | Link + metadata |
| Organizer website | B | — | — | JSON-LD Event, OG | Depends on markup | URL import |
| ICS / Google Calendar export | D | iCalendar (RFC 5545) | File upload / public ICS URL | VEVENT summary, start/end, TZID, location, RRULE (expanded, capped) | Location is free text → validated | Built |
| CSV | D | — | File upload | Mapped columns | 500 rows per file | Built |

Capability per provider in code: `LNKup_Import_Provider_Registry::all()` (domains, URL patterns, level, auth, bulk, recurring, sync, media, limitations, flag, health). Admins can disable any provider without affecting manual creation.

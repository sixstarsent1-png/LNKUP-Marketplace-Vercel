# Competitive research

Sources are public documentation and marketing pages read on 2026-11-24. No authenticated competitor interface was accessed. "Verified" = stated in the provider's own documentation; "Inspiration" = design principle only.

| Platform | Relevant workflow | Design principle | Existing LNKup equivalent | Proposed improvement | Basis |
|---|---|---|---|---|---|
| POSH | Create Event → "External tickets": paste a supported ticketing link, enter name/image/description/venue/date manually, publish; Posh tracks page views and Get Tickets clicks, not purchases; web-only for now | Keep ticketing elsewhere; the listing links out and is honest about what is tracked | Single `ticketing_url` on the event | "Official event listing" section with multiple sources and a primary link; count external clicks, never purchases | Verified (support.posh.vip "Third-Party Links", Oct 2026) |
| POSH | Drafts are invisible outside the organization | Drafts never public | Draft = published post with no slots | Real private drafts | Verified |
| Eventbrite | OAuth; `GET /v3/users/me/organizations/` then `GET /v3/organizations/{id}/events/` with `status`, `time_filter`, `series_filter`, `name_filter`, pagination | Org picker → event browser with status filters | None | Credential-gated Eventbrite connection + event browser | Verified (eventbrite.com/platform/docs) |
| Eventbrite | Guided setup, duplicate, series parent/children | Guided steps with clear status | Legacy wizard | Five-step builder with live preview | Inspiration |
| TicketLeap | Simple setup, multiple dates per event | Bulk date management | Portfolio recurrence (flag) | Calendar preview with per-date exceptions | Inspiration (official API is legacy/read-only; no integration) |
| Eventeny | Festival vendor applications, booth configurations, documents, embeds; API = ticket data only (Pro/Premium) | Vendor-first configuration | Opportunity slots + questions | Option cards, document timing, application preview | Verified API scope (help.eventeny.com) |
| Marketspread | Recurring markets, reusable vendor application templates, multi-date applications | Templates applied to many dates | Portfolio tour defaults + templates | One template library; relative deadlines | Inspiration |
| Luma | Minimal creation; calendar API (`/v1/calendars/events/list`) with per-calendar API keys, Luma Plus required, 200 req/min | One-screen quick create | None | Quick Create; Luma via URL metadata (API needs organizer's key + paid plan) | Verified (docs.luma.com) |
| Shopify | Product → variants → inventory | Options with price + capacity, collapsible advanced | Repeatable booth rows | Option cards + bulk table | Inspiration |
| Stripe | Transparent fee breakdown before charge | Show money math from the authoritative engine | Commission % only | Fee preview per option from `LNKup_Fee_Engine` | Inspiration |
| Airtable | Grid editing, import review | Spreadsheet review with per-row status | None | Bulk import / tour stop tables | Inspiration |
| Notion | Templates, progressive disclosure | Advanced settings hidden until needed | Dense form | Simple/advanced toggles per option | Inspiration |
| Canva | Visual preview while editing | See the result immediately | None | Marketplace card preview rail | Inspiration |
| Linear | Fast keyboard navigation, crisp states | Clear saving/saved/error states | None | Autosave status pill, keyboard-friendly stepper | Inspiration |
| Partiful / DICE / Tixr / Humanitix / Ticket Tailor / Cvent / Manage My Market | Event pages and ticketing | Link-out only | `ticketing_url` | Recognized as Level C providers (link + permitted metadata) | Inspiration |

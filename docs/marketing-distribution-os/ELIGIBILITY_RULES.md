# Market and landing page eligibility rules

Pages are data-driven from the market registry (`lnkup_city_markets`) and the marketplace index. Nothing creates pages in bulk; every city + category page is enabled by staff per pair.

## Market status (computed, `LNKup_Market_Registry::status()`)

| Status | Rule |
|---|---|
| `inactive` | Market disabled or launch status `paused` |
| override | Staff can pin `status_override` to `active`, `emerging`, `prelaunch` or `inactive` |
| `active` | 3 or more distinct open events in the city (published, spots left, date not past) |
| `emerging` | At least one open event, or launch status recruiting / beta / active |
| `prelaunch` | Otherwise |

Health numbers (open events, open spaces, active organizers, vendors based there) are counted live, never estimated. A daily snapshot is stored for trends.

## City market page `/markets/{city}/`

- Indexable per SEO_URL_STRATEGY.md (inventory or ≥300 characters of unique copy).
- Sections render only when they have data: open events, categories with counts, featured organizers (organizers with open events in the city), nearby markets (staff-listed `nearby` slugs or markets within 300 miles), and staff-written `content`. Empty markets show an honest empty state with a market alert sign-up; no placeholder numbers.

## City + category page `/markets/{city}/{category}/` (flag `market_category_pages`)

All of:
1. The category is in the market's `category_pages` list (staff choice in LNKup → City Markets).
2. The city market itself is indexable.
3. At least **3** open events in that city carry the category.

Otherwise the URL renders `noindex` with a canonical to the city page (or 404 when the category is unknown).

## Weekly digest `/markets/{city}/this-week/`

Open events in the next 14 days. Shown when at least 3 qualify; always `noindex, follow` (it is a shareable list, not a search landing page).

## Partner pages `/with/{slug}/`

Only for partner organizations at the agreement stage or later (`agreement`, `active`, `performance_review`, `renewal`). Moving the partner back closes the page. Always `noindex`. Optional market and category must exist.

## Cohort pages `/cohort/{slug}/`

Created in Command Center → Content. Must be marked live; always `noindex`. The interest form is open only while Organizer Growth is on (requests become organizer leads, source `cohort`), and requires consent, a honeypot pass and at most 5 submissions per IP per day.

## Resources hub `/resources/`, `/guides/{slug}/` (flag `resources_hub`)

Posts in the categories `vendor-guides` and `organizer-guides`. A guide can only be published by a non-administrator when the editorial checklist is complete (SEO reviewed, facts checked, legal review if flagged); otherwise it is held as pending. Six outline drafts can be created once; they are never published automatically.

## Campaign landings (existing)

`/for-organizers/{slug}/` and `/for-vendors/{slug}/` stay `noindex` with a canonical to the main landing by default (unchanged).

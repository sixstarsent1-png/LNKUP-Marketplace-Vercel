# SEO URL and canonical strategy

Flag: `seo_index_policy`. With the flag off, URLs and robots output are unchanged.

## Canonical URLs

| Page | Canonical | Old forms (301 to canonical, campaign/referral query kept) |
|---|---|---|
| Event | `/events/{slug}/` | `/lnkup_event/{slug}/` |
| Opportunity | Its event page with `#opp-{id}` | `/lnkup_opportunity/{slug}/` (singles are never indexed) |
| Organizer profile | `/organizers/{slug}/` | `/organizers/?organizer={slug}` |
| Tour / series | `/tours/{slug}/` (or `/series/{slug}/`) | Wrong prefix redirects to the stored one |
| City market | `/markets/{city}/` | — |
| City + category | `/markets/{city}/{category}/` | — (only when eligible; otherwise noindex with canonical to the city page) |
| Weekly digest | `/markets/{city}/this-week/` | — (noindex, follow) |
| Guide | `/guides/{slug}/` | Default post permalink |

Query parameters kept through a redirect: `utm_*`, `ref`, `org_ref`, `cc`, `partner`, `fbclid`, `gclid`, `ttclid`, `lnk`, `apply`, `organizer`. Everything else is dropped. Canonicals never contain tracking parameters.

## Robots decisions (`LNKup_Index_Policy`)

Each public page type has one function that returns `{index, sitemap, reason}`. The same decision drives the robots meta tag, the sitemap entries and the Event schema, so they cannot disagree.

| Type | Indexable when | Otherwise |
|---|---|---|
| Event | Published, no password, marketplace open, not cancelled, authenticity not blocked (`unclaimed_external`, `claim_pending`, `disputed`, `restricted`), has title + city + date, last day not in the past | `noindex`. Past events stay `noindex, follow` for 90 days, then drop out |
| Market | Enabled, indexable, not paused or inactive, and has open events **or** ≥300 characters of staff-written copy | `noindex` (thin) |
| City + category | See ELIGIBILITY_RULES.md | `noindex` |
| Tour | Published, Portfolio OS on for the owner, at least one upcoming stop | `noindex` |
| Organizer | Organizer role with at least one published event | `noindex` |
| Vendor Passport | Public passport only | `noindex` |

## Structured data

- `Event`: ISO start/end with timezone offset, `eventStatus` (Scheduled, Cancelled, Postponed, Rescheduled with `previousStartDate`), `Place` (venue name/address only when not hidden), organizer. **No `offers`**: vendor booth fees are not attendee admission prices.
- `BreadcrumbList` on events, markets, category pages and guides; `Organization`/`ProfilePage` on organizer profiles; `Article` on guides; `ItemList` on market pages.
- Events that are `noindex` emit no Event schema.

## Sitemaps

Provider `lnkup` in core sitemaps (`/wp-sitemap-lnkup-{events|markets|tours|organizers|vendors}-N.xml`) with real `lastmod`; opportunity singles and app pages are excluded. With Rank Math active, the same policy filters Rank Math's sitemap.

## Diagnostics

`GET lnkup/v1/marketing/seo/diagnostics` and Command Center → SEO list indexable counts and issues with samples: policy off, plain permalinks, search engines discouraged, rewrite rules missing, no sitemap, thin event descriptions, events without an uploaded image, duplicate event titles, incomplete events, thin markets.

# Organizer marketing toolkit

Organizer dashboard → **Marketing** (`?view=marketing`, flag `organizer_marketing`). Available to the event owner and team members with the event-level `broadcast` capability. Everything is generated from the organizer's own event data; nothing is auto-posted.

## Tabs

| Tab | What it does | Backed by |
|---|---|---|
| Share | Public event link (copy / native share) and **tracked links** per channel (Instagram, Facebook, TikTok, X, LinkedIn, Email, Text, WhatsApp, QR, Flyer, Website, Partner, Other). Each link shows clicks, applications and bookings; pause, archive, copy, local QR (PNG/SVG) | `organizer/marketing/links`, `LNKup_Tracking_Links` |
| Graphics | Canvas templates rendered in the browser: Applications open, Calling a vendor category, Final spaces (1–15 left), Closing soon, Now booking (categories), Save the date, Free to apply, Tour. Formats 1080×1350, 1080×1080, 1080×1920, 1200×630. Templates whose data is missing explain why instead of rendering | `organizer-marketing.js` |
| Copy | Editable copy for Instagram, Facebook group, Email, Text, Deadline reminder, Food truck call, Partner ask, filled with event facts; "Use a tracked link" swaps in the matching channel link | `LNKup_Organizer_Marketing::copy_templates()` |
| Embed | Website snippet listing the event's (or tour's) open spaces from the public embed API; pricing and inventory stay behind sign-in | `public/embeds/*`, `embed-list.js` |
| Performance | Per-link and total clicks, signups, applications and bookings for the window, plus recommendations derived from real counts (no links yet, views without applications, a link with clicks but no applications, deadline soon with spaces left, unfilled categories, missing event photo) | `organizer/marketing/performance` |

## QR codes

Generated locally (vendored MIT `qrcode-generator`, PHP port in `includes/vendor/qrcode.php`). All `api.qrserver.com` usages were removed, so no event URL is sent to a third party.

## Rules

- Links can only target the organizer's own published events, tours and profile, or public LNKup pages; the redirect is resolved from the stored target at click time (no open redirect).
- Bots and link previews are counted separately; repeat clicks from the same browser on the same day count once.
- Up to 500 non-archived links per owner.

## Related toolkits

- **City Connectors / partners** — dashboard → Share & Track: same links, forwarding the connector's existing referral code (`?ref=`), so the existing program validates and credits as before.
- **Vendors** — after a paid booking, an opt-in share card on the booking detail page gives a reusable tracked link to the event (no personal data in the preview).
- **Partners** — `/with/{slug}/` pages for approved partner organizations, managed in Command Center → Distribution.

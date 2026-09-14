# Phase 9 — Organizer Conversion + Event Launch Flow

Production Next.js app: `lnk-up-marketplace-ui/` (Vercel). WordPress remains source of truth.

## Overview

Phase 9 helps organizers convert from interest → account → first event → first vendor opportunity. It adds organizer auth polish, conversion landing, onboarding, guided launch flow, live event workspace, and share/handoff UX—without porting the full WordPress dashboard or Financial OS into Next.js.

## Environment variables

| Variable | Required | Notes |
|----------|----------|-------|
| `NEXT_PUBLIC_LNKUP_LIVE_API` | Yes | `true` = BFF → WordPress; `false` = mock organizer fixtures |
| `WORDPRESS_API_URL` | Yes (live) | WordPress REST base |
| `NEXT_PUBLIC_WORDPRESS_SITE_URL` | Yes | Public site + organizer dashboard handoff |
| `SESSION_SECRET` | Yes (live auth) | Encrypts httpOnly session cookie |

## Routes

| Route | Purpose |
|-------|---------|
| `/organizers` | Organizer conversion landing |
| `/organizer/onboarding` | Lightweight first-time setup |
| `/organizer/launch` | Guided event + opportunity wizard |
| `/organizer/events/[id]` | Live event workspace |
| `GET /api/auth/me` | Session user with roles |
| `POST /api/auth/login` | Login + role normalization |
| `GET/POST /api/organizer/onboarding` | Onboarding progress (cookie) |
| `GET/POST /api/organizer/launch/draft` | Launch draft (cookie; seeds from onboarding) |
| `POST /api/organizer/launch/publish` | Publish or WordPress handoff |
| `GET /api/organizer/events/[id]` | Event workspace aggregation |
| `GET /api/organizer/events/[id]/share` | Shareable application link |

## Organizer auth

- Same encrypted httpOnly session as vendors (`lnkup_wp_session`)
- Roles normalized from `GET /wp/v2/users/me?context=edit`
- Post-login: organizer → `/organizer`, vendor → `/vendor`, `?redirect=` preserved
- Wrong-role gates: `OrganizerGate` + `OrganizerRoleGate`, `VendorGate` + `VendorRoleGate`
- Public vendor browse (`/vendor/find`, `/vendor/map`) bypasses `VendorGate`

## Launch publish behavior

| Mode | Behavior |
|------|----------|
| Mock (`LIVE_API=false`) | Simulated publish → `/organizer/events/{id}` |
| Live + WP REST | `POST /lnkup/v1/organizer/launch/publish` creates event + opportunity |
| Live + WP route 404 | `handoff_required` → WordPress `#create` — never fake success |

### WordPress `POST /organizer/launch/publish`

**Request body** (from BFF `draftToWpPayload`):

```json
{
  "event_name": "Charlotte Night Market",
  "event_date": "2026-09-15",
  "city": "Charlotte",
  "state": "NC",
  "vendor_fee": 200,
  "spots_total": 20,
  "categories": ["Food Vendor"],
  "document_mode": "platform_defaults"
}
```

**Response (success):**

```json
{
  "event_id": 123,
  "opportunity_id": 456,
  "view_event_url": "https://thelnkup.com/organizer-dashboard/#events",
  "application_url": "https://thelnkup.com/opportunities/?q=..."
}
```

Implemented in `wp-content/plugins/lnkup-marketplace/includes/class-organizer-launch-rest.php` via `LNKup_Organizer_Onboarding`.

## WordPress routes expected (read)

| WP route | BFF usage |
|----------|-----------|
| `GET /operations/events/{id}` | Event workspace meta |
| `GET /crm/events/{id}/roster` | Request counts |
| `GET /crm/events/invite-options` | Events list (`items` array) |
| `GET /crm/launch-checklist` | Home checklist (`title`, `status`, `cta_url`) |
| `GET /intelligence/organizer/summary` | Home stats |

## WordPress routes (write)

| WP route | Status |
|----------|--------|
| `POST /organizer/launch/publish` | **Implemented** — event + first opportunity |
| `POST /organizer/events/{id}/opportunities` | Not implemented — use WP handoff |

## Handoff rules

Use WordPress for: packet/logistics editing, financial setup, maps, CSV import, mass invites, deep operations.

CTA labels: "Open WordPress Dashboard", "Finish in WordPress", "Manage packets in WordPress", "Open Financial Center"

## Financial OS boundary

- Next.js shows **read-only** payment snapshots from `GET /api/organizer/financial`
- No Stripe SDK, PaymentIntents, or Financial OS writes in Next.js
- All settlement/payout logic stays in WordPress `LNKup_Financial_Service`

## Validation (launch publish)

- Event title, date, city, state required
- At least one vendor category
- Vendor fee ≥ 0, spots total ≥ 1
- Documents mode: `none` | `platform_defaults` | `custom`

## Analytics events

| Event | Trigger |
|-------|---------|
| `organizer_landing_viewed` | `/organizers` page load |
| `organizer_join_clicked` | Join CTA click |
| `organizer_onboarding_started` | Onboarding page load |
| `organizer_onboarding_step_completed` | Onboarding Continue/Skip |
| `launch_event_started` | Launch wizard load |
| `launch_event_draft_saved` | Save draft |
| `launch_event_publish_clicked` | Publish button |
| `launch_event_published` | Mock/live publish success |
| `launch_event_handoff_clicked` | WP handoff shown |
| `organizer_event_workspace_viewed` | Event workspace load |
| `application_link_copied` | Share copy button |
| `opportunity_preview_clicked` | Preview as vendor |
| `wordpress_dashboard_opened` | WP dashboard CTA |

## Build and tests

```bash
cd lnk-up-marketplace-ui
npx next build

cd ..
PLAYWRIGHT_APP_URL=http://localhost:3000 npx playwright test tests/e2e/lnkup-app-phase9-organizer-launch.spec.ts --project=lnkup-app
```

## Manual QA checklist

- [ ] `/organizers` hero + CTAs
- [ ] Organizer login → `/organizer`
- [ ] Vendor on `/organizer` sees role gate; organizer on `/vendor/requests` sees vendor gate
- [ ] `/organizer/onboarding` stepper advances
- [ ] `/organizer/launch` validation + mock publish
- [ ] Live publish via WP REST or handoff (no fake success)
- [ ] `/organizer/events/charlotte-night-market` workspace (mock)
- [ ] Share link copy + preview as vendor
- [ ] Organizer home primary CTA + conditional continue draft
- [ ] Checklist items link when `href` present
- [ ] `npx next build` passes

## Known gaps

- Events list uses `invite-options` (events with open opportunities only)
- Numeric WP event IDs required for live workspace; mock uses string slugs
- Share link uses discovery URL filters, not opportunity deep link

## Next phase

Phase 10: Vendor Operations + Event Day Pass.

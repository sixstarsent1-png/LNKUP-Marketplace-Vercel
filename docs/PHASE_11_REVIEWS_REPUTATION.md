# Phase 11 — Vendor Reviews + Reputation

Production Next.js app: `lnk-up-marketplace-ui/` (Vercel). WordPress remains source of truth for reputation data.

## Overview

Phase 11 wires vendor trust and reputation in the Next.js app by replacing mock `/vendor/reviews` with BFF-backed WordPress reputation data, adding reusable badge/review components, surfacing reputation on vendor home and organizer vendor surfaces, and shipping eligibility states, analytics, and tests — without Stripe, Financial OS, or new WordPress routes.

## Environment variables

| Variable | Required | Notes |
|----------|----------|-------|
| `NEXT_PUBLIC_LNKUP_LIVE_API` | Yes | `true` = BFF → WordPress; `false` = mock fixtures |
| `WORDPRESS_API_URL` | Yes (live) | WordPress REST base |
| `NEXT_PUBLIC_WORDPRESS_SITE_URL` | Yes | Public site + dashboard handoff URLs |
| `SESSION_SECRET` | Yes (live auth) | Encrypts httpOnly session cookie |

## Routes

| Route | Purpose |
|-------|---------|
| `/vendor/reviews` | Reputation summary, badges, reviews, completed events timeline |
| `/vendor` | Reputation snapshot card + review eligibility banners |
| `/vendor/growth` | Lightweight reputation strip linking to reviews |
| `/vendor/requests` | Review eligibility banners on booked/completed cards |
| `/vendor/operations` | Eligibility banners in completed section |
| `/vendor/pass/[applicationId]` | Post-event eligibility footer on unlocked pass |
| `/organizer/vendors` | Reputation preview on request cards + profile drawer |
| `GET /api/vendor/reputation` | Summary + completed events |
| `GET /api/vendor/reviews` | Organizer reviews list |
| `GET /api/vendor/reviews/eligible` | Review eligibility derived from dashboard events |
| `POST /api/vendor/reviews/feedback` | Demo success; live returns `handoff_required` |
| `GET /api/public/vendor/[slug]/reputation` | Public adapter BFF (no page route) |

Organizer enrichment (no new route):

- `GET /api/organizer/requests` — attaches `reputationPreview` via parallel `GET /crm/vendors/{id}` (max 10)
- `GET /api/organizer/vendors/[id]` — full `reputation` block on profile via `mapOrganizerVendorProfile`

## WordPress routes (read-only composition)

| WP Route | Role | Notes |
|----------|------|-------|
| `GET /lnkup/v1/reputation/vendor-dashboard` | Vendor | Primary source: reputation, tips, reviews, events, badges |
| `GET /lnkup/v1/verification/vendor` | Vendor | Verification badges merged into summary |
| `GET /lnkup/v1/crm/vendors/{id}` | Organizer | CRM reputation preview for request cards + drawer |
| `GET /lnkup/v1/reputation/vendors/{slug}/public` | Public | Public adapter module only |

**Constraints:**

- Reviews are **organizer → vendor** only (`POST /reputation/reviews` is organizer-only).
- `GET /reputation/applications/{id}/review-eligibility` is organizer-only — vendor eligibility is derived from dashboard `events[].review_status`.
- No vendor feedback submit route exists. `POST /api/vendor/reviews/feedback` returns `handoff_required` in live mode.

## Private vs public rules

| Field | Vendor | Organizer preview | Public adapter |
|-------|--------|-------------------|----------------|
| Review text | Yes | No (summary only) | Highlights only (sanitized) |
| Private/internal notes | Never | Never | Never |
| Risk flags | Never | Neutral info/warning labels only | Never |
| Document URLs | Never in reviews | Never | Never |

## Badge mapping

Badge slugs from WordPress map to UI labels in `mapWpBadge()` — e.g. `verified`, `documents_ready`, `top_rated`, `completed_10`, `completed_50`, `fast_responder`, `would_book_again`, `reliable_setup`.

## Eligibility behavior

| Status | UI |
|--------|-----|
| `awaiting_organizer_review` | Banner: review pending from organizer |
| `review_received` | Banner + CTA to `/vendor/reviews` |
| `vendor_feedback_available` | Feedback CTA → handoff in live mode |
| `not_eligible` | Hidden |

## Mock vs live

| Mode | Behavior |
|------|----------|
| Mock | `mockVendorReputation*`, `mockOrganizerReviews`, `mockReviewEligibility` fixtures |
| Live | BFF proxies WordPress with session auth; organizer requests enriched in parallel |

## Security

- All vendor BFF routes require session auth in live mode
- Organizer routes require organizer session
- Analytics metadata sanitized — never review text, risk details, or credentials
- Stripe / Financial OS not used in Next.js reputation surfaces

## Analytics events

| Event | Trigger |
|-------|---------|
| `vendor_reputation_viewed` | Reviews page loaded |
| `vendor_review_card_viewed` | Review card in viewport (optional) |
| `vendor_badge_viewed` | Badge interaction |
| `public_vendor_profile_viewed` | Public adapter rendered |
| `organizer_reputation_preview_viewed` | Organizer profile drawer opened |
| `vendor_feedback_started` | Feedback CTA clicked |
| `vendor_feedback_submitted` | Feedback handoff completed |
| `share_vendor_profile_clicked` | Share action (future) |

## QA checklist

- [ ] `/vendor/reviews` — loading, empty (new vendor), trusted mock, error + retry
- [ ] Vendor home — reputation snapshot, eligibility banners
- [ ] Organizer request cards — reputation row when preview present
- [ ] Organizer drawer — summary, badges, safe risk flags
- [ ] Eligibility banners on requests, operations, pass
- [ ] Live feedback POST returns `handoff_required` (never fake success)
- [ ] No Stripe imports in `lnk-up-marketplace-ui/app` or `lib`
- [ ] Playwright `lnkup-app-phase11-vendor-reputation.spec.ts` green

## Known gaps

- No vendor feedback WordPress route — handoff only
- No `/vendors/[slug]` public profile page (adapter-ready component only)
- Full `GET /growth/dashboard` not wired in this phase
- Organizer review submit remains WordPress-only

## Related docs

- [PHASE_10_VENDOR_OPERATIONS.md](PHASE_10_VENDOR_OPERATIONS.md)
- [SYSTEM_OVERVIEW.md](SYSTEM_OVERVIEW.md) §14.2

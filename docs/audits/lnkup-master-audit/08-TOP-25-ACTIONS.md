# 08 — Top 25 actions

Ranked by business impact, defect severity, dependencies, effort, organizer-acquisition value, booking-conversion value, and financial/security risk. IDs refer to 04.

| # | Action | Why now | Effort | Ref |
|---|---|---|---|---|
| 1 | Merge `redesign/event-product` into `origin/master` and release one tagged version (12.35.0) of plugin + comms + theme | Production runs H's plugin with M's theme; months of Phase 1–7 fixes aren't live; both lines say 12.33.x | M | A1 |
| 2 | Configure the SiteGround deploy secrets and deploy only from tags (add staging) | Every deploy so far was manual; that's how production got mixed | S | A2 |
| 3 | Export and record production flag values (add a flags screen or CLI) | 12 systems are "built but possibly dark"; nobody can say what's on | S | A3 |
| 4 | Run a full Stripe **test-mode** booking on staging: connect, publish, apply, approve, pay (card + ACH), webhook, pass, check-in, refund, payout | Money has never been exercised against Stripe; every suite runs with Stripe off | M | C4 |
| 5 | Confirm and fix the City Connector commission default (10% of booth GMV, uncapped) before recruiting connectors | Default pays partners more than LNKup earns on a typical booth | S | C5 |
| 6 | Confirm EAS runtimes; ship a new iOS binary so App Store users get Mobile 3.x | Store has 1.0; updates target 1.0.1 | M | A4 |
| 7 | Ship the booth/parking edit fix (`route_id`) | Organizers can't edit booths on event day | S (in M) | B1 |
| 8 | Make invoices follow checkout re-pricing; widen the processing-status guard | Invoice can differ from the charge, or show "partially paid" | S | C1, C3 |
| 9 | Revoke app tokens on password reset/change | A stolen phone stays signed in up to 90 days after a reset | S | D1 |
| 10 | Restrict `wp/v2/users` and remove the author sitemap | 14 accounts publicly listed | S | D2 |
| 11 | Configure an email provider + bounce webhook in Communications | Onboarding and payment emails depend on raw `wp_mail` from SiteGround | S | E15 |
| 12 | Pilot: enable onboarding v2, apply drafts, Organizer OS ops and activation/onboarding flows for 3–5 independent organizers | Built features reach real organizers with low blast radius | S | P1-3 |
| 13 | Add camera QR scanning to the Expo app (native build) and a JS QR fallback on web | iPhone staff can't scan passes on either surface | M | E1 |
| 14 | Serve `/.well-known/assetlinks.json` and a fuller AASA (events, opportunities) | Shared event links don't open the app | S | B5 |
| 15 | Remove demo "fitness trainer" pages/products, noindex app pages, drop unused WooCommerce | Indexed demo content on a marketplace domain | S | B4 |
| 16 | Fix `/markets/{city}/` (flag or rewrite flush) and add market URLs to the sitemap | City landing pages are the main organic acquisition surface and are 404 | S | B3 |
| 17 | Add `og:image` to event and tour pages | Shared links (organizer promo, Six Stars channels) show no image | S | E13 |
| 18 | Build a platform support ticket flow ("Get help" → case → assignment → resolution → notify) | Users with payment or account problems have nowhere to go in-product | L | E3 |
| 19 | Floor-plan upload endpoint for maps | Visual booth layouts are unusable for organizers | S | B2 |
| 20 | Replace or remove the fake Next.js admin pages; decide whether Next.js is a product surface | Staff could act on invented revenue; the app is four months stale in production | S/M | A6, B6 |
| 21 | Escape the two JS sinks; count successful sign-ups in rate limits; honeypot the public forms | Cheap hardening | S | D5, D6 |
| 22 | Enable Organizer Growth for staff and route its outreach through Communications | The acquisition CRM is built but dark | M | P2-1, E17 |
| 23 | Publish the Android app | No Android users today | M | A5 |
| 24 | Turn on matching for vendors (cohort rollout), then organizer discovery | Built engine; drives applications to independent organizers' events | S | P2-2 |
| 25 | Turn on Passport, then migrate pre-12.20 documents into `lnkup-private` after the probe passes | Old vendor documents still sit in public dated folders | M | D4 |

**What should happen first:** 1 → 2 → 3, as one release-hygiene sprint. Then 4 and 5 before any organizer recruiting push. Items 6–11 can run in parallel with 4. Items 12–17 are the "ready for independent organizers" bar.

## What we need before scaling organizers

1. One production revision with automated deploys (1, 2).
2. A proven Stripe journey including Connect payouts (4).
3. Healthy partner economics (5).
4. Current mobile apps on both stores (6, 23).
5. Reliable transactional email (11).
6. Working event-day tools: booth edit, scanning on iPhone (7, 13).
7. A support path (18).
8. Clean SEO and city pages for discovery (15–17).

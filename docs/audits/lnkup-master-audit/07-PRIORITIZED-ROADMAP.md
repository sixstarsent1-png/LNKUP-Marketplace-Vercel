# 07 — Prioritized, dependency-aware roadmap

The strategic goal: acquire independent organizers and increase legitimate completed vendor bookings, with Six Stars' 2027 festivals supplying early vendor demand. The sequence follows that goal.

1. **Stop shipping two codebases.** Nothing else sticks until there's one production revision.
2. **Prove one booking works with real Stripe.**
3. **Make what's already built visible and switched on** for a small pilot.
4. **Build the remaining gaps** that block organizers running a real event.

## P0 — Release blockers (do first, in this order)

| Step | Work | Depends on | Effort | Business value |
|---|---|---|---|---|
| P0-1 | **Unify the lines.** Merge H (12.33.1–12.33.4, including `LNKup_Organizer_Fee_Privacy`, plain titles, signed links, decode-entities) into `origin/master`, resolve conflicts (hotspots: `class-shortcodes.php`, `class-financial-rest.php`, `class-financial-service.php`, `helpers.php`, `financial.js`), release as **12.35.0** from a tag | — | M | Puts Bookings Center, money fixes, booth-edit fix, draft-event hiding, upload probe, funnel and ops queue into production |
| P0-2 | **Fix the deploy pipeline.** Add SiteGround SSH secrets; deploy plugin + comms + theme from the tag; add a staging target; stop committing zips | P0-1 | S | Reproducible releases |
| P0-3 | **Record production flags** (read-only flags screen or CLI dump) and write the live values into `CURRENT_STATE.md` | — | S | Know what is actually on |
| P0-4 | **Staging Stripe journey.** Staging WordPress with Stripe test keys + a Connect test account. Run organizer connect → publish → vendor apply → approve → pay (card and ACH) → webhook → pass → check-in → partial refund → payout. Fix what breaks | P0-2 | M | First proof that money works end to end |
| P0-5 | **Partner commission default.** Confirm live `connector_booking_share_percent`/cap. Move City Connector to a `platform_fee` basis or add a cap before recruiting connectors | — | S | Prevents negative-margin bookings |
| P0-6 | **iOS binary.** Confirm EAS runtimes. Build and submit 1.0.1 (or bump to 1.1.0) so App Store users get current JS; then OTA | P0-1 | M | Organizers and vendors on phones see current features |

## P1 — Core marketplace activation (organizer → booking → event day)

| Step | Work | Depends on | Effort |
|---|---|---|---|
| P1-1 | Invoice follows re-pricing (C1) + processing guard (C3) | P0-1 | S |
| P1-2 | Security quick fixes: revoke app tokens on password reset (D1), restrict `wp/v2/users` + author sitemap (D2), escape the two JS sinks (D5), sign-up limits + honeypots (D6) | P0-1 | S |
| P1-3 | Pilot cohort: turn on `organizer_onboarding_v2_enabled`, `apply_wizard_enabled`, `organizer_ops_v2_enabled` and Communications `flow_activation` / `flow_onboarding` for 3–5 independent organizers (`organizer_os_pilot_user_ids`); keep Six Stars as house organizer IDs | P0-3, P0-4, E15 | S |
| P1-4 | Email deliverability: configure a provider and bounce webhook in Communications, set `company_address` (E15) | — | S |
| P1-5 | Event-day: camera QR scanning in Expo (`expo-camera`, native build) + JS fallback on web for iPhone Safari (E1); floor-plan upload endpoint (B2) | P0-6 | M |
| P1-6 | Deep links: `assetlinks.json` + AASA under `/.well-known/` with event and opportunity paths (B5) | — | S |
| P1-7 | SEO and brand cleanup: remove demo pages/products and WooCommerce if unused; noindex app pages; fix `/markets/{city}/`; `og:image` on events (B3, B4, E13) | P0-2 | S |
| P1-8 | Platform support: "Get help" ticket flow (payment, refund, account, listing) with assignment and history (E3) | P0-1 | L |
| P1-9 | Android: Play production release (A5) | P0-6, P1-6 | M |
| P1-10 | Decide the Next.js app's role. Either retire it (WordPress web + Expo are the product), or promote it with live admin pages and CI `tsc` (A6, B6) | — | S (decide) / M (build) |

**Why this order:** organizers judge LNKup on the first event they run. They need a working onboarding, money they trust (P0-4, P1-1), messages that arrive (P1-4), and a check-in that works on the phone in their hand (P1-5). Growth tooling before that only brings organizers into a shaky first event.

## P2 — Growth and retention

| Step | Work | Depends on |
|---|---|---|
| P2-1 | Turn on Organizer Growth (`organizer_growth_enabled`) for staff; route outreach through Communications for suppression and tracking (E17) | P1-4 |
| P2-2 | Matching on for vendors, then organizers (`matching_enabled` + discovery flags, `flow_discovery*`) using the rollout cohorts | P1-3 |
| P2-3 | Passport on (`passport_enabled`), then document vault sharing after the probe reads `protected`; migrate pre-12.20 documents into `lnkup-private` (D4) | P0-1 |
| P2-4 | Teams (`trust_org_teams`) + team screens in apps (E6); waitlist offers in apps (E7) | P1-3 |
| P2-5 | Retention flows (`flow_retention`, digests) once activation metrics exist (funnel from P0-1) | P1-4 |
| P2-6 | Trust: report listing (E4), account restriction (E5), signed QR for new passes (D8), referral fraud signals (D9) | — |
| P2-7 | Replace `$request['id']` reads across 22 REST classes with the shared `route_id()` helper + tests (06 #3) | P0-1 |

## P3 — Advanced expansion

| Work | Note |
|---|---|
| Portfolio OS for multi-city organizers (`portfolio_os_enabled`), organizer tour overview in Expo | After pilots run single events cleanly |
| Booking modifications (E8), offline check-in (E2) | Event-day scale |
| Enterprise: outbound webhooks/API keys, multi-org UI (E12) | Only with demand |
| Monetization: subscriptions go-live; remove or build premium listings, financing, insurance (E11) | Product decision |
| Admin command center: flag screen + ops queue + job monitor in one place (E14) | Mostly built on M |

## Suggested Cursor sessions (one per branch, in order)

1. **Release unification:** P0-1, P0-2 (branch `release/12.35-unify`). Done when production shows 12.35.0, `explore-ux.js` loads and the deploy workflow is green.
2. **Flags and staging money:** P0-3, P0-4, P0-5. Done when a staging card payment, refund and payout are recorded and the flag snapshot is in the docs.
3. **Mobile release:** P0-6, P1-6, P1-5 (camera), P1-9.
4. **Integrity and security quick fixes:** P1-1, P1-2, P2-7.
5. **Pilot enablement:** P1-3, P1-4, P1-7.
6. **Support OS:** P1-8.
7. **Growth stack:** P2-1, P2-2, P2-5.
8. **Passport and trust:** P2-3, P2-6, P2-4.

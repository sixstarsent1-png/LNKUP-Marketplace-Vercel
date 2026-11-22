# Trust, Safety & Compliance OS — Roadmap

**Shipped:** Stages 1–3 (Trust schema v1). **Audit:** [TRUST_SAFETY_AUDIT.md](TRUST_SAFETY_AUDIT.md).

WordPress stays the system of record. Every later stage builds on the Stage 1–3 foundation instead of adding parallel state:

| Foundation piece | Class / table | Use in later stages |
|---|---|---|
| Tamper-evident audit log | `LNKup_Audit_Log` · `wp_lnkup_audit_log` | Every consequential decision (refund approval, restriction, dispute outcome, MFA reset) |
| Case queue | `LNKup_Trust_Cases` · `wp_lnkup_trust_cases`, `wp_lnkup_trust_case_events` | Risk signals, reports, disputes and incidents open or update a case; never act alone |
| Listing authenticity | `LNKup_Event_Authenticity` · `_lnkup_authenticity_status` | Tour stops, matching eligibility, trust signals |
| Organization teams | `LNKup_Org_Teams` · `wp_lnkup_org_members`, `wp_lnkup_org_invites` | Refund / cancellation / document permissions, session revocation |
| Organizer verification | `LNKup_Organizer_Verification` · user meta | Badges, onboarding gates, reverification triggers |
| Safe notices | `LNKup_Trust::notify_user()` | Email + inbox only; no lock-screen push with case details |

## What shipped (Stages 1–3)

### Stage 1 — Audit
`docs/TRUST_SAFETY_AUDIT.md`: confirmed protections (webhook signatures and replay, server-side intent binding and amount checks, private documents, check-in eligibility) and findings F-1 … F-10.

### Stage 2 — Critical fixes and foundation
- External event claims are bound to the invited email or domain. Second claimants become `claim_disputed` with an elevated case and no transfer. Claims are rate-limited per user and per listing.
- `wp_safe_remote_get` for organizer-supplied Eventbrite / Posh URLs.
- Hash-chained audit log, daily integrity cron, document download logging. Financial audit IPs are stored as keyed hashes, with a one-time migration of old rows.
- Case queue with fraud flag, chargeback and rejected-payment-confirmation adapters.
- wp-admin **LNKup → Trust & Safety** tabs: Overview, Cases, Organizer Verification, Event Authenticity, Audit Log, Policies & Settings (new `lnkup_manage_trust` capability).
- Organization teams (owner, admin, event manager, finance, operations staff; scoped to all events, a tour, or an event), wired into `LNKup_Capabilities::staff_can()` and application review. Organizer **Team & Trust** view.

### Stage 3 — Organizer verification and event authenticity
- Organizer verification status machine (`not_started` … `restricted`), backfilled from `lnkup_organizer_verified` and kept in sync with it.
- Domain control by emailed code or DNS TXT record (no HTTP fetch of organizer sites).
- Separate public indicators: payouts active (Stripe), domain verified, LNKup reviewed.
- Listing authenticity statuses. The publish gate (flag) is enforced in the discovery index, apply eligibility and checkout. Payout-account swaps and organizer changes open review cases without touching existing bookings. A duplicate-event scan opens cases and never merges on its own. Vendor-facing labels never imply a guarantee.

### Feature flags (`lnkup_settings`)

| Flag | Default | Effect |
|---|---|---|
| `trust_claim_review_mode` | `log` | `off` legacy · `log` cases only · `enforce` mismatched claim links wait for review |
| `trust_publish_gate` | `false` | Only `organizer_controlled` / `additional_verification` listings are discoverable, applicable, payable |
| `trust_auto_promote_claims` | `false` | Email/domain-matched claim + active payouts → `organizer_controlled` automatically |
| `trust_org_teams` | `false` | Enables team invites and team capability checks |

`restricted` listings are always blocked, whatever the flags say.

**Rollback:** turn the flags off. Tables and meta are additive; leaving them in place is harmless. To remove, drop the six `wp_lnkup_*` trust tables and delete `lnkup_trust_db_version`.

## Stages 4–13

Each stage ships behind its own flag, logs decisions to the audit log, and routes human decisions through cases.

| Stage | Scope | Builds on | Acceptance tests |
|---|---|---|---|
| **4 Vendor verification** | Split account / business / document / event-eligibility / participation indicators in `LNKup_Verification`; revocation workflow with reasons; expiry → `reverification` without forcing re-verification of existing vendors | Audit log, cases, `notify_user` | Existing verified vendor keeps history; expiring document changes status and notifies once; organizer cannot read a non-applicant's documents |
| **5 Risk rules engine** | Deterministic, explainable rules (claim spam, duplicate listings, application floods, document reuse hash, failed verification streaks) writing `case` + evidence; no automatic bans; false-positive dismissals suppress the same signal for N days | `LNKup_Trust_Cases::open()` idempotency, `LNKup_Fraud_Flags` | Every flag has reason, source, timestamp, subject; dismissing a false positive stops repeats; no protected characteristics in inputs |
| **6 Payment & Connect protection** | Reconciliation exceptions → cases (reuse `LNKup_Financial_Reconciliation::classify_payment`); refund authorization via `staff_can( 'manage_refunds' )`; re-auth for payout changes; webhook attribution checks | Org teams caps, Connect change hook, audit log | Forged intent / replayed webhook / unauthorized refund all refused; finance staff can refund, ops staff cannot |
| **7 Disputes & resolutions** | Marketplace dispute center on `lnkup_dispute` + `LNKup_Financial_Disputes`: categories, stages (submitted → appealed), evidence uploads (private docs), organizer response, documented outcome. Chargebacks stay a separate provider flow with Stripe deadlines from `evidence_details.due_by` | Cases (chargeback type exists), private documents | Opening a dispute never refunds or freezes funds; refund only via authorized workflow |
| **8 Document compliance** | Event / tour / category / booth requirements, version history, rejection reasons, short-lived signed download URLs, signed QR passes (HMAC of packet + event + version, rotated on refund/cancel) | Document download audit, `LNKup_Checkins` | Expired doc blocks eligibility where required; forged or cross-event QR is refused |
| **9 Account & team security** | Login rate limiting, MFA for `lnkup_admin`/finance (via an established plugin), re-auth for sensitive actions, session revocation on password/email change, Application Password revocation for mobile | `LNKup_Org_Teams::revoke()` | Repeated logins throttled; revoked member's API calls fail immediately |
| **10 Reporting & trust signals** | "Report organizer / vendor / event" with rate limits and anonymity → cases; vendor-facing organizer indicators on event pages and checkout; no internal scores public | Cases, `public_label()`, verification indicators | Reports create cases with references; reporter identity hidden from the reported party |
| **11 Incident response** | Incident records (security incidents, not event-day `LNKup_Incidents`), severity definitions, containment actions (revoke sessions, disable credential, hold listing publication via `restricted`), runbook | Audit log, authenticity `restricted`, cases | No "freeze all funds" control; every containment action is audited |
| **12 Mobile** | Expo: verification status, report flow, dispute status, team invite acceptance via `POST /org/invites/accept` (SecureStore Basic auth) | REST routes from Stages 2–11 | Same permission results as web; no case details in push |
| **13 Hardening** | External pen test of all `lnkup/v1` routes, dependency audit, load test of case queries, staged rollout of flags | All | Signed-off external assessment; flags enabled per environment |

## Known gaps after Stages 1–3
- Team members without an organizer (or staff) role can accept invites and pass REST checks, but the theme's dashboard role gate keeps them out of the organizer dashboard UI.
- Organizer CRM routes still check owner-only (not team-aware). Refund approval does not yet use `manage_refunds` (Stage 6).
- Duplicate detection compares same-date LNKup events only; external-to-external dedup stays in `LNKup_External_Events_Dedup::scan()`.
- No automated PHPUnit harness in the repo; coverage comes from the wp-env PHP integration suites and Playwright.

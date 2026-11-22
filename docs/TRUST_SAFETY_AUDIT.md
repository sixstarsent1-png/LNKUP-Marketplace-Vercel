# Trust, Safety & Security Audit (Stage 1)

**Date:** 2026-11-22 · **Plugin:** `lnkup-marketplace` 12.29.x working tree (Trust schema v1, `lnkup_trust_db_version`) · **Scope:** WordPress plugin (system of record). The mobile app and Next.js BFF are consumers and were not audited here.

This audit was produced by reading the code. It is not a penetration test. Items are labelled **Confirmed** (read the code path end to end) or **Sampled** (pattern checked across files, representative handlers read). Severity uses critical / high / medium / low.

## 1. Summary

| # | Finding | Severity | Status |
|---|---------|----------|--------|
| F-1 | External event claim does not bind the claimer to the invited email, and immediately publishes the listing as organizer-owned and bookable | High | **Fixed in Trust schema v1** (Stage 2a, flag-gated publish) |
| F-2 | Second claimant / conflicting claims have no dispute path | High | **Fixed in Trust schema v1** (`claim_disputed` + trust case) |
| F-3 | Financial audit log stores raw IP addresses and is not tamper-evident; no general (non-financial) audit log | Medium | **Fixed in Trust schema v1** (hash-chained `lnkup_audit_log`, IPs hashed) |
| F-4 | Eventbrite / Posh importers fetch organizer-supplied URLs with `wp_remote_get` and follow redirects (possible SSRF via redirect) | Low | **Fixed in Trust schema v1** (`wp_safe_remote_get`) |
| F-5 | Private document downloads are not access-logged | Low | **Fixed in Trust schema v1** (audit `document_downloaded`) |
| F-6 | QR passes are random 8-char strings, not signed tokens | Low | Open — mitigated by `actor_can_operate`; roadmap Stage 8/hardening |
| F-7 | Team access is per-event only (`LNKup_Event_Staff`); no organization / tour scope | Structural | **Added in Trust schema v1** (`LNKup_Org_Teams`, flag `trust_org_teams`) |
| F-8 | Organizer verification is a boolean (`lnkup_organizer_verified`) with no review lifecycle | Structural | **Added in Trust schema v1** (status machine + domain control) |
| F-9 | No unified trust case queue or Trust & Safety admin | Structural | **Added in Trust schema v1** |
| F-10 | Organizer wallet / ledger discrepancies (duplicate settlement, missing mirrors) | High (financial) | **Isolated** — root-caused in [finance/RECONCILIATION_2026-11-21.md](finance/RECONCILIATION_2026-11-21.md); code fixed in 12.22.1+; historical corrections await owner approval. Not touched here. |

No confirmed critical IDOR was found in the sampled routes. That is not proof of absence; see §3.4.

## 2. Existing protections (keep)

### 2.1 Stripe webhooks — Confirmed
- `LNKup_Stripe_Webhooks::register_route` → `POST lnkup/v1/stripe-webhook` (`__return_true`, correct for a provider callback).
- `LNKup_Financial_Service::handle_webhook` (`includes/financial/class-financial-service.php` ~L1577) verifies `Stripe-Signature` against both the platform and Connect signing secrets. Unsigned payloads are accepted **only** when no secret is configured **and** `test_mode` is on.
- Replay protection: `LNKup_Stripe_Webhooks::log_event` dedupes on Stripe `event_id`; `is_event_processed` short-circuits.
- Handled types include `checkout.session.*`, `payment_intent.*`, `charge.refunded`, `charge.dispute.created|funds_withdrawn|funds_reinstated|closed`, `account.updated`, `account.application.deauthorized`, `transfer.*`, `payout.*`.

### 2.2 Payment state integrity — Confirmed
- Only two writers set `_lnkup_payment_status = paid`: `LNKup_Payments::mark_paid` (called only from `LNKup_Financial_Service::finalize_payment`) and the dispute "won" path in the webhook router.
- `finalize_payment` callers: webhook handlers, `reconcile_payment`, client confirm, zero-amount, simulation.
- Client confirm / reconcile paths call `verify_intent_for_payment` (~L1434). It retrieves the PaymentIntent server-side, requires it to be bound to this payment (`metadata.payment_post_id` or the stored intent), requires `status = succeeded`, and checks amount and currency (`charge_amount_ok`). Rejections are logged via `log_rejected_confirm`.
- **No path marks a booking paid from a frontend success redirect.** `financial/checkout/native-return` only 302s to the app scheme.
- Simulation (`LNKup_Stripe::ajax_simulate_payment`) needs a nonce, `simulation_enabled()` (constant, or test mode + `WP_DEBUG` + no live provider), and admin or explicit developer opt-in.
- Finalize runs under a per-payment lock (`with_lock( 'pay_' . $id )`) with unique ledger keys (12.22.x).

### 2.3 Private documents — Confirmed
- `LNKup_Documents::upload` validates with `wp_check_filetype_and_ext` against an allowlist and stores files under a private upload dir protected by `.htaccess` + `index.php` (`private_upload_dir`, `class-documents.php` L118–L140).
- Downloads: `admin-ajax?action=lnkup_download_document` (per-document nonce) and `GET lnkup/v1/documents/{id}/file` (`document_file_permission`). Both allow only the owning vendor, `lnkup_verify_documents` (admin roles only, `class-roles.php` L113) or manage-all. Organizers cannot download vendor documents directly.

### 2.4 CPT and meta exposure — Confirmed
- Only `lnkup_event` and `lnkup_opportunity` are `show_in_rest` (`LNKup_Post_Types::REST_PUBLIC_TYPES`). All other CPTs are `public => false` and not in `wp/v2`.
- Meta registered via `LNKup_Meta::register_meta` inherits that REST visibility and requires `edit_posts` to write. Application workflow keys cannot be edited through meta boxes (`APPLICATION_WORKFLOW_KEYS`).

### 2.5 Check-in — Confirmed
- `LNKup_Checkins::evaluate` (L94) checks: application exists, event matches the expected event, opportunity belongs to the event, `actor_can_operate`, vendor cannot self-check-in, booking eligibility, lifecycle stage, and duplicate scans (`already_checked_in`).

### 2.6 Application ownership — Sampled
- `LNKup_Applications::withdraw` → `vendor_owns_application`; `request_info` → `user_owns_post( _lnkup_organizer_user_id )` or manage-all; `respond_info` → vendor user match. REST handlers in `class-applications-rest.php` delegate to these.
- Packets REST uses `LNKup_Packets::can_view_pass` and event-organizer permission callbacks.

### 2.7 Organizer badge semantics — Confirmed
- `LNKup_Verification::compute_organizer_level_data` (L981) requires `payout_connected && event_details && has_live_opp && manual_verified` for "Verified Organizer". Stripe onboarding alone yields at most "Payout Connected".

## 3. Findings

### F-1 / F-2 External event claim (High) — Confirmed
`LNKup_External_Events::claim()` (`class-external-events.php` L1461):
- Validates that the token exists, matches the external event, is unused and unexpired. It does **not** compare the claiming organizer's account email with `claim_tokens.email`. Anyone holding a forwarded or leaked link can claim.
- Calls `create_lnk_event_from_external` → `LNKup_Organizer_Onboarding::create_event_from_request`, which creates a `publish` event owned by the claimer (`_lnkup_status = published`, L105/L133). Any opportunity the claimer then adds through `create_opportunity_for_event` is `publish` + `_lnkup_opportunity_status = open` (L224/L262) and immediately bookable and payable. Nothing distinguishes a claimed listing from an organizer-created one.
- First claim wins. A later claimant gets `already_claimed` with the event ID and no review path.

**Fix (Trust schema v1):** email/domain binding, `claim_disputed`, per-user / per-event rate limits, trust cases, and `_lnkup_authenticity_status` on every claimed event. When `trust_publish_gate` is on, opportunities on events that are not `organizer_controlled` are kept out of discovery, and both apply and checkout are refused. Enforcing at the bookability points (not a one-time draft flag) also covers opportunities created after the claim.

### F-3 Audit logging (Medium) — Confirmed
`LNKup_Financial_Audit::log` writes `$_SERVER['REMOTE_ADDR']` verbatim, against the repo rule "store hashed IPs". Rows can be updated or deleted with no detection, and only financial actions are covered.

**Fix:** `LNKup_Audit_Log` (hash chain, `ip_hash`, request ID, scrubbed state). The financial audit writes through to it, new financial rows store a hashed IP, and existing raw IPs are hashed by a one-time migration.

### F-4 Importer SSRF via redirect (Low) — Confirmed
`LNKup_External_Source_Eventbrite::fetch_event_html` and `LNKup_External_Source_Posh::fetch_event_html` use `wp_remote_get` with `redirection` > 0 on organizer-supplied URLs (host-gated by provider detection). A redirect could reach an internal address. RSS uses `fetch_feed`, which already uses the safe HTTP API. **Fix:** `wp_safe_remote_get`.

### F-6 QR pass format (Low) — Confirmed
`class-packets.php` L54: `'LNKUP-' . strtoupper( wp_generate_password( 8, false ) )`, about 41 bits after uppercasing. Scans require an authorized operator for that event, so a guessed pass only helps an insider. Recommendation: signed, event-bound tokens (HMAC of packet + event + version) with rotation on refund or cancellation. Deferred to the roadmap.

### 3.4 REST route sweep — Sampled
- 20 `__return_true` routes, all reviewed. Public reads: marketplace, discovery pins, reputation, organizer public profile, tours, external event search/get, apply-context, application questions. Public writes: analytics tracking (`marketplace/track/*`, `external-events/track/view`), `connector/interest`, `growth/unsubscribe` (token), `auth/login|register`, `stripe-webhook`. None of the public writes alter booking, payment or ownership state.
- Role-only permission callbacks (`organizer_permission`, `vendor_permission`) cover about 90 routes. Ownership is enforced inside the handlers or services; that was verified for applications, packets, documents, financial payment routes (`can_access_payment`) and operations (`event_cap` / `row_cap`). Organizer CRM and intelligence routes were spot-checked. A full route-by-route matrix remains a recommended external assessment.
- **Recommendation:** add automated IDOR checks to CI (two vendors + two organizers seeded; each reads and writes the other's IDs). `tests/e2e/vendor-security.spec.ts` and `organizer-security.spec.ts` already cover part of this; `tests/e2e/trust-safety.spec.ts` extends it.

### 3.5 Other checks
- `unserialize()` on user data: none found.
- `wp_redirect` with user input: none found (payment flows use `wp_safe_redirect`).
- `$wpdb` dynamic queries: sampled files use `prepare()`; table names are code constants.
- Login rate limiting / MFA: not present in the plugin. Roadmap Stage 9.

## 4. Third-party dependencies
Stripe (payments, Connect, disputes); Eventbrite / Posh / Google CSE / RSS (external events); OpenWeather; geocoder; Expo push. Domain verification (new) uses DNS lookups (`dns_get_record`) and email only, with no HTTP fetch of user URLs.

## 5. External assessments still required
- Authenticated penetration test of all `lnkup/v1` routes with seeded cross-tenant identities.
- Stripe dispute / refund flow review against the live Connect configuration (destination charges).
- Legal review of verification badge copy, dispute policy, and data retention.

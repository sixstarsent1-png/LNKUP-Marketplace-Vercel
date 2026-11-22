# Phase 27 — Vendor Passport & Reputation OS

**Status:** Built (behind feature flags) · Plugin `lnkup-marketplace` (schema `lnkup_passport_schema_version = 1`) · Communications `flow_passport`
**Release checklist:** [PHASE_27_RELEASE_CHECKLIST.md](PHASE_27_RELEASE_CHECKLIST.md)

The Vendor Passport turns the existing vendor profile, verification, documents, reviews, reputation and participation records into one portable identity. Nothing was rebuilt: every record stays on the existing CPTs/meta; three additive SQL tables hold indexes, history and consent.

## 1. Audit summary (what existed → what changed)

| Area | Existed | Phase 27 |
|---|---|---|
| Profile | `lnkup_vendor_profile` + ~50 meta keys, web form + partial REST | Passport projections (public / organizer / owner), new fields (`_lnkup_facebook`, `_lnkup_capabilities`, `_lnkup_secondary_categories`, `_lnkup_preferred_booth_types`, `_lnkup_portfolio_items`, `_lnkup_passport_visibility`) |
| Profile strength | `LNKup_Growth_Profile_Strength`, hardcoded weights, mixed in documents + trust | Weights in settings + `lnkup_profile_strength_weights` filter; completeness = profile fields only; documents/trust moved to `related` |
| Verification | `_lnkup_verification_status` meta, derived levels | Append-only `lnkup_vendor_verification` records (types, provenance, expiry, revocation); legacy meta mirrored both ways |
| Documents | Private dir + PHP streaming; no versions/sharing; pending/expired counted as ready | Versions (`_lnkup_document_group_id`, `_lnkup_superseded_by`, `_lnkup_version`), reuse checks, consented per-application shares, signed organizer downloads, legacy public-file migration |
| Reviews | 8 categories, 1/application (non-atomic), report hid review, private notes leaked | Atomic `lnkup_review_index`, eligibility (no self-review, event ended), 5 public categories + tags, vendor responses, moderation decisions + one appeal, notes author/staff only |
| Reputation | Single 0–100 score shown publicly | Score is staff-only; public/organizer see four indicators (`LNKup_Reputation_Summary`) |
| History | Paid counted as completed; no tour grouping | `LNKup_Participation_History`: evidence-based milestones, one row per event, tour stops counted once each |
| Public page | `/vendors/?vendor={slug|id}`; ID enumeration; ignored opt-out | `/vendors/{slug}` (301 from legacy), slug only, privacy-aware, JSON-LD, noindex when private |

### Security fixes shipped first (27A)
1. `GET /reputation/vendors/{id}` no longer writes meta onto arbitrary posts (reads return a neutral state and queue a background refresh).
2. Numeric reputation routes require a signed-in owner/organizer/staff; public lookups are slug-only and rate-limited.
3. Private/internal review notes are returned only to the authoring organizer or staff (was: any organizer).
4. Reporting a review opens a dispute; the review stays visible until a moderator decides. One open dispute per review; monthly cap per vendor.
5. Expired and pending documents no longer count as "ready"; newest version wins; rejection reasons persist; `Content-Disposition` sanitized.
6. Automatic badge revocations can be re-earned even when staff triggered the refresh.
7. Reliability: completion requires check-in (or paid + event ended + no no-show); rejections are not cancellations; response rate is measured from invites; rebooking = repeat confirmed bookings; review disputes and unconfirmed organizer flags never lower scores.
8. Profile-complete fallback uses the real section keys.
9. Legacy document files in public uploads can be moved into `uploads/lnkup-private` (admin-started, batched, logged).

## 2. Architecture

```
lnkup_vendor_profile / lnkup_document / lnkup_application(+check-in meta) / lnkup_review / lnkup_vendor_badge
          │
          ▼
LNKup_Verification_Records · LNKup_Document_Vault · LNKup_Participation_History · LNKup_Reputation_Summary
          │
          ▼
LNKup_Passport_Projection (public | organizer | owner — allow-lists)
          │
          ├─ LNKup_Passport_REST  (lnkup/v1/passport/*)   → web dashboard (passport.js), Expo, Next.js BFF
          ├─ LNKup_Passport_Public (/vendors/{slug}, JSON-LD, robots)
          └─ lnkup-communications LNKup_Comms_Listen_Passport (flow_passport)
```

Files: `includes/passport/*`, `admin/class-passport-admin.php`, `public/js/passport.js`, `public/css/passport.css`.

## 3. Data model

| Table | Purpose | Key columns |
|---|---|---|
| `{prefix}lnkup_vendor_verification` | Append-only verification history | `vendor_profile_id, type, status, authority, source, document_id, reviewer_id, verified_at, expires_at, revoked_at, reason` |
| `{prefix}lnkup_document_share` | Vendor consent to share one document version with one organizer for one event/application | `document_id, vendor_profile_id, organizer_user_id, event_id, application_id, granted_by, granted_at, revoked_at, expires_at` |
| `{prefix}lnkup_review_index` | One review per booking (UNIQUE `application_id`), fast aggregates | `application_id (NULL for legacy rows without one), review_id, organizer_user_id, vendor_profile_id, overall, status, verified_participation` |

Verification types: `account, business_identity, business_document, license_permit, insurance, event_requirement`. Statuses: `not_submitted, pending, info_required, verified, rejected, expired, revoked`. A verified record past `expires_at` reads as `expired`; the daily job appends an `expired` row. `business_identity` drives the "Business Identity Verified" badge and is mirrored to `_lnkup_verification_status` (`approved|pending|rejected|expired|revoked`); legacy writers of that meta are mirrored back into records (`source = legacy_admin`).

New meta: documents `_lnkup_document_group_id, _lnkup_version, _lnkup_superseded_by, _lnkup_display_name, _lnkup_insured_name, _lnkup_coverage_amount, _lnkup_review_history, _lnkup_reviewed_by/_at`; reviews `_lnkup_positive_tags, _lnkup_verified_participation, _lnkup_under_dispute, _lnkup_vendor_response(_at/_updated_at/_status), _lnkup_edit_history, _lnkup_edit_requested, _lnkup_edit_window_until, _lnkup_moderation_reason`; disputes `_lnkup_reason_code, _lnkup_decision, _lnkup_decision_reason, _lnkup_decided_by/_at, _lnkup_appeal_reason, _lnkup_appealed_by/_at, _lnkup_final`; flags `_lnkup_admin_confirmed`; applications `_lnkup_shared_document_versions`; opportunities (optional) `_lnkup_document_requirements` JSON e.g. `{"certificate_of_insurance":{"min_coverage":1000000,"named_insured":"Org LLC"}}`.

Migrations are additive and idempotent (`LNKup_Passport::maybe_migrate`, admin load + daily): review index backfill (legacy `disputed` reviews become published + under review), verification `legacy_meta` backfill, document version groups.

## 4. REST API (`lnkup/v1`)

| Route | Method | Permission | Notes |
|---|---|---|---|
| `/passport/me` | GET, PATCH | vendor (`lnkup_manage_own_profile`) or staff (`?profile_id=`) | Owner view; PATCH validates fields, returns `fields` errors (400) |
| `/passport/me/portfolio` | GET, PUT | vendor | PUT = full ordered list; ownership-checked attachments only |
| `/passport/me/portfolio/upload` | POST (multipart `file`) | vendor | ≤ 8 MB, 40/hour, max 30 photos |
| `/passport/me/portfolio/{attachment_id}` | DELETE | vendor | |
| `/passport/me/privacy` | GET, PATCH | vendor | `hidden_event_ids` limited to own events |
| `/passport/me/history` | GET `?page` | vendor | Owner history + stats |
| `/passport/me/reviews` | GET `?page` | vendor | With dispute status, report reasons |
| `/passport/me/verification` | GET, POST | vendor | POST requires `passport_verification_v2` |
| `/passport/vendors/{slug}` | GET | public (rate-limited) | Public projection; 404 when private |
| `/passport/vendors/{slug}/history`, `/reviews` | GET | public (rate-limited) | Paginated |
| `/passport/vendors/{profile_id}/organizer` | GET `?opportunity_id` | organizer or staff | Contact only with qualifying relationship |
| `/passport/vendors/{profile_id}/invite-tour` | POST `{tour_id, event_ids[], message}` | organizer | One invite per future stop the organizer owns |
| `/documents/vault` | GET | vendor | Groups, versions, shares |
| `/documents/reusable?opportunity_id=` | GET | vendor | `satisfies | needs_review | insufficient | missing`; never approves for the event |
| `/documents/shares` | GET, POST `{document_id, application_id, consent:true}` | vendor | Requires `document_vault_sharing` |
| `/documents/shares/{id}` | DELETE | owner | Immediate revocation |
| `/documents/shares/{id}/file?expires&token` | GET | organizer of the share + HMAC token (15 min) | Audit-logged stream |
| `/applications/{id}/shared-documents` | GET | event organizer or staff | Shares + requirement check + fresh signed links |
| `/reputation/reviews/{id}/response` | POST | reviewed vendor | Requires `review_responses` |
| `/reputation/reviews/{id}/report` | POST `{reason, reason_code}` | reviewed vendor | Review stays visible |
| `/reputation/disputes/{id}/appeal` | POST | vendor or reviewing organizer, once | |
| `/reputation/admin/disputes/{id}/decision` | POST `{decision, reason_code, note}` | staff | `uphold | hide | request_edit` |
| `/reputation/vendors/{id}*` | GET | signed-in owner/organizer/staff | Score/breakdown removed for non-staff |
| `/reputation/vendors/{slug}/public` | GET | public | Legacy shape, built from the public projection |
| `/verification/admin/manual` | POST | staff | Now writes records (`approved|rejected|pending|info_required|revoked`, `expires_at`, `type`) |

Apply flow: `POST /applications`, drafts `.../submit`, tour groups and AJAX accept `share_consent` + `share_document_ids[]`; only required types are shared, per application.

Organizer-facing routes (`/crm`, `/intelligence`, `/applications`, `/match`, `/operations`, `/vendor-lists`, `/tours`) strip `vendor_score`, `reputation_score`, `reputation_label`, `breakdown`, `dispute_count` for non-staff (`rest_request_after_callbacks`), and drop `avg_rating` below the review minimum.

## 5. Review & reputation rules

- **Eligibility:** reviewer owns the booking's event (or staff); not the vendor; event ended; application `paid|completed` or checked in; never `submitted|under_review|info_requested|waitlisted|rejected|cancelled`. One review per booking (atomic). "Verified participation" when a check-in exists.
- **Categories:** Communication, Professionalism, Preparedness, Event coordination, Overall (legacy keys mapped). Positive tags allow-listed. No personal traits.
- **Editing:** author within `passport_review_edit_days` (default 7) or a moderator-reopened 14-day window; edit history kept.
- **Responses:** one public response per review; editable for `passport_response_edit_days`; never edits the review.
- **Reports:** reason ≥ 10 chars + reason code; one open dispute per review; `passport_report_monthly_cap` per vendor; review stays visible (`under_review`).
- **Moderation:** `uphold` (default), `request_edit`, or `hide` with a documented reason (`policy_abuse`, `policy_discrimination`, `policy_private_info`, `policy_coercion`, `ineligible`, `factually_unsupported`, `duplicate`). Each party may appeal once within 30 days; the appeal decision is final. Every action is logged to `lnkup_reputation_activity`.
- **Display:** average shown only with ≥ `passport_review_min_count` (3) published reviews from ≥ `passport_review_min_organizers` (2) organizers; each organizer's reviews are averaged first, then averaged; one decimal; count shown. Otherwise "Not enough reviews yet" / "No reviews yet". New vendors get a neutral note.
- **Indicators are separate:** Business verification ≠ experience ≠ organizer feedback ≠ document readiness. The 0–100 score remains internal (admin, internal ranking/sort).
- **Achievements** (evidence-only, from `LNKup_Participation_History::stats`): First Confirmed Event, First Verified Check-In, Five/Ten Confirmed Events, Returning Vendor (≥2 confirmed with one organizer), Multi-City (≥3 cities), Tour Participant (≥2 stops of one tour), Established Marketplace Vendor (≥10 events, ≥3 organizers, ≥180 days). Shown when `passport_history_v2` is on.
- **Matching** (`matching_reputation_signals`): document readiness already in the requirements factor (now excludes expired/superseded docs); positive feedback ≥ 4.0 above the minimum is a small signals-only tie-breaker. No reviews and private flags are never penalties; hard eligibility is evaluated separately.

## 6. Document security

- Files live in `uploads/lnkup-private/` (Apache deny via `.htaccess`). **Nginx/IIS:** add `location ^~ /wp-content/uploads/lnkup-private/ { deny all; return 404; }` (or equivalent).
- Downloads only via authenticated PHP: owner/verifier route (`/documents/{id}/file`) or organizer share route with an HMAC token bound to share + user + expiry (`passport_share_token_minutes`, default 15). Both are audit-logged; `X-Content-Type-Options: nosniff`, no caching, sanitized filename.
- Sharing requires explicit consent per application; the exact version is recorded on the application; shares lapse 60 days after the event and are revoked automatically when an application is cancelled; vendors can revoke any time.
- Reuse checks compare type, review status, expiry against the event date, and optional coverage/named-insured rules; uncertain cases return `needs_review` for the organizer.
- Notifications never contain document contents.
- Legacy migration: wp-admin → Vendor Passport & Trust → System health → dry run → Start migration (25 files/hour, logged, resumable, pausable).

## 7. Communications (`flow_passport`)

Triggers: `v_verification_update`, `v_verification_expiring`, `v_review_received`, `v_review_moderated`, `v_tour_invite`, `o_review_response`, `o_review_moderated`, `o_document_shared`, `o_shared_document_expiring`. Document expiry reminders reuse `v_document_expiring` with the same window keys as the existing daily automation (dedupe), at `passport_reminder_days` (default 30/14/7), only for documents that matter (required by an upcoming application or suggested for the vendor's categories). Tour invites send one grouped message instead of one per stop. Deep link: `/(vendor)/passport?tab=…`; web: `vendor-dashboard/?view=passport&tab=…`.

## 8. Clients

- **Web dashboard:** `vendor-dashboard/?view=passport` (Overview, Portfolio, Verification, Event History, Reviews, Documents, Preferences, Invitations, Settings). Theme nav swaps "Profile" → "Passport" when `passport_enabled` (via `lnkup_passport_enabled()`); the existing profile editor stays reachable. The apply wizard shows Vault reuse with a consent checkbox.
- **Expo:** `app/(vendor)/passport.tsx` (single route, `?tab=`), `lib/api/passport.ts`, organizer `components/organizer-vendors/passport-panel.tsx` (indicators, confirmed history, tour-stop invites), Documents screen versions + "Shared with organizers" + stop sharing. Uses existing `expo-document-picker` (no new native module → OTA-compatible).
- **Next.js:** public reputation mapper uses indicators (no score), `/api/vendor/reviews/[id]/response` BFF route, `ReviewCard` response UI.

## 9. Feature flags (`lnkup_settings`, wp-admin → LNKup → Vendor Passport & Trust → Settings)

`passport_enabled` (master), `passport_verification_v2`, `document_vault_sharing`, `passport_history_v2`, `review_responses`, `matching_reputation_signals`; communications `flow_passport`. 27A hardening is not flagged.

## 10. Testing

- `scripts/e2e/php/vendor-passport-test.php` — 120+ assertions (public read safety, privacy, participation evidence, tour counting, achievements, review eligibility/privacy/duplicates, rating minimums, responses, reports, moderation, appeals, verification lifecycle/expiry/legacy mirroring, documents versions/reuse/sharing/tokens/revocation, organizer redaction, tour invites, strength, migration idempotency).
- `tests/e2e/vendor-passport.spec.ts` — public page, redirect, enumeration, page-source privacy, REST security, signed-in dashboard tabs and privacy toggle.
- `lnk-up-marketplace-mobile/__tests__/passport.test.ts`, `npm run phase27:verify` (mobile), `node scripts/phase27-verify.mjs` (repo).

## 11. Rollback

Turn off the Passport flags (and `flow_passport`). All migrations only add tables/meta; legacy meta (`_lnkup_verification_status`, `_lnkup_gallery_ids`, review `_lnkup_status`) is kept in sync, so older code paths keep working. Tables can remain in place. To restore the old public URL format, disable permalinks for `/vendors/{slug}` by removing the rewrite (legacy `?vendor=` keeps working).

## 12. Known limitations

- No malware scanning (no scanner on the host); uploads are type/size-validated and private.
- Coverage amount / named insured are vendor-entered metadata; organizers still review the document.
- No portfolio video; no on-device image compression in Expo (server enforces 8 MB).
- Tour grouping depends on `_lnkup_tour_id`; events without it are standalone.
- QR codes use the same external image service as event passes (public URL only).
- Internal score inputs changed (completion definition, flags, disputes), so historical score snapshots are not directly comparable across the release.

# Organizer Acquisition OS — Audit & Architecture (Phases 0–3)

Status: implemented in plugin v12.25.0 behind feature flags (`organizer_growth_enabled`, `organizer_growth_outreach_enabled`, both **off** by default).
Scope of this pass: Stage 0 integrity fixes, lead CRM, import, scoring, pipeline, Organizer Growth admin, compliant outreach.
Phases 4–8 (onboarding center, connector/organizer referral views, vendor supply intelligence, analytics/automations, full mobile) are **not** built yet.

All paths below are relative to `wp-content/plugins/lnkup-marketplace/` unless noted.

---

## 1. What already existed (reused, not rebuilt)

| Area | Existing implementation | How the Acquisition OS uses it |
|---|---|---|
| Organizer identity | WordPress user with role `lnkup_organizer` + `_lnkup_organizer_*` user meta (`public/class-registration.php`, `includes/class-organizer-onboarding.php`). No organization entity. | Leads link to organizer users via `lnkup_org_lead_accounts` (one org → many users, one user → one org). No duplicate accounts. |
| Roles / caps | `LNKup_Roles` (`lnkup_vendor`, `lnkup_organizer`, `lnkup_connector`, `lnkup_admin`), `LNKup_Capabilities` | New cap `lnkup_manage_organizer_growth` for `lnkup_admin` + `administrator`. |
| Event lifecycle | Events are inserted as published posts; `_lnkup_event_status` flips `draft`↔`live` via `LNKup_Organizer_Onboarding::sync_event_listing_status()`. `LNKup_Lifecycle_Hooks` fires `lnkup_event_status_changed` for every write and `lnkup_event_published` on post publish. | New once-only `lnkup_event_first_live( $event_id, $organizer_id )` (guarded by `_lnkup_first_published_at`). Post publication is not a usable milestone because slots don't exist yet at insert. |
| Applications | `lnkup_application_submitted( $app_id )` (`class-applications.php`), `lnkup_application_status_changed` | Drives `first_application` milestone. |
| Payments | `lnkup_payment_completed( $payment_id )` (`financial/class-financial-service.php`, under per-payment lock) | Drives `first_paid_booking`, only when `_lnkup_payment_status = paid`. |
| Earned fees | `wp_lnkup_platform_fees` via `LNKup_Platform_Fees` | Acquisition revenue reads `sum_net_platform_fees()` (new, refund-adjusted). |
| Outreach | `LNKup_Outreach` + `LNKup_Outreach_Cron`, tables `lnkup_outreach_campaigns` / `lnkup_outreach_recipients`, hourly/daily limits, signup token → `mark_registered()` | Extended with `lead_id`, template support, suppression checks, unsubscribe, per-campaign cap, approval gate. |
| Attribution | City Connector (`LNKup_Connector_Attribution`, cookie `lnkup_connector_ref`), organizer recruit (`LNKup_Organizer_Recruit_Referrals`, cookie `lnkup_org_ref`), vendor referral (`LNKup_Referral_Credits`, cookie `lnkup_ref`); shared `wp_lnkup_referrals` / `wp_lnkup_referral_rewards` | Read-only for lead linking (`program` + `referrer_user_id`). Commission logic is untouched apart from Stage 0 bug fixes. |
| CSV import | `LNKup_Vendor_Import` (organizer vendor prospects) | Same parsing approach (`fgetcsv`, header normalization, guessed mapping) in `LNKup_Org_Lead_Import`. |
| Communications OS | `wp-content/plugins/lnkup-communications/` (untracked, in progress): hashed-email suppression, HMAC one-click unsubscribe, provider bounce webhooks | When `lnkup_comms_loaded` has fired, lead suppression is mirrored into `LNKup_Comms_Suppression` and checked before sends. Lead outreach keeps its own suppression table because leads are not WordPress users. |
| Jobs | WP-Cron (`lnkup_marketplace_daily`, single events) | Import batches and campaign queue use single events; scoring refresh + task digest hang off `lnkup_marketplace_daily`. |

## 2. Gaps found

1. No organizer **lead / prospect / partner** model; no pipeline; no lead scoring.
2. No **organization** concept — multi-brand production companies could not be counted once.
3. Email outreach had **no suppression list, no unsubscribe link, no List-Unsubscribe header, no bounce handling** in the marketplace plugin.
4. No milestone hook for "event actually went live".
5. No marketing-spend records → CAC / LTV cannot be computed (and are not shown).
6. Six Stars Entertainment exists only as organizer user #537 in `docs/finance/RECONCILIATION_2026-11-21.md`; nothing in code separates house organizers from independent ones.

## 3. Money-integrity bugs found and fixed (Stage 0)

| # | Bug | Fix |
|---|---|---|
| 1 | `LNKup_Referral_Credits::on_payment_completed` matched any referral row for the vendor — a connector- or organizer-recruit-referred vendor triggered a `first_invoice_paid` vendor-referral credit to that referrer. | Query restricted to `program = 'vendor_referral'`. |
| 2 | Stripe Dashboard refunds (`charge.refunded`) and dispute credits never fired `lnkup_refund_completed`, so connector rewards / rank attribution were not reversed. | `LNKup_Refunds::sync_payment_refund_status()` (reached by every refund path) now fires `lnkup_payment_refund_synced( $payment_id, $returned_cents, $is_full )` once per increase in the cumulative refunded amount (lock + `_lnkup_refund_sync_announced_cents`). Connector rewards/ranks moved off `lnkup_refund_completed` onto the new `LNKup_Referral_Reversals` service, so admin, webhook and dispute paths share one code path. |
| 3 | Organizer-recruit rewards were inserted as `paid` + credited and never reversed. | Proportional reversal per refunded share; unspent credit is revoked from the wallet, any spent shortfall is audit-logged (`organizer_recruit_clawback_review`). New `reversed_amount` column on `wp_lnkup_referral_rewards`. |
| 4 | `resolve_platform_fee_for_payment()` read `_lnkup_fee_breakdown` as an array; it is stored as JSON. | JSON-decoded; prefers `lnkup_platform_fee`. |
| 5 | `wp_lnkup_platform_fees` was never adjusted for refunds. | New `refunded_platform_fee` column, maintained by `LNKup_Platform_Fees::sync_refunded_fee()`; `sum_net_platform_fees()` / `net_fee_for_payment()` for reporting. Lost chargebacks (`lnkup_payment_chargeback_lost`, new) forfeit the full fee. |
| 6 | Paid connector rewards on refunded bookings were only audit-logged, repeatedly. | Flagged once per reward (`connector_reward_clawback_review`), payment meta guard. |

Net-fee model: LNKup returns its fee in proportion to what the vendor got back (platform charges refund from LNKup's balance; destination refunds reverse only the organizer's transfer, so LNKup covers the fee share). This is derived from the recorded refund amounts, not estimated.

## 4. Security concerns noted (not all fixed in this pass)

- **Self-referral checks are minimal** (only "code owner ≠ new user"). No IP/device/payment-method matching. Phase 5.
- **Connector share is 10% of booth price** (`connector_booking_share_percent`), which can exceed LNKup's own fee (6% + $5.99) on booths above ~$150. Rewards are disabled by default (`connector_rewards_enabled = false`). Flagged for business review; not changed.
- **Vendor discovery has no opt-in flag** (`lnkup_get_discoverable_vendors()` returns every published profile). Phase 6 must add one before showing supply counts to prospects.
- Lead contact data is PII: growth routes require `lnkup_manage_organizer_growth`; connectors cannot read the lead CRM in this pass; suppression stores SHA-256 hashes only; activity log stores actor IDs, not IPs.

## 5. Architecture (as built)

```
CSV import ─┐
Admin entry ─┼─> lnkup_org_leads ──> pipeline stages ──> LNKup_Outreach (lead campaigns, templates)
            │        │                        ▲                    │
            │        └─ lnkup_org_lead_accounts (lead ↔ organizer users)
            │                                 │                    ▼
lifecycle hooks (first_live / application / payment / refund) ─> lnkup_org_lead_milestones
                                                                   │
net platform fees (refund-adjusted) ───────────────────────────> Growth overview
```

### Tables (`includes/organizer-growth/class-org-growth-tables.php`, schema option `lnkup_org_growth_schema`)

`lnkup_org_leads`, `lnkup_org_lead_events`, `lnkup_org_lead_accounts`, `lnkup_org_lead_activity`, `lnkup_org_lead_tasks`, `lnkup_org_lead_tags`, `lnkup_org_lead_saved_views`, `lnkup_org_lead_imports`, `lnkup_org_lead_import_rows`, `lnkup_org_lead_milestones`, `lnkup_org_partners`, `lnkup_org_outreach_templates`, `lnkup_contact_suppression`.
Outreach recipients gained `lead_id`, `template_key`, `skip_reason`.

### Stage rules

- Manual moves allowed for stages 1–12 (Discovered … Onboarding) and outcomes.
- Stages 13–17 (First Event Published, First Application Received, First Paid Booking, Activated, Repeat Organizer) are **milestone-only**; the REST API rejects manual moves into them (`lnkup_stage_milestone_only`).
- `Do Not Contact` adds the lead's email and domain to suppression.
- Milestones are unique per `(lead_id, milestone)`; counted per organization through the accounts table.

### Lead → account linking

1. Outreach token on signup (`LNKup_Outreach::mark_registered` → recipient `lead_id`).
2. Verified email match (lead public email = new user email).
3. Domain match → **suggestion only**, admin confirms in the lead drawer.
4. Manual link by admin.
Linking back-fills milestones from existing data (first live event, first application, first paid booking).

### Six Stars / house organizers

Setting `org_growth_house_organizer_ids` (comma-separated user IDs). Leads linked to those users are flagged `is_house_affiliated`; the overview reports house vs independent separately.

## 6. REST API (`lnkup/v1/growth/*`)

All routes require `lnkup_manage_organizer_growth` and return JSON (`WP_Error` with explicit status). While `organizer_growth_enabled` is off only `bootstrap` and `settings` respond (403 `lnkup_growth_disabled` otherwise). The only public route is `growth/unsubscribe` (HMAC token).

| Area | Routes |
|---|---|
| Module | `bootstrap`, `settings` (GET/POST), `overview?affiliation=` |
| Leads | `leads` (GET paginated + filters / POST), `leads/{id}` (GET/PATCH), `leads/bulk`, `leads/export`, `leads/duplicates`, `leads/{id}/stage`, `leads/{id}/activity` (GET / POST = log contact), `leads/{id}/tasks`, `leads/{id}/events`, `leads/{id}/link-account` (POST/DELETE), `leads/{id}/merge`, `leads/{id}/not-duplicate`, `leads/{id}/suppress`, `accounts/search` |
| Pipeline & tasks | `pipeline`, `tasks`, `tasks/{id}/complete` |
| Import | `import` (history), `import/template`, `import/upload` (multipart), `import/{id}`, `import/{id}/preview`, `import/{id}/run`, `import/{id}/rollback` |
| Scoring & views | `scoring/config` (GET/POST), `views` (GET/POST), `views/{id}` (DELETE) |
| Outreach | `templates`, `templates/{key}` (PUT), `templates/{key}/preview`, `campaigns` (GET/POST), `campaigns/{id}`, `campaigns/{id}/approve`, `campaigns/{id}/queue`, `campaigns/{id}/cancel`, `suppression` (GET/POST/DELETE), `unsubscribe` (public) |

Unsubscribe pages are served from `/?lnkup_org_unsub=TOKEN` (GET confirm page, RFC 8058 one-click POST).

## 7. Testing evidence (wp-env, 2026-11-22)

| Suite | Result | Covers acceptance tests |
|---|---|---|
| `scripts/e2e/php/org-growth-schema-check.php` | 31/31 pass | migrations, caps, 64 routes all with `permission_callback` |
| `scripts/e2e/php/org-growth-test.php` | 125/125 pass | 1 import + dry run + rollback · 2 dedupe/merge · 3 connector assignment · 4 pipeline guards · 5 outreach logging · 6 unsubscribe/suppression + campaign approval, caps, staleness, dedupe window, CAN-SPAM footer/headers · 7–8 attributed registration links the existing account · 10–14 milestones from real hooks · 15 attribution persists · 16 refund voids paid milestone and fee · 17–18 second event → repeat, counted as one org · 19 organizer/connector/vendor/anonymous get 401/403 · 20 webhook retries don't duplicate milestones |
| `scripts/e2e/php/org-growth-integrity-test.php` | 28/28 pass | 16 + 20: program filter, JSON fee fallback, one announcement per refund increase, net fees, organizer-recruit clawback, connector reversal + single clawback flag, lost chargeback, admin credit-refund path |
| Existing 13 financial PHP suites (`run-financial-php.ps1`) | 13/13 pass | regression check for refund/reward changes |
| `tests/e2e/organizer-growth.spec.ts` (Playwright, chromium) | 7/7 pass | UI: import with dry run, duplicate detection, logging outreach + stage move, milestone stage refused, Kanban, suppression + invalid unsubscribe link, overview |

**Phase 4 update (v12.29.0):** acceptance 9 (organizer finishes onboarding) and 17 (second event / saved setup) are now covered by `scripts/e2e/php/organizer-activation-test.php` (69/69) and `tests/e2e/organizer-start.spec.ts` (4/4). Historical refunded fees can be fixed with the `sync_refunded_fee` repair (`financial-fee-backfill-test.php`, 16/16).

Not run: real Stripe test-mode charges/refunds (acceptance 13) — follow [finance/STRIPE_TEST_MODE_RUNBOOK.md](finance/STRIPE_TEST_MODE_RUNBOOK.md) and record the four `org-growth-stripe-check.php` outputs here. Payments were simulated with payment posts + the real `lnkup_payment_completed` / refund-sync code paths; the Stripe webhook handler itself was not exercised end to end. Acceptance 9 (onboarding) is Phase 4.

Run locally: `powershell -File scripts/e2e/run-org-growth-php.ps1` and `PLAYWRIGHT_ADMIN_USER=admin PLAYWRIGHT_ADMIN_PASS=password npx playwright test tests/e2e/organizer-growth.spec.ts --project=chromium`.

## 8. Deployment

- Bump to plugin **12.25.0** runs `lnkup_maybe_upgrade_db()` → `LNKup_Org_Growth_Tables::create_tables()` (additive tables + `ALTER TABLE … ADD COLUMN` on outreach tables) and `LNKup_Treasury_Tables` dbDelta (`refunded_platform_fee`, `reversed_amount`). Role capability sync adds `lnkup_manage_organizer_growth`.
- Flags (option `lnkup_org_growth_settings`, editable in Organizer Growth → Settings): `organizer_growth_enabled`, `organizer_growth_outreach_enabled` — both **off**.
- Before any outreach: set **sender postal address** (queueing is refused without it), set house-affiliated organizer IDs (Six Stars = user #537 per reconciliation doc — confirm before entering), review thresholds.
- Cron: `lnkup_marketplace_daily` (+ rescoring, task digest), single events `lnkup_org_lead_import_batch`, `lnkup_org_growth_rescore`, existing `lnkup_outreach_process_queue`. Import status polling also advances batches when WP-Cron is slow.
- Existing data: platform-fee rows refunded **before** this release keep `refunded_platform_fee = 0` until their next refund sync; run a backfill (call `LNKup_Platform_Fees::sync_refunded_fee()` for refunded payments) before trusting historical net revenue.
- Behaviour change: connector reward/rank reversal now also runs for Stripe Dashboard refunds and dispute credits (previously admin refunds only).
- Rollback: turn both flags off (UI and REST go dark, unsubscribe keeps working). Tables/columns are additive and safe to leave.

## 9. Not done in this pass (honest list)

- XLSX import (needs a new Composer dependency) — the importer asks for CSV.
- Reply detection / opens — only manual logging and the existing signup-link token; no tracking pixel.
- Connector-facing lead views, organizer referral dashboard, anti-fraud, configurable organizer-acquisition rewards (Phase 5).
- Onboarding center, application-question builder, migration tools (Phase 4).
- Vendor supply intelligence (Phase 6), Expo screens (Phase 8).
- Phase 4 (onboarding/activation) shipped in v12.29 — see SYSTEM_OVERVIEW §9.21.

## 10. Phase 7 — analytics, spend and activation automations

Closes gap 5. Cost per acquisition now exists, but only from real records: spend entered in the Spend tab plus approved/paid organizer-referral commissions net of reversals. A channel with neither shows **Unavailable**; commissions without spend is labelled **Commissions only**. LTV is still not shown, because there isn't enough paid history to compute it honestly. Cohorts, time-to-milestone, conversion by dimension, productivity and the at-risk list come from recorded milestones, payments and platform-fee rows, with the house/independent split throughout. The activation automations in `lnkup-communications` (flag `flow_activation`, off by default) are re-checked against the activation model before each send. Architecture: SYSTEM_OVERVIEW §9.23.

Still not done: LTV, spend attribution by city or connector beyond the per-channel split, automated import of ad-platform spend (manual entry/CSV only).

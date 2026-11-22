# Referral, Affiliate & Partnership OS — Foundation (plugin 12.28.0)

Stage 1–3 slice of the Partnership OS: audit, unified program/rule/attribution infrastructure, ledger
hardening, an admin hub, and City Connector lead submission + territories. Built on the existing
referral treasury tables, City Connector program and Organizer Acquisition OS — nothing was rebuilt
and no historical row is rewritten.

## A. Audit (what existed before this change)

| Area | Existing implementation |
|------|-------------------------|
| Shared referral ledger | `wp_lnkup_referral_codes`, `wp_lnkup_referrals`, `wp_lnkup_referral_rewards` (`LNKup_Treasury_Tables`), each row tagged with `program` |
| City Connector (`city_connector`) | `LNKup_Connector*` classes, 21 `connector/*` REST routes, dashboard, ranks + rank bonuses, content studio, wp-admin Connectors page |
| Connector commission | **10% of booth GMV** (`_lnkup_vendor_spot_fee`), optional per-booking cap, 12 months from the referral's first paid booking, 30-day hold, $50 minimum, manual approve / mark paid. `connector_rewards_enabled` defaults to **off** |
| Connector attribution | `?ref=` cookie (30 days) → referral row on `user_register`; organizer side wins over vendor side on a payment |
| Referral invites (`organizer_recruit`) | Vendors/organizers refer organizers or vendors; **20% of LNKup platform fee** for 12 months as wallet credit (not cash) |
| Vendor referral (`vendor_referral`) | One-time credit (credit rule `first_invoice_paid`, default $10) on the referred vendor's first paid booking |
| Refunds / disputes | `LNKup_Referral_Reversals` on `lnkup_payment_refund_synced` and `lnkup_payment_chargeback_lost` |
| Organizer Acquisition OS | Leads with `assigned_connector_id`, `referrer_user_id`, `referral_code`, `partner_id`, `is_house_affiliated` (Six Stars), `org_partners`, dedupe, suppression, milestones |
| Payout rail | None for partners. Connector payouts are manual; `payout_preference` is free text |

**Gaps fixed in this slice**

1. Cross-program stacking: each program attributed and paid independently, so one booking could pay a
   connector, an organizer referrer and a vendor referrer.
2. Any partial refund reversed 100% of unpaid connector rewards.
3. `mark_paid()` had no status guard (reversed/paid rows could be re-marked); `approve_reward()` was not atomic and ignored the hold period.
4. Paid-reward clawbacks only lived in post meta + audit log.
5. Rewards did not record which rule/rate produced them.

## B. Architecture

```mermaid
flowchart TD
  pay[lnkup_payment_completed] --> handlers[Program handlers]
  handlers --> arb[LNKup_Attribution_Arbiter]
  arb -->|"allow or block"| rules[LNKup_Commission_Rules]
  rules -->|"amount + rule stamp"| ledger[(lnkup_referral_rewards)]
  refund[LNKup_Referral_Reversals] -->|"refund ratio"| ledger
  ledger --> hub[Partnerships and Referrals admin]
  arb --> reviews[(lnkup_partner_reviews)]
  ledger --> reviews
```

Module: `wp-content/plugins/lnkup-marketplace/includes/partnerships/`

| Class | Role |
|-------|------|
| `LNKup_Partner_Tables` | Schema (`lnkup_partner_schema` option), additive columns, idempotency backfill |
| `LNKup_Partner_Programs` | Registry: `city_connector`, `organizer_recruit`, `vendor_referral` (active) + `affiliate`, `strategic_partner`, `enterprise_partner` (disabled placeholders) |
| `LNKup_Commission_Rules` | Versioned, immutable rules; calculation; rule locking |
| `LNKup_Attribution_Arbiter` | One winner per referred account and per payment; conflicts; backfill cron |
| `LNKup_Partner_Reviews` | Review queue (conflicts, clawbacks), deduped by key |
| `LNKup_Partnerships` | Read models (overview, pipeline, ledger), territories, connector leads, settings |
| `LNKup_Partnerships_REST` | `lnkup/v1/partners/*` + `connector/leads`, `connector/territories` |
| `LNKup_Partnerships_Admin` | wp-admin → LNKup → Partnerships & Referrals |

## E. Database changes (additive only)

New tables: `lnkup_partner_programs`, `lnkup_commission_rules` (unique `program, version`),
`lnkup_connector_territories` (unique `connector_id, scope_type, value`), `lnkup_partner_reviews` (unique `dedupe_key`).

New columns:

- `lnkup_referrals`: `attribution_status` (`winning|superseded|conflict`), `rule_id`; indexes on `referred_user_id`, `attribution_status`.
- `lnkup_referral_rewards`: `reversed_amount`, `rule_id`, `rule_version`, `basis`, `basis_amount`, `currency`, `payout_reference`, `hold_reason`, `held_from_status`, `idempotency_key` (now **nullable** + **unique**), indexes on `payment_id`, `(program, reward_status)`.
- `lnkup_connectors`: `commission_rule_id`.

Idempotency backfill: every existing reward gets `program:user_id:referral_id:payment_id:event_type`;
true historical duplicates keep a `:legacy:<id>` suffix so the unique index can be created without
touching amounts or statuses. Rows written by code that sets no key stay `NULL` (never collide).

Migration runs on `init` when `lnkup_partner_schema` ≠ `1.0.0`. Seeding never overwrites existing rows.

## G. Commission rules

| Program | Version | Basis | Terms | Status |
|---------|---------|-------|-------|--------|
| City Connector | v1 (legacy settings) | booth GMV | `connector_booking_share_percent` (10%), cap, term, hold from settings | active — **every approved connector at migration is locked to v1** |
| City Connector | v2 | LNKup platform fee | rate unset | **draft** — publish in the admin hub after setting a rate |
| Referral invites | v1 (legacy settings) | LNKup platform fee | `organizer_recruit_percent` (20%), `organizer_recruit_months` (12) | active |
| Vendor referral | v1 (legacy credit rules) | fixed | credit rule amount (default $10), first paid booking, `payment_stackable` | active |

- **Immutability:** published rules are never edited. "Create draft" + "Publish" supersedes the previous
  version (`status = superseded`, `effective_to` set) — it keeps paying anyone locked to it.
- **Locking:** a connector locks the rule in force at `approved_at` (stamped on approval / first use).
  A referral locks the rule in force at its `created_at` (connector referrals use the connector's rule).
- **Legacy settings rules** (`source = settings`) keep reading the existing settings keys so the
  current Settings screen behaves exactly as before.
- **Basis:** `booth_gmv` = `_lnkup_vendor_spot_fee`; `platform_fee` = `_lnkup_application_fee_cents`
  → `_lnkup_platform_fee` → fee snapshot → `wp_lnkup_platform_fees` (same resolver the referral-invite
  program and its reversals already use). Gross booking value is never the basis for new rules unless an
  admin explicitly picks `booth_gmv`; the form warns that a GMV share can exceed LNKup's revenue.
- **Window:** connector term starts at the referral's first paid booking (unchanged); referral-invite
  window starts at attribution (unchanged).

## H. Attribution and stacking

- **Per account:** across all programs, the earliest referral row wins (`created_at`, then `id`); later rows are `superseded`.
- **Conflict:** a second, different referrer inside `partnerships_conflict_window_hours` (default 72)
  marks both claims `conflict` and opens one review. Rewards for conflicted claims are withheld and the
  payment IDs are recorded; resolving the review picks the winner and **replays only the idempotent
  reward step** of the winning program for those payments.
- **Per payment:** organizer-side winner first, then vendor-side. Only one `exclusive` rule pays per
  payment; `payment_stackable` rules (vendor referral credit) may also pay.
- **Rollout mode** (`lnkup_partnerships_settings.partnerships_arbiter_mode`): `shadow` (default — logs
  `partner_arbiter_shadow_block` to the financial audit log, never blocks), `enforce`, `off`.
- Self-referral prevention stays in each program's attribution code (unchanged).

## Ledger lifecycle

`pending` (hold → awaiting approval) → `approved` → `paid`, plus `on_hold` (review) and `reversed`.

- Approve requires the hold to have ended and a positive net amount; approve / mark-paid / hold /
  release are conditional updates (`WHERE reward_status = …`), so a second request gets `409 lnkup_reward_state`.
- Mark paid records an admin-entered `payout_reference`. It records that a payout was sent outside LNKup;
  it does not move money.
- Partial refunds raise `reversed_amount` proportionally (status unchanged); 100% sets `reversed`.
  Balances everywhere use **net** = `amount − reversed_amount`.
- Paid rewards are never changed; the owed share opens one `clawback` review (amount raised on later
  partial refunds). Admin records recovered / written off / not owed.
- Referral-invite and vendor-referral rewards remain automatic wallet credits; their reversals are unchanged.

## F. REST (`lnkup/v1`)

| Method | Route | Permission |
|--------|-------|-----------|
| GET | `/partners/overview` | `user_has_manage_all` |
| GET | `/partners/referrals?program&attribution_status&market&role&page&per_page` | manage_all |
| GET | `/partners/ledger?program&status&connector_id&page&per_page` | manage_all |
| POST | `/partners/ledger/{id}/approve\|hold\|release\|mark-paid` (`reason`, `payout_reference`) | manage_all |
| GET/POST | `/partners/rules` | manage_all |
| POST | `/partners/rules/{id}/publish` | manage_all |
| GET | `/partners/reviews?type&status` | manage_all |
| POST | `/partners/reviews/{id}/resolve` (`winner_referral_id` or `resolution`, `notes`) | manage_all |
| GET/POST/DELETE | `/partners/territories` | manage_all |
| GET/POST | `/connector/leads` | active connector (own leads only) |
| GET | `/connector/territories` | active connector |

Errors are `WP_Error` JSON with a status; 503 `lnkup_partners_not_ready` before migration.

**Connector lead submission:** requires a name and a public route (website, Instagram or business
email). Existing LNKup accounts, duplicate leads (dedupe on email/domain/Instagram/linked account) return
only `{ status: "already_in_pipeline" }`; suppressed contacts return `not_accepted`. New leads go into
Organizer Growth with `source = city_connector`, `assigned_connector_id`, `referrer_user_id` and the
connector's code. 20 submissions per connector per day. Connectors see only leads assigned to or
submitted by them, without contact data or scores.

## Surfaces

- wp-admin → LNKup → **Partnerships & Referrals**: Overview (real counts only; house vs independent via
  `org_growth_house_organizer_ids`; 10-minute cache flushed on ledger writes), Referral Pipeline,
  Commission Rules, Commission Ledger (+ CSV export with formula-injection guard), Reviews, City
  Connectors (territories; links to the existing Connectors page), Settings.
- Connector dashboard: new **Organizer Leads** and **My Cities** views (shortcode tabs + theme sidebar);
  earnings copy uses the connector's rule ("10% of the booth price…" vs "X% of LNKup fees…"); the
  planner/estimate uses the same rule and is hidden for non-GMV rules where a GMV estimate would mislead.
- Hooks for Communications OS: `lnkup_partner_commission_recorded`, `lnkup_partner_commission_approved`,
  `lnkup_partner_commission_paid`, `lnkup_partner_review_opened`.

## K. Testing (local wp-env, PHP 8.1)

- `scripts/e2e/php/partnerships-test.php` — **116 assertions, all passed** (schema, rules, ledger,
  reversals, clawback, arbiter per account/per payment, duplicate webhook, leads privacy, territories, REST permissions).
- Existing suites after the change: Organizer Growth PHP (3 suites) **passed**; financial PHP (14 suites) **passed**.
- `tests/e2e/partnerships.spec.ts` — **10/10 passed** on Chromium (seed a connector first with
  `scripts/e2e/php/partnerships-seed-connector.php`; env `PLAYWRIGHT_ADMIN_USER/PASS`, `PLAYWRIGHT_CONNECTOR_USER/PASS`).
- `node scripts/partnerships-foundation-verify.mjs` — 13 static checks passed.
- Not run: the `mobile` Playwright project (WebKit browser not installed locally). Pre-existing failure:
  `connector-program.spec.ts` "interest page renders form with market context" (strict-mode match on "Charlotte"; page not touched here).

Runners: `powershell -File scripts/e2e/run-partnerships-php.ps1`.

## L. Deployment

1. Deploy plugin 12.28.0 or later + theme (`inc/dashboard.php` nav). Migration runs on first request.
2. Leave `partnerships_arbiter_mode = shadow`; review `partner_arbiter_shadow_block` audit rows and
   the Reviews tab (the backfill cron marks historical conflicts) before switching to `enforce`.
3. Publish City Connector v2 only after the fee-share rate and agreement are approved. Existing connectors stay on v1.
4. Rollback: deactivate/redeploy 12.27.x. New tables/columns are additive and ignored by older code;
   set arbiter mode `off` to disable all new gating without a redeploy.
5. `dist/lnkup-marketplace.zip` was **not** rebuilt in this change (the working tree also contains other
   in-progress work); rebuild with `scripts/build-plugin-zip.ps1` when releasing.

## M. Remaining (not built)

- Stage 4–5: affiliate / strategic / enterprise applications, approval statuses, public program pages
  (registry slots exist, disabled). Organizer-dashboard "Refer an Organizer" already exists via `organizer_recruit`.
- Stage 7 payout rail: partners are paid manually; no Stripe transfers. Paying affiliates/connectors
  through Stripe Connect needs a recipient-account, KYC and tax (1099) review first — **blocked on compliance/tax review**.
- City Connector mobile screens (Expo) — not built; REST routes are ready for them.
- Campaigns, partner resource library expansion, geographic opportunity analytics, Communications templates.
- Tour-level reporting: attribution is per payment, so tours never double count, but no tour rollup view exists yet.
- Rank bonuses are still decided by `LNKup_Connector_Ranks` and are not gated by the arbiter.
- Nothing here establishes legal, tax or affiliate-disclosure compliance; program terms and disclosures need review.

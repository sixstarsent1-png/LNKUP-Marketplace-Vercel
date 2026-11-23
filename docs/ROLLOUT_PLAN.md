# LNKup staged rollout plan

How the features built in the buildout (PRs #9 to #16) go from "merged, flags off" to "on for everyone". Nothing here is switched on by merging. Each step is a settings change made by a named owner, and every step can be reversed by turning the same setting back off.

Related: [BUILDOUT_CHECKLIST.md](BUILDOUT_CHECKLIST.md) (what was verified), [MATCHING_DISCOVERY.md](MATCHING_DISCOVERY.md) (matching gates), `wp-content/plugins/lnkup-marketplace/FINANCIAL.md` (money).

## Controls

| Control | Where | What it does |
|---|---|---|
| `organizer_os_pilot_user_ids` | LNKup settings | Organizer IDs (and their team members) who get Organizer OS flags while the global flag is off: `application_review_v2_enabled`, `waitlist_offers_enabled`, `organizer_ops_v2_enabled`, `organizer_document_review_enabled`. |
| `portfolio_os_user_ids` | LNKup settings | Organizer IDs who get `portfolio_os_enabled` (tours, series, templates, bulk pricing) while it is off globally. Public tour pages only follow the global flag. |
| `LNKup_Rollout` | wp-admin rollout screen, `wp lnkup rollout` | Matching, apply and discovery-message flags. Stages `internal` (admins and allowlist), `p5`, `p25`, `p50`, `all`, with a stable per-user bucket. Widening runs gates and needs an override note to skip one. Every change is logged. |
| Partnerships arbiter mode | Partnerships settings | `off`, `shadow` (log conflicts, allow), `enforce`. The readiness tab shows the blockers. |
| Communications flows | Communications settings | One switch per flow, plus `delivery_paused` for everything. |
| `ENABLE_OPS_ANALYTICS` | Vercel project env | Shows the Admin Lite pages, including `/admin/ops`. |

## Stage 1: internal accounts

**Who:** LNKup staff and test accounts, flagged `_lnkup_test_account` so analytics and the ops queue ignore them.

**Turn on:**
- Put staff organizer IDs in `organizer_os_pilot_user_ids` and `portfolio_os_user_ids`.
- Set the rollout flags to `internal`: `apply_wizard_enabled`, `tour_multi_apply_enabled`, the matching flags.
- Set the partnerships arbiter to `shadow`.
- Set `ENABLE_OPS_ANALYTICS=true` on the Vercel preview only.

**Advance when:**
- At least 7 days have passed.
- All the PHP suites listed in the checklist pass on staging.
- The ops queue (LNKup → Operations queue) shows no critical items that came from these accounts.
- Payment reconciliation shows no errors.

## Stage 2: one Six Stars series plus one independent organizer

**Who:**
- One Six Stars tour or series (a house organizer, also listed in `org_growth_house_organizer_ids`).
- One independent organizer who agreed to pilot.

**Turn on:**
- Add both organizer IDs to `organizer_os_pilot_user_ids`.
- Add the Six Stars organizer to `portfolio_os_user_ids`.
- Leave the rollout flags at `internal` plus those organizers' vendors via the allowlist, or move them to `p5`.

**Advance when:** both organizers have completed one event from publish to check-in on the new flows, and the funnel and the ops queue show the signals in the table below.

## Stage 3: widening

- Turn the Organizer OS and Portfolio flags on globally, one flag at a time, at least 3 days apart.
- Widen the rollout flags `p5` → `p25` → `p50` → `all`. Each step needs the gates to pass and 3 days at the current stage.

## Evidence and pause signals, per workflow

| Workflow | Flags | Advance on | Pause if |
|---|---|---|---|
| Apply drafts and multi-stop apply | `apply_wizard_enabled`, `tour_multi_apply_enabled` | Funnel `apply_started` → `submitted` holds or improves vs. before; no `CHANGED_WHILE_APPLYING` spikes | Submitted count drops; drafts stuck (the ops queue shows info requests unanswered for 7 days rising) |
| Publish (event + booths) | `organizer_onboarding_v2_enabled` | No duplicate booths per launch (`client_token` lock); draft events stay hidden | A second opportunity row for one launch, or a draft visible on `wp/v2` |
| Application review and waitlist offers | `application_review_v2_enabled`, `waitlist_offers_enabled` | Review overdue items in the ops queue fall; one message per offer | The "waiting more than five days" ops item grows; any vendor gets both the approval and offer messages |
| Event operations and readiness | `organizer_ops_v2_enabled` | Readiness items under 100% within 14 days get resolved before the event | Events reach the day with readiness below 100% and no tasks assigned |
| Tours and series | `portfolio_os_enabled`, `portfolio_os_user_ids` | Per-stop rollup matches the per-event numbers; team members see only their scoped stops | A teammate without `view_financials` sees money |
| Matching and discovery | matching rollout flags | `LNKup_Rollout` gates: backfill done, queue fresh, under 20% vendors ungeocoded, under 10% opportunities uncategorized | Matching items in the ops queue; recommendations shown for closed opportunities |
| Discovery messages | `flow_discovery*`, `flow_recruitment*` | Gates: transactional sends on 7 or more days, unsubscribes and complaints under 2% | Unsubscribe or complaint spike; Communications health failing |
| Partner attribution | arbiter `shadow` → `enforce` | Readiness tab: 14+ days of shadow, backfill done, no stale conflict reviews, clean ledger integrity | A commission approved before its hold ended, or open conflict reviews older than the window |
| Ops queue | `ENABLE_OPS_ANALYTICS` | Staff use it daily; dismissed items come back only when they change | Items that never clear because the source is wrong; file a fix instead of dismissing |

## Dependencies that gate their own switch

Each of these stays off until its own dependency is met. They are not unlocked by the stages above.

- **Document sharing (`document_vault_sharing`, `organizer_document_review_enabled`):** needs private uploads to be protected on the host.
  - The private-uploads probe must show "protected". The SiteGround Direct Delivery exception is the hosting owner's job.
  - The settings save and the migration already refuse to turn sharing on while the probe reports the folder exposed.
- **Partner payouts:** payouts stay manual. Admins mark approved commissions paid, with a payout reference, after paying outside LNKup.
  - Automated payouts need partner identity verification (KYC) and 1099 reporting for US partners over the IRS threshold. No partner money moves through Stripe until the finance owner picks a provider for both.
  - Until then, partner apps show "Payable after a hold and LNKup approval" and never promise a payout date beyond the hold end.
- **New outbound messaging (any flow not yet sending in production):** needs Communications health to be clean.
  - Required: sender postal address, the bounce and complaint webhook, the Expo access token for push, and the background worker running.
  - The ops queue lists each of these until it is fixed. Turn new flows on one at a time, after transactional mail has been sending for 7 days.
- **Live card payments for new flows:** the test-mode card run on staging needs staging Stripe keys (finance owner). All buildout verification used test mode and synthetic fixtures.

## Rollback

- **Organizer OS and Portfolio:** remove IDs from the pilot lists, or turn the global flag off. The data stays, and the old screens come back.
- **Rollout flags:** `wp lnkup rollout set <flag> --audience=cohort --percent=0`, or step back one stage. Every change is in the rollout log.
- **Partnerships:** set the arbiter back to `shadow`. Commissions already recorded keep their status.
- **Communications:** turn the flow off, or set `delivery_paused`. Queued messages wait and are not dropped.
- **Code:** each phase is its own PR. Revert the PR (newest first), then redeploy the plugin.
  - New tables (`lnkup_ops_item_state`) are left in place and are harmless when unused.

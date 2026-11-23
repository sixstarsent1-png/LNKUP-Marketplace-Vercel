# Matching, match alerts and apply flows: staged rollout runbook

Plugin 12.33.0. Architecture: [MATCHING_DISCOVERY.md](MATCHING_DISCOVERY.md) §11. Communications flags: [COMMUNICATIONS_ROLLOUT.md](COMMUNICATIONS_ROLLOUT.md).

## How rollout works

Every feature keeps its own on/off switch. That switch is the kill switch: turning it off stops the feature for everyone, immediately.

The **audience** (option `lnkup_rollout`, class `LNKup_Rollout`) only narrows who sees a feature that is on:

| Stage | Who sees it |
|---|---|
| Internal | Allowlisted user IDs and admins (`lnkup_manage_all`) |
| 5% / 25% / 50% | The above plus a stable percentage of users |
| Everyone | All users (also the default when no audience is set) |

- Percentages use a stable bucket per user (`crc32(group:user_id) mod 100`). Raising 5% to 25% keeps the first 5% in.
- Flags share a bucket group so one person gets a coherent experience: `matching_vendor` (vendor recommendations, tours, match alerts, tour alerts, weekly matches), `matching_organizer` (Find Vendors, recruitment nudges, open spots), `apply` (apply drafts, multi-stop apply).
- Matches are computed for everyone as soon as the feature is on; only display and messages follow the audience.
- Signed-out visitors only see a cohort feature once it reaches Everyone. Cron and WP-CLI work counts as system and always runs.
- Communications re-checks the audience at send time (`cohort_excluded`). For delegated core messages (saved-search alerts, saved-event deadlines, invites), people outside the audience keep getting the core email.

Flags with a staged audience:

| Flag | Where it is switched on |
|---|---|
| `matching_vendor_discovery_enabled`, `matching_tours_enabled`, `matching_organizer_discovery_enabled` | Marketplace Intelligence → Matching Rules (needs `matching_enabled`) |
| `apply_wizard_enabled`, `tour_multi_apply_enabled` | LNKup → Settings |
| `flow_discovery`, `flow_discovery_tours`, `flow_discovery_weekly`, `flow_recruitment`, `flow_recruitment_updates` | LNKup → Communications → Settings |

## Where to manage it

- **wp-admin:** LNKup → Marketplace Intelligence → **Rollout & health**. One row per flag with on/off, audience, stage start, allowlist, the checks for the next stage, and history.
- **WP-CLI:**

```bash
wp lnkup rollout status
wp lnkup rollout set flow_discovery --audience=cohort --percent=0 --users=12,34 --note="Internal pilot"
wp lnkup rollout set flow_discovery --stage=p5 --note="Week 2"
wp lnkup rollout set flow_discovery --stage=p25 --note="Sign-off from ops" --override   # skips failing checks, logged
wp lnkup rollout set flow_discovery --audience=all --note="GA"
```

## Checks before widening

Widening the audience (internal → 5% → 25% → 50% → everyone) shows these checks first. Narrowing never needs them. Any failing check can be overridden with a note; the history records which checks were skipped.

| Check | Applies to | Passes when |
|---|---|---|
| Feature is switched on | all | the flag itself is on |
| Vendor index backfill complete | matching flags, multi-stop apply | no backfill is running |
| Recompute queue fresh, cron running | matching flags, multi-stop apply | oldest queued item under 2 hours, nightly rebuild scheduled, no health issues |
| Data quality | matching flags, multi-stop apply | under 20% of active vendors without a map location and under 10% of opportunities without categories |
| Transactional messages running | comms flows | transactional messages sent on 7+ of the last 14 days |
| No unsubscribe spike | comms flows | 5 or fewer unsubscribes/complaints in 3 days, or under 2% of promotional sends |
| Time at stage | all, when widening | 3+ days at the current stage |

## Recommended order

1. Deploy 12.33.0. Confirm Rollout & health shows the backfill complete and no health warnings.
2. Review Data Quality and Category Groups.
3. Turn on `matching_enabled`. Set `matching_vendor_discovery_enabled` to Internal with a few test vendors, then switch it on.
4. Ramp vendor recommendations 5% → 25% → 50% → everyone, 3+ days per stage.
5. Organizer Find Vendors (`matching_organizer_discovery_enabled`), then tours, the same way.
6. Communications: only after transactional flows have run cleanly for a week. Ramp `flow_discovery`, then `flow_discovery_tours`, `flow_discovery_weekly`, `flow_recruitment`, `flow_recruitment_updates`.
7. Apply flows: `apply_wizard_enabled`, then `tour_multi_apply_enabled`.

## What to watch at each stage

Marketplace Intelligence → **Overview** (last 30 days, observed, not causal):

- **Recommendation quality:** impressions, clicks, CTR, applications that followed a recommendation, approvals and paid bookings, by band, section and rules version. Strong matches should convert better than possible ones; if not, revisit the weights.
- **In audience vs. not yet:** applications per vendor and approval rate for vendors who see recommendations vs. those who do not yet.
- **Matching alerts:** sent, suppressed, opened, clicked and applied-after per trigger.
- LNKup → Communications → Health for bounces, complaints and unsubscribes.

## Tuning the ranking

Marketplace Intelligence → **Matching Rules**:

1. Change weights or the minimum score.
2. **Preview changes** scores a sample of vendors with the current and the new rules and shows recommended pairs, band shifts, factor contribution and examples gained / lost. Nothing is saved.
3. **Save as new version** requires a short note. Each version is kept with who and why; results and analytics record the rules version.
4. **Revert to this version** restores any earlier version (recorded as a new version).

Flag and invite-cap changes do not recompute matches; weight, minimum-score and category-group changes do.

## Health alerts

The nightly matching job (and every LNKup admin screen) checks for a stale recompute queue (over 2 hours), a stalled backfill (no progress for 30 minutes), 3+ failed recompute runs in a row, or a missing nightly schedule. Admins see a notice on LNKup screens and get an email (at most every 6 hours) at the support address.

## Rollback

- **One feature, everyone:** switch its flag off (Matching Rules, Settings, or `wp lnkup-comms flag <flag> --off --cancel-queued`).
- **Shrink the audience:** set the stage back to Internal or a lower percentage. No checks needed; queued messages for people now outside the audience are suppressed at send time.
- **Ranking change went wrong:** Matching Rules → Revert to the previous version.
- **Everything matching:** switch `matching_enabled` off; clients fall back to the legacy recommendations.

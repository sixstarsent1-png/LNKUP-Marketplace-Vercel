# Communications OS — Production Rollout (SiteGround)

How to ship **lnkup-communications** to thelnkup.com and enable the transactional flows one at a time, with a checkpoint and a rollback at every step.

Related docs: [SITEGROUND_DEPLOY.md](SITEGROUND_DEPLOY.md) (deploy mechanics), [COMMUNICATIONS_MATRIX.md](COMMUNICATIONS_MATRIX.md) (what each flow sends), [COMMUNICATIONS_AUDIT.md](COMMUNICATIONS_AUDIT.md), `wp-content/plugins/lnkup-communications/README.md`.

## Conventions

```bash
# One SSH session for everything below.
ssh -p 18765 -i ~/.ssh/lnkup_siteground USER@ssh.thelnkup.com
cd ~/www/thelnkup.com/public_html
```

All `wp` commands run from that directory. Keep a copy of every command's output in the rollout log, for example a ticket or a shared doc.

## Step 0 — Fix production error display (do this first)

Production currently prints PHP and database errors to visitors (seen as a Rank Math `ytd_rank_math_analytics_objects` error followed by "headers already sent"). In `wp-config.php`:

```php
define( 'WP_DEBUG', false );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_DEBUG_LOG', true );
@ini_set( 'display_errors', 0 );
```

Then fix the Rank Math table: **Rank Math → Status & Tools → Database Tools → Re-create Missing Database Tables**. If Analytics is not used, turn the module off instead.

## Step 1 — Preflight (read-only)

From your laptop. The file never needs to be uploaded:

```bash
ssh -p 18765 -i ~/.ssh/lnkup_siteground USER@ssh.thelnkup.com \
  "cd ~/www/thelnkup.com/public_html && wp eval-file -" < scripts/prod/comms-preflight.php > preflight-$(date +%F).txt
```

PowerShell:

```powershell
Get-Content -Raw scripts/prod/comms-preflight.php | ssh -p 18765 -i $HOME\.ssh\lnkup_siteground USER@ssh.thelnkup.com "cd ~/www/thelnkup.com/public_html && wp eval-file -" | Tee-Object "preflight-$(Get-Date -Format yyyy-MM-dd).txt"
```

**Stop if any line is `[FAIL]`.**

| Result | Action |
|---|---|
| WordPress below 6.9 | Update WordPress, or replace `lib/action-scheduler` with Action Scheduler 3.9.x before activating. |
| WP_DEBUG_DISPLAY fail | Do Step 0. |
| Core delegation seam / Lifecycle hooks warn | Deploy lnkup-marketplace first (Step 2 does this). |
| Other Action Scheduler copies | Acceptable. The newest registered copy wins; note the version. |

Keep the **Baseline** block. It holds the per-message counts from lnkup-marketplace for the last 7 days, and each flag checkpoint compares against it.

## Step 2 — Deploy with every flag off

1. GitHub → Actions → **Deploy WordPress to SiteGround** → Run workflow. This deploys lnkup-marketplace (seam hooks), lnkup-theme (nav filter) and lnkup-communications. The laptop fallback is `scripts/deploy-siteground.ps1`.
2. Activate the plugin:
   ```bash
   wp plugin activate lnkup-communications
   wp lnkup-comms status
   ```
3. **Checkpoint:**
   - [ ] Every flag reads `off`, and "Core messages delegated to this plugin: none".
   - [ ] Preflight rerun shows "Communications tables: all present".
   - [ ] `https://thelnkup.com/notification-settings/` loads for a vendor test account.
   - [ ] wp-admin → LNKup → Communications opens on every tab.
   - [ ] The next day, the core notification counts are in line with the baseline. Nothing should change while flags are off.

**Rollback:** `wp plugin deactivate lnkup-communications`. Core behaviour is unchanged because the delegation filter disappears with it. Queued plugin messages stop too, because nothing runs the worker.

## Step 3 — Real cron runner

1. Site Tools → **Devs → Cron Jobs** → add a job:
   - Interval: every minute (`* * * * *`).
   - Command:
     ```bash
     cd /home/customer/www/thelnkup.com/public_html && wp action-scheduler run --batch-size=50 --quiet && wp cron event run --due-now --quiet
     ```
     If Site Tools rejects `wp`, use the full path from `which wp`, for example `/usr/local/bin/wp`.
2. Wait 10 minutes, then add to `wp-config.php` (above "That's all, stop editing"):
   ```php
   define( 'DISABLE_WP_CRON', true );
   ```
3. **Checkpoint**, after 2 hours:
   ```bash
   wp lnkup-comms status            # "Background worker" = ok
   wp cron event list --fields=hook,next_run_relative | grep lnkup_financial_hourly
   wp action-scheduler list --group=lnkup-comms --status=complete --per-page=5
   ```
   - [ ] Background worker is `ok`.
   - [ ] `lnkup_financial_hourly` runs within the hour. This also fixes the stalled financial sync found in the reconciliation.
   - [ ] No `[WARN] Overdue WP-Cron events` on the preflight rerun.

**Rollback:** remove `DISABLE_WP_CRON` and delete the cron job.

## Step 4 — Settings before any flag

wp-admin → LNKup → Communications → **Settings**:

- [ ] **Sender postal address** (CAN-SPAM).
- [ ] **Internal test account IDs**: one vendor test account and one organizer test account, each with an application and an event in production.
- [ ] Email channel on, push channel on, Android channels **off** until the mobile update ships.
- [ ] LNKup → Settings: `support_email`, and the Expo access token if Expo enhanced push security is enabled.

## Step 5 — Transactional flows, one at a time

Leave 24 to 48 hours between flags. For each flag, run the same loop:

```bash
# a) Test against the internal test account (bypasses the flag; records must belong to the test account)
wp lnkup-comms test <trigger> --user=<TEST_VENDOR_ID> --application=<TEST_APP_ID> --deliver

# b) Enable
wp lnkup-comms flag <flag> --on

# c) Observe (immediately, +1h, +24h)
wp lnkup-comms status
```

| Order | Flag | Test triggers | Core messages it takes over |
|---|---|---|---|
| 1 | `flow_applications` | `v_application_submitted`, `v_application_approved --variant=payment`, `o_application_received --user=<TEST_ORG_ID> --application=<APP_ON_TEST_EVENT>` | application_submitted / approved / rejected / waitlisted, payment_required, info requests |
| 2 | `flow_payments` | `v_payment_reminder`, `v_booking_confirmed` (paid test booking) | payment_confirmed, packet_unlocked, organizer_vendor_paid, invoice reminders and emails, installment/deposit reminders |
| 3 | `flow_documents` | `v_documents_needed` | documents_needed |
| 4 | `flow_operations` | `v_pass_updated`, `v_logistics_changed` | pass updated, logistics changed, ops broadcasts (arrival reminder moves only once `flow_countdown` is also on) |

**Checkpoint after each flag (24h):**

- [ ] `wp lnkup-comms status` shows no `error` in Template rendering, Duplicate sends or Email transport, and a failure rate under 5%.
- [ ] A preflight rerun shows that flow's `event_key` rows have **stopped growing** in the core baseline; core no longer sends them.
- [ ] `last_24h` shows that flow's triggers `sent` at roughly the volume the baseline predicts.
- [ ] Spot-check 3 real messages in Communications → **Delivery logs**: right recipient, right event and link, no placeholders.
- [ ] No related support tickets.

**Rollback for a flag:**

```bash
wp lnkup-comms flag <flag> --off --cancel-queued
```

That flow returns to lnkup-marketplace on its next event, and the plugin's unsent messages for the flow are cancelled, so nobody gets both versions. Send-time suppression is the backstop: any message still queued for a flag that is off is suppressed when it comes up for sending, recorded as `flag_off:<flag>` in Delivery logs. To cancel a flow's queue on its own, run `wp lnkup-comms cancel --flag=<flag>`. Internal test sends are the only exception and still go out.

**Emergency stop:** `wp lnkup-comms pause`. Everything stays queued, and `wp lnkup-comms resume` continues.

## Stop point for this rollout

Keep these **off** until the transactional flows have run cleanly for a week:

- Engagement: `flow_onboarding`, `flow_countdown`, `flow_digests`, `flow_post_event`, `flow_discovery` (and any other `flow_discovery_*` / `flow_recruitment*` flags), `flow_recruitment`, `flow_retention`.
- `organizer_broadcasts`, `campaigns_enabled`.
- `fin_payout_notifications`, which also needs the financial reconciliation sign-off.

SiteGround SMTP allows roughly 400–800 emails per hour, so promotional volume must not start before a dedicated email provider is in place.

## External checklist (outside this rollout)

- [ ] SPF, DKIM and DMARC alignment for thelnkup.com with the current mail transport.
- [ ] Optional: email provider bounce and complaint webhook (Communications → Settings).
- [ ] Mobile: FCM V1 credentials and `google-services.json`, a new EAS build, then the over-the-air update. After that, enable **Android channels**.

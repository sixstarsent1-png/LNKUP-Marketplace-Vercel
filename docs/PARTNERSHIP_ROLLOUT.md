# Partnership OS — go-live runbook

How to switch on what is built (Partnership OS foundation, affiliate and strategic programs, partner
notifications, mobile Partner home) one stage at a time. Each stage has entry criteria, the exact
actions, how to verify, what to watch, and a rollback that works without a redeploy where possible.

Two tools report readiness from the same code (`LNKup_Partnership_Readiness`):

- **wp-admin → LNKup → Partnerships & Referrals → Go-live readiness.** Stage checklist, every check, and
  the shadow-mode evidence. Read-only; cached 10 minutes ("Re-check now" refreshes).
- **`scripts/prod/partnerships-preflight.php`.** The same checks over SSH plus platform checks. Writes
  nothing (no options, transients, tables or cron). Safe at any stage, even before deploy.

```
ssh -p 18765 -i ~/.ssh/lnkup_siteground USER@ssh.thelnkup.com \
  "cd ~/www/thelnkup.com/public_html && wp eval-file -" < scripts/prod/partnerships-preflight.php
```

Local dry run: `Get-Content -Raw scripts/prod/partnerships-preflight.php | npx @wordpress/env run cli wp eval-file -`

Save the preflight output at every stage; it is the before/after record.

**Never, at any stage:** partners are not paid through Stripe. Commissions are approved and marked paid
in the Commission Ledger with a payout reference after a payout is sent outside LNKup. Stripe transfers
to partners stay off until the compliance and tax (KYC, 1099) review is done.

| Switch | Where | Default |
|---|---|---|
| `partnerships_arbiter_mode` (`off` / `shadow` / `enforce`) | Partnerships & Referrals → Settings | `shadow` |
| `partnerships_conflict_window_hours` | same | 72 |
| `connector_leads_enabled` | same | on |
| Commission rules per program | Partnerships & Referrals → Commission Rules | City Connector v1 only |
| `flow_partners` | LNKup → Communications → Settings | off |
| Mobile Partner home | EAS Update channel `production` | not published |

---

## Stage 0 — Pre-deploy (local)

**Entry:** the change set is merged to the branch you will deploy.

**Actions**

1. Run the local suites (wp-env):
   - `powershell -File scripts/e2e/run-partnerships-php.ps1`
   - `powershell -File scripts/e2e/run-comms-php.ps1`
   - `powershell -File scripts/e2e/run-financial-php.ps1`
   - `node scripts/partnerships-foundation-verify.mjs`
   - Mobile: `cd lnk-up-marketplace-mobile && npm run typecheck && npm test`
2. Rebuild the plugin zip: `powershell -File scripts/build-plugin-zip.ps1`. Keep the previous
   production zip for rollback.
3. Take a full database backup (SiteGround → Backups → create on demand) and note its time.
4. Run the production preflight. Expect `[FAIL] Partnership OS: ... Deploy the current build (stage 1)`
   on an older plugin; that is the baseline.

**Rollback:** nothing changed on production.

## Stage 1 — Deploy

**Entry:** stage 0 passed; a backup from today exists.

**Actions**

1. Upload `lnkup-marketplace` (zip), `lnkup-communications`, and the theme (`inc/dashboard.php` nav).
2. Load any front-end page once. The migration runs on the first request (schema `1.1.1`).
3. Do **not** change the arbiter mode (stays `shadow`) or `flow_partners` (stays off).

**Verify:** preflight shows `[OK] Partnership schema`, `[OK] Partner pages`, and the stage line
`DONE 1. Deployed`. In wp-admin, open Go-live readiness and confirm the same.

**Watch (first hour):** PHP error log; Stripe webhook deliveries still succeed; vendor checkout still
works; the Partnerships Overview loads.

**Rollback:** redeploy the previous zip. New tables and columns are additive and ignored by older
code. To disable all new reward gating without a redeploy, set the arbiter mode to `off`.

## Stage 2 — Shadow observation (14 days or more)

**Entry:** stage 1 is DONE.

The arbiter decides "who wins" for every reward but only **logs** what it would withhold
(`partner_arbiter_shadow_block`). Rewards are still paid exactly as before.

**Actions (weekly)**

1. Open Go-live readiness → "Shadow-mode evidence". For each reason:
   - `superseded`: a later referral lost to an earlier one (earliest wins). Expected.
   - `conflict`: two referrers inside the conflict window. Each opens a review.
   - `payment_winner_other`: another program's referral already won this payment.
2. Resolve every open attribution conflict in the Reviews tab (pick the winner or record why).
3. Confirm the attribution backfill finished (`[OK] Attribution backfill`).
4. Spot-check five blocks against the Referral Pipeline: the winner shown should be the one you would
   pay by hand. If not, stop and investigate before stage 3.

**Verify:** the "Shadow evidence and enforce" stage turns READY when
- the backfill is done,
- shadow mode has run at least 14 days (`lnkup_partnership_readiness_thresholds` filter),
- no attribution conflict is older than 7 days,
- commissions approved before their hold ended, reversed beyond their amount, and duplicate keys are all 0.

**Rollback:** none needed; shadow changes nothing.

## Stage 3 — Enforce attribution

**Entry:** the stage shows READY; the spot check matched.

**Actions:** Partnerships & Referrals → Settings → Attribution mode `enforce` → Save. The change is
audited (`partnership_settings_updated`) and flushes the readiness cache.

**Verify:** readiness shows the mode as `enforce` and the stage as DONE.

**Watch (48 hours):** "Rewards withheld in enforce mode" should track the shadow rate from stage 2.
A jump means a new attribution pattern; review the Reviews tab. City Connector commissions still
appear in the ledger for winning referrals.

**Rollback:** set the mode back to `shadow` (logs only) or `off`. No redeploy. Rewards withheld while
enforcing are not lost: resolving the review replays the winning program's reward step.

## Stage 4 — Affiliate and strategic programs

**Entry:** stage 3 DONE; the affiliate / strategic terms and disclosures are approved by whoever signs
off on legal and tax wording.

**Actions**

1. Commission Rules → create the affiliate (and strategic) rule draft with the approved rate, basis,
   term and hold. Publish it. Strategic partners with a separate agreement get an agreement rule
   (`agreement_ref`), which never replaces the program rule.
2. Confirm `/affiliate-program/` and `/partner-with-lnkup/` show the published terms (no rate is shown
   while a program has no published rule).
3. Approve one internal test partner from the Applications tab. Refer a test organizer with their link
   and complete a test booking in Stripe test mode, if available on staging.

**Verify:** readiness shows `[OK] Professional Affiliate: Rule vN published` and the active-account
count; the test partner's dashboard shows the terms and the referral.

**Watch:** the Applications queue; Reviews for affiliate-vs-connector conflicts on the same organizer.

**Rollback:** pause the program's partners from Applications (their codes stop resolving). A published
rule is immutable; publish a new version to change terms. Referrals keep the rule they were locked to.

## Stage 5 — Partner notifications

**Entry:** stage 3 DONE (or you have explicitly accepted shadow mode via the
`lnkup_partnership_readiness_allow_comms_in_shadow` filter); comms flags 1–14 in the
lnkup-communications README order are already on and stable; no failing readiness checks.

**Actions**

1. Communications → Settings: add the internal test partner to the test accounts.
2. Turn on `flow_partners`.
3. Trigger one of each for the test partner: referral registered, commission recorded (after the
   15-minute delay), commission approved, lead update.

**Verify**

- Delivery logs: email, inbox and push rows for the test partner only.
- The commission push shows **no amount**; the inbox and email show it.
- The partner's inbox row has role `partner` and does not appear in their vendor/organizer inbox.
- Tapping the push opens the Partner Earnings screen (after stage 6).

**Watch:** unsubscribe and complaint rates; suppressed sends (reversed or conflicted commissions are
suppressed at send time by design).

**Rollback:** turn `flow_partners` off. Scheduled partner messages are re-checked at send and stop.

## Stage 6 — Mobile Partner home

**Entry:** stage 1 DONE with `[OK] Mobile sign-in for partners` (the server admits active partner
accounts). Stage 5 is optional but needed for push.

The Partner home is JavaScript only. It ships over the air to installed builds whose app version
matches (`runtimeVersion.policy = appVersion`); older store builds need a new store build.

**Actions**

1. `cd lnk-up-marketplace-mobile && npm run typecheck && npm test`.
2. Publish to preview first: `npm run update:preview`. Install the preview build on a device.
3. Device smoke with the internal test City Connector:
   - sign in → lands on the Partner home (partner-only account) or shows "Switch to Partner" in More (multi-role);
   - Home shows the code and share links; Share and Copy work;
   - Referrals, Leads (submit one), Earnings load with real data and the empty states make sense;
   - notification tap (from stage 5) opens Earnings;
   - suspend the test partner in wp-admin → Partner screens show an access error card (no spinner), and
     signing out and back in is refused with the "not set up" message. Reactivate them afterwards.
4. Publish to production: `npm run update:production`.

**Verify:** the smoke list above on a production build after it downloads the update (relaunch twice).

**Rollback:** republish the previous update group (`npx eas-cli update:republish --group <previous-group-id>`),
or roll the channel back to the embedded bundle. The server side keeps working for web partners.

---

## After go-live

- Re-run the preflight weekly for the first month and keep the outputs.
- Payout rail (Stripe transfers to partners) stays a separate project, blocked on compliance review.

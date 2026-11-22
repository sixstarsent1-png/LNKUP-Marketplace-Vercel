# Stripe test-mode runbook — paid booking, Dashboard refunds, webhook replays

Covers Organizer Acquisition OS acceptance test 13 ("vendor completes a real test-mode payment") and the Stage 0 refund paths with **real Stripe events**. The automated suites (`run-financial-php.ps1`, `run-org-growth-php.ps1`) simulate Stripe; this runbook is the only end-to-end check against Stripe itself.

**Never run this against production.** Use wp-env (`http://localhost:8888`) or a staging site with **test** keys (`sk_test_…` / `pk_test_…`). The check script refuses live keys.

## 0. Prerequisites

- Stripe account in **test mode**, Stripe CLI installed and logged in: `stripe login`.
- wp-env running: `npx.cmd -y @wordpress/env@10 start`.
- wp-admin → LNKup → Settings → Payments:
  - Stripe publishable + secret **test** keys.
  - Leave "Stripe Connect" off for the simplest run (platform charge). Repeat with Connect on and a test Express account to exercise destination charges.
- A test organizer and a test vendor (any accounts on the local site; never real organizers).
- Optional, to see milestones move: Organizer Growth enabled, and the organizer linked to a lead (Organizer Growth → lead → *Link account*).

## 1. Forward webhooks

```powershell
stripe listen --forward-to http://localhost:8888/wp-json/lnkup/v1/stripe-webhook
```

Copy the printed `whsec_…` secret into **Settings → Stripe webhook secret** (and the Connect webhook secret when testing Connect). Leave `stripe listen` running.

## 2. Paid booking

1. As the organizer, create and publish an event with a paid slot (e.g. $50).
2. As the vendor, apply to the slot. As the organizer, approve the application.
3. As the vendor, pay the invoice with card `4242 4242 4242 4242`, any future expiry, any CVC.
4. In the `stripe listen` window confirm `payment_intent.succeeded` (and `charge.succeeded`) returned **200**.
5. Find the payment post ID (wp-admin → LNKup → Reconciliation → organizer, or the vendor's Payments tab), then:

```powershell
npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/org-growth-stripe-check.php <payment_id> paid
```

Expect `ALL PASSED`: one platform-fee row, net fee = full fee, paid-booking milestone active (if linked).

## 3. Webhook replay (idempotency)

```powershell
stripe events list --limit 5
stripe events resend <evt_id_of_payment_intent.succeeded>
```

Re-run the check with `paid`. It must still pass (still one fee row, no second settlement).

## 4. Partial refund from the Stripe Dashboard

Stripe Dashboard (test mode) → Payments → the charge → **Refund** → partial (e.g. $20 of $50). Wait for `charge.refunded` → 200, then:

```powershell
npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/org-growth-stripe-check.php <payment_id> partial
```

Expect: status `partially_refunded`, net fee reduced in proportion (60% kept for a 40% refund), refund announced once, unpaid connector rewards reversed, organizer-recruit reward partially reversed.

## 5. Full refund + replay

Refund the remainder in the Dashboard, then `stripe events resend <evt_id_of_charge.refunded>` once. Run the check with `full`.

Expect: status `refunded`, net fee 0, organizer-recruit reward `reversed`, paid-booking milestone `voided` when this was the organizer's only paid booking, nothing reversed twice.

## 6. Optional: lost dispute

Pay a new booking with `4000 0000 0000 0259` (disputed charge), then in the Dashboard *Submit evidence* → choose to lose (test mode `losing_evidence`). After `charge.dispute.closed` the fee is forfeited in full (`net 0`).

## Recording the result

Paste the four check outputs (paid, replay, partial, full) into `docs/ORGANIZER_ACQUISITION_AUDIT.md` §7 and mark acceptance test 13 as verified, noting the date and whether Connect was on.

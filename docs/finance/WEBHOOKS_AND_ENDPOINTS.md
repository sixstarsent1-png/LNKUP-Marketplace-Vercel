# Stripe webhooks, WAF and Connect endpoints

Payments reach the organizer ledger through three paths, in order of preference:

1. **Stripe webhook** → `POST https://thelnkup.com/wp-json/lnkup/v1/stripe-webhook` (immediate).
2. **Client confirm** after Checkout / Payment Element return (immediate, verified with Stripe).
3. **Hourly reconciliation** (`lnkup_financial_hourly` cron) → retries failed webhooks and syncs recent unpaid/unsettled payments.

If webhooks are blocked, payments still land within an hour, but the dashboard shows "Last synced …" and organizers can press **Sync now**.

## Stripe Dashboard setup

Create **two** endpoints with the same URL. Each has its own signing secret.

| Endpoint | Listens to | Secret setting (LNKup → Settings) |
| --- | --- | --- |
| Platform ("Your account") | events on the LNKup account | `stripe_webhook_secret` |
| Connect ("Connected accounts") | events on organizers' Express accounts | `stripe_connect_webhook_secret` |

### Platform endpoint events

- `checkout.session.completed`
- `checkout.session.async_payment_succeeded`
- `checkout.session.async_payment_failed`
- `payment_intent.succeeded`
- `payment_intent.processing`
- `payment_intent.payment_failed` (ignored once a payment is paid, or when it belongs to a superseded PaymentIntent)
- `charge.refunded`
- `charge.dispute.created`
- `charge.dispute.funds_withdrawn`, `charge.dispute.funds_reinstated`
- `charge.dispute.closed` (lost: organizer share reversed, status `chargeback_lost`; won: reinstated)
- `transfer.created`, `transfer.reversed`
- `customer.subscription.created`, `customer.subscription.updated`, `customer.subscription.deleted`

### Connect endpoint events

- `account.updated`
- `account.application.deauthorized`
- `payout.created`, `payout.updated`, `payout.paid`, `payout.failed`, `payout.canceled`

LNKup only acts on payouts it created (`metadata.payout_id`), and only when the event's connected account (`event.account`) is the payout owner's account and the local payout is a Connect payout. Stripe's automatic payouts are reported in the money contract's `paid_out` via the Payouts API (read-only), not through webhooks.

## WAF / CDN / security plugin rules

The webhook request has **no cookies, no nonce and no browser headers**. Make sure nothing in front of WordPress blocks it:

- Allow `POST /wp-json/lnkup/v1/stripe-webhook` without login, CAPTCHA, JS challenge, or "bot fight" checks.
- Do not cache that path; do not strip the `Stripe-Signature` header; do not rewrite the body (signature verification uses the raw body).
- If the WAF supports IP allowlists, allow Stripe's webhook IPs: <https://docs.stripe.com/ips#webhook-notifications>.
- Security plugins that disable the REST API for anonymous users must exempt `lnkup/v1/stripe-webhook`.
- Response must be JSON within 10 seconds. A 4xx/5xx makes Stripe retry for up to 3 days; LNKup stores every event (`wp_lnkup_webhook_events`, unique `event_id`) and the hourly job retries `failed` and stuck `pending` rows up to 5 times.

## Verifying in production

1. Stripe Dashboard → Developers → Webhooks → each endpoint shows recent **200** responses.
2. wp-admin → LNKup → **Reconciliation**: "Last webhook received" is recent and "Webhooks not processed" is 0.
3. "Hourly reconciliation last ran" is within the last hour and "Next hourly run" is scheduled. If WP-Cron is disabled (`DISABLE_WP_CRON`), add a system cron: `*/15 * * * * curl -s https://thelnkup.com/wp-cron.php?doing_wp_cron >/dev/null`.
4. Per organizer, **Verify with Stripe (read-only)** compares each payment with its PaymentIntent, charge, balance transaction and transfer.

## Admin endpoints (manage_options only)

| Route | Method | Effect |
| --- | --- | --- |
| `lnkup/v1/admin/financial/reconcile` | GET | Platform summary, or `?organizer_id=` full report; `stripe=0` skips Stripe. Read-only. |
| `lnkup/v1/admin/financial/ledger-audit/{organizer_id}` | GET | Every ledger row with payment linkage and duplicate flags. Read-only. |
| `lnkup/v1/admin/financial/repair` | POST | `mode=dry_run` (default) returns a plan + `confirmation_token`; `mode=apply` with the token applies it. |
| `lnkup/v1/admin/financial/repair-runs` | GET | Last 50 repair runs. |

Stripe is read with GET requests only, server-side; keys never reach browsers or the mobile app.

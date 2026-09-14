# Referral Invites — recruit organizers or vendors (12-month fee share)

Program id: `organizer_recruit` (kept for schema continuity)

## Rules

- **Referrers:** vendors and organizers (not City Connector).
- **Recruits:** outside **organizers or vendors** via role-specific links:
  - Organizer: `sign-up?org_ref=CODE&interest=organizer`
  - Vendor: `sign-up?org_ref=CODE&interest=vendor`
- **Signup page:** always `lnkup_registration_url()` → **`/sign-up/`** (not `/register/`).
- **Earnings:** While the referral window is active, **20% of LNKup platform take** for **12 months** from attribution.
  - Referred **organizer**: reward on paid booth txs for **their events**.
  - Referred **vendor**: reward on **that vendor’s** paid booth txs.
- **Payout / spend:** Wallet credit via `LNKup_Credits` (`source=organizer_recruit`). Credits **apply to booth fees at checkout** (`LNKup_Credits::apply_to_payment`) — **not** a vendor bank cashout and **not** a Stripe Connect transfer to the referrer. Stripe destination charges stay organizer + full LNKup `application_fee`; fee-share is an internal ledger liability after payment.
- **List:** Attributed recruits (organizer or vendor) appear on the referrer dashboard **from signup**, with `lifecycle=awaiting_payment` until the first booth payment — not payment-gated.
- **Idempotent:** one reward row per `(referral_id, payment_id)`.

Distinct from:

- Peer vendor Referral Credits (`vendor_referral` / Wallet → Referral Credits)
- CRM Invites (organizer → vendor to an event)
- City Connector program

## REST (`lnkup/v1`)

| Method | Route | Auth |
|--------|-------|------|
| GET | `/referrals/organizer-recruit` | vendor/organizer |
| POST | `/referrals/organizer-recruit/code` | vendor/organizer |
| GET | `/referrals/resolve?code=` | public (extended for this program) |

Dashboard fields include `share_url` (organizer alias), `share_url_organizer`, `share_url_vendor`.

## Surfaces

- **WP:** Vendor `?view=referrals`, Organizer `#referrals`, dual Copy buttons
- **Expo:** Home banner CTA (vendor + organizer) → `/(vendor|organizer)/referrals` (hidden from tab bar). Share organizer + vendor links. Copy: credit toward booth fees, not bank withdrawal.
- **Next:** `InviteOrganizersCard` with both copy actions

## Stripe (v1)

1. Booth charge: organizer Connect destination + full `application_fee` to LNKup.
2. After `lnkup_payment_completed`: recruit handler grants ledger credit only (no Stripe referrer call).
3. Referrer spends credit on a later booth checkout (amount charged via Stripe is reduced by credits applied).

## Settings (`lnkup_settings`)

- `organizer_recruit_enabled` (default on)
- `organizer_recruit_percent` (default 20)
- `organizer_recruit_months` (default 12)

## Smoke checklist

1. Share URLs contain `/sign-up/` (not `/register/`) and both `interest=organizer` and `interest=vendor`.
2. Organizer signup with org link → referral `target_role=organizer` appears on referrer dashboard **immediately** (`lifecycle=awaiting_payment`, `$0`) before any booth payment.
3. Vendor signup with vendor link → same for `target_role=vendor`.
4. Booth payment → credit issued; lifecycle becomes `earning`.
5. Duplicate payment → one credit; after expiry → no new reward.

Verify: `node scripts/referral-invites-verify.mjs`

**Deploy note:** Attribution + `lifecycle` require WordPress plugin **12.18.26+** on thelnkup.com. Mobile OTA alone is not enough for new signups to appear.

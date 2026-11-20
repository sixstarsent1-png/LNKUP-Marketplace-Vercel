/**
 * Static wiring check for the Connect money path:
 * onboard → pay → settle → cash out.
 *
 * Live Stripe charges remain operator-owned (needs WP + Stripe credentials).
 * Optional live route probe: node scripts/live-wp-money-path-smoke.mjs
 */
import fs from "node:fs"
import path from "node:path"
import { fileURLToPath } from "node:url"

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const root = path.resolve(__dirname, "..")

const requiredChecks = [
  {
    file: "wp-content/plugins/lnkup-marketplace/includes/class-financial-rest.php",
    snippets: [
      "/financial/connect/onboarding-link",
      "/financial/connect/status",
      "/financial/checkout/(?P<payment_id>\\d+)/redirect-url",
      "/financial/checkout/(?P<payment_id>\\d+)/prepare",
      "/financial/payments/(?P<payment_id>\\d+)/reconcile",
      "/financial/payments/(?P<payment_id>\\d+)/audit",
      "/financial/organizer-wallet/cashout",
      "connect_onboarding",
      "organizer_cashout",
    ],
  },
  {
    file: "wp-content/plugins/lnkup-marketplace/includes/financial/class-financial-service.php",
    snippets: [
      "create_checkout",
      "finalize_payment",
      "settle_treasury",
      "request_organizer_cashout",
    ],
  },
  {
    file: "wp-content/plugins/lnkup-marketplace/includes/financial/class-stripe-connect.php",
    snippets: [
      "create_onboarding_link",
      "onboarding_status",
      "create_connect_payout",
      "persist_payment_settlement_meta",
      "settlement_mode_for_organizer",
    ],
  },
  {
    file: "wp-content/plugins/lnkup-marketplace/includes/financial/class-organizer-payouts.php",
    snippets: ["request_cashout", "process_payout", "create_connect_payout"],
  },
  {
    file: "wp-content/plugins/lnkup-marketplace/includes/financial/class-fee-engine.php",
    snippets: ["vendor_checkout_fee", "platform_fee", "Stripe application_fee"],
  },
  {
    file: "wp-content/plugins/lnkup-marketplace/includes/financial/class-stripe-webhooks.php",
    snippets: ["handle", "process_event", "checkout.session.completed"],
  },
  {
    file: "wp-content/plugins/lnkup-marketplace/FINANCIAL.md",
    snippets: [
      "Onboard test organizer Express",
      "Vendor test payment",
      "application_fee_amount",
      "transfer_data.destination",
      "money-path-verify",
    ],
  },
]

const failures = []

for (const check of requiredChecks) {
  const absolutePath = path.join(root, check.file)
  if (!fs.existsSync(absolutePath)) {
    failures.push(`${check.file}: missing file`)
    continue
  }
  const content = fs.readFileSync(absolutePath, "utf8")
  for (const snippet of check.snippets) {
    if (!content.includes(snippet)) {
      failures.push(`${check.file}: missing snippet "${snippet}"`)
    }
  }
}

if (failures.length > 0) {
  console.error("Money-path verify failed:")
  for (const failure of failures) {
    console.error(` - ${failure}`)
  }
  process.exit(1)
}

console.log(`Money-path verify passed (${requiredChecks.length} files).`)
console.log("Operator next: onboard → pay → settle → cash out on staging (FINANCIAL.md).")
console.log("Optional live probe: node scripts/live-wp-money-path-smoke.mjs")

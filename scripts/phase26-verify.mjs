import fs from "node:fs"
import path from "node:path"
import { fileURLToPath } from "node:url"

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const root = path.resolve(__dirname, "..")

const requiredChecks = [
  {
    file: "wp-content/plugins/lnkup-marketplace/lnkup-marketplace.php",
    snippets: ["ach_enabled", "stripe_billing_enabled", "apple_pay_enabled", "google_pay_enabled"],
  },
  {
    file: "wp-content/plugins/lnkup-marketplace/admin/class-admin.php",
    snippets: [
      "ach_enabled",
      "stripe_billing_enabled",
      "apple_pay_enabled",
      "google_pay_enabled",
      "Payment methods (Financial OS)",
      "render_stripe_test_mode_notice",
      "sk_live_",
    ],
  },
  {
    file: "wp-content/plugins/lnkup-marketplace/includes/financial/class-stripe-provider.php",
    snippets: [
      "excluded_payment_method_types",
      "us_bank_account",
      "automatic_payment_methods",
      "create_checkout_session",
      "session_params",
    ],
  },
  {
    file: "wp-content/plugins/lnkup-marketplace/includes/financial/class-stripe-webhooks.php",
    snippets: [
      "handle_payment_intent_processing",
      "handle_subscription_event",
      "payment_intent.processing",
      "payment_status",
      "mark_payment_processing",
    ],
  },
  {
    file: "wp-content/plugins/lnkup-marketplace/includes/financial/class-financial-service.php",
    snippets: [
      "mark_payment_processing",
      "payment_processing",
      "Signing secret configured",
    ],
  },
  {
    file: "wp-content/plugins/lnkup-marketplace/includes/class-payments.php",
    snippets: ["mark_processing", "processing"],
  },
  {
    file: "wp-content/plugins/lnkup-marketplace/includes/financial/class-subscriptions.php",
    snippets: ["subscribe_via_stripe", "sync_from_stripe_event", "stripe_price_id"],
  },
  {
    file: "wp-content/plugins/lnkup-marketplace/includes/financial/class-stripe-client.php",
    snippets: ["create_subscription", "cancel_subscription", "case 'subscriptions'"],
  },
  {
    file: "wp-content/plugins/lnkup-marketplace/includes/financial/class-fee-engine.php",
    snippets: ["ACH_PROCESSING_RATE", "resolve_processing_fee"],
  },
  {
    file: "wp-content/plugins/lnkup-marketplace/admin/class-financial-connect-admin.php",
    snippets: ["Webhook idempotency evidence", "recent_webhook_events", "payment_intent.processing"],
  },
  {
    file: "wp-content/plugins/lnkup-marketplace/FINANCIAL.md",
    snippets: [
      "Checkout Session ACH contract",
      "payment_status=paid",
      "Webhook signatures",
    ],
  },
  {
    file: "docs/PHASE_26_FINANCIAL_OS_EXPANSION.md",
    snippets: [
      "Built",
      "ACH",
      "Stripe Billing",
      "handoff",
      "checkout.session.completed",
      "payment_status=paid",
      "Checkout Sessions",
    ],
  },
  {
    file: "docs/PHASE_26_RELEASE_CHECKLIST.md",
    snippets: [
      "phase26:verify",
      "ACH",
      "payment_status!=paid",
      "signing secret rejects unsigned",
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
  console.error("Phase 26 verify failed:")
  for (const failure of failures) {
    console.error(` - ${failure}`)
  }
  process.exit(1)
}

console.log(`Phase 26 verify passed (${requiredChecks.length} files).`)

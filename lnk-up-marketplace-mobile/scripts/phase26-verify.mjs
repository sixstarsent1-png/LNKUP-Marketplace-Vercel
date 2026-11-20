import fs from "node:fs"
import path from "node:path"

const root = process.cwd()

const requiredChecks = [
  {
    file: "lib/payments/stripe-checkout.native.ts",
    snippets: [
      "fetchPaymentHandoff",
      "openBrowserHandoff",
      "Financial OS handoff",
      "WebBrowser.openAuthSessionAsync",
      "lnkup://stripe-redirect",
      '"native"',
    ],
  },
  {
    file: "lib/payments/stripe-checkout.ts",
    snippets: ["fetchPaymentHandoff", "Financial OS handoff", "WebBrowser.openBrowserAsync"],
  },
  {
    file: "lib/api/vendor-payment.ts",
    snippets: ["/financial/checkout/", "redirect-url", "fetchPaymentHandoff"],
  },
  {
    file: "package.json",
    snippets: ["phase26:verify"],
  },
]

const forbidden = [
  {
    file: "lib/payments/stripe-checkout.native.ts",
    snippets: [
      "presentPaymentSheet",
      "initPaymentSheet",
      "@stripe/stripe-react-native",
      "WebBrowser.openBrowserAsync",
    ],
  },
  {
    file: "lib/payments/stripe-checkout.ts",
    snippets: ["openWebPaymentSheet", "prepareCheckout"],
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

for (const check of forbidden) {
  const absolutePath = path.join(root, check.file)
  if (!fs.existsSync(absolutePath)) continue
  const content = fs.readFileSync(absolutePath, "utf8")
  for (const snippet of check.snippets) {
    if (content.includes(snippet)) {
      failures.push(`${check.file}: forbidden snippet still present "${snippet}"`)
    }
  }
}

if (failures.length > 0) {
  console.error("Phase 26 mobile verify failed:")
  for (const failure of failures) {
    console.error(` - ${failure}`)
  }
  process.exit(1)
}

console.log(`Phase 26 mobile verify passed (${requiredChecks.length} files).`)

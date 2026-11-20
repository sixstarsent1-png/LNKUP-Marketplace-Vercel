import fs from "node:fs"
import path from "node:path"

const root = process.cwd()

const requiredChecks = [
  {
    file: "lib/api/fixture-honesty.ts",
    snippets: ["reportFixtureFallback", "withFixtureFallback", "shouldShowFixtureHonesty"],
  },
  {
    file: "components/demo-data-banner.tsx",
    snippets: ["DemoDataBanner", "clearDemoDataBanner", "subscribeFixtureHonesty"],
  },
  {
    file: "app/_layout.tsx",
    snippets: ["DemoDataBanner"],
  },
  {
    file: "lib/api/marketplace.ts",
    snippets: ["withFixtureFallback", "marketplace.search"],
  },
  {
    file: "lib/api/vendor-wallet.ts",
    snippets: ["withFixtureFallback", "vendor.wallet"],
  },
  {
    file: "lib/api/organizer-events.ts",
    snippets: ["withFixtureFallback", "organizer.events"],
  },
  {
    file: "lib/push/notifications.ts",
    snippets: ["notification-routing", "syncPushRegistration"],
  },
  {
    file: "lib/push/notifications.native.ts",
    snippets: ["expo-notifications", "setNotificationHandler"],
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
  console.error("fixture-honesty-verify failed:")
  for (const failure of failures) {
    console.error(`- ${failure}`)
  }
  process.exit(1)
}

console.log("fixture-honesty-verify OK")

/**
 * Static checks for profile PATCH BFF, growth live wiring, inbox REST, tax PDFs.
 */
import fs from "node:fs"
import path from "node:path"
import { fileURLToPath } from "node:url"

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const root = path.resolve(__dirname, "..")

const requiredChecks = [
  {
    file: "wp-content/plugins/lnkup-marketplace/includes/class-inbox-rest.php",
    snippets: [
      "/notifications/inbox",
      "/notifications/inbox/read-all",
      "LNKup_Inbox_Service::mark_read",
    ],
  },
  {
    file: "wp-content/plugins/lnkup-marketplace/includes/class-notifications.php",
    snippets: ["LNKup_Inbox_Service::record_from_push"],
  },
  {
    file: "wp-content/plugins/lnkup-marketplace/includes/financial/class-tax-center.php",
    snippets: ["generate_w9_pdf", "generate_1099_pdf", "Dompdf\\Dompdf"],
  },
  {
    file: "wp-content/plugins/lnkup-marketplace/includes/class-financial-rest.php",
    snippets: ["/financial/tax-center/w9/pdf", "/financial/tax-center/1099/pdf"],
  },
  {
    file: "lnk-up-marketplace-ui/app/api/vendor/profile/route.ts",
    snippets: ["export async function PATCH", '/vendor/profile"', 'method: "POST"'],
  },
  {
    file: "lnk-up-marketplace-ui/app/api/vendor/growth/route.ts",
    snippets: ["/growth/dashboard", "mapGrowthDashboard"],
  },
  {
    file: "lnk-up-marketplace-ui/app/api/notifications/[id]/read/route.ts",
    snippets: ["/notifications/inbox/", "encodeURIComponent(id)"],
  },
  {
    file: "lnk-up-marketplace-mobile/lib/api/notifications.ts",
    snippets: ["mergeServerInbox", "markInboxRead", "/notifications/inbox"],
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
  console.error("Gap-close verify failed:")
  for (const failure of failures) {
    console.error(` - ${failure}`)
  }
  process.exit(1)
}

console.log(`Gap-close verify passed (${requiredChecks.length} files).`)

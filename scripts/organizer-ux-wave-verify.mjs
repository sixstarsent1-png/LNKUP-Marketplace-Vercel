/**
 * Static verification for Organizer UX wave (edit event, fee rows, login-link, weather merge).
 */
import fs from "node:fs"
import path from "node:path"
import { fileURLToPath } from "node:url"

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..")
const fails = []

function read(rel) {
  return fs.readFileSync(path.join(root, rel), "utf8")
}

function assertIncludes(rel, snippets, label) {
  const body = read(rel)
  for (const s of snippets) {
    if (!body.includes(s)) {
      fails.push(`${label}: missing "${s}" in ${rel}`)
    }
  }
}

assertIncludes(
  "wp-content/plugins/lnkup-marketplace/includes/class-organizer-onboarding.php",
  [
    "function update_event_from_request",
    "function normalize_opportunity_rows",
    "function create_opportunities_for_event",
    "fee_category",
  ],
  "onboarding",
)

assertIncludes(
  "wp-content/plugins/lnkup-marketplace/includes/class-organizer-launch-rest.php",
  [
    "/organizer/events/(?P<id>\\d+)",
    "/organizer/events/(?P<id>\\d+)/opportunities",
    "create_opportunities_for_event",
    "opportunities",
  ],
  "launch-rest",
)

assertIncludes(
  "wp-content/plugins/lnkup-marketplace/includes/class-financial-rest.php",
  ["/financial/connect/login-link", "connect_login_link"],
  "financial-rest",
)

assertIncludes(
  "wp-content/plugins/lnkup-marketplace/includes/financial/class-stripe-connect.php",
  ["function create_login_link", "create_login_link"],
  "stripe-connect",
)

assertIncludes(
  "wp-content/plugins/lnkup-marketplace/includes/class-packets.php",
  ["rain_plan", "heat_plan"],
  "packets-weather",
)

assertIncludes(
  "wp-content/plugins/lnkup-marketplace/public/class-shortcodes.php",
  [
    "Booth pricing by vendor type",
    "fee_category[]",
    "lnkup-edit-event-toggle",
    "lnkup_update_event",
  ],
  "wp-ui",
)

assertIncludes(
  "lnk-up-marketplace-mobile/lib/api/organizer-launch.ts",
  ["feeRows", "opportunities"],
  "mobile-launch",
)

assertIncludes(
  "lnk-up-marketplace-mobile/lib/api/organizer-events.ts",
  ["updateOrganizerEvent", "addOrganizerOpportunities"],
  "mobile-events",
)

assertIncludes(
  "lnk-up-marketplace-mobile/lib/api/organizer-financial.ts",
  ["fetchConnectLoginLink", "requestOrganizerCashout", "login-link", "cashout"],
  "mobile-money-api",
)

assertIncludes(
  "lnk-up-marketplace-mobile/app/(organizer)/money.tsx",
  ["Cash out", "Open Stripe Express", "fetchConnectLoginLink"],
  "mobile-money-ui",
)

assertIncludes(
  "lnk-up-marketplace-mobile/lib/api/vendor-pass.ts",
  ["forecast_status", "recommendations", "contingency"],
  "mobile-weather",
)

assertIncludes(
  "lnk-up-marketplace-ui/lib/api/organizer-launch.ts",
  ["feeRows", "opportunities"],
  "next-launch",
)

assertIncludes(
  "lnk-up-marketplace-mobile/app/(organizer)/index.tsx",
  ["More stats", "eventEditBtn", "checklistVisible"],
  "mobile-home",
)

assertIncludes(
  "lnk-up-marketplace-mobile/app/(organizer)/vendors/index.tsx",
  ["OrganizerDiscoverFilters", "verified", "category"],
  "mobile-discover",
)

assertIncludes(
  "lnk-up-marketplace-ui/app/api/organizer/events/[id]/route.ts",
  ["PATCH", "organizer/events"],
  "next-event-bff",
)

assertIncludes(
  "lnk-up-marketplace-ui/app/api/organizer/financial/login-link/route.ts",
  ["connect/login-link"],
  "next-login-bff",
)

assertIncludes(
  "lnk-up-marketplace-ui/lib/api/vendor-packet.ts",
  ["contingency", "weatherRisks", "forecast_status"],
  "next-weather",
)

assertIncludes(
  "lnk-up-marketplace-ui/components/organizer/organizer-financial-snapshot.tsx",
  ["More on web"],
  "next-money-handoff",
)

if (fails.length) {
  console.error("organizer-ux-wave-verify FAILED:\n" + fails.map((f) => ` - ${f}`).join("\n"))
  process.exit(1)
}

console.log(
  "organizer-ux-wave-verify OK (" +
    [
      "edit event",
      "fee rows",
      "login-link",
      "cashout clients",
      "weather merge",
      "discover/home",
      "next BFF",
    ].join(", ") +
    ")",
)

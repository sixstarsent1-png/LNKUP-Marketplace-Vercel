/**
 * Static checks for organizer recruit Referral Invites (12-month fee share).
 */
import fs from "node:fs"
import path from "node:path"
import { fileURLToPath } from "node:url"

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..")
const fails = []

function assertIncludes(rel, snippets, label) {
  const full = path.join(root, rel)
  if (!fs.existsSync(full)) {
    fails.push(`${label}: missing ${rel}`)
    return
  }
  const body = fs.readFileSync(full, "utf8")
  for (const s of snippets) {
    if (!body.includes(s)) fails.push(`${label}: missing "${s}" in ${rel}`)
  }
}

assertIncludes(
  "wp-content/plugins/lnkup-marketplace/includes/financial/class-organizer-recruit-referrals.php",
  [
    "organizer_recruit",
    "lnkup_registration_url",
    "share_url_vendor",
    "interest",
    "attribute_user",
    "sign-up",
    "awaiting_payment",
    "on_wp_login",
  ],
  "wp-service",
)

assertIncludes(
  "wp-content/plugins/lnkup-marketplace/includes/class-organizer-recruit-rest.php",
  ["/referrals/organizer-recruit", "LNKup_Organizer_Recruit_REST"],
  "wp-rest",
)

assertIncludes(
  "wp-content/plugins/lnkup-marketplace/includes/financial/bootstrap.php",
  ["LNKup_Organizer_Recruit_Referrals::init"],
  "wp-bootstrap",
)

assertIncludes(
  "lnk-up-marketplace-mobile/lib/api/organizer-recruit-referrals.ts",
  ["/referrals/organizer-recruit"],
  "expo-api",
)

assertIncludes(
  "lnk-up-marketplace-mobile/app/(vendor)/referrals.tsx",
  ["ReferralInvitesScreen"],
  "expo-vendor",
)

assertIncludes(
  "lnk-up-marketplace-mobile/app/(organizer)/referrals.tsx",
  ["ReferralInvitesScreen"],
  "expo-organizer",
)

assertIncludes(
  "lnk-up-marketplace-ui/app/api/referrals/organizer-recruit/route.ts",
  ["/referrals/organizer-recruit"],
  "next-bff",
)

assertIncludes(
  "lnk-up-marketplace-ui/components/referrals/invite-organizers-card.tsx",
  ["InviteOrganizersCard", "Referral Invites"],
  "next-card",
)

assertIncludes(
  "docs/PHASE_REFERRAL_INVITES.md",
  ["12 months", "organizer_recruit", "20%", "sign-up", "interest=vendor", "awaiting_payment"],
  "docs",
)

if (fails.length) {
  console.error("referral-invites-verify FAILED:\n" + fails.map((f) => ` - ${f}`).join("\n"))
  process.exit(1)
}

console.log("referral-invites-verify OK")

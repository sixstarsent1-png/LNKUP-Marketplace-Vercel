/**
 * Static checks for App Store readiness artifacts in-repo.
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
  "wp-content/themes/lnkup-theme/inc/legal-pages.php",
  ["privacy", "terms", "contact", "LNKup_Theme_Legal_Pages", "Delete account"],
  "legal-pages",
)

assertIncludes(
  "wp-content/plugins/lnkup-marketplace/includes/class-account-deletion.php",
  ["request_deletion", "scrub_personal_data", "GRACE_DAYS", "DELETE"],
  "account-deletion",
)

assertIncludes(
  "wp-content/plugins/lnkup-marketplace/includes/class-account-rest.php",
  ["/account/delete", "LNKup_Account_REST"],
  "account-rest",
)

assertIncludes(
  "lnk-up-marketplace-mobile/components/delete-account-card.tsx",
  ["DeleteAccountCard", "DELETE", "requestAccountDeletion"],
  "mobile-delete-ui",
)

assertIncludes(
  "lnk-up-marketplace-mobile/app/(vendor)/more.tsx",
  ["DeleteAccountCard"],
  "vendor-more",
)

assertIncludes(
  "lnk-up-marketplace-mobile/app/(organizer)/more.tsx",
  ["DeleteAccountCard"],
  "organizer-more",
)

assertIncludes(
  "docs/ASC_APP_REVIEW_PACKAGE.md",
  ["5.1.1", "thelnkup.com/privacy", "Delete account", "auth/register", "Create free account is in-app"],
  "asc-package",
)

assertIncludes(
  "wp-content/plugins/lnkup-marketplace/includes/class-auth-rest.php",
  ["/auth/register", "LNKup_Registration::register_user"],
  "auth-register",
)

assertIncludes(
  "lnk-up-marketplace-mobile/app/signup.tsx",
  ["Create account", "register(", "Privacy Policy"],
  "mobile-signup",
)

assertIncludes(
  "lnk-up-marketplace-mobile/app/login.tsx",
  ['pathname: "/signup"', "LegalLinks"],
  "login-in-app-signup",
)

assertIncludes(
  "wp-content/plugins/lnkup-marketplace/includes/class-organizer-launch-rest.php",
  ["/organizer/events/"],
  "wp-parity-routes-soft",
)

// login-link is financial-rest
assertIncludes(
  "wp-content/plugins/lnkup-marketplace/includes/class-financial-rest.php",
  ["/financial/connect/login-link", "/financial/organizer-wallet/cashout"],
  "financial-parity",
)

if (fails.length) {
  console.error("app-store-readiness-verify FAILED:\n" + fails.map((f) => ` - ${f}`).join("\n"))
  process.exit(1)
}

console.log("app-store-readiness-verify OK (legal pages, account delete, ASC package, WP routes)")

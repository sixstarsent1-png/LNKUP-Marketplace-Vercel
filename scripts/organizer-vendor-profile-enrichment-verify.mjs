import fs from "node:fs"
import path from "node:path"
import { fileURLToPath } from "node:url"

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const root = path.resolve(__dirname, "..")

const requiredChecks = [
  {
    file: "wp-content/plugins/lnkup-marketplace/public/class-shortcodes.php",
    snippets: [
      "lnkup-crm-gallery",
      "lnkup-crm-documents",
      "lnkup-crm-doc-chips",
      "With you",
      "water_needs",
      "profile_strength_tier",
      "About",
    ],
  },
  {
    file: "wp-content/plugins/lnkup-marketplace/public/css/organizer-crm.css",
    snippets: [".lnkup-crm-gallery", ".lnkup-crm-doc-chips", ".lnkup-crm-history-list"],
  },
  {
    file: "lnk-up-marketplace-ui/lib/api/organizer-vendor-profile.ts",
    snippets: [
      "gallery",
      "pastEvents",
      "upcomingEvents",
      "wordpressCrmUrl",
      "profileStrengthScore",
      "mapReadinessBlockers",
      "?vendor=",
    ],
  },
  {
    file: "lnk-up-marketplace-ui/components/organizer/organizer-vendor-profile-drawer.tsx",
    snippets: [
      "organizer-drawer-gallery",
      "organizer-drawer-setup",
      "organizer-drawer-documents",
      "organizer-drawer-history",
      "Open in LNKup CRM",
      "organizer-drawer-crm-link",
    ],
  },
  {
    file: "lnk-up-marketplace-ui/components/marketplace/vendor-card.tsx",
    snippets: ["onViewProfile", "vendor-card-view-profile"],
  },
  {
    file: "lnk-up-marketplace-ui/app/organizer/vendors/page.tsx",
    snippets: ["handleDiscoverViewProfile", "onViewProfile={handleDiscoverViewProfile}", "discoverProfileId"],
  },
  {
    file: "lnk-up-marketplace-ui/app/api/organizer/vendors/[id]/route.ts",
    snippets: ["mapOrganizerVendorProfile", "readiness"],
  },
  {
    file: "lnk-up-marketplace-mobile/lib/api/organizer-vendor-directory.ts",
    snippets: [
      "OrganizerVendorProfilePeek",
      "gallery",
      "setupNeeds",
      "pastEvents",
      "wordpressUrl",
      "?vendor=",
      "readinessBlockers",
      "mapVendorProfilePeek",
    ],
  },
  {
    file: "lnk-up-marketplace-mobile/app/(organizer)/vendors/[profileId].tsx",
    snippets: [
      "applicationId",
      "organizer-vendor-setup",
      "organizer-vendor-documents",
      "organizer-vendor-history",
      "organizer-vendor-crm-link",
      "Open in LNKup CRM",
      "readinessBlockers",
    ],
  },
  {
    file: "lnk-up-marketplace-mobile/app/(organizer)/vendors/index.tsx",
    snippets: ["vendorProfileHref(intelProfileId, applicationId)"],
  },
  {
    file: "lnk-up-marketplace-mobile/components/organizer-vendors/vendor-queue.ts",
    snippets: ["?applicationId=${applicationId}"],
  },
  {
    file: "docs/ORGANIZER_VENDOR_PROFILE_ENRICHMENT.md",
    snippets: ["Built", "read-only", "GET /crm/vendors/{id}", "Open in LNKup CRM"],
  },
]

const forbidden = [
  {
    file: "lnk-up-marketplace-mobile/lib/api/organizer-vendor-directory.ts",
    snippets: ["?view=crm&vendor="],
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

if (failures.length) {
  console.error("Organizer vendor profile enrichment verify FAILED:")
  for (const failure of failures) console.error(` - ${failure}`)
  process.exit(1)
}

console.log(`Organizer vendor profile enrichment verify OK (${requiredChecks.length} files checked).`)

import fs from "node:fs"
import path from "node:path"
import { fileURLToPath } from "node:url"

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const root = path.resolve(__dirname, "..")

const requiredChecks = [
  {
    file: "wp-content/plugins/lnkup-marketplace/includes/class-marketplace-analytics.php",
    snippets: ["option_array", "array_unshift", "Throwable"],
  },
  {
    file: "wp-content/plugins/lnkup-marketplace/includes/class-vendor-personalization.php",
    snippets: ["get_search_history_entries", "is_array( $h )"],
  },
  {
    file: "wp-content/plugins/lnkup-marketplace/includes/class-marketplace-rest.php",
    snippets: ["lnkup_search_failed", "Throwable"],
  },
  {
    file: "wp-content/plugins/lnkup-marketplace/includes/class-discovery.php",
    snippets: ["build_relevance_order", "'params'"],
  },
  {
    file: "wp-content/plugins/lnkup-marketplace/includes/class-meta.php",
    snippets: ["'_lnkup_gallery_ids'"],
  },
  {
    file: "wp-content/plugins/lnkup-marketplace/includes/helpers.php",
    snippets: [
      "lnkup_parse_gallery_ids",
      "lnkup_save_post_gallery",
      "lnkup_get_opportunity_gallery",
      "'gallery'",
    ],
  },
  {
    file: "wp-content/plugins/lnkup-marketplace/includes/class-organizer-onboarding.php",
    snippets: ["lnkup_save_post_gallery", "gallery_ids"],
  },
  {
    file: "wp-content/plugins/lnkup-marketplace/public/class-shortcodes.php",
    snippets: ["'input_id'     => 'lnkup_event_gallery_ids'", "_lnkup_gallery_ids"],
  },
  {
    file: "wp-content/plugins/lnkup-marketplace/public/js/marketplace.js",
    snippets: ["data-image-upload') === 'gallery'", "handleImageFiles"],
  },
  {
    file: "wp-content/plugins/lnkup-marketplace/includes/class-apply-with-profile-view.php",
    snippets: ["lnkup-event-gallery-detail", "lnkup_get_opportunity_gallery"],
  },
  {
    file: "wp-content/plugins/lnkup-marketplace/includes/class-invoices.php",
    snippets: ["'pdf_url'", "financial/invoices/"],
  },
  {
    file: "wp-content/plugins/lnkup-marketplace/public/js/financial.js",
    snippets: ["lnkup-invoice-view", "pdf_url"],
  },
  {
    file: "lnk-up-marketplace-mobile/app/opportunity/[opportunityId].tsx",
    snippets: ["opportunity-gallery", "detail?.gallery"],
  },
  {
    file: "lnk-up-marketplace-ui/components/marketplace/apply-modal.tsx",
    snippets: ["lnkup-apply-event-gallery"],
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

if (failures.length) {
  console.error("Find / media / wallet verify FAILED:")
  for (const failure of failures) console.error(` - ${failure}`)
  process.exit(1)
}

console.log(`Find / media / wallet verify OK (${requiredChecks.length} files).`)

import fs from "node:fs"
import path from "node:path"
import { fileURLToPath } from "node:url"

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const root = path.resolve(__dirname, "..")

const requiredChecks = [
  {
    file: "wp-content/plugins/lnkup-marketplace/includes/external-sources/class-external-source-posh.php",
    snippets: ["LNKup_External_Source_Posh", "posh.vip", "parse_url", "supports_url_import"],
  },
  {
    file: "wp-content/plugins/lnkup-marketplace/includes/external-sources/class-external-source-rss.php",
    snippets: ["LNKup_External_Source_Rss", "looks_like_feed_url", "fetch_feed", "parse_url"],
  },
  {
    file: "wp-content/plugins/lnkup-marketplace/includes/external-sources/class-external-sources.php",
    snippets: ["'posh'", "'rss'", "detect_from_url", "LNKup_External_Source_Posh"],
  },
  {
    file: "wp-content/plugins/lnkup-marketplace/lnkup-marketplace.php",
    snippets: ["class-external-source-posh.php", "class-external-source-rss.php"],
  },
  {
    file: "wp-content/plugins/lnkup-marketplace/includes/class-external-events-rest.php",
    snippets: [
      "/external-events/organizer/import-url",
      "organizer_import_url",
      "rate_limited",
    ],
  },
  {
    file: "wp-content/plugins/lnkup-marketplace/public/js/marketplace.js",
    snippets: [
      "organizer/import-url",
      "applyImportPreviewToForm",
      "formatImportDateParts",
    ],
  },
  {
    file: "wp-content/plugins/lnkup-marketplace/public/class-shortcodes.php",
    snippets: ["Eventbrite", "Posh", "RSS", "lnkup-import-preview-btn"],
  },
  {
    file: "wp-content/plugins/lnkup-marketplace/admin/class-external-events-admin.php",
    snippets: ["Posh", "RSS", "Paste Eventbrite"],
  },
  {
    file: "docs/PHASE_EXTERNAL_EVENT_IMPORT.md",
    snippets: ["Built", "organizer/import-url", "No public REST"],
  },
]

const forbidden = [
  {
    file: "wp-content/plugins/lnkup-marketplace/public/js/marketplace.js",
    snippets: ["setTimeout(function () {\n      loading.hidden = true;\n      var platform = detectImportPlatform"],
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
      failures.push(`${check.file}: forbidden stub still present`)
    }
  }
}

// Extra: ensure setTimeout fake preview pattern is gone
const jsPath = path.join(root, "wp-content/plugins/lnkup-marketplace/public/js/marketplace.js")
const js = fs.readFileSync(jsPath, "utf8")
if (js.includes("Ticketing link recognized.")) {
  failures.push("marketplace.js: stub toast still present")
}
if (!js.includes("/external-events/organizer/import-url")) {
  failures.push("marketplace.js: missing organizer import-url fetch")
}

if (failures.length > 0) {
  console.error("External event import verify failed:")
  for (const failure of failures) {
    console.error(` - ${failure}`)
  }
  process.exit(1)
}

console.log(`External event import verify passed (${requiredChecks.length} files).`)

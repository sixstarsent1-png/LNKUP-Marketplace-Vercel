import fs from "node:fs"
import path from "node:path"
import { fileURLToPath } from "node:url"

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const root = path.resolve(__dirname, "..")
const plugin = "wp-content/plugins/lnkup-marketplace"

const requiredChecks = [
  {
    file: `${plugin}/lnkup-marketplace.php`,
    snippets: ["includes/passport/class-passport.php", "LNKup_Passport::init()", "LNKup_Passport_Tables"],
  },
  {
    file: `${plugin}/includes/class-vendor-reputation.php`,
    snippets: ["resolve_public_profile", "queue_refresh", "Reads never write"],
  },
  {
    file: `${plugin}/includes/class-reputation-rest.php`,
    snippets: ["profile_by_id_permission", "redact_internal_score", "/reputation/reviews/(?P<id>\\d+)/response", "/reputation/disputes/(?P<id>\\d+)/appeal"],
  },
  {
    file: `${plugin}/includes/class-vendor-reviews.php`,
    snippets: ["claim_index", "DISPUTE_OPEN", "public_category_scores", "is_author || $is_staff", "'reason_required'"],
  },
  {
    file: `${plugin}/includes/passport/class-passport-projection.php`,
    snippets: ["public_view", "organizer_view", "owner_view", "strip_internal_score", "rest_request_after_callbacks"],
  },
  {
    file: `${plugin}/includes/passport/class-document-vault.php`,
    snippets: ["find_reusable", "organizer_review_required", "verify_token", "hash_equals", "record_on_application"],
  },
  {
    file: `${plugin}/includes/passport/class-verification-records.php`,
    snippets: ["append", "expire_due", "backfill_from_meta", "on_legacy_meta"],
  },
  {
    file: `${plugin}/includes/passport/class-participation-history.php`,
    snippets: ["is_completed_participation", "max_stops_in_one_tour", "hidden_event_ids"],
  },
  {
    file: `${plugin}/includes/passport/class-reputation-summary.php`,
    snippets: ["passport_review_min_count", "display_average", "Not enough reviews yet"],
  },
  {
    file: `${plugin}/includes/passport/class-passport-public.php`,
    snippets: ["vendors/([a-z0-9]", "301", "application/ld+json", "noindex"],
  },
  {
    file: "wp-content/plugins/lnkup-communications/includes/listeners/class-comms-listen-passport.php",
    snippets: ["v_verification_update", "v_review_received", "o_document_shared", "v_tour_invite"],
  },
  { file: "scripts/e2e/php/vendor-passport-test.php", snippets: ["Vendor Passport & Reputation OS", "lnkup_t_finish"] },
  { file: "tests/e2e/vendor-passport.spec.ts", snippets: ["legacy query URL redirects permanently"] },
  { file: "docs/PHASE_27_VENDOR_PASSPORT.md", snippets: ["Vendor Passport", "Rollback"] },
  { file: "docs/PHASE_27_RELEASE_CHECKLIST.md", snippets: ["phase27:verify"] },
]

const forbidden = [
  // The public projection must never load private fields.
  { file: `${plugin}/includes/passport/class-passport-projection.php`, pattern: /public_view[\s\S]{0,1200}_lnkup_contact_email/ },
  // Every passport route registers a permission callback.
  { file: `${plugin}/includes/passport/class-passport-rest.php`, pattern: /register_rest_route\((?:(?!permission_callback)[\s\S]){0,400}\);/ },
  // Public reputation must not render the internal score card.
  { file: `${plugin}/includes/class-reputation-rest.php`, pattern: /summary_card\(\s*\$data\['reputation'\]/ },
]

const failures = []
for (const check of requiredChecks) {
  const abs = path.join(root, check.file)
  if (!fs.existsSync(abs)) {
    failures.push(`${check.file}: missing file`)
    continue
  }
  const content = fs.readFileSync(abs, "utf8")
  for (const snippet of check.snippets) {
    if (!content.includes(snippet)) failures.push(`${check.file}: missing snippet "${snippet}"`)
  }
}
for (const check of forbidden) {
  const abs = path.join(root, check.file)
  if (!fs.existsSync(abs)) continue
  if (check.pattern.test(fs.readFileSync(abs, "utf8"))) failures.push(`${check.file}: forbidden pattern ${check.pattern}`)
}

if (failures.length) {
  console.error("Phase 27 verify failed:")
  for (const f of failures) console.error(` - ${f}`)
  process.exit(1)
}
console.log(`Phase 27 verify passed (${requiredChecks.length} files, ${forbidden.length} guards).`)

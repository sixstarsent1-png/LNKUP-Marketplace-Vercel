import fs from "node:fs"
import path from "node:path"

const root = process.cwd()

const requiredChecks = [
  {
    file: "lib/api/passport.ts",
    snippets: [
      "/passport/me",
      "/passport/me/portfolio",
      "/passport/me/privacy",
      "/passport/me/history",
      "/passport/me/reviews",
      "/passport/me/verification",
      "/reputation/reviews/${reviewId}/response",
      "/reputation/disputes/${disputeId}/appeal",
      "/passport/vendors/${profileId}/organizer",
      "/passport/vendors/${profileId}/invite-tour",
      "wpFetchFormData",
    ],
  },
  {
    file: "app/(vendor)/passport.tsx",
    snippets: ["SegmentedTabs", "LoadingPanel", "ErrorPanel", "EmptyPanel", "Share.share", "updatePrivacy", "asPassportTab"],
  },
  {
    file: "app/(vendor)/_layout.tsx",
    snippets: ['name="passport"'],
  },
  {
    file: "app/(vendor)/more.tsx",
    snippets: ['"/(vendor)/passport"'],
  },
  {
    file: "components/organizer-vendors/passport-panel.tsx",
    snippets: ["fetchOrganizerPassport", "inviteToTour", "Not verified yet"],
  },
  {
    file: "package.json",
    snippets: ["phase27:verify"],
  },
]

// Native stays a client of WordPress: no local vendor identity store, no Stripe, no internal score.
const forbidden = [
  { file: "lib/api/passport.ts", snippets: ["@stripe/stripe-react-native", "AsyncStorage", "reputation_score", "vendor_score"] },
  { file: "app/(vendor)/passport.tsx", snippets: ["Reputation score", "SecureStore.setItem"] },
  { file: "app/(vendor)/reviews.tsx", snippets: ["Reputation score"] },
  { file: "components/organizer-vendors/passport-panel.tsx", snippets: ["vendor_score", "reputation_score"] },
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
    if (!content.includes(snippet)) failures.push(`${check.file}: missing snippet "${snippet}"`)
  }
}

for (const check of forbidden) {
  const absolutePath = path.join(root, check.file)
  if (!fs.existsSync(absolutePath)) continue
  const content = fs.readFileSync(absolutePath, "utf8")
  for (const snippet of check.snippets) {
    if (content.includes(snippet)) failures.push(`${check.file}: forbidden snippet present "${snippet}"`)
  }
}

if (failures.length > 0) {
  console.error("Phase 27 mobile verify failed:")
  for (const failure of failures) console.error(` - ${failure}`)
  process.exit(1)
}

console.log(`Phase 27 mobile verify passed (${requiredChecks.length} files).`)

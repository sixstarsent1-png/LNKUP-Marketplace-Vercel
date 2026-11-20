import fs from "node:fs"
import path from "node:path"

const root = process.cwd()

const requiredChecks = [
  {
    file: "lib/tutorials/tutorial-contract.ts",
    snippets: [
      "profile_readiness",
      "discover_opportunity",
      "submit_application",
      "complete_payment_pass",
      "launch_event",
      "review_requests",
      "track_payments",
      "pushHref",
    ],
  },
  {
    file: "lib/tutorials/tutorial-progress.ts",
    snippets: [
      "lnkup_tutorial_progress_v1",
      "getTutorialProgress",
      "setTutorialDismissed",
      "markTutorialViewed",
      "markTutorialPopupSeen",
      "popupSeen",
      "getItemAsync",
      "setItemAsync",
    ],
  },
  {
    file: "lib/tutorials/build-vendor-tutorial.ts",
    snippets: ["buildVendorTutorial", "profile_readiness", "pushHref"],
  },
  {
    file: "lib/tutorials/build-organizer-tutorial.ts",
    snippets: ["buildOrganizerTutorial", "launch_event", "pushHref"],
  },
  {
    file: "lib/tutorials/use-role-tutorial.ts",
    snippets: [
      "tutorial_viewed",
      "tutorial_step_clicked",
      "tutorial_step_completed",
      "tutorial_push_cta_clicked",
      "showPopup",
      "markPopupSeen",
    ],
  },
  {
    file: "components/guided-tutorial-checklist.tsx",
    snippets: [
      "GuidedTutorialChecklist",
      "Enable push alerts",
      "tutorial.pushHref",
    ],
  },
  {
    file: "components/tutorial-popup-modal.tsx",
    snippets: [
      "TutorialPopupModal",
      "Skip all",
      "onFinish",
      "onSkipAll",
    ],
  },
  {
    file: "app/(vendor)/index.tsx",
    snippets: [
      "GuidedTutorialChecklist",
      "TutorialPopupModal",
      "buildVendorTutorial",
      "useRoleTutorial",
      'testID="vendor-home-tutorial"',
      'testID="vendor-tutorial-popup"',
    ],
  },
  {
    file: "app/(organizer)/index.tsx",
    snippets: [
      "GuidedTutorialChecklist",
      "TutorialPopupModal",
      "buildOrganizerTutorial",
      "useRoleTutorial",
      'testID="organizer-home-tutorial"',
      'testID="organizer-tutorial-popup"',
    ],
  },
  {
    file: "lib/analytics/mobile-events.ts",
    snippets: [
      "tutorial_viewed",
      "tutorial_step_clicked",
      "tutorial_step_completed",
      "tutorial_push_cta_clicked",
    ],
  },
  {
    file: "app/(vendor)/profile.tsx",
    snippets: ["PushPreferencesCard"],
  },
  {
    file: "app/(organizer)/more.tsx",
    snippets: ["PushPreferencesCard"],
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
  console.error("Phase 23 verify failed:")
  for (const failure of failures) {
    console.error(`- ${failure}`)
  }
  process.exit(1)
}

console.log("Phase 23 verify passed.")

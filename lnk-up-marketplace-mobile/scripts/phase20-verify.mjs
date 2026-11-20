import fs from "node:fs"
import path from "node:path"

const root = process.cwd()

const requiredChecks = [
  {
    file: "app/(organizer)/index.tsx",
    snippets: [
      "OrganizerIntelligenceCard",
      "organizer_experiment_viewed",
      "organizer_experiment_recommendation_opened",
      "organizer_experiment_recommendation_action_clicked",
      "organizer_experiment_decision_recorded",
    ],
  },
  {
    file: "components/organizer-intelligence-card.tsx",
    snippets: [
      "organizer-mobile-active-experiment",
      "organizer-mobile-diagnostics",
      "organizer-mobile-top-recommendation",
    ],
  },
  {
    file: "lib/api/organizer-home.ts",
    snippets: ["intelligence", "mapOrganizerDiagnostics", "mapOrganizerExperiments", "mapOrganizerRecommendations"],
  },
  {
    file: "lib/api/organizer-intelligence.ts",
    snippets: ["OrganizerExperimentStatus", "OrganizerRecommendation", "prioritizeOrganizerRecommendations"],
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
  console.error("Phase 20 verify failed:")
  for (const failure of failures) {
    console.error(`- ${failure}`)
  }
  process.exit(1)
}

console.log("Phase 20 verify passed.")

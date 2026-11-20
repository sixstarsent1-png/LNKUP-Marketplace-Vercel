import fs from "node:fs"
import path from "node:path"

const root = process.cwd()
const artifactsDir = path.join(root, "artifacts")
const statusPath = path.join(artifactsDir, "phase15-20-command-status.json")
const reportJsonPath = path.join(artifactsDir, "phase15-20-gate-report.json")
const reportMdPath = path.join(artifactsDir, "phase15-20-gate-report.md")
const testResultsDir = path.join(root, "test-results")

function ensureArtifactsDir() {
  fs.mkdirSync(artifactsDir, { recursive: true })
}

function readJsonIfExists(filePath) {
  if (!fs.existsSync(filePath)) return null
  try {
    return JSON.parse(fs.readFileSync(filePath, "utf8"))
  } catch {
    return null
  }
}

function findFailureContexts(dir) {
  if (!fs.existsSync(dir)) return []
  const failures = []
  const stack = [dir]
  while (stack.length) {
    const current = stack.pop()
    const entries = fs.readdirSync(current, { withFileTypes: true })
    for (const entry of entries) {
      const nextPath = path.join(current, entry.name)
      if (entry.isDirectory()) {
        stack.push(nextPath)
      } else if (entry.isFile() && entry.name === "error-context.md") {
        failures.push(nextPath)
      }
    }
  }
  return failures
}

function toStatusLabel(value) {
  if (value === 0) return "pass"
  if (value === null || value === undefined) return "unknown"
  return "fail"
}

function buildBlockers(commandStatus, failureCount) {
  const blockers = []
  if (commandStatus.phaseSuites && commandStatus.phaseSuites !== 0) {
    blockers.push({
      severity: "critical",
      source: "phase_suites",
      message: "Phase 16-20 suite command failed.",
    })
  }
  if (commandStatus.mobileTypecheck && commandStatus.mobileTypecheck !== 0) {
    blockers.push({
      severity: "high",
      source: "mobile_typecheck",
      message: "Mobile typecheck failed.",
    })
  }
  if (commandStatus.mobileVerify && commandStatus.mobileVerify !== 0) {
    blockers.push({
      severity: "high",
      source: "mobile_verify",
      message: "Phase 20 mobile verification failed.",
    })
  }
  if (failureCount > 0) {
    blockers.push({
      severity: "high",
      source: "playwright_failures",
      message: `${failureCount} failure context file(s) found in test-results.`,
    })
  }
  return blockers
}

function overallDecision(blockers) {
  if (blockers.some((item) => item.severity === "critical")) return "no_go"
  if (blockers.some((item) => item.severity === "high")) return "no_go"
  if (blockers.some((item) => item.severity === "medium")) return "conditional_go"
  return "go"
}

ensureArtifactsDir()

const statusPayload = readJsonIfExists(statusPath) ?? {
  lint: null,
  mobileTypecheck: null,
  phaseSuites: null,
  analyticsSmoke: null,
  mobileVerify: null,
}

const failureContexts = findFailureContexts(testResultsDir)
const blockers = buildBlockers(statusPayload, failureContexts.length)
const decision = overallDecision(blockers)

const summary = {
  generatedAt: new Date().toISOString(),
  decision,
  commandStatus: {
    lint: toStatusLabel(statusPayload.lint),
    mobileTypecheck: toStatusLabel(statusPayload.mobileTypecheck),
    phaseSuites: toStatusLabel(statusPayload.phaseSuites),
    analyticsSmoke: toStatusLabel(statusPayload.analyticsSmoke),
    mobileVerify: toStatusLabel(statusPayload.mobileVerify),
  },
  failureContextCount: failureContexts.length,
  blockers,
  recommendations:
    decision === "go"
      ? ["Proceed with release workflow.", "Retain artifacts for rollout audit."]
      : [
          "Do not promote to production.",
          "Address blockers and rerun Phase 15-20 quality gate.",
        ],
}

const markdown = [
  "# Phase 15-20 Gate Report",
  "",
  `- Generated: ${summary.generatedAt}`,
  `- Decision: **${summary.decision}**`,
  `- Playwright failure contexts: ${summary.failureContextCount}`,
  "",
  "## Command Status",
  `- Lint: ${summary.commandStatus.lint}`,
  `- Mobile typecheck: ${summary.commandStatus.mobileTypecheck}`,
  `- Phase suites: ${summary.commandStatus.phaseSuites}`,
  `- Analytics smoke: ${summary.commandStatus.analyticsSmoke}`,
  `- Mobile verify: ${summary.commandStatus.mobileVerify}`,
  "",
  "## Blockers",
  ...(summary.blockers.length
    ? summary.blockers.map((item) => `- [${item.severity}] ${item.source}: ${item.message}`)
    : ["- None"]),
  "",
  "## Recommendations",
  ...summary.recommendations.map((item) => `- ${item}`),
  "",
].join("\n")

fs.writeFileSync(reportJsonPath, `${JSON.stringify(summary, null, 2)}\n`, "utf8")
fs.writeFileSync(reportMdPath, `${markdown}\n`, "utf8")

console.log(`[phase15-20-gate] wrote ${reportJsonPath}`)
console.log(`[phase15-20-gate] wrote ${reportMdPath}`)

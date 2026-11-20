/**
 * Store submission preflight — automated checks before eas submit.
 */
import fs from "node:fs"
import path from "node:path"
import { fileURLToPath } from "node:url"
import { spawnSync } from "node:child_process"

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..")
const repoRoot = path.resolve(root, "..")
const mobile = root
const fails = []

function assertFile(rel, label) {
  const full = path.join(mobile, rel)
  if (!fs.existsSync(full)) fails.push(`${label}: missing ${rel}`)
}

assertFile("STORE_RELEASE.md", "store-release-doc")
assertFile("eas.json", "eas-config")
assertFile("components/delete-account-card.tsx", "account-deletion-ui")

const eas = JSON.parse(fs.readFileSync(path.join(mobile, "eas.json"), "utf8"))
if (!eas.submit?.production?.ios?.ascAppId) {
  fails.push("eas: missing submit.production.ios.ascAppId")
}
if (eas.build?.production?.env?.EXPO_PUBLIC_LNKUP_LIVE_API !== "true") {
  fails.push("eas: production profile must set EXPO_PUBLIC_LNKUP_LIVE_API=true")
}

const appJson = JSON.parse(fs.readFileSync(path.join(mobile, "app.json"), "utf8"))
for (const key of ["privacyPolicyUrl", "supportUrl", "termsOfServiceUrl"]) {
  const url = appJson.expo?.extra?.[key]
  if (!url || !String(url).startsWith("https://")) {
    fails.push(`app.json: extra.${key} must be https URL`)
  }
}

const ascRun = spawnSync("node", [path.join(repoRoot, "scripts/app-store-readiness-verify.mjs")], {
  cwd: repoRoot,
  encoding: "utf8",
})
if (ascRun.status !== 0) {
  fails.push("app-store-readiness-verify failed")
  if (ascRun.stderr) console.error(ascRun.stderr)
}

if (fails.length) {
  console.error("store-preflight-verify FAILED:\n" + fails.map((f) => ` - ${f}`).join("\n"))
  process.exit(1)
}

console.log("store-preflight-verify OK")
console.log("Manual: iOS/Android screenshots, eas submit, account deletion on device")

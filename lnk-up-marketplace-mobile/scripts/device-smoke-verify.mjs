/**
 * Static preflight for TestFlight build 10 splash + referral surfaces.
 */
import fs from "node:fs"
import path from "node:path"
import { fileURLToPath } from "node:url"

const mobileRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..")
const repoRoot = path.resolve(mobileRoot, "..")
const fails = []

function assertIncludes(rel, snippets, label) {
  const full = path.join(mobileRoot, rel)
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
  "app.json",
  ['"backgroundColor": "#000000"', "splash-icon.png", '"imageWidth": 200'],
  "splash-config",
)

assertIncludes(
  "scripts/generate-native-icons.mjs",
  ["splash-icon.png", "transparent"],
  "splash-generator",
)

assertIncludes(
  "components/push-bootstrap.tsx",
  ["return null"],
  "push-bootstrap-web",
)

assertIncludes(
  "app/(vendor)/index.tsx",
  ["referral"],
  "vendor-home-referrals",
)

assertIncludes(
  "app/(organizer)/index.tsx",
  ["referral"],
  "organizer-home-referrals",
)

const splashPath = path.join(mobileRoot, "assets/images/splash-icon.png")
if (!fs.existsSync(splashPath)) {
  fails.push("splash: missing assets/images/splash-icon.png")
} else {
  const size = fs.statSync(splashPath).size
  if (size < 1000) fails.push(`splash: splash-icon.png suspiciously small (${size} bytes)`)
}

const pluginVersion = fs.readFileSync(
  path.join(repoRoot, "wp-content/plugins/lnkup-marketplace/lnkup-marketplace.php"),
  "utf8",
)
const match = pluginVersion.match(/Version:\s*([\d.]+)/)
if (!match || compareSemver(match[1], "12.18.27") < 0) {
  fails.push(`plugin: repo version must be >= 12.18.27 (got ${match?.[1] ?? "unknown"})`)
}

if (fails.length) {
  console.error("device-smoke-verify FAILED:\n" + fails.map((f) => ` - ${f}`).join("\n"))
  process.exit(1)
}

console.log("device-smoke-verify OK (static — confirm splash on TestFlight build 10 device)")

function compareSemver(a, b) {
  const pa = a.split(".").map(Number)
  const pb = b.split(".").map(Number)
  for (let i = 0; i < Math.max(pa.length, pb.length); i++) {
    const diff = (pa[i] ?? 0) - (pb[i] ?? 0)
    if (diff !== 0) return diff
  }
  return 0
}

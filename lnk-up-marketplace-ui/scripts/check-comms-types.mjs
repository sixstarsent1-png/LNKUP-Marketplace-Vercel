// Fails when WordPress (comms-types.json, from `wp lnkup-comms export-types`) knows a notification
// type or inbox tab that the web app does not. Run: npm run check:comms-types
import { readFileSync } from "node:fs"
import { fileURLToPath } from "node:url"

const root = fileURLToPath(new URL("..", import.meta.url))
const server = JSON.parse(readFileSync(`${root}lib/api/comms-types.json`, "utf8"))
const typesSource = readFileSync(`${root}lib/api/comms-types.ts`, "utf8")
const commsSource = readFileSync(`${root}lib/api/comms.ts`, "utf8")

const listed = new Set([...typesSource.matchAll(/^\s+"([a-z_]+)",$/gm)].map((m) => m[1]))
const tabsMatch = /export const INBOX_TABS = \[([^\]]+)\]/.exec(commsSource)
const webTabs = new Set(tabsMatch ? [...tabsMatch[1].matchAll(/"([a-z]+)"/g)].map((m) => m[1]) : [])

const problems = []
for (const { type } of server.types) {
  if (!listed.has(type)) problems.push(`Unknown notification type: ${type} (add it to lib/api/comms-types.ts)`)
}
for (const role of ["vendor", "organizer"]) {
  for (const tab of server.tabs[role]) {
    if (!webTabs.has(tab)) problems.push(`Unknown ${role} inbox tab: ${tab} (add it to INBOX_TABS in lib/api/comms.ts)`)
  }
}

if (problems.length) {
  console.error(problems.join("\n"))
  process.exit(1)
}
console.log(`comms types in sync: ${server.types.length} types, ${webTabs.size} tabs`)

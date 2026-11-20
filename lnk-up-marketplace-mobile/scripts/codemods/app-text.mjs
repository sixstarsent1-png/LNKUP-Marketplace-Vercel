#!/usr/bin/env node
/**
 * One-off codemod: replace `Text` from "react-native" with the Dynamic Type–capped AppText.
 * Usage: node scripts/codemods/app-text.mjs [--dry]
 */
import fs from "node:fs"
import path from "node:path"

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, "$1")), "..", "..")
const dry = process.argv.includes("--dry")
const skip = new Set([
  path.join(root, "components", "ui", "app-text.tsx"),
  path.join(root, "components", "ui", "typography.tsx"),
])

function walk(dir, out = []) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name)
    if (entry.isDirectory()) walk(full, out)
    else if (/\.tsx$/.test(entry.name)) out.push(full)
  }
  return out
}

const importRe = /import\s*\{([^}]*)\}\s*from\s*"react-native"/m
let changed = 0

for (const file of [...walk(path.join(root, "app")), ...walk(path.join(root, "components"))]) {
  if (skip.has(file)) continue
  const src = fs.readFileSync(file, "utf8")
  const match = src.match(importRe)
  if (!match) continue
  const names = match[1].split(",").map((n) => n.trim()).filter(Boolean)
  if (!names.includes("Text")) continue
  const rest = names.filter((n) => n !== "Text")
  const multiline = match[1].includes("\n")
  const rebuilt = rest.length
    ? multiline
      ? `import {\n  ${rest.join(",\n  ")},\n} from "react-native"`
      : `import { ${rest.join(", ")} } from "react-native"`
    : ""
  let next = src.replace(importRe, rebuilt)
  const appTextImport = `import { AppText as Text } from "@/components/ui/app-text"`
  next = rebuilt ? next.replace(rebuilt, `${rebuilt}\n${appTextImport}`) : next.replace(/^/, `${appTextImport}\n`)
  if (!rebuilt) next = next.replace(/^\n/, "")
  changed += 1
  if (!dry) fs.writeFileSync(file, next)
  console.log(`${dry ? "[dry] " : ""}${path.relative(root, file)}`)
}

console.log(`${changed} file(s) ${dry ? "would change" : "changed"}`)

#!/usr/bin/env node
/**
 * Remove StyleSheet entries no longer referenced as `styles.<name>` in the same file.
 * Usage: node scripts/codemods/drop-unused-styles.mjs <file> [file…]
 * Only touches top-level two-space-indented `name: { … },` blocks inside StyleSheet.create.
 */
import fs from "node:fs"

for (const file of process.argv.slice(2)) {
  const src = fs.readFileSync(file, "utf8")
  const start = src.search(/StyleSheet\.create\(\{/)
  if (start < 0) {
    console.log(`skip ${file}`)
    continue
  }
  const head = src.slice(0, start)
  let sheet = src.slice(start)
  const names = [...sheet.matchAll(/^ {2}(\w+): \{/gm)].map((m) => m[1])
  const removed = []
  for (const name of names) {
    if (new RegExp(`styles\\.${name}\\b`).test(head)) continue
    const block = new RegExp(`^ {2}${name}: \\{[\\s\\S]*?^ {2}\\},?\\r?\\n`, "m")
    if (block.test(sheet)) {
      sheet = sheet.replace(block, "")
      removed.push(name)
    }
  }
  fs.writeFileSync(file, head + sheet)
  console.log(`${file}: removed ${removed.length ? removed.join(", ") : "nothing"}`)
}

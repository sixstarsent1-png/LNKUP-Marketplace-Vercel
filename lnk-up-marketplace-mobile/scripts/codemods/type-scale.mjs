#!/usr/bin/env node
/**
 * Replace raw `fontSize: N` in StyleSheets with the shared `typeScale` tokens.
 * Usage: node scripts/codemods/type-scale.mjs <file> [file…]
 * Sizes between steps round to the nearest step (20 and 24 become `title`, 22).
 */
import fs from "node:fs"

const SCALE = [
  ["micro", 11],
  ["caption", 12],
  ["footnote", 13],
  ["body", 14],
  ["callout", 15],
  ["bodyLarge", 16],
  ["headline", 18],
  ["title", 22],
  ["display", 28],
]

function tokenFor(size) {
  let best = SCALE[0]
  for (const step of SCALE) {
    if (Math.abs(step[1] - size) < Math.abs(best[1] - size)) best = step
  }
  return best[0]
}

for (const file of process.argv.slice(2)) {
  let src = fs.readFileSync(file, "utf8")
  let count = 0
  src = src.replace(/fontSize:\s*(\d+)(?=\s*[,\n}])/g, (_, raw) => {
    count += 1
    return `fontSize: typeScale.${tokenFor(Number(raw))}`
  })
  if (count === 0) {
    console.log(`skip ${file}`)
    continue
  }
  const themeImport = /import \{([^}]*)\} from "@\/lib\/theme"/
  const match = src.match(themeImport)
  if (match && !/\btypeScale\b/.test(match[1])) {
    src = src.replace(themeImport, (_, names) => `import {${names.trimEnd()}, typeScale } from "@/lib/theme"`)
  } else if (!match) {
    src = src.replace(/(\nimport [^\n]+\n)(?!import)/, `$1import { typeScale } from "@/lib/theme"\n`)
  }
  fs.writeFileSync(file, src)
  console.log(`updated ${file}: ${count} sizes`)
}

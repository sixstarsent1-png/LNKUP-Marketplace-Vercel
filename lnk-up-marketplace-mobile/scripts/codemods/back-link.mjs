#!/usr/bin/env node
/** One-off codemod: replace hand-rolled "← Back to More" links with the shared BackLink. */
import fs from "node:fs"
import path from "node:path"

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, "$1")), "..", "..")
const files = ["documents", "favorites", "growth", "operations", "reviews"].map((name) =>
  path.join(root, "app", "(vendor)", `${name}.tsx`),
)
const block = /<Pressable style=\{styles\.back\} onPress=\{\(\) => safeBack\("\/\(vendor\)\/more"\)\}>\s*<Text style=\{styles\.backText\}>← Back to More<\/Text>\s*<\/Pressable>/
const importLine = `import { BackLink } from "@/components/ui/back-link"`

for (const file of files) {
  let src = fs.readFileSync(file, "utf8")
  if (!block.test(src)) {
    console.log(`skip ${path.basename(file)}`)
    continue
  }
  src = src.replace(block, `<BackLink label="Profile" fallbackHref="/(vendor)/more" />`)
  if (!src.includes(importLine)) {
    src = src.replace(/(import \{ Screen \} from "@\/components\/screen"\n)/, `$1${importLine}\n`)
  }
  if (!/safeBack\(/.test(src)) {
    src = src.replace(/import \{ safeBack \} from "@\/lib\/navigation\/safe-back"\n/, "")
  }
  fs.writeFileSync(file, src)
  console.log(`updated ${path.basename(file)}`)
}

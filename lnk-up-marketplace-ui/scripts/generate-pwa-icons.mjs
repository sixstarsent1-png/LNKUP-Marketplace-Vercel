import { mkdir, readFile, writeFile } from "node:fs/promises"
import { dirname, join } from "node:path"
import { fileURLToPath } from "node:url"

const __dirname = dirname(fileURLToPath(import.meta.url))
const root = join(__dirname, "..")
const publicDir = join(root, "public")
const iconsDir = join(publicDir, "icons")

async function main() {
  let sharp
  try {
    sharp = (await import("sharp")).default
  } catch {
    console.error("sharp is required. Run: pnpm add -D sharp")
    process.exit(1)
  }

  await mkdir(iconsDir, { recursive: true })

  const svg = await readFile(join(publicDir, "icon.svg"))

  const sizes = [
    { name: "icons/icon-192.png", size: 192 },
    { name: "icons/icon-512.png", size: 512 },
    { name: "apple-touch-icon.png", size: 180 },
  ]

  for (const { name, size } of sizes) {
    const out = join(publicDir, name)
    await sharp(svg).resize(size, size).png().toFile(out)
    console.log("wrote", name)
  }
}

main().catch((err) => {
  console.error(err)
  process.exit(1)
})

import { mkdir } from "node:fs/promises"
import { dirname, join } from "node:path"
import { fileURLToPath } from "node:url"

const __dirname = dirname(fileURLToPath(import.meta.url))
const root = join(__dirname, "..")
const brandDir = join(root, "assets", "brand")
const imagesDir = join(root, "assets", "images")
const logoPath = join(brandDir, "lnkup-logo.png")

/** Near-black → transparent so splash is logo-only (no black box on device). */
async function logoOnTransparent(sharp, size) {
  const { data, info } = await sharp(logoPath)
    .ensureAlpha()
    .raw()
    .toBuffer({ resolveWithObject: true })

  for (let i = 0; i < data.length; i += 4) {
    const r = data[i]
    const g = data[i + 1]
    const b = data[i + 2]
    // Knock out near-black background from brand export
    if (r < 28 && g < 28 && b < 28) {
      data[i + 3] = 0
    }
  }

  return sharp(data, {
    raw: { width: info.width, height: info.height, channels: 4 },
  })
    .resize(size, size, {
      fit: "contain",
      background: { r: 0, g: 0, b: 0, alpha: 0 },
    })
    .png()
}

async function main() {
  let sharp
  try {
    sharp = (await import("sharp")).default
  } catch {
    console.error("sharp is required. Run: npm install -D sharp")
    process.exit(1)
  }

  await mkdir(imagesDir, { recursive: true })

  // App Store icon — opaque full-bleed black (Apple rejects transparency)
  await sharp(logoPath)
    .resize(1024, 1024, {
      fit: "cover",
      background: { r: 0, g: 0, b: 0, alpha: 1 },
    })
    .flatten({ background: "#000000" })
    .png()
    .toFile(join(imagesDir, "icon.png"))
  console.log("wrote assets/images/icon.png (opaque)")

  // Splash — transparent logo only; native splash fills with backgroundColor
  await (await logoOnTransparent(sharp, 512))
    .toFile(join(imagesDir, "splash-icon.png"))
  console.log("wrote assets/images/splash-icon.png (transparent)")

  // Android adaptive foreground — transparent mark inset for safe zone
  await (await logoOnTransparent(sharp, 432))
    .extend({
      top: 40,
      bottom: 40,
      left: 40,
      right: 40,
      background: { r: 0, g: 0, b: 0, alpha: 0 },
    })
    .png()
    .toFile(join(imagesDir, "android-icon-foreground.png"))
  console.log("wrote assets/images/android-icon-foreground.png")

  // Android adaptive background — solid black
  await sharp({
    create: {
      width: 512,
      height: 512,
      channels: 3,
      background: { r: 0, g: 0, b: 0 },
    },
  })
    .png()
    .toFile(join(imagesDir, "android-icon-background.png"))
  console.log("wrote assets/images/android-icon-background.png")

  // Android monochrome — silhouette from luminance
  const monoBase = await logoOnTransparent(sharp, 432)
  await monoBase
    .greyscale()
    .threshold(40)
    .png()
    .toFile(join(imagesDir, "android-icon-monochrome.png"))
  console.log("wrote assets/images/android-icon-monochrome.png")

  await sharp(logoPath).resize(48, 48).png().toFile(join(imagesDir, "favicon.png"))
  console.log("wrote assets/images/favicon.png")
}

main().catch((err) => {
  console.error(err)
  process.exit(1)
})

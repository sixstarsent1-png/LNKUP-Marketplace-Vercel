import fs from "node:fs"
import path from "node:path"

const root = process.cwd()

const legacyTypographyPath = path.join(root, "components/ui/text.tsx")

const requiredChecks = [
  {
    file: "lib/theme.ts",
    snippets: ["fonts.display", "Sora_700Bold", "Inter_400Regular", "surfaceStyle", "shadow("],
  },
  {
    file: "lib/fonts/use-lnkup-fonts.ts",
    snippets: ["useFonts", "Sora_700Bold", "Inter_400Regular"],
  },
  {
    file: "app/_layout.tsx",
    snippets: ["useLnkupFonts", "fontsLoaded"],
  },
  {
    file: "components/ui/typography.tsx",
    snippets: ["Text as RNText", "DisplayTitle", "SectionTitle", "Eyebrow", "<RNText"],
  },
  {
    file: "components/ui/surface.tsx",
    snippets: ["Surface", "accentBorderTop", "surfaceStyle"],
  },
  {
    file: "components/home-hero-card.tsx",
    snippets: ["Surface", "DisplayTitle", "HomeHeroCard", "components/ui/typography"],
  },
  {
    file: "components/opportunity-card.tsx",
    snippets: ["Surface", "expo-image", "OpportunityThumbnail", "gallery"],
  },
  {
    file: "components/organizer-home-hero.tsx",
    snippets: ["OrganizerHomeHero", "Surface"],
  },
  {
    file: "app/login.tsx",
    snippets: ["lnkup-logo.png", "Surface", "DisplayTitle", "components/ui/typography"],
  },
  {
    file: "app/(vendor)/find.tsx",
    snippets: ["viewToggleBtnActive", "colors.lime", "searchSurface", "components/ui/typography"],
  },
]

const failures = []

if (fs.existsSync(legacyTypographyPath)) {
  failures.push("components/ui/text.tsx: legacy file must be removed (use typography.tsx)")
}

for (const check of requiredChecks) {
  const absolutePath = path.join(root, check.file)
  if (!fs.existsSync(absolutePath)) {
    failures.push(`${check.file}: missing file`)
    continue
  }
  const content = fs.readFileSync(absolutePath, "utf8")
  for (const snippet of check.snippets) {
    if (!content.includes(snippet)) {
      failures.push(`${check.file}: missing snippet "${snippet}"`)
    }
  }
}

if (failures.length > 0) {
  console.error("ui-polish-verify failed:")
  for (const failure of failures) {
    console.error(`- ${failure}`)
  }
  process.exit(1)
}

console.log("ui-polish-verify passed")

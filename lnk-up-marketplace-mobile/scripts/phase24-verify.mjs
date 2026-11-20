import fs from "node:fs"
import path from "node:path"

const root = process.cwd()

const requiredChecks = [
  {
    file: "lib/api/map-pins.ts",
    snippets: [
      "fetchMapPins",
      "/marketplace/map-pins",
      "MarketplaceMapPin",
      "haversineMiles",
      "mockMapPins",
    ],
  },
  {
    file: "components/discovery-map.native.tsx",
    snippets: [
      "react-native-maps",
      "MapView",
      "Marker",
      "map_view_opened",
      "map_pin_clicked",
      "LoadingPanel",
      "EmptyPanel",
      "ErrorPanel",
    ],
  },
  {
    file: "components/discovery-map.tsx",
    snippets: ["map_view_opened", "map_pin_clicked", "fetchMapPins"],
  },
  {
    file: "components/map-pin-preview.tsx",
    snippets: ["MapPinPreview", "/opportunity/", "/apply/"],
  },
  {
    file: "app/(vendor)/find.tsx",
    snippets: [
      "DiscoveryMap",
      'testID="find-view-toggle"',
      'testID="find-view-map"',
      'testID="vendor-find-map"',
      'viewMode === "map"',
    ],
  },
  {
    file: "lib/analytics/mobile-events.ts",
    snippets: ["map_view_opened", "map_pin_clicked"],
  },
  {
    file: "package.json",
    snippets: ['"react-native-maps"', "phase24:verify"],
  },
  {
    file: "app.json",
    snippets: ["react-native-maps"],
  },
]

const failures = []

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
  console.error("Phase 24 verify failed:")
  for (const failure of failures) {
    console.error(`- ${failure}`)
  }
  process.exit(1)
}

console.log("Phase 24 verify passed.")

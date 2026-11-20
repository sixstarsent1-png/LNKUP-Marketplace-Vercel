export type MarketDefinition = {
  slug: string
  city: string
  state: string
  displayName: string
  region?: string
}

export const LAUNCH_MARKETS: MarketDefinition[] = [
  { slug: "charlotte", city: "Charlotte", state: "NC", displayName: "Charlotte", region: "Carolinas" },
  { slug: "atlanta", city: "Atlanta", state: "GA", displayName: "Atlanta", region: "Southeast" },
  { slug: "raleigh", city: "Raleigh", state: "NC", displayName: "Raleigh", region: "Carolinas" },
  { slug: "greensboro", city: "Greensboro", state: "NC", displayName: "Greensboro", region: "Carolinas" },
  { slug: "miami", city: "Miami", state: "FL", displayName: "Miami", region: "Florida" },
  { slug: "dallas", city: "Dallas", state: "TX", displayName: "Dallas", region: "Texas" },
  { slug: "houston", city: "Houston", state: "TX", displayName: "Houston", region: "Texas" },
  { slug: "nashville", city: "Nashville", state: "TN", displayName: "Nashville", region: "Southeast" },
  { slug: "washington-dc", city: "Washington", state: "DC", displayName: "Washington DC", region: "Mid-Atlantic" },
  { slug: "virginia-beach", city: "Virginia Beach", state: "VA", displayName: "Virginia Beach", region: "Mid-Atlantic" },
]

export function getMarketBySlug(slug: string): MarketDefinition | undefined {
  return LAUNCH_MARKETS.find((m) => m.slug === slug)
}

export function getAllMarketSlugs(): string[] {
  return LAUNCH_MARKETS.map((m) => m.slug)
}

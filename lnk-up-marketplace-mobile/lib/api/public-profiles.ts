import { isLiveApiEnabled } from "@/lib/api/config"
import type { WpAuth } from "@/lib/api/wp-client"
import { wpFetchJson } from "@/lib/api/wp-client"

export type PublicProfile = {
  name: string
  tagline?: string
  bio?: string
  city?: string
  state?: string
  categories: string[]
  markets?: string
  website?: string
  instagram?: string
  logoUrl?: string
  coverUrl?: string
}

type WpPublicVendor = {
  business_name?: string
  description?: string
  city?: string
  state?: string
  categories?: unknown
  website?: string
  instagram?: string
  logo?: string
  cover?: string
  cities_served?: unknown
}

function asStringList(raw: unknown): string[] {
  if (typeof raw === "string" && raw.trim()) return [raw.trim()]
  if (!Array.isArray(raw)) return []
  return raw.map((item) => String(item).trim()).filter(Boolean)
}

function mapVendor(data: WpPublicVendor): PublicProfile {
  return {
    name: data.business_name || "Vendor",
    bio: data.description || undefined,
    city: data.city || undefined,
    state: data.state || undefined,
    categories: asStringList(data.categories),
    markets: asStringList(data.cities_served).join(", ") || undefined,
    website: data.website || undefined,
    instagram: data.instagram || undefined,
    logoUrl: data.logo || undefined,
    coverUrl: data.cover || undefined,
  }
}

export async function fetchPublicVendorProfile(slug: string): Promise<PublicProfile> {
  if (!isLiveApiEnabled()) {
    return {
      name: "Demo Vendor Co",
      bio: "Local food vendor.",
      city: "Charlotte",
      state: "NC",
      categories: ["Food Vendor"],
      website: "https://example.com",
    }
  }
  const data = await wpFetchJson<WpPublicVendor>(
    `/reputation/vendors/${encodeURIComponent(slug)}/public`,
  )
  return mapVendor(data)
}

export async function fetchPublicOrganizerProfile(slug: string): Promise<PublicProfile> {
  if (!isLiveApiEnabled()) {
    return {
      name: "Demo Organizer",
      tagline: "Night markets across the city.",
      city: "Charlotte",
      state: "NC",
      categories: [],
    }
  }
  const data = await wpFetchJson<WpPublicVendor & { tagline?: string; service_cities?: string }>(
    `/organizers/${encodeURIComponent(slug)}/public`,
  )
  return {
    ...mapVendor(data),
    tagline: data.tagline || undefined,
    markets: data.service_cities || undefined,
    categories: [],
  }
}

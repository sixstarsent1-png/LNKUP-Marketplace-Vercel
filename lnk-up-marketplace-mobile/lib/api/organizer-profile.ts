import { isLiveApiEnabled, getOrganizerPublicProfileUrl } from "@/lib/api/config"
import { invalidateOrganizerHome } from "@/lib/api/organizer-home"
import { uploadProfileImage } from "@/lib/api/profile-media"
import type { WpAuth } from "@/lib/api/wp-client"
import { wpFetchJson } from "@/lib/api/wp-client"

export type OrganizerProfileEdit = {
  publicName: string
  tagline: string
  bio: string
  city: string
  state: string
  serviceCities: string
  website: string
  instagram: string
  publicSlug: string
  publicUrl: string
  logoUrl: string
  logoId: number
}

type WpOrganizerProfile = {
  public_name?: string
  tagline?: string
  bio?: string
  city?: string
  state?: string
  service_cities?: string
  website?: string
  instagram?: string
  public_slug?: string
  public_url?: string
  logo_url?: string
  logo_id?: number
}

function mapProfile(data: WpOrganizerProfile): OrganizerProfileEdit {
  const publicSlug = data.public_slug ?? ""
  return {
    publicName: data.public_name ?? "",
    tagline: data.tagline ?? "",
    bio: data.bio ?? "",
    city: data.city ?? "",
    state: data.state ?? "",
    serviceCities: data.service_cities ?? "",
    website: data.website ?? "",
    instagram: data.instagram ?? "",
    publicSlug,
    publicUrl: data.public_url || (publicSlug ? getOrganizerPublicProfileUrl(publicSlug) : ""),
    logoUrl: data.logo_url ?? "",
    logoId: Number(data.logo_id ?? 0) || 0,
  }
}

export async function fetchOrganizerProfileEdit(auth?: WpAuth): Promise<OrganizerProfileEdit> {
  if (!isLiveApiEnabled()) {
    return {
      publicName: "Demo Markets",
      tagline: "Night markets and pop-ups.",
      bio: "We host vendor markets across the city.",
      city: "Charlotte",
      state: "NC",
      serviceCities: "Charlotte, Raleigh",
      website: "https://example.com",
      instagram: "@demomarkets",
      publicSlug: "demo-markets",
      publicUrl: getOrganizerPublicProfileUrl("demo-markets"),
      logoUrl: "",
      logoId: 0,
    }
  }
  const data = await wpFetchJson<WpOrganizerProfile>("/organizer/profile", { auth })
  return mapProfile(data)
}

export async function updateOrganizerProfileEdit(
  patch: Partial<OrganizerProfileEdit>,
  auth?: WpAuth,
): Promise<OrganizerProfileEdit> {
  if (!isLiveApiEnabled()) {
    return {
      publicName: patch.publicName ?? "Demo Markets",
      tagline: patch.tagline ?? "",
      bio: patch.bio ?? "",
      city: patch.city ?? "",
      state: patch.state ?? "",
      serviceCities: patch.serviceCities ?? "",
      website: patch.website ?? "",
      instagram: patch.instagram ?? "",
      publicSlug: "demo-markets",
      publicUrl: getOrganizerPublicProfileUrl("demo-markets"),
      logoUrl: patch.logoUrl ?? "",
      logoId: patch.logoId ?? 0,
    }
  }
  const body: Record<string, string> = {}
  if (patch.publicName !== undefined) body.public_name = patch.publicName
  if (patch.tagline !== undefined) body.tagline = patch.tagline
  if (patch.bio !== undefined) body.bio = patch.bio
  if (patch.city !== undefined) body.city = patch.city
  if (patch.state !== undefined) body.state = patch.state
  if (patch.serviceCities !== undefined) body.service_cities = patch.serviceCities
  if (patch.website !== undefined) body.website = patch.website
  if (patch.instagram !== undefined) body.instagram = patch.instagram
  const data = await wpFetchJson<WpOrganizerProfile>("/organizer/profile", {
    method: "POST",
    auth,
    body,
  }).finally(invalidateOrganizerHome)
  return mapProfile(data)
}

export async function uploadOrganizerLogo(
  file: { uri: string; fileName: string; mimeType?: string },
  auth?: WpAuth,
): Promise<{ logoUrl: string; logoId: number }> {
  if (!isLiveApiEnabled()) {
    return { logoUrl: file.uri, logoId: 1 }
  }
  const uploaded = await uploadProfileImage("/organizer/profile/logo", file, auth)
  return { logoUrl: uploaded.url, logoId: uploaded.attachmentId }
}

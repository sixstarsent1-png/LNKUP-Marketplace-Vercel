import { isLiveApiEnabled } from "@/lib/api/config"
import { uploadProfileImage } from "@/lib/api/profile-media"
import type { WpAuth } from "@/lib/api/wp-client"
import { wpFetchJson } from "@/lib/api/wp-client"
import { invalidateVendorReadiness } from "@/lib/api/vendor-readiness"

export type VendorProfileEdit = {
  profileId: number
  businessName: string
  description: string
  website: string
  instagram: string
  contactEmail: string
  contactPhone: string
  city: string
  state: string
  categories: string[]
  logoUrl: string
  logoAttachmentId: number
  publicSlug: string
  publicUrl: string
}

type WpVendorProfile = {
  profile_id?: number
  business_name?: string
  description?: string
  website?: string
  instagram?: string
  contact_email?: string
  contact_phone?: string
  city?: string
  state?: string
  categories?: string[]
  logo_url?: string
  logo_attachment_id?: number
  public_slug?: string
  public_url?: string
}

function mapProfile(data: WpVendorProfile): VendorProfileEdit {
  const publicSlug = data.public_slug ?? ""
  return {
    profileId: Number(data.profile_id ?? 0),
    businessName: data.business_name ?? "",
    description: data.description ?? "",
    website: data.website ?? "",
    instagram: data.instagram ?? "",
    contactEmail: data.contact_email ?? "",
    contactPhone: data.contact_phone ?? "",
    city: data.city ?? "",
    state: data.state ?? "",
    categories: Array.isArray(data.categories) ? data.categories.map(String).filter(Boolean) : [],
    logoUrl: data.logo_url ?? "",
    logoAttachmentId: Number(data.logo_attachment_id ?? 0) || 0,
    publicSlug,
    publicUrl: data.public_url ?? "",
  }
}

export async function fetchVendorProfileEdit(auth?: WpAuth): Promise<VendorProfileEdit> {
  if (!isLiveApiEnabled()) {
    return {
      profileId: 1,
      businessName: "Demo Vendor Co",
      description: "Mock profile for offline editing.",
      website: "https://example.com",
      instagram: "@demo",
      contactEmail: "demo@example.com",
      contactPhone: "555-0100",
      city: "Charlotte",
      state: "NC",
      categories: ["Food Vendor"],
      logoUrl: "",
      logoAttachmentId: 0,
      publicSlug: "demo-vendor-co",
      publicUrl: "https://thelnkup.com/vendors/?vendor=demo-vendor-co",
    }
  }

  const data = await wpFetchJson<WpVendorProfile>("/vendor/profile", { auth })
  return mapProfile(data)
}

export async function updateVendorProfileEdit(
  patch: Partial<Omit<VendorProfileEdit, "profileId">>,
  auth?: WpAuth,
): Promise<{ success: boolean; message?: string }> {
  if (!isLiveApiEnabled()) {
    return { success: true, message: "Profile saved (mock)." }
  }

  const body: Record<string, unknown> = {}
  if (patch.businessName !== undefined) body.business_name = patch.businessName
  if (patch.description !== undefined) body.description = patch.description
  if (patch.website !== undefined) body.website = patch.website
  if (patch.instagram !== undefined) body.instagram = patch.instagram
  if (patch.contactEmail !== undefined) body.contact_email = patch.contactEmail
  if (patch.contactPhone !== undefined) body.contact_phone = patch.contactPhone
  if (patch.city !== undefined) body.city = patch.city
  if (patch.state !== undefined) body.state = patch.state
  if (patch.categories !== undefined) body.categories = patch.categories

  const result = await wpFetchJson<{ success?: boolean; message?: string }>("/vendor/profile", {
    method: "POST",
    auth,
    body,
  }).finally(invalidateVendorReadiness)
  return { success: result.success !== false, message: result.message }
}

export async function uploadVendorLogo(
  file: { uri: string; fileName: string; mimeType?: string },
  auth?: WpAuth,
): Promise<{ logoUrl: string; logoAttachmentId: number }> {
  if (!isLiveApiEnabled()) {
    return { logoUrl: file.uri, logoAttachmentId: 1 }
  }
  const uploaded = await uploadProfileImage("/vendor/profile/logo", file, auth).finally(invalidateVendorReadiness)
  return { logoUrl: uploaded.url, logoAttachmentId: uploaded.attachmentId }
}

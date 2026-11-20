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
  publicSlug: string
  publicUrl: string
}

export type WpVendorProfile = {
  profile_id?: number
  business_name?: string
  description?: string
  website?: string
  instagram?: string
  contact_email?: string
  contact_phone?: string
  city?: string
  state?: string
  public_slug?: string
  public_url?: string
}

export function mockVendorProfileEdit(): VendorProfileEdit {
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
    publicSlug: "demo-vendor-co",
    publicUrl: "https://thelnkup.com/vendors/?vendor=demo-vendor-co",
  }
}

export function mapVendorProfile(data: WpVendorProfile, publicUrlFallback = ""): VendorProfileEdit {
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
    publicSlug,
    publicUrl: data.public_url || publicUrlFallback,
  }
}

export function toWpVendorProfilePatch(
  patch: Partial<Omit<VendorProfileEdit, "profileId">>,
): Record<string, string> {
  const body: Record<string, string> = {}
  if (patch.businessName !== undefined) body.business_name = patch.businessName
  if (patch.description !== undefined) body.description = patch.description
  if (patch.website !== undefined) body.website = patch.website
  if (patch.instagram !== undefined) body.instagram = patch.instagram
  if (patch.contactEmail !== undefined) body.contact_email = patch.contactEmail
  if (patch.contactPhone !== undefined) body.contact_phone = patch.contactPhone
  if (patch.city !== undefined) body.city = patch.city
  if (patch.state !== undefined) body.state = patch.state
  return body
}

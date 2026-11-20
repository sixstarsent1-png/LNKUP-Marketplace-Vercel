import { useCallback } from "react"
import { useLocalSearchParams } from "expo-router"

import { PublicProfileScreen } from "@/components/public-profile-screen"
import { getVendorPublicProfileUrl } from "@/lib/api/config"
import { fetchPublicVendorProfile } from "@/lib/api/public-profiles"

export default function VendorStorefrontRoute() {
  const params = useLocalSearchParams<{ vendor?: string }>()
  const slug = Array.isArray(params.vendor) ? params.vendor[0] : params.vendor
  const load = useCallback((value: string) => fetchPublicVendorProfile(value), [])

  return (
    <PublicProfileScreen
      title="Vendor"
      slug={slug}
      websiteUrl={slug ? getVendorPublicProfileUrl(slug) : getVendorPublicProfileUrl("")}
      load={load}
    />
  )
}

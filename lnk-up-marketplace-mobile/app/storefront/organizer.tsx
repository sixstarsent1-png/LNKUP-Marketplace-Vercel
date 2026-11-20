import { useCallback } from "react"
import { useLocalSearchParams } from "expo-router"

import { PublicProfileScreen } from "@/components/public-profile-screen"
import { getOrganizerPublicProfileUrl } from "@/lib/api/config"
import { fetchPublicOrganizerProfile } from "@/lib/api/public-profiles"

export default function OrganizerStorefrontRoute() {
  const params = useLocalSearchParams<{ organizer?: string }>()
  const slug = Array.isArray(params.organizer) ? params.organizer[0] : params.organizer
  const load = useCallback((value: string) => fetchPublicOrganizerProfile(value), [])

  return (
    <PublicProfileScreen
      title="Organizer"
      slug={slug}
      websiteUrl={slug ? getOrganizerPublicProfileUrl(slug) : getOrganizerPublicProfileUrl("")}
      load={load}
    />
  )
}

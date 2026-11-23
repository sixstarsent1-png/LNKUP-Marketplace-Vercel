"use client"

import { useCallback, useEffect, useRef, useState } from "react"
import { isLiveApiEnabled } from "@/lib/api/config"
import type { OrganizerVendorProfilePreview } from "@/lib/api/organizer-vendor-profile"
import type { OrganizerVendorNoteSummary, OrganizerVendorTimelineItem } from "@/lib/api/organizer-crm"
import { mapWpBadge } from "@/lib/api/vendor-reputation"
import { vendors } from "@/lib/data"

function buildMockProfile(profileId: string, applicationId?: string): OrganizerVendorProfilePreview | null {
  const vendor = vendors.find((v) => v.id === profileId)
  if (!vendor) {
    return {
      id: profileId,
      businessName: "Vendor profile",
      description: "Profile details are available in the LNKup web dashboard.",
    }
  }
  return {
    id: profileId,
    businessName: vendor.name,
    category: vendor.category,
    description: `${vendor.name} brings a polished booth experience and reliable event ops.`,
    citiesServed: [vendor.city],
    statesServed: [vendor.state],
    trustBadges: vendor.verified ? ["verified"] : [],
    completedEvents: vendor.completedEvents,
    rating: vendor.rating,
    photos: ["/placeholder.svg", "/placeholder.svg"],
    contactEmail: "vendor@example.com",
    contactPhone: "(555) 010-2000",
    website: "https://example.com",
    documents: [
      { type: "Insurance", status: vendor.insured ? "approved" : "pending" },
      { type: "Health permit", status: vendor.documentsVerified ? "approved" : "missing" },
    ],
    setupNeeds: {
      setupType: "10x10 tent",
      powerNeeds: "20A",
      waterNeeds: "None",
      tentSize: "10x10",
      spaceNeeds: "One booth",
    },
    pastEvents: [
      {
        eventTitle: "Summer Market Demo",
        status: "completed",
        date: "2025-06-12",
      },
    ],
    upcomingEvents: applicationId
      ? [{ eventTitle: "Upcoming booking", status: "approved", date: "2026-08-01" }]
      : undefined,
    profileStrengthScore: vendor.documentsVerified ? 82 : 54,
    profileStrengthTier: vendor.documentsVerified ? "Strong" : "Building",
    wordpressCrmUrl: undefined,
    reputation: {
      tier: vendor.reviews > 0 ? "trusted" : "new",
      tierLabel: vendor.reviews > 0 ? "Trusted vendor" : "New vendor",
      score: vendor.reviews > 0 ? 78 : 12,
      rating: vendor.rating,
      reviewCount: vendor.reviews,
      completedEvents: vendor.completedEvents,
      badges: [
        mapWpBadge("verified", vendor.verified),
        mapWpBadge("documents_ready", vendor.documentsVerified),
      ],
    },
    applicationMessage: applicationId ? "Excited to join your event lineup." : undefined,
    readinessBlockers: applicationId && !vendor.documentsVerified ? ["Missing health permit"] : undefined,
  }
}

export function useOrganizerVendorProfile(
  profileId: string | null,
  applicationId?: string,
  enabled = true,
) {
  const live = isLiveApiEnabled()
  const [profile, setProfile] = useState<OrganizerVendorProfilePreview | null>(null)
  const [timeline, setTimeline] = useState<OrganizerVendorTimelineItem[]>([])
  const [notes, setNotes] = useState<OrganizerVendorNoteSummary[]>([])
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const requestRef = useRef<AbortController | null>(null)

  const fetchProfile = useCallback(async () => {
    if (!profileId || !enabled) return

    if (!live) {
      setProfile(buildMockProfile(profileId, applicationId))
      setTimeline([])
      setNotes([])
      setLoading(false)
      setError(null)
      return
    }

    // A newer vendor selection cancels this one so a slow response can't fill the wrong drawer.
    requestRef.current?.abort()
    const controller = new AbortController()
    requestRef.current = controller
    const isCurrent = () => requestRef.current === controller

    setLoading(true)
    setError(null)
    setProfile(null)
    setTimeline([])
    setNotes([])

    try {
      const params = applicationId ? `?applicationId=${encodeURIComponent(applicationId)}` : ""
      const response = await fetch(`/api/organizer/vendors/${profileId}${params}`, { signal: controller.signal })
      const data = await response.json()
      if (!isCurrent()) return

      if (response.status === 404) {
        setProfile({
          id: profileId,
          businessName: "Vendor profile",
          description: "We couldn't load this profile. Open the LNKup web dashboard for full details.",
        })
        setTimeline([])
        setNotes([])
        return
      }
      if (!response.ok) {
        throw new Error(data.message || "We couldn't load this vendor profile.")
      }

      const payload = data as {
        profile?: OrganizerVendorProfilePreview
        timeline?: OrganizerVendorTimelineItem[]
        notes?: OrganizerVendorNoteSummary[]
      }
      setProfile((payload.profile ?? data) as OrganizerVendorProfilePreview)
      setTimeline(payload.timeline ?? [])
      setNotes(payload.notes ?? [])
    } catch (err) {
      if (!isCurrent()) return
      console.error("[useOrganizerVendorProfile]", err)
      setError(err instanceof Error ? err.message : "We couldn't load this vendor profile.")
      setProfile(null)
      setTimeline([])
      setNotes([])
    } finally {
      if (isCurrent()) {
        requestRef.current = null
        setLoading(false)
      }
    }
  }, [profileId, applicationId, enabled, live])

  useEffect(() => {
    if (!profileId || !enabled) {
      requestRef.current?.abort()
      requestRef.current = null
      setProfile(null)
      setTimeline([])
      setNotes([])
      return
    }
    fetchProfile()
  }, [profileId, enabled, fetchProfile])

  useEffect(() => () => requestRef.current?.abort(), [])

  return { profile, timeline, notes, loading, error, retry: fetchProfile, isLive: live }
}

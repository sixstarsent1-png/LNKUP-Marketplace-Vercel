import { NextResponse } from "next/server"
import { buildOrganizerQueueBucketsFromRequests } from "@/lib/api/organizer-crm"
import { isLiveApiEnabled } from "@/lib/api/config"
import { mapOrganizerRequestList } from "@/lib/api/organizer-requests"
import { mapOrganizerReputationPreview } from "@/lib/api/vendor-reputation"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"

const MAX_REPUTATION_FETCH = 10

async function enrichWithReputation(
  items: ReturnType<typeof mapOrganizerRequestList>,
  auth: ReturnType<typeof toWpAuth>,
) {
  const profileIds = [
    ...new Set(items.map((i) => i.vendorProfileId).filter(Boolean) as string[]),
  ].slice(0, MAX_REPUTATION_FETCH)

  const previews = new Map<string, ReturnType<typeof mapOrganizerReputationPreview>>()
  await Promise.all(
    profileIds.map(async (id) => {
      try {
        const crm = await wpFetchJson<Record<string, unknown>>(`/crm/vendors/${id}`, { auth })
        previews.set(id, mapOrganizerReputationPreview(crm))
      } catch {
        // omit preview when unavailable
      }
    }),
  )

  return items.map((item) => ({
    ...item,
    reputationPreview: item.vendorProfileId
      ? previews.get(item.vendorProfileId)
      : undefined,
  }))
}

export async function GET() {
  try {
    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to view vendor requests.")
    }

    const auth = toWpAuth(session)
    const data = await wpFetchJson<{ items?: unknown[]; total?: number }>("/applications/organizer", { auth })
    let items = mapOrganizerRequestList(data)

    if (isLiveApiEnabled()) {
      items = await enrichWithReputation(items, auth)
    }

    return NextResponse.json({
      code: "ok",
      items,
      queueBuckets: buildOrganizerQueueBucketsFromRequests(items),
      total: data.total ?? items.length,
    })
  } catch (error) {
    return toErrorResponse(error, {
      context: "organizer/requests",
      fallbackMessage: "We couldn't load vendor requests. Please try again.",
      fallbackCode: "organizer_requests_failed",
      forbiddenMessage: "This section is for organizers.",
    })
  }
}

import { isLiveApiEnabled } from "@/lib/api/config"
import type { WpAuth } from "@/lib/api/wp-client"
import { wpFetchJson } from "@/lib/api/wp-client"
import { MOCK_VENDOR_REVIEWS } from "@/lib/data/fixtures"
import { formatShortDate } from "@/lib/format/date"

export type VendorReviewItem = {
  id: string
  overallRating: number
  publicText?: string
  eventTitle?: string
  organizerName?: string
  createdAt?: string
}

export type VendorReviewsSnapshot = {
  score: number
  label?: string
  avgRating: number
  reviewCount: number
  tips: string[]
  reviews: VendorReviewItem[]
}

type WpReputationDashboard = {
  reputation?: {
    score?: number
    label?: string
    avg_rating?: number
    review_count?: number
  }
  tips?: string[] | Array<{ label?: string; text?: string }>
  reviews?: Array<{
    id?: number
    overall_rating?: number
    public_text?: string
    event_title?: string
    organizer_name?: string
    created_at?: string
  }>
}

const formatDate = formatShortDate

export async function fetchVendorReviews(auth?: WpAuth): Promise<VendorReviewsSnapshot> {
  if (!isLiveApiEnabled()) {
    return {
      ...MOCK_VENDOR_REVIEWS,
      reviews: MOCK_VENDOR_REVIEWS.reviews.map((r) => ({ ...r })),
    }
  }

  const data = await wpFetchJson<WpReputationDashboard>("/reputation/vendor-dashboard", { auth })
  const rep = data.reputation ?? {}
  const tips = (data.tips ?? [])
    .map((t) => (typeof t === "string" ? t : t.label || t.text || ""))
    .filter(Boolean)
    .slice(0, 5)

  const reviews: VendorReviewItem[] = (data.reviews ?? []).map((r, index) => ({
    id: r.id ? String(r.id) : `review-${index}`,
    overallRating: Number(r.overall_rating ?? 0),
    publicText: r.public_text,
    eventTitle: r.event_title,
    organizerName: r.organizer_name,
    createdAt: formatDate(r.created_at),
  }))

  return {
    score: Number(rep.score ?? 0),
    label: rep.label,
    avgRating: Number(rep.avg_rating ?? 0),
    reviewCount: Number(rep.review_count ?? reviews.length),
    tips,
    reviews,
  }
}

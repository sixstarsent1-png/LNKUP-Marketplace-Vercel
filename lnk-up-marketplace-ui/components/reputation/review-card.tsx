"use client"

import { useState } from "react"
import { Star } from "lucide-react"
import type { OrganizerReview } from "@/lib/api/vendor-reputation"
import { trackLnkupEvent } from "@/lib/analytics/track-event"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"

const CATEGORY_LABELS: Record<string, string> = {
  professionalism: "Professionalism",
  setupReadiness: "Setup readiness",
  productQuality: "Product quality",
  communication: "Communication",
  cleanliness: "Cleanliness",
  arrivalTime: "Arrival time",
  customerEngagement: "Customer engagement",
}

export function ReviewCard({ review, canRespond = false }: { review: OrganizerReview; canRespond?: boolean }) {
  const categories = review.categories
    ? Object.entries(review.categories).filter(([, v]) => typeof v === "number")
    : []
  const [response, setResponse] = useState(review.vendorResponse ?? "")
  const [draft, setDraft] = useState(review.vendorResponse ?? "")
  const [editing, setEditing] = useState(false)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function handleSaveResponse() {
    setSaving(true)
    setError(null)
    try {
      const res = await fetch(`/api/vendor/reviews/${encodeURIComponent(review.id)}/response`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ text: draft }),
      })
      const json = (await res.json().catch(() => ({}))) as { message?: string }
      if (!res.ok) {
        setError(json.message ?? "We couldn't save your response.")
        return
      }
      setResponse(draft.trim())
      setEditing(false)
    } catch {
      setError("We couldn't save your response. Check your connection and try again.")
    } finally {
      setSaving(false)
    }
  }

  return (
    <Card
      className="rounded-2xl"
      data-testid="vendor-review-card"
      onClick={() =>
        trackLnkupEvent({
          event: "vendor_review_card_viewed",
          source: "vendor_reviews",
          requestId: review.id,
        })
      }
    >
      <CardContent className="flex flex-col gap-2 pt-6">
        <div className="flex items-center justify-between gap-2">
          <span className="font-medium">{review.eventName ?? "Event"}</span>
          {typeof review.rating === "number" && (
            <span className="inline-flex items-center gap-1 text-sm font-medium">
              <Star className="size-4 fill-accent text-accent" />
              {review.rating}
            </span>
          )}
        </div>
        <span className="text-xs text-muted-foreground">
          {[review.organizerName, review.eventDate, review.createdAt].filter(Boolean).join(" · ")}
        </span>
        {review.verifiedParticipation && (
          <span className="w-fit rounded-full border px-2 py-0.5 text-[10px] text-muted-foreground">Verified participation</span>
        )}
        {review.text && (
          <p className="text-sm text-muted-foreground">&ldquo;{review.text}&rdquo;</p>
        )}
        {review.wouldBookAgain && (
          <p className="text-xs font-medium text-accent">Would book again</p>
        )}
        {categories.length > 0 && (
          <div className="flex flex-wrap gap-2 pt-1">
            {categories.map(([key, value]) => (
              <span
                key={key}
                className="rounded-full bg-muted px-2 py-0.5 text-[10px] text-muted-foreground"
              >
                {CATEGORY_LABELS[key] ?? key}: {value}/5
              </span>
            ))}
          </div>
        )}
        {response && !editing && (
          <blockquote className="mt-1 border-l-2 border-accent pl-3 text-sm" data-testid="vendor-review-response">
            <span className="block text-xs font-medium">Your response</span>
            {response}
          </blockquote>
        )}
        {canRespond && !editing && (
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="w-fit"
            onClick={(e) => {
              e.stopPropagation()
              setEditing(true)
            }}
          >
            {response ? "Edit response" : "Respond publicly"}
          </Button>
        )}
        {canRespond && editing && (
          <div className="flex flex-col gap-2" onClick={(e) => e.stopPropagation()}>
            <label htmlFor={`response-${review.id}`} className="text-xs font-medium">
              Public response (does not change the review)
            </label>
            <textarea
              id={`response-${review.id}`}
              className="min-h-20 rounded-xl border bg-background p-2 text-sm"
              maxLength={2000}
              value={draft}
              onChange={(e) => setDraft(e.target.value)}
            />
            {error && (
              <p role="alert" className="text-xs text-destructive">
                {error}
              </p>
            )}
            <div className="flex gap-2">
              <Button type="button" size="sm" disabled={saving || draft.trim().length < 2} onClick={() => void handleSaveResponse()}>
                {saving ? "Saving…" : "Save response"}
              </Button>
              <Button type="button" size="sm" variant="ghost" disabled={saving} onClick={() => setEditing(false)}>
                Cancel
              </Button>
            </div>
          </div>
        )}
      </CardContent>
    </Card>
  )
}

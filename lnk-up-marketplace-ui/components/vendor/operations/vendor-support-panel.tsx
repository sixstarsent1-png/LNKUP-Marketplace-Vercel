"use client"

import { useState } from "react"
import Link from "next/link"
import { LifeBuoy } from "lucide-react"
import {
  SUPPORT_TOPIC_LABELS,
  type VendorSupportRequest,
  type VendorSupportSubmitResult,
  type VendorSupportTopic,
} from "@/lib/api/vendor-support"
import { trackLnkupEvent } from "@/lib/analytics/track-event"
import { Button } from "@/components/ui/button"
import { Textarea } from "@/components/ui/textarea"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog"

const TOPICS: VendorSupportTopic[] = [
  "load_in",
  "parking",
  "booth",
  "documents",
  "payment",
  "general",
]

export function VendorSupportPanel({
  requestId,
  items,
  loading,
  submitting,
  onSubmit,
  wordpressUrl,
}: {
  requestId: string
  items: VendorSupportRequest[]
  loading?: boolean
  submitting?: boolean
  onSubmit: (input: { topic: VendorSupportTopic; message: string }) => Promise<VendorSupportSubmitResult | null>
  wordpressUrl?: string
}) {
  const [open, setOpen] = useState(false)
  const [topic, setTopic] = useState<VendorSupportTopic>("general")
  const [message, setMessage] = useState("")
  const [error, setError] = useState<string | null>(null)
  const [handoff, setHandoff] = useState<VendorSupportSubmitResult | null>(null)

  const handleSubmit = async () => {
    setError(null)
    if (!message.trim()) {
      setError("Please enter a message.")
      return
    }

    trackLnkupEvent({
      event: "vendor_support_submitted",
      source: "vendor_pass",
      requestId,
      metadata: { topic },
    })

    try {
      const result = await onSubmit({ topic, message: message.trim() })
      if (result?.status === "handoff_required") {
        setHandoff(result)
        return
      }
      setMessage("")
      setOpen(false)
    } catch {
      setError("We couldn't submit your request. Please try again.")
    }
  }

  return (
    <section id="support" className="flex flex-col gap-3" data-testid="vendor-support-panel">
      <div className="flex items-center justify-between gap-2">
        <h3 className="font-heading text-lg font-semibold">Event support</h3>
        <Dialog
          open={open}
          onOpenChange={(next) => {
            setOpen(next)
            if (next) {
              trackLnkupEvent({
                event: "vendor_support_started",
                source: "vendor_pass",
                requestId,
              })
            }
          }}
        >
          <DialogTrigger
            render={
              <Button size="sm" variant="outline" data-testid="vendor-support-open">
                <LifeBuoy data-icon="inline-start" />
                Request support
              </Button>
            }
          />
          <DialogContent>
            <DialogHeader>
              <DialogTitle>Request event support</DialogTitle>
              <DialogDescription>
                Tell the organizer what you need help with. Avoid sharing sensitive credentials.
              </DialogDescription>
            </DialogHeader>

            <div className="flex flex-col gap-3">
              <Select value={topic} onValueChange={(v) => setTopic(v as VendorSupportTopic)}>
                <SelectTrigger data-testid="vendor-support-topic">
                  <SelectValue placeholder="Topic" />
                </SelectTrigger>
                <SelectContent>
                  {TOPICS.map((t) => (
                    <SelectItem key={t} value={t}>
                      {SUPPORT_TOPIC_LABELS[t]}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>

              <Textarea
                value={message}
                onChange={(e) => setMessage(e.target.value)}
                placeholder="Describe what you need help with..."
                rows={4}
                data-testid="vendor-support-message"
              />

              {error && <p className="text-sm text-destructive">{error}</p>}

              {handoff?.status === "handoff_required" && (
                <div className="rounded-xl border border-dashed p-3 text-sm text-muted-foreground">
                  <p>{handoff.message}</p>
                  {handoff.wordpressUrl && (
                    <Button
                      className="mt-2"
                      size="sm"
                      nativeButton={false}
                      render={
                        <Link
                          href={handoff.wordpressUrl}
                          target="_blank"
                          data-testid="vendor-support-handoff"
                        />
                      }
                      onClick={() =>
                        trackLnkupEvent({
                          event: "wordpress_operations_handoff_clicked",
                          source: "vendor_support",
                          requestId,
                        })
                      }
                    >
                      Open in LNKup
                    </Button>
                  )}
                </div>
              )}

              <Button
                onClick={handleSubmit}
                disabled={submitting}
                data-testid="vendor-support-submit"
              >
                {submitting ? "Submitting..." : "Submit request"}
              </Button>
            </div>
          </DialogContent>
        </Dialog>
      </div>

      {loading ? (
        <p className="text-sm text-muted-foreground">Loading support history...</p>
      ) : items.length > 0 ? (
        <ul className="flex flex-col gap-2">
          {items.map((item) => (
            <li key={item.id} className="rounded-xl border p-3 text-sm">
              <p className="font-medium">{SUPPORT_TOPIC_LABELS[item.topic]}</p>
              <p className="text-muted-foreground">{item.message}</p>
              {item.response && (
                <p className="mt-1 text-xs text-muted-foreground">Response · {item.response}</p>
              )}
            </li>
          ))}
        </ul>
      ) : (
        <p className="text-sm text-muted-foreground">
          No support requests yet. Use the button above if you need help on event day.
        </p>
      )}

      {wordpressUrl && (
        <Button
          variant="ghost"
          size="sm"
          className="w-fit"
          nativeButton={false}
          render={<Link href={wordpressUrl} target="_blank" />}
          onClick={() =>
            trackLnkupEvent({
              event: "wordpress_operations_handoff_clicked",
              source: "vendor_pass_footer",
              requestId,
            })
          }
        >
          Open operations in LNKup
        </Button>
      )}
    </section>
  )
}

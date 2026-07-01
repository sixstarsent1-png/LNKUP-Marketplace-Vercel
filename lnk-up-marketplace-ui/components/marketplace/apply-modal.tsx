"use client"

import { useState } from "react"
import { CheckCircle2, PartyPopper } from "lucide-react"
import type { EventItem } from "@/lib/data"
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog"
import { Button } from "@/components/ui/button"
import { Textarea } from "@/components/ui/textarea"
import { Checkbox } from "@/components/ui/checkbox"
import { Label } from "@/components/ui/label"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"

export function ApplyModal({
  event,
  children,
}: {
  event: EventItem
  children: React.ReactNode
}) {
  const [open, setOpen] = useState(false)
  const [agreed, setAgreed] = useState(false)
  const [submitted, setSubmitted] = useState(false)
  const [category, setCategory] = useState(event.categoriesNeeded[0])

  function reset() {
    setSubmitted(false)
    setAgreed(false)
    setCategory(event.categoriesNeeded[0])
  }

  return (
    <Dialog
      open={open}
      onOpenChange={(o) => {
        setOpen(o)
        if (!o) setTimeout(reset, 200)
      }}
    >
      <DialogTrigger render={children as React.ReactElement} />
      <DialogContent className="sm:max-w-md">
        {submitted ? (
          <div className="flex flex-col items-center gap-3 py-4 text-center">
            <span className="flex size-14 items-center justify-center rounded-full bg-accent">
              <PartyPopper className="size-7 text-accent-foreground" />
            </span>
            <DialogHeader className="items-center">
              <DialogTitle>Application submitted</DialogTitle>
              <DialogDescription className="text-center">
                Application submitted. You&apos;ll be notified when the organizer reviews it.
              </DialogDescription>
            </DialogHeader>
            <DialogClose render={<Button className="w-full" />}>Got it</DialogClose>
          </div>
        ) : (
          <>
            <DialogHeader>
              <DialogTitle>Apply with your LNKup profile</DialogTitle>
              <DialogDescription>
                Your profile does most of the work. Add a short note and submit
                your application for free.
              </DialogDescription>
            </DialogHeader>

            <div className="flex flex-col gap-4">
              <div className="flex flex-col gap-2">
                <Label>Category</Label>
                <Select value={category} onValueChange={setCategory}>
                  <SelectTrigger className="w-full">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {event.categoriesNeeded.map((c) => (
                      <SelectItem key={c} value={c}>
                        {c}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              <div className="flex flex-col gap-2">
                <Label htmlFor="apply-message">Short message</Label>
                <Textarea
                  id="apply-message"
                  rows={3}
                  placeholder="Tell the organizer what you'll bring to the event…"
                />
              </div>

              <Button variant="outline" size="sm" className="w-full">
                Attach documents (optional)
              </Button>

              <Label className="flex items-start gap-2 rounded-xl bg-muted p-3 text-sm font-normal">
                <Checkbox
                  checked={agreed}
                  onCheckedChange={(v) => setAgreed(v === true)}
                  className="mt-0.5"
                />
                <span className="text-muted-foreground">
                  I understand payment is only required if I am accepted.
                </span>
              </Label>

              <Button
                className="w-full"
                disabled={!agreed}
                onClick={() => setSubmitted(true)}
              >
                <CheckCircle2 data-icon="inline-start" />
                Submit application
              </Button>
            </div>
          </>
        )}
      </DialogContent>
    </Dialog>
  )
}

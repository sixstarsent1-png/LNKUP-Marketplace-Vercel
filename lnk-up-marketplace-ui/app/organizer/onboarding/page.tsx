"use client"

import { useEffect, useState } from "react"
import Link from "next/link"
import { useRouter } from "next/navigation"
import { ArrowRight, Loader2 } from "lucide-react"
import { vendorCategories } from "@/lib/data"
import { getOrganizerDashboardUrl } from "@/lib/api/config"
import {
  ONBOARDING_STEP_ORDER,
  type OrganizerOnboardingStep,
} from "@/lib/api/organizer-onboarding"
import { useOrganizerOnboarding } from "@/lib/hooks/use-organizer-onboarding"
import { trackLnkupEvent } from "@/lib/analytics/track-event"
import { PageHeader } from "@/components/marketplace/page-header"
import { PanelContent } from "@/components/marketplace/states"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Progress } from "@/components/ui/progress"

const STEP_LABELS: Record<OrganizerOnboardingStep, string> = {
  organizer_basics: "Organizer basics",
  event_type: "Event type",
  event_details: "First event details",
  vendor_categories: "Vendor categories",
  fees_spots_deadline: "Fee, spots & deadline",
  documents: "Required documents",
  publish_or_handoff: "Publish or continue on LNKup web",
  complete: "Complete",
}

export default function OrganizerOnboardingPage() {
  const router = useRouter()
  const { state, loading, error, saving, advance, reload } = useOrganizerOnboarding()
  const [orgName, setOrgName] = useState("")
  const [eventType, setEventType] = useState("")
  const [city, setCity] = useState("")
  const [stateCode, setStateCode] = useState("")
  const [categories, setCategories] = useState<string[]>([])
  const [vendorFee, setVendorFee] = useState("200")
  const [spots, setSpots] = useState("10")
  const [deadline, setDeadline] = useState("")

  useEffect(() => {
    trackLnkupEvent({ event: "organizer_onboarding_started", source: "organizer_onboarding" })
  }, [])

  const stepIndex = ONBOARDING_STEP_ORDER.indexOf(
    state.currentStep === "complete" ? "publish_or_handoff" : state.currentStep,
  )
  const progress = state.launchReadiness || Math.round(((stepIndex + 1) / ONBOARDING_STEP_ORDER.length) * 100)
  const wpUrl = getOrganizerDashboardUrl()

  async function handleNext(skip = false) {
    const nextState = await advance({
      skip,
      draft: {
        organizationName: orgName,
        eventType,
        city,
        state: stateCode,
        categories,
        vendorFee: Number(vendorFee) || undefined,
        spotsTotal: Number(spots) || undefined,
        applicationDeadline: deadline,
      },
    })
    trackLnkupEvent({
      event: "organizer_onboarding_step_completed",
      source: "organizer_onboarding",
      metadata: { step: state.currentStep, skipped: skip },
    })
    const advancedStep = nextState?.currentStep ?? state.currentStep
    if (advancedStep === "publish_or_handoff" || advancedStep === "complete") {
      if (!skip) {
        router.push("/organizer/launch")
      }
    }
  }

  function toggleCategory(cat: string) {
    setCategories((prev) => (prev.includes(cat) ? prev.filter((c) => c !== cat) : [...prev, cat]))
  }

  return (
    <div className="flex flex-col gap-6" data-testid="organizer-onboarding">
      <PageHeader
        title="Organizer setup"
        subtitle="A quick guide to launch your first vendor opportunity."
      />
      <Progress value={progress} className="h-2" />
      <p className="text-sm text-muted-foreground">
        Step {Math.min(stepIndex + 1, ONBOARDING_STEP_ORDER.length)} of {ONBOARDING_STEP_ORDER.length}:{" "}
        {STEP_LABELS[state.currentStep] ?? state.currentStep}
      </p>

      <PanelContent state={loading ? "loading" : error ? "error" : "content"} retry={reload}>
        {state.currentStep === "organizer_basics" && (
          <div className="flex flex-col gap-3">
            <Label>Organization or brand name</Label>
            <Input value={orgName} onChange={(e) => setOrgName(e.target.value)} placeholder="CultureWave Events" />
          </div>
        )}
        {state.currentStep === "event_type" && (
          <div className="flex flex-col gap-3">
            <Label>Primary event type</Label>
            <Input value={eventType} onChange={(e) => setEventType(e.target.value)} placeholder="Festival, Market, Fair…" />
          </div>
        )}
        {state.currentStep === "event_details" && (
          <div className="grid gap-3 sm:grid-cols-2">
            <div className="flex flex-col gap-2">
              <Label>City</Label>
              <Input value={city} onChange={(e) => setCity(e.target.value)} />
            </div>
            <div className="flex flex-col gap-2">
              <Label>State</Label>
              <Input value={stateCode} onChange={(e) => setStateCode(e.target.value)} maxLength={2} />
            </div>
          </div>
        )}
        {state.currentStep === "vendor_categories" && (
          <div className="flex flex-wrap gap-2">
            {vendorCategories.slice(0, 8).map((cat) => (
              <Button
                key={cat}
                type="button"
                size="sm"
                variant={categories.includes(cat) ? "default" : "outline"}
                onClick={() => toggleCategory(cat)}
              >
                {cat}
              </Button>
            ))}
          </div>
        )}
        {state.currentStep === "fees_spots_deadline" && (
          <div className="grid gap-3 sm:grid-cols-3">
            <div className="flex flex-col gap-2">
              <Label>Vendor fee ($)</Label>
              <Input type="number" value={vendorFee} onChange={(e) => setVendorFee(e.target.value)} />
            </div>
            <div className="flex flex-col gap-2">
              <Label>Spots</Label>
              <Input type="number" value={spots} onChange={(e) => setSpots(e.target.value)} />
            </div>
            <div className="flex flex-col gap-2">
              <Label>Application deadline</Label>
              <Input type="date" value={deadline} onChange={(e) => setDeadline(e.target.value)} />
            </div>
          </div>
        )}
        {state.currentStep === "documents" && (
          <p className="text-sm text-muted-foreground">
            Platform default documents will be required. Customize later on LNKup web if needed.
          </p>
        )}
        {state.currentStep === "publish_or_handoff" && (
          <div className="flex flex-col gap-3 rounded-2xl border p-4">
            <p className="text-sm">Ready to launch? Use the guided flow or finish on LNKup web.</p>
            <Button nativeButton={false} render={<Link href="/organizer/launch" />}>
              Launch event in app
              <ArrowRight data-icon="inline-end" />
            </Button>
            {wpUrl && (
              <Button variant="outline" nativeButton={false} render={<a href={`${wpUrl}#create`} target="_blank" rel="noopener noreferrer" />}>
                Finish on LNKup web
              </Button>
            )}
          </div>
        )}

        {state.currentStep !== "publish_or_handoff" && state.currentStep !== "complete" && (
          <div className="mt-6 flex flex-wrap gap-2">
            <Button onClick={() => handleNext(false)} disabled={saving}>
              {saving && <Loader2 className="animate-spin" data-icon="inline-start" />}
              Continue
            </Button>
            <Button variant="ghost" onClick={() => handleNext(true)} disabled={saving}>
              Skip for now
            </Button>
          </div>
        )}
      </PanelContent>
    </div>
  )
}

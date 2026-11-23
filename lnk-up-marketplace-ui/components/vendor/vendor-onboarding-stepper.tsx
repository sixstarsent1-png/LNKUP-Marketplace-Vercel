"use client"

import Link from "next/link"
import { useMemo } from "react"
import { ArrowRight, CheckCircle2 } from "lucide-react"
import {
  ONBOARDING_STEP_META,
  type VendorOnboardingState,
  type VendorOnboardingStep,
} from "@/lib/api/vendor-onboarding"
import { getPublicWpSiteUrl } from "@/lib/api/config"
import { trackLnkupEvent } from "@/lib/analytics/track-event"
import { PageHeader } from "@/components/marketplace/page-header"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"
import { Progress } from "@/components/ui/progress"
import { cn } from "@/lib/utils"

const STEPS: Exclude<VendorOnboardingStep, "complete">[] = [
  "business",
  "categories",
  "service_area",
  "setup",
  "documents",
  "first_search",
]

export function VendorOnboardingStepper({
  state,
  saving,
  onSave,
}: {
  state: VendorOnboardingState
  saving?: boolean
  onSave: (step: VendorOnboardingStep, action: "complete" | "skip") => Promise<void>
}) {
  const current = state.currentStep === "complete" ? "first_search" : state.currentStep
  const stepIndex = STEPS.indexOf(current as Exclude<VendorOnboardingStep, "complete">)
  const progress = state.currentStep === "complete" ? 100 : Math.round(((stepIndex + 1) / STEPS.length) * 100)
  const meta = ONBOARDING_STEP_META[current as keyof typeof ONBOARDING_STEP_META]
  const wpProfile = getPublicWpSiteUrl()

  const completedSet = useMemo(() => new Set(state.completedSteps), [state.completedSteps])

  if (state.currentStep === "complete") {
    return (
      <Card className="rounded-2xl border-dashed" data-testid="onboarding-complete">
        <CardContent className="flex flex-col items-center gap-4 py-10 text-center">
          <CheckCircle2 className="size-12 text-accent" />
          <div>
            <h2 className="font-heading text-xl font-semibold">You&apos;re ready to explore</h2>
            <p className="mt-1 text-sm text-muted-foreground">
              Find event opportunities. Apply for free. Pay only if accepted.
            </p>
          </div>
          <div className="flex flex-wrap justify-center gap-2">
            <Button nativeButton={false} render={<Link href="/vendor/find" />}>
              Find events
              <ArrowRight data-icon="inline-end" />
            </Button>
            <Button variant="outline" nativeButton={false} render={<Link href="/vendor/documents" />}>
              Upload documents
            </Button>
          </div>
        </CardContent>
      </Card>
    )
  }

  return (
    <div className="flex flex-col gap-6" data-testid="vendor-onboarding-stepper">
      <PageHeader
        title="Set up your vendor profile"
        subtitle="One profile. More event opportunities."
      />

      <div className="flex flex-col gap-2">
        <div className="flex items-center justify-between text-sm">
          <span className="text-muted-foreground">
            Step {stepIndex + 1} of {STEPS.length}
          </span>
          <span className="font-medium">{state.profileCompletion}% complete</span>
        </div>
        <Progress value={progress} className="h-2" />
      </div>

      <div className="flex gap-2 overflow-x-auto pb-1">
        {STEPS.map((step, i) => (
          <span
            key={step}
            className={cn(
              "shrink-0 rounded-full px-3 py-1 text-xs font-medium",
              completedSet.has(step)
                ? "bg-accent text-accent-foreground"
                : step === current
                  ? "bg-primary text-primary-foreground"
                  : "bg-muted text-muted-foreground",
            )}
          >
            {i + 1}. {ONBOARDING_STEP_META[step].title.split(" ")[0]}
          </span>
        ))}
      </div>

      <Card className="rounded-2xl">
        <CardContent className="flex flex-col gap-4 py-6">
          <div>
            <h2 className="font-heading text-lg font-semibold">{meta.title}</h2>
            <p className="mt-1 text-sm text-muted-foreground">{meta.description}</p>
          </div>

          {meta.wpNote && current !== "documents" && current !== "first_search" && (
            <p className="rounded-xl bg-muted px-4 py-3 text-sm text-muted-foreground">
              {meta.wpNote}{" "}
              {wpProfile && (
                <a href={`${wpProfile}`} className="font-medium text-foreground underline" target="_blank" rel="noopener noreferrer">
                  Open LNKup profile
                </a>
              )}
            </p>
          )}

          {current === "documents" && (
            <Button
              variant="outline"
              nativeButton={false}
              render={<Link href="/vendor/documents" />}
              onClick={() =>
                trackLnkupEvent({
                  event: "document_upload_clicked",
                  source: "onboarding",
                })
              }
            >
              Go to documents
            </Button>
          )}

          {current === "first_search" && (
            <Button
              nativeButton={false}
              render={<Link href="/vendor/find" />}
            >
              Browse opportunities
            </Button>
          )}

          <div className="flex flex-wrap gap-2 pt-2">
            <Button
              disabled={saving}
              onClick={() => {
                void onSave(current as VendorOnboardingStep, "complete").then(() => {
                  trackLnkupEvent({
                    event: "vendor_onboarding_step_completed",
                    source: "onboarding",
                    metadata: { step: current },
                  })
                })
              }}
            >
              Save and continue
            </Button>
            {state.canSkip && (
              <Button
                variant="ghost"
                disabled={saving}
                onClick={() => void onSave(current as VendorOnboardingStep, "skip")}
              >
                Skip for now
              </Button>
            )}
          </div>
        </CardContent>
      </Card>
    </div>
  )
}

"use client"

import Link from "next/link"
import { ArrowRight, Check, Circle, X } from "lucide-react"
import type { RoleTutorial } from "@/lib/tutorials/tutorial-contract"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Progress } from "@/components/ui/progress"
import { Button } from "@/components/ui/button"
import { cn } from "@/lib/utils"

export function GuidedTutorialChecklist({
  tutorial,
  onStepClick,
  onDismiss,
  testId,
}: {
  tutorial: RoleTutorial
  onStepClick: (stepId: RoleTutorial["steps"][number]["id"]) => void
  onDismiss?: () => void
  testId: string
}) {
  const pct = Math.round((tutorial.completedCount / tutorial.totalCount) * 100)

  return (
    <Card className="rounded-2xl border-primary/20" data-testid={testId}>
      <CardHeader className="gap-3">
        <div className="flex items-start justify-between gap-3">
          <div className="flex flex-col gap-1">
            <CardTitle className="text-base">{tutorial.title}</CardTitle>
            <p className="text-sm text-muted-foreground">{tutorial.subtitle}</p>
          </div>
          <div className="flex items-center gap-2">
            <span className="text-sm font-semibold text-muted-foreground">
              {tutorial.completedCount}/{tutorial.totalCount}
            </span>
            {onDismiss ? (
              <Button
                type="button"
                variant="ghost"
                size="icon-sm"
                aria-label="Dismiss tutorial"
                onClick={onDismiss}
              >
                <X className="size-4" />
              </Button>
            ) : null}
          </div>
        </div>
        <Progress value={pct} className="h-2" />
      </CardHeader>
      <CardContent className="flex flex-col gap-3">
        {tutorial.steps.map((step, index) => (
          <div
            key={step.id}
            className={cn(
              "flex flex-col gap-2 rounded-xl border p-3 sm:flex-row sm:items-center sm:justify-between",
              step.done ? "border-muted bg-muted/30" : "border-border bg-background",
            )}
            data-testid={`${testId}-step-${step.id}`}
          >
            <div className="flex items-start gap-3">
              <span
                className={cn(
                  "mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full",
                  step.done ? "bg-success text-primary-foreground" : "bg-muted text-muted-foreground",
                )}
              >
                {step.done ? <Check className="size-3.5" /> : <Circle className="size-3" />}
              </span>
              <div className="flex min-w-0 flex-col gap-1">
                <p
                  className={cn(
                    "text-sm font-medium",
                    step.done && "text-muted-foreground line-through",
                  )}
                >
                  Step {index + 1}: {step.label}
                </p>
                <p className="text-xs text-muted-foreground">{step.description}</p>
              </div>
            </div>
            {!step.done ? (
              <Button
                size="sm"
                className="min-h-10 shrink-0"
                nativeButton={false}
                render={<Link href={step.href} />}
                onClick={() => onStepClick(step.id)}
                data-testid={`${testId}-cta-${step.id}`}
              >
                {step.ctaLabel}
                <ArrowRight data-icon="inline-end" />
              </Button>
            ) : null}
          </div>
        ))}
      </CardContent>
    </Card>
  )
}

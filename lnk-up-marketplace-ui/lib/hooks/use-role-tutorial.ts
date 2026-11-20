"use client"

import { useCallback, useEffect, useMemo, useRef, useState } from "react"
import type { RoleTutorial, TutorialRole, TutorialStepId } from "@/lib/tutorials/tutorial-contract"
import {
  getTutorialProgress,
  markTutorialViewed,
  setTutorialDismissed,
} from "@/lib/tutorials/tutorial-progress"
import { trackLnkupEvent } from "@/lib/analytics/track-event"

export function useRoleTutorial(role: TutorialRole, tutorial: RoleTutorial, userId?: number | string) {
  const [dismissed, setDismissed] = useState(false)
  const completedRef = useRef<Set<TutorialStepId>>(new Set())
  const viewedRef = useRef(false)

  useEffect(() => {
    const progress = getTutorialProgress(role, userId)
    setDismissed(progress.dismissed)
  }, [role, userId])

  useEffect(() => {
    if (viewedRef.current || dismissed || tutorial.isComplete) return
    viewedRef.current = true
    markTutorialViewed(role, userId)
    trackLnkupEvent({
      event: "tutorial_viewed",
      source: `${role}_home`,
      metadata: {
        role,
        completed_count: tutorial.completedCount,
        total_count: tutorial.totalCount,
      },
    })
  }, [role, userId, dismissed, tutorial.completedCount, tutorial.totalCount, tutorial.isComplete])

  useEffect(() => {
    for (const step of tutorial.steps) {
      if (!step.done || completedRef.current.has(step.id)) continue
      completedRef.current.add(step.id)
      trackLnkupEvent({
        event: "tutorial_step_completed",
        source: `${role}_home`,
        metadata: {
          role,
          step_id: step.id,
        },
      })
    }
  }, [role, tutorial.steps])

  const dismiss = useCallback(() => {
    setTutorialDismissed(role, userId)
    setDismissed(true)
  }, [role, userId])

  const onStepClick = useCallback(
    (stepId: TutorialStepId) => {
      trackLnkupEvent({
        event: "tutorial_step_clicked",
        source: `${role}_home`,
        metadata: {
          role,
          step_id: stepId,
        },
      })
    },
    [role],
  )

  const visible = useMemo(
    () => !dismissed && !tutorial.isComplete,
    [dismissed, tutorial.isComplete],
  )

  return {
    visible,
    dismiss,
    onStepClick,
  }
}

import { useCallback, useEffect, useMemo, useRef, useState } from "react"

import { trackMobileEvent } from "@/lib/analytics/mobile-events"
import type { WpAuth } from "@/lib/api/wp-client"
import type { RoleTutorial, TutorialRole, TutorialStepId } from "@/lib/tutorials/tutorial-contract"
import {
  getTutorialProgress,
  markTutorialPopupSeen,
  markTutorialViewed,
  setTutorialDismissed,
} from "@/lib/tutorials/tutorial-progress"

export function useRoleTutorial(
  role: TutorialRole,
  tutorial: RoleTutorial,
  userId?: number | string,
  auth?: WpAuth,
) {
  const [dismissed, setDismissed] = useState(false)
  const [popupSeen, setPopupSeen] = useState(false)
  const [ready, setReady] = useState(false)
  const completedRef = useRef<Set<TutorialStepId>>(new Set())
  const viewedRef = useRef(false)

  useEffect(() => {
    let active = true
    void getTutorialProgress(role, userId).then((progress) => {
      if (!active) return
      setDismissed(progress.dismissed)
      setPopupSeen(Boolean(progress.popupSeen))
      setReady(true)
    })
    return () => {
      active = false
    }
  }, [role, userId])

  useEffect(() => {
    if (!ready || viewedRef.current || dismissed || tutorial.isComplete) return
    viewedRef.current = true
    void markTutorialViewed(role, userId)
    void trackMobileEvent({
      event: "tutorial_viewed",
      source: `mobile_${role}_home`,
      auth,
      metadata: {
        role,
        completed_count: tutorial.completedCount,
        total_count: tutorial.totalCount,
        surface: "popup",
      },
    })
  }, [
    ready,
    role,
    userId,
    dismissed,
    tutorial.isComplete,
    tutorial.completedCount,
    tutorial.totalCount,
    auth,
  ])

  useEffect(() => {
    for (const step of tutorial.steps) {
      if (!step.done || completedRef.current.has(step.id)) continue
      completedRef.current.add(step.id)
      void trackMobileEvent({
        event: "tutorial_step_completed",
        source: `mobile_${role}_home`,
        auth,
        metadata: {
          role,
          step_id: step.id,
        },
      })
    }
  }, [role, tutorial.steps, auth])

  const dismiss = useCallback(() => {
    void setTutorialDismissed(role, userId)
    setDismissed(true)
  }, [role, userId])

  const markPopupSeen = useCallback(() => {
    void markTutorialPopupSeen(role, userId)
    setPopupSeen(true)
  }, [role, userId])

  const onStepClick = useCallback(
    (stepId: TutorialStepId) => {
      void trackMobileEvent({
        event: "tutorial_step_clicked",
        source: `mobile_${role}_home`,
        auth,
        metadata: {
          role,
          step_id: stepId,
          surface: "popup",
        },
      })
    },
    [role, auth],
  )

  const onPushClick = useCallback(() => {
    void trackMobileEvent({
      event: "tutorial_push_cta_clicked",
      source: `mobile_${role}_home`,
      auth,
      metadata: { role },
    })
  }, [role, auth])

  const visible = useMemo(
    () => ready && !dismissed && !tutorial.isComplete,
    [ready, dismissed, tutorial.isComplete],
  )

  const showPopup = useMemo(() => visible && !popupSeen, [visible, popupSeen])

  return {
    visible,
    showPopup,
    dismiss,
    markPopupSeen,
    onStepClick,
    onPushClick,
  }
}

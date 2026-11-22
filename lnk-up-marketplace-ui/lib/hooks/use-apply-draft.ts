"use client"

import { useCallback, useEffect, useRef, useState } from "react"
import { applyRequest, type ApplyAnswerValue } from "@/lib/api/apply-client"
import type { WpApplyDraftSummary } from "@/lib/api/types"

export interface ApplyDraftFields {
  selected_category: string
  application_message: string
  setup_notes: string
}

export type DraftSaveState = "idle" | "saving" | "saved" | "conflict" | "error"

interface UseApplyDraftOptions {
  opportunityId: number | null
  enabled: boolean
  initial: WpApplyDraftSummary | null | undefined
  inviteToken?: string
  /** Called when another device saved a newer version; the caller should load these values. */
  onConflict: (draft: WpApplyDraftSummary) => void
}

const SAVE_DELAY_MS = 900

/**
 * Debounced, revision-aware autosave for one opportunity's application draft.
 * Saves are skipped entirely when drafts are disabled or nothing changed.
 */
export function useApplyDraft({ opportunityId, enabled, initial, inviteToken, onConflict }: UseApplyDraftOptions) {
  const [state, setState] = useState<DraftSaveState>("idle")
  const revision = useRef<number | null>(initial?.revision ?? null)
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null)
  const pending = useRef<{ fields: ApplyDraftFields; answers: Record<string, ApplyAnswerValue> } | null>(null)
  const onConflictRef = useRef(onConflict)
  onConflictRef.current = onConflict

  useEffect(() => {
    revision.current = initial?.revision ?? null
  }, [initial?.id, initial?.revision])

  const flush = useCallback(async (): Promise<"ok" | "conflict" | "error"> => {
    if (timer.current) {
      clearTimeout(timer.current)
      timer.current = null
    }
    const payload = pending.current
    if (!enabled || !opportunityId || !payload) return "ok"
    pending.current = null
    setState("saving")
    const result = await applyRequest<{ draft: WpApplyDraftSummary }>(`/api/applications/drafts/${opportunityId}`, {
      method: "PUT",
      body: {
        step: "review",
        fields: payload.fields,
        answers: payload.answers,
        invite_token: inviteToken ?? "",
        revision: revision.current,
      },
    })
    if (result.ok && result.data?.draft) {
      revision.current = result.data.draft.revision
      setState("saved")
      return "ok"
    }
    const latest = result.errorData?.draft as WpApplyDraftSummary | undefined
    if (result.code === "lnkup_draft_conflict" && latest) {
      revision.current = latest.revision
      setState("conflict")
      onConflictRef.current(latest)
      return "conflict"
    }
    setState("error")
    return "error"
  }, [enabled, opportunityId, inviteToken])

  const schedule = useCallback(
    (fields: ApplyDraftFields, answers: Record<string, ApplyAnswerValue>) => {
      if (!enabled || !opportunityId) return
      pending.current = { fields, answers }
      if (timer.current) clearTimeout(timer.current)
      timer.current = setTimeout(() => void flush(), SAVE_DELAY_MS)
    },
    [enabled, opportunityId, flush],
  )

  useEffect(
    () => () => {
      if (timer.current) clearTimeout(timer.current)
    },
    [],
  )

  return { state, schedule, flush }
}

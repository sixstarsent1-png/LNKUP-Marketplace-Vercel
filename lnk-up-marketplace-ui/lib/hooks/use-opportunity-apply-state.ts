"use client"

import { useCallback, useEffect, useState } from "react"
import { isLiveApiEnabled } from "@/lib/api/config"
import type { WpApplyContext } from "@/lib/api/types"
import {
  normalizeVendorRequestStatus,
  vendorRequestStatusLabel,
  type VendorRequestStatus,
} from "@/lib/api/vendor-requests"
import { useAuth } from "@/lib/hooks/use-auth"

export type OpportunityApplyState =
  | { type: "idle" }
  | { type: "loading" }
  | { type: "logged_out" }
  | { type: "eligible" }
  | { type: "marketplace_closed"; message: string }
  | { type: "already_applied"; status: VendorRequestStatus; applicationId?: number }
  | { type: "blocked"; reason: string; code?: string }
  | { type: "error"; message: string }

function resolveApplyState(context: WpApplyContext, errorCode?: string, errorMessage?: string): OpportunityApplyState {
  if (errorCode === "marketplace_closed") {
    return { type: "marketplace_closed", message: errorMessage ?? "Applications are not open yet." }
  }

  const eligibility = context.eligibility
  if (eligibility?.block_reason === "duplicate" || eligibility?.existing_application_id) {
    return {
      type: "already_applied",
      status: normalizeVendorRequestStatus(eligibility.existing_application_status),
      applicationId: eligibility.existing_application_id,
    }
  }

  if (eligibility?.can_apply === false) {
    const reason = context.button_state?.reason ?? eligibility.block_reason ?? "You cannot apply to this opportunity."
    const code = eligibility.block_reason
    if (code === "closed" || code === "full" || code === "deadline") {
      return { type: "blocked", reason, code }
    }
    return { type: "blocked", reason, code }
  }

  if (eligibility?.can_apply) {
    return { type: "eligible" }
  }

  return { type: "eligible" }
}

export function useOpportunityApplyState(opportunityId?: number) {
  const live = isLiveApiEnabled()
  const { isAuthenticated, loading: authLoading } = useAuth()
  const [state, setState] = useState<OpportunityApplyState>({ type: "idle" })

  const refresh = useCallback(async () => {
    if (!live || !opportunityId) {
      setState({ type: "idle" })
      return
    }
    if (authLoading) {
      setState({ type: "loading" })
      return
    }
    if (!isAuthenticated) {
      setState({ type: "logged_out" })
      return
    }

    setState({ type: "loading" })
    try {
      const response = await fetch(`/api/applications/apply-context/${opportunityId}`)
      const data = await response.json()
      if (!response.ok) {
        if (data.code === "marketplace_closed") {
          setState({
            type: "marketplace_closed",
            message: data.message ?? "Applications are not open yet.",
          })
          return
        }
        setState({ type: "error", message: data.message ?? "Could not load apply status." })
        return
      }
      setState(resolveApplyState(data as WpApplyContext, data.code, data.message))
    } catch (err) {
      console.error("[useOpportunityApplyState]", err)
      setState({ type: "error", message: "Could not load apply status." })
    }
  }, [live, opportunityId, isAuthenticated, authLoading])

  useEffect(() => {
    refresh()
  }, [refresh])

  const statusLabel =
    state.type === "already_applied" ? vendorRequestStatusLabel(state.status) : undefined

  return { state, refresh, statusLabel, live, isAuthenticated, authLoading }
}

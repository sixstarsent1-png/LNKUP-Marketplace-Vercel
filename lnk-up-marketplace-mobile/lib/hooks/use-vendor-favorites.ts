import { useCallback, useEffect, useRef, useState } from "react"

import { appErrorFromWp } from "@/lib/api/errors"
import { fetchVendorFavorites, setVendorFavorite } from "@/lib/api/vendor-favorites"
import type { WpAuth } from "@/lib/api/wp-client"
import { showToast } from "@/lib/feedback/feedback"

/**
 * Saved-opportunity state shared by Home, Explore, detail and Favorites. Toggles are guarded per
 * opportunity (a second tap while saving is ignored), shown optimistically, then replaced by the
 * server's answer; failures restore the previous state and explain why.
 */
export function useVendorFavorites(auth: WpAuth | undefined, { autoLoad = true } = {}) {
  const [savedIds, setSavedIds] = useState<Set<number>>(new Set())
  const [pendingIds, setPendingIds] = useState<Set<number>>(new Set())
  const inFlight = useRef(new Set<number>())

  const refresh = useCallback(async () => {
    if (!auth) return
    try {
      const result = await fetchVendorFavorites(auth)
      setSavedIds(new Set(result.opportunityIds.filter(Boolean)))
    } catch {
      // Hearts keep their last known state; screens surface their own load errors.
    }
  }, [auth])

  useEffect(() => {
    if (autoLoad) void refresh()
  }, [autoLoad, refresh])

  const setLocal = useCallback((id: number, saved: boolean) => {
    setSavedIds((prev) => {
      const next = new Set(prev)
      if (saved) next.add(id)
      else next.delete(id)
      return next
    })
  }, [])

  const setSaved = useCallback(
    async (id: number, wanted: boolean): Promise<boolean | null> => {
      if (!id || inFlight.current.has(id)) return null
      inFlight.current.add(id)
      setPendingIds(new Set(inFlight.current))
      const previous = savedIds.has(id)
      setLocal(id, wanted)
      try {
        const final = await setVendorFavorite(id, wanted, auth)
        setLocal(id, final)
        return final
      } catch (err) {
        setLocal(id, previous)
        showToast(`Couldn't update saved events. ${appErrorFromWp(err).message}`, "error")
        return null
      } finally {
        inFlight.current.delete(id)
        setPendingIds(new Set(inFlight.current))
      }
    },
    [auth, savedIds, setLocal],
  )

  const toggle = useCallback((id: number) => setSaved(id, !savedIds.has(id)), [savedIds, setSaved])

  return { savedIds, pendingIds, refresh, toggle, setSaved, setSavedIds }
}

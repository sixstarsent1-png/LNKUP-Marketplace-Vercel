"use client"

import { createContext, useContext } from "react"
import { useOrganizerHomeInternal } from "@/lib/hooks/use-organizer-home"

type OrganizerHomeValue = ReturnType<typeof useOrganizerHomeInternal>

const OrganizerHomeContext = createContext<OrganizerHomeValue | null>(null)

export function OrganizerHomeProvider({ children }: { children: React.ReactNode }) {
  const value = useOrganizerHomeInternal(true)
  return <OrganizerHomeContext.Provider value={value}>{children}</OrganizerHomeContext.Provider>
}

export function useOrganizerHomeContext() {
  return useContext(OrganizerHomeContext)
}

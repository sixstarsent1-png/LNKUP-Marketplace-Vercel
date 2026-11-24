import { useEffect, useRef, useState } from "react"
import { Platform } from "react-native"

export type NetworkStatus = "online" | "offline" | "reconnecting"

type NetworkModule = {
  getNetworkStateAsync: () => Promise<{ isConnected?: boolean | null; isInternetReachable?: boolean | null }>
}

function loadNetworkModule(): NetworkModule | null {
  try {
    // Lazy require: binaries without expo-network must keep working over OTA.
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    return require("expo-network") as NetworkModule
  } catch {
    return null
  }
}

async function readOnline(network: NetworkModule | null): Promise<boolean> {
  if (Platform.OS === "web" && typeof navigator !== "undefined" && "onLine" in navigator) {
    return navigator.onLine
  }
  if (!network?.getNetworkStateAsync) return true
  try {
    const state = await network.getNetworkStateAsync()
    return !(state.isConnected === false || state.isInternetReachable === false)
  } catch {
    return true
  }
}

/**
 * Device connectivity for event-day screens. "reconnecting" is reported for one poll after the
 * device comes back online so screens can refresh before re-enabling writes.
 */
export function useNetworkStatus(pollMs = 5000): NetworkStatus {
  const [status, setStatus] = useState<NetworkStatus>("online")
  const wasOffline = useRef(false)

  useEffect(() => {
    const network = loadNetworkModule()
    let active = true

    async function check() {
      const online = await readOnline(network)
      if (!active) return
      if (!online) {
        wasOffline.current = true
        setStatus("offline")
        return
      }
      if (wasOffline.current) {
        wasOffline.current = false
        setStatus("reconnecting")
        return
      }
      setStatus("online")
    }

    void check()
    const id = setInterval(() => void check(), pollMs)
    const onWebChange = () => void check()
    if (Platform.OS === "web" && typeof window !== "undefined") {
      window.addEventListener("online", onWebChange)
      window.addEventListener("offline", onWebChange)
    }
    return () => {
      active = false
      clearInterval(id)
      if (Platform.OS === "web" && typeof window !== "undefined") {
        window.removeEventListener("online", onWebChange)
        window.removeEventListener("offline", onWebChange)
      }
    }
  }, [pollMs])

  return status
}

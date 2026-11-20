import { router, type Href } from "expo-router"

/** Prefer history back; if the stack is empty (common on web), replace to a known screen. */
export function safeBack(fallbackHref: Href = "/(vendor)/find") {
  if (router.canGoBack()) {
    router.back()
    return
  }
  router.replace(fallbackHref)
}

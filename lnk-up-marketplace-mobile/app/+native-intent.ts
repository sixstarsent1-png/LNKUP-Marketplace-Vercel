import { mapSystemPath } from "@/lib/navigation/deep-link-map"
import { attributionFromUrl, savePendingAttribution } from "@/lib/navigation/pending-attribution"

/**
 * Website links open the matching in-app screen when the app is installed. Campaign and referral
 * parameters on the link are remembered for signup.
 */
export function redirectSystemPath({ path }: { path: string; initial: boolean }): string {
  const attribution = attributionFromUrl(path)
  if (Object.keys(attribution).length > 0) void savePendingAttribution(attribution)
  return mapSystemPath(path)
}

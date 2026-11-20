import type { WpAuth } from "@/lib/api/wp-fetch"

export function getOpsWpAuth(): WpAuth | undefined {
  const username = process.env.OPS_WP_USERNAME?.trim()
  const appPassword = process.env.OPS_WP_APP_PASSWORD?.trim()
  if (!username || !appPassword) return undefined
  return { username, appPassword }
}

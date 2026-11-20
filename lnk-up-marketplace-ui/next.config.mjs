import path from "node:path"
import { fileURLToPath } from "node:url"

const __dirname = path.dirname(fileURLToPath(import.meta.url))

function isValidUrl(value) {
  try {
    const parsed = new URL(value)
    return parsed.protocol === "http:" || parsed.protocol === "https:"
  } catch {
    return false
  }
}

function isValidAllowlistHost(host) {
  return /^[a-z0-9.-]+$/.test(host) && !host.startsWith(".") && !host.endsWith(".")
}

function runEnvPreflight() {
  const errors = []
  const appUrl = process.env.NEXT_PUBLIC_APP_URL?.trim()
  const wpAppUrl = process.env.LNKUP_APP_URL?.trim()
  const allowlist = process.env.LNKUP_APP_URL_ALLOWLIST?.trim() ?? ""
  const live = process.env.NEXT_PUBLIC_LNKUP_LIVE_API === "true"

  if (live && !appUrl) {
    errors.push("NEXT_PUBLIC_APP_URL is required when NEXT_PUBLIC_LNKUP_LIVE_API=true")
  }
  if (appUrl && !isValidUrl(appUrl)) {
    errors.push("NEXT_PUBLIC_APP_URL must be an absolute http/https URL")
  }
  if (wpAppUrl && !isValidUrl(wpAppUrl)) {
    errors.push("LNKUP_APP_URL must be an absolute http/https URL")
  }
  if (allowlist) {
    const invalid = allowlist
      .split(",")
      .map((entry) => entry.trim().toLowerCase())
      .filter(Boolean)
      .filter((entry) => !isValidAllowlistHost(entry))
    if (invalid.length > 0) {
      errors.push(`LNKUP_APP_URL_ALLOWLIST has invalid host entries: ${invalid.join(", ")}`)
    }
  }
  if (errors.length > 0) {
    throw new Error(`LNKup config preflight failed:\n- ${errors.join("\n- ")}`)
  }
}

runEnvPreflight()

/** @type {import('next').NextConfig} */
const nextConfig = {
  turbopack: {
    root: __dirname,
  },
  typescript: {
    ignoreBuildErrors: true,
  },
  images: {
    unoptimized: true,
  },
}

export default nextConfig

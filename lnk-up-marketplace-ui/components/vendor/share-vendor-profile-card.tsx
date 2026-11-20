"use client"

import { useCallback, useEffect, useState } from "react"
import { Check, Copy, Loader2, Share2 } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Surface } from "@/components/vendor/surface"
import { getVendorPublicProfileUrl, isLiveApiEnabled } from "@/lib/api/config"
import { parseApiResponse } from "@/lib/api/parse-api-error"

type ProfileSharePayload = {
  public_slug?: string
  public_url?: string
  business_name?: string
}

/**
 * Share / copy the vendor's public WordPress storefront profile.
 */
export function ShareVendorProfileCard() {
  const [url, setUrl] = useState("")
  const [name, setName] = useState("My LNKup profile")
  const [loading, setLoading] = useState(true)
  const [copied, setCopied] = useState(false)

  useEffect(() => {
    let active = true
    async function load() {
      setLoading(true)
      try {
        if (!isLiveApiEnabled()) {
          if (!active) return
          setName("Demo Vendor Co")
          setUrl(getVendorPublicProfileUrl("demo-vendor-co"))
          return
        }
        const response = await fetch("/api/vendor/profile")
        const { data } = await parseApiResponse<ProfileSharePayload>(response)
        if (!active) return
        const slug = data?.public_slug || ""
        setName(data?.business_name || "My LNKup profile")
        setUrl(data?.public_url || (slug ? getVendorPublicProfileUrl(slug) : ""))
      } catch {
        if (active) setUrl("")
      } finally {
        if (active) setLoading(false)
      }
    }
    void load()
    return () => {
      active = false
    }
  }, [])

  const handleCopy = useCallback(async () => {
    if (!url) return
    try {
      await navigator.clipboard.writeText(url)
      setCopied(true)
      setTimeout(() => setCopied(false), 2000)
    } catch {
      /* ignore */
    }
  }, [url])

  const handleShare = useCallback(async () => {
    if (!url) return
    if (typeof navigator !== "undefined" && typeof navigator.share === "function") {
      try {
        await navigator.share({
          title: name,
          text: `Check out ${name} on LNKup`,
          url,
        })
        return
      } catch {
        /* fall through */
      }
    }
    await handleCopy()
  }, [url, name, handleCopy])

  if (loading) {
    return (
      <Surface variant="secondary" className="flex items-center gap-2" data-testid="share-vendor-profile">
        <Loader2 className="size-4 animate-spin text-muted-foreground" />
        <span className="text-sm text-muted-foreground">Loading share link…</span>
      </Surface>
    )
  }

  if (!url) return null

  return (
    <Surface variant="secondary" className="flex flex-col gap-3" data-testid="share-vendor-profile">
      <div>
        <p className="text-xs font-bold uppercase tracking-wider text-muted-foreground">
          Share profile
        </p>
        <p className="text-sm text-muted-foreground">
          Send your public LNKup profile to organizers and partners.
        </p>
        <p className="mt-1 break-all text-xs text-muted-foreground">{url}</p>
      </div>
      <div className="flex flex-wrap gap-2">
        <Button size="sm" onClick={() => void handleShare()} data-testid="vendor-share-profile">
          <Share2 data-icon="inline-start" />
          Share
        </Button>
        <Button
          size="sm"
          variant="outline"
          onClick={() => void handleCopy()}
          data-testid="vendor-copy-profile"
        >
          {copied ? <Check data-icon="inline-start" /> : <Copy data-icon="inline-start" />}
          {copied ? "Copied" : "Copy link"}
        </Button>
      </div>
    </Surface>
  )
}

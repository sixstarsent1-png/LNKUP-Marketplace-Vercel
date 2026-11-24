/**
 * Resolved event media from WordPress (LNKup_Event_Media::to_rest). WordPress picks the image
 * (cover, flyer, tour/organizer art, LNKup art or generated cover); the app only renders it.
 * Older servers only send `featured_image`, which is used as a plain cover.
 */
export type WpEventMedia = {
  source?: string
  display_mode?: string
  presentation?: string
  url?: string
  thumbnail_url?: string
  medium_url?: string
  large_url?: string
  width?: number
  height?: number
  aspect_ratio?: number
  fit?: string
  background?: string
  alt?: string
  uploaded?: boolean
  version?: number
}

export type EventMedia = {
  /** Card-sized image. */
  url: string
  thumbUrl: string
  /** Detail/hero image. */
  largeUrl: string
  fit: "cover" | "contain"
  background?: string
  alt?: string
  source?: string
}

const HEX = /^#[0-9a-f]{6}$/i

export function mapEventMedia(media: WpEventMedia | null | undefined, featuredImage?: string | null): EventMedia | undefined {
  const fallback = typeof featuredImage === "string" ? featuredImage.trim() : ""
  const url = media?.medium_url || media?.url || fallback
  if (!url) return undefined
  return {
    url,
    thumbUrl: media?.thumbnail_url || url,
    largeUrl: media?.large_url || media?.url || url,
    fit: media?.fit === "contain" ? "contain" : "cover",
    background: media?.background && HEX.test(media.background) ? media.background : undefined,
    alt: media?.alt || undefined,
    source: media?.source || undefined,
  }
}

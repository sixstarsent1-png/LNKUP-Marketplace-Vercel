/**
 * Resolved event media from WordPress (LNKup_Event_Media::to_rest). WordPress decides which
 * image represents an event (cover, flyer, tour/organizer art, LNKup art or generated cover);
 * clients only render it. `featured_image` is kept in sync for older code paths.
 */
export type WpEventMedia = {
  source?: string
  display_mode?: string
  presentation?: "photo" | "flyer" | "logo" | "art" | "generated" | string
  url?: string
  thumbnail_url?: string
  medium_url?: string
  large_url?: string
  width?: number
  height?: number
  aspect_ratio?: number
  fit?: "cover" | "contain" | string
  background?: string
  alt?: string
  uploaded?: boolean
  version?: number
}

export type EventMedia = {
  url: string
  thumbUrl: string
  largeUrl: string
  fit: "cover" | "contain"
  background: string
  alt: string
  presentation: string
  source: string
}

const HEX = /^#[0-9a-f]{6}$/i

/** Map the WordPress media object; falls back to a bare featured image URL from older servers. */
export function mapEventMedia(media: WpEventMedia | null | undefined, featuredImage?: string): EventMedia | undefined {
  const url = media?.medium_url || media?.url || featuredImage || ""
  if (!url) return undefined
  return {
    url,
    thumbUrl: media?.thumbnail_url || url,
    largeUrl: media?.large_url || media?.url || url,
    fit: media?.fit === "contain" ? "contain" : "cover",
    background: media?.background && HEX.test(media.background) ? media.background : "#111111",
    alt: media?.alt ?? "",
    presentation: media?.presentation ?? "photo",
    source: media?.source ?? "",
  }
}

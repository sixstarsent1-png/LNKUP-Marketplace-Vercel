import Image from "next/image"

import { cn } from "@/lib/utils"

interface EventArtProps {
  image?: string | null
  name: string
  className?: string
  sizes?: string
}

/** First letters of the first two words, for the poster shown when an event has no art. */
function initialsOf(name: string): string {
  return name
    .trim()
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((word) => word.charAt(0).toUpperCase())
    .join("")
}

/**
 * Event artwork shared by cards: the flyer shown whole over a blurred copy (so its text is never
 * cropped), or an LNKup poster with the event initials when there is no image. Matches the
 * WordPress and Expo cards.
 */
export function EventArt({ image, name, className, sizes = "(max-width: 640px) 100vw, 360px" }: EventArtProps) {
  const hasImage = Boolean(image) && image !== "/placeholder.svg"

  if (!hasImage) {
    return (
      <div
        role="img"
        aria-label={name}
        className={cn(
          "absolute inset-0 grid place-items-center bg-neutral-950",
          "bg-[radial-gradient(120%_90%_at_100%_0%,rgba(196,240,74,0.28)_0%,rgba(196,240,74,0)_55%)]",
          className,
        )}
      >
        <span aria-hidden="true" className="font-heading text-5xl font-bold tracking-tighter text-[#c4f04a]">
          {initialsOf(name)}
        </span>
        <span aria-hidden="true" className="absolute right-3 bottom-2 text-[11px] font-bold tracking-[0.12em] text-white/50">
          LNKUP
        </span>
      </div>
    )
  }

  return (
    <div className={cn("absolute inset-0 overflow-hidden bg-neutral-900", className)}>
      <Image src={image as string} alt="" aria-hidden fill sizes={sizes} className="scale-125 object-cover blur-2xl brightness-60 saturate-125" />
      <Image src={image as string} alt={name} fill sizes={sizes} className="object-contain" />
    </div>
  )
}

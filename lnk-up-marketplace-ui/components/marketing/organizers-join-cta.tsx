"use client"

import Link from "next/link"
import { ArrowRight } from "lucide-react"
import { trackLnkupEvent } from "@/lib/analytics/track-event"
import { Button } from "@/components/ui/button"

function isExternalHref(href: string): boolean {
  return /^https?:\/\//i.test(href)
}

export function OrganizersJoinCta({ href }: { href: string }) {
  const external = isExternalHref(href)
  return (
    <Button
      size="lg"
      nativeButton={false}
      render={
        external ? (
          <a href={href} data-testid="join-organizer-cta" />
        ) : (
          <Link href={href} data-testid="join-organizer-cta" />
        )
      }
      onClick={() => trackLnkupEvent({ event: "organizer_join_clicked", source: "organizers_landing" })}
    >
      Join as an Organizer
      <ArrowRight data-icon="inline-end" />
    </Button>
  )
}

"use client"

import Image from "next/image"
import Link from "next/link"
import { MapPin, Globe, AtSign, Loader2, ExternalLink, Mail, Phone } from "lucide-react"
import type { OrganizerVendorProfilePreview } from "@/lib/api/organizer-vendor-profile"
import type { OrganizerVendorNoteSummary, OrganizerVendorTimelineItem } from "@/lib/api/organizer-crm"
import { ReputationSummaryCard } from "@/components/reputation/reputation-summary-card"
import { ReputationBadge } from "@/components/reputation/reputation-badge"
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetDescription,
} from "@/components/ui/sheet"
import { Badge } from "@/components/ui/badge"
import { Separator } from "@/components/ui/separator"
import { Alert, AlertDescription } from "@/components/ui/alert"
import { Button } from "@/components/ui/button"

function SetupRow({ label, value }: { label: string; value?: string }) {
  if (!value) return null
  return (
    <li className="flex justify-between gap-3 text-sm">
      <span className="text-muted-foreground">{label}</span>
      <span className="text-right font-medium">{value}</span>
    </li>
  )
}

export function OrganizerVendorProfileDrawer({
  open,
  onOpenChange,
  profile,
  timeline = [],
  notes = [],
  loading,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  profile: OrganizerVendorProfilePreview | null
  timeline?: OrganizerVendorTimelineItem[]
  notes?: OrganizerVendorNoteSummary[]
  loading?: boolean
}) {
  const location = [profile?.citiesServed?.[0], profile?.statesServed?.[0]].filter(Boolean).join(", ")
  const reputation = profile?.reputation
  const setup = profile?.setupNeeds
  const hasSetup =
    setup &&
    Boolean(
      setup.setupType ||
        setup.powerNeeds ||
        setup.waterNeeds ||
        setup.tentSize ||
        setup.trailerSize ||
        setup.spaceNeeds ||
        setup.truckSize,
    )
  const gallery = profile?.photos?.filter(Boolean) ?? []

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent
        side="right"
        className="flex max-h-[85dvh] w-full flex-col overflow-hidden sm:max-w-md"
        data-testid="organizer-profile-drawer"
      >
        <SheetHeader className="shrink-0">
          <SheetTitle>{profile?.businessName ?? "Vendor profile"}</SheetTitle>
          <SheetDescription>
            {profile?.category ?? "Review vendor details before approving."}
            {profile?.profileStrengthTier
              ? ` · Profile ${profile.profileStrengthTier}${
                  profile.profileStrengthScore != null ? ` ${profile.profileStrengthScore}%` : ""
                }`
              : ""}
          </SheetDescription>
        </SheetHeader>

        {loading ? (
          <div className="flex items-center justify-center py-12">
            <Loader2 className="size-6 animate-spin text-muted-foreground" />
          </div>
        ) : profile ? (
          <div className="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto px-4 pb-6">
            {profile.coverUrl && (
              <div className="relative h-28 w-full overflow-hidden rounded-2xl">
                <Image src={profile.coverUrl} alt="" fill className="object-cover" />
              </div>
            )}

            {profile.logoUrl && (
              <div className="relative mx-auto size-20 overflow-hidden rounded-2xl">
                <Image src={profile.logoUrl} alt={profile.businessName} fill className="object-cover" />
              </div>
            )}

            {profile.description && (
              <p className="text-sm text-muted-foreground" data-testid="organizer-drawer-about">
                {profile.description}
              </p>
            )}

            {location && (
              <p className="inline-flex items-center gap-1 text-sm text-muted-foreground">
                <MapPin className="size-4" />
                {location}
              </p>
            )}

            {gallery.length > 0 && (
              <div data-testid="organizer-drawer-gallery">
                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                  Gallery
                </p>
                <div className="grid grid-cols-3 gap-2">
                  {gallery.slice(0, 6).map((url) => (
                    <div key={url} className="relative aspect-square overflow-hidden rounded-xl">
                      <Image src={url} alt="" fill className="object-cover" />
                    </div>
                  ))}
                </div>
              </div>
            )}

            {reputation && (
              <div className="flex flex-col gap-3" data-testid="organizer-drawer-reputation">
                <ReputationSummaryCard
                  summary={{
                    tier: reputation.tier,
                    tierLabel: reputation.tierLabel,
                    score: reputation.score,
                    rating: reputation.rating,
                    reviewCount: reputation.reviewCount ?? 0,
                    completedEvents: reputation.completedEvents ?? 0,
                    wouldBookAgainRate: reputation.wouldBookAgainRate,
                    badges: reputation.badges ?? [],
                  }}
                  compact
                />
                {typeof reputation.wouldBookAgainRate === "number" && (
                  <p className="text-xs text-muted-foreground">
                    {Math.round(reputation.wouldBookAgainRate * 100)}% of organizers would book again
                  </p>
                )}
                {reputation.riskFlags && reputation.riskFlags.length > 0 && (
                  <div className="flex flex-col gap-2">
                    {reputation.riskFlags.map((flag) => (
                      <Alert key={flag.id} variant="default">
                        <AlertDescription className="text-xs">{flag.label}</AlertDescription>
                      </Alert>
                    ))}
                  </div>
                )}
              </div>
            )}

            {!reputation && (profile.rating != null || profile.completedEvents != null) && (
              <div className="flex flex-wrap gap-2 text-sm text-muted-foreground">
                {profile.rating != null && <span>Rating {profile.rating.toFixed(1)}</span>}
                {profile.completedEvents != null && (
                  <span>{profile.completedEvents} events completed</span>
                )}
              </div>
            )}

            {reputation?.badges && reputation.badges.length > 0 && (
              <div className="flex flex-wrap gap-1.5">
                {reputation.badges.map((badge) => (
                  <ReputationBadge key={badge.id} badge={badge} />
                ))}
              </div>
            )}

            {profile.trustBadges && profile.trustBadges.length > 0 && (
              <div className="flex flex-wrap gap-1.5">
                {profile.trustBadges.map((badge) => (
                  <Badge key={badge} variant="secondary">
                    {badge}
                  </Badge>
                ))}
              </div>
            )}

            {hasSetup && (
              <>
                <Separator />
                <div data-testid="organizer-drawer-setup">
                  <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    Setup & ops
                  </p>
                  <ul className="flex flex-col gap-1.5">
                    <SetupRow label="Setup" value={setup?.setupType} />
                    <SetupRow label="Power" value={setup?.powerNeeds} />
                    <SetupRow label="Water" value={setup?.waterNeeds} />
                    <SetupRow label="Tent" value={setup?.tentSize} />
                    <SetupRow label="Trailer" value={setup?.trailerSize} />
                    <SetupRow label="Truck" value={setup?.truckSize} />
                    <SetupRow label="Space" value={setup?.spaceNeeds} />
                  </ul>
                </div>
              </>
            )}

            {profile.documents && profile.documents.length > 0 && (
              <>
                <Separator />
                <div data-testid="organizer-drawer-documents">
                  <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    Documents
                  </p>
                  <div className="flex flex-wrap gap-1.5">
                    {profile.documents.map((doc) => (
                      <Badge key={`${doc.type}-${doc.status}`} variant="outline">
                        {doc.type} · {doc.status}
                      </Badge>
                    ))}
                  </div>
                </div>
              </>
            )}

            {(profile.upcomingEvents?.length || profile.pastEvents?.length) && (
              <>
                <Separator />
                <div data-testid="organizer-drawer-history">
                  <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    With you
                  </p>
                  <div className="flex flex-col gap-2">
                    {(profile.upcomingEvents ?? []).slice(0, 4).map((row) => (
                      <div key={`up-${row.applicationId ?? row.eventTitle}`} className="rounded-lg border p-2">
                        <p className="text-xs font-medium">{row.eventTitle}</p>
                        <p className="text-[11px] text-muted-foreground">
                          {row.status}
                          {row.date ? ` · ${row.date}` : ""}
                        </p>
                      </div>
                    ))}
                    {(profile.pastEvents ?? []).slice(0, 4).map((row) => (
                      <div key={`past-${row.applicationId ?? row.eventTitle}`} className="rounded-lg border p-2">
                        <p className="text-xs font-medium">{row.eventTitle}</p>
                        <p className="text-[11px] text-muted-foreground">
                          {row.status}
                          {row.date ? ` · ${row.date}` : ""}
                        </p>
                      </div>
                    ))}
                  </div>
                </div>
              </>
            )}

            {profile.products && profile.products.length > 0 && (
              <>
                <Separator />
                <div>
                  <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    Products
                  </p>
                  <div className="flex flex-wrap gap-1.5">
                    {profile.products.map((p) => (
                      <Badge key={p} variant="outline">
                        {p}
                      </Badge>
                    ))}
                  </div>
                </div>
              </>
            )}

            {profile.applicationMessage && (
              <>
                <Separator />
                <div>
                  <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    Application message
                  </p>
                  <p className="text-sm">{profile.applicationMessage}</p>
                </div>
              </>
            )}

            {profile.readinessBlockers && profile.readinessBlockers.length > 0 ? (
              <>
                <Separator />
                <div>
                  <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    Readiness blockers
                  </p>
                  <ul className="flex flex-col gap-1">
                    {profile.readinessBlockers.slice(0, 4).map((blocker) => (
                      <li key={blocker} className="text-xs text-muted-foreground">
                        - {blocker}
                      </li>
                    ))}
                  </ul>
                </div>
              </>
            ) : null}

            {timeline.length > 0 && (
              <>
                <Separator />
                <div>
                  <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    Action history
                  </p>
                  <div className="flex flex-col gap-2">
                    {timeline.slice(0, 6).map((item) => (
                      <div key={item.id} className="rounded-lg border p-2">
                        <p className="text-xs font-medium">{item.label}</p>
                        <p className="text-[11px] text-muted-foreground">
                          {item.type}
                          {item.timestamp ? ` · ${item.timestamp}` : ""}
                        </p>
                      </div>
                    ))}
                  </div>
                </div>
              </>
            )}

            {notes.length > 0 && (
              <>
                <Separator />
                <div>
                  <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    Organizer notes
                  </p>
                  <div className="flex flex-col gap-2">
                    {notes.slice(0, 4).map((note) => (
                      <div key={note.id} className="rounded-lg border p-2">
                        <p className="text-xs">{note.content}</p>
                        <p className="text-[11px] text-muted-foreground">
                          {note.pinned ? "Pinned" : "Note"}
                          {note.createdAt ? ` · ${note.createdAt}` : ""}
                        </p>
                      </div>
                    ))}
                  </div>
                </div>
              </>
            )}

            {(profile.website || profile.instagram || profile.contactEmail || profile.contactPhone) && (
              <>
                <Separator />
                <div className="flex flex-col gap-2 text-sm" data-testid="organizer-drawer-contact">
                  {profile.contactEmail && (
                    <a
                      href={`mailto:${profile.contactEmail}`}
                      className="inline-flex items-center gap-2 text-foreground hover:underline"
                    >
                      <Mail className="size-4" />
                      {profile.contactEmail}
                    </a>
                  )}
                  {profile.contactPhone && (
                    <a
                      href={`tel:${profile.contactPhone}`}
                      className="inline-flex items-center gap-2 text-foreground hover:underline"
                    >
                      <Phone className="size-4" />
                      {profile.contactPhone}
                    </a>
                  )}
                  {profile.website && (
                    <Link
                      href={profile.website}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="inline-flex items-center gap-2 text-foreground hover:underline"
                    >
                      <Globe className="size-4" />
                      Website
                    </Link>
                  )}
                  {profile.instagram && (
                    <span className="inline-flex items-center gap-2 text-muted-foreground">
                      <AtSign className="size-4" />
                      @{profile.instagram.replace(/^@/, "")}
                    </span>
                  )}
                </div>
              </>
            )}

            {profile.wordpressCrmUrl && (
              <>
                <Separator />
                <Button
                  asChild
                  variant="outline"
                  className="w-full"
                  data-testid="organizer-drawer-crm-link"
                >
                  <a href={profile.wordpressCrmUrl} target="_blank" rel="noopener noreferrer">
                    <ExternalLink className="size-4" />
                    Open in LNKup CRM
                  </a>
                </Button>
              </>
            )}
          </div>
        ) : (
          <p className="px-4 text-sm text-muted-foreground">
            We couldn&apos;t load this vendor profile.
          </p>
        )}
      </SheetContent>
    </Sheet>
  )
}

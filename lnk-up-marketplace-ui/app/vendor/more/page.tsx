"use client"

import Link from "next/link"
import {
  FileText,
  TrendingUp,
  Star,
  Heart,
  Ticket,
  User,
  Bell,
  Bookmark,
  Download,
  HelpCircle,
  Shield,
} from "lucide-react"
import { SettingsMenu, type SettingsGroup } from "@/components/marketplace/settings-menu"
import { VendorReferralCard } from "@/components/vendor/vendor-referral-card"
import { InviteOrganizersCard } from "@/components/referrals/invite-organizers-card"
import { ExecutiveHero } from "@/components/vendor/executive-hero"
import { Surface } from "@/components/vendor/surface"
import { Avatar, AvatarFallback } from "@/components/ui/avatar"
import { VerificationBadge } from "@/components/marketplace/badges"
import { Button } from "@/components/ui/button"
import { useVendorReferrals } from "@/lib/hooks/use-vendor-referrals"
import { useOrganizerRecruitReferrals } from "@/lib/hooks/use-organizer-recruit-referrals"
import { currentVendor } from "@/lib/data"

const groups: SettingsGroup[] = [
  {
    title: "Work",
    items: [
      { icon: FileText, label: "Documents", hint: "2 pending review", href: "/vendor/documents" },
      { icon: Ticket, label: "Event operations", hint: "Passes & day-of info", href: "/vendor/operations" },
      { icon: TrendingUp, label: "Growth", hint: "Score 87 · Top 12%", href: "/vendor/growth" },
      { icon: Star, label: "Reviews", hint: `${currentVendor.reviews} reviews`, href: "/vendor/reviews" },
      { icon: Heart, label: "Favorites", hint: "3 saved events", href: "/vendor/favorites" },
    ],
  },
  {
    title: "Account",
    items: [
      { icon: User, label: "Business profile", hint: currentVendor.name, href: "/vendor/profile" },
      { icon: Bell, label: "Notifications", hint: "Alerts & updates", href: "/notifications" },
      { icon: Bell, label: "Alert preferences", href: "/notifications/preferences" },
      { icon: Bookmark, label: "Saved search alerts", href: "/notifications/preferences" },
      { icon: Download, label: "Install app", hint: "Add LNKup to your home screen", href: "/vendor" },
      { icon: Shield, label: "Privacy & security" },
      { icon: HelpCircle, label: "Help & support" },
    ],
  },
]

export default function VendorMorePage() {
  const { state, loading, generateInvite } = useVendorReferrals()
  const {
    state: orgRecruit,
    loading: orgRecruitLoading,
    ensureCode: ensureOrgRecruitCode,
  } = useOrganizerRecruitReferrals()

  return (
    <div className="flex flex-col gap-6">
      <ExecutiveHero
        title="More"
        subtitle="Documents, growth, and account settings."
        primaryCta={{ label: "Edit profile", href: "/vendor/profile" }}
      />

      <Surface
        variant="hero"
        className="flex items-center gap-4"
        data-testid="vendor-more-profile"
      >
        <Avatar className="size-14">
          <AvatarFallback className="bg-primary font-heading text-primary-foreground">
            {currentVendor.initials}
          </AvatarFallback>
        </Avatar>
        <div className="flex min-w-0 flex-1 flex-col gap-1">
          <div className="flex flex-wrap items-center gap-2">
            <span className="font-heading text-lg font-semibold">{currentVendor.name}</span>
            {currentVendor.verified && <VerificationBadge />}
          </div>
          <span className="text-sm text-muted-foreground">
            {currentVendor.category} · {currentVendor.city}, {currentVendor.state}
          </span>
        </div>
        <Button
          variant="outline"
          size="sm"
          className="min-h-11 shrink-0"
          nativeButton={false}
          render={<Link href="/vendor/profile" />}
        >
          Edit
        </Button>
      </Surface>

      <InviteOrganizersCard
        state={orgRecruit}
        loading={orgRecruitLoading}
        onEnsureCode={ensureOrgRecruitCode}
      />

      <VendorReferralCard state={state} loading={loading} onGenerate={generateInvite} />

      <SettingsMenu groups={groups} />
    </div>
  )
}

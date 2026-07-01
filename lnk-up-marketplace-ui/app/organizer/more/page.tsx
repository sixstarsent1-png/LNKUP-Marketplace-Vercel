"use client"

import {
  Building2,
  BadgeCheck,
  CreditCard,
  Bell,
  Users,
  FileText,
  HelpCircle,
  Shield,
} from "lucide-react"
import { PageHeader } from "@/components/marketplace/page-header"
import { SettingsMenu, type SettingsGroup } from "@/components/marketplace/settings-menu"
import { Card, CardContent } from "@/components/ui/card"
import { Avatar, AvatarFallback } from "@/components/ui/avatar"
import { VerificationBadge } from "@/components/marketplace/badges"

const groups: SettingsGroup[] = [
  {
    title: "Organization",
    items: [
      { icon: Building2, label: "Business profile", hint: "Name, logo, and contact" },
      { icon: BadgeCheck, label: "Verification", hint: "Verified organizer" },
      { icon: Users, label: "Team members", hint: "3 members" },
    ],
  },
  {
    title: "Billing & payouts",
    items: [
      { icon: CreditCard, label: "Payout account", hint: "Bank ending 4821" },
      { icon: FileText, label: "Tax documents" },
    ],
  },
  {
    title: "Preferences",
    items: [
      { icon: Bell, label: "Notifications" },
      { icon: Shield, label: "Privacy & security" },
      { icon: HelpCircle, label: "Help & support" },
    ],
  },
]

export default function OrganizerMorePage() {
  return (
    <div className="flex flex-col gap-6">
      <PageHeader title="More" subtitle="Organization settings and account." />

      <Card>
        <CardContent className="flex items-center gap-4 pt-6">
          <Avatar className="size-14">
            <AvatarFallback className="bg-primary font-heading text-primary-foreground">CW</AvatarFallback>
          </Avatar>
          <div className="flex flex-1 flex-col gap-1">
            <div className="flex items-center gap-2">
              <span className="font-heading text-lg font-semibold">CultureWave Events</span>
              <VerificationBadge />
            </div>
            <span className="text-sm text-muted-foreground">12 events hosted · Charlotte, NC</span>
          </div>
        </CardContent>
      </Card>

      <SettingsMenu groups={groups} />
    </div>
  )
}

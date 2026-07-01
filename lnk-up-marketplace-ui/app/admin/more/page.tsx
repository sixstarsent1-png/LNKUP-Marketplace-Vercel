"use client"

import {
  ShieldCheck,
  Percent,
  Flag,
  Bell,
  Users,
  FileText,
  Settings2,
  Database,
} from "lucide-react"
import { PageHeader } from "@/components/marketplace/page-header"
import { SettingsMenu, type SettingsGroup } from "@/components/marketplace/settings-menu"

const groups: SettingsGroup[] = [
  {
    title: "Marketplace controls",
    items: [
      { icon: Percent, label: "Take rate & fees", hint: "Currently 10%" },
      { icon: ShieldCheck, label: "Verification rules" },
      { icon: Flag, label: "Content moderation", hint: "3 items in queue" },
    ],
  },
  {
    title: "Operations",
    items: [
      { icon: Users, label: "Admin team", hint: "5 members" },
      { icon: FileText, label: "Audit log" },
      { icon: Database, label: "Data & exports" },
    ],
  },
  {
    title: "System",
    items: [
      { icon: Bell, label: "Alerts & notifications" },
      { icon: Settings2, label: "Platform settings" },
    ],
  },
]

export default function AdminMorePage() {
  return (
    <div className="flex flex-col gap-6">
      <PageHeader title="More" subtitle="Platform administration and controls." />
      <SettingsMenu groups={groups} />
    </div>
  )
}

"use client"

import { useState } from "react"
import {
  DollarSign,
  Users,
  CalendarCheck,
  ShieldAlert,
  ShieldCheck,
  AlertTriangle,
  FileText,
  Tags,
  Settings,
  ArrowRight,
  Store,
  Activity,
} from "lucide-react"
import { PageHeader } from "../app-shell"
import { Card, SectionHeader, StatCard, Pill } from "../primitives"
import { TabBar } from "../ui-kit"
import { VendorCard } from "../cards"
import { vendors, organizers, events, formatCurrency } from "@/lib/data"

/* ============ ADMIN OVERVIEW ============ */
export function AdminOverview() {
  const activity = [
    { icon: Users, text: "New vendor Glow Beauty Bar joined", time: "2m ago" },
    { icon: CalendarCheck, text: "Atlanta Food Fest published", time: "1h ago" },
    { icon: DollarSign, text: "Payout of $4,200 processed", time: "3h ago" },
    { icon: ShieldCheck, text: "City Drip Apparel verified", time: "5h ago" },
  ]
  return (
    <div className="space-y-6">
      <PageHeader title="Marketplace Overview" subtitle="Platform health at a glance." />
      <div className="grid grid-cols-2 gap-3">
        <StatCard label="GMV (30d)" value={formatCurrency(184200)} icon={DollarSign} accent />
        <StatCard label="Active vendors" value="1,284" icon={Users} />
        <StatCard label="Live events" value="46" icon={CalendarCheck} />
        <StatCard label="Take rate" value="8.5%" icon={Activity} />
      </div>

      <Card className="border-destructive/20 bg-destructive/5 p-4">
        <div className="flex items-start gap-3">
          <ShieldAlert className="size-5 shrink-0 text-destructive" />
          <div className="flex-1">
            <h3 className="font-heading text-sm font-bold">Risk alerts</h3>
            <p className="text-sm text-muted-foreground">
              2 flagged payments and 1 chargeback dispute need review.
            </p>
          </div>
          <Pill className="bg-destructive/10 text-destructive">3</Pill>
        </div>
      </Card>

      <div className="grid grid-cols-2 gap-3">
        <Card className="p-4">
          <ShieldCheck className="size-5 text-muted-foreground" />
          <p className="mt-2 font-heading text-2xl font-bold">7</p>
          <p className="text-xs text-muted-foreground">Verification queue</p>
        </Card>
        <Card className="p-4">
          <AlertTriangle className="size-5 text-muted-foreground" />
          <p className="mt-2 font-heading text-2xl font-bold">2</p>
          <p className="text-xs text-muted-foreground">Open disputes</p>
        </Card>
      </div>

      <div>
        <SectionHeader title="Recent activity" />
        <Card className="divide-y divide-border">
          {activity.map((a) => (
            <div key={a.text} className="flex items-center gap-3 p-4">
              <div className="flex size-9 items-center justify-center rounded-xl bg-secondary">
                <a.icon className="size-4" />
              </div>
              <span className="flex-1 text-sm font-medium">{a.text}</span>
              <span className="text-xs text-muted-foreground">{a.time}</span>
            </div>
          ))}
        </Card>
      </div>
    </div>
  )
}

/* ============ ADMIN USERS ============ */
export function AdminUsers() {
  const tabs = ["Vendors", "Organizers", "Admins", "Connectors"]
  const [tab, setTab] = useState("Vendors")
  return (
    <div className="space-y-4">
      <PageHeader title="Users" subtitle="Manage everyone on the marketplace." />
      <TabBar tabs={tabs} active={tab} onChange={setTab} />
      {tab === "Vendors" ? (
        <div className="space-y-3">
          {vendors.map((v) => (
            <VendorCard key={v.id} vendor={v} primaryAction="Manage" />
          ))}
        </div>
      ) : tab === "Organizers" ? (
        <Card className="divide-y divide-border">
          {organizers.map((o) => (
            <div key={o} className="flex items-center gap-3 p-4">
              <div className="flex size-10 items-center justify-center rounded-2xl bg-foreground text-sm font-bold text-background">
                {o.split(" ").slice(0, 2).map((w) => w[0]).join("")}
              </div>
              <div className="flex-1">
                <p className="text-sm font-semibold">{o}</p>
                <p className="text-xs text-muted-foreground">
                  {Math.floor(Math.random() * 8) + 2} active events
                </p>
              </div>
              <Pill className="bg-success/15 text-success">Verified</Pill>
            </div>
          ))}
        </Card>
      ) : tab === "Admins" ? (
        <Card className="divide-y divide-border">
          {["Jordan (Owner)", "Priya (Support)", "Marcus (Finance)"].map((a) => (
            <div key={a} className="flex items-center gap-3 p-4">
              <ShieldCheck className="size-5 text-muted-foreground" />
              <span className="flex-1 text-sm font-medium">{a}</span>
              <Pill>Admin</Pill>
            </div>
          ))}
        </Card>
      ) : (
        <Card className="divide-y divide-border">
          {["Ava (Charlotte)", "Diego (Miami)"].map((c) => (
            <div key={c} className="flex items-center gap-3 p-4">
              <Users className="size-5 text-muted-foreground" />
              <span className="flex-1 text-sm font-medium">{c}</span>
              <Pill className="bg-lime text-lime-foreground">Connector</Pill>
            </div>
          ))}
        </Card>
      )}
    </div>
  )
}

/* ============ ADMIN EVENTS ============ */
export function AdminEvents() {
  const tabs = ["Events", "Opportunities", "Applications", "Packets"]
  const [tab, setTab] = useState("Events")
  return (
    <div className="space-y-4">
      <PageHeader title="Events" subtitle="Oversee every event on the platform." />
      <TabBar tabs={tabs} active={tab} onChange={setTab} />
      {tab === "Events" ? (
        <Card className="divide-y divide-border">
          {events.map((e) => (
            <div key={e.id} className="flex items-center gap-3 p-4">
              <div className="flex size-10 items-center justify-center rounded-2xl bg-secondary">
                <CalendarCheck className="size-4" />
              </div>
              <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-semibold">{e.name}</p>
                <p className="text-xs text-muted-foreground">
                  {e.organizer} · {e.city}, {e.state}
                </p>
              </div>
              <Pill className="bg-success/15 text-success">Live</Pill>
            </div>
          ))}
        </Card>
      ) : tab === "Opportunities" ? (
        <Card className="divide-y divide-border">
          {events.slice(0, 4).map((e) => (
            <div key={e.id} className="flex items-center gap-3 p-4">
              <Store className="size-5 text-muted-foreground" />
              <span className="flex-1 text-sm font-medium">{e.name}</span>
              <span className="text-xs text-muted-foreground">
                {e.categories.length} categories
              </span>
            </div>
          ))}
        </Card>
      ) : tab === "Applications" ? (
        <div className="grid grid-cols-2 gap-3">
          <StatCard label="Total applications" value="3,420" icon={FileText} accent />
          <StatCard label="Approval rate" value="71%" icon={Activity} />
          <StatCard label="Avg. response" value="1.4d" icon={CalendarCheck} />
          <StatCard label="Paid conversion" value="88%" icon={DollarSign} />
        </div>
      ) : (
        <Card className="divide-y divide-border">
          {events.slice(0, 3).map((e) => (
            <div key={e.id} className="flex items-center gap-3 p-4">
              <FileText className="size-5 text-muted-foreground" />
              <span className="flex-1 text-sm font-medium">{e.name} packet</span>
              <Pill>Published</Pill>
            </div>
          ))}
        </Card>
      )}
    </div>
  )
}

/* ============ ADMIN MONEY ============ */
export function AdminMoney() {
  const tabs = ["GMV", "Revenue", "Payouts", "Refunds", "Fees", "Reports"]
  const [tab, setTab] = useState("GMV")
  return (
    <div className="space-y-4">
      <PageHeader title="Money" subtitle="Marketplace financials and payouts." />
      <div className="grid grid-cols-2 gap-3">
        <StatCard label="GMV (30d)" value={formatCurrency(184200)} icon={DollarSign} accent />
        <StatCard label="Net revenue" value={formatCurrency(15660)} icon={Activity} />
      </div>
      <TabBar tabs={tabs} active={tab} onChange={setTab} />
      {tab === "Reports" ? (
        <Card className="divide-y divide-border">
          {["Monthly GMV report", "Payout reconciliation", "Fee breakdown"].map((r) => (
            <div key={r} className="flex items-center gap-3 p-4">
              <FileText className="size-5 text-muted-foreground" />
              <span className="flex-1 text-sm font-medium">{r}</span>
              <button className="rounded-full border border-border px-3 py-1.5 text-xs font-semibold">
                Export
              </button>
            </div>
          ))}
        </Card>
      ) : (
        <Card className="p-4">
          <div className="flex items-center gap-2">
            <Activity className="size-5 text-muted-foreground" />
            <h3 className="font-heading text-sm font-bold">{tab} trend</h3>
          </div>
          <div className="mt-4 flex h-32 items-end gap-1.5">
            {[38, 52, 44, 61, 70, 66, 82, 74, 90, 85, 96, 100].map((h, i) => (
              <div
                key={i}
                className="flex-1 rounded-t-md bg-lime"
                style={{ height: `${h}%` }}
              />
            ))}
          </div>
          <p className="mt-2 text-xs text-muted-foreground">Last 12 weeks</p>
        </Card>
      )}
    </div>
  )
}

/* ============ ADMIN MORE ============ */
export function AdminMore() {
  const items = [
    { icon: FileText, label: "Documents" },
    { icon: ShieldCheck, label: "Verification" },
    { icon: AlertTriangle, label: "Disputes" },
    { icon: Tags, label: "Categories" },
    { icon: Settings, label: "Settings" },
  ]
  return (
    <div className="space-y-4">
      <PageHeader title="More" subtitle="Platform operations and configuration." />
      <Card className="divide-y divide-border">
        {items.map((i) => (
          <button
            key={i.label}
            className="flex w-full items-center gap-3 p-4 text-left hover:bg-secondary"
          >
            <div className="flex size-9 items-center justify-center rounded-xl bg-secondary">
              <i.icon className="size-4" />
            </div>
            <span className="flex-1 text-sm font-semibold">{i.label}</span>
            <ArrowRight className="size-4 text-muted-foreground" />
          </button>
        ))}
      </Card>
    </div>
  )
}

"use client"

import { useState } from "react"
import {
  Plus,
  Users,
  DollarSign,
  Gauge,
  AlertCircle,
  CalendarCheck,
  ArrowRight,
  Wallet,
  Building2,
  UserCog,
  Settings,
  HelpCircle,
  ShieldCheck,
  Bell,
  Store,
  MapPin,
  Megaphone,
  CloudSun,
  ClipboardCheck,
  BarChart3,
  ChevronLeft,
} from "lucide-react"
import { PageHeader } from "../app-shell"
import {
  Card,
  SectionHeader,
  StatCard,
  ProgressRing,
  EmptyState,
  Pill,
  ProgressBar,
} from "../primitives"
import { TabBar } from "../ui-kit"
import { EventCard, VendorCard } from "../cards"
import {
  LaunchChecklist,
  WalletCard,
  TransactionRow,
  type ChecklistItem,
} from "../features"
import {
  events,
  vendors,
  formatCurrency,
  type EventItem,
} from "@/lib/data"

const checklist: ChecklistItem[] = [
  { label: "Create your first event", done: true },
  { label: "Post vendor opportunities", done: true },
  { label: "Approve 5 vendors", done: true },
  { label: "Finish payout setup", done: false },
  { label: "Publish event packet", done: false },
]

/* ============ ORGANIZER HOME ============ */
export function OrganizerHome({
  onNavigate,
}: {
  onNavigate: (k: string) => void
}) {
  return (
    <div className="space-y-6">
      <PageHeader
        title="Organizer Command Center"
        subtitle="CultureWave Events"
        action={
          <button
            onClick={() => onNavigate("events")}
            className="hidden items-center gap-1.5 rounded-full bg-foreground px-4 py-2.5 text-sm font-semibold text-background sm:flex"
          >
            <Plus className="size-4" /> Create Event
          </button>
        }
      />

      <div className="grid grid-cols-2 gap-3">
        <StatCard label="Active events" value="3" icon={CalendarCheck} accent />
        <StatCard label="Vendor requests" value="24" icon={Users} />
        <StatCard label="Paid vendors" value="41" icon={ClipboardCheck} />
        <StatCard label="Vendor revenue" value={formatCurrency(8420)} icon={DollarSign} />
      </div>

      <Card className="flex items-center gap-4 p-4">
        <ProgressRing value={68} size={72} />
        <div>
          <h3 className="font-heading text-base font-bold">Readiness score</h3>
          <p className="text-sm text-muted-foreground">
            Across all active events. Finish payouts to boost your score.
          </p>
        </div>
      </Card>

      <Card className="border-warning/30 bg-warning/5 p-4">
        <div className="flex items-start gap-3">
          <AlertCircle className="size-5 shrink-0 text-warning" />
          <div className="flex-1">
            <h3 className="font-heading text-sm font-bold">Needs attention</h3>
            <p className="text-sm text-muted-foreground">
              6 vendor requests are waiting on your review for Charlotte Night Market.
            </p>
            <button
              onClick={() => onNavigate("vendors")}
              className="mt-2 rounded-full bg-foreground px-4 py-2 text-xs font-semibold text-background"
            >
              Review requests
            </button>
          </div>
        </div>
      </Card>

      <LaunchChecklist items={checklist} />

      <div>
        <SectionHeader
          title="Recent vendor requests"
          action={
            <button
              onClick={() => onNavigate("vendors")}
              className="flex items-center gap-1 text-sm font-semibold text-muted-foreground"
            >
              See all <ArrowRight className="size-4" />
            </button>
          }
        />
        <div className="space-y-3">
          {vendors.slice(0, 3).map((v) => (
            <VendorCard key={v.id} vendor={v} primaryAction="Review" />
          ))}
        </div>
      </div>
    </div>
  )
}

/* ============ ORGANIZER EVENTS ============ */
export function OrganizerEvents({
  onOpenEvent,
}: {
  onOpenEvent: (e: EventItem) => void
}) {
  const stats = [
    { requests: 12, approved: 8, paid: 6, revenue: 1110, readiness: 78 },
    { requests: 18, approved: 14, paid: 12, revenue: 3000, readiness: 64 },
    { requests: 9, approved: 5, paid: 3, revenue: 480, readiness: 52 },
  ]
  return (
    <div className="space-y-4">
      <PageHeader
        title="Events"
        subtitle="Manage every event and its vendor pipeline."
        action={
          <button className="flex items-center gap-1.5 rounded-full bg-foreground px-4 py-2.5 text-sm font-semibold text-background">
            <Plus className="size-4" /> Create
          </button>
        }
      />
      <div className="space-y-4">
        {events.slice(0, 3).map((e, i) => (
          <EventCard
            key={e.id}
            event={e}
            requests={stats[i].requests}
            approved={stats[i].approved}
            paid={stats[i].paid}
            revenue={stats[i].revenue}
            readiness={stats[i].readiness}
            onManage={() => onOpenEvent(e)}
          />
        ))}
      </div>
    </div>
  )
}

/* ============ ORGANIZER EVENT DETAIL ============ */
export function OrganizerEventDetail({
  event,
  onBack,
}: {
  event: EventItem
  onBack: () => void
}) {
  const tabs = [
    "Overview",
    "Requests",
    "Opportunities",
    "Packet",
    "Operations",
    "Intelligence",
  ]
  const [tab, setTab] = useState("Overview")

  return (
    <div className="space-y-4">
      <button
        onClick={onBack}
        className="flex items-center gap-1 text-sm font-semibold text-muted-foreground"
      >
        <ChevronLeft className="size-4" /> Back to events
      </button>
      <PageHeader title={event.name} subtitle={`${event.date} · ${event.venue}`} />
      <TabBar tabs={tabs} active={tab} onChange={setTab} />

      {tab === "Overview" ? (
        <div className="space-y-3">
          <div className="grid grid-cols-2 gap-3">
            <StatCard label="Requests" value="12" icon={Users} />
            <StatCard label="Paid vendors" value="6" icon={ClipboardCheck} accent />
            <StatCard label="Revenue" value={formatCurrency(1110)} icon={DollarSign} />
            <StatCard label="Spots left" value={`${event.spotsRemaining}`} icon={Store} />
          </div>
          <Card className="p-4">
            <div className="mb-1 flex items-center justify-between text-sm">
              <span className="font-semibold">Event readiness</span>
              <span className="text-muted-foreground">78%</span>
            </div>
            <ProgressBar value={78} />
          </Card>
        </div>
      ) : tab === "Requests" ? (
        <div className="space-y-3">
          {vendors.slice(0, 4).map((v) => (
            <RequestReviewCard key={v.id} name={v.name} category={v.category} />
          ))}
        </div>
      ) : tab === "Opportunities" ? (
        <div className="space-y-3">
          {event.categories.map((c) => (
            <Card key={c} className="flex items-center justify-between p-4">
              <div>
                <p className="font-heading text-sm font-bold">{c}</p>
                <p className="text-xs text-muted-foreground">
                  {formatCurrency(event.fee)} fee · {event.deadline}
                </p>
              </div>
              <Pill>{Math.floor(Math.random() * 6) + 2} spots</Pill>
            </Card>
          ))}
        </div>
      ) : tab === "Packet" ? (
        <Card className="divide-y divide-border">
          {[
            { icon: CalendarCheck, label: "Load-in time", value: "3:00 PM – 4:30 PM" },
            { icon: MapPin, label: "Parking", value: "Lot B · vendor pass" },
            { icon: Store, label: "Booth assignments", value: "Published" },
            { icon: ShieldCheck, label: "Refund policy", value: "Full refund 7 days out" },
          ].map((d) => (
            <div key={d.label} className="flex items-center gap-3 p-4">
              <d.icon className="size-5 text-muted-foreground" />
              <span className="flex-1 text-sm font-medium">{d.label}</span>
              <span className="text-sm text-muted-foreground">{d.value}</span>
            </div>
          ))}
        </Card>
      ) : tab === "Operations" ? (
        <div className="grid grid-cols-2 gap-3">
          {[
            { icon: ClipboardCheck, label: "Vendor readiness", value: "78%" },
            { icon: ClipboardCheck, label: "Checked in", value: "0 / 6" },
            { icon: Store, label: "Booth assignments", value: "Done" },
            { icon: Megaphone, label: "Broadcasts", value: "2 sent" },
            { icon: AlertCircle, label: "Incidents", value: "None" },
            { icon: CloudSun, label: "Weather", value: "Clear · 81°" },
          ].map((o) => (
            <Card key={o.label} className="p-4">
              <o.icon className="size-5 text-muted-foreground" />
              <p className="mt-2 font-heading text-lg font-bold">{o.value}</p>
              <p className="text-xs text-muted-foreground">{o.label}</p>
            </Card>
          ))}
        </div>
      ) : (
        <div className="space-y-3">
          <Card className="p-4">
            <div className="flex items-center gap-2">
              <BarChart3 className="size-5 text-muted-foreground" />
              <h3 className="font-heading text-sm font-bold">Category demand</h3>
            </div>
            <div className="mt-3 space-y-2">
              {["Food Vendor", "Dessert Vendor", "Retail Vendor"].map((c, i) => (
                <div key={c}>
                  <div className="mb-1 flex justify-between text-xs">
                    <span>{c}</span>
                    <span className="text-muted-foreground">{90 - i * 22}%</span>
                  </div>
                  <ProgressBar value={90 - i * 22} />
                </div>
              ))}
            </div>
          </Card>
        </div>
      )}
    </div>
  )
}

function RequestReviewCard({
  name,
  category,
}: {
  name: string
  category: string
}) {
  const [decision, setDecision] = useState<string | null>(null)
  return (
    <Card className="p-4">
      <div className="flex items-center gap-3">
        <div className="flex size-11 items-center justify-center rounded-2xl bg-foreground font-heading text-sm font-bold text-background">
          {name.split(" ").slice(0, 2).map((w) => w[0]).join("")}
        </div>
        <div className="flex-1">
          <p className="font-heading text-sm font-bold">{name}</p>
          <p className="text-xs text-muted-foreground">{category}</p>
        </div>
        {decision ? <Pill className="bg-lime text-lime-foreground">{decision}</Pill> : null}
      </div>
      {!decision ? (
        <div className="mt-3 grid grid-cols-3 gap-2">
          <button
            onClick={() => setDecision("Approved")}
            className="rounded-full bg-foreground py-2 text-xs font-semibold text-background"
          >
            Approve
          </button>
          <button
            onClick={() => setDecision("Waitlisted")}
            className="rounded-full bg-secondary py-2 text-xs font-semibold"
          >
            Waitlist
          </button>
          <button
            onClick={() => setDecision("Rejected")}
            className="rounded-full bg-secondary py-2 text-xs font-semibold text-destructive"
          >
            Reject
          </button>
        </div>
      ) : null}
    </Card>
  )
}

/* ============ ORGANIZER VENDORS ============ */
export function OrganizerVendors() {
  const tabs = ["Requests", "Discover", "Approved", "Lists", "Invites"]
  const [tab, setTab] = useState("Requests")

  const byStatus: Record<string, typeof vendors> = {
    Requests: vendors.filter((v) => v.status === "requested"),
    Approved: vendors.filter((v) => v.status === "approved"),
    Invites: vendors.filter((v) => v.status === "invited"),
    Discover: vendors,
  }
  const list = byStatus[tab] ?? vendors

  return (
    <div className="space-y-4">
      <PageHeader title="Vendors" subtitle="Review, discover, and invite vendors." />
      <TabBar tabs={tabs} active={tab} onChange={setTab} />
      {tab === "Lists" ? (
        <EmptyState
          icon={Users}
          title="No saved lists yet"
          description="Group your favorite vendors into lists to invite them faster."
        />
      ) : list.length ? (
        <div className="space-y-3">
          {list.map((v) => (
            <VendorCard
              key={v.id}
              vendor={v}
              primaryAction={
                tab === "Requests" ? "Review" : tab === "Discover" ? "Invite" : undefined
              }
            />
          ))}
        </div>
      ) : (
        <EmptyState
          icon={Users}
          title="Nothing here yet"
          description="Vendors matching this tab will appear here."
        />
      )}
    </div>
  )
}

/* ============ ORGANIZER MONEY ============ */
export function OrganizerMoney() {
  const tabs = ["Overview", "Wallet", "Payouts", "Revenue", "Refunds", "Reports"]
  const [tab, setTab] = useState("Overview")
  return (
    <div className="space-y-4">
      <PageHeader title="Money" subtitle="Balances, payouts, and revenue." />
      <div className="grid grid-cols-2 gap-3">
        <WalletCard label="Available balance" amount={6240} tone="accent" hint="Ready to pay out" />
        <WalletCard label="Pending balance" amount={2180} hint="Clears in 3 days" />
      </div>
      <TabBar tabs={tabs} active={tab} onChange={setTab} />

      {tab === "Payouts" ? (
        <Card className="p-4">
          <div className="flex items-start gap-3">
            <Wallet className="size-5 text-warning" />
            <div className="flex-1">
              <p className="font-heading text-sm font-bold">Finish payout setup</p>
              <p className="text-sm text-muted-foreground">
                We&apos;re reviewing your payout setup. Add your bank details to start
                receiving payouts.
              </p>
              <button className="mt-2 rounded-full bg-foreground px-4 py-2 text-xs font-semibold text-background">
                Finish setup
              </button>
            </div>
          </div>
        </Card>
      ) : tab === "Refunds" ? (
        <EmptyState
          icon={DollarSign}
          title="No refunds issued"
          description="Any vendor refunds you issue will appear here."
        />
      ) : tab === "Reports" ? (
        <Card className="divide-y divide-border">
          {["July vendor revenue", "Q2 payout summary", "Refund report"].map((r) => (
            <div key={r} className="flex items-center gap-3 p-4">
              <BarChart3 className="size-5 text-muted-foreground" />
              <span className="flex-1 text-sm font-medium">{r}</span>
              <button className="rounded-full border border-border px-3 py-1.5 text-xs font-semibold">
                Export
              </button>
            </div>
          ))}
        </Card>
      ) : (
        <Card className="divide-y divide-border px-4">
          <TransactionRow title="Vendor fee · Sweet Heat BBQ" subtitle="Charlotte Night Market" amount={185} incoming />
          <TransactionRow title="Vendor fee · Fresh Press Lemonade" subtitle="Raleigh Homecoming Market" amount={200} incoming />
          <TransactionRow title="Payout to bank" subtitle="Jun 30 · ····8891" amount={4200} />
        </Card>
      )}
    </div>
  )
}

/* ============ ORGANIZER MORE ============ */
export function OrganizerMore({ onRoleHint }: { onRoleHint?: () => void }) {
  const items = [
    { icon: Building2, label: "Organization Profile" },
    { icon: UserCog, label: "Team" },
    { icon: Settings, label: "Settings" },
    { icon: HelpCircle, label: "Help" },
    { icon: ShieldCheck, label: "Verification" },
    { icon: Bell, label: "Notifications" },
  ]
  return (
    <div className="space-y-4">
      <PageHeader title="More" subtitle="Manage your organization and settings." />
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
      <Card className="flex items-center gap-3 p-4">
        <Gauge className="size-5 text-muted-foreground" />
        <div className="flex-1">
          <p className="text-sm font-semibold">Organization verified</p>
          <p className="text-xs text-muted-foreground">CultureWave Events · since 2022</p>
        </div>
      </Card>
    </div>
  )
}

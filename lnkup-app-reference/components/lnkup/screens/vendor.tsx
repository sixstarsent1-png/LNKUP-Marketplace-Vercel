"use client"

import { useMemo, useState } from "react"
import {
  Search,
  SlidersHorizontal,
  Sparkles,
  TrendingUp,
  Wallet,
  Ticket,
  CalendarCheck,
  AlertCircle,
  ArrowRight,
  BadgeCheck,
  FileText,
  Camera,
  Star,
  ShieldCheck,
  Building2,
  Inbox,
  Bookmark,
} from "lucide-react"
import { PageHeader } from "../app-shell"
import {
  Card,
  SectionHeader,
  StatCard,
  ProgressRing,
  EmptyState,
  SkeletonCard,
  ProgressBar,
  Pill,
} from "../primitives"
import { TabBar } from "../ui-kit"
import { OpportunityCard, RequestCard } from "../cards"
import {
  ApplyModal,
  FilterDrawer,
  EventPassCard,
  WalletCard,
  TransactionRow,
} from "../features"
import {
  events,
  vendorRequests,
  VENDOR_CATEGORIES,
  formatCurrency,
  type EventItem,
} from "@/lib/data"
import { useLoaded } from "@/hooks/use-loaded"

/* ============ VENDOR HOME ============ */
export function VendorHome({ onNavigate }: { onNavigate: (k: string) => void }) {
  const recommended = events.slice(0, 3)
  const booked = vendorRequests.filter((r) => r.status === "paid")
  return (
    <div className="space-y-6">
      <PageHeader
        title="Welcome back, Sweet Heat BBQ"
        subtitle="Apply for free. Pay only when accepted."
      />

      <Card className="flex items-center gap-4 p-4">
        <ProgressRing value={82} size={72} />
        <div>
          <h3 className="font-heading text-base font-bold">Profile strength</h3>
          <p className="text-sm text-muted-foreground">
            Add 2 more photos to reach 100% and stand out to organizers.
          </p>
        </div>
      </Card>

      <div className="grid grid-cols-2 gap-3">
        <StatCard label="Booked events" value="4" icon={CalendarCheck} accent />
        <StatCard label="Approval rate" value="86%" icon={TrendingUp} />
      </div>

      <Card className="border-warning/30 bg-warning/5 p-4">
        <div className="flex items-start gap-3">
          <AlertCircle className="size-5 shrink-0 text-warning" />
          <div className="flex-1">
            <h3 className="font-heading text-sm font-bold">Needs attention</h3>
            <p className="text-sm text-muted-foreground">
              Payment due for Charlotte Night Market to unlock your event pass.
            </p>
            <button
              onClick={() => onNavigate("requests")}
              className="mt-2 rounded-full bg-foreground px-4 py-2 text-xs font-semibold text-background"
            >
              Pay {formatCurrency(185)}
            </button>
          </div>
        </div>
      </Card>

      <div>
        <SectionHeader
          title="Recommended for you"
          action={
            <button
              onClick={() => onNavigate("find")}
              className="flex items-center gap-1 text-sm font-semibold text-muted-foreground"
            >
              See all <ArrowRight className="size-4" />
            </button>
          }
        />
        <div className="space-y-4">
          {recommended.map((e, i) => (
            <OpportunityCard
              key={e.id}
              event={e}
              priority={i === 0}
              onApply={() => onNavigate("find")}
              onSave={() => onNavigate("find")}
            />
          ))}
        </div>
      </div>

      <div>
        <SectionHeader title="Upcoming booked events" />
        {booked.length ? (
          <div className="space-y-3">
            {booked.map((r) => (
              <RequestCard key={r.id} request={r} />
            ))}
          </div>
        ) : (
          <EmptyState
            icon={CalendarCheck}
            title="No booked events yet"
            description="Once you're accepted and paid, your events show up here."
          />
        )}
      </div>
    </div>
  )
}

/* ============ VENDOR FIND EVENTS ============ */
export function VendorFindEvents() {
  const loaded = useLoaded()
  const [query, setQuery] = useState("")
  const [selectedCats, setSelectedCats] = useState<string[]>([])
  const [drawerOpen, setDrawerOpen] = useState(false)
  const [applyEvent, setApplyEvent] = useState<EventItem | null>(null)
  const [saved, setSaved] = useState<string[]>(["miami-culture-popup"])
  const [submitted, setSubmitted] = useState(false)

  const filtered = useMemo(() => {
    return events.filter((e) => {
      const matchesQuery =
        !query ||
        e.name.toLowerCase().includes(query.toLowerCase()) ||
        e.city.toLowerCase().includes(query.toLowerCase())
      const matchesCat =
        selectedCats.length === 0 ||
        e.categories.some((c) => selectedCats.includes(c))
      return matchesQuery && matchesCat
    })
  }, [query, selectedCats])

  const trending = events.filter((e) => e.trending)

  const toggleCat = (c: string) =>
    setSelectedCats((prev) =>
      prev.includes(c) ? prev.filter((x) => x !== c) : [...prev, c],
    )

  const toggleSave = (id: string) =>
    setSaved((prev) =>
      prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id],
    )

  return (
    <div className="space-y-5">
      <div className="sticky top-[7.5rem] z-20 -mx-4 space-y-3 bg-background/95 px-4 pb-3 pt-1 backdrop-blur lg:top-0 lg:pt-2">
        <PageHeader
          title="Find Events"
          subtitle="Apply for free. Pay only when accepted."
        />
        <div className="flex gap-2">
          <div className="flex flex-1 items-center gap-2 rounded-full border border-border bg-card px-4">
            <Search className="size-4 text-muted-foreground" />
            <input
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder="Search events or cities"
              className="w-full bg-transparent py-2.5 text-sm outline-none"
            />
          </div>
          <button
            onClick={() => setDrawerOpen(true)}
            className="flex items-center gap-1.5 rounded-full bg-foreground px-4 text-sm font-semibold text-background"
          >
            <SlidersHorizontal className="size-4" />
            Filters
            {selectedCats.length ? (
              <span className="ml-0.5 flex size-5 items-center justify-center rounded-full bg-lime text-[10px] text-lime-foreground">
                {selectedCats.length}
              </span>
            ) : null}
          </button>
        </div>
        <TabBar
          tabs={["All", "Trending", "Saved"]}
          active="All"
          onChange={() => {}}
        />
      </div>

      {submitted ? (
        <Card className="border-lime bg-lime/10 p-4">
          <div className="flex items-center gap-3">
            <BadgeCheck className="size-5 text-lime-foreground" />
            <p className="text-sm font-semibold">
              Application submitted for free. You&apos;ll hear back soon.
            </p>
          </div>
        </Card>
      ) : null}

      {saved.length ? (
        <div>
          <SectionHeader title="Saved events" />
          <div className="flex flex-wrap gap-2">
            {saved.map((id) => {
              const e = events.find((ev) => ev.id === id)
              if (!e) return null
              return (
                <Pill key={id} className="gap-1.5 bg-secondary py-1.5">
                  <Bookmark className="size-3.5" />
                  {e.name}
                </Pill>
              )
            })}
          </div>
        </div>
      ) : null}

      {!loaded ? (
        <div className="space-y-4">
          <SkeletonCard />
          <SkeletonCard />
        </div>
      ) : filtered.length === 0 ? (
        <EmptyState
          icon={Search}
          title="No matching events"
          description="Try clearing filters or searching a different city."
          action={
            <button
              onClick={() => {
                setSelectedCats([])
                setQuery("")
              }}
              className="rounded-full bg-foreground px-4 py-2 text-sm font-semibold text-background"
            >
              Clear filters
            </button>
          }
        />
      ) : (
        <>
          <SectionHeader
            title={`${filtered.length} opportunities`}
            action={
              <span className="flex items-center gap-1 text-sm text-muted-foreground">
                <TrendingUp className="size-4" /> Sort
              </span>
            }
          />
          <div className="space-y-4">
            {filtered.map((e) => (
              <OpportunityCard
                key={e.id}
                event={e}
                saved={saved.includes(e.id)}
                onSave={() => toggleSave(e.id)}
                onApply={() => setApplyEvent(e)}
              />
            ))}
          </div>
        </>
      )}

      {loaded && filtered.length > 0 ? (
        <div>
          <SectionHeader title="Trending opportunities" />
          <div className="no-scrollbar -mx-4 flex gap-3 overflow-x-auto px-4">
            {trending.map((e) => (
              <div key={e.id} className="w-72 shrink-0">
                <OpportunityCard
                  event={e}
                  saved={saved.includes(e.id)}
                  onSave={() => toggleSave(e.id)}
                  onApply={() => setApplyEvent(e)}
                />
              </div>
            ))}
          </div>
        </div>
      ) : null}

      <ApplyModal
        event={applyEvent}
        open={!!applyEvent}
        onClose={() => setApplyEvent(null)}
        onSubmit={() => {
          setApplyEvent(null)
          setSubmitted(true)
        }}
      />
      <FilterDrawer
        open={drawerOpen}
        onClose={() => setDrawerOpen(false)}
        categories={[...VENDOR_CATEGORIES]}
        selectedCategories={selectedCats}
        onToggleCategory={toggleCat}
        onClear={() => setSelectedCats([])}
        onApply={() => setDrawerOpen(false)}
      />
    </div>
  )
}

/* ============ VENDOR REQUESTS ============ */
export function VendorRequests({
  onOpenPass,
}: {
  onOpenPass: () => void
}) {
  const tabs = [
    "All",
    "Under Review",
    "Approved",
    "Payment Due",
    "Paid",
    "Waitlisted",
  ]
  const [tab, setTab] = useState("All")

  const map: Record<string, string> = {
    "Under Review": "under_review",
    Approved: "approved",
    "Payment Due": "payment_due",
    Paid: "paid",
    Waitlisted: "waitlisted",
  }
  const filtered =
    tab === "All"
      ? vendorRequests
      : vendorRequests.filter((r) => r.status === map[tab])

  return (
    <div className="space-y-4">
      <PageHeader
        title="Requests"
        subtitle="Track every application from submitted to booked."
      />
      <TabBar tabs={tabs} active={tab} onChange={setTab} />
      {filtered.length ? (
        <div className="space-y-3">
          {filtered.map((r) => (
            <RequestCard
              key={r.id}
              request={r}
              onAction={r.status === "paid" ? onOpenPass : undefined}
            />
          ))}
        </div>
      ) : (
        <EmptyState
          icon={Inbox}
          title="Nothing here yet"
          description="Applications with this status will appear here."
        />
      )}
    </div>
  )
}

/* ============ VENDOR EVENT PASS ============ */
export function VendorEventPass() {
  const [unlocked, setUnlocked] = useState(false)
  const event = events[0]
  return (
    <div className="space-y-4">
      <PageHeader
        title="Event Pass"
        subtitle={unlocked ? "You're booked — your pass is ready." : "Unlock after payment."}
      />
      <EventPassCard
        event={event}
        unlocked={unlocked}
        onPay={() => setUnlocked(true)}
      />
      {unlocked ? (
        <Card className="p-4">
          <h3 className="font-heading text-sm font-bold">Vendor rules</h3>
          <ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-muted-foreground">
            <li>Arrive during your load-in window only.</li>
            <li>All booths must be set up 30 minutes before doors.</li>
            <li>Bring your own tables, tent, and power source.</li>
          </ul>
        </Card>
      ) : null}
    </div>
  )
}

/* ============ VENDOR WALLET ============ */
export function VendorWallet() {
  const tabs = ["Payments Due", "Paid Bookings", "Receipts", "Credits", "Refunds"]
  const [tab, setTab] = useState("Payments Due")
  return (
    <div className="space-y-4">
      <PageHeader title="Wallet" subtitle="Your payments, receipts, and credits." />
      <div className="grid grid-cols-2 gap-3">
        <WalletCard label="Payments due" amount={185} tone="accent" hint="1 booking" />
        <WalletCard label="Credits" amount={25} hint="Referral reward" />
      </div>
      <TabBar tabs={tabs} active={tab} onChange={setTab} />
      {tab === "Payments Due" ? (
        <Card className="p-4">
          <div className="flex items-center justify-between">
            <div>
              <p className="font-heading text-sm font-bold">Charlotte Night Market</p>
              <p className="text-xs text-muted-foreground">Due before Aug 12</p>
            </div>
            <button className="rounded-full bg-lime px-4 py-2 text-sm font-semibold text-lime-foreground">
              Pay {formatCurrency(185)}
            </button>
          </div>
        </Card>
      ) : tab === "Paid Bookings" || tab === "Receipts" ? (
        <Card className="divide-y divide-border px-4">
          <TransactionRow
            title="Atlanta Food Fest"
            subtitle="Paid Jun 20 · Visa ····4242"
            amount={250}
          />
          <TransactionRow
            title="Raleigh Homecoming Market"
            subtitle="Paid Jun 28 · Visa ····4242"
            amount={200}
          />
        </Card>
      ) : tab === "Credits" ? (
        <Card className="divide-y divide-border px-4">
          <TransactionRow
            title="Referral credit"
            subtitle="Invited The Rolling Dessert Co."
            amount={25}
            incoming
          />
        </Card>
      ) : (
        <EmptyState
          icon={Wallet}
          title="No refunds"
          description="Any refunded vendor fees will show up here."
        />
      )}
    </div>
  )
}

/* ============ VENDOR PROFILE ============ */
export function VendorProfile() {
  const tabs = [
    "Business Info",
    "Photos",
    "Documents",
    "Setup Needs",
    "Reviews",
    "Verification",
  ]
  const [tab, setTab] = useState("Business Info")
  return (
    <div className="space-y-4">
      <Card className="p-5">
        <div className="flex items-center gap-4">
          <div className="flex size-16 items-center justify-center rounded-3xl bg-foreground font-heading text-xl font-bold text-background">
            SH
          </div>
          <div>
            <div className="flex items-center gap-1.5">
              <h1 className="font-heading text-xl font-extrabold">Sweet Heat BBQ</h1>
              <BadgeCheck className="size-5 text-lime-foreground" />
            </div>
            <p className="text-sm text-muted-foreground">
              Food Vendor · Charlotte, NC
            </p>
            <div className="mt-1 flex items-center gap-3 text-sm">
              <span className="flex items-center gap-1 font-semibold">
                <Star className="size-4 fill-lime text-lime" /> 4.9
              </span>
              <span className="text-muted-foreground">34 events</span>
            </div>
          </div>
        </div>
        <div className="mt-4">
          <div className="mb-1 flex items-center justify-between text-xs">
            <span className="font-semibold">Profile strength</span>
            <span className="text-muted-foreground">82%</span>
          </div>
          <ProgressBar value={82} />
        </div>
      </Card>

      <TabBar tabs={tabs} active={tab} onChange={setTab} />

      {tab === "Business Info" ? (
        <Card className="space-y-3 p-4 text-sm">
          <InfoRow icon={Building2} label="Business" value="Sweet Heat BBQ LLC" />
          <InfoRow icon={Sparkles} label="Specialty" value="Smoked brisket & ribs" />
          <InfoRow icon={CalendarCheck} label="Member since" value="2023" />
        </Card>
      ) : tab === "Photos" ? (
        <div className="grid grid-cols-3 gap-2">
          {[1, 2, 3, 4, 5].map((i) => (
            <div
              key={i}
              className="flex aspect-square items-center justify-center rounded-2xl bg-secondary text-muted-foreground"
            >
              <Camera className="size-5" />
            </div>
          ))}
          <button className="flex aspect-square items-center justify-center rounded-2xl border border-dashed border-border text-sm font-semibold text-muted-foreground">
            + Add
          </button>
        </div>
      ) : tab === "Documents" ? (
        <Card className="divide-y divide-border">
          {["Business license", "Certificate of insurance", "Food permit"].map(
            (d) => (
              <div key={d} className="flex items-center gap-3 p-4">
                <FileText className="size-5 text-muted-foreground" />
                <span className="flex-1 text-sm font-medium">{d}</span>
                <span className="rounded-full bg-success/15 px-2 py-0.5 text-xs font-semibold text-success">
                  Verified
                </span>
              </div>
            ),
          )}
        </Card>
      ) : tab === "Setup Needs" ? (
        <Card className="space-y-2 p-4 text-sm">
          {["10x10 tent", "2x 6ft tables", "Generator (5000W)", "Handwash station"].map(
            (n) => (
              <div key={n} className="flex items-center gap-2">
                <BadgeCheck className="size-4 text-lime-foreground" />
                {n}
              </div>
            ),
          )}
        </Card>
      ) : tab === "Reviews" ? (
        <Card className="divide-y divide-border">
          {[
            { by: "CultureWave Events", text: "Fantastic setup and lines all night." },
            { by: "Southern Social Co.", text: "Professional, on-time, crowd favorite." },
          ].map((r) => (
            <div key={r.by} className="p-4">
              <div className="flex items-center gap-2">
                <Star className="size-4 fill-lime text-lime" />
                <span className="text-sm font-semibold">{r.by}</span>
              </div>
              <p className="mt-1 text-sm text-muted-foreground">{r.text}</p>
            </div>
          ))}
        </Card>
      ) : (
        <Card className="space-y-3 p-4">
          {[
            { label: "Identity verified", done: true },
            { label: "Business license", done: true },
            { label: "Insurance on file", done: true },
            { label: "Payout method", done: false },
          ].map((v) => (
            <div key={v.label} className="flex items-center gap-3 text-sm">
              <ShieldCheck
                className={v.done ? "size-5 text-lime-foreground" : "size-5 text-muted-foreground"}
              />
              <span className="flex-1 font-medium">{v.label}</span>
              <span className="text-xs text-muted-foreground">
                {v.done ? "Complete" : "Pending"}
              </span>
            </div>
          ))}
        </Card>
      )}
    </div>
  )
}

function InfoRow({
  icon: Icon,
  label,
  value,
}: {
  icon: typeof Building2
  label: string
  value: string
}) {
  return (
    <div className="flex items-center gap-3">
      <Icon className="size-4 text-muted-foreground" />
      <span className="text-muted-foreground">{label}</span>
      <span className="ml-auto font-semibold">{value}</span>
    </div>
  )
}

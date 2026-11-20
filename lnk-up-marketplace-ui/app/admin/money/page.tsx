"use client"

import { DollarSign, TrendingUp, Percent, ArrowDownLeft, ArrowUpRight } from "lucide-react"
import { events } from "@/lib/data"
import { PageHeader } from "@/components/marketplace/page-header"
import { StatCard } from "@/components/marketplace/stat-card"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Separator } from "@/components/ui/separator"
import { StatusBadge } from "@/components/marketplace/badges"
import { DemoModeNotice } from "@/components/marketplace/demo-mode-notice"

const transactions = [
  { id: "t1", label: "Vendor fee — Houston Rodeo Weekend", org: "Southern Social Co.", amount: 395, fee: 40, type: "in" as const },
  { id: "t2", label: "Payout — Charlotte Night Market", org: "CultureWave Events", amount: 4070, fee: 407, type: "out" as const },
  { id: "t3", label: "Vendor fee — Atlanta Food Fest", org: "Southern Social Co.", amount: 320, fee: 32, type: "in" as const },
  { id: "t4", label: "Payout — Greensboro Block Party", org: "CultureWave Events", amount: 3750, fee: 375, type: "out" as const },
  { id: "t5", label: "Vendor fee — Miami Culture Pop-Up", org: "MarketHouse Collective", amount: 210, fee: 21, type: "in" as const },
]

export default function AdminMoneyPage() {
  const gmv = events.reduce((s, e) => s + e.revenue, 0)
  const revenue = Math.round(gmv * 0.1)

  return (
    <div className="flex flex-col gap-6">
      <PageHeader title="Money" subtitle="Platform revenue, take rate, and transaction flow." />
      <DemoModeNotice testId="admin-money-demo-notice">
        Demo sample only — platform money charts are not live. Use LNKup Financial Center for production totals.
      </DemoModeNotice>

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <StatCard label="GMV (all time)" value={`$${gmv.toLocaleString()}`} hint="+22% MoM" icon={DollarSign} accent />
        <StatCard label="Platform revenue" value={`$${revenue.toLocaleString()}`} hint="10% take rate" icon={TrendingUp} />
        <StatCard label="Take rate" value="10%" hint="Standard tier" icon={Percent} />
        <StatCard label="Avg. vendor fee" value="$250" hint="Across events" icon={DollarSign} />
      </div>

      <Card className="rounded-2xl">
        <CardHeader>
          <CardTitle className="text-base">Recent transactions</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col">
          {transactions.map((t, i) => (
            <div key={t.id}>
              {i > 0 && <Separator />}
              <div className="flex items-center gap-3 py-3">
                <span
                  className={
                    "flex size-10 shrink-0 items-center justify-center rounded-xl " +
                    (t.type === "in" ? "bg-success/15" : "bg-muted")
                  }
                >
                  {t.type === "in" ? (
                    <ArrowDownLeft className="size-5 text-success" />
                  ) : (
                    <ArrowUpRight className="size-5 text-muted-foreground" />
                  )}
                </span>
                <div className="flex flex-1 flex-col">
                  <span className="text-sm font-medium">{t.label}</span>
                  <span className="text-xs text-muted-foreground">{t.org}</span>
                </div>
                <div className="flex flex-col items-end gap-1">
                  <span className="font-heading text-sm font-bold">${t.amount.toLocaleString()}</span>
                  <StatusBadge variant="paid">+${t.fee} fee</StatusBadge>
                </div>
              </div>
            </div>
          ))}
        </CardContent>
      </Card>
    </div>
  )
}

"use client"

import type React from "react"
import { useState } from "react"
import { cn } from "@/lib/utils"
import {
  Lock,
  QrCode,
  Clock,
  MapPin,
  Car,
  Store,
  Phone,
  CheckCircle2,
  Circle,
  ArrowUpRight,
  ArrowDownRight,
} from "lucide-react"
import { Card } from "./primitives"
import { BottomSheet, ChipRow } from "./ui-kit"
import {
  type EventItem,
  VENDOR_CATEGORIES,
  formatCurrency,
} from "@/lib/data"

/* ---------- Event pass card (locked / unlocked) ---------- */
export function EventPassCard({
  event,
  unlocked,
  onPay,
}: {
  event: EventItem
  unlocked: boolean
  onPay?: () => void
}) {
  if (!unlocked) {
    return (
      <Card className="overflow-hidden">
        <div className="flex flex-col items-center gap-3 bg-secondary px-6 py-10 text-center">
          <div className="flex size-14 items-center justify-center rounded-2xl bg-background text-muted-foreground">
            <Lock className="size-6" />
          </div>
          <div>
            <h3 className="font-heading text-lg font-bold">
              Your vendor pass is locked
            </h3>
            <p className="mt-1 text-sm text-muted-foreground">
              Your vendor pass unlocks after payment.
            </p>
          </div>
          <button
            onClick={onPay}
            className="mt-1 rounded-full bg-lime px-6 py-3 text-sm font-semibold text-lime-foreground active:scale-95"
          >
            Pay {formatCurrency(event.fee)} to unlock
          </button>
        </div>
      </Card>
    )
  }

  const details = [
    { icon: Clock, label: "Load-in", value: "3:00 PM – 4:30 PM" },
    { icon: Store, label: "Booth", value: "Row C · Booth 14" },
    { icon: Car, label: "Parking", value: "Lot B · Vendor pass required" },
    { icon: MapPin, label: "Venue", value: `${event.venue}, ${event.city}` },
  ]

  return (
    <Card className="overflow-hidden">
      <div className="bg-foreground px-5 py-5 text-background">
        <div className="flex items-center justify-between">
          <div>
            <p className="text-xs font-medium text-background/60">Vendor Pass</p>
            <h3 className="font-heading text-lg font-bold">{event.name}</h3>
            <p className="text-sm text-background/70">
              {event.date} · {event.city}, {event.state}
            </p>
          </div>
          <span className="rounded-full bg-lime px-2.5 py-1 text-xs font-semibold text-lime-foreground">
            Booked
          </span>
        </div>
        <div className="mt-4 flex items-center gap-4 rounded-2xl bg-background p-4 text-foreground">
          <div className="flex size-20 items-center justify-center rounded-xl bg-secondary">
            <QrCode className="size-14" />
          </div>
          <div>
            <p className="text-sm font-semibold">You&apos;re booked</p>
            <p className="text-xs text-muted-foreground">
              Show this QR at vendor check-in
            </p>
            <p className="mt-1 font-mono text-xs text-muted-foreground">
              LNK-{event.id.slice(0, 4).toUpperCase()}-8821
            </p>
          </div>
        </div>
      </div>
      <div className="divide-y divide-border">
        {details.map((d) => (
          <div key={d.label} className="flex items-center gap-3 px-5 py-3">
            <div className="flex size-9 items-center justify-center rounded-xl bg-secondary text-foreground">
              <d.icon className="size-4" />
            </div>
            <div>
              <p className="text-xs text-muted-foreground">{d.label}</p>
              <p className="text-sm font-semibold">{d.value}</p>
            </div>
          </div>
        ))}
        <div className="flex items-center justify-between px-5 py-3">
          <div className="flex items-center gap-3">
            <div className="flex size-9 items-center justify-center rounded-xl bg-secondary text-foreground">
              <Phone className="size-4" />
            </div>
            <div>
              <p className="text-xs text-muted-foreground">Organizer contact</p>
              <p className="text-sm font-semibold">{event.organizer}</p>
            </div>
          </div>
          <button className="rounded-full border border-border px-3 py-1.5 text-xs font-semibold">
            Message
          </button>
        </div>
      </div>
    </Card>
  )
}

/* ---------- Wallet card ---------- */
export function WalletCard({
  label,
  amount,
  hint,
  tone = "default",
}: {
  label: string
  amount: number
  hint?: string
  tone?: "default" | "accent" | "pending"
}) {
  return (
    <div
      className={cn(
        "rounded-3xl border p-4",
        tone === "accent"
          ? "border-transparent bg-foreground text-background"
          : "border-border bg-card",
      )}
    >
      <p
        className={cn(
          "text-xs font-medium",
          tone === "accent" ? "text-background/60" : "text-muted-foreground",
        )}
      >
        {label}
      </p>
      <p className="mt-1 font-heading text-2xl font-bold">
        {formatCurrency(amount)}
      </p>
      {hint ? (
        <p
          className={cn(
            "mt-1 text-xs",
            tone === "accent" ? "text-background/60" : "text-muted-foreground",
          )}
        >
          {hint}
        </p>
      ) : null}
    </div>
  )
}

/* ---------- Transaction row ---------- */
export function TransactionRow({
  title,
  subtitle,
  amount,
  incoming,
}: {
  title: string
  subtitle: string
  amount: number
  incoming?: boolean
}) {
  return (
    <div className="flex items-center gap-3 py-3">
      <div
        className={cn(
          "flex size-10 items-center justify-center rounded-2xl",
          incoming ? "bg-success/15 text-success" : "bg-secondary text-foreground",
        )}
      >
        {incoming ? (
          <ArrowDownRight className="size-5" />
        ) : (
          <ArrowUpRight className="size-5" />
        )}
      </div>
      <div className="min-w-0 flex-1">
        <p className="truncate text-sm font-semibold">{title}</p>
        <p className="truncate text-xs text-muted-foreground">{subtitle}</p>
      </div>
      <span
        className={cn(
          "text-sm font-bold",
          incoming ? "text-success" : "text-foreground",
        )}
      >
        {incoming ? "+" : "−"}
        {formatCurrency(amount)}
      </span>
    </div>
  )
}

/* ---------- Launch checklist ---------- */
export type ChecklistItem = { label: string; done: boolean }

export function LaunchChecklist({ items }: { items: ChecklistItem[] }) {
  const done = items.filter((i) => i.done).length
  return (
    <Card className="p-4">
      <div className="flex items-center justify-between">
        <h3 className="font-heading text-base font-bold">Launch checklist</h3>
        <span className="text-sm font-semibold text-muted-foreground">
          {done}/{items.length}
        </span>
      </div>
      <ul className="mt-3 space-y-1">
        {items.map((item) => (
          <li key={item.label} className="flex items-center gap-3 py-1.5">
            {item.done ? (
              <CheckCircle2 className="size-5 shrink-0 text-lime" />
            ) : (
              <Circle className="size-5 shrink-0 text-muted-foreground" />
            )}
            <span
              className={cn(
                "text-sm",
                item.done
                  ? "text-muted-foreground line-through"
                  : "font-medium text-foreground",
              )}
            >
              {item.label}
            </span>
          </li>
        ))}
      </ul>
    </Card>
  )
}

/* ---------- Apply modal ---------- */
export function ApplyModal({
  event,
  open,
  onClose,
  onSubmit,
}: {
  event: EventItem | null
  open: boolean
  onClose: () => void
  onSubmit: () => void
}) {
  const [category, setCategory] = useState<string>("")
  const [message, setMessage] = useState("")
  const [ack, setAck] = useState(false)

  if (!event) return null

  return (
    <BottomSheet
      open={open}
      onClose={onClose}
      title="Apply with your LNKup profile"
      footer={
        <button
          disabled={!ack || !category}
          onClick={onSubmit}
          className="w-full rounded-full bg-foreground py-3 text-sm font-semibold text-background disabled:opacity-40"
        >
          Submit Application
        </button>
      }
    >
      <p className="text-sm text-muted-foreground">
        Your profile does most of the work. Add a short note and submit your
        application for free.
      </p>

      <div className="mt-4 rounded-2xl bg-secondary p-3">
        <p className="font-heading text-sm font-bold">{event.name}</p>
        <p className="text-xs text-muted-foreground">
          {event.date} · {formatCurrency(event.fee)} vendor fee
        </p>
      </div>

      <div className="mt-4">
        <label className="text-sm font-semibold">Category</label>
        <div className="mt-2">
          <ChipRow
            options={[...VENDOR_CATEGORIES].slice(0, 8)}
            selected={category ? [category] : []}
            onToggle={(c) => setCategory((prev) => (prev === c ? "" : c))}
          />
        </div>
      </div>

      <div className="mt-4">
        <label className="text-sm font-semibold" htmlFor="apply-msg">
          Short message
        </label>
        <textarea
          id="apply-msg"
          value={message}
          onChange={(e) => setMessage(e.target.value)}
          rows={3}
          placeholder="Tell the organizer what makes your setup a great fit."
          className="mt-2 w-full rounded-2xl border border-border bg-background p-3 text-sm outline-none focus:border-ring"
        />
      </div>

      <button className="mt-3 w-full rounded-2xl border border-dashed border-border py-3 text-sm font-medium text-muted-foreground">
        + Attach documents (optional)
      </button>

      <label className="mt-4 flex cursor-pointer items-start gap-3 rounded-2xl bg-secondary p-3">
        <input
          type="checkbox"
          checked={ack}
          onChange={(e) => setAck(e.target.checked)}
          className="mt-0.5 size-4 accent-lime"
        />
        <span className="text-sm text-foreground">
          I understand payment is only required if I am accepted.
        </span>
      </label>
    </BottomSheet>
  )
}

/* ---------- Filter drawer ---------- */
export function FilterDrawer({
  open,
  onClose,
  categories,
  selectedCategories,
  onToggleCategory,
  onClear,
  onApply,
}: {
  open: boolean
  onClose: () => void
  categories: string[]
  selectedCategories: string[]
  onToggleCategory: (c: string) => void
  onClear: () => void
  onApply: () => void
}) {
  const states = ["NC", "GA", "FL", "TX"]
  return (
    <BottomSheet
      open={open}
      onClose={onClose}
      title="Filters"
      footer={
        <div className="flex gap-2">
          <button
            onClick={onClear}
            className="flex-1 rounded-full border border-border py-3 text-sm font-semibold"
          >
            Clear all
          </button>
          <button
            onClick={onApply}
            className="flex-[2] rounded-full bg-foreground py-3 text-sm font-semibold text-background"
          >
            Show results
          </button>
        </div>
      }
    >
      <div>
        <p className="text-sm font-semibold">Categories</p>
        <div className="mt-2">
          <ChipRow
            options={categories}
            selected={selectedCategories}
            onToggle={onToggleCategory}
          />
        </div>
      </div>
      <div className="mt-5">
        <p className="text-sm font-semibold">State</p>
        <div className="mt-2 flex flex-wrap gap-2">
          {states.map((s) => (
            <span
              key={s}
              className="rounded-full border border-border px-3.5 py-1.5 text-sm font-medium text-muted-foreground"
            >
              {s}
            </span>
          ))}
        </div>
      </div>
      <div className="mt-5">
        <p className="text-sm font-semibold">Vendor fee</p>
        <div className="mt-3 flex items-center gap-3">
          <input
            type="range"
            min={100}
            max={300}
            defaultValue={300}
            className="w-full accent-lime"
          />
        </div>
        <div className="mt-1 flex justify-between text-xs text-muted-foreground">
          <span>$100</span>
          <span>$300+</span>
        </div>
      </div>
    </BottomSheet>
  )
}

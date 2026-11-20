"use client"

import type React from "react"
import { useEffect } from "react"
import { cn } from "@/lib/utils"
import { Star, X } from "lucide-react"

/* ---------- Scrollable tab bar ---------- */
export function TabBar({
  tabs,
  active,
  onChange,
  className,
}: {
  tabs: string[]
  active: string
  onChange: (t: string) => void
  className?: string
}) {
  return (
    <div className={cn("no-scrollbar -mx-4 overflow-x-auto px-4", className)}>
      <div className="flex w-max gap-2">
        {tabs.map((tab) => {
          const isActive = tab === active
          return (
            <button
              key={tab}
              onClick={() => onChange(tab)}
              className={cn(
                "whitespace-nowrap rounded-full px-4 py-2 text-sm font-semibold transition-colors",
                isActive
                  ? "bg-foreground text-background"
                  : "bg-secondary text-muted-foreground hover:text-foreground",
              )}
            >
              {tab}
            </button>
          )
        })}
      </div>
    </div>
  )
}

/* ---------- Chip row (multi-select filters) ---------- */
export function ChipRow({
  options,
  selected,
  onToggle,
  className,
}: {
  options: string[]
  selected: string[]
  onToggle: (opt: string) => void
  className?: string
}) {
  return (
    <div className={cn("no-scrollbar -mx-4 overflow-x-auto px-4", className)}>
      <div className="flex w-max gap-2">
        {options.map((opt) => {
          const isActive = selected.includes(opt)
          return (
            <button
              key={opt}
              onClick={() => onToggle(opt)}
              className={cn(
                "whitespace-nowrap rounded-full border px-3.5 py-1.5 text-sm font-medium transition-colors",
                isActive
                  ? "border-transparent bg-lime text-lime-foreground"
                  : "border-border bg-card text-muted-foreground hover:text-foreground",
              )}
            >
              {opt}
            </button>
          )
        })}
      </div>
    </div>
  )
}

/* ---------- Bottom sheet / drawer ---------- */
export function BottomSheet({
  open,
  onClose,
  title,
  children,
  footer,
}: {
  open: boolean
  onClose: () => void
  title: string
  children: React.ReactNode
  footer?: React.ReactNode
}) {
  useEffect(() => {
    if (!open) return
    document.body.style.overflow = "hidden"
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") onClose()
    }
    window.addEventListener("keydown", onKey)
    return () => {
      document.body.style.overflow = ""
      window.removeEventListener("keydown", onKey)
    }
  }, [open, onClose])

  if (!open) return null

  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center sm:items-center">
      <button
        aria-label="Close"
        onClick={onClose}
        className="absolute inset-0 bg-foreground/40 backdrop-blur-sm"
      />
      <div className="relative z-10 flex max-h-[88vh] w-full flex-col rounded-t-3xl border border-border bg-card shadow-2xl sm:max-w-lg sm:rounded-3xl">
        <div className="flex items-center justify-between border-b border-border px-5 py-4">
          <h3 className="font-heading text-lg font-bold">{title}</h3>
          <button
            onClick={onClose}
            className="flex size-8 items-center justify-center rounded-full bg-secondary text-muted-foreground hover:text-foreground"
          >
            <X className="size-4" />
          </button>
        </div>
        <div className="flex-1 overflow-y-auto px-5 py-4">{children}</div>
        {footer ? (
          <div className="border-t border-border px-5 py-4">{footer}</div>
        ) : null}
      </div>
    </div>
  )
}

/* ---------- Star rating (display) ---------- */
export function Rating({ value }: { value: number }) {
  return (
    <span className="inline-flex items-center gap-1 text-sm font-semibold">
      <Star className="size-4 fill-lime text-lime" />
      {value.toFixed(1)}
    </span>
  )
}

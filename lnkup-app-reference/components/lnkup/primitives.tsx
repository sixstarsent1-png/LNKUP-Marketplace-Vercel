import type React from "react"
import { cn } from "@/lib/utils"
import { BadgeCheck, type LucideIcon } from "lucide-react"
import { type RequestStatus, statusLabel } from "@/lib/data"

/* ---------- Card ---------- */
export function Card({
  className,
  children,
  ...props
}: React.HTMLAttributes<HTMLDivElement>) {
  return (
    <div
      className={cn(
        "rounded-3xl border border-border bg-card text-card-foreground shadow-sm",
        className,
      )}
      {...props}
    >
      {children}
    </div>
  )
}

/* ---------- Section header ---------- */
export function SectionHeader({
  title,
  action,
  className,
}: {
  title: string
  action?: React.ReactNode
  className?: string
}) {
  return (
    <div className={cn("mb-3 flex items-center justify-between gap-3", className)}>
      <h2 className="font-heading text-lg font-bold text-foreground">{title}</h2>
      {action}
    </div>
  )
}

/* ---------- Status badge ---------- */
const statusStyles: Record<RequestStatus, string> = {
  under_review: "bg-secondary text-muted-foreground",
  approved: "bg-foreground text-background",
  payment_due: "bg-warning/15 text-warning",
  paid: "bg-success/15 text-success",
  waitlisted: "bg-secondary text-foreground",
  rejected: "bg-destructive/10 text-destructive",
}

export function StatusBadge({ status }: { status: RequestStatus }) {
  return (
    <span
      className={cn(
        "inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold",
        statusStyles[status],
      )}
    >
      {statusLabel(status)}
    </span>
  )
}

/* ---------- Verification badge ---------- */
export function VerificationBadge({
  label = "Verified",
  className,
}: {
  label?: string
  className?: string
}) {
  return (
    <span
      className={cn(
        "inline-flex items-center gap-1 rounded-full bg-lime px-2 py-0.5 text-xs font-semibold text-lime-foreground",
        className,
      )}
    >
      <BadgeCheck className="size-3.5" />
      {label}
    </span>
  )
}

/* ---------- Pill / tag ---------- */
export function Pill({
  children,
  className,
}: {
  children: React.ReactNode
  className?: string
}) {
  return (
    <span
      className={cn(
        "inline-flex items-center rounded-full bg-secondary px-2.5 py-1 text-xs font-medium text-secondary-foreground",
        className,
      )}
    >
      {children}
    </span>
  )
}

/* ---------- Stat card ---------- */
export function StatCard({
  label,
  value,
  icon: Icon,
  hint,
  accent,
  className,
}: {
  label: string
  value: string
  icon?: LucideIcon
  hint?: string
  accent?: boolean
  className?: string
}) {
  return (
    <div
      className={cn(
        "rounded-3xl border p-4",
        accent
          ? "border-transparent bg-foreground text-background"
          : "border-border bg-card text-card-foreground",
        className,
      )}
    >
      <div className="flex items-center justify-between">
        <span
          className={cn(
            "text-xs font-medium",
            accent ? "text-background/70" : "text-muted-foreground",
          )}
        >
          {label}
        </span>
        {Icon ? (
          <Icon
            className={cn(
              "size-4",
              accent ? "text-lime" : "text-muted-foreground",
            )}
          />
        ) : null}
      </div>
      <div className="mt-2 font-heading text-2xl font-bold tracking-tight">
        {value}
      </div>
      {hint ? (
        <div
          className={cn(
            "mt-1 text-xs",
            accent ? "text-background/60" : "text-muted-foreground",
          )}
        >
          {hint}
        </div>
      ) : null}
    </div>
  )
}

/* ---------- Progress ring ---------- */
export function ProgressRing({
  value,
  size = 64,
  stroke = 6,
  label,
}: {
  value: number
  size?: number
  stroke?: number
  label?: string
}) {
  const radius = (size - stroke) / 2
  const circumference = 2 * Math.PI * radius
  const offset = circumference - (value / 100) * circumference
  return (
    <div
      className="relative inline-flex items-center justify-center"
      style={{ width: size, height: size }}
    >
      <svg width={size} height={size} className="-rotate-90">
        <circle
          cx={size / 2}
          cy={size / 2}
          r={radius}
          fill="none"
          strokeWidth={stroke}
          className="stroke-secondary"
        />
        <circle
          cx={size / 2}
          cy={size / 2}
          r={radius}
          fill="none"
          strokeWidth={stroke}
          strokeDasharray={circumference}
          strokeDashoffset={offset}
          strokeLinecap="round"
          className="stroke-lime transition-all duration-500"
        />
      </svg>
      <span className="absolute font-heading text-sm font-bold">
        {label ?? `${value}%`}
      </span>
    </div>
  )
}

/* ---------- Progress bar ---------- */
export function ProgressBar({
  value,
  className,
}: {
  value: number
  className?: string
}) {
  return (
    <div className={cn("h-2 w-full overflow-hidden rounded-full bg-secondary", className)}>
      <div
        className="h-full rounded-full bg-lime transition-all duration-500"
        style={{ width: `${Math.min(100, Math.max(0, value))}%` }}
      />
    </div>
  )
}

/* ---------- Empty state ---------- */
export function EmptyState({
  icon: Icon,
  title,
  description,
  action,
}: {
  icon: LucideIcon
  title: string
  description: string
  action?: React.ReactNode
}) {
  return (
    <div className="flex flex-col items-center justify-center rounded-3xl border border-dashed border-border bg-card px-6 py-12 text-center">
      <div className="flex size-12 items-center justify-center rounded-2xl bg-secondary text-muted-foreground">
        <Icon className="size-6" />
      </div>
      <h3 className="mt-4 font-heading text-base font-semibold">{title}</h3>
      <p className="mt-1 max-w-xs text-pretty text-sm text-muted-foreground">
        {description}
      </p>
      {action ? <div className="mt-4">{action}</div> : null}
    </div>
  )
}

/* ---------- Error state ---------- */
export function ErrorState({
  title = "Something went wrong",
  description = "We couldn't load this right now. Please try again.",
  onRetry,
}: {
  title?: string
  description?: string
  onRetry?: () => void
}) {
  return (
    <div className="flex flex-col items-center justify-center rounded-3xl border border-destructive/20 bg-destructive/5 px-6 py-12 text-center">
      <h3 className="font-heading text-base font-semibold text-destructive">
        {title}
      </h3>
      <p className="mt-1 max-w-xs text-pretty text-sm text-muted-foreground">
        {description}
      </p>
      {onRetry ? (
        <button
          onClick={onRetry}
          className="mt-4 rounded-full bg-foreground px-4 py-2 text-sm font-semibold text-background"
        >
          Try again
        </button>
      ) : null}
    </div>
  )
}

/* ---------- Skeleton card ---------- */
export function SkeletonCard() {
  return (
    <div className="overflow-hidden rounded-3xl border border-border bg-card">
      <div className="h-36 w-full animate-pulse bg-secondary" />
      <div className="space-y-3 p-4">
        <div className="h-4 w-2/3 animate-pulse rounded-full bg-secondary" />
        <div className="h-3 w-1/2 animate-pulse rounded-full bg-secondary" />
        <div className="flex gap-2 pt-2">
          <div className="h-8 w-full animate-pulse rounded-full bg-secondary" />
          <div className="h-8 w-16 animate-pulse rounded-full bg-secondary" />
        </div>
      </div>
    </div>
  )
}

export function SkeletonRow() {
  return (
    <div className="flex items-center gap-3 rounded-3xl border border-border bg-card p-4">
      <div className="size-11 animate-pulse rounded-2xl bg-secondary" />
      <div className="flex-1 space-y-2">
        <div className="h-3.5 w-1/2 animate-pulse rounded-full bg-secondary" />
        <div className="h-3 w-1/3 animate-pulse rounded-full bg-secondary" />
      </div>
    </div>
  )
}

import { BadgeCheck, ShieldCheck, FileCheck2, Clock } from "lucide-react"
import { cn } from "@/lib/utils"

type StatusVariant = "review" | "approved" | "due" | "paid" | "waitlist" | "rejected" | "live" | "closed" | "draft"

const statusStyles: Record<StatusVariant, string> = {
  review: "bg-muted text-foreground",
  approved: "bg-accent text-accent-foreground",
  due: "bg-warning/15 text-warning",
  paid: "bg-success/15 text-success",
  waitlist: "bg-primary/10 text-foreground",
  rejected: "bg-destructive/10 text-destructive",
  live: "bg-accent text-accent-foreground",
  closed: "bg-muted text-muted-foreground",
  draft: "bg-secondary text-secondary-foreground",
}

export function StatusBadge({
  variant,
  children,
  className,
}: {
  variant: StatusVariant
  children: React.ReactNode
  className?: string
}) {
  return (
    <span
      className={cn(
        "inline-flex h-6 w-fit shrink-0 items-center gap-1 rounded-full px-2.5 text-xs font-semibold whitespace-nowrap",
        statusStyles[variant],
        className,
      )}
    >
      {variant === "due" && <Clock className="size-3" />}
      {children}
    </span>
  )
}

export function VerificationBadge({
  label = "Verified",
  type = "check",
  className,
}: {
  label?: string
  type?: "check" | "shield" | "doc"
  className?: string
}) {
  const Icon = type === "shield" ? ShieldCheck : type === "doc" ? FileCheck2 : BadgeCheck
  return (
    <span
      className={cn(
        "inline-flex h-6 w-fit items-center gap-1 rounded-full bg-primary px-2.5 text-xs font-semibold text-primary-foreground",
        className,
      )}
    >
      <Icon className="size-3" />
      {label}
    </span>
  )
}

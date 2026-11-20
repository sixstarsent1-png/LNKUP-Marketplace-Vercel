import Link from "next/link"
import type { LucideIcon } from "lucide-react"
import {
  Calendar,
  CheckCircle2,
  FileWarning,
  Search,
  Send,
  Star,
  Ticket,
  User,
  Wallet,
} from "lucide-react"
import { cn } from "@/lib/utils"
import { Surface } from "@/components/vendor/surface"
import { Button } from "@/components/ui/button"
import type { VendorRetentionCard } from "@/lib/api/notifications"
import type { VendorNextAction } from "@/lib/vendor/next-action"

export type ActionCenterLevel = "urgent" | "warning" | "info" | "success"

export type ActionCenterItem = {
  id: string
  title: string
  meta?: string
  href?: string
  actionLabel?: string
  level?: ActionCenterLevel
  icon?: LucideIcon
  primary?: boolean
  onClick?: () => void
  /** Used for e2e: retention-card-{type} */
  retentionType?: VendorRetentionCard["type"]
}

const levelDotClass: Record<ActionCenterLevel, string> = {
  urgent: "bg-destructive",
  warning: "bg-warning",
  info: "bg-muted-foreground/40",
  success: "bg-success",
}

const retentionIconMap: Record<VendorRetentionCard["type"], LucideIcon> = {
  payment_required: Wallet,
  event_day: Calendar,
  packet_unlocked: Ticket,
  application_update: Send,
  document_reminder: FileWarning,
  saved_search_match: Search,
  review_update: Star,
  profile_completion: User,
  pwa_install: CheckCircle2,
  system: FileWarning,
}

const nextActionIconMap: Record<VendorNextAction["priority"], LucideIcon> = {
  payment: Wallet,
  pass: Ticket,
  event_day: Calendar,
  documents: FileWarning,
  reputation: Star,
  review: Send,
  onboarding: User,
  profile: User,
  discover: Search,
}

function severityToLevel(
  severity?: VendorRetentionCard["severity"],
): ActionCenterLevel {
  if (severity === "urgent") return "urgent"
  if (severity === "warning") return "warning"
  if (severity === "success") return "success"
  return "info"
}

/**
 * Maps retention cards into Action Center rows, dropping soft/PWA nudges.
 */
export function retentionCardsToActionItems(
  cards: VendorRetentionCard[],
): ActionCenterItem[] {
  return cards
    .filter((card) => card.type !== "pwa_install")
    .map((card) => ({
      id: card.id,
      title: card.title,
      meta: card.description,
      href: card.href,
      actionLabel: card.actionLabel ?? "View",
      level: severityToLevel(card.severity),
      icon: retentionIconMap[card.type] ?? FileWarning,
      primary: card.severity === "urgent" || card.severity === "warning",
      retentionType: card.type,
    }))
}

/**
 * Ensures the primary next action appears in the Action Center when not already covered.
 */
export function mergeNextActionIntoItems(
  items: ActionCenterItem[],
  nextAction: VendorNextAction,
): ActionCenterItem[] {
  const urgentPriorities = new Set([
    "payment",
    "event_day",
    "documents",
    "onboarding",
    "profile",
  ])
  if (!urgentPriorities.has(nextAction.priority)) return items

  const alreadyCovered = items.some(
    (item) =>
      item.href === nextAction.href ||
      item.title.toLowerCase().includes(nextAction.label.toLowerCase().slice(0, 12)),
  )
  if (alreadyCovered) return items

  return [
    {
      id: `next-action-${nextAction.priority}`,
      title: nextAction.label,
      meta: nextAction.description,
      href: nextAction.href,
      actionLabel: nextAction.label,
      level:
        nextAction.priority === "payment" || nextAction.priority === "event_day"
          ? "urgent"
          : "warning",
      icon: nextActionIconMap[nextAction.priority],
      primary: true,
    },
    ...items,
  ]
}

export function ActionCenter({
  items,
  title = "Action Center",
  subtitle = "What requires your attention today",
  showEmpty = true,
  className,
  testId = "action-center",
}: {
  items: ActionCenterItem[]
  title?: string
  subtitle?: string
  showEmpty?: boolean
  className?: string
  testId?: string
}) {
  if (items.length === 0 && !showEmpty) return null

  return (
    <Surface
      variant="primary"
      className={cn("flex flex-col gap-4", className)}
      data-testid={testId}
    >
      <div className="flex flex-col gap-1">
        <h2 className="font-heading text-lg font-bold tracking-tight">{title}</h2>
        {subtitle && <p className="text-sm text-muted-foreground">{subtitle}</p>}
      </div>

      {items.length === 0 ? (
        <p className="rounded-xl bg-muted/50 px-4 py-5 text-sm text-muted-foreground" data-testid={`${testId}-empty`}>
          You&apos;re all caught up. No urgent actions today — browse events or strengthen your
          profile.
        </p>
      ) : (
        <ul className="flex flex-col gap-2" data-testid={`${testId}-list`}>
          {items.map((item) => {
            const Icon = item.icon ?? FileWarning
            const level = item.level ?? "info"
            return (
              <li
                key={item.id}
                className="flex items-center gap-3 rounded-xl border border-border/70 bg-background/60 px-3 py-3 transition-transform hover:-translate-y-0.5"
                data-testid={
                  item.retentionType
                    ? `retention-card-${item.retentionType}`
                    : `${testId}-item`
                }
              >
                <span
                  className={cn("size-2 shrink-0 rounded-full", levelDotClass[level])}
                  aria-hidden
                />
                <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted">
                  <Icon className="size-4 text-foreground" aria-hidden />
                </span>
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-semibold">{item.title}</p>
                  {item.meta && (
                    <p className="truncate text-xs text-muted-foreground">{item.meta}</p>
                  )}
                </div>
                {item.href && (
                  <Button
                    size="sm"
                    variant={item.primary ? "default" : "ghost"}
                    className="min-h-11 shrink-0"
                    nativeButton={false}
                    render={
                      item.href.startsWith("/") ? (
                        <Link href={item.href} />
                      ) : (
                        <a href={item.href} target="_blank" rel="noopener noreferrer" />
                      )
                    }
                    onClick={item.onClick}
                  >
                    {item.actionLabel ?? "View"}
                  </Button>
                )}
              </li>
            )
          })}
        </ul>
      )}
    </Surface>
  )
}

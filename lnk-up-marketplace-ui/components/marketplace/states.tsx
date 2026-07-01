import type { LucideIcon } from "lucide-react"
import { AlertTriangle, RefreshCw } from "lucide-react"
import { Card } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { Skeleton } from "@/components/ui/skeleton"
import { cn } from "@/lib/utils"
import type { PanelState } from "@/lib/use-panel-state"

export function EmptyState({
  icon: Icon,
  title,
  description,
  action,
  className,
}: {
  icon: LucideIcon
  title: string
  description: string
  action?: React.ReactNode
  className?: string
}) {
  return (
    <Card
      className={cn(
        "items-center gap-3 rounded-2xl border-dashed py-10 text-center",
        className,
      )}
    >
      <span className="flex size-12 items-center justify-center rounded-full bg-muted">
        <Icon className="size-6 text-muted-foreground" />
      </span>
      <div className="flex flex-col gap-1 px-6">
        <h3 className="text-base font-semibold">{title}</h3>
        <p className="text-pretty text-sm text-muted-foreground">{description}</p>
      </div>
      {action}
    </Card>
  )
}

export function ErrorState({
  title = "Something went wrong",
  description = "We couldn't load this right now. Give it another try.",
  onRetry,
  className,
}: {
  title?: string
  description?: string
  onRetry?: () => void
  className?: string
}) {
  return (
    <Card
      className={cn("items-center gap-3 rounded-2xl py-10 text-center", className)}
    >
      <span className="flex size-12 items-center justify-center rounded-full bg-destructive/10">
        <AlertTriangle className="size-6 text-destructive" />
      </span>
      <div className="flex flex-col gap-1 px-6">
        <h3 className="text-base font-semibold">{title}</h3>
        <p className="text-pretty text-sm text-muted-foreground">{description}</p>
      </div>
      {onRetry && (
        <Button variant="outline" size="sm" onClick={onRetry}>
          <RefreshCw data-icon="inline-start" />
          Try again
        </Button>
      )}
    </Card>
  )
}

export function SkeletonCard({ className }: { className?: string }) {
  return (
    <Card className={cn("gap-3 overflow-hidden rounded-2xl p-0", className)}>
      <Skeleton className="h-36 w-full rounded-none" />
      <div className="flex flex-col gap-2 p-4">
        <Skeleton className="h-4 w-2/3" />
        <Skeleton className="h-3 w-1/2" />
        <div className="mt-2 flex gap-2">
          <Skeleton className="h-6 w-16 rounded-full" />
          <Skeleton className="h-6 w-20 rounded-full" />
        </div>
        <Skeleton className="mt-2 h-9 w-full rounded-xl" />
      </div>
    </Card>
  )
}

export function SkeletonList({ count = 3 }: { count?: number }) {
  return (
    <div className="grid gap-4">
      {Array.from({ length: count }).map((_, i) => (
        <SkeletonCard key={i} />
      ))}
    </div>
  )
}

export function PanelContent({
  state,
  retry,
  loading,
  empty,
  error,
  children,
}: {
  state: PanelState
  retry?: () => void
  loading?: React.ReactNode
  empty?: React.ReactNode
  error?: React.ReactNode
  children: React.ReactNode
}) {
  if (state === "loading") return <>{loading ?? <SkeletonList />}</>
  if (state === "error") return <>{error ?? <ErrorState onRetry={retry} />}</>
  if (state === "empty") return <>{empty}</>
  return <>{children}</>
}

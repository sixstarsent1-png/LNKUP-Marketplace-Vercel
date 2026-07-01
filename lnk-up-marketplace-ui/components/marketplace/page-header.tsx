import Link from "next/link"
import { cn } from "@/lib/utils"

export function PageHeader({
  title,
  subtitle,
  action,
  className,
}: {
  title: string
  subtitle?: string
  action?: React.ReactNode
  className?: string
}) {
  return (
    <div className={cn("mb-4 flex items-start justify-between gap-3", className)}>
      <div className="flex flex-col gap-1">
        <h1 className="text-balance font-heading text-2xl font-bold tracking-tight md:text-3xl">
          {title}
        </h1>
        {subtitle && (
          <p className="text-pretty text-sm text-muted-foreground">{subtitle}</p>
        )}
      </div>
      {action}
    </div>
  )
}

export function SectionHeader({
  title,
  href,
  linkLabel = "See all",
}: {
  title: string
  href?: string
  linkLabel?: string
}) {
  return (
    <div className="mb-3 flex items-center justify-between">
      <h2 className="font-heading text-lg font-semibold">{title}</h2>
      {href && (
        <Link href={href} className="text-sm font-medium text-muted-foreground hover:text-foreground">
          {linkLabel}
        </Link>
      )}
    </div>
  )
}

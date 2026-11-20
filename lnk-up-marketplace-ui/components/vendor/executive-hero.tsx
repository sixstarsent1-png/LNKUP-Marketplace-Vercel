import Link from "next/link"
import { ArrowRight } from "lucide-react"
import { cn } from "@/lib/utils"
import { Surface } from "@/components/vendor/surface"
import { Button } from "@/components/ui/button"

export type ExecutiveHeroMetric = {
  label: string
  value: string
}

export type ExecutiveHeroCta = {
  label: string
  href: string
  onClick?: () => void
}

export function ExecutiveHero({
  title,
  subtitle,
  metrics,
  primaryCta,
  secondaryCta,
  className,
  testId = "executive-hero",
}: {
  title: string
  subtitle?: string
  metrics?: ExecutiveHeroMetric[]
  primaryCta?: ExecutiveHeroCta
  secondaryCta?: ExecutiveHeroCta
  className?: string
  testId?: string
}) {
  const hasCtas = Boolean(primaryCta || secondaryCta)

  return (
    <Surface variant="hero" className={cn("flex flex-col gap-5", className)} data-testid={testId}>
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="flex min-w-0 flex-col gap-1.5">
          <h1 className="text-balance font-heading text-2xl font-extrabold tracking-tight md:text-3xl">
            {title}
          </h1>
          {subtitle && (
            <p className="text-pretty text-sm text-muted-foreground md:text-[0.9375rem]">
              {subtitle}
            </p>
          )}
        </div>

        {hasCtas && (
          <div className="flex flex-wrap gap-2 sm:shrink-0">
            {primaryCta && (
              <Button
                className="min-h-11"
                nativeButton={false}
                render={<Link href={primaryCta.href} />}
                onClick={primaryCta.onClick}
              >
                {primaryCta.label}
                <ArrowRight data-icon="inline-end" />
              </Button>
            )}
            {secondaryCta && (
              <Button
                variant="outline"
                className="min-h-11"
                nativeButton={false}
                render={<Link href={secondaryCta.href} />}
                onClick={secondaryCta.onClick}
              >
                {secondaryCta.label}
              </Button>
            )}
          </div>
        )}
      </div>

      {metrics && metrics.length > 0 && (
        <div className="flex flex-wrap gap-2" data-testid={`${testId}-metrics`}>
          {metrics.map((metric) => (
            <span
              key={`${metric.label}-${metric.value}`}
              className="inline-flex items-center gap-1.5 rounded-full bg-muted/70 px-3 py-1.5 text-xs font-medium text-muted-foreground"
            >
              {metric.label}{" "}
              <strong className="font-bold text-foreground">{metric.value}</strong>
            </span>
          ))}
        </div>
      )}
    </Surface>
  )
}

export function greetingLabel(date = new Date()): string {
  const hour = date.getHours()
  if (hour < 12) return "Good morning"
  if (hour < 17) return "Good afternoon"
  return "Good evening"
}

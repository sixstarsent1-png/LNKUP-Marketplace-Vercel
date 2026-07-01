import { cn } from "@/lib/utils"

export function ProgressRing({
  value,
  size = 56,
  strokeWidth = 6,
  label,
  className,
}: {
  value: number
  size?: number
  strokeWidth?: number
  label?: string
  className?: string
}) {
  const radius = (size - strokeWidth) / 2
  const circumference = 2 * Math.PI * radius
  const offset = circumference - (value / 100) * circumference

  return (
    <div className={cn("relative inline-flex items-center justify-center", className)}>
      <svg width={size} height={size} className="-rotate-90">
        <circle
          cx={size / 2}
          cy={size / 2}
          r={radius}
          fill="none"
          strokeWidth={strokeWidth}
          className="stroke-muted"
        />
        <circle
          cx={size / 2}
          cy={size / 2}
          r={radius}
          fill="none"
          strokeWidth={strokeWidth}
          strokeDasharray={circumference}
          strokeDashoffset={offset}
          strokeLinecap="round"
          className={cn(
            value >= 70 ? "stroke-success" : value >= 45 ? "stroke-accent" : "stroke-warning",
          )}
        />
      </svg>
      <span className="absolute font-heading text-sm font-bold">
        {label ?? `${value}%`}
      </span>
    </div>
  )
}

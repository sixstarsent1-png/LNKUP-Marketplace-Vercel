import { cva, type VariantProps } from "class-variance-authority"
import { cn } from "@/lib/utils"

const surfaceVariants = cva("rounded-2xl", {
  variants: {
    variant: {
      hero: "border border-border bg-gradient-to-br from-accent/10 via-card to-card p-5 shadow-none md:p-6",
      primary: "border border-border bg-card p-5 shadow-sm",
      secondary: "border border-border/80 bg-card p-4 shadow-none",
      quiet: "border-0 bg-muted/40 p-5 shadow-none",
    },
  },
  defaultVariants: {
    variant: "primary",
  },
})

export type SurfaceVariant = NonNullable<VariantProps<typeof surfaceVariants>["variant"]>

export function Surface({
  variant = "primary",
  className,
  children,
  ...props
}: React.ComponentProps<"div"> & VariantProps<typeof surfaceVariants>) {
  return (
    <div
      data-slot="surface"
      data-variant={variant}
      className={cn(surfaceVariants({ variant }), className)}
      {...props}
    >
      {children}
    </div>
  )
}

export { surfaceVariants }

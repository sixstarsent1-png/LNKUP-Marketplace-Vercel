"use client"

import { useState } from "react"
import { SlidersHorizontal } from "lucide-react"
import { vendorCategories } from "@/lib/data"
import {
  Sheet,
  SheetContent,
  SheetFooter,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
  SheetClose,
} from "@/components/ui/sheet"
import { Button } from "@/components/ui/button"
import { cn } from "@/lib/utils"

const feeRanges = ["Under $150", "$150–$250", "$250–$400", "$400+"]
const attendance = ["Under 3k", "3k–6k", "6k–10k", "10k+"]

export function FilterDrawer() {
  const [cats, setCats] = useState<string[]>([])
  const [fee, setFee] = useState<string | null>(null)
  const [size, setSize] = useState<string | null>(null)

  function toggleCat(c: string) {
    setCats((prev) => (prev.includes(c) ? prev.filter((x) => x !== c) : [...prev, c]))
  }

  const activeCount = cats.length + (fee ? 1 : 0) + (size ? 1 : 0)

  return (
    <Sheet>
      <SheetTrigger
        render={
          <Button variant="outline" size="icon" aria-label="Filters">
            <SlidersHorizontal />
          </Button>
        }
      />
      <SheetContent side="bottom" className="max-h-[85vh] rounded-t-3xl">
        <SheetHeader>
          <SheetTitle>Filters</SheetTitle>
        </SheetHeader>

        <div className="flex flex-col gap-5 overflow-y-auto px-4">
          <FilterGroup title="Category">
            {vendorCategories.slice(0, 9).map((c) => (
              <Chip key={c} active={cats.includes(c)} onClick={() => toggleCat(c)}>
                {c}
              </Chip>
            ))}
          </FilterGroup>

          <FilterGroup title="Vendor fee">
            {feeRanges.map((f) => (
              <Chip key={f} active={fee === f} onClick={() => setFee(fee === f ? null : f)}>
                {f}
              </Chip>
            ))}
          </FilterGroup>

          <FilterGroup title="Expected attendance">
            {attendance.map((a) => (
              <Chip key={a} active={size === a} onClick={() => setSize(size === a ? null : a)}>
                {a}
              </Chip>
            ))}
          </FilterGroup>
        </div>

        <SheetFooter className="flex-row gap-2">
          <Button
            variant="outline"
            className="flex-1"
            onClick={() => {
              setCats([])
              setFee(null)
              setSize(null)
            }}
          >
            Clear
          </Button>
          <SheetClose render={<Button className="flex-1" />}>
            Show results{activeCount > 0 ? ` (${activeCount})` : ""}
          </SheetClose>
        </SheetFooter>
      </SheetContent>
    </Sheet>
  )
}

function FilterGroup({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-2">
      <h4 className="text-sm font-semibold">{title}</h4>
      <div className="flex flex-wrap gap-2">{children}</div>
    </div>
  )
}

function Chip({
  active,
  onClick,
  children,
}: {
  active: boolean
  onClick: () => void
  children: React.ReactNode
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={cn(
        "rounded-full border px-3 py-1.5 text-sm font-medium transition-colors",
        active
          ? "border-primary bg-primary text-primary-foreground"
          : "border-border bg-background text-foreground hover:bg-muted",
      )}
    >
      {children}
    </button>
  )
}

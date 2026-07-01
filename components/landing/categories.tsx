import { vendorCategories } from '@/lib/mock-data'
import { categoryIcons } from '@/lib/category-icons'

export function Categories() {
  return (
    <section id="categories" className="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8 lg:py-24">
      <div className="flex flex-col items-end justify-between gap-4 sm:flex-row">
        <div className="max-w-2xl">
          <p className="text-sm font-semibold uppercase tracking-wider text-accent-foreground/70">
            Vendor categories
          </p>
          <h2 className="mt-3 text-pretty font-display text-3xl font-extrabold tracking-tight sm:text-4xl">
            Whatever you bring to the event
          </h2>
        </div>
        <p className="text-sm text-muted-foreground">
          11 categories &middot; more launching soon
        </p>
      </div>

      <div className="mt-10 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
        {vendorCategories.map((cat) => {
          const Icon = categoryIcons[cat.name]
          return (
            <div
              key={cat.name}
              className="group flex items-center gap-3 rounded-2xl border border-border bg-card p-4 transition-all hover:-translate-y-0.5 hover:border-foreground/20 hover:shadow-md"
            >
              <span className="grid size-11 shrink-0 place-items-center rounded-xl bg-muted text-foreground transition-colors group-hover:bg-accent group-hover:text-accent-foreground">
                <Icon className="size-5" />
              </span>
              <span className="font-medium">{cat.name}</span>
            </div>
          )
        })}
      </div>
    </section>
  )
}

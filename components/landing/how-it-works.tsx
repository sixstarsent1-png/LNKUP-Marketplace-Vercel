import { UserPlus, Search, Send, CreditCard } from 'lucide-react'

const steps = [
  {
    icon: UserPlus,
    title: 'Create Your Vendor Profile',
    desc: 'Build a profile that shows organizers who you are, what you sell, and where you set up.',
  },
  {
    icon: Search,
    title: 'Browse Event Opportunities',
    desc: 'Filter by city, date, attendance, and vendor fee to find events that fit your business.',
  },
  {
    icon: Send,
    title: 'Apply For Free',
    desc: 'Submit applications to as many events as you want — applying never costs a thing.',
  },
  {
    icon: CreditCard,
    title: 'Pay Only When Accepted',
    desc: 'No upfront fees. You only pay your vendor spot once an organizer approves you.',
  },
]

export function HowItWorks() {
  return (
    <section id="how-it-works" className="border-y border-border bg-muted/40">
      <div className="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8 lg:py-24">
        <div className="max-w-2xl">
          <p className="text-sm font-semibold uppercase tracking-wider text-accent-foreground/70">
            How it works
          </p>
          <h2 className="mt-3 text-pretty font-display text-3xl font-extrabold tracking-tight sm:text-4xl">
            From profile to paid in four steps
          </h2>
        </div>

        <ol className="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
          {steps.map((step, i) => (
            <li
              key={step.title}
              className="group relative flex flex-col rounded-2xl border border-border bg-card p-6 transition-shadow hover:shadow-lg hover:shadow-foreground/5"
            >
              <div className="flex items-center justify-between">
                <span className="grid size-11 place-items-center rounded-xl bg-primary text-primary-foreground">
                  <step.icon className="size-5" />
                </span>
                <span className="font-display text-3xl font-extrabold text-border transition-colors group-hover:text-accent">
                  0{i + 1}
                </span>
              </div>
              <h3 className="mt-5 font-display text-lg font-bold">
                {step.title}
              </h3>
              <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                {step.desc}
              </p>
            </li>
          ))}
        </ol>
      </div>
    </section>
  )
}

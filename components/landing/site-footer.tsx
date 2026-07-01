import Link from 'next/link'
import { ArrowRight } from 'lucide-react'
import { Button } from '@/components/ui/button'

const footerCols = [
  {
    title: 'For vendors',
    links: [
      { label: 'Find Events', href: '/dashboard/find-events' },
      { label: 'Create Profile', href: '/dashboard/profile' },
      { label: 'Applications', href: '/dashboard/applications' },
      { label: 'Reviews', href: '/dashboard/reviews' },
    ],
  },
  {
    title: 'For organizers',
    links: [
      { label: 'Organizer Home', href: '/organizer' },
      { label: 'Import Event', href: '/organizer/import' },
      { label: 'Build Opportunity', href: '/organizer/create' },
      { label: 'Review Applicants', href: '/organizer/applications' },
    ],
  },
  {
    title: 'Company',
    links: [
      { label: 'Admin', href: '/admin' },
      { label: 'About', href: '#' },
      { label: 'Careers', href: '#' },
      { label: 'Contact', href: '#' },
    ],
  },
]

export function SiteFooter() {
  return (
    <footer className="bg-primary text-primary-foreground">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div className="grid gap-10 border-b border-primary-foreground/10 py-16 lg:grid-cols-[1.5fr_2fr]">
          <div>
            <h2 className="max-w-md text-pretty font-display text-3xl font-extrabold tracking-tight sm:text-4xl">
              Find Your Next Opportunity.
            </h2>
            <p className="mt-4 max-w-sm text-primary-foreground/70">
              Join thousands of vendors growing their business at the best live
              events in the country.
            </p>
            <Button
              size="lg"
              className="mt-6 bg-accent text-accent-foreground hover:bg-accent/90"
              render={<Link href="/dashboard" />}
              nativeButton={false}
            >
              Get started free
              <ArrowRight data-icon="inline-end" />
            </Button>
          </div>

          <div className="grid grid-cols-2 gap-8 sm:grid-cols-3">
            {footerCols.map((col) => (
              <div key={col.title}>
                <h3 className="text-sm font-semibold">{col.title}</h3>
                <ul className="mt-4 flex flex-col gap-3">
                  {col.links.map((link) => (
                    <li key={link.label}>
                      <Link
                        href={link.href}
                        className="text-sm text-primary-foreground/60 transition-colors hover:text-primary-foreground"
                      >
                        {link.label}
                      </Link>
                    </li>
                  ))}
                </ul>
              </div>
            ))}
          </div>
        </div>

        <div className="flex flex-col items-center justify-between gap-4 py-8 sm:flex-row">
          <span className="font-display text-xl font-extrabold tracking-tight">
            LNK<span className="text-accent">up</span>
          </span>
          <p className="text-sm text-primary-foreground/50">
            &copy; {new Date().getFullYear()} LNKup. The opportunity network for
            live events.
          </p>
        </div>
      </div>
    </footer>
  )
}

'use client'

import { useState } from 'react'
import { Link2, Loader2, Sparkles } from 'lucide-react'
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Badge } from '@/components/ui/badge'
import { toast } from 'sonner'

const sources = [
  { name: 'Eventbrite', color: 'bg-orange-100 text-orange-700' },
  { name: 'Ticketmaster', color: 'bg-blue-100 text-blue-700' },
  { name: 'Facebook Events', color: 'bg-sky-100 text-sky-700' },
  { name: 'Posh', color: 'bg-pink-100 text-pink-700' },
]

export default function ImportEventPage() {
  const [loading, setLoading] = useState(false)
  const [imported, setImported] = useState(false)

  function handleImport() {
    setLoading(true)
    setImported(false)
    setTimeout(() => {
      setLoading(false)
      setImported(true)
      toast.success('Event details imported. Review and publish.')
    }, 1600)
  }

  return (
    <div className="mx-auto flex w-full max-w-3xl flex-col gap-6">
      <div className="flex flex-col gap-1">
        <h1 className="font-display text-2xl font-bold tracking-tight md:text-3xl">
          Import an event
        </h1>
        <p className="text-muted-foreground">
          Already listed elsewhere? Pull in the details and open it to vendors in
          seconds.
        </p>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Paste an event link</CardTitle>
          <CardDescription>
            We&apos;ll auto-fill the name, date, location, and description
          </CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col gap-5">
          <div className="flex flex-col gap-2">
            <Label htmlFor="url">Event URL</Label>
            <div className="flex flex-col gap-3 sm:flex-row">
              <div className="relative flex-1">
                <Link2 className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                  id="url"
                  placeholder="https://eventbrite.com/e/your-event"
                  className="pl-9"
                  defaultValue="https://eventbrite.com/e/summer-soul-fest-2026"
                />
              </div>
              <Button onClick={handleImport} disabled={loading}>
                {loading ? (
                  <>
                    <Loader2 data-icon="inline-start" className="animate-spin" />
                    Importing
                  </>
                ) : (
                  <>
                    <Sparkles data-icon="inline-start" />
                    Import
                  </>
                )}
              </Button>
            </div>
          </div>

          <div className="flex flex-wrap items-center gap-2">
            <span className="text-sm text-muted-foreground">Works with:</span>
            {sources.map((s) => (
              <Badge key={s.name} variant="secondary" className={s.color}>
                {s.name}
              </Badge>
            ))}
          </div>
        </CardContent>
      </Card>

      {imported && (
        <Card className="border-accent/40">
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Sparkles className="size-5 text-accent" />
              Imported successfully
            </CardTitle>
            <CardDescription>
              Review the details below, then add vendor categories and publish.
            </CardDescription>
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Detail label="Event name" value="Summer Soul Fest 2026" />
              <Detail label="Date" value="Aug 23, 2026" />
              <Detail label="Location" value="Atlanta, GA" />
              <Detail label="Expected attendance" value="9,500" />
            </div>
            <Button className="w-fit">Continue to vendor setup</Button>
          </CardContent>
        </Card>
      )}
    </div>
  )
}

function Detail({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex flex-col gap-1 rounded-xl bg-muted p-3">
      <span className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
        {label}
      </span>
      <span className="font-medium">{value}</span>
    </div>
  )
}

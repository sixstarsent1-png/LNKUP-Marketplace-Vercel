'use client'

import { useMemo, useState } from 'react'
import { MapPin, Search, SlidersHorizontal } from 'lucide-react'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectGroup,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { EventCard } from '@/components/event-card'
import { events, vendorCategories } from '@/lib/mock-data'

export default function FindEventsPage() {
  const [query, setQuery] = useState('')
  const [category, setCategory] = useState('all')
  const [sort, setSort] = useState('date')
  const [activeCat, setActiveCat] = useState<string | null>(null)

  const filtered = useMemo(() => {
    let list = events.filter((e) => {
      const matchesQuery =
        query === '' ||
        e.name.toLowerCase().includes(query.toLowerCase()) ||
        e.city.toLowerCase().includes(query.toLowerCase())
      const selected = activeCat ?? (category === 'all' ? null : category)
      const matchesCat =
        !selected || e.categoriesNeeded.includes(selected as never)
      return matchesQuery && matchesCat
    })
    list = [...list].sort((a, b) => {
      if (sort === 'attendance') return b.attendance - a.attendance
      if (sort === 'fee') return a.vendorFee - b.vendorFee
      if (sort === 'spots') return b.spotsRemaining - a.spotsRemaining
      return 0
    })
    return list
  }, [query, category, sort, activeCat])

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-1">
        <h1 className="font-display text-2xl font-bold tracking-tight md:text-3xl">
          Find events
        </h1>
        <p className="text-muted-foreground">
          {filtered.length} opportunities open for vendors right now
        </p>
      </div>

      <div className="flex flex-col gap-4 rounded-2xl border border-border bg-card p-4">
        <div className="flex flex-col gap-3 sm:flex-row">
          <div className="relative flex-1">
            <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder="Search by event or city..."
              className="pl-9"
            />
          </div>
          <Select value={sort} onValueChange={setSort}>
            <SelectTrigger className="sm:w-48">
              <SlidersHorizontal className="size-4 text-muted-foreground" />
              <SelectValue placeholder="Sort by" />
            </SelectTrigger>
            <SelectContent>
              <SelectGroup>
                <SelectItem value="date">Date (soonest)</SelectItem>
                <SelectItem value="attendance">Attendance</SelectItem>
                <SelectItem value="fee">Lowest fee</SelectItem>
                <SelectItem value="spots">Most spots</SelectItem>
              </SelectGroup>
            </SelectContent>
          </Select>
        </div>
        <div className="flex flex-wrap gap-2">
          <Badge
            onClick={() => setActiveCat(null)}
            variant={activeCat === null ? 'default' : 'outline'}
            className="cursor-pointer rounded-full px-3 py-1"
          >
            All categories
          </Badge>
          {vendorCategories.map((cat) => (
            <Badge
              key={cat.name}
              onClick={() =>
                setActiveCat(activeCat === cat.name ? null : cat.name)
              }
              variant={activeCat === cat.name ? 'default' : 'outline'}
              className="cursor-pointer rounded-full px-3 py-1"
            >
              {cat.name}
            </Badge>
          ))}
        </div>
      </div>

      {filtered.length === 0 ? (
        <div className="flex flex-col items-center gap-2 rounded-2xl border border-dashed border-border py-16 text-center">
          <MapPin className="size-8 text-muted-foreground" />
          <p className="font-medium">No events match your filters</p>
          <p className="text-sm text-muted-foreground">
            Try clearing your search or picking a different category.
          </p>
        </div>
      ) : (
        <div className="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-3">
          {filtered.map((event) => (
            <EventCard key={event.id} event={event} />
          ))}
        </div>
      )}
    </div>
  )
}

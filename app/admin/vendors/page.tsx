'use client'

import { useState } from 'react'
import { Check, Search, X } from 'lucide-react'
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { StatusBadge } from '@/components/dashboard/status-badge'
import { toast } from 'sonner'
import { vendorApplicants, pendingVendors } from '@/lib/mock-data'

type Row = {
  id: string
  name: string
  category: string
  city: string
  status: 'verified' | 'pending'
  avatar?: string
}

const rows: Row[] = [
  ...vendorApplicants.map((v) => ({
    id: v.id,
    name: v.businessName,
    category: v.category,
    city: 'Atlanta, GA',
    status: v.verified ? ('verified' as const) : ('pending' as const),
    avatar: v.avatar,
  })),
  ...pendingVendors.map((v) => ({
    id: v.id,
    name: v.name,
    category: v.category,
    city: v.city,
    status: 'pending' as const,
  })),
]

export default function AdminVendorsPage() {
  const [query, setQuery] = useState('')
  const filtered = rows.filter(
    (r) =>
      r.name.toLowerCase().includes(query.toLowerCase()) ||
      r.city.toLowerCase().includes(query.toLowerCase()),
  )

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-1">
        <h1 className="font-display text-2xl font-bold tracking-tight md:text-3xl">
          Vendor management
        </h1>
        <p className="text-muted-foreground">
          Verify, suspend, and manage marketplace vendors
        </p>
      </div>

      <Card>
        <CardHeader className="flex-row items-center justify-between gap-4">
          <CardTitle>{filtered.length} vendors</CardTitle>
          <div className="relative w-full max-w-xs">
            <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder="Search vendors..."
              className="pl-9"
            />
          </div>
        </CardHeader>
        <CardContent>
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Business</TableHead>
                  <TableHead>Category</TableHead>
                  <TableHead>Location</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead className="text-right">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {filtered.map((r) => (
                  <TableRow key={r.id}>
                    <TableCell>
                      <div className="flex items-center gap-3">
                        <Avatar className="size-8">
                          <AvatarImage src={r.avatar || '/placeholder.svg'} alt={r.name} />
                          <AvatarFallback>{r.name.slice(0, 2)}</AvatarFallback>
                        </Avatar>
                        <span className="font-medium">{r.name}</span>
                      </div>
                    </TableCell>
                    <TableCell className="text-muted-foreground">
                      {r.category}
                    </TableCell>
                    <TableCell className="text-muted-foreground">
                      {r.city}
                    </TableCell>
                    <TableCell>
                      <StatusBadge status={r.status} />
                    </TableCell>
                    <TableCell className="text-right">
                      {r.status === 'pending' ? (
                        <div className="flex justify-end gap-2">
                          <Button
                            size="sm"
                            onClick={() => toast.success(`${r.name} verified`)}
                          >
                            <Check data-icon="inline-start" />
                            Verify
                          </Button>
                          <Button
                            size="sm"
                            variant="outline"
                            className="text-destructive"
                            onClick={() => toast(`${r.name} rejected`)}
                          >
                            <X />
                          </Button>
                        </div>
                      ) : (
                        <Button
                          size="sm"
                          variant="outline"
                          onClick={() => toast(`${r.name} suspended`)}
                        >
                          Suspend
                        </Button>
                      )}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        </CardContent>
      </Card>
    </div>
  )
}

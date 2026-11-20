"use client"

import { useState } from "react"
import { Search, MoreHorizontal, ShieldCheck, Ban } from "lucide-react"
import { vendors, organizers } from "@/lib/data"
import { PageHeader } from "@/components/marketplace/page-header"
import { DemoModeNotice } from "@/components/marketplace/demo-mode-notice"
import { StatusBadge, VerificationBadge } from "@/components/marketplace/badges"
import { Input } from "@/components/ui/input"
import { Card } from "@/components/ui/card"
import { Avatar, AvatarFallback } from "@/components/ui/avatar"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuGroup,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"

function RowActions() {
  return (
    <DropdownMenu>
      <DropdownMenuTrigger
        render={
          <Button variant="ghost" size="icon" aria-label="Actions">
            <MoreHorizontal />
          </Button>
        }
      />
      <DropdownMenuContent align="end">
        <DropdownMenuGroup>
          <DropdownMenuItem>
            <ShieldCheck data-icon="inline-start" />
            Verify account
          </DropdownMenuItem>
          <DropdownMenuItem>View profile</DropdownMenuItem>
          <DropdownMenuItem className="text-destructive">
            <Ban data-icon="inline-start" />
            Suspend
          </DropdownMenuItem>
        </DropdownMenuGroup>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}

export default function AdminUsersPage() {
  const [query, setQuery] = useState("")
  const filteredVendors = vendors.filter((v) => v.name.toLowerCase().includes(query.toLowerCase()))
  const filteredOrgs = organizers.filter((o) => o.name.toLowerCase().includes(query.toLowerCase()))

  return (
    <div className="flex flex-col gap-6">
      <PageHeader title="Users" subtitle="Manage vendors and organizers on the platform." />
      <DemoModeNotice testId="admin-users-demo-notice">
        Demo sample only — user directory below is not live. Manage users on LNKup web admin.
      </DemoModeNotice>

      <div className="relative">
        <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
        <Input
          placeholder="Search users by name"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          className="pl-9"
        />
      </div>

      <Tabs defaultValue="vendors">
        <TabsList>
          <TabsTrigger value="vendors">Vendors ({filteredVendors.length})</TabsTrigger>
          <TabsTrigger value="organizers">Organizers ({filteredOrgs.length})</TabsTrigger>
        </TabsList>

        <TabsContent value="vendors" className="mt-4">
          <Card className="overflow-hidden p-0">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Vendor</TableHead>
                  <TableHead className="hidden md:table-cell">Category</TableHead>
                  <TableHead className="hidden sm:table-cell">Events</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead className="w-10" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {filteredVendors.map((v) => (
                  <TableRow key={v.id}>
                    <TableCell>
                      <div className="flex items-center gap-3">
                        <Avatar className="size-9 rounded-lg">
                          <AvatarFallback className="rounded-lg bg-primary text-xs font-semibold text-primary-foreground">
                            {v.initials}
                          </AvatarFallback>
                        </Avatar>
                        <div className="flex flex-col">
                          <span className="text-sm font-medium">{v.name}</span>
                          <span className="text-xs text-muted-foreground">
                            {v.city}, {v.state}
                          </span>
                        </div>
                      </div>
                    </TableCell>
                    <TableCell className="hidden md:table-cell">
                      <Badge variant="secondary">{v.category}</Badge>
                    </TableCell>
                    <TableCell className="hidden sm:table-cell text-sm text-muted-foreground">
                      {v.completedEvents}
                    </TableCell>
                    <TableCell>
                      {v.verified ? (
                        <VerificationBadge />
                      ) : (
                        <StatusBadge variant="review">Pending</StatusBadge>
                      )}
                    </TableCell>
                    <TableCell>
                      <RowActions />
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </Card>
        </TabsContent>

        <TabsContent value="organizers" className="mt-4">
          <Card className="overflow-hidden p-0">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Organizer</TableHead>
                  <TableHead className="hidden sm:table-cell">Events hosted</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead className="w-10" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {filteredOrgs.map((o) => (
                  <TableRow key={o.id}>
                    <TableCell>
                      <div className="flex items-center gap-3">
                        <Avatar className="size-9 rounded-lg">
                          <AvatarFallback className="rounded-lg bg-primary text-xs font-semibold text-primary-foreground">
                            {o.name.slice(0, 2).toUpperCase()}
                          </AvatarFallback>
                        </Avatar>
                        <span className="text-sm font-medium">{o.name}</span>
                      </div>
                    </TableCell>
                    <TableCell className="hidden sm:table-cell text-sm text-muted-foreground">
                      {o.events}
                    </TableCell>
                    <TableCell>
                      {o.verified ? (
                        <VerificationBadge />
                      ) : (
                        <StatusBadge variant="review">Pending</StatusBadge>
                      )}
                    </TableCell>
                    <TableCell>
                      <RowActions />
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </Card>
        </TabsContent>
      </Tabs>
    </div>
  )
}

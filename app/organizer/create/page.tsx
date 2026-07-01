'use client'

import { useState } from 'react'
import { Check } from 'lucide-react'
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { Label } from '@/components/ui/label'
import { Badge } from '@/components/ui/badge'
import { Switch } from '@/components/ui/switch'
import { toast } from 'sonner'
import { vendorCategories } from '@/lib/mock-data'

export default function CreateOpportunityPage() {
  const [selected, setSelected] = useState<string[]>(['Food Trucks', 'Drinks'])

  function toggle(cat: string) {
    setSelected((prev) =>
      prev.includes(cat) ? prev.filter((c) => c !== cat) : [...prev, cat],
    )
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-1">
        <h1 className="font-display text-2xl font-bold tracking-tight md:text-3xl">
          Create an opportunity
        </h1>
        <p className="text-muted-foreground">
          List your event and start receiving vendor applications
        </p>
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div className="flex flex-col gap-6 lg:col-span-2">
          <Card>
            <CardHeader>
              <CardTitle>Event details</CardTitle>
              <CardDescription>The basics vendors will see first</CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-5">
              <div className="flex flex-col gap-2">
                <Label htmlFor="event-name">Event name</Label>
                <Input id="event-name" placeholder="e.g. Summer Soul Block Party" />
              </div>
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div className="flex flex-col gap-2">
                  <Label htmlFor="city">City</Label>
                  <Input id="city" placeholder="Atlanta" />
                </div>
                <div className="flex flex-col gap-2">
                  <Label htmlFor="state">State</Label>
                  <Input id="state" placeholder="GA" />
                </div>
                <div className="flex flex-col gap-2">
                  <Label htmlFor="date">Event date</Label>
                  <Input id="date" type="date" />
                </div>
                <div className="flex flex-col gap-2">
                  <Label htmlFor="attendance">Expected attendance</Label>
                  <Input id="attendance" type="number" placeholder="5000" />
                </div>
              </div>
              <div className="flex flex-col gap-2">
                <Label htmlFor="desc">Description</Label>
                <Textarea
                  id="desc"
                  rows={4}
                  placeholder="Tell vendors what makes your event special..."
                />
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Vendor categories needed</CardTitle>
              <CardDescription>
                Pick the types of vendors you want to attract
              </CardDescription>
            </CardHeader>
            <CardContent>
              <div className="flex flex-wrap gap-2">
                {vendorCategories.map((cat) => {
                  const isOn = selected.includes(cat.name)
                  return (
                    <button
                      key={cat.name}
                      type="button"
                      onClick={() => toggle(cat.name)}
                    >
                      <Badge
                        variant={isOn ? 'default' : 'outline'}
                        className="cursor-pointer gap-1 rounded-full px-3 py-1.5"
                      >
                        {isOn && <Check className="size-3.5" />}
                        {cat.name}
                      </Badge>
                    </button>
                  )
                })}
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Spots &amp; pricing</CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-5">
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div className="flex flex-col gap-2">
                  <Label htmlFor="spots">Total vendor spots</Label>
                  <Input id="spots" type="number" placeholder="40" />
                </div>
                <div className="flex flex-col gap-2">
                  <Label htmlFor="fee">Vendor fee (USD)</Label>
                  <Input id="fee" type="number" placeholder="250" />
                </div>
              </div>
              <div className="flex items-center justify-between rounded-xl border border-border p-4">
                <div className="flex flex-col gap-0.5">
                  <p className="text-sm font-medium">Auto-approve verified vendors</p>
                  <p className="text-sm text-muted-foreground">
                    Skip manual review for top-rated, verified vendors
                  </p>
                </div>
                <Switch />
              </div>
              <div className="flex items-center justify-between rounded-xl border border-border p-4">
                <div className="flex flex-col gap-0.5">
                  <p className="text-sm font-medium">Require documents</p>
                  <p className="text-sm text-muted-foreground">
                    Only accept vendors with verified insurance &amp; permits
                  </p>
                </div>
                <Switch defaultChecked />
              </div>
            </CardContent>
          </Card>
        </div>

        <div className="flex flex-col gap-6">
          <Card className="sticky top-20">
            <CardHeader>
              <CardTitle>Publish</CardTitle>
              <CardDescription>
                Review before going live to vendors
              </CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
              <div className="flex flex-col gap-3 text-sm">
                <div className="flex items-center justify-between">
                  <span className="text-muted-foreground">Categories</span>
                  <span className="font-medium">{selected.length} selected</span>
                </div>
                <div className="flex items-center justify-between">
                  <span className="text-muted-foreground">Listing fee</span>
                  <span className="font-medium">Free</span>
                </div>
                <div className="flex items-center justify-between">
                  <span className="text-muted-foreground">Platform fee</span>
                  <span className="font-medium">8% per booking</span>
                </div>
              </div>
              <Button
                className="w-full"
                onClick={() => toast.success('Opportunity published to vendors!')}
              >
                Publish opportunity
              </Button>
              <Button
                variant="outline"
                className="w-full"
                onClick={() => toast('Saved as draft')}
              >
                Save as draft
              </Button>
            </CardContent>
          </Card>
        </div>
      </div>
    </div>
  )
}

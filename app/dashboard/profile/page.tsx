import { BadgeCheck, Camera, MapPin, Star } from 'lucide-react'
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
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { vendorCategories } from '@/lib/mock-data'

export default function ProfilePage() {
  const myCategories = ['Food Trucks', 'Drinks']

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-1">
        <h1 className="font-display text-2xl font-bold tracking-tight md:text-3xl">
          Business profile
        </h1>
        <p className="text-muted-foreground">
          This is what organizers see when you apply
        </p>
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <Card className="lg:col-span-1">
          <CardContent className="flex flex-col items-center gap-4 pt-6 text-center">
            <div className="relative">
              <Avatar className="size-24 border-2 border-border">
                <AvatarImage src="/vendors/smoke-brisket.png" alt="Smoke & Brisket Co." />
                <AvatarFallback>SB</AvatarFallback>
              </Avatar>
              <Button
                size="icon"
                variant="secondary"
                className="absolute -bottom-1 -right-1 size-8 rounded-full"
              >
                <Camera className="size-4" />
                <span className="sr-only">Change photo</span>
              </Button>
            </div>
            <div className="flex flex-col items-center gap-1">
              <div className="flex items-center gap-1.5">
                <h2 className="font-display text-lg font-bold">
                  Smoke &amp; Brisket Co.
                </h2>
                <BadgeCheck className="size-5 text-accent" />
              </div>
              <p className="flex items-center gap-1 text-sm text-muted-foreground">
                <MapPin className="size-3.5" />
                Atlanta, GA
              </p>
            </div>
            <div className="flex items-center gap-1 text-sm">
              <Star className="size-4 fill-amber-500 text-amber-500" />
              <span className="font-semibold">4.8</span>
              <span className="text-muted-foreground">(42 events)</span>
            </div>
            <Badge className="bg-accent/15 text-accent-foreground" variant="secondary">
              Verified vendor
            </Badge>
          </CardContent>
        </Card>

        <Card className="lg:col-span-2">
          <CardHeader>
            <CardTitle>Business details</CardTitle>
            <CardDescription>
              Keep this up to date for faster approvals
            </CardDescription>
          </CardHeader>
          <CardContent className="flex flex-col gap-5">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div className="flex flex-col gap-2">
                <Label htmlFor="biz-name">Business name</Label>
                <Input id="biz-name" defaultValue="Smoke & Brisket Co." />
              </div>
              <div className="flex flex-col gap-2">
                <Label htmlFor="owner">Owner name</Label>
                <Input id="owner" defaultValue="Marcus Hill" />
              </div>
              <div className="flex flex-col gap-2">
                <Label htmlFor="email">Email</Label>
                <Input id="email" type="email" defaultValue="marcus@smokebrisket.co" />
              </div>
              <div className="flex flex-col gap-2">
                <Label htmlFor="phone">Phone</Label>
                <Input id="phone" defaultValue="(404) 555-0182" />
              </div>
            </div>
            <div className="flex flex-col gap-2">
              <Label htmlFor="bio">About your business</Label>
              <Textarea
                id="bio"
                rows={4}
                defaultValue="Award-winning Texas-style BBQ truck serving brisket, ribs, and loaded sides at festivals across the South. Family-owned since 2019."
              />
            </div>
            <div className="flex flex-col gap-2">
              <Label>Categories</Label>
              <div className="flex flex-wrap gap-2">
                {vendorCategories.map((cat) => (
                  <Badge
                    key={cat.name}
                    variant={
                      myCategories.includes(cat.name) ? 'default' : 'outline'
                    }
                    className="rounded-full px-3 py-1"
                  >
                    {cat.name}
                  </Badge>
                ))}
              </div>
            </div>
            <div className="flex justify-end gap-2">
              <Button variant="outline">Cancel</Button>
              <Button>Save changes</Button>
            </div>
          </CardContent>
        </Card>
      </div>
    </div>
  )
}

import { BadgeCheck, Mail, Star } from 'lucide-react'
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { vendorApplicants } from '@/lib/mock-data'

export default function OrganizerVendorsPage() {
  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-1">
        <h1 className="font-display text-2xl font-bold tracking-tight md:text-3xl">
          My vendors
        </h1>
        <p className="text-muted-foreground">
          Vendors you&apos;ve worked with and can rebook
        </p>
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {[...vendorApplicants, ...vendorApplicants.slice(0, 2)].map((v, i) => (
          <Card key={`${v.id}-${i}`}>
            <CardHeader className="flex-row items-center gap-3">
              <Avatar className="size-12">
                <AvatarImage src={v.avatar || '/placeholder.svg'} alt={v.businessName} />
                <AvatarFallback>{v.businessName.slice(0, 2)}</AvatarFallback>
              </Avatar>
              <div className="flex flex-col gap-0.5">
                <CardTitle className="flex items-center gap-1 text-base">
                  {v.businessName}
                  {v.verified && <BadgeCheck className="size-4 text-accent" />}
                </CardTitle>
                <span className="text-sm text-muted-foreground">
                  {v.category}
                </span>
              </div>
            </CardHeader>
            <CardContent className="flex flex-col gap-3">
              <div className="flex items-center gap-3 text-sm">
                <span className="flex items-center gap-1">
                  <Star className="size-4 fill-amber-500 text-amber-500" />
                  {v.rating}
                </span>
                <Badge variant="secondary">{v.completedEvents} events</Badge>
              </div>
              <div className="flex gap-2">
                <Button size="sm" className="flex-1">
                  Invite to event
                </Button>
                <Button size="sm" variant="outline" aria-label="Message">
                  <Mail />
                </Button>
              </div>
            </CardContent>
          </Card>
        ))}
      </div>
    </div>
  )
}

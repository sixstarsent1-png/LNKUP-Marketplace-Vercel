import { Star } from 'lucide-react'
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import { Avatar, AvatarFallback } from '@/components/ui/avatar'
import { reviews } from '@/lib/mock-data'

function Stars({ rating }: { rating: number }) {
  return (
    <div className="flex items-center gap-0.5 text-amber-500">
      {Array.from({ length: 5 }).map((_, i) => (
        <Star
          key={i}
          className={`size-4 ${i < rating ? 'fill-current' : 'text-muted'}`}
          strokeWidth={i < rating ? 0 : 1.5}
        />
      ))}
    </div>
  )
}

export default function ReviewsPage() {
  const avg =
    reviews.reduce((s, r) => s + r.rating, 0) / reviews.length

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-1">
        <h1 className="font-display text-2xl font-bold tracking-tight md:text-3xl">
          Reviews
        </h1>
        <p className="text-muted-foreground">
          What organizers say after working with you
        </p>
      </div>

      <Card>
        <CardContent className="flex flex-col items-center gap-2 py-8 text-center sm:flex-row sm:justify-center sm:gap-8">
          <div className="flex flex-col items-center gap-1">
            <span className="font-display text-5xl font-bold">
              {avg.toFixed(1)}
            </span>
            <Stars rating={Math.round(avg)} />
            <span className="text-sm text-muted-foreground">
              {reviews.length} reviews
            </span>
          </div>
        </CardContent>
      </Card>

      <div className="flex flex-col gap-4">
        {reviews.map((r) => (
          <Card key={r.id}>
            <CardHeader className="flex-row items-start justify-between gap-4">
              <div className="flex items-center gap-3">
                <Avatar className="size-10">
                  <AvatarFallback>
                    {r.organizer
                      .split(' ')
                      .map((w) => w[0])
                      .join('')
                      .slice(0, 2)}
                  </AvatarFallback>
                </Avatar>
                <div className="flex flex-col gap-0.5">
                  <CardTitle className="text-base">{r.organizer}</CardTitle>
                  <span className="text-sm text-muted-foreground">
                    {r.event} &middot; {r.date}
                  </span>
                </div>
              </div>
              <Stars rating={r.rating} />
            </CardHeader>
            <CardContent>
              <p className="leading-relaxed text-foreground/90">
                &ldquo;{r.comment}&rdquo;
              </p>
            </CardContent>
          </Card>
        ))}
      </div>
    </div>
  )
}

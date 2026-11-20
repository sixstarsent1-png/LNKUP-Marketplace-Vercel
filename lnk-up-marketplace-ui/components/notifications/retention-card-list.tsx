"use client"

import Link from "next/link"
import { FileWarning } from "lucide-react"
import type { OrganizerRetentionCard, VendorRetentionCard } from "@/lib/api/notifications"
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert"
import { Button } from "@/components/ui/button"

type RetentionCard = VendorRetentionCard | OrganizerRetentionCard

export function RetentionCardList({
  cards,
  testId = "retention-card-list",
}: {
  cards: RetentionCard[]
  testId?: string
}) {
  if (cards.length === 0) return null

  return (
    <div className="flex flex-col gap-3" data-testid={testId}>
      {cards.map((card) => (
        <Alert key={card.id} data-testid={`retention-card-${card.type}`}>
          <FileWarning />
          <AlertTitle>{card.title}</AlertTitle>
          <AlertDescription className="flex flex-wrap items-center justify-between gap-3">
            <span>{card.description ?? "Take action to stay on track."}</span>
            {card.href && (
              <Button
                size="sm"
                variant={card.severity === "urgent" ? "default" : "outline"}
                nativeButton={false}
                render={
                  card.href.startsWith("/") ? (
                    <Link href={card.href} />
                  ) : (
                    <a href={card.href} target="_blank" rel="noopener noreferrer" />
                  )
                }
              >
                {card.actionLabel ?? "View"}
              </Button>
            )}
          </AlertDescription>
        </Alert>
      ))}
    </div>
  )
}

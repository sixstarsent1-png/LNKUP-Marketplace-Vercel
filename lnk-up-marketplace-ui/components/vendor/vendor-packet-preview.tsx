"use client"

import type { ComponentType, ReactNode } from "react"
import Link from "next/link"
import Image from "next/image"
import {
  Calendar,
  MapPin,
  Car,
  DoorOpen,
  Ticket,
  FileText,
  QrCode,
  CloudSun,
  Zap,
  Droplets,
  AlertTriangle,
} from "lucide-react"
import type { VendorEventPass } from "@/lib/api/vendor-packet"
import { VendorPassActions } from "@/components/vendor/vendor-pass-actions"
import { Card, CardContent } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { trackLnkupEvent } from "@/lib/analytics/track-event"

function PacketField({
  icon: Icon,
  label,
  value,
  testId,
  onView,
}: {
  icon: ComponentType<{ className?: string }>
  label: string
  value?: string
  testId?: string
  onView?: () => void
}) {
  if (!value) return null
  return (
    <div
      className="flex gap-3 rounded-xl bg-muted/60 p-3"
      data-testid={testId}
      onClick={onView}
      role={onView ? "button" : undefined}
    >
      <Icon className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
      <div className="min-w-0">
        <p className="text-xs font-medium text-muted-foreground">{label}</p>
        <p className="text-sm">{value}</p>
      </div>
    </div>
  )
}

export function VendorPacketPreview({
  packet,
  onContinuePayment,
  footer,
}: {
  packet: VendorEventPass
  onContinuePayment?: () => void
  footer?: ReactNode
}) {
  const locked = packet.status === "locked"
  const location = [packet.city, packet.state].filter(Boolean).join(", ")
  const unlocks = packet.unlocks ?? []

  if (locked) {
    return (
      <Card className="rounded-2xl" data-testid="vendor-packet-locked">
        <CardContent className="flex flex-col gap-4 p-6">
          <div className="flex flex-col gap-2">
            <Badge variant="secondary">Locked</Badge>
            <h2 className="font-heading text-xl font-bold">
              {packet.headline || "Your vendor pass unlocks after payment"}
            </h2>
            <p className="text-sm text-muted-foreground">
              {packet.message || "Your vendor pass unlocks after payment."}
            </p>
          </div>

          <div className="rounded-xl border p-4">
            <p className="font-semibold">{packet.eventName}</p>
            {packet.eventDate && (
              <p className="mt-1 inline-flex items-center gap-1 text-sm text-muted-foreground">
                <Calendar className="size-3.5" />
                {packet.eventDate}
              </p>
            )}
            {location && (
              <p className="mt-1 inline-flex items-center gap-1 text-sm text-muted-foreground">
                <MapPin className="size-3.5" />
                {location}
              </p>
            )}
            {packet.organizerName && (
              <p className="mt-1 text-sm text-muted-foreground">Organizer · {packet.organizerName}</p>
            )}
          </div>

          {unlocks.length > 0 && (
            <div className="flex flex-col gap-2">
              <p className="text-sm font-medium">Unlocks after payment</p>
              <ul className="flex flex-col gap-1 text-sm text-muted-foreground">
                {unlocks.map((item) => (
                  <li key={item} className="inline-flex items-center gap-2">
                    <Ticket className="size-3.5 shrink-0" />
                    {item}
                  </li>
                ))}
              </ul>
            </div>
          )}

          <div className="flex flex-col gap-2 sm:flex-row">
            {onContinuePayment ? (
              <Button className="flex-1" onClick={onContinuePayment} data-testid="packet-continue-payment">
                Continue payment
              </Button>
            ) : (
              <Button
                className="flex-1"
                nativeButton={false}
                render={<Link href={`/vendor/requests?pay=${packet.requestId}`} />}
              >
                Continue payment
              </Button>
            )}
            <Button variant="outline" className="flex-1" nativeButton={false} render={<Link href="/vendor/requests" />}>
              Back to requests
            </Button>
          </div>
        </CardContent>
      </Card>
    )
  }

  const organizerContact = [packet.organizerName, packet.organizerEmail, packet.organizerPhone]
    .filter(Boolean)
    .join(" · ")

  return (
    <Card className="rounded-2xl" data-testid="vendor-packet-unlocked">
      <CardContent className="flex flex-col gap-4 p-6">
        <div className="flex flex-col gap-2">
          <Badge className="w-fit bg-accent text-accent-foreground">Booked</Badge>
          <h2 className="font-heading text-xl font-bold">
            {packet.headline || "You're booked — your event pass is ready"}
          </h2>
          <p className="text-sm text-muted-foreground">{packet.eventName}</p>
          {packet.eventDate && (
            <p className="inline-flex items-center gap-1 text-sm text-muted-foreground">
              <Calendar className="size-3.5" />
              {packet.eventDate}
              {packet.startTime ? ` · ${packet.startTime}` : ""}
              {packet.endTime ? ` – ${packet.endTime}` : ""}
              {location ? ` · ${location}` : ""}
            </p>
          )}
        </div>

        <div className="rounded-xl border p-4" data-testid="vendor-pass-qr">
          <p className="text-sm font-medium">Check-in</p>
          {packet.qrCodeUrl ? (
            <div className="mt-3 flex flex-col items-center gap-2">
              <Image
                src={packet.qrCodeUrl}
                alt="Event check-in QR code"
                width={160}
                height={160}
                className="rounded-lg border bg-white p-2"
                unoptimized
              />
              {packet.checkInCode && (
                <p className="font-mono text-sm text-muted-foreground">{packet.checkInCode}</p>
              )}
            </div>
          ) : packet.checkInCode ? (
            <p className="mt-2 font-mono text-lg">{packet.checkInCode}</p>
          ) : (
            <p className="mt-2 text-sm text-muted-foreground">
              Check-in details will appear here when the organizer releases them.
            </p>
          )}
        </div>

        <VendorPassActions packet={packet} />

        <div className="flex flex-col gap-2">
          <PacketField
            icon={MapPin}
            label={packet.venueName ? `Venue · ${packet.venueName}` : "Venue"}
            value={packet.venueAddress}
            testId="vendor-pass-venue"
          />
          <PacketField
            icon={DoorOpen}
            label="Load-in"
            value={packet.loadInTime}
            testId="vendor-pass-loadin"
            onView={() =>
              trackLnkupEvent({
                event: "vendor_loadin_viewed",
                source: "vendor_pass",
                requestId: packet.requestId,
              })
            }
          />
          <PacketField icon={DoorOpen} label="Load-out" value={packet.loadOutTime} />
          <PacketField icon={Car} label="Parking" value={packet.parkingInstructions} />
          <PacketField icon={Ticket} label="Booth" value={packet.boothAssignment} />
          <PacketField icon={Zap} label="Power" value={packet.powerDetails} />
          <PacketField icon={Droplets} label="Water" value={packet.waterDetails} />
          {packet.mapUrl && (
            <div className="flex gap-3 rounded-xl bg-muted/60 p-3">
              <MapPin className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
              <div>
                <p className="text-xs font-medium text-muted-foreground">Venue map</p>
                <a
                  href={packet.mapUrl}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="text-sm font-medium text-primary underline-offset-4 hover:underline"
                >
                  Open map
                </a>
              </div>
            </div>
          )}
          {organizerContact && (
            <PacketField icon={FileText} label="Organizer contact" value={organizerContact} />
          )}
          {packet.dayOfContact && (
            <PacketField icon={FileText} label="Day-of contact" value={packet.dayOfContact} />
          )}
          {packet.emergencyContact && (
            <PacketField icon={AlertTriangle} label="Emergency contact" value={packet.emergencyContact} />
          )}
          {packet.weatherNote && (
            <PacketField icon={CloudSun} label="Weather note" value={packet.weatherNote} />
          )}
          {packet.forecastStatus && (
            <PacketField icon={CloudSun} label="Forecast status" value={packet.forecastStatus} />
          )}
          {packet.rainPlan && (
            <PacketField icon={CloudSun} label="Rain plan" value={packet.rainPlan} />
          )}
          {packet.heatPlan && (
            <PacketField icon={CloudSun} label="Heat plan" value={packet.heatPlan} />
          )}
          {packet.contingency && (
            <PacketField icon={CloudSun} label="Contingency" value={packet.contingency} />
          )}
        </div>

        {packet.weatherRisks && packet.weatherRisks.length > 0 && (
          <div className="flex flex-col gap-2">
            <p className="text-sm font-medium">Weather risks</p>
            <ul className="list-disc space-y-1 pl-5 text-sm text-muted-foreground">
              {packet.weatherRisks.map((item) => (
                <li key={item}>{item}</li>
              ))}
            </ul>
          </div>
        )}

        {packet.weatherRecommendations && packet.weatherRecommendations.length > 0 && (
          <div className="flex flex-col gap-2">
            <p className="text-sm font-medium">Weather recommendations</p>
            <ul className="list-disc space-y-1 pl-5 text-sm text-muted-foreground">
              {packet.weatherRecommendations.map((item) => (
                <li key={item}>{item}</li>
              ))}
            </ul>
          </div>
        )}

        {packet.setupRestrictions && packet.setupRestrictions.length > 0 && (
          <div className="flex flex-col gap-2">
            <p className="text-sm font-medium">Setup restrictions</p>
            <ul className="list-disc space-y-1 pl-5 text-sm text-muted-foreground">
              {packet.setupRestrictions.map((rule) => (
                <li key={rule}>{rule}</li>
              ))}
            </ul>
          </div>
        )}

        {packet.vendorRules && packet.vendorRules.length > 0 && (
          <div className="flex flex-col gap-2" data-testid="vendor-pass-rules">
            <p className="text-sm font-medium">Vendor rules</p>
            <ul className="list-disc space-y-1 pl-5 text-sm text-muted-foreground">
              {packet.vendorRules.map((rule) => (
                <li
                  key={rule}
                  onClick={() =>
                    trackLnkupEvent({
                      event: "vendor_rules_viewed",
                      source: "vendor_pass",
                      requestId: packet.requestId,
                    })
                  }
                >
                  {rule}
                </li>
              ))}
            </ul>
          </div>
        )}

        {(packet.refundPolicy || packet.weatherPolicy) && (
          <div className="flex flex-col gap-2 rounded-xl border p-4 text-sm">
            {packet.refundPolicy && (
              <p>
                <span className="font-medium">Refund policy · </span>
                <span className="text-muted-foreground">{packet.refundPolicy}</span>
              </p>
            )}
            {packet.weatherPolicy && (
              <p>
                <span className="font-medium">Weather policy · </span>
                <span className="text-muted-foreground">{packet.weatherPolicy}</span>
              </p>
            )}
          </div>
        )}

        {!packet.venueAddress && !packet.loadInTime && !packet.boothAssignment && (
          <p className="text-sm text-muted-foreground">
            Event details will appear here once the organizer releases them.
          </p>
        )}

        {footer}
      </CardContent>
    </Card>
  )
}

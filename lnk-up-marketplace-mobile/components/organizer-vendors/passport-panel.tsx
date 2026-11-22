import { useCallback, useEffect, useState } from "react"
import { StyleSheet, View } from "react-native"

import { AppText as Text } from "@/components/ui/app-text"
import { Button } from "@/components/ui/button"
import { Surface } from "@/components/ui/surface"
import { Body, Meta, SectionTitle } from "@/components/ui/typography"
import { appErrorFromWp } from "@/lib/api/errors"
import {
  fetchMyTours,
  fetchOrganizerPassport,
  inviteToTour,
  type OrganizerPassport,
  type OrganizerTourOption,
} from "@/lib/api/passport"
import { useAuth } from "@/lib/auth/auth-context"
import { confirmAction, promptText, showToast } from "@/lib/feedback/feedback"
import { openExternalUrl } from "@/lib/navigation/open-external-url"
import { colors, radii, spacing, typeScale } from "@/lib/theme"

/**
 * Organizer view of a vendor's Passport: transparent indicators (never the internal score),
 * confirmed history, and tour-stop invitations. Failure here never blocks the rest of the screen.
 */
export function OrganizerPassportPanel({ profileId }: { profileId: number }) {
  const { auth } = useAuth()
  const [passport, setPassport] = useState<OrganizerPassport | null>(null)
  const [tours, setTours] = useState<OrganizerTourOption[]>([])
  const [failed, setFailed] = useState(false)
  const [inviting, setInviting] = useState<number | null>(null)

  const load = useCallback(async () => {
    setFailed(false)
    const [p, t] = await Promise.allSettled([fetchOrganizerPassport(profileId, auth), fetchMyTours(auth)])
    if (p.status === "fulfilled") setPassport(p.value)
    else setFailed(true)
    if (t.status === "fulfilled") setTours(t.value)
  }, [auth, profileId])

  useEffect(() => {
    void load()
  }, [load])

  async function invite(tour: OrganizerTourOption) {
    if (!passport) return
    const ok = await confirmAction({
      title: `Invite to ${tour.title}?`,
      message: `${passport.businessName} gets one invitation covering the upcoming stops. Each stop keeps its own application, approval and booking.`,
      confirmLabel: "Send invitation",
    })
    if (!ok) return
    const message = await promptText({ title: "Add a message (optional)", minLength: 0, placeholder: "We'd love to have you at these stops." })
    if (message === null) return
    setInviting(tour.id)
    try {
      const res = await inviteToTour(profileId, tour.id, [], message, auth)
      showToast(
        res.created > 0
          ? `Invited to ${res.created} stop${res.created === 1 ? "" : "s"}.`
          : res.skipped[0] || "No upcoming stops to invite to.",
        res.created > 0 ? "success" : "info",
      )
    } catch (err) {
      showToast(appErrorFromWp(err).message, "error")
    } finally {
      setInviting(null)
    }
  }

  if (failed && !passport) {
    return (
      <Surface style={styles.card}>
        <Meta>Passport details didn't load.</Meta>
        <Button label="Try again" size="sm" variant="secondary" onPress={() => void load()} />
      </Surface>
    )
  }
  if (!passport) return null

  const ind = passport.indicators
  const confirmed = passport.history.filter((h) => h.confirmed)
  return (
    <Surface style={styles.card} testID="organizer-passport-panel">
      <SectionTitle>Vendor Passport</SectionTitle>
      <Row label="Business verification" value={ind.verification.isVerified ? ind.verification.label : "Not verified yet"} hint={ind.verification.explanation} />
      <Row label="Marketplace experience" value={ind.experience.label} />
      <Row label="Organizer feedback" value={ind.feedback.label} hint={ind.feedback.method} />
      <Row label="Documents" value={ind.documents.label} />
      {ind.isNewVendor ? <Meta>{ind.newVendorNote}</Meta> : null}
      {passport.capabilities.length ? <Meta>Capabilities: {passport.capabilities.join(" · ")}</Meta> : null}
      {confirmed.length ? (
        <View style={styles.list}>
          <Text style={styles.label}>Confirmed events</Text>
          {confirmed.slice(0, 5).map((h) => (
            <Body key={h.eventId}>
              {h.eventTitle}
              {h.city ? ` · ${h.city}` : ""}
              {h.evidence === "check_in" ? " · check-in verified" : ""}
            </Body>
          ))}
        </View>
      ) : null}
      {passport.publicUrl ? (
        <Button label="Open public Passport" size="sm" variant="ghost" onPress={() => void openExternalUrl(passport.publicUrl, "vendor passport")} />
      ) : null}
      {passport.acceptsInvites && tours.length ? (
        <View style={styles.list}>
          <Text style={styles.label}>Invite to tour stops</Text>
          {tours.map((tour) => (
            <Button
              key={tour.id}
              label={`${tour.title} (${tour.stopCount} stops)`}
              size="sm"
              variant="secondary"
              loading={inviting === tour.id}
              onPress={() => void invite(tour)}
            />
          ))}
        </View>
      ) : null}
    </Surface>
  )
}

function Row({ label, value, hint }: { label: string; value: string; hint?: string }) {
  return (
    <View style={styles.row}>
      <Text style={styles.label}>{label}</Text>
      <Body>{value || "—"}</Body>
      {hint ? <Meta>{hint}</Meta> : null}
    </View>
  )
}

const styles = StyleSheet.create({
  card: { gap: spacing.sm, padding: spacing.lg, borderRadius: radii.lg },
  row: { gap: 2 },
  list: { gap: spacing.xs },
  label: { fontSize: typeScale.micro, fontWeight: "700", color: colors.gray500, textTransform: "uppercase" },
})

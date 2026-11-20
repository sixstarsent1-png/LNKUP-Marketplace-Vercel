import { StyleSheet, View } from "react-native"
import { router, type Href } from "expo-router"

import { AppIcon, type AppIconName } from "@/components/ui/app-icon"
import { Button } from "@/components/ui/button"
import { Surface } from "@/components/ui/surface"
import { DisplayTitle, Eyebrow, Meta } from "@/components/ui/typography"
import { formatMoney } from "@/lib/api/vendor-wallet"
import type { VendorNextStep } from "@/lib/vendor/vendor-stage"
import { colors, spacing, type SurfaceVariant, typeScale } from "@/lib/theme"

type Model = {
  eyebrow: string
  icon: AppIconName
  title: string
  meta?: string
  cta: string
  ctaIcon: AppIconName
  href: Href
  variant: SurfaceVariant
  secondary?: { label: string; href: Href }
}

function modelFor(step: VendorNextStep): Model {
  switch (step.kind) {
    case "reply":
      return {
        eyebrow: "Organizer question",
        icon: "chatbubble-ellipses-outline",
        title: `Reply to ${step.request.organizerName || "the organizer"}`,
        meta: `${step.request.eventName} is waiting on your answer before they decide.`,
        cta: "Answer now",
        ctaIcon: "send-outline",
        href: `/booking/${step.request.id}` as Href,
        variant: "urgent",
      }
    case "pay": {
      const amount =
        step.request.amountDue != null && step.request.amountDue > 0
          ? formatMoney(step.request.amountDue, step.request.currency ?? "USD")
          : null
      return {
        eyebrow: "Approved — pay to confirm",
        icon: "card-outline",
        title: step.request.eventName,
        meta: [amount ? `${amount} booth fee` : "Booth fee due", step.request.eventDate].filter(Boolean).join(" · "),
        cta: amount ? `Pay ${amount}` : "Pay now",
        ctaIcon: "card-outline",
        href: `/pass/${step.request.id}` as Href,
        variant: "urgent",
        secondary: { label: "Details", href: `/booking/${step.request.id}` as Href },
      }
    }
    case "prepare":
      return {
        eyebrow: "You're confirmed",
        icon: "ticket-outline",
        title: step.request.eventName,
        meta: [step.request.eventDate, step.request.city].filter(Boolean).join(" · "),
        cta: "Open event pass",
        ctaIcon: "ticket-outline",
        href: `/pass/${step.request.id}` as Href,
        variant: "hero",
        secondary: { label: "Booking", href: `/booking/${step.request.id}` as Href },
      }
    case "track":
      return {
        eyebrow: "In review",
        icon: "hourglass-outline",
        title: `${step.count} application${step.count === 1 ? "" : "s"} with organizers`,
        meta: "You'll be notified when an organizer decides. Applying is free — keep exploring.",
        cta: "Track applications",
        ctaIcon: "albums-outline",
        href: "/(vendor)/requests?filter=review" as Href,
        variant: "hero",
        secondary: { label: "Explore", href: "/(vendor)/find" as Href },
      }
    case "profile":
      return {
        eyebrow: "Get started",
        icon: "person-circle-outline",
        title: "Set up your business profile",
        meta: `${Math.round(step.completion)}% complete. Organizers review your profile when you apply.`,
        cta: "Finish profile",
        ctaIcon: "create-outline",
        href: "/(vendor)/profile" as Href,
        variant: "accent",
        secondary: { label: "Explore", href: "/(vendor)/find" as Href },
      }
    case "explore":
      return {
        eyebrow: "Find your next market",
        icon: "compass-outline",
        title: "Discover vendor opportunities",
        meta: "Apply for free. You only pay when an organizer accepts you.",
        cta: "Explore events",
        ctaIcon: "search-outline",
        href: "/(vendor)/find" as Href,
        variant: "accent",
      }
    default: {
      const exhaustive: never = step
      return exhaustive
    }
  }
}

/** The one thing a vendor should do next, from real application and profile state. */
export function NextStepCard({ step }: { step: VendorNextStep }) {
  const model = modelFor(step)
  const urgent = model.variant === "urgent"
  return (
    <Surface variant={model.variant} accentBorderTop={model.variant === "accent"} style={styles.card} testID="vendor-next-step">
      <View style={styles.eyebrowRow}>
        <AppIcon name={model.icon} size={20} color={urgent ? colors.amber700 : colors.black} />
        <Eyebrow style={urgent ? styles.urgentText : undefined}>{model.eyebrow}</Eyebrow>
      </View>
      <DisplayTitle style={styles.title} numberOfLines={2}>
        {model.title}
      </DisplayTitle>
      {model.meta ? <Meta numberOfLines={3}>{model.meta}</Meta> : null}
      <View style={styles.actions}>
        <Button
          label={model.cta}
          icon={model.ctaIcon}
          style={styles.primary}
          onPress={() => router.push(model.href)}
        />
        {model.secondary ? (
          <Button
            label={model.secondary.label}
            variant="secondary"
            onPress={() => router.push(model.secondary!.href)}
          />
        ) : null}
      </View>
    </Surface>
  )
}

const styles = StyleSheet.create({
  card: { padding: spacing.lg, gap: spacing.xs },
  eyebrowRow: { flexDirection: "row", alignItems: "center", gap: spacing.sm },
  urgentText: { color: colors.amber700 },
  title: { fontSize: typeScale.title, lineHeight: 28 },
  actions: { flexDirection: "row", gap: spacing.sm, marginTop: spacing.md },
  primary: { flex: 1 },
})

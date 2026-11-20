import { useEffect, useRef } from "react"
import { LayoutAnimation, Platform, StyleSheet, UIManager, View } from "react-native"

import type { VendorRequest } from "@/lib/api/vendor-requests"
import { AppIcon, type AppIconName } from "@/components/ui/app-icon"
import { Button } from "@/components/ui/button"
import { Surface } from "@/components/ui/surface"
import { DisplayTitle, Eyebrow, Meta } from "@/components/ui/typography"
import { colors, spacing, typeScale } from "@/lib/theme"

export type HomeHeroVariant = "pay" | "pass" | "find"

export type HomeHeroModel = {
  variant: HomeHeroVariant
  title: string
  meta?: string
  ctaLabel: string
  onPress: () => void
}

function formatAmount(amount?: number, currency = "USD"): string | null {
  if (amount == null || amount <= 0) return null
  try {
    return new Intl.NumberFormat("en-US", {
      style: "currency",
      currency,
      maximumFractionDigits: 0,
    }).format(amount)
  } catch {
    return `$${amount}`
  }
}

/** Prefer payment-due, else next booked, else find CTA. */
export function buildHomeHero(
  paymentDue: VendorRequest[],
  upcomingBooked: VendorRequest[],
  handlers: {
    onPay: (item: VendorRequest) => void
    onPass: (item: VendorRequest) => void
    onFind: () => void
  },
): HomeHeroModel {
  const due = paymentDue[0]
  if (due) {
    const amount = formatAmount(due.amountDue, due.currency)
    return {
      variant: "pay",
      title: due.eventName,
      meta: [amount ? `${amount} due` : "Payment due", due.eventDate]
        .filter(Boolean)
        .join(" · "),
      ctaLabel: amount ? `Pay ${amount}` : "Pay now",
      onPress: () => handlers.onPay(due),
    }
  }

  const next = upcomingBooked[0]
  if (next) {
    return {
      variant: "pass",
      title: next.eventName,
      meta: [next.eventDate, next.city].filter(Boolean).join(" · "),
      ctaLabel: "Open pass",
      onPress: () => handlers.onPass(next),
    }
  }

  return {
    variant: "find",
    title: "Find your next market",
    meta: "Apply for free. Pay only when accepted.",
    ctaLabel: "Browse events",
    onPress: handlers.onFind,
  }
}

if (Platform.OS === "android" && UIManager.setLayoutAnimationEnabledExperimental) {
  UIManager.setLayoutAnimationEnabledExperimental(true)
}

export function HomeHeroCard({ hero }: { hero: HomeHeroModel }) {
  const isPay = hero.variant === "pay"
  const isFind = hero.variant === "find"
  const prevVariant = useRef(hero.variant)

  useEffect(() => {
    if (prevVariant.current === hero.variant) return
    prevVariant.current = hero.variant
    LayoutAnimation.configureNext(LayoutAnimation.Presets.easeInEaseOut)
  }, [hero.variant])

  const iconName: AppIconName = isPay
    ? "alert-circle"
    : isFind
      ? "compass-outline"
      : "ticket-outline"

  return (
    <Surface
      variant={isPay ? "urgent" : isFind ? "accent" : "hero"}
      accentBorderTop={isFind}
      style={styles.surface}
    >
      <View style={styles.eyebrowRow}>
        <AppIcon name={iconName} size={20} color={isPay ? colors.amber700 : colors.black} />
        <Eyebrow>
          {hero.variant === "pay"
            ? "Needs attention"
            : hero.variant === "pass"
              ? "Your next market"
              : "Get started"}
        </Eyebrow>
      </View>
      <DisplayTitle style={styles.title} numberOfLines={2}>
        {hero.title}
      </DisplayTitle>
      {hero.meta ? (
        <Meta style={isPay ? styles.metaPay : undefined} numberOfLines={2}>
          {hero.meta}
        </Meta>
      ) : null}
      <Button
        label={hero.ctaLabel}
        variant={isFind ? "accent" : "primary"}
        icon={isPay ? "card-outline" : isFind ? "search-outline" : "ticket-outline"}
        onPress={hero.onPress}
        accessibilityLabel={`${hero.ctaLabel}: ${hero.title}`}
        style={styles.cta}
      />
    </Surface>
  )
}

const styles = StyleSheet.create({
  surface: {
    padding: spacing.lg,
    gap: spacing.xs,
  },
  eyebrowRow: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.sm,
  },
  title: {
    fontSize: typeScale.headline,
  },
  metaPay: {
    color: colors.amber700,
  },
  cta: {
    marginTop: spacing.md,
    alignSelf: "stretch",
  },
})

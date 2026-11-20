import { StyleSheet } from "react-native"
import { router, type Href } from "expo-router"

import { Button } from "@/components/ui/button"
import { Surface } from "@/components/ui/surface"
import { DisplayTitle, Eyebrow, Meta } from "@/components/ui/typography"
import { colors, spacing, typeScale } from "@/lib/theme"

export type OrganizerHeroAction = {
  eyebrow: string
  title: string
  body: string
  cta: string
  href: Href
  tone: "urgent" | "default"
}

export function OrganizerHomeHero({ hero }: { hero: OrganizerHeroAction }) {
  const isUrgent = hero.tone === "urgent"

  return (
    <Surface variant={isUrgent ? "urgent" : "accent"} accentBorderTop={!isUrgent} style={styles.surface}>
      <Eyebrow style={isUrgent ? styles.eyebrowUrgent : undefined}>{hero.eyebrow}</Eyebrow>
      <DisplayTitle style={styles.title} numberOfLines={3}>
        {hero.title}
      </DisplayTitle>
      <Meta style={styles.body} numberOfLines={3}>
        {hero.body}
      </Meta>
      <Button
        label={hero.cta}
        icon="arrow-forward"
        onPress={() => router.push(hero.href)}
        accessibilityLabel={`${hero.cta}: ${hero.title}`}
        style={styles.cta}
      />
    </Surface>
  )
}

const styles = StyleSheet.create({
  surface: {
    padding: spacing.xl,
    gap: spacing.sm,
  },
  eyebrowUrgent: {
    color: colors.amber700,
  },
  title: {
    fontSize: typeScale.title,
    lineHeight: 30,
  },
  body: {
    fontSize: typeScale.callout,
    lineHeight: 22,
    color: colors.gray700,
  },
  cta: {
    marginTop: spacing.sm,
    alignSelf: "flex-start",
  },
})

import { FlatList, StyleSheet, View } from "react-native"
import { router, type Href } from "expo-router"

import { EventCard } from "@/components/marketplace/event-card"
import { SectionHeader } from "@/components/ui/section-header"
import type { AppIconName } from "@/components/ui/app-icon"
import { savedOptionIds, type EventGroup } from "@/lib/api/event-groups"
import { spacing } from "@/lib/theme"

export function openEventGroup(group: EventGroup) {
  if (group.eventId) {
    router.push(`/event/${group.eventId}?option=${group.primaryOpportunityId}` as Href)
    return
  }
  router.push(`/opportunity/${group.primaryOpportunityId}` as Href)
}

/** Horizontal discovery rail of compact event cards. Hidden when there is nothing to show. */
export function EventRail({
  title,
  icon,
  groups,
  savedIds,
  onToggleSave,
  onSeeAll,
  testID,
}: {
  title: string
  icon: AppIconName
  groups: EventGroup[]
  savedIds: ReadonlySet<number>
  onToggleSave: (group: EventGroup) => void
  onSeeAll?: () => void
  testID?: string
}) {
  if (groups.length === 0) return null
  return (
    <View style={styles.section} testID={testID}>
      <SectionHeader title={title} icon={icon} actionLabel={onSeeAll ? "See all" : undefined} onAction={onSeeAll} />
      <FlatList
        horizontal
        data={groups}
        keyExtractor={(g) => g.key}
        showsHorizontalScrollIndicator={false}
        contentContainerStyle={styles.row}
        ItemSeparatorComponent={Gap}
        renderItem={({ item }) => (
          <EventCard
            group={item}
            variant="compact"
            saved={savedOptionIds(item, savedIds).length > 0}
            onToggleSave={() => onToggleSave(item)}
            onPress={() => openEventGroup(item)}
          />
        )}
      />
    </View>
  )
}

function Gap() {
  return <View style={styles.gap} />
}

const styles = StyleSheet.create({
  section: { gap: spacing.md },
  row: { paddingRight: spacing.lg },
  gap: { width: spacing.md },
})

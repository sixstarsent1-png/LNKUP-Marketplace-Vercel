import { StyleSheet, View } from "react-native"

import { Button } from "@/components/ui/button"
import { Surface } from "@/components/ui/surface"
import { Body, Meta, SectionTitle } from "@/components/ui/typography"
import type { ApplyDraftCard } from "@/lib/api/apply"
import { colors, spacing, typeScale } from "@/lib/theme"

type DraftResumeListProps = {
  drafts: ApplyDraftCard[]
  discardingId: number | null
  onResume: (draft: ApplyDraftCard) => void
  onDiscard: (draft: ApplyDraftCard) => void
}

function draftMeta(draft: ApplyDraftCard): string {
  return [draft.eventDate, draft.location, draft.updatedAt ? `Saved ${draft.updatedAt}` : ""].filter(Boolean).join(" · ")
}

function draftWarning(draft: ApplyDraftCard): string | null {
  if (!draft.canSubmit) return "Applications are closed for this booth."
  if (draft.priceChanged) return "The booth fee changed since you started."
  if (draft.deadline) return `Apply by ${draft.deadline}`
  return null
}

/** "Continue applying": unfinished server-side drafts, resumable on any device. Renders nothing when empty. */
export function DraftResumeList({ drafts, discardingId, onResume, onDiscard }: DraftResumeListProps) {
  if (drafts.length === 0) return null
  return (
    <View style={styles.section} testID="draft-resume-list">
      <SectionTitle accessibilityRole="header" style={styles.title}>
        Continue applying
      </SectionTitle>
      {drafts.map((draft) => {
        const warning = draftWarning(draft)
        return (
          <Surface key={draft.id} elevated style={styles.card} testID={`draft-card-${draft.opportunityId}`}>
            <Body style={styles.event}>{draft.eventName}</Body>
            {draft.boothName ? <Meta>{draft.boothName}</Meta> : null}
            <Meta>{draftMeta(draft)}</Meta>
            {warning ? (
              <Meta style={!draft.canSubmit || draft.priceChanged ? styles.warning : undefined}>{warning}</Meta>
            ) : null}
            <View style={styles.actions}>
              {draft.canSubmit ? (
                <Button label="Continue" size="sm" icon="create-outline" onPress={() => onResume(draft)} />
              ) : null}
              <Button
                label="Discard"
                size="sm"
                variant="ghost"
                loading={discardingId === draft.id}
                loadingLabel="Discarding…"
                onPress={() => onDiscard(draft)}
                accessibilityLabel={`Discard application for ${draft.eventName}`}
              />
            </View>
          </Surface>
        )
      })}
    </View>
  )
}

const styles = StyleSheet.create({
  section: { gap: spacing.sm },
  title: { fontSize: typeScale.bodyLarge },
  card: { gap: spacing.xs },
  event: { fontSize: typeScale.callout, fontWeight: "700", color: colors.black },
  warning: { color: colors.amber700 },
  actions: { flexDirection: "row", gap: spacing.sm, marginTop: spacing.sm },
})

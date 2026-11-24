import { ActivityIndicator, Modal, StyleSheet, View } from "react-native"
import { AppText as Text } from "@/components/ui/app-text"

import { Button } from "@/components/ui/button"
import { StatTile, StatGrid } from "@/components/ui/stat-tile"
import { Meta, SectionTitle } from "@/components/ui/typography"
import type { ApplicationIntel, OrganizerVendorRequest } from "@/lib/api/organizer-requests"
import { constrainedColumnStyle } from "@/lib/layout/use-breakpoint"
import { colors, layout, radii, spacing, typography } from "@/lib/theme"

/** Confirmation modal shown before approving: application intel + approve/profile/cancel actions. */
export function ApproveIntelModal({
  item,
  intel,
  loading,
  acting,
  onCancel,
  onOpenProfile,
  onApprove,
  note,
}: {
  item: OrganizerVendorRequest | null
  intel: ApplicationIntel | null
  loading: boolean
  acting: boolean
  onCancel: () => void
  onOpenProfile?: () => void
  onApprove: () => void
  /** What approving does (invoice, payment window), from the server. */
  note?: string
}) {
  return (
    <Modal visible={Boolean(item)} transparent animationType="fade" onRequestClose={onCancel}>
      <View style={styles.backdrop}>
        <View
          style={[styles.card, constrainedColumnStyle(layout.sheetMaxWidth)]}
          accessibilityViewIsModal
        >
          <SectionTitle accessibilityRole="header">
            {item?.vendorBusinessName ?? "Review vendor"}
          </SectionTitle>
          {item?.eventName ? <Meta>{item.eventName}</Meta> : null}

          {loading ? (
            <View style={styles.loading} accessibilityRole="progressbar" accessibilityLabel="Loading vendor intel">
              <ActivityIndicator color={colors.black} />
              <Meta>Checking readiness…</Meta>
            </View>
          ) : null}

          {!loading && intel ? (
            <View style={styles.intelBlock}>
              <StatGrid>
                {intel.readinessLabel ? (
                  <StatTile label="Readiness" value={intel.readinessLabel} tone="accent" />
                ) : null}
                {intel.paymentLikelihood != null ? (
                  <StatTile label="Payment likelihood" value={String(intel.paymentLikelihood)} />
                ) : null}
                {intel.noShowLikelihood != null ? (
                  <StatTile label="No-show risk" value={String(intel.noShowLikelihood)} />
                ) : null}
              </StatGrid>
              {intel.message ? (
                <View style={styles.messageBox}>
                  <Text style={styles.message} numberOfLines={6}>
                    {intel.message}
                  </Text>
                </View>
              ) : (
                <Meta>No application message.</Meta>
              )}
            </View>
          ) : null}

          {!loading && !intel ? <Meta>Intel unavailable — you can still approve.</Meta> : null}

          {note ? <Meta testID="approve-note">{note}</Meta> : null}

          <Button
            label="Approve vendor"
            icon="checkmark"
            disabled={!item}
            loading={acting}
            onPress={onApprove}
            style={styles.approve}
          />
          <View style={styles.secondaryRow}>
            <Button label="Cancel" variant="secondary" style={styles.flex} onPress={onCancel} />
            {onOpenProfile ? (
              <Button
                label="Profile"
                icon="person-outline"
                variant="secondary"
                style={styles.flex}
                onPress={onOpenProfile}
              />
            ) : null}
          </View>
        </View>
      </View>
    </Modal>
  )
}

const styles = StyleSheet.create({
  backdrop: {
    flex: 1,
    backgroundColor: colors.overlay,
    justifyContent: "center",
    padding: spacing.lg,
  },
  card: {
    backgroundColor: colors.white,
    borderRadius: radii.xl,
    padding: spacing.xl,
    gap: spacing.md,
  },
  loading: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.sm,
  },
  intelBlock: {
    gap: spacing.md,
  },
  messageBox: {
    backgroundColor: colors.gray50,
    borderRadius: radii.md,
    padding: spacing.md,
  },
  message: {
    ...typography.body,
  },
  approve: {
    marginTop: spacing.xs,
  },
  secondaryRow: {
    flexDirection: "row",
    gap: spacing.sm,
  },
  flex: {
    flex: 1,
  },
})

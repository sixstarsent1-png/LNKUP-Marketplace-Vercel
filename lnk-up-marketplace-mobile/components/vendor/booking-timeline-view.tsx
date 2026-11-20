import { StyleSheet, View } from "react-native"

import { AppIcon, type AppIconName } from "@/components/ui/app-icon"
import { AppText as Text } from "@/components/ui/app-text"
import type { TimelineState, TimelineStep } from "@/lib/vendor/booking-timeline"
import { colors, fonts, spacing, typeScale } from "@/lib/theme"

const DOT: Record<TimelineState, { icon: AppIconName; bg: string; fg: string; border: string }> = {
  done: { icon: "checkmark", bg: colors.black, fg: colors.white, border: colors.black },
  current: { icon: "ellipse", bg: colors.lime, fg: colors.black, border: colors.black },
  upcoming: { icon: "ellipse-outline", bg: colors.white, fg: colors.gray400, border: colors.gray200 },
  stopped: { icon: "close", bg: colors.red50, fg: colors.red600, border: colors.red200 },
}

const STATE_LABEL: Record<TimelineState, string> = {
  done: "completed",
  current: "current step",
  upcoming: "not started",
  stopped: "ended",
}

/** Vertical progress timeline for a vendor booking. */
export function BookingTimelineView({ steps }: { steps: TimelineStep[] }) {
  return (
    <View style={styles.list} accessibilityRole="list" testID="booking-timeline">
      {steps.map((step, index) => {
        const dot = DOT[step.state]
        const last = index === steps.length - 1
        return (
          <View
            key={step.key}
            style={styles.row}
            accessible
            accessibilityLabel={`${step.label}, ${STATE_LABEL[step.state]}${step.detail ? `. ${step.detail}` : ""}`}
          >
            <View style={styles.rail}>
              <View style={[styles.dot, { backgroundColor: dot.bg, borderColor: dot.border }]}>
                <AppIcon name={dot.icon} size={step.state === "current" ? 8 : 12} color={dot.fg} />
              </View>
              {!last ? <View style={[styles.line, step.state === "done" && styles.lineDone]} /> : null}
            </View>
            <View style={[styles.copy, !last && styles.copySpaced]}>
              <Text
                style={[
                  styles.label,
                  step.state === "upcoming" && styles.labelMuted,
                  step.state === "stopped" && styles.labelStopped,
                ]}
              >
                {step.label}
              </Text>
              {step.detail ? <Text style={styles.detail}>{step.detail}</Text> : null}
            </View>
          </View>
        )
      })}
    </View>
  )
}

const styles = StyleSheet.create({
  list: { gap: 0 },
  row: { flexDirection: "row", gap: spacing.md },
  rail: { alignItems: "center", width: 24 },
  dot: {
    width: 24,
    height: 24,
    borderRadius: 12,
    borderWidth: 2,
    alignItems: "center",
    justifyContent: "center",
  },
  line: { flex: 1, width: 2, backgroundColor: colors.gray200, marginVertical: 2 },
  lineDone: { backgroundColor: colors.black },
  copy: { flex: 1, paddingTop: 2 },
  copySpaced: { paddingBottom: spacing.lg },
  label: { fontFamily: fonts.bodySemi, fontSize: typeScale.callout, color: colors.black },
  labelMuted: { color: colors.gray500 },
  labelStopped: { color: colors.red600 },
  detail: { fontFamily: fonts.body, fontSize: typeScale.footnote, lineHeight: 18, color: colors.gray500, marginTop: 2 },
})

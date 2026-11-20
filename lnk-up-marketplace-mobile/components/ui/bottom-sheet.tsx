import type { ReactNode } from "react"
import {
  KeyboardAvoidingView,
  Modal,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  View,
} from "react-native"
import { useSafeAreaInsets } from "react-native-safe-area-context"

import { AppIcon } from "@/components/ui/app-icon"
import { SectionTitle } from "@/components/ui/typography"
import { constrainedColumnStyle } from "@/lib/layout/use-breakpoint"
import { colors, layout, radii, spacing } from "@/lib/theme"

type BottomSheetProps = {
  visible: boolean
  title: string
  onClose: () => void
  children: ReactNode
  footer?: ReactNode
}

/** Slide-up modal sheet with grabber, titled header, scrollable body, and safe-area-aware footer. */
export function BottomSheet({ visible, title, onClose, children, footer }: BottomSheetProps) {
  const insets = useSafeAreaInsets()

  return (
    <Modal visible={visible} animationType="slide" transparent onRequestClose={onClose}>
      <KeyboardAvoidingView
        style={styles.backdrop}
        behavior={Platform.OS === "ios" ? "padding" : undefined}
      >
        <Pressable
          style={StyleSheet.absoluteFill}
          onPress={onClose}
          accessibilityRole="button"
          accessibilityLabel={`Close ${title}`}
        />
        <View
          style={[styles.sheet, constrainedColumnStyle(layout.sheetMaxWidth)]}
          accessibilityViewIsModal
        >
          <View style={styles.grabber} />
          <View style={styles.header}>
            <SectionTitle accessibilityRole="header">{title}</SectionTitle>
            <Pressable
              onPress={onClose}
              accessibilityRole="button"
              accessibilityLabel={`Close ${title}`}
              hitSlop={8}
              style={({ pressed }) => [styles.closeBtn, pressed && styles.pressed]}
            >
              <AppIcon name="close" size={20} color={colors.gray700} />
            </Pressable>
          </View>

          <ScrollView contentContainerStyle={styles.body} keyboardShouldPersistTaps="handled">
            {children}
          </ScrollView>

          {footer ? (
            <View style={[styles.footer, { paddingBottom: Math.max(insets.bottom, spacing.lg) }]}>
              {footer}
            </View>
          ) : (
            <View style={{ height: insets.bottom }} />
          )}
        </View>
      </KeyboardAvoidingView>
    </Modal>
  )
}

const styles = StyleSheet.create({
  backdrop: {
    flex: 1,
    backgroundColor: colors.overlay,
    justifyContent: "flex-end",
  },
  sheet: {
    backgroundColor: colors.white,
    borderTopLeftRadius: radii.xl,
    borderTopRightRadius: radii.xl,
    maxHeight: "88%",
  },
  grabber: {
    alignSelf: "center",
    marginTop: spacing.sm,
    width: 40,
    height: 4,
    borderRadius: 2,
    backgroundColor: colors.gray200,
  },
  header: {
    flexDirection: "row",
    justifyContent: "space-between",
    alignItems: "center",
    paddingHorizontal: spacing.lg,
    paddingVertical: spacing.sm,
    borderBottomWidth: 1,
    borderBottomColor: colors.gray200,
  },
  closeBtn: {
    width: layout.hitTargetMin,
    height: layout.hitTargetMin,
    marginRight: -spacing.sm,
    alignItems: "center",
    justifyContent: "center",
    borderRadius: radii.pill,
  },
  pressed: {
    backgroundColor: colors.gray100,
  },
  body: {
    padding: spacing.lg,
    gap: spacing.sm,
    paddingBottom: spacing.xxl,
  },
  footer: {
    flexDirection: "row",
    gap: spacing.sm,
    paddingHorizontal: spacing.lg,
    paddingTop: spacing.md,
    borderTopWidth: 1,
    borderTopColor: colors.gray200,
  },
})

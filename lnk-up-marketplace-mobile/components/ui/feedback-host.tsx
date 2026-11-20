import { useEffect, useState, useSyncExternalStore } from "react"
import { Pressable, StyleSheet, View } from "react-native"
import { AppText as Text } from "@/components/ui/app-text"
import Animated, { FadeInUp, FadeOutUp, useReducedMotion } from "react-native-reanimated"
import { useSafeAreaInsets } from "react-native-safe-area-context"

import { AppIcon, type AppIconName } from "@/components/ui/app-icon"
import { BottomSheet } from "@/components/ui/bottom-sheet"
import { Button } from "@/components/ui/button"
import { FormField } from "@/components/ui/form-field"
import { Body } from "@/components/ui/typography"
import {
  dismissToast,
  getActiveDialog,
  getToasts,
  settleDialog,
  subscribeFeedback,
  type PromptRequest,
  type ToastTone,
} from "@/lib/feedback/feedback"
import { colors, fonts, layout, radii, shadow, spacing, typeScale } from "@/lib/theme"

const TOAST_TONES: Record<ToastTone, { icon: AppIconName; bg: string; fg: string }> = {
  success: { icon: "checkmark-circle", bg: colors.black, fg: colors.white },
  error: { icon: "alert-circle", bg: colors.red600, fg: colors.white },
  info: { icon: "information-circle", bg: colors.gray900, fg: colors.white },
}

/** Renders toasts and the active confirm/prompt dialog. Mount once at the root. */
export function FeedbackHost() {
  const toasts = useSyncExternalStore(subscribeFeedback, getToasts, getToasts)
  const dialog = useSyncExternalStore(subscribeFeedback, getActiveDialog, getActiveDialog)
  const insets = useSafeAreaInsets()
  const reduceMotion = useReducedMotion()

  return (
    <>
      <View pointerEvents="box-none" style={[styles.toastStack, { top: insets.top + spacing.sm }]}>
        {toasts.map((toast) => {
          const tone = TOAST_TONES[toast.tone]
          return (
            <Animated.View
              key={toast.id}
              entering={reduceMotion ? undefined : FadeInUp.duration(180)}
              exiting={reduceMotion ? undefined : FadeOutUp.duration(160)}
              style={[styles.toast, { backgroundColor: tone.bg }]}
            >
              <Pressable
                onPress={() => dismissToast(toast.id)}
                accessibilityRole="alert"
                accessibilityLiveRegion={toast.tone === "error" ? "assertive" : "polite"}
                accessibilityHint="Tap to dismiss"
                style={styles.toastInner}
              >
                <AppIcon name={tone.icon} size={18} color={tone.fg} />
                <Text style={[styles.toastText, { color: tone.fg }]}>{toast.message}</Text>
              </Pressable>
            </Animated.View>
          )
        })}
      </View>

      {dialog?.kind === "confirm" ? (
        <BottomSheet visible title={dialog.title} onClose={() => settleDialog(dialog.id, false)}
          footer={
            <>
              <Button
                label={dialog.cancelLabel}
                variant="secondary"
                style={styles.footerButton}
                onPress={() => settleDialog(dialog.id, false)}
              />
              <Button
                label={dialog.confirmLabel}
                variant={dialog.destructive ? "danger" : "primary"}
                style={styles.footerButton}
                onPress={() => settleDialog(dialog.id, true)}
              />
            </>
          }
        >
          {dialog.message ? <Body>{dialog.message}</Body> : null}
        </BottomSheet>
      ) : null}

      {dialog?.kind === "prompt" ? <PromptSheet key={dialog.id} dialog={dialog} /> : null}
    </>
  )
}

function PromptSheet({ dialog }: { dialog: PromptRequest }) {
  const [value, setValue] = useState(dialog.initialValue ?? "")
  const [touched, setTouched] = useState(false)
  const trimmed = value.trim()
  const tooShort = trimmed.length < dialog.minLength

  useEffect(() => {
    setValue(dialog.initialValue ?? "")
  }, [dialog.initialValue])

  function submit() {
    setTouched(true)
    if (tooShort) return
    settleDialog(dialog.id, trimmed)
  }

  return (
    <BottomSheet
      visible
      title={dialog.title}
      onClose={() => settleDialog(dialog.id, null)}
      footer={
        <>
          <Button
            label={dialog.cancelLabel}
            variant="secondary"
            style={styles.footerButton}
            onPress={() => settleDialog(dialog.id, null)}
          />
          <Button label={dialog.confirmLabel} style={styles.footerButton} onPress={submit} />
        </>
      }
    >
      {dialog.message ? <Body>{dialog.message}</Body> : null}
      <FormField
        label={dialog.title}
        value={value}
        onChangeText={setValue}
        placeholder={dialog.placeholder}
        multiline={dialog.multiline}
        autoFocus
        error={
          touched && tooShort
            ? dialog.minLength > 1
              ? `Enter at least ${dialog.minLength} characters.`
              : "This field is required."
            : undefined
        }
        onSubmitEditing={dialog.multiline ? undefined : submit}
      />
    </BottomSheet>
  )
}

const styles = StyleSheet.create({
  toastStack: {
    position: "absolute",
    left: spacing.lg,
    right: spacing.lg,
    zIndex: 1000,
    gap: spacing.sm,
    alignItems: "center",
  },
  toast: {
    width: "100%",
    maxWidth: layout.sheetMaxWidth,
    borderRadius: radii.md,
    ...shadow("md"),
  },
  toastInner: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.sm,
    paddingHorizontal: spacing.lg,
    paddingVertical: spacing.md,
    minHeight: layout.hitTargetMin,
  },
  toastText: {
    flex: 1,
    fontFamily: fonts.bodyMedium,
    fontSize: typeScale.body,
    lineHeight: 20,
  },
  footerButton: {
    flex: 1,
  },
})

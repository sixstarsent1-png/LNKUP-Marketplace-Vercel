import { StyleSheet, TextInput, View, type TextInputProps } from "react-native"
import { AppText as Text } from "@/components/ui/app-text"

import { colors, fonts, fontScaleCaps, layout, radii, spacing, typography } from "@/lib/theme"

type FormFieldProps = Omit<TextInputProps, "style" | "placeholderTextColor"> & {
  label: string
  hint?: string
  error?: string
  tone?: "default" | "muted"
}

/** Labeled text input with consistent sizing, hint, and error text. */
export function FormField({ label, hint, error, multiline, tone = "default", ...props }: FormFieldProps) {
  return (
    <View style={styles.field}>
      <Text style={styles.label} maxFontSizeMultiplier={fontScaleCaps.label}>
        {label}
      </Text>
      <TextInput
        maxFontSizeMultiplier={fontScaleCaps.control}
        {...props}
        accessibilityLabel={props.accessibilityLabel ?? label}
        accessibilityHint={hint}
        multiline={multiline}
        placeholderTextColor={colors.gray500}
        style={[
          styles.input,
          tone === "muted" && styles.inputMuted,
          multiline && styles.textarea,
          error ? styles.inputError : null,
        ]}
      />
      {error ? (
        <Text style={styles.error} accessibilityLiveRegion="polite">
          {error}
        </Text>
      ) : hint ? (
        <Text style={styles.hint}>{hint}</Text>
      ) : null}
    </View>
  )
}

const styles = StyleSheet.create({
  field: {
    gap: spacing.xs,
  },
  label: {
    ...typography.eyebrow,
    letterSpacing: 0.5,
  },
  input: {
    minHeight: layout.hitTargetMin,
    borderWidth: 1,
    borderColor: colors.gray200,
    borderRadius: radii.md,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.md,
    fontSize: 15,
    fontFamily: fonts.body,
    color: colors.black,
    backgroundColor: colors.white,
  },
  inputMuted: {
    backgroundColor: colors.gray50,
  },
  inputError: {
    borderColor: colors.red600,
  },
  textarea: {
    minHeight: 88,
    textAlignVertical: "top",
  },
  hint: {
    ...typography.meta,
    fontSize: 12,
  },
  error: {
    ...typography.meta,
    fontSize: 12,
    color: colors.red600,
  },
})

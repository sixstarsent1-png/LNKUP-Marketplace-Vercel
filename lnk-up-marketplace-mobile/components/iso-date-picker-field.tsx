import { useCallback, useMemo, useState, type ComponentType } from "react"
import {
  Platform,
  Pressable,
  StyleSheet,
  TextInput,
  View,
} from "react-native"
import { AppText as Text } from "@/components/ui/app-text"

import { colors, radii, spacing, typeScale } from "@/lib/theme"

const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/

export function isValidIsoDate(value?: string): boolean {
  if (!value || !ISO_DATE.test(value)) return false
  const parsed = new Date(`${value}T12:00:00`)
  return !Number.isNaN(parsed.getTime())
}

function toIsoDate(date: Date): string {
  const y = date.getFullYear()
  const m = String(date.getMonth() + 1).padStart(2, "0")
  const d = String(date.getDate()).padStart(2, "0")
  return `${y}-${m}-${d}`
}

function fromIsoDate(value?: string): Date {
  if (value && isValidIsoDate(value)) {
    return new Date(`${value}T12:00:00`)
  }
  return new Date()
}

type DateTimePickerEvent = { type: string }
type DateTimePickerComponent = ComponentType<{
  value: Date
  mode: "date"
  display: "spinner" | "default"
  onChange: (event: DateTimePickerEvent, date?: Date) => void
}>

function loadDateTimePicker(): DateTimePickerComponent | null {
  try {
    // Lazy require — missing native module must not crash OTA on older binaries.
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    const mod = require("@react-native-community/datetimepicker")
    return (mod.default ?? mod) as DateTimePickerComponent
  } catch {
    return null
  }
}

/**
 * Native date picker that stores YYYY-MM-DD strings.
 * Falls back to text input when datetimepicker native module is unavailable.
 */
export function IsoDatePickerField({
  label,
  value,
  onChange,
  optional,
}: {
  label: string
  value?: string
  onChange: (iso: string) => void
  optional?: boolean
}) {
  const DateTimePicker = useMemo(() => loadDateTimePicker(), [])
  const [open, setOpen] = useState(false)
  const display = value && isValidIsoDate(value) ? value : optional ? "Optional" : "Select date"

  const handleChange = useCallback(
    (event: DateTimePickerEvent, date?: Date) => {
      if (Platform.OS === "android") {
        setOpen(false)
      }
      if (event.type === "dismissed") {
        setOpen(false)
        return
      }
      if (date) {
        onChange(toIsoDate(date))
      }
      if (Platform.OS === "ios" && event.type === "set") {
        setOpen(false)
      }
    },
    [onChange],
  )

  if (!DateTimePicker) {
    return (
      <View style={styles.wrap}>
        <Text style={styles.label}>{label}</Text>
        <TextInput
          value={value ?? ""}
          onChangeText={onChange}
          placeholder={optional ? "YYYY-MM-DD (optional)" : "YYYY-MM-DD"}
          placeholderTextColor={colors.gray400}
          autoCapitalize="none"
          autoCorrect={false}
          style={styles.input}
        />
      </View>
    )
  }

  return (
    <View style={styles.wrap}>
      <Text style={styles.label}>{label}</Text>
      <Pressable style={styles.button} onPress={() => setOpen(true)}>
        <Text style={[styles.value, (!value || !isValidIsoDate(value)) && styles.placeholder]}>
          {display}
        </Text>
      </Pressable>
      {open ? (
        <DateTimePicker
          value={fromIsoDate(value)}
          mode="date"
          display={Platform.OS === "ios" ? "spinner" : "default"}
          onChange={handleChange}
        />
      ) : null}
    </View>
  )
}

const styles = StyleSheet.create({
  wrap: {
    gap: spacing.xs,
  },
  label: {
    fontSize: typeScale.footnote,
    fontWeight: "600",
    color: colors.gray700,
  },
  button: {
    borderWidth: 1,
    borderColor: colors.gray200,
    borderRadius: radii.md,
    backgroundColor: colors.white,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.md,
  },
  input: {
    borderWidth: 1,
    borderColor: colors.gray200,
    borderRadius: radii.md,
    backgroundColor: colors.white,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.md,
    fontSize: typeScale.callout,
    fontWeight: "600",
    color: colors.black,
  },
  value: {
    fontSize: typeScale.callout,
    fontWeight: "600",
    color: colors.black,
  },
  placeholder: {
    color: colors.gray400,
    fontWeight: "500",
  },
})

import { Pressable, StyleSheet, View } from "react-native"

import { AppIcon } from "@/components/ui/app-icon"
import { AppTextInput } from "@/components/ui/app-text"
import { colors, fonts, layout, radii, spacing, typeScale } from "@/lib/theme"

type SearchFieldProps = {
  value: string
  onChangeText: (value: string) => void
  placeholder: string
  accessibilityLabel?: string
  testID?: string
  autoFocus?: boolean
}

/** Rounded search input with a clear button. */
export function SearchField({ value, onChangeText, placeholder, accessibilityLabel, testID, autoFocus }: SearchFieldProps) {
  return (
    <View style={styles.wrap}>
      <AppIcon name="search-outline" size={18} color={colors.gray500} />
      <AppTextInput
        value={value}
        onChangeText={onChangeText}
        placeholder={placeholder}
        placeholderTextColor={colors.gray500}
        accessibilityLabel={accessibilityLabel ?? placeholder}
        returnKeyType="search"
        autoCorrect={false}
        autoCapitalize="none"
        autoFocus={autoFocus}
        testID={testID}
        style={styles.input}
      />
      {value ? (
        <Pressable onPress={() => onChangeText("")} accessibilityRole="button" accessibilityLabel="Clear search" hitSlop={10}>
          <AppIcon name="close-circle" size={18} color={colors.gray400} />
        </Pressable>
      ) : null}
    </View>
  )
}

const styles = StyleSheet.create({
  wrap: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.sm,
    minHeight: layout.hitTargetMin,
    paddingHorizontal: spacing.md,
    borderRadius: radii.md,
    borderWidth: 1,
    borderColor: colors.line,
    backgroundColor: colors.white,
  },
  input: {
    flex: 1,
    minHeight: layout.hitTargetMin,
    fontFamily: fonts.body,
    fontSize: typeScale.callout,
    color: colors.black,
  },
})

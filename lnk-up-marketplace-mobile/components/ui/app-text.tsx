import { Text, TextInput, type TextInputProps, type TextProps } from "react-native"
import { forwardRef } from "react"

import { fontScaleCaps } from "@/lib/theme"

/**
 * Drop-in for React Native `Text` that caps Dynamic Type growth (React 19 removed defaultProps on
 * function components, so the cap can't be set globally). Pass `maxFontSizeMultiplier` to override.
 */
export function AppText({ maxFontSizeMultiplier = fontScaleCaps.meta, ...props }: TextProps) {
  return <Text maxFontSizeMultiplier={maxFontSizeMultiplier} {...props} />
}

/** Drop-in for `TextInput` with the same Dynamic Type cap as form labels. */
export const AppTextInput = forwardRef<TextInput, TextInputProps>(function AppTextInput(
  { maxFontSizeMultiplier = fontScaleCaps.control, ...props },
  ref,
) {
  return <TextInput ref={ref} maxFontSizeMultiplier={maxFontSizeMultiplier} {...props} />
})

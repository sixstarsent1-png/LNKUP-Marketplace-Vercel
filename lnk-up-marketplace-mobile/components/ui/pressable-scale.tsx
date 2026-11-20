import { Pressable, StyleSheet, type PressableProps, type StyleProp, type ViewStyle } from "react-native"
import { useReducedMotion } from "react-native-reanimated"

type PressableScaleProps = Omit<PressableProps, "style"> & {
  style?: StyleProp<ViewStyle>
  pressedStyle?: StyleProp<ViewStyle>
}

/** Card-level Pressable with consistent pressed feedback (slight dim + scale; dim only with Reduce Motion). */
export function PressableScale({ style, pressedStyle, disabled, ...rest }: PressableScaleProps) {
  const reduceMotion = useReducedMotion()
  return (
    <Pressable
      disabled={disabled}
      style={({ pressed }) => [
        style,
        pressed && !disabled && (pressedStyle ?? (reduceMotion ? styles.pressedStill : styles.pressed)),
      ]}
      {...rest}
    />
  )
}

const styles = StyleSheet.create({
  pressed: {
    opacity: 0.9,
    transform: [{ scale: 0.99 }],
  },
  pressedStill: {
    opacity: 0.85,
  },
})

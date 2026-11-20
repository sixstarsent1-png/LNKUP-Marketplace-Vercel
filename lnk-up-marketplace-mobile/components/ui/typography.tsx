import type { ReactNode } from "react"
import { Text as RNText, type TextProps } from "react-native"

import { fontScaleCaps, typography } from "@/lib/theme"

type TypoProps = TextProps & {
  children: ReactNode
  style?: TextProps["style"]
}

export function DisplayTitle({ children, style, ...rest }: TypoProps) {
  return (
    <RNText maxFontSizeMultiplier={fontScaleCaps.display} style={[typography.displayTitle, style]} {...rest}>
      {children}
    </RNText>
  )
}

export function SectionTitle({ children, style, ...rest }: TypoProps) {
  return (
    <RNText maxFontSizeMultiplier={fontScaleCaps.title} style={[typography.sectionTitle, style]} {...rest}>
      {children}
    </RNText>
  )
}

export function Eyebrow({ children, style, ...rest }: TypoProps) {
  return (
    <RNText maxFontSizeMultiplier={fontScaleCaps.label} style={[typography.eyebrow, style]} {...rest}>
      {children}
    </RNText>
  )
}

export function Body({ children, style, ...rest }: TypoProps) {
  return (
    <RNText maxFontSizeMultiplier={fontScaleCaps.body} style={[typography.body, style]} {...rest}>
      {children}
    </RNText>
  )
}

export function Meta({ children, style, ...rest }: TypoProps) {
  return (
    <RNText maxFontSizeMultiplier={fontScaleCaps.meta} style={[typography.meta, style]} {...rest}>
      {children}
    </RNText>
  )
}

export function Label({ children, style, ...rest }: TypoProps) {
  return (
    <RNText maxFontSizeMultiplier={fontScaleCaps.label} style={[typography.label, style]} {...rest}>
      {children}
    </RNText>
  )
}

export function Caption({ children, style, ...rest }: TypoProps) {
  return (
    <RNText maxFontSizeMultiplier={fontScaleCaps.label} style={[typography.caption, style]} {...rest}>
      {children}
    </RNText>
  )
}

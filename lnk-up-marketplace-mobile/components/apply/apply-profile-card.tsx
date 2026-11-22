import { useState } from "react"
import { Pressable, StyleSheet, View } from "react-native"

import { AppIcon } from "@/components/ui/app-icon"
import { AppText as Text } from "@/components/ui/app-text"
import { FormField } from "@/components/ui/form-field"
import {
  PROFILE_OVERRIDE_FIELDS,
  type ApplyProfile,
  type ProfileOverrideField,
  type ProfileOverrides,
} from "@/lib/api/apply"
import { colors, radii, spacing, typeScale } from "@/lib/theme"

const FIELD_COPY: Record<ProfileOverrideField, { label: string; multiline?: boolean; keyboard?: "email-address" | "phone-pad" }> = {
  businessName: { label: "Business name" },
  products: { label: "Products or services", multiline: true },
  setupNeeds: { label: "Setup needs", multiline: true },
  contactEmail: { label: "Contact email", keyboard: "email-address" },
  contactPhone: { label: "Contact phone", keyboard: "phone-pad" },
}

type ApplyProfileCardProps = {
  profile: ApplyProfile
  /** Current edits; a field counts as changed only when it differs from the profile. */
  overrides: ProfileOverrides
  changed: ProfileOverrides
  onChange: (field: ProfileOverrideField, value: string) => void
  onEditProfile: () => void
}

/**
 * What the organizer sees from the vendor profile. Edits here apply to this application only;
 * permanent changes go through the profile screen.
 */
export function ApplyProfileCard({ profile, overrides, changed, onChange, onEditProfile }: ApplyProfileCardProps) {
  const changedCount = Object.keys(changed).length
  const [open, setOpen] = useState(changedCount > 0)
  const summary = [overrides.businessName ?? profile.businessName, overrides.products ?? profile.products]
    .filter(Boolean)
    .join(" · ")

  return (
    <View style={styles.card} testID="apply-profile-card">
      <Pressable
        onPress={() => setOpen((value) => !value)}
        style={styles.header}
        accessibilityRole="button"
        accessibilityState={{ expanded: open }}
        accessibilityLabel={`Your profile, ${open ? "collapse" : "expand"} to edit for this application`}
      >
        <View style={styles.headerCopy}>
          <Text style={styles.title}>Your profile</Text>
          <Text style={styles.meta} numberOfLines={open ? undefined : 1}>
            {open ? "Shared with the organizer when you apply." : summary || "Shared with the organizer when you apply."}
          </Text>
          {changedCount > 0 ? (
            <Text style={styles.changed}>
              {changedCount} field{changedCount === 1 ? "" : "s"} changed for this application only
            </Text>
          ) : null}
        </View>
        <AppIcon name={open ? "chevron-up" : "chevron-down"} size={20} color={colors.gray500} />
      </Pressable>

      {open ? (
        <View style={styles.fields}>
          {PROFILE_OVERRIDE_FIELDS.map((field) => {
            const copy = FIELD_COPY[field]
            return (
              <FormField
                key={field}
                label={copy.label}
                hint={field in changed ? "Changed for this application only" : undefined}
                value={overrides[field] ?? profile[field]}
                onChangeText={(text) => onChange(field, text)}
                multiline={copy.multiline}
                keyboardType={copy.keyboard}
                autoCapitalize={copy.keyboard ? "none" : undefined}
                testID={`apply-profile-${field}`}
              />
            )
          })}
          <Pressable onPress={onEditProfile} hitSlop={8} accessibilityRole="link">
            <Text style={styles.link}>Update your profile for every application</Text>
          </Pressable>
        </View>
      ) : null}
    </View>
  )
}

const styles = StyleSheet.create({
  card: {
    borderWidth: 1,
    borderColor: colors.gray200,
    borderRadius: radii.lg,
    backgroundColor: colors.white,
  },
  header: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.md,
    padding: spacing.md,
    minHeight: 44,
  },
  headerCopy: { flex: 1, gap: 2 },
  title: { fontSize: typeScale.body, fontWeight: "800", color: colors.black },
  meta: { fontSize: typeScale.footnote, color: colors.gray500 },
  changed: { fontSize: typeScale.caption, fontWeight: "700", color: colors.amber700 },
  fields: { gap: spacing.md, paddingHorizontal: spacing.md, paddingBottom: spacing.md },
  link: { fontSize: typeScale.body, fontWeight: "700", color: colors.black, textDecorationLine: "underline" },
})

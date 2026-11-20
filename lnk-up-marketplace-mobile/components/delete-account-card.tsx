import { useState } from "react"
import { Pressable, StyleSheet, TextInput, View } from "react-native"
import { AppText as Text } from "@/components/ui/app-text"
import { router } from "expo-router"

import { requestAccountDeletion } from "@/lib/api/account"
import { appErrorFromWp } from "@/lib/api/errors"
import { useAuth } from "@/lib/auth/auth-context"
import { confirmAction, showToast } from "@/lib/feedback/feedback"
import { colors, radii, spacing, typeScale } from "@/lib/theme"

/**
 * In-app account deletion (Apple Guideline 5.1.1(v)).
 */
export function DeleteAccountCard() {
  const { auth, logout } = useAuth()
  const [open, setOpen] = useState(false)
  const [confirm, setConfirm] = useState("")
  const [busy, setBusy] = useState(false)

  async function handleDelete() {
    if (busy) return
    if (confirm.trim().toUpperCase() !== "DELETE") {
      showToast("Type DELETE in all caps to confirm.", "error")
      return
    }
    const ok = await confirmAction({
      title: "Delete your LNKup account?",
      message:
        "Your profile and personal data will be deleted within 30 days. Payment records we're required to keep are retained. This can't be undone.",
      confirmLabel: "Delete account",
      destructive: true,
    })
    if (!ok) return
    setBusy(true)
    try {
      const result = await requestAccountDeletion(auth, confirm.trim())
      showToast(
        result.message || "Account deletion scheduled. Your account will be deleted within 30 days.",
        "success",
      )
      await logout()
      router.replace("/login")
    } catch (err) {
      showToast(`Couldn't schedule deletion. ${appErrorFromWp(err).message}`, "error")
    } finally {
      setBusy(false)
    }
  }

  return (
    <View style={styles.card} testID="delete-account-card">
      <Text style={styles.title}>Delete account</Text>
      <Text style={styles.body}>
        Permanently delete your LNKup account and personal data. Payment records we must keep for
        tax/legal reasons may be retained in anonymized form. Deletion completes within 30 days.
      </Text>
      {!open ? (
        <Pressable
          style={styles.dangerOutline}
          onPress={() => setOpen(true)}
          accessibilityRole="button"
          accessibilityLabel="Start account deletion"
          testID="delete-account-open"
        >
          <Text style={styles.dangerOutlineText}>Delete my account…</Text>
        </Pressable>
      ) : (
        <View style={styles.confirmBlock}>
          <Text style={styles.hint}>Type DELETE to confirm</Text>
          <TextInput
            style={styles.input}
            value={confirm}
            onChangeText={setConfirm}
            autoCapitalize="characters"
            autoCorrect={false}
            placeholder="DELETE"
            placeholderTextColor={colors.gray400}
            editable={!busy}
            testID="delete-account-confirm"
          />
          <Pressable
            style={[styles.dangerSolid, busy && styles.disabled]}
            onPress={() => void handleDelete()}
            disabled={busy}
            accessibilityRole="button"
            testID="delete-account-submit"
          >
            <Text style={styles.dangerSolidText}>{busy ? "Deleting…" : "Confirm deletion"}</Text>
          </Pressable>
          <Pressable onPress={() => setOpen(false)} disabled={busy} hitSlop={8}>
            <Text style={styles.cancel}>Cancel</Text>
          </Pressable>
        </View>
      )}
    </View>
  )
}

const styles = StyleSheet.create({
  card: {
    borderWidth: 1,
    borderColor: colors.red50,
    backgroundColor: colors.white,
    borderRadius: radii.lg,
    padding: spacing.lg,
    gap: spacing.sm,
  },
  title: {
    fontSize: typeScale.bodyLarge,
    fontWeight: "700",
    color: colors.red600,
  },
  body: {
    fontSize: typeScale.footnote,
    lineHeight: 18,
    color: colors.gray500,
  },
  dangerOutline: {
    marginTop: spacing.sm,
    borderWidth: 1,
    borderColor: colors.red600,
    borderRadius: radii.md,
    paddingVertical: spacing.md,
    alignItems: "center",
  },
  dangerOutlineText: {
    fontSize: typeScale.body,
    fontWeight: "700",
    color: colors.red600,
  },
  confirmBlock: {
    marginTop: spacing.sm,
    gap: spacing.sm,
  },
  hint: {
    fontSize: typeScale.caption,
    fontWeight: "600",
    color: colors.gray500,
  },
  input: {
    borderWidth: 1,
    borderColor: colors.gray200,
    borderRadius: radii.md,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
    fontSize: typeScale.bodyLarge,
    fontWeight: "700",
    color: colors.black,
  },
  dangerSolid: {
    backgroundColor: colors.red600,
    borderRadius: radii.md,
    paddingVertical: spacing.md,
    alignItems: "center",
  },
  dangerSolidText: {
    fontSize: typeScale.body,
    fontWeight: "800",
    color: colors.white,
  },
  disabled: {
    opacity: 0.6,
  },
  cancel: {
    textAlign: "center",
    fontSize: typeScale.footnote,
    fontWeight: "600",
    color: colors.gray500,
    paddingTop: spacing.xs,
  },
})

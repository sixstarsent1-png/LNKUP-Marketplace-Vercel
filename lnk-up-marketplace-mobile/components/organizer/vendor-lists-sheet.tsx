import { useCallback, useEffect, useState } from "react"
import { Pressable, StyleSheet, View } from "react-native"

import { LoadingPanel } from "@/components/panel-states"
import { AppIcon } from "@/components/ui/app-icon"
import { AppText as Text } from "@/components/ui/app-text"
import { BottomSheet } from "@/components/ui/bottom-sheet"
import { Button } from "@/components/ui/button"
import { Meta } from "@/components/ui/typography"
import { appErrorFromWp } from "@/lib/api/errors"
import { createVendorList, fetchVendorListMembers, fetchVendorLists, setVendorOnList, type VendorList } from "@/lib/api/organizer-crm"
import type { WpAuth } from "@/lib/api/wp-client"
import { promptText, showToast } from "@/lib/feedback/feedback"
import { colors, layout, radii, spacing, typography } from "@/lib/theme"

type VendorListsSheetProps = {
  visible: boolean
  onClose: () => void
  profileId: string
  vendorName: string
  auth: WpAuth | undefined
}

/** Add a vendor to (or remove from) the organizer's private lists. */
export function VendorListsSheet({ visible, onClose, profileId, vendorName, auth }: VendorListsSheetProps) {
  const [lists, setLists] = useState<VendorList[] | null>(null)
  const [membership, setMembership] = useState<Record<string, boolean>>({})
  const [busyId, setBusyId] = useState<string | null>(null)

  const load = useCallback(async () => {
    if (!auth) return
    try {
      const all = await fetchVendorLists(auth, { force: true })
      const members = await Promise.all(all.map((list) => fetchVendorListMembers(list.id, auth).catch(() => [] as string[])))
      setLists(all)
      setMembership(Object.fromEntries(all.map((list, i) => [list.id, members[i]?.includes(profileId) ?? false])))
    } catch (err) {
      setLists([])
      showToast(appErrorFromWp(err).message, "error")
    }
  }, [auth, profileId])

  useEffect(() => {
    if (visible) void load()
  }, [visible, load])

  async function toggle(list: VendorList) {
    if (!auth || busyId) return
    const next = !membership[list.id]
    setBusyId(list.id)
    try {
      await setVendorOnList(list.id, profileId, next, auth)
      setMembership((prev) => ({ ...prev, [list.id]: next }))
      showToast(next ? `Added to ${list.name}.` : `Removed from ${list.name}.`, "success")
    } catch (err) {
      showToast(appErrorFromWp(err).message, "error")
    } finally {
      setBusyId(null)
    }
  }

  async function createList() {
    if (!auth) return
    const name = await promptText({ title: "New list", message: "Lists are private to your organization.", placeholder: "e.g. Summer favorites", confirmLabel: "Create", minLength: 2 })
    if (!name) return
    try {
      const id = await createVendorList(name, auth)
      if (id) await setVendorOnList(id, profileId, true, auth)
      showToast(`Added to ${name}.`, "success")
      await load()
    } catch (err) {
      showToast(appErrorFromWp(err).message, "error")
    }
  }

  return (
    <BottomSheet
      visible={visible}
      onClose={onClose}
      title={`Save ${vendorName}`}
      footer={<Button label="New list" icon="add" variant="secondary" fullWidth onPress={() => void createList()} />}
    >
      {lists === null ? <LoadingPanel label="Loading lists…" variant="spinner" /> : null}
      {lists && lists.length === 0 ? <Meta>You don&apos;t have any lists yet. Create one to group vendors you like.</Meta> : null}
      <View style={styles.list}>
        {(lists ?? []).map((list) => {
          const on = Boolean(membership[list.id])
          return (
            <Pressable
              key={list.id}
              onPress={() => void toggle(list)}
              disabled={busyId === list.id}
              accessibilityRole="checkbox"
              accessibilityState={{ checked: on, busy: busyId === list.id }}
              aria-checked={on}
              style={[styles.row, on && styles.rowOn]}
            >
              <AppIcon name={on ? "checkbox" : "square-outline"} size={20} color={colors.black} />
              <Text style={styles.name}>{list.name}</Text>
              <Text style={styles.count}>{list.count}</Text>
            </Pressable>
          )
        })}
      </View>
    </BottomSheet>
  )
}

const styles = StyleSheet.create({
  list: { gap: spacing.xs },
  row: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.md,
    minHeight: layout.hitTargetMin + 4,
    paddingHorizontal: spacing.md,
    borderRadius: radii.md,
    backgroundColor: colors.gray50,
  },
  rowOn: { backgroundColor: colors.limeMuted },
  name: { ...typography.label, flex: 1 },
  count: { ...typography.meta },
})

import { useEffect, useRef, useState, type ComponentType } from "react"
import { Modal, Platform, StyleSheet, View } from "react-native"
import { useSafeAreaInsets } from "react-native-safe-area-context"

import { Button } from "@/components/ui/button"
import { Body, SectionTitle } from "@/components/ui/typography"
import { colors, radii, spacing } from "@/lib/theme"

type CameraModule = {
  CameraView: ComponentType<{
    style?: unknown
    facing?: "back" | "front"
    barcodeScannerSettings?: { barcodeTypes: string[] }
    onBarcodeScanned?: (result: { data: string }) => void
  }>
  requestCameraPermissionsAsync: () => Promise<{ granted: boolean }>
}

/** expo-camera ships in the 1.1.0 binary; older binaries keep typed pass codes only. */
function camera(): CameraModule | null {
  if (Platform.OS === "web") return null
  try {
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    return require("expo-camera") as CameraModule
  } catch {
    return null
  }
}

export function hasPassScanner(): boolean {
  return camera() !== null
}

/** Full-screen QR scanner for vendor passes; hands back the first code it reads. */
export function PassScanner({
  visible,
  onCode,
  onClose,
}: {
  visible: boolean
  onCode: (code: string) => void
  onClose: () => void
}) {
  const insets = useSafeAreaInsets()
  const [allowed, setAllowed] = useState<boolean | null>(null)
  const handled = useRef(false)
  const mod = camera()

  useEffect(() => {
    if (!visible || !mod) return
    handled.current = false
    void mod.requestCameraPermissionsAsync().then((res) => setAllowed(res.granted))
  }, [visible, mod])

  if (!mod) return null
  const { CameraView } = mod

  return (
    <Modal visible={visible} animationType="slide" onRequestClose={onClose}>
      <View style={[styles.root, { paddingTop: insets.top, paddingBottom: Math.max(insets.bottom, spacing.lg) }]}>
        <SectionTitle style={styles.title}>Scan a vendor pass</SectionTitle>
        {allowed === false ? (
          <Body style={styles.copy}>Allow camera access in Settings to scan passes, or type the code instead.</Body>
        ) : (
          <View style={styles.frame}>
            <CameraView
              style={StyleSheet.absoluteFill}
              facing="back"
              barcodeScannerSettings={{ barcodeTypes: ["qr"] }}
              onBarcodeScanned={({ data }) => {
                if (handled.current || !data) return
                handled.current = true
                onCode(data)
              }}
            />
          </View>
        )}
        <Button label="Type the code instead" variant="secondary" onPress={onClose} />
      </View>
    </Modal>
  )
}

const styles = StyleSheet.create({
  root: {
    flex: 1,
    backgroundColor: colors.black,
    paddingHorizontal: spacing.lg,
    gap: spacing.lg,
  },
  title: {
    color: colors.white,
    marginTop: spacing.lg,
  },
  copy: {
    color: colors.gray200,
  },
  frame: {
    flex: 1,
    borderRadius: radii.lg,
    overflow: "hidden",
    borderWidth: 2,
    borderColor: colors.lime,
  },
})

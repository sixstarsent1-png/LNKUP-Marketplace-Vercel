import { useCallback, useEffect, useRef, useState } from "react"
import {
  ActivityIndicator,
  Modal,
  Pressable,
  StyleSheet,
  TextInput,
  View,
} from "react-native"
import { AppText as Text } from "@/components/ui/app-text"
import * as DocumentPicker from "expo-document-picker"

import { EmptyPanel, ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { Screen } from "@/components/screen"
import { BackLink } from "@/components/ui/back-link"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import {
  DOCUMENT_TYPE_OPTIONS,
  documentStatusLabel,
  fetchVendorDocuments,
  MAX_DOCUMENT_UPLOAD_BYTES,
  shareVendorDocument,
  uploadVendorDocument,
  type VendorDocumentRecord,
  type VendorDocumentStatus,
} from "@/lib/api/vendor-documents"
import { useAuth } from "@/lib/auth/auth-context"
import { showToast } from "@/lib/feedback/feedback"
import { colors, radii, spacing } from "@/lib/theme"

const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/

function statusColor(status: VendorDocumentStatus): string {
  switch (status) {
    case "approved":
      return colors.green700
    case "pending":
      return colors.amber700
    case "rejected":
    case "expired":
      return colors.red600
    default:
      return colors.gray500
  }
}

function hasFile(item: VendorDocumentRecord): boolean {
  return Boolean(item.id && !item.id.startsWith("type-") && item.status !== "missing")
}

export default function VendorDocumentsScreen() {
  const { auth } = useAuth()
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [items, setItems] = useState<VendorDocumentRecord[]>([])
  const [uploadingType, setUploadingType] = useState<string | null>(null)
  const pickingRef = useRef(false)
  const uploadInFlight = useRef(false)
  const [downloadingId, setDownloadingId] = useState<string | null>(null)
  const [uploadMessage, setUploadMessage] = useState<string | null>(null)
  const [pendingUpload, setPendingUpload] = useState<{
    documentType: string
    uri: string
    fileName: string
    mimeType?: string
  } | null>(null)
  const [expirationDate, setExpirationDate] = useState("")

  const load = useCallback(
    async (mode: "initial" | "soft" = "initial") => {
      const hasItems = items.length > 0
      if (mode === "soft" && hasItems) {
        setRefreshing(true)
      } else {
        setLoading(true)
      }
      setError(null)
      try {
        const result = await fetchVendorDocuments(auth)
        setItems(result.items)
      } catch (err) {
        setError(appErrorFromWp(err))
        if (!(mode === "soft" && hasItems)) {
          setItems([])
        }
      } finally {
        setLoading(false)
        setRefreshing(false)
      }
    },
    [auth, items.length],
  )

  useEffect(() => {
    void load("initial")
    // eslint-disable-next-line react-hooks/exhaustive-deps -- mount once; auth changes remount via load deps in practice
  }, [auth])

  async function pickAndUpload(documentType: string) {
    if (pickingRef.current || uploadingType || pendingUpload) return
    pickingRef.current = true
    setUploadMessage(null)
    try {
      const picked = await DocumentPicker.getDocumentAsync({
        type: ["application/pdf", "image/jpeg", "image/png", "image/jpg"],
        copyToCacheDirectory: true,
        multiple: false,
      })

      if (picked.canceled || !picked.assets?.[0]) {
        return
      }

      const asset = picked.assets[0]
      if (asset.size != null && asset.size > MAX_DOCUMENT_UPLOAD_BYTES) {
        setUploadMessage("File must be 10MB or smaller.")
        return
      }

      setExpirationDate("")
      setPendingUpload({
        documentType,
        uri: asset.uri,
        fileName: asset.name || `${documentType}.pdf`,
        mimeType: asset.mimeType || "application/pdf",
      })
    } catch (err) {
      const shaped = appErrorFromWp(err)
      setUploadMessage(shaped.message)
    } finally {
      pickingRef.current = false
    }
  }

  async function confirmUpload() {
    if (!pendingUpload || uploadInFlight.current) return
    const exp = expirationDate.trim()
    if (exp && !ISO_DATE.test(exp)) {
      showToast("Use YYYY-MM-DD for the expiration date, or leave it blank.", "error")
      return
    }

    uploadInFlight.current = true
    setUploadingType(pendingUpload.documentType)
    try {
      await uploadVendorDocument(
        {
          documentType: pendingUpload.documentType,
          uri: pendingUpload.uri,
          fileName: pendingUpload.fileName,
          mimeType: pendingUpload.mimeType,
          expirationDate: exp || undefined,
        },
        auth,
      )
      setUploadMessage(null)
      showToast("Document uploaded. We'll let you know once it's reviewed.", "success")
      setPendingUpload(null)
      setExpirationDate("")
      await load("soft")
    } catch (err) {
      const shaped = appErrorFromWp(err)
      setUploadMessage(`Upload failed. ${shaped.message}`)
    } finally {
      uploadInFlight.current = false
      setUploadingType(null)
    }
  }

  async function handleDownload(item: VendorDocumentRecord) {
    if (downloadingId) return
    setDownloadingId(item.id)
    setUploadMessage(null)
    try {
      await shareVendorDocument(item.id, auth, item.fileName)
    } catch (err) {
      showToast(`Couldn't open this document. ${appErrorFromWp(err).message}`, "error")
    } finally {
      setDownloadingId(null)
    }
  }

  const showInitialLoader = loading && items.length === 0

  return (
    <Screen
      title="Documents"
      subtitle="Upload once. Reuse across events."
      refreshing={refreshing}
      onRefresh={() => void load("soft")}
    >
      <BackLink label="Profile" fallbackHref="/(vendor)/more" />

      {showInitialLoader ? <LoadingPanel label="Loading documents…" /> : null}

      {!showInitialLoader && error && items.length === 0 ? (
        <ErrorPanel title={error.title} message={error.message} onAction={() => void load()} />
      ) : null}

      {error && items.length > 0 ? (
        <Text style={styles.refreshError}>Couldn't refresh. Pull to try again.</Text>
      ) : null}

      {uploadMessage ? <Text style={styles.message}>{uploadMessage}</Text> : null}

      {!showInitialLoader && !error && items.length === 0 ? (
        <EmptyPanel
          title="No documents yet"
          message="Upload compliance documents to get approved faster."
        />
      ) : null}

      {items.length > 0 ? (
        <View style={styles.list}>
          {items.map((item) => (
            <View key={item.id} style={styles.card}>
              <View style={styles.row}>
                <Text style={styles.title}>{item.label}</Text>
                {item.required ? <Text style={styles.required}>Required</Text> : null}
              </View>
              <Text style={[styles.status, { color: statusColor(item.status) }]}>
                {documentStatusLabel(item.status)}
              </Text>
              {item.expiresAt ? <Text style={styles.meta}>Expires {item.expiresAt}</Text> : null}
              {item.rejectionReason ? (
                <Text style={styles.reject}>{item.rejectionReason}</Text>
              ) : null}
              <View style={styles.actions}>
                {hasFile(item) ? (
                  <Pressable
                    style={styles.outlineBtn}
                    disabled={downloadingId === item.id}
                    onPress={() => void handleDownload(item)}
                  >
                    {downloadingId === item.id ? (
                      <ActivityIndicator color={colors.black} />
                    ) : (
                      <Text style={styles.outlineText}>View / Share</Text>
                    )}
                  </Pressable>
                ) : null}
                {(item.status === "missing" ||
                  item.status === "rejected" ||
                  item.status === "expired" ||
                  item.status === "pending") && (
                  <Pressable
                    style={[styles.uploadBtn, Boolean(uploadingType) && uploadingType !== item.type && styles.dimmed]}
                    disabled={Boolean(uploadingType)}
                    accessibilityRole="button"
                    accessibilityLabel={`${item.status === "missing" ? "Upload" : "Replace"} ${item.label}`}
                    onPress={() => void pickAndUpload(item.type)}
                  >
                    {uploadingType === item.type ? (
                      <ActivityIndicator color={colors.black} />
                    ) : (
                      <Text style={styles.uploadText}>
                        {item.status === "missing" ? "Upload" : "Replace"}
                      </Text>
                    )}
                  </Pressable>
                )}
              </View>
            </View>
          ))}
        </View>
      ) : null}

      {!showInitialLoader ? (
        <View style={styles.addSection}>
          <Text style={styles.addTitle}>Upload another type</Text>
          {DOCUMENT_TYPE_OPTIONS.slice(0, 6).map((opt) => (
            <Pressable
              key={opt.value}
              style={[styles.outline, Boolean(uploadingType) && uploadingType !== opt.value && styles.dimmed]}
              disabled={Boolean(uploadingType)}
              accessibilityRole="button"
              accessibilityLabel={`Upload ${opt.label}`}
              onPress={() => void pickAndUpload(opt.value)}
            >
              {uploadingType === opt.value ? (
                <ActivityIndicator color={colors.black} />
              ) : (
                <Text style={styles.outlineText}>{opt.label}</Text>
              )}
            </Pressable>
          ))}
        </View>
      ) : null}

      <Modal visible={Boolean(pendingUpload)} transparent animationType="fade">
        <View style={styles.modalBackdrop}>
          <View style={styles.modalCard}>
            <Text style={styles.modalTitle}>Expiration date</Text>
            <Text style={styles.modalBody}>
              Optional. Use YYYY-MM-DD for permits and insurance that expire.
            </Text>
            <TextInput
              value={expirationDate}
              onChangeText={setExpirationDate}
              placeholder="YYYY-MM-DD"
              placeholderTextColor={colors.gray400}
              autoCapitalize="none"
              style={styles.modalInput}
            />
            <View style={styles.modalActions}>
              <Pressable
                style={styles.modalCancel}
                onPress={() => {
                  setPendingUpload(null)
                  setExpirationDate("")
                }}
              >
                <Text style={styles.outlineText}>Cancel</Text>
              </Pressable>
              <Pressable
                style={styles.uploadBtn}
                disabled={Boolean(uploadingType)}
                onPress={() => void confirmUpload()}
              >
                {uploadingType ? (
                  <ActivityIndicator color={colors.black} />
                ) : (
                  <Text style={styles.uploadText}>Upload</Text>
                )}
              </Pressable>
            </View>
          </View>
        </View>
      </Modal>
    </Screen>
  )
}

const styles = StyleSheet.create({
  back: {
    marginBottom: spacing.sm,
  },
  backText: {
    fontSize: 14,
    fontWeight: "600",
    color: colors.gray700,
  },
  message: {
    fontSize: 13,
    color: colors.gray700,
    marginBottom: spacing.sm,
  },
  refreshError: {
    fontSize: 13,
    color: colors.amber700,
    marginBottom: spacing.sm,
  },
  list: {
    gap: spacing.md,
  },
  card: {
    backgroundColor: colors.white,
    borderRadius: radii.lg,
    borderWidth: 1,
    borderColor: colors.gray200,
    padding: spacing.lg,
    gap: spacing.xs,
  },
  row: {
    flexDirection: "row",
    justifyContent: "space-between",
    alignItems: "center",
    gap: spacing.sm,
  },
  title: {
    flex: 1,
    fontSize: 16,
    fontWeight: "700",
    color: colors.black,
  },
  required: {
    fontSize: 11,
    fontWeight: "700",
    color: colors.gray500,
    textTransform: "uppercase",
  },
  status: {
    fontSize: 13,
    fontWeight: "600",
  },
  meta: {
    fontSize: 13,
    color: colors.gray500,
  },
  reject: {
    fontSize: 13,
    color: colors.red600,
  },
  actions: {
    marginTop: spacing.sm,
    gap: spacing.sm,
  },
  uploadBtn: {
    backgroundColor: colors.lime,
    borderRadius: radii.md,
    paddingVertical: spacing.sm,
    alignItems: "center",
    minHeight: 40,
    justifyContent: "center",
    flex: 1,
  },
  uploadText: {
    fontWeight: "700",
    color: colors.black,
  },
  outlineBtn: {
    borderWidth: 1,
    borderColor: colors.gray200,
    borderRadius: radii.md,
    paddingVertical: spacing.sm,
    alignItems: "center",
    backgroundColor: colors.white,
    minHeight: 40,
    justifyContent: "center",
  },
  addSection: {
    marginTop: spacing.xl,
    gap: spacing.sm,
  },
  addTitle: {
    fontSize: 14,
    fontWeight: "700",
    color: colors.black,
  },
  dimmed: {
    opacity: 0.45,
  },
  outline: {
    borderWidth: 1,
    borderColor: colors.gray200,
    borderRadius: radii.md,
    paddingVertical: spacing.md,
    alignItems: "center",
    backgroundColor: colors.white,
  },
  outlineText: {
    fontWeight: "600",
    color: colors.black,
  },
  modalBackdrop: {
    flex: 1,
    backgroundColor: "rgba(0,0,0,0.4)",
    justifyContent: "center",
    padding: spacing.xl,
  },
  modalCard: {
    backgroundColor: colors.white,
    borderRadius: radii.lg,
    padding: spacing.xl,
    gap: spacing.sm,
  },
  modalTitle: {
    fontSize: 17,
    fontWeight: "800",
    color: colors.black,
  },
  modalBody: {
    fontSize: 14,
    color: colors.gray700,
    lineHeight: 20,
  },
  modalInput: {
    borderWidth: 1,
    borderColor: colors.gray200,
    borderRadius: radii.md,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.md,
    fontSize: 15,
    color: colors.black,
  },
  modalActions: {
    flexDirection: "row",
    gap: spacing.sm,
    marginTop: spacing.sm,
  },
  modalCancel: {
    flex: 1,
    borderWidth: 1,
    borderColor: colors.gray200,
    borderRadius: radii.md,
    paddingVertical: spacing.sm,
    alignItems: "center",
    justifyContent: "center",
  },
})

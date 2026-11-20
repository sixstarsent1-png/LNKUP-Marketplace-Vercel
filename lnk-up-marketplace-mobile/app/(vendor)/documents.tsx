import { useCallback, useEffect, useMemo, useRef, useState } from "react"
import { StyleSheet, View } from "react-native"
import * as DocumentPicker from "expo-document-picker"

import { IsoDatePickerField } from "@/components/iso-date-picker-field"
import { EmptyPanel, ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { Screen } from "@/components/screen"
import { AppIcon } from "@/components/ui/app-icon"
import { BackLink } from "@/components/ui/back-link"
import { InlineBanner } from "@/components/ui/banner"
import { BottomSheet } from "@/components/ui/bottom-sheet"
import { Button } from "@/components/ui/button"
import { FilterChip } from "@/components/ui/chip"
import { SectionHeader } from "@/components/ui/section-header"
import { StatusBadge } from "@/components/ui/status-badge"
import { Surface } from "@/components/ui/surface"
import { Body, Eyebrow, Meta, SectionTitle } from "@/components/ui/typography"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import {
  DOCUMENT_TYPE_OPTIONS,
  documentStatusLabel,
  fetchVendorDocuments,
  MAX_DOCUMENT_UPLOAD_BYTES,
  shareVendorDocument,
  uploadVendorDocument,
  type VendorDocumentRecord,
} from "@/lib/api/vendor-documents"
import { useAuth } from "@/lib/auth/auth-context"
import { showToast } from "@/lib/feedback/feedback"
import { formatShortDate } from "@/lib/format/date"
import {
  canUploadDocument,
  documentExpiry,
  documentStatusTone,
  requiredDocumentProgress,
} from "@/lib/vendor/document-status"
import { colors, radii, spacing, typeScale } from "@/lib/theme"

function hasFile(item: VendorDocumentRecord): boolean {
  return Boolean(item.id && !item.id.startsWith("type-") && item.status !== "missing")
}

type PendingUpload = {
  documentType: string
  label: string
  uri: string
  fileName: string
  mimeType?: string
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
  const [pendingUpload, setPendingUpload] = useState<PendingUpload | null>(null)
  const [expirationDate, setExpirationDate] = useState("")
  const itemsRef = useRef<VendorDocumentRecord[]>([])
  itemsRef.current = items

  const load = useCallback(
    async (mode: "initial" | "soft" = "initial") => {
      const hasItems = itemsRef.current.length > 0
      if (mode === "soft" && hasItems) setRefreshing(true)
      else setLoading(true)
      setError(null)
      try {
        const result = await fetchVendorDocuments(auth)
        setItems(result.items)
      } catch (err) {
        setError(appErrorFromWp(err))
        if (!(mode === "soft" && hasItems)) setItems([])
      } finally {
        setLoading(false)
        setRefreshing(false)
      }
    },
    [auth],
  )

  useEffect(() => {
    void load("initial")
  }, [load])

  async function pickAndUpload(documentType: string, label: string) {
    if (pickingRef.current || uploadingType || pendingUpload) return
    pickingRef.current = true
    setUploadMessage(null)
    try {
      const picked = await DocumentPicker.getDocumentAsync({
        type: ["application/pdf", "image/jpeg", "image/png", "image/jpg"],
        copyToCacheDirectory: true,
        multiple: false,
      })
      if (picked.canceled || !picked.assets?.[0]) return
      const asset = picked.assets[0]
      if (asset.size != null && asset.size > MAX_DOCUMENT_UPLOAD_BYTES) {
        setUploadMessage("That file is over 10 MB. Choose a smaller PDF or photo.")
        return
      }
      setExpirationDate("")
      setPendingUpload({
        documentType,
        label,
        uri: asset.uri,
        fileName: asset.name || `${documentType}.pdf`,
        mimeType: asset.mimeType || "application/pdf",
      })
    } catch (err) {
      setUploadMessage(appErrorFromWp(err).message)
    } finally {
      pickingRef.current = false
    }
  }

  async function confirmUpload() {
    if (!pendingUpload || uploadInFlight.current) return
    uploadInFlight.current = true
    setUploadingType(pendingUpload.documentType)
    try {
      await uploadVendorDocument(
        {
          documentType: pendingUpload.documentType,
          uri: pendingUpload.uri,
          fileName: pendingUpload.fileName,
          mimeType: pendingUpload.mimeType,
          expirationDate: expirationDate.trim() || undefined,
        },
        auth,
      )
      setUploadMessage(null)
      showToast("Document uploaded. We'll let you know once it's reviewed.", "success")
      setPendingUpload(null)
      setExpirationDate("")
      await load("soft")
    } catch (err) {
      setUploadMessage(`Upload failed. ${appErrorFromWp(err).message}`)
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

  const progress = useMemo(() => requiredDocumentProgress(items), [items])
  const listedTypes = useMemo(() => new Set(items.map((item) => item.type)), [items])
  const otherTypes = DOCUMENT_TYPE_OPTIONS.filter((opt) => !listedTypes.has(opt.value)).slice(0, 8)
  const showInitialLoader = loading && items.length === 0
  const allClear = progress.required > 0 && progress.approved === progress.required

  return (
    <Screen
      title="Documents"
      subtitle="Upload once. Reuse across events."
      refreshing={refreshing}
      onRefresh={() => void load("soft")}
      header={<BackLink label="Profile" fallbackHref="/(vendor)/more" />}
    >
      {showInitialLoader ? <LoadingPanel label="Loading documents…" variant="skeleton" count={3} /> : null}

      {!showInitialLoader && error && items.length === 0 ? (
        <ErrorPanel title={error.title} message={error.message} onAction={() => void load()} />
      ) : null}

      {error && items.length > 0 ? (
        <InlineBanner
          tone="error"
          message={`Couldn't refresh documents. ${error.message}`}
          actionLabel="Retry"
          onAction={() => void load("soft")}
        />
      ) : null}

      {uploadMessage ? <InlineBanner tone="error" message={uploadMessage} /> : null}

      {!showInitialLoader && progress.required > 0 ? (
        <Surface variant={allClear ? "hero" : progress.attention > 0 ? "urgent" : "default"} style={styles.summary}>
          <Eyebrow>Compliance</Eyebrow>
          <SectionTitle>
            {progress.approved} of {progress.required} required documents approved
          </SectionTitle>
          <View style={styles.track} accessibilityElementsHidden>
            <View
              style={[styles.fill, { width: `${Math.round((progress.approved / progress.required) * 100)}%` }]}
            />
          </View>
          <Meta>
            {allClear
              ? "You're cleared for event check-in."
              : progress.attention > 0
                ? `${progress.attention} need${progress.attention === 1 ? "s" : ""} your attention. Organizers check these before event day.`
                : "The rest are being reviewed."}
          </Meta>
        </Surface>
      ) : null}

      {!showInitialLoader && !error && items.length === 0 ? (
        <EmptyPanel
          icon="document-text-outline"
          title="No documents yet"
          message="Upload insurance, permits and licenses once — they're reused for every event you apply to."
        />
      ) : null}

      {items.length > 0 ? (
        <View style={styles.list}>
          {items.map((item) => (
            <DocumentCard
              key={item.id}
              item={item}
              downloading={downloadingId === item.id}
              uploading={uploadingType === item.type}
              uploadDisabled={Boolean(uploadingType) && uploadingType !== item.type}
              onView={() => void handleDownload(item)}
              onUpload={() => void pickAndUpload(item.type, item.label)}
            />
          ))}
        </View>
      ) : null}

      {!showInitialLoader && otherTypes.length > 0 ? (
        <View style={styles.section}>
          <SectionHeader title="Add another document" icon="add-circle-outline" />
          <View style={styles.chips}>
            {otherTypes.map((opt) => (
              <FilterChip
                key={opt.value}
                label={opt.label}
                onPress={() => void pickAndUpload(opt.value, opt.label)}
                accessibilityLabel={`Upload ${opt.label}`}
              />
            ))}
          </View>
        </View>
      ) : null}

      <BottomSheet
        visible={Boolean(pendingUpload)}
        title={pendingUpload ? `Upload ${pendingUpload.label}` : "Upload document"}
        onClose={() => {
          if (uploadingType) return
          setPendingUpload(null)
          setExpirationDate("")
        }}
        footer={
          <>
            <Button
              label="Cancel"
              variant="secondary"
              style={styles.flex}
              disabled={Boolean(uploadingType)}
              onPress={() => {
                setPendingUpload(null)
                setExpirationDate("")
              }}
            />
            <Button
              label="Upload"
              loadingLabel="Uploading…"
              style={styles.flex}
              loading={Boolean(uploadingType)}
              onPress={() => void confirmUpload()}
            />
          </>
        }
      >
        <View style={styles.fileRow}>
          <AppIcon name="document-attach-outline" size={20} color={colors.black} />
          <Body numberOfLines={1} style={styles.flex}>
            {pendingUpload?.fileName}
          </Body>
        </View>
        <IsoDatePickerField label="Expiration date" value={expirationDate} onChange={setExpirationDate} optional />
        <Meta>Add it for permits and insurance that expire, so we can remind you before they lapse.</Meta>
      </BottomSheet>
    </Screen>
  )
}

function DocumentCard({
  item,
  downloading,
  uploading,
  uploadDisabled,
  onView,
  onUpload,
}: {
  item: VendorDocumentRecord
  downloading: boolean
  uploading: boolean
  uploadDisabled: boolean
  onView: () => void
  onUpload: () => void
}) {
  const expiry = documentExpiry(item.expiresAt)
  const expiresLabel = item.expiresAt ? formatShortDate(item.expiresAt) : undefined
  return (
    <Surface style={styles.card} testID={`document-${item.type}`}>
      <View style={styles.cardHead}>
        <View style={styles.iconWrap}>
          <AppIcon name="document-text-outline" size={20} color={colors.black} />
        </View>
        <View style={styles.flex}>
          <SectionTitle numberOfLines={2} style={styles.cardTitle}>
            {item.label}
          </SectionTitle>
          <Meta>{item.required ? "Required by organizers" : "Optional"}</Meta>
        </View>
        <StatusBadge label={documentStatusLabel(item.status)} tone={documentStatusTone(item.status)} />
      </View>

      {expiresLabel ? (
        <Meta style={expiry === "expired" ? styles.expired : expiry === "soon" ? styles.soon : undefined}>
          {expiry === "expired" ? `Expired ${expiresLabel}` : `Expires ${expiresLabel}`}
          {expiry === "soon" ? " · renew soon" : ""}
        </Meta>
      ) : null}
      {item.rejectionReason ? <InlineBanner tone="error" message={item.rejectionReason} /> : null}

      {hasFile(item) || canUploadDocument(item) ? (
        <View style={styles.actions}>
          {hasFile(item) ? (
            <Button
              label="View"
              icon="eye-outline"
              variant="secondary"
              size="sm"
              style={styles.flex}
              loading={downloading}
              loadingLabel="Opening…"
              onPress={onView}
              accessibilityLabel={`View ${item.label}`}
            />
          ) : null}
          {canUploadDocument(item) ? (
            <Button
              label={item.status === "missing" ? "Upload" : "Replace"}
              icon="cloud-upload-outline"
              variant={item.status === "missing" || item.status === "rejected" || item.status === "expired" ? "accent" : "secondary"}
              size="sm"
              style={styles.flex}
              loading={uploading}
              loadingLabel="Uploading…"
              disabled={uploadDisabled}
              onPress={onUpload}
              accessibilityLabel={`${item.status === "missing" ? "Upload" : "Replace"} ${item.label}`}
            />
          ) : null}
        </View>
      ) : null}
    </Surface>
  )
}

const styles = StyleSheet.create({
  flex: {
    flex: 1,
  },
  summary: {
    gap: spacing.sm,
  },
  track: {
    height: 8,
    borderRadius: radii.pill,
    backgroundColor: colors.gray100,
    overflow: "hidden",
  },
  fill: {
    height: "100%",
    borderRadius: radii.pill,
    backgroundColor: colors.lime,
  },
  list: {
    gap: spacing.md,
  },
  card: {
    gap: spacing.sm,
  },
  cardHead: {
    flexDirection: "row",
    alignItems: "flex-start",
    gap: spacing.md,
  },
  iconWrap: {
    width: 40,
    height: 40,
    borderRadius: radii.md,
    backgroundColor: colors.gray100,
    alignItems: "center",
    justifyContent: "center",
  },
  cardTitle: {
    fontSize: typeScale.bodyLarge,
  },
  expired: {
    color: colors.red600,
  },
  soon: {
    color: colors.amber700,
  },
  actions: {
    flexDirection: "row",
    gap: spacing.sm,
  },
  section: {
    gap: spacing.md,
  },
  chips: {
    flexDirection: "row",
    flexWrap: "wrap",
    gap: spacing.sm,
  },
  fileRow: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.sm,
    padding: spacing.md,
    borderRadius: radii.md,
    backgroundColor: colors.gray50,
  },
})

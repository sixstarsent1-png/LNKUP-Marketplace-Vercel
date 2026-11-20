import { useEffect, useRef, useState } from "react"
import { StyleSheet, View } from "react-native"

import { BottomSheet } from "@/components/ui/bottom-sheet"
import { Button } from "@/components/ui/button"
import { FilterChipRow, type ChipOption } from "@/components/ui/chip"
import { FormField } from "@/components/ui/form-field"
import { Meta } from "@/components/ui/typography"
import { appErrorFromWp } from "@/lib/api/errors"
import { requestMoreInfo, type OrganizerVendorRequest } from "@/lib/api/organizer-requests"
import type { WpAuth } from "@/lib/api/wp-client"
import { spacing } from "@/lib/theme"

const DOCUMENTS = ["Insurance certificate", "Business license", "Health permit", "Product photos"] as const
type DocumentKey = (typeof DOCUMENTS)[number] | "none"

type InfoRequestSheetProps = {
  item: OrganizerVendorRequest | null
  auth?: WpAuth
  onClose: () => void
  onSent: () => void
}

/** Ask a vendor a question before deciding; the vendor replies from Requests. */
export function InfoRequestSheet({ item, auth, onClose, onSent }: InfoRequestSheetProps) {
  const [message, setMessage] = useState("")
  const [document, setDocument] = useState<DocumentKey>("none")
  const [sending, setSending] = useState(false)
  const [error, setError] = useState<string | undefined>()
  const inFlight = useRef(false)

  useEffect(() => {
    setMessage("")
    setDocument("none")
    setError(undefined)
  }, [item?.id])

  const options: ChipOption<DocumentKey>[] = [
    { value: "none", label: "No document" },
    ...DOCUMENTS.map((doc) => ({ value: doc, label: doc })),
  ]

  const handleSend = async () => {
    if (!item || inFlight.current) return
    inFlight.current = true
    setSending(true)
    setError(undefined)
    try {
      await requestMoreInfo(item, message, auth, document === "none" ? [] : [document])
      onSent()
    } catch (err) {
      setError(appErrorFromWp(err).message)
    } finally {
      inFlight.current = false
      setSending(false)
    }
  }

  return (
    <BottomSheet
      visible={Boolean(item)}
      title="Ask for more info"
      onClose={onClose}
      footer={
        <Button
          label="Send question"
          loading={sending}
          loadingLabel="Sending…"
          disabled={message.trim().length < 3}
          fullWidth
          onPress={() => void handleSend()}
        />
      }
    >
      <View style={styles.body}>
        {item ? <Meta>{`${item.vendorBusinessName} · ${item.eventName}`}</Meta> : null}
        <FormField
          label="What do you need?"
          value={message}
          onChangeText={setMessage}
          multiline
          maxLength={2000}
          error={error}
        />
        <Meta>Ask for a document (optional)</Meta>
        <FilterChipRow options={options} value={document} onChange={setDocument} />
      </View>
    </BottomSheet>
  )
}

const styles = StyleSheet.create({
  body: {
    gap: spacing.sm,
  },
})

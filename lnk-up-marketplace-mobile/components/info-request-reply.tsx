import { useRef, useState } from "react"
import { StyleSheet, View } from "react-native"

import { Button } from "@/components/ui/button"
import { FormField } from "@/components/ui/form-field"
import { Surface } from "@/components/ui/surface"
import { Body, Eyebrow, Meta } from "@/components/ui/typography"
import { appErrorFromWp } from "@/lib/api/errors"
import { respondToInfoRequest, type VendorInfoRequest } from "@/lib/api/vendor-requests"
import type { WpAuth } from "@/lib/api/wp-client"
import { colors, spacing } from "@/lib/theme"

type InfoRequestReplyProps = {
  applicationId: string
  request: VendorInfoRequest
  auth?: WpAuth
  onReplied: () => void
}

/** Organizer's question plus a reply box; the server moves the request back to review. */
export function InfoRequestReply({ applicationId, request, auth, onReplied }: InfoRequestReplyProps) {
  const [message, setMessage] = useState("")
  const [sending, setSending] = useState(false)
  const [error, setError] = useState<string | undefined>()
  const inFlight = useRef(false)

  const handleSend = async () => {
    if (inFlight.current) return
    if (!auth) {
      setError("Sign in to reply.")
      return
    }
    inFlight.current = true
    setSending(true)
    setError(undefined)
    try {
      await respondToInfoRequest(applicationId, message, auth)
      setMessage("")
      onReplied()
    } catch (err) {
      setError(appErrorFromWp(err).message)
    } finally {
      inFlight.current = false
      setSending(false)
    }
  }

  return (
    <Surface style={styles.card}>
      <Eyebrow>The organizer needs more information</Eyebrow>
      <Body>{request.message}</Body>
      {request.documents.length > 0 ? (
        <Meta>Documents requested: {request.documents.join(", ")}</Meta>
      ) : null}
      <View style={styles.form}>
        <FormField
          label="Your reply"
          value={message}
          onChangeText={setMessage}
          multiline
          maxLength={4000}
          error={error}
        />
        <Button
          label="Send reply"
          loading={sending}
          loadingLabel="Sending…"
          disabled={message.trim().length < 2}
          onPress={() => void handleSend()}
        />
      </View>
    </Surface>
  )
}

const styles = StyleSheet.create({
  card: {
    gap: spacing.sm,
    borderLeftWidth: 3,
    borderLeftColor: colors.lime,
  },
  form: {
    gap: spacing.sm,
  },
})

import { useCallback, useState } from "react"
import { StyleSheet, View } from "react-native"

import { EmptyPanel, ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { Screen } from "@/components/screen"
import { AppText as Text } from "@/components/ui/app-text"
import { InlineBanner } from "@/components/ui/banner"
import { BottomSheet } from "@/components/ui/bottom-sheet"
import { Button } from "@/components/ui/button"
import { FormField } from "@/components/ui/form-field"
import { StatusBadge } from "@/components/ui/status-badge"
import { Meta } from "@/components/ui/typography"
import { appErrorFromWp } from "@/lib/api/errors"
import {
  fetchPartnerLeads,
  leadFormError,
  submitPartnerLead,
  type PartnerLead,
  type PartnerLeadInput,
} from "@/lib/api/partner"
import { useAuth } from "@/lib/auth/auth-context"
import { showToast } from "@/lib/feedback/feedback"
import { formatShortDate } from "@/lib/format/date"
import { useFocusResource } from "@/lib/hooks/use-focus-resource"
import { useSingleFlight } from "@/lib/hooks/use-single-flight"
import { colors, radii, spacing, typeScale } from "@/lib/theme"

const EMPTY_FORM: PartnerLeadInput = { name: "", email: "", website: "", instagram: "", city: "", eventType: "", reason: "" }

function LeadRow({ lead }: { lead: PartnerLead }) {
  const submitted = formatShortDate(lead.submittedAt)
  const meta = [[lead.city, lead.state].filter(Boolean).join(", "), lead.category, submitted ? `Sent ${submitted}` : ""]
    .filter(Boolean)
    .join(" · ")
  const closed = Boolean(lead.outcomeLabel)
  return (
    <View style={styles.row} testID={`partner-lead-${lead.id}`}>
      <View style={styles.rowTop}>
        <Text style={styles.name} numberOfLines={1}>
          {lead.name}
        </Text>
        <StatusBadge label={lead.outcomeLabel || lead.stageLabel || "Submitted"} tone={closed ? "neutral" : "review"} />
      </View>
      {meta ? <Meta>{meta}</Meta> : null}
    </View>
  )
}

export default function PartnerLeadsScreen() {
  const { auth } = useAuth()
  const fetcher = useCallback(() => fetchPartnerLeads(auth), [auth])
  const { data, loading, refreshing, error, reload } = useFocusResource(fetcher)
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState<PartnerLeadInput>(EMPTY_FORM)
  const [formError, setFormError] = useState<string | null>(null)

  const atLimit = Boolean(data && data.dailyLimit > 0 && data.submittedToday >= data.dailyLimit)

  const [submit, submitting] = useSingleFlight(async () => {
    const problem = leadFormError(form)
    if (problem) {
      setFormError(problem)
      return
    }
    setFormError(null)
    try {
      const result = await submitPartnerLead(auth, form)
      if (result.status === "submitted") {
        showToast(result.message || "Lead submitted.", "success")
        setForm(EMPTY_FORM)
        setOpen(false)
        void reload("refresh")
        return
      }
      setFormError(result.message || "LNKup couldn't accept this lead.")
    } catch (err) {
      setFormError(appErrorFromWp(err).message)
    }
  })

  function update<K extends keyof PartnerLeadInput>(key: K, value: string) {
    setForm((current) => ({ ...current, [key]: value }))
  }

  return (
    <Screen
      title="Leads"
      subtitle="Organizers you introduced to LNKup"
      refreshing={refreshing}
      onRefresh={() => void reload("refresh")}
    >
      {loading && !data ? <LoadingPanel label="Loading leads…" variant="skeleton" count={2} /> : null}
      {error && !data ? (
        <ErrorPanel title={error.title} message={error.message} onAction={() => void reload("initial")} />
      ) : null}

      {data && !data.enabled ? (
        <EmptyPanel
          title="Lead submission isn't open yet"
          message="Share your referral link from Home in the meantime. Organizers who sign up with it are tracked automatically."
          icon="flag-outline"
        />
      ) : null}

      {data && data.enabled ? (
        <View style={styles.stack} testID="partner-leads">
          {error ? (
            <InlineBanner tone="error" message={error.message} actionLabel="Retry" onAction={() => void reload("refresh")} />
          ) : null}
          <Meta>
            Know an organizer who should be on LNKup? Send them to the team. A lead isn't an acquired organizer until
            they register and activate.
          </Meta>
          <Button
            label={atLimit ? "Daily limit reached" : "Submit a lead"}
            icon="add-circle-outline"
            disabled={atLimit}
            onPress={() => {
              setFormError(null)
              setOpen(true)
            }}
            testID="partner-lead-open"
          />
          {data.dailyLimit > 0 ? (
            <Meta>
              {data.submittedToday} of {data.dailyLimit} leads sent today.
            </Meta>
          ) : null}

          {data.items.length === 0 ? (
            <EmptyPanel
              title="No leads yet"
              message="Leads you submit appear here with their progress. Contact details stay private to LNKup."
              icon="flag-outline"
            />
          ) : (
            data.items.map((lead) => <LeadRow key={lead.id} lead={lead} />)
          )}
        </View>
      ) : null}

      <BottomSheet
        visible={open}
        title="Submit an organizer lead"
        onClose={() => setOpen(false)}
        footer={
          <Button
            label="Send to LNKup"
            loading={submitting}
            onPress={() => void submit()}
            fullWidth
            testID="partner-lead-submit"
          />
        }
      >
        <View style={styles.form}>
          {formError ? <InlineBanner tone="error" message={formError} /> : null}
          <FormField label="Organizer or business name" value={form.name} onChangeText={(v) => update("name", v)} />
          <FormField
            label="Website"
            value={form.website}
            onChangeText={(v) => update("website", v)}
            autoCapitalize="none"
            keyboardType="url"
          />
          <FormField
            label="Instagram"
            value={form.instagram}
            onChangeText={(v) => update("instagram", v)}
            autoCapitalize="none"
            placeholder="@handle"
          />
          <FormField
            label="Business email"
            value={form.email}
            onChangeText={(v) => update("email", v)}
            autoCapitalize="none"
            keyboardType="email-address"
            hint="Public website, Instagram or email — at least one."
          />
          <FormField label="City" value={form.city} onChangeText={(v) => update("city", v)} />
          <FormField
            label="Event type"
            value={form.eventType}
            onChangeText={(v) => update("eventType", v)}
            placeholder="Market, festival, pop-up…"
          />
          <FormField
            label="Why they're a fit"
            value={form.reason}
            onChangeText={(v) => update("reason", v)}
            multiline
          />
        </View>
      </BottomSheet>
    </Screen>
  )
}

const styles = StyleSheet.create({
  stack: { gap: spacing.md },
  form: { gap: spacing.md },
  row: {
    borderWidth: 1,
    borderColor: colors.gray200,
    borderRadius: radii.md,
    padding: spacing.md,
    gap: 4,
    backgroundColor: colors.white,
  },
  rowTop: { flexDirection: "row", alignItems: "center", justifyContent: "space-between", gap: spacing.sm },
  name: { fontSize: typeScale.callout, fontWeight: "700", color: colors.black, flex: 1 },
})

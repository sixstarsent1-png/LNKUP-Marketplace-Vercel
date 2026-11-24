import { useCallback, useEffect, useRef, useState } from "react"
import { KeyboardAvoidingView, Platform, Pressable, ScrollView, StyleSheet, View } from "react-native"
import { AppText as Text } from "@/components/ui/app-text"
import { Redirect, router, useLocalSearchParams, type Href } from "expo-router"
import { SafeAreaView } from "react-native-safe-area-context"

import { ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { BackLink } from "@/components/ui/back-link"
import { Button } from "@/components/ui/button"
import { FormField } from "@/components/ui/form-field"
import { SectionTitle } from "@/components/ui/typography"
import { ApplyQuestions } from "@/components/apply/apply-questions"
import {
  DECLINED_MESSAGE,
  fetchApplyContext,
  newIdempotencyKey,
  reapplyNote,
  saveApplyDraft,
  submitApplication,
  submitApplyDraft,
  type ApplyAnswerValue,
  type ApplyContext,
  type DraftInput,
} from "@/lib/api/apply"
import { appErrorFromWp, WpFetchError, type AppErrorShape } from "@/lib/api/errors"
import { formatMoney } from "@/lib/api/format-money"
import { useAuth } from "@/lib/auth/auth-context"
import { constrainedColumnStyle } from "@/lib/layout/use-breakpoint"
import { safeBack } from "@/lib/navigation/safe-back"
import { colors, layout, radii, spacing, typeScale } from "@/lib/theme"

export default function ApplyScreen() {
  const { opportunityId } = useLocalSearchParams<{ opportunityId: string }>()
  const { auth, isAuthenticated } = useAuth()
  const [loading, setLoading] = useState(true)
  const [submitting, setSubmitting] = useState(false)
  const submittingRef = useRef(false)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [context, setContext] = useState<ApplyContext | null>(null)
  const [category, setCategory] = useState("")
  const [message, setMessage] = useState("")
  const [accepted, setAccepted] = useState(false)
  const [termsError, setTermsError] = useState<string | null>(null)
  const [submittedMessage, setSubmittedMessage] = useState<string | null>(null)
  const [submittedApplicationId, setSubmittedApplicationId] = useState<string | null>(null)
  const [setupNotes, setSetupNotes] = useState("")
  const [answers, setAnswers] = useState<Record<string, ApplyAnswerValue>>({})
  const [invalid, setInvalid] = useState<string[]>([])
  const [priceChanged, setPriceChanged] = useState(false)
  const [blockedCode, setBlockedCode] = useState<string | null>(null)
  const [draftState, setDraftState] = useState<"idle" | "saving" | "saved" | "error">("idle")
  const idempotencyKey = useRef(newIdempotencyKey())
  const draftRevision = useRef<number | null>(null)
  const saveTimer = useRef<ReturnType<typeof setTimeout> | null>(null)

  const id = Number(opportunityId)
  const draftsOn = Boolean(context?.features.drafts && context.canApply)

  const load = useCallback(async () => {
    if (!id) {
      setError({
        code: "validation_error",
        title: "Invalid opportunity",
        message: "Missing opportunity id.",
      })
      setLoading(false)
      return
    }
    setLoading(true)
    setError(null)
    setSubmittedMessage(null)
    try {
      const data = await fetchApplyContext(id, auth)
      setContext(data)
      setCategory(data.defaultCategory ?? data.categories[0] ?? "")
      if (data.draft && data.features.drafts) {
        draftRevision.current = data.draft.revision
        if (data.draft.fields.selectedCategory) setCategory(data.draft.fields.selectedCategory)
        setMessage(data.draft.fields.message)
        setSetupNotes(data.draft.fields.setupNotes)
        setAnswers(data.draft.answers)
      }
    } catch (err) {
      setError(appErrorFromWp(err))
    } finally {
      setLoading(false)
    }
  }, [auth, id])

  useEffect(() => {
    void load()
  }, [load])

  useEffect(
    () => () => {
      if (saveTimer.current) clearTimeout(saveTimer.current)
    },
    [],
  )

  const saveDraftNow = useCallback(
    async (input: DraftInput): Promise<boolean> => {
      if (saveTimer.current) {
        clearTimeout(saveTimer.current)
        saveTimer.current = null
      }
      setDraftState("saving")
      try {
        const saved = await saveApplyDraft(id, input, draftRevision.current, auth)
        if (saved) draftRevision.current = saved.revision
        setDraftState("saved")
        return true
      } catch (err) {
        if (err instanceof WpFetchError && err.code === "lnkup_draft_conflict") {
          // Another device saved first: reload the newest version rather than overwrite it.
          await load()
        }
        setDraftState("error")
        return false
      }
    },
    [auth, id, load],
  )

  function queueDraftSave(next: Partial<DraftInput>) {
    if (!draftsOn) return
    const input: DraftInput = {
      selectedCategory: next.selectedCategory ?? category,
      message: next.message ?? message,
      setupNotes: next.setupNotes ?? setupNotes,
      answers: next.answers ?? answers,
    }
    if (saveTimer.current) clearTimeout(saveTimer.current)
    saveTimer.current = setTimeout(() => void saveDraftNow(input), 900)
  }

  if (!isAuthenticated) {
    return <Redirect href={{ pathname: "/login", params: { returnTo: `/apply/${id}` } }} />
  }

  async function handleSubmit() {
    if (!context || submittingRef.current || submittedMessage) return
    if (!accepted) {
      setTermsError("Accept the marketplace terms to submit.")
      return
    }
    setTermsError(null)
    if (context.categories.length > 0 && !category.trim()) {
      setError({
        code: "validation_error",
        title: "Category required",
        message: "Select a vendor category before applying.",
        retryable: false,
      })
      return
    }
    if (context.buttonDisabled || !context.canApply) {
      return
    }

    submittingRef.current = true
    setSubmitting(true)
    setError(null)
    setInvalid([])
    try {
      let result
      if (draftsOn) {
        const saved = await saveDraftNow({ selectedCategory: category, message, setupNotes, answers })
        if (!saved) {
          setError({ code: "unknown", title: "Couldn't save", message: "We couldn't save your application. Try again.", retryable: true })
          return
        }
        result = await submitApplyDraft(
          context.opportunityId,
          { acceptedTerms: true, acceptCurrentPrice: priceChanged, idempotencyKey: idempotencyKey.current },
          auth,
        )
      } else {
        result = await submitApplication(
          {
            opportunityId: context.opportunityId,
            selectedCategory: category,
            message,
            setupNotes,
            answers,
            acceptedTerms: true,
            idempotencyKey: idempotencyKey.current,
          },
          auth,
        )
      }
      setSubmittedApplicationId(result.applicationId ?? null)
      setSubmittedMessage(
        result.message ?? "Track it under Bookings. You only pay if the organizer approves you.",
      )
    } catch (err) {
      if (err instanceof WpFetchError) {
        if (err.code === "duplicate" || err.code === "previously_declined") {
          setBlockedCode(err.code)
          return
        }
        if (err.code === "lnkup_answers_required" || err.code === "lnkup_invalid_answer") {
          const fields = err.data?.fields
          setInvalid(Array.isArray(fields) ? fields.map(String) : [])
        }
        if (err.code === "lnkup_price_changed") {
          setPriceChanged(true)
        }
      }
      setError(appErrorFromWp(err))
    } finally {
      submittingRef.current = false
      setSubmitting(false)
    }
  }

  const canApplyForm = Boolean(context?.canApply && !context?.buttonDisabled && !blockedCode)
  const feeLabel =
    context?.vendorFee == null ? "Set by organizer" : context.vendorFee > 0 ? formatMoney(context.vendorFee) : "No booth fee"
  const blockReason = blockedCode ?? context?.blockReason
  const blockedTitle =
    blockReason === "duplicate" ? "You already applied" : blockReason === "previously_declined" ? "Application declined" : "Applications unavailable"
  const blockedMessage =
    blockReason === "duplicate"
      ? "Track this application under Bookings."
      : blockReason === "previously_declined"
        ? DECLINED_MESSAGE
        : "Applications are not open for this opportunity yet."
  const note = reapplyNote(context?.previousApplication ?? null)

  return (
    <SafeAreaView style={styles.safe} edges={["top", "bottom"]}>
      <View style={[styles.header, constrainedColumnStyle(layout.contentMaxWidth)]}>
        <BackLink label="Back" fallbackHref="/(vendor)/find" />
        <SectionTitle accessibilityRole="header">Apply</SectionTitle>
      </View>

      {submittedMessage ? (
        <View style={[styles.successWrap, constrainedColumnStyle(layout.contentMaxWidth)]}>
          <View style={styles.successCard} testID="apply-success">
            <Text style={styles.successEyebrow}>Submitted · waiting for organizer review</Text>
            <Text style={styles.successTitle}>{context?.eventName ?? "Application submitted"}</Text>
            <Text style={styles.successMessage}>{submittedMessage}</Text>
            <View style={styles.nextSteps}>
              <Text style={styles.nextStepsTitle}>What happens next</Text>
              <Text style={styles.nextStep}>1. The organizer reviews your application. This is not an approval yet.</Text>
              <Text style={styles.nextStep}>2. If they have questions, you'll get a notification to reply.</Text>
              <Text style={styles.nextStep}>3. If you're approved, you pay the booth fee to confirm your spot.</Text>
            </View>
            <Button
              label="View application"
              icon="document-text-outline"
              fullWidth
              onPress={() =>
                router.replace((submittedApplicationId ? `/booking/${submittedApplicationId}` : "/(vendor)/requests") as Href)
              }
            />
            <Button
              label="Continue exploring"
              variant="secondary"
              fullWidth
              onPress={() => router.replace("/(vendor)/find")}
            />
          </View>
        </View>
      ) : (
        <KeyboardAvoidingView style={styles.flex} behavior={Platform.OS === "ios" ? "padding" : undefined}>
          <ScrollView
            contentContainerStyle={[
              styles.body,
              constrainedColumnStyle(layout.contentMaxWidth),
              canApplyForm ? styles.bodyWithFooter : null,
            ]}
            keyboardShouldPersistTaps="handled"
          >
            {loading ? <LoadingPanel label="Loading application…" variant="skeleton" count={2} /> : null}

            {!loading && error && !context ? (
              <ErrorPanel title={error.title} message={error.message} onAction={() => void load()} />
            ) : null}

            {!loading && context ? (
              <View style={[styles.content, constrainedColumnStyle(layout.contentMaxWidth)]}>
                <View style={styles.summaryCard}>
                  <Text style={styles.title}>{context.eventName}</Text>
                  <Text style={styles.meta}>
                    {[context.eventDate, context.city, context.state].filter(Boolean).join(" · ")}
                  </Text>
                  {context.organizerName ? (
                    <Text style={styles.meta}>Organizer · {context.organizerName}</Text>
                  ) : null}
                  {context.excerpt ? (
                    <Text style={styles.excerpt} numberOfLines={2}>
                      {context.excerpt}
                    </Text>
                  ) : null}
                  <Pressable
                    onPress={() => router.push(`/opportunity/${context.opportunityId}` as Href)}
                    hitSlop={8}
                  >
                    <Text style={styles.detailsLink}>View full details</Text>
                  </Pressable>
                  <View style={styles.feeRow}>
                    <Text style={styles.feeLabel}>Booth fee · charged only if approved</Text>
                    <Text style={styles.feeValue}>{feeLabel}</Text>
                  </View>
                </View>

                {context.warnings.some((w) => typeof w === "string" && w.trim()) ? (
                  <View style={styles.warningsCard}>
                    <Text style={styles.warningsTitle}>Before you apply</Text>
                    {context.warnings.map((warning, index) =>
                      typeof warning === "string" && warning.trim() ? (
                        <Text key={`warning-${index}`} style={styles.warningItem}>
                          {warning}
                        </Text>
                      ) : null,
                    )}
                  </View>
                ) : null}

                {!canApplyForm ? (
                  <View style={styles.blockedWrap}>
                    <ErrorPanel title={blockedTitle} message={blockedMessage} />
                    {blockReason === "duplicate" || blockReason === "previously_declined" ? (
                      <Button
                        label="View bookings"
                        icon="ticket-outline"
                        fullWidth
                        onPress={() => router.replace("/(vendor)/requests")}
                      />
                    ) : null}
                    <Button
                      label="Back to Explore"
                      variant="secondary"
                      fullWidth
                      onPress={() => safeBack("/(vendor)/find")}
                    />
                  </View>
                ) : (
                  <>
                    {note ? (
                      <View style={styles.noteCard} testID="apply-reapply-note">
                        <Text style={styles.noteText}>{note}</Text>
                      </View>
                    ) : null}
                    {error ? (
                      <ErrorPanel
                        title={error.title}
                        message={error.message}
                        onAction={() => setError(null)}
                        actionLabel="Dismiss"
                      />
                    ) : null}

                    {context.categories.length > 0 ? (
                      <View style={styles.field}>
                        <Text style={styles.label}>Category</Text>
                        <Text style={styles.helper}>Required if listed for this event</Text>
                        <View style={styles.chips}>
                          {context.categories.map((item) => {
                            const active = item === category
                            return (
                              <Pressable
                                key={item}
                                onPress={() => {
                                  setCategory(item)
                                  queueDraftSave({ selectedCategory: item })
                                }}
                                style={[styles.chip, active && styles.chipActive]}
                                accessibilityRole="button"
                                accessibilityState={{ selected: active }}
                                aria-selected={active}
                              >
                                <Text style={[styles.chipText, active && styles.chipTextActive]}>{item}</Text>
                              </Pressable>
                            )
                          })}
                        </View>
                      </View>
                    ) : null}

                    <FormField
                      label="Message (optional)"
                      hint="Helps the organizer review you faster."
                      value={message}
                      onChangeText={(text) => {
                        setMessage(text)
                        queueDraftSave({ message: text })
                      }}
                      placeholder="Tell the organizer about your booth…"
                      multiline
                    />

                    <FormField
                      label="Setup notes (optional)"
                      hint="Power, tent size, vehicle access…"
                      value={setupNotes}
                      onChangeText={(text) => {
                        setSetupNotes(text)
                        queueDraftSave({ setupNotes: text })
                      }}
                      multiline
                    />

                    <ApplyQuestions
                      questions={context.questions}
                      answers={answers}
                      invalid={invalid}
                      documents={context.vendorDocuments}
                      onChange={(questionId, value) => {
                        const next = { ...answers, [questionId]: value }
                        setAnswers(next)
                        queueDraftSave({ answers: next })
                      }}
                    />

                    {draftsOn && draftState !== "idle" ? (
                      <Text style={styles.helper} accessibilityLiveRegion="polite" testID="apply-draft-state">
                        {draftState === "saving"
                          ? "Saving…"
                          : draftState === "saved"
                            ? "Saved. You can finish this later on any device."
                            : "Couldn't save your progress. Your answers are still here."}
                      </Text>
                    ) : null}

                    <Pressable
                      style={[styles.termsRow, termsError ? styles.termsRowError : null]}
                      onPress={() => {
                        setAccepted((value) => !value)
                        setTermsError(null)
                      }}
                      accessibilityRole="checkbox"
                      accessibilityState={{ checked: accepted }}
                      aria-checked={accepted}
                      accessibilityLabel="I accept LNKup marketplace terms and understand booth fees may apply."
                    >
                      <View style={[styles.checkbox, accepted && styles.checkboxOn]} />
                      <Text style={styles.termsText}>
                        I accept LNKup marketplace terms and understand booth fees may apply.
                      </Text>
                    </Pressable>
                    {termsError ? <Text style={styles.termsError}>{termsError}</Text> : null}
                  </>
                )}
              </View>
            ) : null}
          </ScrollView>

          {!loading && context && canApplyForm ? (
            <View style={styles.stickyFooter}>
              <View style={constrainedColumnStyle(layout.contentMaxWidth)}>
                <Button
                  label={priceChanged ? "Submit at the new price" : context.buttonLabel}
                  loadingLabel="Submitting…"
                  loading={submitting}
                  fullWidth
                  onPress={() => void handleSubmit()}
                />
              </View>
            </View>
          ) : null}
        </KeyboardAvoidingView>
      )}
    </SafeAreaView>
  )
}

const styles = StyleSheet.create({
  safe: {
    flex: 1,
    backgroundColor: colors.white,
  },
  flex: {
    flex: 1,
  },
  header: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
    gap: spacing.md,
    paddingHorizontal: spacing.lg,
    paddingVertical: spacing.sm,
    borderBottomWidth: 1,
    borderBottomColor: colors.gray200,
  },
  body: {
    padding: spacing.lg,
    paddingBottom: spacing.xxl,
  },
  bodyWithFooter: {
    paddingBottom: 120,
  },
  content: {
    gap: spacing.md,
  },
  summaryCard: {
    backgroundColor: colors.gray50,
    borderRadius: radii.lg,
    borderWidth: 1,
    borderColor: colors.gray200,
    padding: spacing.lg,
    gap: spacing.xs,
  },
  title: {
    fontSize: typeScale.title,
    fontWeight: "800",
    color: colors.black,
  },
  meta: {
    fontSize: typeScale.body,
    color: colors.gray500,
  },
  excerpt: {
    marginTop: spacing.sm,
    fontSize: typeScale.body,
    color: colors.gray700,
    lineHeight: 20,
  },
  detailsLink: {
    marginTop: spacing.xs,
    fontSize: typeScale.body,
    fontWeight: "700",
    color: colors.black,
    textDecorationLine: "underline",
  },
  feeRow: {
    marginTop: spacing.md,
    flexDirection: "row",
    justifyContent: "space-between",
    alignItems: "center",
    backgroundColor: colors.white,
    borderRadius: radii.md,
    borderWidth: 1,
    borderColor: colors.gray200,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm + 2,
  },
  feeLabel: {
    fontSize: typeScale.footnote,
    fontWeight: "600",
    color: colors.gray500,
  },
  feeValue: {
    fontSize: typeScale.bodyLarge,
    fontWeight: "800",
    color: colors.black,
  },
  warningsCard: {
    backgroundColor: colors.amber50,
    borderRadius: radii.lg,
    borderWidth: 1,
    borderColor: colors.amber200,
    padding: spacing.lg,
    gap: spacing.sm,
  },
  warningsTitle: {
    fontSize: typeScale.body,
    fontWeight: "800",
    color: colors.amber700,
  },
  warningItem: {
    fontSize: typeScale.footnote,
    color: colors.amber700,
    lineHeight: 18,
  },
  blockedWrap: {
    gap: spacing.md,
  },
  noteCard: {
    backgroundColor: colors.gray50,
    borderRadius: radii.lg,
    borderWidth: 1,
    borderColor: colors.gray200,
    padding: spacing.md,
  },
  noteText: {
    fontSize: typeScale.body,
    color: colors.gray700,
    lineHeight: 20,
  },
  field: {
    gap: spacing.sm,
  },
  label: {
    fontSize: typeScale.footnote,
    fontWeight: "700",
    color: colors.gray700,
  },
  helper: {
    fontSize: typeScale.caption,
    color: colors.gray500,
    marginTop: -4,
  },
  chips: {
    flexDirection: "row",
    flexWrap: "wrap",
    gap: spacing.sm,
  },
  chip: {
    borderWidth: 1,
    borderColor: colors.gray200,
    borderRadius: radii.md,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.md,
    backgroundColor: colors.white,
    minHeight: 44,
    justifyContent: "center",
  },
  chipActive: {
    backgroundColor: colors.black,
    borderColor: colors.black,
  },
  chipText: {
    fontSize: typeScale.footnote,
    fontWeight: "700",
    color: colors.gray700,
  },
  chipTextActive: {
    color: colors.white,
  },
  termsRow: {
    flexDirection: "row",
    gap: spacing.md,
    alignItems: "flex-start",
    padding: spacing.md,
    borderRadius: radii.md,
    borderWidth: 1,
    borderColor: colors.gray200,
    backgroundColor: colors.gray50,
  },
  termsRowError: {
    borderColor: colors.red600,
    backgroundColor: colors.red50,
  },
  checkbox: {
    width: 24,
    height: 24,
    borderRadius: 6,
    borderWidth: 2,
    borderColor: colors.gray400,
    marginTop: 1,
  },
  checkboxOn: {
    backgroundColor: colors.lime,
    borderColor: colors.black,
  },
  termsText: {
    flex: 1,
    fontSize: typeScale.body,
    color: colors.gray700,
    lineHeight: 20,
  },
  termsError: {
    fontSize: typeScale.footnote,
    color: colors.red600,
    fontWeight: "600",
    marginTop: -4,
  },
  stickyFooter: {
    borderTopWidth: 1,
    borderTopColor: colors.gray200,
    paddingHorizontal: spacing.lg,
    paddingVertical: spacing.md,
    backgroundColor: colors.white,
  },
  successWrap: {
    flex: 1,
    padding: spacing.lg,
    justifyContent: "center",
  },
  successCard: {
    backgroundColor: colors.green50,
    borderRadius: radii.lg,
    borderWidth: 1,
    borderColor: colors.green200,
    padding: spacing.xl,
    gap: spacing.md,
  },
  successEyebrow: {
    fontSize: typeScale.caption,
    fontWeight: "800",
    letterSpacing: 1.2,
    textTransform: "uppercase",
    color: colors.green700,
  },
  successTitle: {
    fontSize: typeScale.title,
    fontWeight: "800",
    color: colors.black,
  },
  successMessage: {
    fontSize: typeScale.callout,
    color: colors.gray700,
    lineHeight: 22,
  },
  nextSteps: { gap: spacing.xs, paddingVertical: spacing.sm },
  nextStepsTitle: { fontSize: typeScale.callout, fontWeight: "700", color: colors.black },
  nextStep: { fontSize: typeScale.body, color: colors.gray700, lineHeight: 20 },
})

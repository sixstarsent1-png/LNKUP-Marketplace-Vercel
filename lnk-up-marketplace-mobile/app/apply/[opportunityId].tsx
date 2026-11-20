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
import { fetchApplyContext, submitApplication, type ApplyContext } from "@/lib/api/apply"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
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

  const id = Number(opportunityId)

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
    } catch (err) {
      setError(appErrorFromWp(err))
    } finally {
      setLoading(false)
    }
  }, [auth, id])

  useEffect(() => {
    void load()
  }, [load])

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
    try {
      const result = await submitApplication(
        {
          opportunityId: context.opportunityId,
          selectedCategory: category,
          message,
          acceptedTerms: true,
        },
        auth,
      )
      setSubmittedMessage(
        result.message ?? "Track it under Bookings. You only pay if the organizer approves you.",
      )
    } catch (err) {
      setError(appErrorFromWp(err))
    } finally {
      submittingRef.current = false
      setSubmitting(false)
    }
  }

  const canApplyForm = Boolean(context?.canApply && !context?.buttonDisabled)
  const feeLabel = context?.vendorFee != null && context.vendorFee > 0 ? `$${context.vendorFee}` : "Fee TBD"

  return (
    <SafeAreaView style={styles.safe} edges={["top", "bottom"]}>
      <View style={[styles.header, constrainedColumnStyle(layout.contentMaxWidth)]}>
        <BackLink label="Back" fallbackHref="/(vendor)/find" />
        <SectionTitle accessibilityRole="header">Apply</SectionTitle>
      </View>

      {submittedMessage ? (
        <View style={[styles.successWrap, constrainedColumnStyle(layout.contentMaxWidth)]}>
          <View style={styles.successCard}>
            <Text style={styles.successEyebrow}>LNKup</Text>
            <Text style={styles.successTitle}>Application submitted</Text>
            <Text style={styles.successMessage}>{submittedMessage}</Text>
            <Button
              label="View bookings"
              icon="ticket-outline"
              fullWidth
              onPress={() => router.replace("/(vendor)/requests")}
            />
            <Button
              label="Find more events"
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
                  <Text style={styles.brand}>LNKup</Text>
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
                    <Text style={styles.feeLabel}>Vendor fee</Text>
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
                    <ErrorPanel
                      title="Applications unavailable"
                      message={context.blockReason ?? "Applications are not open for this opportunity yet."}
                    />
                    <Button
                      label="Back to Explore"
                      variant="secondary"
                      fullWidth
                      onPress={() => safeBack("/(vendor)/find")}
                    />
                  </View>
                ) : (
                  <>
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
                                onPress={() => setCategory(item)}
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
                      onChangeText={setMessage}
                      placeholder="Tell the organizer about your booth…"
                      multiline
                    />

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
                  label={context.buttonLabel}
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
  brand: {
    fontSize: typeScale.caption,
    fontWeight: "800",
    letterSpacing: 1.2,
    textTransform: "uppercase",
    color: colors.gray500,
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
})

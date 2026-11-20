import { useCallback, useEffect, useRef, useState } from "react"
import {
  KeyboardAvoidingView,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  TextInput,
  View,
} from "react-native"
import { AppText as Text } from "@/components/ui/app-text"
import { Redirect, router, useLocalSearchParams, type Href } from "expo-router"
import { SafeAreaView } from "react-native-safe-area-context"

import { ErrorPanel, LoadingPanel } from "@/components/panel-states"
import {
  fetchApplyContext,
  submitApplication,
  type ApplyContext,
} from "@/lib/api/apply"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import { useAuth } from "@/lib/auth/auth-context"
import { constrainedColumnStyle, useBreakpoint } from "@/lib/layout/use-breakpoint"
import { safeBack } from "@/lib/navigation/safe-back"
import { colors, layout, radii, spacing } from "@/lib/theme"

export default function ApplyScreen() {
  const { opportunityId } = useLocalSearchParams<{ opportunityId: string }>()
  const { auth, isAuthenticated } = useAuth()
  const { primaryButtonPaddingVertical } = useBreakpoint()
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
      setSubmittedMessage(result.message ?? "Check Requests for status.")
    } catch (err) {
      setError(appErrorFromWp(err))
    } finally {
      submittingRef.current = false
      setSubmitting(false)
    }
  }

  const canApplyForm = Boolean(context?.canApply && !context?.buttonDisabled)
  const feeLabel =
    context?.vendorFee != null && context.vendorFee > 0
      ? `$${context.vendorFee}`
      : "Fee TBD"

  return (
    <SafeAreaView style={styles.safe} edges={["top", "bottom"]}>
      <View style={styles.header}>
        <Pressable onPress={() => safeBack("/(vendor)/find")} style={styles.back}>
          <Text style={styles.backText}>Back</Text>
        </Pressable>
        <Text style={styles.headerTitle}>Apply</Text>
        <View style={styles.backSpacer} />
      </View>

      {submittedMessage ? (
        <View style={[styles.successWrap, constrainedColumnStyle(layout.contentMaxWidth)]}>
          <View style={styles.successCard}>
            <Text style={styles.successEyebrow}>LNKup</Text>
            <Text style={styles.successTitle}>Application submitted</Text>
            <Text style={styles.successMessage}>{submittedMessage}</Text>
            <Pressable
              style={[styles.primary, { paddingVertical: primaryButtonPaddingVertical }]}
              onPress={() => router.replace("/(vendor)/requests")}
            >
              <Text style={styles.primaryText}>View requests</Text>
            </Pressable>
            <Pressable
              style={styles.secondaryBtn}
              onPress={() => router.replace("/(vendor)/find")}
            >
              <Text style={styles.secondaryBtnText}>Find more events</Text>
            </Pressable>
          </View>
        </View>
      ) : (
        <KeyboardAvoidingView
          style={styles.flex}
          behavior={Platform.OS === "ios" ? "padding" : undefined}
        >
          <ScrollView
            contentContainerStyle={[
              styles.body,
              constrainedColumnStyle(layout.contentMaxWidth),
              canApplyForm ? styles.bodyWithFooter : null,
            ]}
            keyboardShouldPersistTaps="handled"
          >
            {loading ? <LoadingPanel label="Loading application…" /> : null}

            {!loading && error && !context ? (
              <ErrorPanel
                title={error.title}
                message={error.message}
                onAction={() => void load()}
              />
            ) : null}

            {!loading && context ? (
              <View style={[styles.content, constrainedColumnStyle(layout.contentMaxWidth)]}>
                <View style={styles.summaryCard}>
                  <Text style={styles.brand}>LNKup</Text>
                  <Text style={styles.title}>{context.eventName}</Text>
                  <Text style={styles.meta}>
                    {[context.eventDate, context.city, context.state]
                      .filter(Boolean)
                      .join(" · ")}
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
                      onPress={() =>
                        router.push(`/opportunity/${context.opportunityId}` as Href)
                      }
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
                      message={
                        context.blockReason ??
                        "Applications are not open for this opportunity yet."
                      }
                    />
                    <Pressable
                      style={styles.secondaryBtn}
                      onPress={() => safeBack("/(vendor)/find")}
                    >
                      <Text style={styles.secondaryBtnText}>Back to Explore</Text>
                    </Pressable>
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
                                <Text
                                  style={[styles.chipText, active && styles.chipTextActive]}
                                >
                                  {item}
                                </Text>
                              </Pressable>
                            )
                          })}
                        </View>
                      </View>
                    ) : null}

                    <View style={styles.field}>
                      <Text style={styles.label}>Message</Text>
                      <Text style={styles.helper}>Optional — helps organizers review you</Text>
                      <TextInput
                        value={message}
                        onChangeText={setMessage}
                        placeholder="Tell the organizer about your booth…"
                        placeholderTextColor={colors.gray400}
                        style={styles.input}
                        multiline
                      />
                    </View>

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
                <Pressable
                  style={[
                    styles.primary,
                    { paddingVertical: primaryButtonPaddingVertical },
                    submitting && styles.disabled,
                  ]}
                  disabled={submitting}
                  onPress={() => void handleSubmit()}
                  accessibilityRole="button"
                  accessibilityState={{ disabled: submitting, busy: submitting }}
                >
                  <Text style={styles.primaryText}>
                    {submitting ? "Submitting…" : context.buttonLabel}
                  </Text>
                </Pressable>
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
    paddingHorizontal: spacing.lg,
    paddingVertical: spacing.sm,
    borderBottomWidth: 1,
    borderBottomColor: colors.gray200,
  },
  back: {
    minWidth: 56,
  },
  backSpacer: {
    minWidth: 56,
  },
  backText: {
    fontWeight: "600",
    color: colors.gray700,
  },
  headerTitle: {
    flex: 1,
    textAlign: "center",
    fontWeight: "700",
    fontSize: 16,
    color: colors.black,
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
    fontSize: 12,
    fontWeight: "800",
    letterSpacing: 1.2,
    textTransform: "uppercase",
    color: colors.gray500,
  },
  title: {
    fontSize: 24,
    fontWeight: "800",
    color: colors.black,
  },
  meta: {
    fontSize: 14,
    color: colors.gray500,
  },
  excerpt: {
    marginTop: spacing.sm,
    fontSize: 14,
    color: colors.gray700,
    lineHeight: 20,
  },
  detailsLink: {
    marginTop: spacing.xs,
    fontSize: 14,
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
    fontSize: 13,
    fontWeight: "600",
    color: colors.gray500,
  },
  feeValue: {
    fontSize: 16,
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
    fontSize: 14,
    fontWeight: "800",
    color: colors.amber700,
  },
  warningItem: {
    fontSize: 13,
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
    fontSize: 13,
    fontWeight: "700",
    color: colors.gray700,
  },
  helper: {
    fontSize: 12,
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
    fontSize: 13,
    fontWeight: "700",
    color: colors.gray700,
  },
  chipTextActive: {
    color: colors.white,
  },
  input: {
    minHeight: 96,
    borderWidth: 1,
    borderColor: colors.gray200,
    borderRadius: radii.md,
    padding: spacing.md,
    fontSize: 15,
    color: colors.black,
    backgroundColor: colors.gray50,
    textAlignVertical: "top",
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
    fontSize: 14,
    color: colors.gray700,
    lineHeight: 20,
  },
  termsError: {
    fontSize: 13,
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
  primary: {
    backgroundColor: colors.black,
    borderRadius: radii.md,
    paddingVertical: spacing.md,
    alignItems: "center",
  },
  primaryText: {
    color: colors.white,
    fontWeight: "700",
    fontSize: 16,
  },
  secondaryBtn: {
    borderWidth: 1,
    borderColor: colors.gray200,
    borderRadius: radii.md,
    paddingVertical: spacing.md,
    alignItems: "center",
    backgroundColor: colors.white,
  },
  secondaryBtnText: {
    fontWeight: "700",
    fontSize: 15,
    color: colors.black,
  },
  disabled: {
    opacity: 0.6,
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
    fontSize: 12,
    fontWeight: "800",
    letterSpacing: 1.2,
    textTransform: "uppercase",
    color: colors.green700,
  },
  successTitle: {
    fontSize: 24,
    fontWeight: "800",
    color: colors.black,
  },
  successMessage: {
    fontSize: 15,
    color: colors.gray700,
    lineHeight: 22,
  },
})

import { Pressable, StyleSheet, View } from "react-native"
import { AppText as Text } from "@/components/ui/app-text"
import { FormField } from "@/components/ui/form-field"
import type { ApplyAnswerValue, ApplyQuestion } from "@/lib/api/apply"
import { colors, radii, spacing, typeScale } from "@/lib/theme"

type Props = {
  questions: ApplyQuestion[]
  answers: Record<string, ApplyAnswerValue>
  invalid: string[]
  documents: Array<{ id: number; label: string }>
  onChange: (id: string, value: ApplyAnswerValue) => void
}

/** Organizer questions for one vendor slot. Required checks happen on the server; `invalid` shows what it rejected. */
export function ApplyQuestions({ questions, answers, invalid, documents, onChange }: Props) {
  if (!questions.length) return null
  return (
    <View style={styles.wrap} testID="apply-questions">
      <Text style={styles.heading} accessibilityRole="header">
        Organizer questions
      </Text>
      {questions.map((q) => (
        <QuestionField
          key={q.id}
          question={q}
          value={answers[q.id]}
          error={invalid.includes(q.id) ? (q.required ? "This answer is required." : "Check this answer.") : undefined}
          documents={documents}
          onChange={(value) => onChange(q.id, value)}
        />
      ))}
    </View>
  )
}

function QuestionField({
  question,
  value,
  error,
  documents,
  onChange,
}: {
  question: ApplyQuestion
  value: ApplyAnswerValue | undefined
  error?: string
  documents: Array<{ id: number; label: string }>
  onChange: (value: ApplyAnswerValue) => void
}) {
  const label = `${question.label}${question.required ? " *" : ""}`
  switch (question.type) {
    case "text":
    case "textarea":
      return (
        <FormField
          label={label}
          hint={question.help || undefined}
          error={error}
          value={typeof value === "string" ? value : ""}
          onChangeText={onChange}
          multiline={question.type === "textarea"}
        />
      )
    case "checkbox": {
      const checked = value === true
      return (
        <View style={styles.field}>
          <Pressable
            style={[styles.checkRow, error ? styles.checkRowError : null]}
            onPress={() => onChange(!checked)}
            accessibilityRole="checkbox"
            accessibilityState={{ checked }}
            aria-checked={checked}
            accessibilityLabel={label}
          >
            <View style={[styles.checkbox, checked && styles.checkboxOn]} />
            <Text style={styles.checkText}>{label}</Text>
          </Pressable>
          {error ? <Text style={styles.error}>{error}</Text> : question.help ? <Text style={styles.help}>{question.help}</Text> : null}
        </View>
      )
    }
    case "select":
    case "file": {
      const options =
        question.type === "select"
          ? question.options.map((o) => ({ value: o, label: o }))
          : documents.map((d) => ({ value: String(d.id), label: d.label }))
      return (
        <View style={styles.field}>
          <Text style={styles.label}>{label}</Text>
          {question.type === "file" && options.length === 0 ? (
            <Text style={styles.help}>Upload this document under Documents first, then come back to attach it.</Text>
          ) : (
            <View style={styles.chips} accessibilityRole="radiogroup" accessibilityLabel={label}>
              {options.map((option) => {
                const active = String(value ?? "") === option.value
                return (
                  <Pressable
                    key={option.value}
                    onPress={() => onChange(question.type === "file" ? Number(option.value) : option.value)}
                    style={[styles.chip, active && styles.chipActive]}
                    accessibilityRole="radio"
                    accessibilityState={{ selected: active }}
                    aria-selected={active}
                  >
                    <Text style={[styles.chipText, active && styles.chipTextActive]}>{option.label}</Text>
                  </Pressable>
                )
              })}
            </View>
          )}
          {error ? <Text style={styles.error}>{error}</Text> : question.help ? <Text style={styles.help}>{question.help}</Text> : null}
        </View>
      )
    }
    default: {
      const unreachable: never = question.type
      return unreachable
    }
  }
}

const styles = StyleSheet.create({
  wrap: { gap: spacing.md },
  heading: { fontSize: typeScale.body, fontWeight: "800", color: colors.black },
  field: { gap: spacing.sm },
  label: { fontSize: typeScale.footnote, fontWeight: "700", color: colors.gray700 },
  help: { fontSize: typeScale.caption, color: colors.gray500 },
  error: { fontSize: typeScale.footnote, color: colors.red600, fontWeight: "600" },
  chips: { flexDirection: "row", flexWrap: "wrap", gap: spacing.sm },
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
  chipActive: { backgroundColor: colors.black, borderColor: colors.black },
  chipText: { fontSize: typeScale.footnote, fontWeight: "700", color: colors.gray700 },
  chipTextActive: { color: colors.white },
  checkRow: {
    flexDirection: "row",
    gap: spacing.md,
    alignItems: "flex-start",
    padding: spacing.md,
    borderRadius: radii.md,
    borderWidth: 1,
    borderColor: colors.gray200,
    backgroundColor: colors.gray50,
  },
  checkRowError: { borderColor: colors.red600, backgroundColor: colors.red50 },
  checkbox: { width: 24, height: 24, borderRadius: 6, borderWidth: 2, borderColor: colors.gray400, marginTop: 1 },
  checkboxOn: { backgroundColor: colors.lime, borderColor: colors.black },
  checkText: { flex: 1, fontSize: typeScale.body, color: colors.gray700, lineHeight: 20 },
})

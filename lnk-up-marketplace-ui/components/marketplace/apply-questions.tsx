"use client"

import type { ApplyAnswerValue } from "@/lib/api/apply-client"
import type { WpApplicationQuestion } from "@/lib/api/types"
import { Checkbox } from "@/components/ui/checkbox"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"

interface ApplyQuestionsProps {
  questions: WpApplicationQuestion[]
  answers: Record<string, ApplyAnswerValue>
  invalid: string[]
  documents: Array<{ id: number; label: string }>
  idPrefix: string
  onChange: (id: string, value: ApplyAnswerValue) => void
}

/** Organizer questions for one vendor slot. Validation is server-side; `invalid` highlights what it rejected. */
export function ApplyQuestions({ questions, answers, invalid, documents, idPrefix, onChange }: ApplyQuestionsProps) {
  if (!questions.length) return null
  return (
    <fieldset className="flex flex-col gap-3" data-testid="lnkup-apply-questions">
      <legend className="mb-1 text-sm font-medium">Organizer questions</legend>
      {questions.map((q) => (
        <ApplyQuestionField
          key={q.id}
          question={q}
          value={answers[q.id]}
          isInvalid={invalid.includes(q.id)}
          documents={documents}
          fieldId={`${idPrefix}-${q.id}`}
          onChange={(value) => onChange(q.id, value)}
        />
      ))}
    </fieldset>
  )
}

interface ApplyQuestionFieldProps {
  question: WpApplicationQuestion
  value: ApplyAnswerValue | undefined
  isInvalid: boolean
  documents: Array<{ id: number; label: string }>
  fieldId: string
  onChange: (value: ApplyAnswerValue) => void
}

function ApplyQuestionField({ question, value, isInvalid, documents, fieldId, onChange }: ApplyQuestionFieldProps) {
  const label = `${question.label}${question.required ? " *" : ""}`
  const errorId = `${fieldId}-error`
  const describedBy = isInvalid ? errorId : undefined
  const error = isInvalid ? (
    <p id={errorId} className="text-xs text-destructive">
      {question.required ? "This answer is required." : "Check this answer."}
    </p>
  ) : null
  const help = question.help ? <p className="text-xs text-muted-foreground">{question.help}</p> : null

  switch (question.type) {
    case "checkbox":
      return (
        <div className="flex flex-col gap-1">
          <Label className="flex items-start gap-2 text-sm font-normal">
            <Checkbox
              checked={value === true}
              onCheckedChange={(checked) => onChange(checked === true)}
              aria-invalid={isInvalid}
              aria-describedby={describedBy}
              className="mt-0.5"
            />
            <span>{label}</span>
          </Label>
          {help}
          {error}
        </div>
      )
    case "select":
    case "file": {
      const options =
        question.type === "select"
          ? question.options.map((o) => ({ value: o, label: o }))
          : documents.map((d) => ({ value: String(d.id), label: d.label }))
      return (
        <div className="flex flex-col gap-1">
          <Label htmlFor={fieldId}>{label}</Label>
          {question.type === "file" && options.length === 0 ? (
            <p className="text-sm text-muted-foreground">
              Upload this document in your vendor documents first, then come back to attach it.
            </p>
          ) : (
            <Select value={value == null ? "" : String(value)} onValueChange={(v) => onChange(String(v ?? ""))}>
              <SelectTrigger id={fieldId} className="w-full" aria-invalid={isInvalid} aria-describedby={describedBy}>
                <SelectValue placeholder={question.type === "file" ? "Choose a document" : "Choose an option"} />
              </SelectTrigger>
              <SelectContent>
                {options.map((o) => (
                  <SelectItem key={o.value} value={o.value}>
                    {o.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}
          {help}
          {error}
        </div>
      )
    }
    case "textarea":
      return (
        <div className="flex flex-col gap-1">
          <Label htmlFor={fieldId}>{label}</Label>
          <Textarea
            id={fieldId}
            rows={3}
            value={typeof value === "string" ? value : ""}
            onChange={(e) => onChange(e.target.value)}
            aria-invalid={isInvalid}
            aria-describedby={describedBy}
          />
          {help}
          {error}
        </div>
      )
    case "text":
      return (
        <div className="flex flex-col gap-1">
          <Label htmlFor={fieldId}>{label}</Label>
          <Input
            id={fieldId}
            value={typeof value === "string" ? value : ""}
            onChange={(e) => onChange(e.target.value)}
            aria-invalid={isInvalid}
            aria-describedby={describedBy}
          />
          {help}
          {error}
        </div>
      )
    default: {
      const unreachable: never = question.type
      return unreachable
    }
  }
}

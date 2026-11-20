"use client"

import { useState } from "react"
import { Button } from "@/components/ui/button"

export function ConfirmActionButton({
  label,
  confirmLabel,
  variant = "default",
  disabled,
  onConfirm,
  testId,
}: {
  label: string
  confirmLabel?: string
  variant?: "default" | "outline" | "destructive" | "secondary"
  disabled?: boolean
  onConfirm: () => Promise<void> | void
  testId?: string
}) {
  const [confirming, setConfirming] = useState(false)
  const [busy, setBusy] = useState(false)

  async function handleClick() {
    if (!confirming) {
      setConfirming(true)
      return
    }
    setBusy(true)
    try {
      await onConfirm()
      setConfirming(false)
    } finally {
      setBusy(false)
    }
  }

  return (
    <Button
      type="button"
      size="sm"
      variant={confirming ? "destructive" : variant}
      disabled={disabled || busy}
      onClick={() => void handleClick()}
      onBlur={() => {
        if (!busy) setConfirming(false)
      }}
      data-testid={testId}
    >
      {busy ? "Working…" : confirming ? confirmLabel ?? `Confirm ${label}` : label}
    </Button>
  )
}

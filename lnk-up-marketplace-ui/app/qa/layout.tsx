import { notFound } from "next/navigation"

export default function QaLayout({ children }: { children: React.ReactNode }) {
  if (process.env.ENABLE_QA_TOOLS !== "true") {
    notFound()
  }

  return (
    <div className="min-h-svh bg-background">
      <div className="border-b border-amber-200 bg-amber-50 px-4 py-2 text-center text-sm text-amber-950">
        Internal QA only — does not expose credentials.
      </div>
      {children}
    </div>
  )
}

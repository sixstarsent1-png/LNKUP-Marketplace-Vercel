import { notFound } from "next/navigation"

export default function OpsLayout({ children }: { children: React.ReactNode }) {
  if (
    process.env.ENABLE_QA_TOOLS !== "true" &&
    process.env.ENABLE_OPS_ANALYTICS !== "true"
  ) {
    notFound()
  }

  return (
    <div className="min-h-svh bg-background">
      <div className="border-b border-amber-200 bg-amber-50 px-4 py-2 text-center text-sm text-amber-950">
        This internal launch view is for marketplace QA and optimization. The LNKup web dashboard remains the
        system of record.
      </div>
      {children}
    </div>
  )
}

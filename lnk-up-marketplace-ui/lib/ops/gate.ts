export function isOpsAnalyticsEnabled(): boolean {
  return (
    process.env.ENABLE_QA_TOOLS === "true" ||
    process.env.ENABLE_OPS_ANALYTICS === "true" ||
    process.env.NEXT_PUBLIC_ENABLE_OPS_ANALYTICS === "true"
  )
}

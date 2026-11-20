import fs from "node:fs"
import path from "node:path"

const root = process.cwd()

const requiredChecks = [
  {
    file: "lib/admin/queues.ts",
    snippets: ["mapConnectorRow", "mapExternalEventRow", "mapVerificationRow", "getMockConnectorItems"],
  },
  {
    file: "lib/admin/ops-alerts.ts",
    snippets: ["buildAdminOpsAlerts", "recommendationIds", "recommendationsForAlert"],
  },
  {
    file: "app/api/admin/summary/route.ts",
    snippets: ['"/admin/connectors"', '"/verification/admin/summary"', "kpis?.pending_review", "admin_summary_partial"],
  },
  {
    file: "app/api/admin/connectors/route.ts",
    snippets: ['"/admin/connectors"', "admin_connectors_partial", "items"],
  },
  {
    file: "app/api/admin/external-events/route.ts",
    snippets: ['"/external-events/admin/queue"', "admin_external_events_partial", "items"],
  },
  {
    file: "app/api/admin/verification/route.ts",
    snippets: ['"/verification/admin/queue"', "admin_verification_partial", "items"],
  },
  {
    file: "app/api/admin/health/route.ts",
    snippets: ["admin_connectors", "verification_summary", "admin_health_degraded"],
  },
  {
    file: "app/api/admin/connectors/[id]/action/route.ts",
    snippets: ["approve", "pause", "reject"],
  },
  {
    file: "app/api/admin/external-events/[id]/action/route.ts",
    snippets: ["publish", "reject"],
  },
  {
    file: "app/api/admin/verification/[id]/action/route.ts",
    snippets: ["document", "approved", "rejected"],
  },
  {
    file: "app/admin/connectors/page.tsx",
    snippets: ['data-testid="admin-connectors-queue"', "Review"],
  },
  {
    file: "app/admin/connectors/[id]/page.tsx",
    snippets: ["ConfirmActionButton", "connector-approve"],
  },
  {
    file: "app/admin/external-events/[id]/page.tsx",
    snippets: ["external-event-publish", "ConfirmActionButton"],
  },
  {
    file: "app/admin/verification/[id]/page.tsx",
    snippets: ["verification-approve", "ConfirmActionButton"],
  },
  {
    file: "app/admin/page.tsx",
    snippets: ["AdminOpsAlertsPanel", "buildAdminOpsAlerts", "/api/ops/analytics/summary"],
  },
  {
    file: "components/admin/admin-handoff-card.tsx",
    snippets: ["AdminHandoffCard"],
  },
  {
    file: "../wp-content/plugins/lnkup-marketplace/includes/class-external-events-rest.php",
    snippets: ["/external-events/admin/queue", "admin_queue", "admin_detail"],
  },
  {
    file: "../wp-content/plugins/lnkup-marketplace/includes/class-verification-rest.php",
    snippets: ["/verification/admin/summary", "/verification/admin/queue", "admin_summary"],
  },
]

const failures = []

for (const check of requiredChecks) {
  const absolutePath = path.join(root, check.file)
  if (!fs.existsSync(absolutePath)) {
    failures.push(`${check.file}: missing file`)
    continue
  }
  const content = fs.readFileSync(absolutePath, "utf8")
  for (const snippet of check.snippets) {
    if (!content.includes(snippet)) {
      failures.push(`${check.file}: missing snippet "${snippet}"`)
    }
  }
}

if (failures.length > 0) {
  console.error("Phase 25 verify failed:")
  for (const failure of failures) {
    console.error(`- ${failure}`)
  }
  process.exit(1)
}

console.log("Phase 25 verify passed.")

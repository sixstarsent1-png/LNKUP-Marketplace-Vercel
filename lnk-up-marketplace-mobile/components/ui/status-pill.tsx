import { StatusBadge } from "@/components/ui/status-badge"
import {
  applicationStatusLabel,
  applicationStatusTone,
  type ApplicationStatus,
  type StatusAudience,
} from "@/lib/status/application-status"

/** Application status pill with the shared vendor/organizer wording. */
export function StatusPill({ status, audience }: { status: ApplicationStatus; audience: StatusAudience }) {
  return <StatusBadge label={applicationStatusLabel(status, audience)} tone={applicationStatusTone(status, audience)} />
}

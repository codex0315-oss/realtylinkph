import type { Property } from './property'
import type { User } from './user'

export type AppointmentStatus = 'pending' | 'confirmed' | 'cancelled' | 'completed'

export interface BookAppointmentRequest {
  property_id: number
  agent_id: number
  preferred_datetime: string
  notes?: string
}

export interface Appointment {
  id: number
  property_id: number
  buyer_id: number
  agent_id: number
  preferred_datetime: string
  status: AppointmentStatus
  can_review?: boolean
  gcal_event_id: string | null
  notes: string | null
  /** Cancellation record — why, by whom, and whether it counted as late. */
  cancel_reason_code?: string | null
  cancel_reason?: string | null
  cancel_reason_note?: string | null
  cancelled_by_id?: number | null
  cancelled_at?: string | null
  late_cancellation?: boolean
  /** True when cancelling right now would count against the canceller. */
  cancel_is_late?: boolean
  /** May the current viewer report that the other party didn't turn up? */
  can_report_no_show?: boolean
  /** A reported no-show: who didn't come, who reported it, and any note. */
  no_show?: { user_id: number; reported_by: number | null; reported_at: string | null; note: string | null } | null
  /** An admin forgave the strike this viewing produced. */
  strike_waived?: boolean
  created_at: string
  property?: Property
  buyer?: User
  agent?: User
}

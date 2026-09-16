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
  created_at: string
  property?: Property
  buyer?: User
  agent?: User
}

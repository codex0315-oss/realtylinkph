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
  created_at: string
  property?: Property
  buyer?: User
  agent?: User
}

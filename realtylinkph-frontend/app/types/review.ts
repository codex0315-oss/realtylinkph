import type { User } from './user'
import type { Appointment } from './appointment'

export interface AgentReview {
  id: number
  agent_id: number
  buyer_id: number
  /** The viewing the review rests on, if any (chat-based reviews have none). */
  appointment_id: number | null
  conversation_id?: number | null
  rating: number
  review_text: string | null
  is_visible: boolean
  /** "Verified viewing" badge — the review rests on a viewing that took place. */
  is_verified?: boolean
  property_title?: string | null
  is_edited?: boolean
  created_at: string
  updated_at?: string
  agent?: User
  buyer?: User
}

/** GET /agents/{id}/reviewable — may the current buyer review this agent, and why. */
export interface ReviewEligibility {
  eligible: boolean
  basis: 'viewing' | 'agent_cancelled' | 'conversation' | null
  verified: boolean
  /** The viewings the basis rests on (for the "which viewing?" picker). */
  appointments: Appointment[]
  conversation_id: number | null
  /** The buyer's existing review of this agent, if any (→ offer Edit). */
  review: AgentReview | null
}

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
  /** The agent's public reply, and when it was last written. */
  agent_reply?: string | null
  agent_replied_at?: string | null
  is_visible: boolean
  /** When hidden: why (admin + the reviewer), who (admin only), and when. */
  hidden_reason?: string | null
  hidden_by?: string | null
  hidden_at?: string | null
  /** "Verified viewing" badge — the review rests on a viewing that took place. */
  is_verified?: boolean
  property_title?: string | null
  /** Buyer's own reviews (GET /me/reviews): the viewed listing and when. */
  property_id?: number | null
  property_photo?: string | null
  viewed_at?: string | null
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

/** Homepage testimonial (GET /reviews/featured) — a public, contact-free shape. */
export interface FeaturedReview {
  id: number
  rating: number
  review_text: string
  is_verified: boolean
  property_title: string | null
  /** Short display name, e.g. "Maria S." */
  buyer_name: string
  buyer_avatar: string | null
  agent_id: number
  agent_name: string | null
  created_at: string | null
}

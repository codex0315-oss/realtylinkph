import type { User } from './user'

export interface AgentReview {
  id: number
  agent_id: number
  buyer_id: number
  appointment_id: number
  rating: number
  review_text: string | null
  is_visible: boolean
  created_at: string
  agent?: User
  buyer?: User
}

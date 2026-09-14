import type { Property } from './property'
import type { User } from './user'

export interface Message {
  id: number
  conversation_id: number
  sender_id: number
  body: string
  /** Posted by RealtyLink AI while the agent was offline. */
  is_ai?: boolean
  is_read: boolean
  read_at?: string | null
  created_at: string
  sender?: User
}

export interface Conversation {
  id: number
  property_id: number
  buyer_id: number
  agent_id: number
  last_message_at: string | null
  unread_count?: number
  property?: Property
  buyer?: User
  agent?: User
  latest_message?: Message | null
}
